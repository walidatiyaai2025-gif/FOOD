import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter/widgets.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';

class CustomerDiagnostics {
  CustomerDiagnostics({this.maxEvents = 120});

  static final CustomerDiagnostics instance = CustomerDiagnostics();

  static const _storageKey = 'foodex.customer.diagnostics.events.v1';
  static const schemaVersion = 1;

  final int maxEvents;
  final List<Map<String, dynamic>> _events = <Map<String, dynamic>>[];

  SharedPreferences? _preferences;
  String? _appVersion;
  String? _apiBaseUrl;
  String? _locale;
  String? _authState;
  String? _channel;
  bool _platformWide = false;
  int? _retailStoreContextId;
  String? _currentRoute;
  String? _lastSuccessfulApiAt;
  String _networkState = 'unknown';
  bool _flushInProgress = false;
  bool _flushRequested = false;
  String? _uploadBaseUrl;
  String? _uploadToken;

  List<Map<String, dynamic>> get events =>
      List<Map<String, dynamic>>.unmodifiable(_events);

  String? get currentRoute => _currentRoute;
  String? get lastSuccessfulApiAt => _lastSuccessfulApiAt;
  String get networkState => _networkState;

  Future<void> initialize() async {
    _preferences ??= await SharedPreferences.getInstance();
    final raw = _preferences?.getString(_storageKey);
    if (raw == null || raw.isEmpty) return;

    try {
      final decoded = jsonDecode(raw);
      if (decoded is! List) return;
      _events
        ..clear()
        ..addAll(
          decoded
              .whereType<Map>()
              .map((row) => Map<String, dynamic>.from(row))
              .take(maxEvents),
        );
    } catch (_) {
      await _preferences?.remove(_storageKey);
    }
  }

  void updateContext({
    required String appVersion,
    required String apiBaseUrl,
    required String locale,
    required bool authenticated,
    String? channel,
    required bool platformWide,
    int? retailStoreContextId,
  }) {
    _appVersion = appVersion;
    _apiBaseUrl = _sanitizeBaseUrl(apiBaseUrl);
    _locale = locale;
    _authState = authenticated ? 'authenticated' : 'guest';
    _channel = channel;
    _platformWide = platformWide;
    _retailStoreContextId = retailStoreContextId;
  }

  void updateRoute(String? route) {
    final safe = _sanitizeRoute(route);
    if (safe == null || safe == _currentRoute) return;
    _currentRoute = safe;
    record('navigation', {'route': safe});
  }

  void record(
    String type,
    Map<String, Object?> details, {
    DateTime? at,
  }) {
    final event = <String, dynamic>{
      'timestamp': (at ?? DateTime.now()).toUtc().toIso8601String(),
      'type': type,
      'details': redact(details),
    };
    _events.add(event);
    if (_events.length > maxEvents) {
      _events.removeRange(0, _events.length - maxEvents);
    }
    _persist();
    if (_isRemoteEligible(type)) {
      _scheduleAutomaticFlush();
    }
  }

  void recordFlutterError(FlutterErrorDetails details) {
    record('flutter_error', {
      'exception': details.exceptionAsString(),
      'library': details.library,
      'context': details.context?.toDescription(),
      'stack': _boundedStack(details.stack),
    });
  }

  void recordDartError(Object error, StackTrace stack) {
    record('dart_error', {
      'error': error.toString(),
      'stack': _boundedStack(stack),
    });
  }

  void recordApiResponse({
    required String method,
    required Uri uri,
    required int statusCode,
    required Map<String, String> headers,
    required Duration elapsed,
  }) {
    _networkState = 'reachable';
    if (statusCode >= 200 && statusCode < 400) {
      _lastSuccessfulApiAt = DateTime.now().toUtc().toIso8601String();
      notifyConnectivityRecovered();
      return;
    }

    record('api_failure', {
      'method': method,
      'path': _sanitizeUri(uri),
      'status_code': statusCode,
      'correlation_id': _correlationId(headers),
      'elapsed_ms': elapsed.inMilliseconds,
    });
  }

