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
        body: jsonEncode({'email': email, 'password': password, 'app': 'van'}),
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

    final scopeRaw = user['van_scope'];
    if (scopeRaw is! Map) {
      throw const VanAccessDeniedException();
    }
    final scope = Map<String, dynamic>.from(scopeRaw);

    final session = VanSession(
      token: decoded['token'] as String,
      name: (user['name'] ?? '').toString(),
      email: (user['email'] ?? email).toString(),
      locale: (user['locale'] ?? 'ar').toString(),
      permissions: permissions,
      vanId: (scope['van_id'] as num?)?.toInt() ?? 0,
      vanCode: (scope['van_code'] ?? '').toString(),
      assignmentId: (scope['assignment_id'] as num?)?.toInt() ?? 0,
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
    Map<String, String> headers = const {},
  }) async {
    return _request(
      () => _client.post(
        _endpoint(path),
        headers: {..._headers, ...headers},
        body: jsonEncode(body),
      ),
    );
  }

  Future<Object?> postMultipart(
    String path, {
    Map<String, String> fields = const {},
    Map<String, String> headers = const {},
    String? fileField,
    String? filePath,
    String? fileName,
  }) async {
    try {
      final request = http.MultipartRequest('POST', _endpoint(path))
        ..headers.addAll({
          'Accept': 'application/json',
          'Authorization': 'Bearer $token',
          ...headers,
        })
        ..fields.addAll(fields);

      if (fileField != null && filePath != null) {
        request.files.add(
          await http.MultipartFile.fromPath(
            fileField,
            filePath,
            filename: fileName,
          ),
        );
      }

      final streamed = await _client.send(request);
      return _decodeResponse(await http.Response.fromStream(streamed));
    } on SocketException {
      throw const VanOfflineException();
    } on http.ClientException {
      throw const VanOfflineException();
    } on FileSystemException {
      throw const VanApiException('The selected proof image is unavailable.');
    }
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

    return _decodeResponse(response);
  }

  Object? _decodeResponse(http.Response response) {
    if (response.statusCode == 401) {
      throw const VanSessionExpiredException();
    }
    if (response.statusCode == 403) {
      throw const VanAccessDeniedException();
    }
    if (response.statusCode < 200 || response.statusCode >= 300) {
      var message = 'Van API request failed (${response.statusCode}).';
      if (response.body.trim().isNotEmpty) {
        try {
          final decoded = jsonDecode(response.body);
          if (decoded is Map && decoded['message'] is String) {
            final serverMessage = (decoded['message'] as String).trim();
            if (serverMessage.isNotEmpty) message = serverMessage;
          }
        } on FormatException {
          // Preserve the stable HTTP status fallback.
        }
      }
      throw VanApiException(message, response.statusCode);
    }
    if (response.body.trim().isEmpty) return null;

    try {
      return jsonDecode(response.body) as Object?;
    } on FormatException {
      throw const VanApiException('Invalid API response.');
    }
  }
}
