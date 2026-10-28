class DriverCollectedOrder {
  const DriverCollectedOrder({
    required this.orderId,
    required this.orderNumber,
    required this.currency,
    required this.orderTotal,
    required this.collectedAmount,
    required this.remainingAmount,
    required this.paymentState,
  });

  final int orderId;
  final String orderNumber;
  final String currency;
  final double orderTotal;
  final double collectedAmount;
  final double remainingAmount;
  final String paymentState;

  factory DriverCollectedOrder.fromJson(Map<String, dynamic> json) =>
      DriverCollectedOrder(
        orderId: (json['order_id'] as num?)?.toInt() ?? 0,
        orderNumber: (json['order_number'] ?? '').toString(),
        currency: (json['currency'] ?? '').toString(),
        orderTotal: (json['order_total'] as num?)?.toDouble() ?? 0,
        collectedAmount: (json['collected_amount'] as num?)?.toDouble() ?? 0,
        remainingAmount: (json['remaining_amount'] as num?)?.toDouble() ?? 0,
        paymentState: (json['payment_state'] ?? 'unpaid').toString(),
      );
}

class DriverCollectionReceipt {
  const DriverCollectionReceipt({
    required this.id,
    required this.amount,
    required this.currency,
    required this.status,
    required this.createdAt,
  });

  final int id;
  final double amount;
  final String currency;
  final String status;
  final String createdAt;

  factory DriverCollectionReceipt.fromJson(Map<String, dynamic> json) =>
      DriverCollectionReceipt(
        id: (json['id'] as num?)?.toInt() ?? 0,
        amount: (json['amount'] as num?)?.toDouble() ?? 0,
        currency: (json['currency'] ?? '').toString(),
        status: (json['status'] ?? '').toString(),
        createdAt: (json['created_at'] ?? '').toString(),
      );
}

class DriverRemittance {
  const DriverRemittance({
    required this.id,
    required this.amount,
    required this.currency,
    required this.method,
    required this.status,
    required this.createdAt,
    this.reference = '',
    this.note = '',
  });

  final int id;
  final double amount;
  final String currency;
  final String method;
  final String status;
  final String createdAt;
  final String reference;
  final String note;

  factory DriverRemittance.fromJson(Map<String, dynamic> json) =>
      DriverRemittance(
        id: (json['id'] as num?)?.toInt() ?? 0,
        amount: (json['amount'] as num?)?.toDouble() ?? 0,
        currency: (json['currency'] ?? '').toString(),
        method: (json['method'] ?? '').toString(),
        status: (json['status'] ?? '').toString(),
        createdAt: (json['created_at'] ?? '').toString(),
        reference: (json['reference'] ?? '').toString(),
        note: (json['note'] ?? '').toString(),
      );
}

class DriverWalletAccount {
  const DriverWalletAccount({
    required this.id,
    required this.currency,
    required this.status,
    required this.custodyBalance,
    required this.availableToRemit,
    this.unsettledCollections,
    this.totalCollected = 0,
    this.collectedOrders = const [],
    this.hasMoreCollectedOrders = false,
    this.transactions = const [],
    this.remittances = const [],
  });

  final int id;
  final String currency;
  final String status;
  final double custodyBalance;
  final double availableToRemit;
  final double? unsettledCollections;
  double get unsettledCollectionsBalance =>
      unsettledCollections ?? custodyBalance;
  final double totalCollected;
  final List<DriverCollectedOrder> collectedOrders;
  final bool hasMoreCollectedOrders;
  final List<DriverCollectionReceipt> transactions;
  final List<DriverRemittance> remittances;

  factory DriverWalletAccount.fromJson(Map<String, dynamic> json) =>
      DriverWalletAccount(
        id: (json['id'] as num?)?.toInt() ?? 0,
        currency: (json['currency'] ?? '').toString(),
        status: (json['status'] ?? '').toString(),
        custodyBalance: (json['custody_balance'] as num?)?.toDouble() ?? 0,
        availableToRemit: (json['available_to_remit'] as num?)?.toDouble() ?? 0,
        unsettledCollections:
            (json['unsettled_collections'] as num?)?.toDouble(),
        totalCollected: (json['total_collected'] as num?)?.toDouble() ?? 0,
        collectedOrders: (json['orders'] as List? ?? const [])
            .whereType<Map>()
            .map((row) => DriverCollectedOrder.fromJson(
                  Map<String, dynamic>.from(row),
                ))
            .toList(growable: false),
        hasMoreCollectedOrders: json['has_more'] == true,
        transactions: (json['transactions'] as List? ?? const [])
            .whereType<Map>()
            .map((row) => DriverCollectionReceipt.fromJson(
                  Map<String, dynamic>.from(row),
                ))
            .toList(growable: false),
        remittances: (json['remittances'] as List? ?? const [])
            .whereType<Map>()
            .map((row) => DriverRemittance.fromJson(
                  Map<String, dynamic>.from(row),
                ))
            .toList(growable: false),
      );
}

class DriverCollectionResult {
  const DriverCollectionResult({
    required this.receipt,
    required this.remainingAmount,
    required this.currency,
  });

  final DriverCollectionReceipt receipt;
  final double remainingAmount;
  final String currency;
}

abstract interface class DriverCollectionRepository {
  Future<DriverCollectionResult> collect(
    int assignmentId, {
    required double amount,
    required String idempotencyKey,
  });
}

abstract interface class DriverWalletRepository
    implements DriverCollectionRepository {
  Future<List<DriverWalletAccount>> wallet();

  Future<DriverRemittance> submitRemittance({
    required int collectionAccountId,
    required double amount,
    required String method,
    String? reference,
    String? note,
    required String idempotencyKey,
  });
}