  void recordApiError({
    required String method,
    required Uri uri,
    required Object error,
    required Duration elapsed,
  }) {
    _networkState = 'unreachable';
    record('api_error', {
      'method': method,
      'path': _sanitizeUri(uri),
      'error': error.toString(),
      'elapsed_ms': elapsed.inMilliseconds,
    });
  }

  void recordRuntimeFailure({
    required String operation,
    required String path,
    required String category,
    int? statusCode,
    String? supportReference,
  }) {
    String safePath;
    try {
      safePath = _sanitizeUri(Uri.parse(path));
    } catch (_) {
      safePath = _redactString(path.split('#').first);
    }

    record('runtime_failure', {
      'operation': operation,
      'path': safePath,
      'category': category,
      if (statusCode != null) 'status_code': statusCode,
      if (supportReference != null && supportReference.trim().isNotEmpty)
        'support_reference': supportReference.trim(),
    });
  }


  void configureInspectorUpload({
    required String baseUrl,
    required String token,
  }) {
    final normalizedBaseUrl = baseUrl.trim();
    final normalizedToken = token.trim();
    if (normalizedBaseUrl.isEmpty || normalizedToken.isEmpty) {
      clearInspectorUpload();
      return;
    }

    _uploadBaseUrl = normalizedBaseUrl;
    _uploadToken = normalizedToken;
    _scheduleAutomaticFlush();
  }

  void clearInspectorUpload() {
    _uploadBaseUrl = null;
    _uploadToken = null;
    _flushRequested = false;
  }

  void notifyConnectivityRecovered() {
    _scheduleAutomaticFlush();
  }

  void _scheduleAutomaticFlush() {
    final baseUrl = _uploadBaseUrl;
    final token = _uploadToken;
    if (baseUrl == null || token == null) return;

    if (_flushInProgress) {
      _flushRequested = true;
      return;
    }

    unawaited(flushToInspector(baseUrl: baseUrl, token: token));
  }

