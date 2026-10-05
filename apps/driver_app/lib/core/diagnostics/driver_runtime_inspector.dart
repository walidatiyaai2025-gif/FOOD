import 'dart:async';
import 'dart:convert';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';

import 'driver_runtime_platform.dart';

const driverAppVersion = '1.0.56';
const driverAppBuild = '55';

String sanitizeForDiagnostics(Object? value, {int maxLength = 12000}) {
  var text = value?.toString() ?? '';

  text = text.replaceAll(
    RegExp(r'Bearer\s+[A-Za-z0-9._~+\/=:-]+', caseSensitive: false),
    'Bearer [REDACTED]',
  );

  text = text.replaceAllMapped(
    RegExp(
      r'("(?:password|passcode|token|access_token|refresh_token|authorization|cookie|secret|customer_name|customer_phone|customer_email|address|civil_id|latitude|longitude|lat|lng)"\s*:\s*")[^"]*(")',
      caseSensitive: false,
    ),
    (match) => '${match.group(1)}[REDACTED]${match.group(2)}',
  );

  text = text.replaceAllMapped(
    RegExp(
      r'\b(password|passcode|token|access_token|refresh_token|authorization|cookie|secret)=([^&\s]+)',
      caseSensitive: false,
    ),
    (match) => '${match.group(1)}=[REDACTED]',
  );

  text = text.replaceAllMapped(
    RegExp(r'([?&][^=\s]+)=([^&\s]+)'),
    (match) => '${match.group(1)}=[REDACTED]',
  );

  text = text.replaceAll(
    RegExp(
      r'\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b',
      caseSensitive: false,
    ),
    '[REDACTED_EMAIL]',
  );

  text = text.replaceAll(
    RegExp(r'(?<!\d)(?:\+?\d[\d -]{6,}\d)(?!\d)'),
    '[REDACTED_PHONE]',
  );

  text = text.replaceAll(
    RegExp(r'(?<![A-Za-z0-9])[-+]?\d{1,3}\.\d{4,}(?![A-Za-z0-9])'),
    '[REDACTED_COORDINATE]',
  );

  if (text.length > maxLength) {
    return '${text.substring(0, maxLength)}…[TRUNCATED]';
  }
  return text;
}

class DriverRuntimeInspector {
  DriverRuntimeInspector({this.maxEvents = 120});

  static final DriverRuntimeInspector instance = DriverRuntimeInspector();

  static const _storageKey = 'foodex_driver_runtime_inspector_v1';

  final int maxEvents;
  final List<Map<String, dynamic>> _events = <Map<String, dynamic>>[];

  SharedPreferences? _preferences;
  Future<void> _persistChain = Future<void>.value();
  String _appVersion = driverAppVersion;
  String _appBuild = driverAppBuild;
  String? _lastRoute;
  bool _flushInProgress = false;
  bool _flushRequested = false;
  String? _uploadBaseUrl;
  String? _uploadToken;
  String? _uploadChannel;
  int? _uploadStoreId;

  int get eventCount => _events.length;
  String? get lastRoute => _lastRoute;
  String? get lastEventAt =>
      _events.isEmpty ? null : _events.last['timestamp']?.toString();

  Future<void> initialize({
    String appVersion = driverAppVersion,
    String appBuild = driverAppBuild,
  }) async {
    _appVersion = appVersion;
    _appBuild = appBuild;

    try {
      final preferences = await SharedPreferences.getInstance();
      _preferences = preferences;
      final raw = preferences.getString(_storageKey);
      if (raw == null || raw.trim().isEmpty) return;

      final decoded = jsonDecode(raw);
      if (decoded is! List) return;

      final restored = decoded
          .whereType<Map>()
          .map((event) => Map<String, dynamic>.from(event))
          .toList(growable: false);
      _events
        ..clear()
        ..addAll(
          restored.length <= maxEvents
              ? restored
              : restored.sublist(restored.length - maxEvents),
        );

      for (final event in _events.reversed) {
        if (event['type'] == 'navigation' && event['route'] is String) {
          _lastRoute = event['route'] as String;
          break;
        }
      }
    } catch (_) {
      // Diagnostics must never interfere with app startup.
    }
  }

  void recordNavigation(String route) {
    final safeRoute = sanitizeForDiagnostics(route, maxLength: 300);
    _lastRoute = safeRoute;
    _append({
      'type': 'navigation',
      'route': safeRoute,
    });
  }

