import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:device_info_plus/device_info_plus.dart';
import 'package:flutter/widgets.dart';
import 'package:http/http.dart' as http;
import 'package:package_info_plus/package_info_plus.dart';
import 'package:shared_preferences/shared_preferences.dart';

class CustomerDiagnosticEvent {
  const CustomerDiagnosticEvent({
    required this.type,
    required this.timestamp,
    required this.data,
  });

  final String type;
  final DateTime timestamp;
  final Map<String, Object?> data;

  Map<String, Object?> toJson() => {
        'type': type,
        'timestamp': timestamp.toUtc().toIso8601String(),
        'data': data,
      };

  factory CustomerDiagnosticEvent.fromJson(Map<String, dynamic> json) {
    return CustomerDiagnosticEvent(
      type: json['type']?.toString() ?? 'unknown',
      timestamp: DateTime.tryParse(json['timestamp']?.toString() ?? '') ??
          DateTime.fromMillisecondsSinceEpoch(0, isUtc: true),
      data: Map<String, Object?>.from(
        json['data'] is Map ? json['data'] as Map : const {},
      ),
    );
  }
}

class CustomerDiagnostics extends ChangeNotifier {
  CustomerDiagnostics({this.maxEvents = 150});

  static final CustomerDiagnostics instance = CustomerDiagnostics();
  static const _storageKey = 'foodex_customer_diagnostics_v1';
  static const _redacted = '[REDACTED]';

  final int maxEvents;
  final List<CustomerDiagnosticEvent> _events = <CustomerDiagnosticEvent>[];

  SharedPreferences? _preferences;
  String _appVersion = 'unknown';
  String _buildNumber = 'unknown';
  String _deviceModel = 'unknown';
  final String _osVersion = Platform.operatingSystemVersion;
  String _apiBaseUrl = '';
  String _environment = 'unknown';
  String _route = '/';
  String _locale = 'unknown';
  String _authState = 'guest';
  String? _channel;
  int? _storeId;
  String _connectivity = 'unknown';
  DateTime? _lastSuccessfulApiContact;

  List<CustomerDiagnosticEvent> get events =>
      List<CustomerDiagnosticEvent>.unmodifiable(_events);
  String get route => _route;
  String get environment => _environment;
  String get appVersion => _appVersion;
  String get connectivity => _connectivity;
  DateTime? get lastSuccessfulApiContact => _lastSuccessfulApiContact;

  Future<void> initialize({
    required String apiBaseUrl,
    required String environment,
  }) async {
    _apiBaseUrl = apiBaseUrl;
    _environment = environment;
    try {
      _preferences = await SharedPreferences.getInstance();
      final raw = _preferences?.getString(_storageKey);
      if (raw != null && raw.isNotEmpty) {
        final decoded = jsonDecode(raw);
        if (decoded is List) {
          _events
            ..clear()
            ..addAll(
              decoded
                  .whereType<Map>()
                  .map((row) => CustomerDiagnosticEvent.fromJson(
                        Map<String, dynamic>.from(row),
                      ))
                  .take(maxEvents),
            );
        }
      }
    } catch (_) {
      _preferences = null;
    }

    try {
      final package = await PackageInfo.fromPlatform();
      _appVersion = package.version;
      _buildNumber = package.buildNumber;
    } catch (_) {}

    try {
      final deviceInfo = DeviceInfoPlugin();
      if (Platform.isAndroid) {
        final info = await deviceInfo.androidInfo;
        _deviceModel = '${info.manufacturer} ${info.model}'.trim();
      } else if (Platform.isIOS) {
        final info = await deviceInfo.iosInfo;
        _deviceModel = info.utsname.machine;
      }
    } catch (_) {}

    notifyListeners();
  }

