class VanVisitRecord {
  const VanVisitRecord({
    required this.id,
    required this.customerType,
    required this.customerId,
    required this.status,
    required this.allowedTransitions,
    this.storeId,
    this.routeKey,
    this.latitude,
    this.longitude,
    this.address,
    this.orderId,
    this.noOrderReasonId,
    this.plannedAt,
    this.startedAt,
    this.completedAt,
    this.closedAt,
  });

  final int id;
  final String customerType;
  final int customerId;
  final int? storeId;
  final String? routeKey;
  final double? latitude;
  final double? longitude;
  final String? address;
  final String status;
  final int? orderId;
  final int? noOrderReasonId;
  final String? plannedAt;
  final String? startedAt;
  final String? completedAt;
  final String? closedAt;
  final List<String> allowedTransitions;
}

class VanNoOrderReasonRecord {
  const VanNoOrderReasonRecord({
    required this.id,
    required this.code,
    required this.labelEn,
    required this.labelAr,
  });

  final int id;
  final String code;
  final String labelEn;
  final String labelAr;

  String label(bool arabic) =>
      arabic && labelAr.trim().isNotEmpty ? labelAr : labelEn;
}

abstract interface class VanVisitRepository {
  Future<List<VanVisitRecord>> visits({String? status});

  Future<List<VanNoOrderReasonRecord>> noOrderReasons();

  Future<VanVisitRecord> transition({
    required int visitId,
    required String status,
    int? orderId,
    int? noOrderReasonId,
  });
}
