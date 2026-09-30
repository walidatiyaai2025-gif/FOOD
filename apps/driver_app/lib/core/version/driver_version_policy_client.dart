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
    http.Client? client,
  })  : assert(platform == 'android' || platform == 'ios'),
        _base = _normalizedBase(baseUrl),
        _client = client ?? http.Client();

  final Uri _base;
  final String platform;
  final String currentVersion;
  final http.Client _client;

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
    final http.Response response;
    try {
      response = await _client
          .get(
            endpoint,
            headers: const {'Accept': 'application/json'},
          )
          .timeout(const Duration(seconds: 12));
    } on TimeoutException {
      throw const DriverVersionPolicyOfflineException();
    } on SocketException {
      throw const DriverVersionPolicyOfflineException();
    } on http.ClientException {
      throw const DriverVersionPolicyOfflineException();
    }

    if (response.statusCode < 200 || response.statusCode >= 300) {
      throw DriverVersionPolicyException(response.statusCode);
    }

    try {
      final decoded = jsonDecode(response.body);
      if (decoded is! Map) {
        throw const FormatException('Invalid app version policy response.');
      }
      return AppVersionPolicy.fromJson(Map<String, dynamic>.from(decoded));
    } on FormatException {
      rethrow;
    } catch (_) {
      throw const FormatException('Invalid app version policy response.');
    }
  }

  void close() => _client.close();
}

class DriverVersionPolicyException implements Exception {
  const DriverVersionPolicyException(this.statusCode);

  final int statusCode;
}

class DriverVersionPolicyOfflineException implements Exception {
  const DriverVersionPolicyOfflineException();
}
