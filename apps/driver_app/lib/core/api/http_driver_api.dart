import 'dart:convert';
import 'dart:io';

import 'package:http/http.dart' as http;

import '../../features/tasks/driver_journey.dart';
import '../auth/driver_session.dart';

Uri _driverApiBase(String raw) {
  final parsed = Uri.parse(raw);
  final path = parsed.path.endsWith('/') ? parsed.path : '${parsed.path}/';
  return parsed.replace(path: path);
}

class HttpDriverAuthRepository implements DriverAuthRepository {
  HttpDriverAuthRepository(String baseUrl, {http.Client? client})
      : _base = _driverApiBase(baseUrl),
        _client = client ?? http.Client();

  final Uri _base;
  final http.Client _client;

  Uri _endpoint(String path) => _base.resolve('api/v1/$path');

  @override
  Future<DriverSession> login({
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
      throw const DriverOfflineException();
    } on http.ClientException {
      throw const DriverOfflineException();
    }

    if (response.statusCode == 401 || response.statusCode == 422) {
      throw const DriverAuthenticationException();
    }
    if (response.statusCode < 200 || response.statusCode >= 300) {
      throw const DriverApiException();
    }

    final decoded = jsonDecode(response.body);
    if (decoded is! Map<String, dynamic> ||
        decoded['token'] is! String ||
        decoded['user'] is! Map) {
      throw const DriverApiException('Invalid login response.');
    }

    final user = Map<String, dynamic>.from(decoded['user'] as Map);
    final roles = (user['roles'] as List? ?? const [])
        .map((role) => role.toString().toUpperCase())
        .where((role) => role == 'B2C_DRIVER' || role == 'B2B_DRIVER')
        .toSet();

    if (roles.length != 1) {
      throw const DriverRoleDeniedException();
    }

    final channel =
        roles.single == 'B2C_DRIVER' ? DriverChannel.b2c : DriverChannel.b2b;

    return DriverSession(
      token: decoded['token'] as String,
      name: (user['name'] ?? '').toString(),
      email: (user['email'] ?? email).toString(),
      locale: (user['locale'] ?? 'ar').toString(),
      channel: channel,
    );
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
      if (response.statusCode == 401 || response.statusCode == 204) return;
      if (response.statusCode < 200 || response.statusCode >= 300) {
        throw const DriverApiException();
      }
    } on SocketException {
      throw const DriverOfflineException();
    } on http.ClientException {
      throw const DriverOfflineException();
    }
  }
}

class HttpDriverAssignmentRepository implements DriverAssignmentRepository {
  HttpDriverAssignmentRepository(
    String baseUrl,
    this.token, {
    http.Client? client,
  })  : _base = _driverApiBase(baseUrl),
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

  @override
  Future<List<DriverAssignment>> list(DriverChannel channel) async {
    final response = await _request(
      () => _client.get(_endpoint('driver/assignments'), headers: _headers),
    );
    final decoded = jsonDecode(response.body);
    if (decoded is! Map<String, dynamic> || decoded['data'] is! List) {
      throw const DriverApiException('Invalid assignment response.');
    }

    return (decoded['data'] as List)
        .map((raw) {
          if (raw is! Map) {
            throw const DriverApiException('Invalid assignment row.');
          }
          final map = Map<String, dynamic>.from(raw);
          final assignmentChannel = switch (
              (map['assignment_type'] ?? '').toString().toLowerCase()) {
            'b2c' => DriverChannel.b2c,
            'b2b' => DriverChannel.b2b,
            _ => throw const DriverApiException('Invalid assignment channel.'),
          };
          final orderId = (map['order_id'] as num?)?.toInt() ?? 0;
          final statuses = (map['available_statuses'] as List? ?? const [])
              .map((status) => status.toString())
              .toList(growable: false);

          return DriverAssignment(
            id: (map['id'] as num).toInt(),
            orderId: orderId,
            channel: assignmentChannel,
            reference: '#$orderId',
            status: (map['status'] ?? '').toString(),
            availableStatuses: statuses,
          );
        })
        .where((assignment) => assignment.channel == channel)
        .toList(growable: false);
  }

  @override
  Future<void> transition(
    int id,
    DriverChannel channel,
    String status,
  ) async {
    await _request(
      () => _client.post(
        _endpoint('driver/assignments/$id/status'),
        headers: _headers,
        body: jsonEncode({'status': status}),
      ),
    );
  }

  Future<http.Response> _request(
    Future<http.Response> Function() request,
  ) async {
    final http.Response response;
    try {
      response = await request();
    } on SocketException {
      throw const DriverOfflineException();
    } on http.ClientException {
      throw const DriverOfflineException();
    }

    if (response.statusCode == 401) {
      throw const DriverSessionExpiredException();
    }
    if (response.statusCode == 403) {
      throw const DriverAccessDeniedException();
    }
    if (response.statusCode < 200 || response.statusCode >= 300) {
      throw const DriverApiException();
    }
    return response;
  }
}
