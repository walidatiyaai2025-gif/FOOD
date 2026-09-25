import 'dart:convert';

import 'package:http/http.dart' as http;

class AppNotification {
  const AppNotification({required this.id, required this.title, required this.body, required this.readAt});
  final int id;
  final String title;
  final String body;
  final DateTime? readAt;

  factory AppNotification.fromJson(Map<String, dynamic> json) => AppNotification(
    id: json['id'] as int,
    title: json['title'] as String,
    body: json['body'] as String,
    readAt: json['read_at'] == null ? null : DateTime.parse(json['read_at'] as String),
  );
}

Future<List<AppNotification>> fetchCustomerNotifications({
  required String baseUrl,
  required String token,
  required String locale,
  http.Client? client,
}) async {
  final httpClient = client ?? http.Client();
  final response = await httpClient.get(
    Uri.parse('$baseUrl/api/v1/notifications?locale=$locale'),
    headers: {'Accept': 'application/json', 'Authorization': 'Bearer $token'},
  );
  if (response.statusCode < 200 || response.statusCode >= 300) return const [];
  final decoded = jsonDecode(response.body) as Map<String, dynamic>;
  return (decoded['data'] as List<dynamic>? ?? const [])
      .cast<Map<String, dynamic>>()
      .map(AppNotification.fromJson)
      .toList(growable: false);
}