  Future<int> flushToInspector({
    required String baseUrl,
    required String token,
    http.Client? client,
    int limit = 5,
  }) async {
    final normalizedBaseUrl = baseUrl.trim();
    final normalizedToken = token.trim();
    if (_flushInProgress ||
        normalizedBaseUrl.isEmpty ||
        normalizedToken.isEmpty ||
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
      final root = normalizedBaseUrl.replaceFirst(RegExp(r'/+
    _events.clear();
    await _preferences?.remove(_storageKey);
  }

  Map<String, dynamic> exportPayload({String? note}) {
    final now = DateTime.now().toUtc().toIso8601String();
    return <String, dynamic>{
      'schema_version': schemaVersion,
      'generated_at': now,
      'app': {
        'name': 'FOODEX Customer',
        'version': _appVersion ?? 'unknown',
        'platform': Platform.operatingSystem,
        'os_version': Platform.operatingSystemVersion,
        'locale': _locale ?? 'unknown',
      },
      'environment': {
        'api_base_url': _apiBaseUrl ?? '',
        'network_state': _networkState,
        'last_successful_api_at': _lastSuccessfulApiAt,
      },
      'session': {
        'state': _authState ?? 'unknown',
        'channel': _channel,
        'platform_wide': _platformWide,
        'retail_store_context_id': _retailStoreContextId,
      },
      'navigation': {
        'current_route': _currentRoute,
      },
      if (note != null && note.trim().isNotEmpty)
        'user_note': redact(note.trim()),
      'event_count': _events.length,
      'events': _events.map((event) => redact(event)).toList(growable: false),
    };
  }

  String exportJson({String? note}) =>
      const JsonEncoder.withIndent('  ').convert(exportPayload(note: note));

  String summary({String? note}) {
    final payload = exportPayload(note: note);
    return [
      'FOODEX Customer diagnostics',
      'Version: ${payload['app']['version']}',
      'Platform: ${payload['app']['platform']}',
      'Route: ${payload['navigation']['current_route'] ?? '-'}',
      'Network: ${payload['environment']['network_state']}',
      'Events: ${payload['event_count']}',
      if (note != null && note.trim().isNotEmpty)
        'Note: ${redact(note.trim())}',
    ].join('\n');
  }

  Future<File> writeExportFile({String? note}) async {
    final directory = Directory.systemTemp;
    final timestamp = DateTime.now()
        .toUtc()
        .toIso8601String()
        .replaceAll(':', '')
        .replaceAll('.', '');
    final file = File(
      '${directory.path}/foodex-customer-diagnostics-$timestamp.json',
    );
    await file.writeAsString(exportJson(note: note), flush: true);
    return file;
  }

  static Object? redact(Object? value, {String? key}) {
    if (key != null && _isSensitiveKey(key)) {
      return '[REDACTED]';
    }

    if (value is Map) {
      return value.map<String, dynamic>(
        (rawKey, rawValue) => MapEntry(
          rawKey.toString(),
          redact(rawValue, key: rawKey.toString()),
        ),
      );
    }

    if (value is Iterable) {
      return value.map((item) => redact(item)).toList(growable: false);
    }

    if (value is String) {
      return _redactString(value);
    }

    return value;
  }

  static bool _isSensitiveKey(String key) {
    final normalized = key.toLowerCase().replaceAll(RegExp(r'[^a-z0-9]'), '');
    const exact = <String>{
      'password',
      'passwordconfirmation',
      'authorization',
      'cookie',
      'setcookie',
      'token',
      'accesstoken',
      'refreshtoken',
      'fcmtoken',
      'pushtoken',
      'guesttoken',
      'secret',
      'clientsecret',
      'credential',
      'credentials',
      'email',
      'phone',
      'phonenumber',
      'civilid',
      'civilnumber',
      'address',
      'addressline1',
      'addressline2',
      'line1',
      'line2',
      'recipientname',
      'latitude',
      'longitude',
      'coordinates',
      'locationcoordinates',
      'paymentcard',
      'cardnumber',
      'cvv',
    };
    if (exact.contains(normalized)) return true;

    return normalized.endsWith('password') ||
        normalized.endsWith('token') ||
        normalized.endsWith('secret') ||
        normalized.endsWith('authorization') ||
        normalized.endsWith('cookie') ||
        normalized.endsWith('email') ||
        normalized.endsWith('phone') ||
        normalized.endsWith('civilnumber') ||
        normalized.endsWith('latitude') ||
        normalized.endsWith('longitude');
  }

  static String _redactString(String input) {
    var value = input;
    // Strip coordinate pairs before generic key/value redaction. Otherwise a
    // value such as "coordinates=29.375859,47.977405" can be partially
    // consumed at the comma and leak the second coordinate.
    value = value.replaceAll(
      RegExp(
        r'(?<!\d)-?\d{1,3}\.\d{4,}\s*[,/]\s*-?\d{1,3}\.\d{4,}(?!\d)',
      ),
      '[REDACTED_COORDINATES]',
    );
    value = value.replaceAllMapped(
      RegExp(
        r'\b(password|passcode|token|access[_-]?token|refresh[_-]?token|fcm[_-]?token|push[_-]?token|guest[_-]?token|authorization|cookie|secret|client[_-]?secret|email|phone(?:[_-]?number)?|civil(?:[_-]?(?:id|number))?|address|latitude|longitude|coordinates|card[_-]?number|cvv)\s*[:=]\s*[^\n;,&]+',
        caseSensitive: false,
      ),
      (match) => '${match.group(1)}=[REDACTED]',
    );
    value = value.replaceAll(
      RegExp(r'Bearer\s+[^\s,;]+', caseSensitive: false),
      'Bearer [REDACTED]',
    );
    value = value.replaceAll(
      RegExp(r'[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}', caseSensitive: false),
      '[REDACTED_EMAIL]',
    );
    value = value.replaceAll(
      RegExp(r'eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}'),
      '[REDACTED_TOKEN]',
    );
    value = value.replaceAll(
      RegExp(r'(?<!\d)(?:\+?965[\s-]?)?[24569]\d{7}(?!\d)'),
      '[REDACTED_PHONE]',
    );
    value = value.replaceAll(
      RegExp(r'(?<!\d)\d{12}(?!\d)'),
      '[REDACTED_CIVIL_ID]',
    );
    return value.length > 4000 ? '${value.substring(0, 4000)}…' : value;
  }

  static String? _sanitizeRoute(String? route) {
    if (route == null || route.trim().isEmpty) return null;
    try {
      final uri = Uri.parse(route);
      return uri.replace(query: '', fragment: '').toString();
    } catch (_) {
      return route.split('?').first;
    }
  }

  static String _sanitizeUri(Uri uri) {
    final safeQuery = <String, String>{};
    uri.queryParameters.forEach((key, value) {
      safeQuery[key] = _isSensitiveKey(key)
          ? '[REDACTED]'
          : _redactString(value);
    });
    return uri
        .replace(
          userInfo: '',
          queryParameters: safeQuery.isEmpty ? null : safeQuery,
          fragment: '',
        )
        .toString();
  }

  static String _sanitizeBaseUrl(String value) {
    try {
      final uri = Uri.parse(value);
      return uri.replace(userInfo: '', query: '', fragment: '').toString();
    } catch (_) {
      return '';
    }
  }

  static String? _correlationId(Map<String, String> headers) {
    for (final key in const [
      'x-request-id',
      'x-correlation-id',
      'request-id',
      'traceparent',
    ]) {
      final value = headers[key];
      if (value != null && value.trim().isNotEmpty) {
        return _redactString(value.trim());
      }
    }
    return null;
  }

  static String? _boundedStack(StackTrace? stack) {
    if (stack == null) return null;
    final lines = stack.toString().split('\n').take(20);
    return _redactString(lines.join('\n'));
  }

  void _persist() {
    final preferences = _preferences;
    if (preferences == null) return;
    final payload = jsonEncode(_events);
    unawaited(
      preferences.setString(_storageKey, payload).then<void>((_) {}),
    );
  }
}

class CustomerDiagnosticsHttpClient extends http.BaseClient {
  CustomerDiagnosticsHttpClient(
    this._inner, {
    CustomerDiagnostics? diagnostics,
  }) : diagnostics = diagnostics ?? CustomerDiagnostics.instance;

  final http.Client _inner;
  final CustomerDiagnostics diagnostics;

  @override
  Future<http.StreamedResponse> send(http.BaseRequest request) async {
    final stopwatch = Stopwatch()..start();
    try {
      final response = await _inner.send(request);
      stopwatch.stop();
      diagnostics.recordApiResponse(
        method: request.method,
        uri: request.url,
        statusCode: response.statusCode,
        headers: response.headers,
        elapsed: stopwatch.elapsed,
      );
      return response;
    } catch (error) {
      stopwatch.stop();
      diagnostics.recordApiError(
        method: request.method,
        uri: request.url,
        error: error,
        elapsed: stopwatch.elapsed,
      );
      rethrow;
    }
  }

  @override
  void close() => _inner.close();
}

class CustomerDiagnosticsNavigatorObserver extends NavigatorObserver {
  CustomerDiagnosticsNavigatorObserver([CustomerDiagnostics? diagnostics])
      : diagnostics = diagnostics ?? CustomerDiagnostics.instance;

  final CustomerDiagnostics diagnostics;

  void _record(Route<dynamic>? route) {
    diagnostics.updateRoute(route?.settings.name);
  }

  @override
  void didPush(Route<dynamic> route, Route<dynamic>? previousRoute) {
    super.didPush(route, previousRoute);
    _record(route);
  }

  @override
  void didPop(Route<dynamic> route, Route<dynamic>? previousRoute) {
    super.didPop(route, previousRoute);
    _record(previousRoute);
  }

  @override
  void didReplace({Route<dynamic>? newRoute, Route<dynamic>? oldRoute}) {
    super.didReplace(newRoute: newRoute, oldRoute: oldRoute);
    _record(newRoute);
  }
}
), '');
      final endpoint = Uri.parse('$root/api/v1/runtime-inspector/events');

