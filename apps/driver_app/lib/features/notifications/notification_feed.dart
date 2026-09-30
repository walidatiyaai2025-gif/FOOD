import 'dart:convert';

import 'package:http/http.dart' as http;

import '../../core/diagnostics/driver_runtime_inspector.dart';

class DriverNotification {
  const DriverNotification({
    required this.id,
    required this.title,
    required this.body,
    required this.readAt,
    required this.data,
    this.type = '',
  });

  final int id;
  final String title;
  final String body;
  final DateTime? readAt;
  final String type;
  final Map<String, dynamic> data;

  int? get assignmentId =>
      int.tryParse((data['assignment_id'] ?? '').toString());

  int? get orderId => int.tryParse((data['order_id'] ?? '').toString());

  bool get accessRevoked => const {'1', 'true', 'yes'}
      .contains((data['access_revoked'] ?? '').toString().toLowerCase());

  factory DriverNotification.fromJson(Map<String, dynamic> json) =>
      DriverNotification(
        id: (json['id'] as num).toInt(),
        title: (json['title'] ?? '').toString(),
        body: (json['body'] ?? '').toString(),
        readAt: json['read_at'] == null
            ? null
            : DateTime.tryParse(json['read_at'].toString()),
        type: (json['type'] ?? '').toString(),
        data: json['data'] is Map
            ? Map<String, dynamic>.from(json['data'] as Map)
            : const <String, dynamic>{},
      );
}

abstract interface class DriverNotificationRepository {
  Future<List<DriverNotification>> list({required String locale});

  Future<void> markRead(int notificationId);
}

class HttpDriverNotificationRepository implements DriverNotificationRepository {
  HttpDriverNotificationRepository({
    required this.baseUrl,
    required this.token,
    http.Client? client,
  }) : _client = DriverDiagnosticHttpClient(client ?? http.Client());

  final String baseUrl;
  final String token;
  final http.Client _client;

  Map<String, String> get _headers => {
        'Accept': 'application/json',
        'Authorization': 'Bearer $token',
      };

  @override
  Future<List<DriverNotification>> list({required String locale}) async {
    final response = await _client.get(
      Uri.parse('$baseUrl/api/v1/notifications').replace(
        queryParameters: {'locale': locale},
      ),
      headers: _headers,
    );

    if (response.statusCode == 401) {
      throw const DriverNotificationException('session_expired');
    }
    if (response.statusCode < 200 || response.statusCode >= 300) {
      throw DriverNotificationException(
        'http_${response.statusCode}',
      );
    }

    final decoded = jsonDecode(response.body);
    if (decoded is! Map<String, dynamic>) {
      throw const DriverNotificationException('invalid_response');
    }

    return (decoded['data'] as List<dynamic>? ?? const [])
        .whereType<Map>()
        .map((row) => DriverNotification.fromJson(
              Map<String, dynamic>.from(row),
            ))
        .toList(growable: false);
  }

  @override
  Future<void> markRead(int notificationId) async {
    final response = await _client.post(
      Uri.parse('$baseUrl/api/v1/notifications/$notificationId/read'),
      headers: _headers,
    );

    if (response.statusCode == 401) {
      throw const DriverNotificationException('session_expired');
    }
    if (response.statusCode < 200 || response.statusCode >= 300) {
      throw DriverNotificationException(
        'http_${response.statusCode}',
      );
    }
  }
}

class DriverNotificationException implements Exception {
  const DriverNotificationException(this.code);

  final String code;
}

Future<List<DriverNotification>> fetchDriverNotifications({
  required String baseUrl,
  required String token,
  required String locale,
  http.Client? client,
}) {
  return HttpDriverNotificationRepository(
    baseUrl: baseUrl,
    token: token,
    client: client,
  ).list(locale: locale);
}
