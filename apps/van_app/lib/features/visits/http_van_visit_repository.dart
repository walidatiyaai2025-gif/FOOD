import '../../core/api/http_van_api.dart';
import '../../core/auth/van_session.dart';
import 'van_visit_contract.dart';

class HttpVanVisitRepository implements VanVisitRepository {
  HttpVanVisitRepository(this.api);

  final VanApiClient api;

  @override
  Future<List<VanVisitRecord>> visits({String? status}) async {
    final query = status == null || status.trim().isEmpty
        ? 'van/visits?per_page=100'
        : 'van/visits?per_page=100&status=${Uri.encodeQueryComponent(status)}';
    final decoded = _map(await api.getJson(query));
    return _list(decoded['data'])
        .map((row) => _visit(_map(row)))
        .toList(growable: false);
  }

  @override
  Future<List<VanNoOrderReasonRecord>> noOrderReasons() async {
    final decoded = _map(await api.getJson('van/no-order-reasons'));
    return _list(decoded['data']).map((row) {
      final data = _map(row);
      return VanNoOrderReasonRecord(
        id: _requiredInt(data['id']),
        code: _string(data['code']),
        labelEn: _string(data['label_en']),
        labelAr: _string(data['label_ar']),
      );
    }).toList(growable: false);
  }

  @override
  Future<VanVisitRecord> transition({
    required int visitId,
    required String status,
    int? orderId,
    int? noOrderReasonId,
  }) async {
    final decoded = _map(
      await api.postJson(
        'van/visits/$visitId/transition',
        body: {
          'status': status,
          if (orderId != null) 'order_id': orderId,
          if (noOrderReasonId != null) 'no_order_reason_id': noOrderReasonId,
        },
      ),
    );
    return _visit(decoded);
  }

  VanVisitRecord _visit(Map<String, dynamic> data) {
    return VanVisitRecord(
      id: _requiredInt(data['id']),
      customerType: _string(data['customer_type']),
      customerId: _requiredInt(data['customer_id']),
      storeId: _int(data['store_id']),
      routeKey: _nullableString(data['route_key']),
      latitude: _double(data['latitude']),
      longitude: _double(data['longitude']),
      address: _nullableString(data['address']),
      status: _string(data['status']),
      orderId: _int(data['order_id']),
      noOrderReasonId: _int(data['no_order_reason_id']),
      plannedAt: _nullableString(data['planned_at']),
      startedAt: _nullableString(data['started_at']),
      completedAt: _nullableString(data['completed_at']),
      closedAt: _nullableString(data['closed_at']),
      allowedTransitions: _list(data['allowed_transitions'])
          .map((value) => value?.toString() ?? '')
          .where((value) => value.isNotEmpty)
          .toList(growable: false),
    );
  }

  Map<String, dynamic> _map(Object? value) {
    if (value is Map<String, dynamic>) return value;
    if (value is Map) return Map<String, dynamic>.from(value);
    throw const VanApiException('Invalid Van visit response.');
  }

  List<Object?> _list(Object? value) {
    if (value is List) return List<Object?>.from(value);
    throw const VanApiException('Invalid Van visit list response.');
  }

  int _requiredInt(Object? value) {
    final parsed = _int(value);
    if (parsed == null) {
      throw const VanApiException('Invalid Van visit identifier.');
    }
    return parsed;
  }

  int? _int(Object? value) {
    if (value is int) return value;
    return int.tryParse(value?.toString() ?? '');
  }

  double? _double(Object? value) {
    if (value is num) return value.toDouble();
    return double.tryParse(value?.toString() ?? '');
  }

  String _string(Object? value) => value?.toString() ?? '';

  String? _nullableString(Object? value) {
    final string = value?.toString();
    return string == null || string.isEmpty ? null : string;
  }
}
