import '../../core/api/b2c_account_api.dart';

List<Map<String, dynamic>> customerAccountRows(Object? value) {
  if (value is Map && value['data'] is List) {
    return (value['data'] as List)
        .whereType<Map>()
        .map((item) => Map<String, dynamic>.from(item))
        .toList(growable: false);
  }
  if (value is List) {
    return value
        .whereType<Map>()
        .map((item) => Map<String, dynamic>.from(item))
        .toList(growable: false);
  }
  return const <Map<String, dynamic>>[];
}

Map<String, dynamic> customerAccountMap(Object? value) {
  if (value is Map<String, dynamic>) return value;
  if (value is Map) return Map<String, dynamic>.from(value);
  return const <String, dynamic>{};
}

String customerAccountErrorKey(Object? error) {
  if (error is B2cAccountException) {
    switch (error.code) {
      case 'network_unavailable':
        return 'customer.error.offline';
      case 'session_expired':
      case 'authentication_required':
        return 'customer.error.session_expired';
      case 'forbidden':
        return 'customer.error.forbidden';
    }
  }
  return 'customer.error.action_failed';
}

class CustomerNotificationTarget {
  const CustomerNotificationTarget({
    required this.orderId,
    required this.channel,
    this.storeId,
  });

  final int orderId;
  final String channel;
  final int? storeId;
}
