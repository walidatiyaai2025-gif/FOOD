import '../../core/api/http_van_api.dart';
import '../../core/auth/van_session.dart';
import 'van_notification_contract.dart';

class HttpVanNotificationRepository implements VanNotificationRepository {
  HttpVanNotificationRepository(this.api);

  final VanApiClient api;

  @override
  Future<List<VanNotificationRecord>> notifications({
    required String locale,
  }) async {
    final decoded = _map(
      await api.getJson(
        'notifications?per_page=100&locale=${Uri.encodeQueryComponent(locale)}',
      ),
    );
    return _list(decoded['data']).map((row) {
      final data = _map(row);
      return VanNotificationRecord(
        id: _requiredInt(data['id']),
        type: _string(data['type']),
        title: _string(data['title']),
        body: _string(data['body']),
        publishedAt: _nullableString(data['published_at']),
        read: _nullableString(data['read_at']) != null,
      );
    }).toList(growable: false);
  }

  @override
  Future<void> markRead(int notificationId) async {
    await api.postJson('notifications/$notificationId/read');
  }

  Map<String, dynamic> _map(Object? value) {
    if (value is Map<String, dynamic>) return value;
    if (value is Map) return Map<String, dynamic>.from(value);
    throw const VanApiException('Invalid notification response.');
  }

  List<Object?> _list(Object? value) {
    if (value is List) return List<Object?>.from(value);
    throw const VanApiException('Invalid notification list response.');
  }

  int _requiredInt(Object? value) {
    if (value is int) return value;
    final parsed = int.tryParse(value?.toString() ?? '');
    if (parsed == null) {
      throw const VanApiException('Invalid notification identifier.');
    }
    return parsed;
  }

  String _string(Object? value) => value?.toString() ?? '';

  String? _nullableString(Object? value) {
    final text = value?.toString();
    return text == null || text.isEmpty ? null : text;
  }
}