  void updateContext({
    String? route,
    String? locale,
    bool? isAuthenticated,
    String? channel,
    bool? platformWide,
    int? storeId,
  }) {
    if (route != null) _route = _safeRoute(route);
    if (locale != null) _locale = locale;
    if (isAuthenticated != null) {
      _authState = isAuthenticated
          ? (platformWide == true ? 'authenticated_platform' : 'authenticated')
          : 'guest';
      if (!isAuthenticated) {
        _channel = null;
        _storeId = null;
      }
    }
    if (channel != null) _channel = channel;
    if (storeId != null) _storeId = storeId;
    notifyListeners();
  }

  void record(String type, [Map<String, Object?> data = const {}]) {
    _events.add(
      CustomerDiagnosticEvent(
        type: type,
        timestamp: DateTime.now().toUtc(),
        data: Map<String, Object?>.from(
          sanitize(data) as Map<String, Object?>,
        ),
      ),
    );
    while (_events.length > maxEvents) {
      _events.removeAt(0);
    }
    notifyListeners();
    unawaited(_persist());
  }

  void recordError(String type, Object error, StackTrace? stackTrace) {
    record(type, {
      'error': error.toString(),
      if (stackTrace != null) 'stack': _clip(stackTrace.toString(), 4000),
    });
  }

  void markApiSuccess() {
    _connectivity = 'online';
    _lastSuccessfulApiContact = DateTime.now().toUtc();
    notifyListeners();
  }

  void markApiFailure({required bool connectivityFailure}) {
    if (connectivityFailure) _connectivity = 'offline';
    notifyListeners();
  }

  Future<void> clear() async {
    _events.clear();
    notifyListeners();
    try {
      await _preferences?.remove(_storageKey);
    } catch (_) {}
  }

  Map<String, Object?> buildExport({String? note}) {
    return Map<String, Object?>.from(
      sanitize({
        'schema_version': 1,
        'generated_at': DateTime.now().toUtc().toIso8601String(),
        'app': {
          'name': 'FOODEX Customer',
          'version': _appVersion,
          'build': _buildNumber,
          'platform': Platform.operatingSystem,
          'os_version': _osVersion,
          'device_model': _deviceModel,
          'environment': _environment,
          'api_base_url': _apiBaseUrl,
        },
        'context': {
          'route': _route,
          'locale': _locale,
          'auth_state': _authState,
          'channel': _channel,
          'store_id': _storeId,
        },
        'health': {
          'connectivity': _connectivity,
          'last_successful_api_contact':
              _lastSuccessfulApiContact?.toIso8601String(),
          'event_count': _events.length,
          'last_event_at':
              _events.isEmpty ? null : _events.last.timestamp.toIso8601String(),
        },
        if (note != null && note.trim().isNotEmpty) 'user_note': note.trim(),
        'events': _events.map((event) => event.toJson()).toList(growable: false),
      }) as Map,
    );
  }

  String copySummary() {
    final last = _events.isEmpty ? null : _events.last;
    return [
      'FOODEX Customer $_appVersion+$_buildNumber',
      'Environment: $_environment',
      'Route: $_route',
      'Auth: $_authState${_channel == null ? '' : ' / $_channel'}',
      'Connectivity: $_connectivity',
      'Events: ${_events.length}',
      if (last != null)
        'Last event: ${last.type} @ ${last.timestamp.toIso8601String()}',
    ].join('\n');
  }

  static Object? sanitize(Object? value, {String? key}) {
    if (key != null && _isSensitiveKey(key)) return _redacted;
    if (value is Map) {
      final output = <String, Object?>{};
      for (final entry in value.entries) {
        final childKey = entry.key.toString();
        output[childKey] = sanitize(entry.value, key: childKey);
      }
      return output;
    }
    if (value is Iterable) {
      return value.map((item) => sanitize(item)).toList(growable: false);
    }
    if (value is String) return _sanitizeString(value);
    return value;
  }