  void recordException(
    Object error,
    StackTrace stack, {
    required String source,
  }) {
    _append({
      'type': 'error',
      'source': sanitizeForDiagnostics(source, maxLength: 80),
      'error_type': error.runtimeType.toString(),
      'message': sanitizeForDiagnostics(error, maxLength: 2000),
      'stack': sanitizeForDiagnostics(stack, maxLength: 12000),
    });
  }

  void recordHttpFailure({
    required String method,
    required Uri uri,
    required Duration elapsed,
    int? statusCode,
    Object? error,
  }) {
    _append({
      'type': 'http_failure',
      'method': sanitizeForDiagnostics(method.toUpperCase(), maxLength: 16),
      'endpoint': _safeEndpoint(uri),
      'status_code': statusCode,
      'duration_ms': elapsed.inMilliseconds,
      if (error != null)
        'error': sanitizeForDiagnostics(error, maxLength: 1200),
    });
  }

  void recordVersionPolicyFailure({
    required String failureClass,
    required Uri uri,
    required Duration elapsed,
    required String platform,
    required String appVersion,
    required String appBuild,
    required int attempt,
    int? statusCode,
    String? correlationId,
    Object? error,
  }) {
    _append({
      'type': 'driver_version_policy_failure',
      'failure_class':
          sanitizeForDiagnostics(failureClass, maxLength: 80),
      'operation': 'GET ${sanitizeForDiagnostics(uri.path, maxLength: 240)}',
      'endpoint': _safeEndpoint(uri),
      'status_code': statusCode,
      'duration_ms': elapsed.inMilliseconds,
      'platform': sanitizeForDiagnostics(platform, maxLength: 20),
      'app_version': sanitizeForDiagnostics(appVersion, maxLength: 40),
      'app_build': sanitizeForDiagnostics(appBuild, maxLength: 40),
      'attempt': attempt,
      if (correlationId != null && correlationId.trim().isNotEmpty)
        'correlation_id':
            sanitizeForDiagnostics(correlationId.trim(), maxLength: 160),
      if (error != null) 'error_type': error.runtimeType.toString(),
    });
  }

  void recordTrackingFailure({
    required String code,
    Object? error,
    StackTrace? stack,
  }) {
    _append({
      'type': 'driver_tracking_failure',
      'code': sanitizeForDiagnostics(code, maxLength: 80),
      if (error != null) 'error_type': error.runtimeType.toString(),
      if (stack != null)
        'stack': sanitizeForDiagnostics(stack, maxLength: 4000),
    });
  }

  List<Map<String, dynamic>> snapshot() => _events
      .map((event) => Map<String, dynamic>.from(event))
      .toList(growable: false);

  Map<String, dynamic> exportPayload({
    required String locale,
    required bool authenticated,
  }) {
    return {
      'schema': 'foodex.driver.inspector.v1',
      'generated_at': DateTime.now().toUtc().toIso8601String(),
      'app': {
        'name': 'FOODEX Driver',
        'version': _appVersion,
        'build': _appBuild,
        'platform': driverOperatingSystem,
        'os_version': sanitizeForDiagnostics(
          driverOperatingSystemVersion,
          maxLength: 1000,
        ),
        'locale': sanitizeForDiagnostics(locale, maxLength: 20),
      },
      'session': {
        'authenticated': authenticated,
      },
      'current_route': _lastRoute,
      'event_count': _events.length,
      'events': snapshot(),
      'privacy': {
        'automatic_upload': false,
        'request_bodies_included': false,
        'response_bodies_included': false,
        'credentials_included': false,
        'query_values_included': false,
        'precise_location_included': false,
      },
    };
  }

  Future<DriverDiagnosticExportFile> writeExportFile({
    required String locale,
    required bool authenticated,
  }) async {
    final generated = DateTime.now().toUtc();
    final stamp =
        generated.toIso8601String().replaceAll(RegExp(r'[:.]'), '-');
    final encoder = const JsonEncoder.withIndent(' ');

    return writeDriverDiagnosticExport(
      filename: 'foodex-driver-inspector-$stamp.json',
      content: encoder.convert(
        exportPayload(
          locale: locale,
          authenticated: authenticated,
        ),
      ),
    );
  }