      for (final event in pending) {
        final payload = _inspectorPayload(event);
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
        _persist();
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
        'flutter_error',
        'dart_error',
        'api_failure',
        'api_error',
        'runtime_failure',
      }.contains(type);

  Map<String, dynamic>? _inspectorPayload(Map<String, dynamic> event) {
    final type = event['type']?.toString();
    if (!_isRemoteEligible(type)) return null;

    final details = event['details'] is Map
        ? Map<String, dynamic>.from(event['details'] as Map)
        : <String, dynamic>{};
    final status = (details['status_code'] as num?)?.toInt();
    final message = switch (type) {
      'flutter_error' =>
        (details['exception'] ?? 'Flutter runtime error').toString(),
      'dart_error' => (details['error'] ?? 'Dart runtime error').toString(),
      'api_failure' => 'Customer API request failed',
      'api_error' =>
        (details['error'] ?? 'Customer API network failure').toString(),
      'runtime_failure' =>
        (details['operation'] ??
                details['category'] ??
                'Customer runtime failure')
            .toString(),
      _ => 'Customer runtime failure',
    };

    return <String, dynamic>{
      'app': 'customer',
      'category': type,
      'severity': status != null && status < 500 ? 'warning' : 'error',
      'message': redact(message),
      'app_version': _appVersion ?? 'unknown',
      'platform': Platform.operatingSystem,
      'os_version': redact(Platform.operatingSystemVersion),
      if (_currentRoute != null) 'current_route': _currentRoute,
      if (_channel != null) 'channel': _channel,
      if (_retailStoreContextId != null)
        'store_id': _retailStoreContextId,
      if (details['method'] != null) 'method': details['method'],
      if (details['path'] != null) 'path': details['path'],
      if (status != null) 'status': status,
      if (details['correlation_id'] != null)
        'correlation_id': details['correlation_id'],
      if (details['correlation_id'] == null &&
          details['support_reference'] != null)
        'correlation_id': details['support_reference'],
      if (details['stack'] != null) 'stack': details['stack'],
      'metadata': redact({
        if (details['library'] != null) 'library': details['library'],
        if (details['context'] != null) 'context': details['context'],
        if (details['elapsed_ms'] != null)
          'elapsed_ms': details['elapsed_ms'],
        if (details['category'] != null)
          'failure_category': details['category'],
      }),
    };
  }

