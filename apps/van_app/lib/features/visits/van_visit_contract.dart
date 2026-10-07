class VanVisitRecord {
  const VanVisitRecord({
    required this.id,
    required this.customerType,
    required this.customerId,
    required this.status,
    required this.allowedTransitions,
    this.storeId,
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
  final String status;
  final int? orderId;
  final int? noOrderReasonId;
  final String? plannedAt;
  final String? startedAt;
  final String? completedAt;
  final String? closedAt;
  final List<String> allowedTransitions;
}

abstract interface class VanVisitRepository {
  Future<List<VanVisitRecord>> visits({String? status});

  Future<VanVisitRecord> transition({
    required int visitId,
    required String status,
    int? orderId,
    int? noOrderReasonId,
  });
}