  void configureInspectorUpload({
    required String baseUrl,
    required String token,
    required String channel,
    int? storeId,
  }) {
    final normalizedBaseUrl = baseUrl.trim();
    final normalizedToken = token.trim();
    final normalizedChannel = channel.trim();
    if (normalizedBaseUrl.isEmpty ||
        normalizedToken.isEmpty ||
        normalizedChannel.isEmpty) {
      clearInspectorUpload();
      return;
    }

    _uploadBaseUrl = normalizedBaseUrl;
    _uploadToken = normalizedToken;
    _uploadChannel = normalizedChannel;
    _uploadStoreId = storeId;
    _scheduleAutomaticFlush();
  }

  void clearInspectorUpload() {
    _uploadBaseUrl = null;
    _uploadToken = null;
    _uploadChannel = null;
    _uploadStoreId = null;
    _flushRequested = false;
  }

  void notifyConnectivityRecovered() {
    _scheduleAutomaticFlush();
  }

  void _scheduleAutomaticFlush() {
    final baseUrl = _uploadBaseUrl;
    final token = _uploadToken;
    final channel = _uploadChannel;
    if (baseUrl == null || token == null || channel == null) return;

    if (_flushInProgress) {
      _flushRequested = true;
      return;
    }

    unawaited(
      flushToInspector(
        baseUrl: baseUrl,
        token: token,
        channel: channel,
        storeId: _uploadStoreId,
      ),
    );
  }

  Future<int> flushToInspector({
    required String baseUrl,
    required String token,
    required String channel,
    int? storeId,
    http.Client? client,
    int limit = 5,
  }) async {
    final normalizedBaseUrl = baseUrl.trim();
    final normalizedToken = token.trim();
    final normalizedChannel = channel.trim();
    if (_flushInProgress ||
        normalizedBaseUrl.isEmpty ||
        normalizedToken.isEmpty ||
        normalizedChannel.isEmpty ||
        limit <= 0) {
      return 0;
    }

    final pending = _events
        .where((event) =>
            event['remote_submitted_at'] == null &&
            _isRemoteEligible(event['type']?.toString()))
        .take(limit)
        .toList(growable: false);
    if (pending.isEmpty) return 0;

    _flushInProgress = true;
    final ownsClient = client == null;
    final transport = client ?? http.Client();
    var submitted = 0;

    try {
      final root = normalizedBaseUrl.replaceFirst(RegExp(r'/+$'), '');
      final endpoint = Uri.parse('$root/api/v1/runtime-inspector/events');

      for (final event in pending) {
        final payload = _inspectorPayload(
          event,
          channel: normalizedChannel,
          storeId: storeId,
        );
        if (payload == null) continue;

        try {
          final response = await transport.post(
            endpoint,
            headers: {
              'Accept': 'application/json',
              'Content-Type': 'application/json',
              'Authorization': 'Bearer $normalizedToken',
            },
            body: jsonEncode(payload),
          );
          if (response.statusCode != 202) break;

          event['remote_submitted_at'] =
              DateTime.now().toUtc().toIso8601String();
          submitted++;
        } catch (_) {
          break;
        }
      }

      if (submitted > 0) {
        _schedulePersist();
      }
      return submitted;
    } finally {
      if (ownsClient) transport.close();
      _flushInProgress = false;
      if (_flushRequested) {
        _flushRequested = false;
        _scheduleAutomaticFlush();
      }
    }
  }

  bool _isRemoteEligible(String? type) => const {
        'error',
        'http_failure',
        'driver_version_policy_failure',
        'driver_tracking_failure',
      }.contains(type);