  Future<void> clear() async {
    _events.clear();
    await _preferences?.remove(_storageKey);
  }

  Map<String, dynamic> exportPayload({String? note}) {
    final now = DateTime.now().toUtc().toIso8601String();
    return <String, dynamic>{
      'schema_version': schemaVersion,
      'generated_at': now,
      'app': {
        'name': 'FOODEX Customer',
        'version': _appVersion ?? 'unknown',
        'platform': Platform.operatingSystem,
        'os_version': Platform.operatingSystemVersion,
        'locale': _locale ?? 'unknown',
      },
      'environment': {
        'api_base_url': _apiBaseUrl ?? '',
        'network_state': _networkState,
        'last_successful_api_at': _lastSuccessfulApiAt,
      },
      'session': {
        'state': _authState ?? 'unknown',
        'channel': _channel,
        'platform_wide': _platformWide,
        'retail_store_context_id': _retailStoreContextId,
      },
      'navigation': {
        'current_route': _currentRoute,
      },
      if (note != null && note.trim().isNotEmpty)
        'user_note': redact(note.trim()),
      'event_count': _events.length,
      'events': _events.map((event) => redact(event)).toList(growable: false),
    };
  }

  String exportJson({String? note}) =>
      const JsonEncoder.withIndent('  ').convert(exportPayload(note: note));

  String summary({String? note}) {
    final payload = exportPayload(note: note);
    return [
      'FOODEX Customer diagnostics',
      'Version: ${payload['app']['version']}',
      'Platform: ${payload['app']['platform']}',
      'Route: ${payload['navigation']['current_route'] ?? '-'}',
      'Network: ${payload['environment']['network_state']}',
      'Events: ${payload['event_count']}',
      if (note != null && note.trim().isNotEmpty)
        'Note: ${redact(note.trim())}',
    ].join('\n');
  }

