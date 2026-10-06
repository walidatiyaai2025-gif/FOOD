import 'dart:convert';
import 'dart:io';

import 'package:http/http.dart' as http;

import '../auth/van_session.dart';

Uri _apiBase(String raw) {
  final parsed = Uri.parse(raw);
  final path = parsed.path.endsWith('/') ? parsed.path : '${parsed.path}/';
  return parsed.replace(path: path);
}

class HttpVanAuthRepository implements VanAuthRepository {
  HttpVanAuthRepository(String baseUrl, {http.Client? client})
      : _base = _apiBase(baseUrl),
        _client = client ?? http.Client();

  final Uri _base;
  final http.Client _client;

  Uri _endpoint(String path) => _base.resolve('api/v1/$path');

  @override
  Future<VanSession> login({
    required String email,
    required String password,
  }) async {
    final http.Response response;
    try {
      response = await _client.post(
        _endpoint('auth/login'),
        headers: const {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
        },
        body: jsonEncode({'email': email, 'password': password}),
      );
    } on SocketException {
      throw const VanOfflineException();
    } on http.ClientException {
      throw const VanOfflineException();
    }

    if (response.statusCode == 401 || response.statusCode == 422) {
      throw const VanAuthenticationException();
    }
    if (response.statusCode < 200 || response.statusCode >= 300) {
      throw const VanApiException();
    }

    final decoded = jsonDecode(response.body);
    if (decoded is! Map<String, dynamic> ||
        decoded['token'] is! String ||
        decoded['user'] is! Map) {
      throw const VanApiException('Invalid login response.');
    }

    final user = Map<String, dynamic>.from(decoded['user'] as Map);
    final permissions = (user['permissions'] as List? ?? const [])
        .map((value) => value.toString())
        .where((value) => value.isNotEmpty)
        .toSet();

    final session = VanSession(
      token: decoded['token'] as String,
      name: (user['name'] ?? '').toString(),
      email: (user['email'] ?? email).toString(),
      locale: (user['locale'] ?? 'ar').toString(),
      permissions: permissions,
    );

    if (!session.canUseVan) {
      throw const VanAccessDeniedException();
    }

    return session;
  }

  @override
  Future<void> logout(String token) async {
    try {
      final response = await _client.post(
        _endpoint('auth/logout'),
        headers: {
          'Accept': 'application/json',
          'Authorization': 'Bearer $token',
        },
      );
      if (response.statusCode == 204 || response.statusCode == 401) return;
      if (response.statusCode < 200 || response.statusCode >= 300) {
        throw const VanApiException();
      }
    } on SocketException {
      throw const VanOfflineException();
    } on http.ClientException {
      throw const VanOfflineException();
    }
  }
}

class VanApiClient {
  VanApiClient(String baseUrl, this.token, {http.Client? client})
      : _base = _apiBase(baseUrl),
        _client = client ?? http.Client();

  final Uri _base;
  final String token;
  final http.Client _client;

  Uri _endpoint(String path) => _base.resolve('api/v1/$path');

  Map<String, String> get _headers => {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'Authorization': 'Bearer $token',
      };

  Future<Object?> getJson(String path) async {
    return _request(() => _client.get(_endpoint(path), headers: _headers));
  }

  Future<Object?> postJson(
    String path, {
    Map<String, Object?> body = const {},
  }) async {
    return _request(
      () => _client.post(
        _endpoint(path),
        headers: _headers,
        body: jsonEncode(body),
      ),
    );
  }

  Future<Object?> _request(Future<http.Response> Function() request) async {
    final http.Response response;
    try {
      response = await request();
    } on SocketException {
      throw const VanOfflineException();
    } on http.ClientException {
      throw const VanOfflineException();
    }

    if (response.statusCode == 401) {
      throw const VanSessionExpiredException();
    }
    if (response.statusCode == 403) {
      throw const VanAccessDeniedException();
    }
    if (response.statusCode < 200 || response.statusCode >= 300) {
      throw VanApiException('Van API request failed (${response.statusCode}).');
    }
    if (response.body.trim().isEmpty) return null;

    try {
      return jsonDecode(response.body) as Object?;
    } on FormatException {
      throw const VanApiException('Invalid API response.');
    }
  }
}