  Map<String, dynamic>? _inspectorPayload(
    Map<String, dynamic> event, {
    required String channel,
    int? storeId,
  }) {
    final type = event['type']?.toString();
    if (!_isRemoteEligible(type)) return null;

    final status = (event['status_code'] as num?)?.toInt();
    final message = switch (type) {
      'error' =>
        event['message'] ?? event['error_type'] ?? 'Driver runtime error',
      'http_failure' => event['error'] ?? 'Driver API request failed',
      'driver_version_policy_failure' =>
        event['failure_class'] ?? 'Driver version policy failure',
      'driver_tracking_failure' =>
        event['code'] ?? 'Driver tracking failure',
      _ => 'Driver runtime failure',
    };

    return <String, dynamic>{
      'app': 'driver',
      'category': type,
      'severity': status != null && status < 500 ? 'warning' : 'error',
      'message': sanitizeForDiagnostics(message, maxLength: 2000),
      'app_version': _appVersion,
      'app_build': _appBuild,
      'platform': driverOperatingSystem,
      'os_version': sanitizeForDiagnostics(
        driverOperatingSystemVersion,
        maxLength: 240,
      ),
      if (_lastRoute != null) 'current_route': _lastRoute,
      'channel': channel,
      if (storeId != null && storeId > 0) 'store_id': storeId,
      if (event['method'] != null) 'method': event['method'],
      if (event['endpoint'] != null) 'path': event['endpoint'],
      if (status != null) 'status': status,
      if (event['correlation_id'] != null)
        'correlation_id': event['correlation_id'],
      if (event['attempt'] != null) 'attempt': event['attempt'],
      if (event['stack'] != null) 'stack': event['stack'],
      'metadata': {
        if (event['source'] != null) 'runtime_source': event['source'],
        if (event['error_type'] != null) 'error_type': event['error_type'],
        if (event['duration_ms'] != null)
          'duration_ms': event['duration_ms'],
        if (event['operation'] != null) 'operation': event['operation'],
        if (event['failure_class'] != null)
          'failure_class': event['failure_class'],
        if (event['code'] != null) 'failure_code': event['code'],
      },
    };
  }
  Future<void> clear() async {
    try {
      await _persistChain;
    } catch (_) {
      // Keep clearing even if a previous preference write failed.
    }
    _events.clear();
    _lastRoute = null;
    final preferences = _preferences;
    if (preferences != null) {
      try {
        await preferences.remove(_storageKey);
      } catch (_) {
        // Clearing diagnostics must not break the UI.
      }
    }
  }

  void _append(Map<String, dynamic> event) {
    final timestamp = DateTime.now().toUtc().toIso8601String();
    _events.add(<String, dynamic>{
      'timestamp': timestamp,
      ...event,
    });
    if (_events.length > maxEvents) {
      _events.removeRange(0, _events.length - maxEvents);
    }
    _schedulePersist();
    if (_isRemoteEligible(event['type']?.toString())) {
      _scheduleAutomaticFlush();
    }
  }

  void _schedulePersist() {
    final preferences = _preferences;
    if (preferences == null) return;

    final payload = jsonEncode(_events);
    _persistChain = _persistChain
        .then((_) => preferences.setString(_storageKey, payload))
        .then<void>((_) {})
        .catchError((_) {});
  }

  String _safeEndpoint(Uri uri) {
    final segments = uri.pathSegments.map((segment) {
      if (RegExp(r'^\d+$').hasMatch(segment)) return ':id';
      if (RegExp(
        r'^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$',
        caseSensitive: false,
      ).hasMatch(segment)) {
        return ':id';
      }
      return sanitizeForDiagnostics(segment, maxLength: 160);
    }).join('/');

    final port = uri.hasPort &&
            !((uri.scheme == 'https' && uri.port == 443) ||
                (uri.scheme == 'http' && uri.port == 80))
        ? ':${uri.port}'
        : '';
    final path = segments.isEmpty ? '/' : '/$segments';
    return '${uri.scheme}://${uri.host}$port$path';
  }
}

class DriverDiagnosticHttpClient extends http.BaseClient {
  DriverDiagnosticHttpClient(
    this._inner, {
    DriverRuntimeInspector? inspector,
  }) : _inspector = inspector ?? DriverRuntimeInspector.instance;

  final http.Client _inner;
  final DriverRuntimeInspector _inspector;

  @override
  Future<http.StreamedResponse> send(http.BaseRequest request) async {
    final stopwatch = Stopwatch()..start();
    try {
      final response = await _inner.send(request);
      stopwatch.stop();
      if (response.statusCode < 200 || response.statusCode >= 300) {
        _inspector.recordHttpFailure(
          method: request.method,
          uri: request.url,
          statusCode: response.statusCode,
          elapsed: stopwatch.elapsed,
        );
      } else {
        _inspector.notifyConnectivityRecovered();
      }
      return response;
    } catch (error) {
      stopwatch.stop();
      _inspector.recordHttpFailure(
        method: request.method,
        uri: request.url,
        elapsed: stopwatch.elapsed,
        error: error,
      );
      rethrow;
    }
  }

  @override
  void close() => _inner.close();
}