  Future<File> writeExportFile({String? note}) async {
    final directory = Directory.systemTemp;
    final timestamp = DateTime.now()
        .toUtc()
        .toIso8601String()
        .replaceAll(':', '')
        .replaceAll('.', '');
    final file = File(
      '${directory.path}/foodex-customer-diagnostics-$timestamp.json',
    );
    await file.writeAsString(exportJson(note: note), flush: true);
    return file;
  }

  static Object? redact(Object? value, {String? key}) {
    if (key != null && _isSensitiveKey(key)) {
      return '[REDACTED]';
    }

    if (value is Map) {
      return value.map<String, dynamic>(
        (rawKey, rawValue) => MapEntry(
          rawKey.toString(),
          redact(rawValue, key: rawKey.toString()),
        ),
      );
    }

    if (value is Iterable) {
      return value.map((item) => redact(item)).toList(growable: false);
    }

    if (value is String) {
      return _redactString(value);
    }

    return value;
  }

  static bool _isSensitiveKey(String key) {
    final normalized = key.toLowerCase().replaceAll(RegExp(r'[^a-z0-9]'), '');
    const exact = <String>{
      'password',
      'passwordconfirmation',
      'authorization',
      'cookie',
      'setcookie',
      'token',
      'accesstoken',
      'refreshtoken',
      'fcmtoken',
      'pushtoken',
      'guesttoken',
      'secret',
      'clientsecret',
      'credential',
      'credentials',
      'email',
      'phone',
      'phonenumber',
      'civilid',
      'civilnumber',
      'address',
      'addressline1',
      'addressline2',
      'line1',
      'line2',
      'recipientname',
      'latitude',
      'longitude',
      'coordinates',
      'locationcoordinates',
      'paymentcard',
      'cardnumber',
      'cvv',
    };
    if (exact.contains(normalized)) return true;

    return normalized.endsWith('password') ||
        normalized.endsWith('token') ||
        normalized.endsWith('secret') ||
        normalized.endsWith('authorization') ||
        normalized.endsWith('cookie') ||
        normalized.endsWith('email') ||
        normalized.endsWith('phone') ||
        normalized.endsWith('civilnumber') ||
        normalized.endsWith('latitude') ||
        normalized.endsWith('longitude');
  }

  static String _redactString(String input) {
    var value = input;
    // Strip coordinate pairs before generic key/value redaction. Otherwise a
    // value such as "coordinates=29.375859,47.977405" can be partially
    // consumed at the comma and leak the second coordinate.
    value = value.replaceAll(
      RegExp(
        r'(?<!\d)-?\d{1,3}\.\d{4,}\s*[,/]\s*-?\d{1,3}\.\d{4,}(?!\d)',
      ),
      '[REDACTED_COORDINATES]',
    );
    value = value.replaceAllMapped(
      RegExp(
        r'\b(password|passcode|token|access[_-]?token|refresh[_-]?token|fcm[_-]?token|push[_-]?token|guest[_-]?token|authorization|cookie|secret|client[_-]?secret|email|phone(?:[_-]?number)?|civil(?:[_-]?(?:id|number))?|address|latitude|longitude|coordinates|card[_-]?number|cvv)\s*[:=]\s*[^\n;,&]+',
        caseSensitive: false,
      ),
      (match) => '${match.group(1)}=[REDACTED]',
    );
    value = value.replaceAll(
      RegExp(r'Bearer\s+[^\s,;]+', caseSensitive: false),
      'Bearer [REDACTED]',
    );
    value = value.replaceAll(
      RegExp(r'[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}', caseSensitive: false),
      '[REDACTED_EMAIL]',
    );
    value = value.replaceAll(
      RegExp(r'eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}'),
      '[REDACTED_TOKEN]',
    );
    value = value.replaceAll(
      RegExp(r'(?<!\d)(?:\+?965[\s-]?)?[24569]\d{7}(?!\d)'),
      '[REDACTED_PHONE]',
    );
    value = value.replaceAll(
      RegExp(r'(?<!\d)\d{12}(?!\d)'),
      '[REDACTED_CIVIL_ID]',
    );
    return value.length > 4000 ? '${value.substring(0, 4000)}…' : value;
  }

