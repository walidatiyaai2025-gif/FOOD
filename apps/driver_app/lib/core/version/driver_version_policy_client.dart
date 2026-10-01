import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:http/http.dart' as http;

import '../../version_policy.dart';
import '../diagnostics/driver_runtime_inspector.dart';

abstract interface class DriverVersionPolicyClient {
  Future<AppVersionPolicy> fetch();
}

class HttpDriverVersionPolicyClient implements DriverVersionPolicyClient {
  HttpDriverVersionPolicyClient({
    required String baseUrl,
    required this.platform,
    this.currentVersion = driverAppVersion,
    this.currentBuild = driverAppBuild,
    this.timeout = const Duration(seconds: 12),
    http.Client? client,
    DriverRuntimeInspector? inspector,
  })  : assert(platform == 'android' || platform == 'ios'),
        _base = _normalizedBase(baseUrl),
        _client = client ?? http.Client(),
        _inspector = inspector ?? DriverRuntimeInspector.instance;

  final Uri _base;
  final String platform;
  final String currentVersion;
  final String currentBuild;
  final Duration timeout;
  final http.Client _client;
  final DriverRuntimeInspector _inspector;
  int _attempt = 0;

  static Uri _normalizedBase(String raw) {
    final parsed = Uri.parse(raw);
    final path = parsed.path.endsWith('/') ? parsed.path : '${parsed.path}/';
    return parsed.replace(path: path);
  }

  Uri get endpoint => _base.resolve('api/v1/app-version').replace(
        queryParameters: {
          'app': 'driver',
          'platform': platform,
          'current_version': currentVersion,
        },
      );

  @override
  Future<AppVersionPolicy> fetch() async {
    final attempt = ++_attempt;
    final stopwatch = Stopwatch()..start();
    late final http.Response response;

    try {
      response = await _client
          .get(
            endpoint,
            headers: const {'Accept': 'application/json'},
          )
          .timeout(timeout);
    } on TimeoutException catch (error) {
      stopwatch.stop();
      _recordFailure(
        failureClass: 'timeout',
        elapsed: stopwatch.elapsed,
        attempt: attempt,
        error: error,
      );
      throw const DriverVersionPolicyOfflineException('timeout');
    } on SocketException catch (error) {
      stopwatch.stop();
      _recordFailure(
        failureClass: 'offline-socket',
        elapsed: stopwatch.elapsed,
        attempt: attempt,
        error: error,
      );
      throw const DriverVersionPolicyOfflineException('offline-socket');
    } on http.ClientException catch (error) {
      stopwatch.stop();
      _recordFailure(
        failureClass: 'client-error',
        elapsed: stopwatch.elapsed,
        attempt: attempt,
        error: error,
      );
      throw const DriverVersionPolicyOfflineException('client-error');
    } catch (error) {
      stopwatch.stop();
      _recordFailure(
        failureClass: 'client-error',
        elapsed: stopwatch.elapsed,
        attempt: attempt,
        error: error,
      );
      rethrow;
    }

    final correlationId = _correlationId(response);

    if (response.statusCode < 200 || response.statusCode >= 300) {
      stopwatch.stop();
      _recordFailure(
        failureClass: 'http',
        elapsed: stopwatch.elapsed,
        attempt: attempt,
        statusCode: response.statusCode,
        correlationId: correlationId,
      );
      throw DriverVersionPolicyException(
        response.statusCode,
        correlationId: correlationId,
      );
    }

    final dynamic decoded;
    try {
      decoded = jsonDecode(response.body);
    } on FormatException catch (error) {
      stopwatch.stop();
      _recordFailure(
        failureClass: 'invalid-json',
        elapsed: stopwatch.elapsed,
        attempt: attempt,
        correlationId: correlationId,
        error: error,
      );
      throw const FormatException('Invalid app version policy response.');
    } catch (error) {
      stopwatch.stop();
      _recordFailure(
        failureClass: 'invalid-json',
        elapsed: stopwatch.elapsed,
        attempt: attempt,
        correlationId: correlationId,
        error: error,
      );
      throw const FormatException('Invalid app version policy response.');
    }

    if (decoded is! Map) {
      stopwatch.stop();
      _recordFailure(
        failureClass: 'invalid-json',
        elapsed: stopwatch.elapsed,
        attempt: attempt,
        correlationId: correlationId,
      );
      throw const FormatException('Invalid app version policy response.');
    }

    try {
      final policy =
          AppVersionPolicy.fromJson(Map<String, dynamic>.from(decoded));
      stopwatch.stop();
      return policy;
    } catch (error) {
      stopwatch.stop();
      _recordFailure(
        failureClass: 'invalid-policy',
        elapsed: stopwatch.elapsed,
        attempt: attempt,
        correlationId: correlationId,
        error: error,
      );
      throw const FormatException('Invalid app version policy response.');
    }
  }

  void _recordFailure({
    required String failureClass,
    required Duration elapsed,
    required int attempt,
    int? statusCode,
    String? correlationId,
    Object? error,
  }) {
    _inspector.recordVersionPolicyFailure(
      failureClass: failureClass,
      uri: endpoint,
      elapsed: elapsed,
      statusCode: statusCode,
      platform: platform,
      appVersion: currentVersion,
      appBuild: currentBuild,
      attempt: attempt,
      correlationId: correlationId,
      error: error,
    );
  }

  String? _correlationId(http.Response response) {
    for (final name in const [
      'x-request-id',
      'x-correlation-id',
    ]) {
      for (final entry in response.headers.entries) {
        if (entry.key.toLowerCase() == name) {
          final value = entry.value.trim();
          if (value.isNotEmpty) return value;
        }
      }
    }
    return null;
  }

  void close() => _client.close();
}

class DriverVersionPolicyException implements Exception {
  const DriverVersionPolicyException(
    this.statusCode, {
    this.correlationId,
  });

  final int statusCode;
  final String? correlationId;
}

class DriverVersionPolicyOfflineException implements Exception {
  const DriverVersionPolicyOfflineException([
    this.failureClass = 'offline-socket',
  ]);

  final String failureClass;
}