  static bool _isSensitiveKey(String key) {
    final normalized = key.toLowerCase().replaceAll('-', '_');
    const fragments = <String>[
      'password',
      'token',
      'authorization',
      'cookie',
      'secret',
      'email',
      'phone',
      'civil',
      'address',
      'latitude',
      'longitude',
      'coordinate',
      'card_number',
      'cvv',
      'payment_data',
    ];
    return fragments.any(normalized.contains);
  }

  static String _sanitizeString(String input) {
    var value = input;
    value = value.replaceAll(
      RegExp(r'Bearer\s+[A-Za-z0-9._~+\/-]+=*', caseSensitive: false),
      'Bearer $_redacted',
    );
    value = value.replaceAll(
      RegExp(r'[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}', caseSensitive: false),
      _redacted,
    );
    value = value.replaceAll(
      RegExp(r'(?<!\d)\d{8,16}(?!\d)'),
      _redacted,
    );
    return _clip(value, 5000);
  }

  static String _safeRoute(String route) {
    final uri = Uri.tryParse(route);
    return uri?.path.isNotEmpty == true ? uri!.path : '/';
  }

  static String _clip(String value, int limit) =>
      value.length <= limit ? value : '${value.substring(0, limit)}…';

  Future<void> _persist() async {
    final prefs = _preferences;
    if (prefs == null) return;
    try {
      await prefs.setString(
        _storageKey,
        jsonEncode(_events.map((event) => event.toJson()).toList()),
      );
    } catch (_) {}
  }
}

class CustomerDiagnosticsHttpClient extends http.BaseClient {
  CustomerDiagnosticsHttpClient(
    this.diagnostics, {
    http.Client? inner,
  }) : _inner = inner ?? http.Client();

  final CustomerDiagnostics diagnostics;
  final http.Client _inner;

  @override
  Future<http.StreamedResponse> send(http.BaseRequest request) async {
    final started = DateTime.now();
    try {
      final response = await _inner.send(request);
      final elapsed = DateTime.now().difference(started).inMilliseconds;
      if (response.statusCode >= 200 && response.statusCode < 400) {
        diagnostics.markApiSuccess();
      } else {
        diagnostics.markApiFailure(connectivityFailure: false);
        diagnostics.record('api_failure', {
          'method': request.method,
          'path': request.url.path,
          'status_code': response.statusCode,
          'duration_ms': elapsed,
          'request_id': response.headers['x-request-id'] ??
              response.headers['x-correlation-id'],
        });
      }
      return response;
    } catch (error, stackTrace) {
      diagnostics.markApiFailure(connectivityFailure: true);
      diagnostics.record('api_failure', {
        'method': request.method,
        'path': request.url.path,
        'status_code': null,
        'duration_ms': DateTime.now().difference(started).inMilliseconds,
        'error': error.toString(),
        'stack': CustomerDiagnostics._clip(stackTrace.toString(), 2500),
      });
      rethrow;
    }
  }

  @override
  void close() => _inner.close();
}

class CustomerDiagnosticsRouteObserver extends NavigatorObserver {
  CustomerDiagnosticsRouteObserver(this.diagnostics);

  final CustomerDiagnostics diagnostics;

  void _capture(Route<dynamic>? route) {
    final name = route?.settings.name;
    if (name == null || name.isEmpty) return;
    final safeRoute = Uri.tryParse(name)?.path ?? name;
    diagnostics.updateContext(route: safeRoute);
    diagnostics.record('navigation', {'route': safeRoute});
  }

  @override
  void didPush(Route<dynamic> route, Route<dynamic>? previousRoute) {
    _capture(route);
    super.didPush(route, previousRoute);
  }

  @override
  void didReplace({Route<dynamic>? newRoute, Route<dynamic>? oldRoute}) {
    _capture(newRoute);
    super.didReplace(newRoute: newRoute, oldRoute: oldRoute);
  }

  @override
  void didPop(Route<dynamic> route, Route<dynamic>? previousRoute) {
    _capture(previousRoute);
    super.didPop(route, previousRoute);
  }
}