  static String? _sanitizeRoute(String? route) {
    if (route == null || route.trim().isEmpty) return null;
    try {
      final uri = Uri.parse(route);
      return uri.replace(query: '', fragment: '').toString();
    } catch (_) {
      return route.split('?').first;
    }
  }

  static String _sanitizeUri(Uri uri) {
    final safeQuery = <String, String>{};
    uri.queryParameters.forEach((key, value) {
      safeQuery[key] = _isSensitiveKey(key)
          ? '[REDACTED]'
          : _redactString(value);
    });
    return uri
        .replace(
          userInfo: '',
          queryParameters: safeQuery.isEmpty ? null : safeQuery,
          fragment: '',
        )
        .toString();
  }

  static String _sanitizeBaseUrl(String value) {
    try {
      final uri = Uri.parse(value);
      return uri.replace(userInfo: '', query: '', fragment: '').toString();
    } catch (_) {
      return '';
    }
  }

  static String? _correlationId(Map<String, String> headers) {
    for (final key in const [
      'x-request-id',
      'x-correlation-id',
      'request-id',
      'traceparent',
    ]) {
      final value = headers[key];
      if (value != null && value.trim().isNotEmpty) {
        return _redactString(value.trim());
      }
    }
    return null;
  }

  static String? _boundedStack(StackTrace? stack) {
    if (stack == null) return null;
    final lines = stack.toString().split('\n').take(20);
    return _redactString(lines.join('\n'));
  }

  void _persist() {
    final preferences = _preferences;
    if (preferences == null) return;
    final payload = jsonEncode(_events);
    unawaited(
      preferences.setString(_storageKey, payload).then<void>((_) {}),
    );
  }
}

class CustomerDiagnosticsHttpClient extends http.BaseClient {
  CustomerDiagnosticsHttpClient(
    this._inner, {
    CustomerDiagnostics? diagnostics,
  }) : diagnostics = diagnostics ?? CustomerDiagnostics.instance;

  final http.Client _inner;
  final CustomerDiagnostics diagnostics;

  @override
  Future<http.StreamedResponse> send(http.BaseRequest request) async {
    final stopwatch = Stopwatch()..start();
    try {
      final response = await _inner.send(request);
      stopwatch.stop();
      diagnostics.recordApiResponse(
        method: request.method,
        uri: request.url,
        statusCode: response.statusCode,
        headers: response.headers,
        elapsed: stopwatch.elapsed,
      );
      return response;
    } catch (error) {
      stopwatch.stop();
      diagnostics.recordApiError(
        method: request.method,
        uri: request.url,
        error: error,
        elapsed: stopwatch.elapsed,
      );
      rethrow;
    }
  }

  @override
  void close() => _inner.close();
}

class CustomerDiagnosticsNavigatorObserver extends NavigatorObserver {
  CustomerDiagnosticsNavigatorObserver([CustomerDiagnostics? diagnostics])
      : diagnostics = diagnostics ?? CustomerDiagnostics.instance;

  final CustomerDiagnostics diagnostics;

  void _record(Route<dynamic>? route) {
    diagnostics.updateRoute(route?.settings.name);
  }

  @override
  void didPush(Route<dynamic> route, Route<dynamic>? previousRoute) {
    super.didPush(route, previousRoute);
    _record(route);
  }

  @override
  void didPop(Route<dynamic> route, Route<dynamic>? previousRoute) {
    super.didPop(route, previousRoute);
    _record(previousRoute);
  }

  @override
  void didReplace({Route<dynamic>? newRoute, Route<dynamic>? oldRoute}) {
    super.didReplace(newRoute: newRoute, oldRoute: oldRoute);
    _record(newRoute);
  }
}
