class CustomerOrderContext {
  const CustomerOrderContext({
    required this.storeId,
    required this.channel,
  }) : assert(storeId > 0);

  final int storeId;
  final String channel;

  String get normalizedChannel => channel.toLowerCase();

  bool get isValidChannel =>
      normalizedChannel == 'b2b' || normalizedChannel == 'b2c';
}

class CustomerOrderPage {
  const CustomerOrderPage({
    required this.orders,
    required this.currentPage,
    required this.perPage,
    required this.total,
    required this.scope,
    this.allTotal = 0,
    this.statusCodes = const <String>[],
    this.statusCounts = const <String, int>{},
  });

  final List<CustomerOrderSummary> orders;
  final int currentPage;
  final int perPage;
  final int total;
  final String scope;
  final int allTotal;
  final List<String> statusCodes;
  final Map<String, int> statusCounts;

  factory CustomerOrderPage.fromJson(Map<String, dynamic> json) {
    final raw = json['data'];
    final meta = json['meta'] is Map
        ? Map<String, dynamic>.from(json['meta'] as Map)
        : const <String, dynamic>{};

    final orders = raw is List
        ? raw
            .whereType<Map>()
            .map((item) => CustomerOrderSummary.fromJson(
                  Map<String, dynamic>.from(item),
                ))
            .toList(growable: false)
        : const <CustomerOrderSummary>[];

    final rawCounts = meta['status_counts'] is Map
        ? Map<String, dynamic>.from(meta['status_counts'] as Map)
        : const <String, dynamic>{};
    final statusCounts = <String, int>{
      for (final entry in rawCounts.entries)
        entry.key.toLowerCase(): _int(entry.value),
    };
    final statusCodes = meta['status_codes'] is List
        ? (meta['status_codes'] as List)
            .map((value) => value.toString().toLowerCase())
            .where((value) => value.isNotEmpty)
            .toList(growable: false)
        : statusCounts.keys.toList(growable: false);

    return CustomerOrderPage(
      orders: orders,
      currentPage: _int(meta['current_page'], fallback: 1),
      perPage: _int(meta['per_page'], fallback: orders.length),
      total: _int(meta['total'], fallback: orders.length),
      scope: meta['scope']?.toString() ?? '',
      allTotal: _int(
        meta['all_total'],
        fallback: statusCounts.isEmpty
            ? _int(meta['total'], fallback: orders.length)
            : statusCounts.values.fold<int>(0, (sum, value) => sum + value),
      ),
      statusCodes: statusCodes,
      statusCounts: statusCounts,
    );
  }
}

class CustomerOrderSummary {
  const CustomerOrderSummary({
    required this.id,
    required this.orderNumber,
    required this.storeId,
    required this.storeName,
    required this.storeLogoUrl,
    required this.channel,
    required this.status,
    required this.currency,
    required this.grandTotal,
    required this.createdAt,
    this.itemCount = 0,
    this.nextStatuses = const <String>[],
    this.reorderItems = const <CustomerOrderItem>[],
    this.approvalStatus,
    this.invoiceOutstandingAmount,
    this.appliedCustomerCreditAmount,
    this.remainderMethod,
    this.fullySettled,
  });

  final int id;
  final String orderNumber;
  final int storeId;
  final String? storeName;
  final String? storeLogoUrl;
  final String channel;
  final String status;
  final String currency;
  final double grandTotal;
  final DateTime? createdAt;
  final int itemCount;
  final List<String> nextStatuses;
  final List<CustomerOrderItem> reorderItems;

  /// Optional customer-visible approval state supplied by the authoritative
  /// order/approval contract. When absent, the UI may only infer the safe
  /// pending/approved lifecycle bucket from [status].
  final String? approvalStatus;

  /// Authoritative invoice remainder. Null means the order-list payload did
  /// not expose settlement data; callers must not derive debt from grand total.
  final double? invoiceOutstandingAmount;

  /// Authoritative amount consumed from positive customer credit/balance.
  final double? appliedCustomerCreditAmount;

  /// Authoritative remainder settlement method (for example account_debt or
  /// cash_on_delivery). This is presentation metadata, never a balance source.
  final String? remainderMethod;

  /// Explicit fully-settled flag when supplied by the finance contract.
  final bool? fullySettled;

  CustomerOrderContext get context =>
      CustomerOrderContext(storeId: storeId, channel: channel);

  bool get isTerminal => CustomerOrderRefreshPolicy.isTerminal(status);

  factory CustomerOrderSummary.fromJson(Map<String, dynamic> json) {
    final store = json['store'] is Map
        ? Map<String, dynamic>.from(json['store'] as Map)
        : const <String, dynamic>{};
    final settlement = _map(
      json['settlement'] ?? json['financial_settlement'],
    );
    final invoice = _map(json['invoice']);

    final invoiceOutstandingAmount = _firstNullableDouble(<Object?>[
      json['invoice_outstanding_amount'],
      json['outstanding_amount'],
      json['remaining_amount'],
      settlement['invoice_outstanding_amount'],
      settlement['outstanding_amount'],
      settlement['remaining_amount'],
      invoice['outstanding_amount'],
      invoice['remaining_amount'],
    ]);
    final appliedCustomerCreditAmount = _firstNullableDouble(<Object?>[
      json['applied_customer_credit'],
      json['customer_credit_applied'],
      json['balance_applied'],
      settlement['applied_customer_credit'],
      settlement['customer_credit_applied'],
      settlement['balance_applied'],
    ]);
    final remainderMethod = _firstNullableText(<Object?>[
      json['remainder_method'],
      json['settlement_method'],
      json['remaining_payment_method'],
      settlement['remainder_method'],
      settlement['settlement_method'],
      settlement['remaining_payment_method'],
    ]);
    final explicitFullySettled = _firstNullableBool(<Object?>[
      json['fully_settled'],
      settlement['fully_settled'],
      invoice['fully_settled'],
    ]);
    final fullySettled = explicitFullySettled ??
        (invoiceOutstandingAmount == null
            ? null
            : invoiceOutstandingAmount <= 0.0000001);
    final reorderItems = json['items'] is List
        ? (json['items'] as List)
            .whereType<Map>()
            .map((item) => CustomerOrderItem.fromJson(
                  Map<String, dynamic>.from(item),
                ))
            .where((item) => item.productId > 0 && item.quantity > 0)
            .toList(growable: false)
        : const <CustomerOrderItem>[];
    final nextStatuses = json['next_statuses'] is List
        ? (json['next_statuses'] as List)
            .map((value) => value.toString().toLowerCase())
            .where((value) => value.isNotEmpty)
            .toList(growable: false)
        : const <String>[];

    return CustomerOrderSummary(
      id: _int(json['id']),
      orderNumber: json['order_number']?.toString() ?? '',
      storeId: _int(json['store_id'] ?? store['id']),
      storeName: _nullableText(store['name']),
      storeLogoUrl: _nullableText(store['logo_url']),
      channel: (json['channel']?.toString() ?? 'b2c').toLowerCase(),
      status: (json['status']?.toString() ?? '').toLowerCase(),
      currency: json['currency']?.toString() ?? '',
      grandTotal: _double(json['grand_total'] ?? json['total']),
      createdAt: _date(json['created_at']),
      itemCount: _int(json['item_count'], fallback: reorderItems.length),
      nextStatuses: nextStatuses,
      reorderItems: reorderItems,
      approvalStatus: _firstNullableText(<Object?>[
        json['approval_status'],
        settlement['approval_status'],
      ])?.toLowerCase(),
      invoiceOutstandingAmount: invoiceOutstandingAmount,
      appliedCustomerCreditAmount: appliedCustomerCreditAmount,
      remainderMethod: remainderMethod?.toLowerCase(),
      fullySettled: fullySettled,
    );
  }
}

class CustomerOrderDetails {
  const CustomerOrderDetails({
    required this.summary,
    required this.subtotal,
    required this.discountTotal,
    required this.deliveryTotal,
    required this.paymentMethod,
    required this.deliveryAddress,
    required this.items,
    required this.history,
    required this.payment,
    required this.requestedDeliveryDate,
  });

  final CustomerOrderSummary summary;
  final double subtotal;
  final double discountTotal;
  final double deliveryTotal;
  final String? paymentMethod;
  final Map<String, dynamic>? deliveryAddress;
  final List<CustomerOrderItem> items;
  final List<CustomerOrderHistoryEntry> history;
  final CustomerOrderPayment? payment;
  final String? requestedDeliveryDate;

  factory CustomerOrderDetails.fromJson(Map<String, dynamic> json) {
    final items = json['items'] is List
        ? (json['items'] as List)
            .whereType<Map>()
            .map((item) => CustomerOrderItem.fromJson(
                  Map<String, dynamic>.from(item),
                ))
            .toList(growable: false)
        : const <CustomerOrderItem>[];

    final history = json['status_history'] is List
        ? (json['status_history'] as List)
            .whereType<Map>()
            .map((entry) => CustomerOrderHistoryEntry.fromJson(
                  Map<String, dynamic>.from(entry),
                ))
            .toList(growable: false)
        : const <CustomerOrderHistoryEntry>[];

    return CustomerOrderDetails(
      summary: CustomerOrderSummary.fromJson(json),
      subtotal: _double(json['subtotal']),
      discountTotal: _double(json['discount_total']),
      deliveryTotal: _double(json['delivery_total']),
      paymentMethod: _nullableText(json['payment_method']),
      deliveryAddress: json['delivery_address'] is Map
          ? Map<String, dynamic>.from(json['delivery_address'] as Map)
          : null,
      items: items,
      history: history,
      payment: json['payment'] is Map
          ? CustomerOrderPayment.fromJson(
              Map<String, dynamic>.from(json['payment'] as Map),
            )
          : null,
      requestedDeliveryDate: _nullableText(json['requested_delivery_date']),
    );
  }
}

class CustomerOrderItem {
  const CustomerOrderItem({
    required this.id,
    required this.productId,
    required this.sku,
    required this.name,
    required this.quantity,
    required this.unitPrice,
    required this.lineTotal,
  });

  final int id;
  final int productId;
  final String sku;
  final String name;
  final double quantity;
  final double unitPrice;
  final double lineTotal;

  factory CustomerOrderItem.fromJson(Map<String, dynamic> json) =>
      CustomerOrderItem(
        id: _int(json['id']),
        productId: _int(json['product_id']),
        sku: json['sku']?.toString() ?? '',
        name: json['name']?.toString() ?? '',
        quantity: _double(json['quantity']),
        unitPrice: _double(json['unit_price']),
        lineTotal: _double(json['line_total']),
      );
}

class CustomerOrderHistoryEntry {
  const CustomerOrderHistoryEntry({
    required this.id,
    required this.fromStatus,
    required this.toStatus,
    required this.note,
    required this.createdAt,
  });

  final int id;
  final String? fromStatus;
  final String toStatus;
  final String? note;
  final DateTime? createdAt;

  factory CustomerOrderHistoryEntry.fromJson(Map<String, dynamic> json) =>
      CustomerOrderHistoryEntry(
        id: _int(json['id']),
        fromStatus: _nullableText(json['from_status'])?.toLowerCase(),
        toStatus: (json['to_status']?.toString() ?? '').toLowerCase(),
        note: _nullableText(json['note']),
        createdAt: _date(json['created_at']),
      );
}

class CustomerOrderPayment {
  const CustomerOrderPayment({
    required this.id,
    required this.provider,
    required this.status,
    required this.amount,
    required this.currency,
  });

  final int id;
  final String provider;
  final String status;
  final double amount;
  final String currency;

  factory CustomerOrderPayment.fromJson(Map<String, dynamic> json) =>
      CustomerOrderPayment(
        id: _int(json['id']),
        provider: json['provider']?.toString() ?? '',
        status: json['status']?.toString() ?? '',
        amount: _double(json['amount']),
        currency: json['currency']?.toString() ?? '',
      );
}

class CustomerOrderNotificationIntent {
  const CustomerOrderNotificationIntent({
    required this.orderId,
    this.context,
  });

  final int orderId;
  final CustomerOrderContext? context;

  static CustomerOrderNotificationIntent? fromData(
    Map<String, dynamic> data,
  ) {
    final orderId = _int(data['order_id']);
    if (orderId <= 0) return null;

    final storeId = _int(data['store_id']);
    final channel = data['channel']?.toString().toLowerCase();

    if (storeId > 0 && (channel == 'b2b' || channel == 'b2c')) {
      return CustomerOrderNotificationIntent(
        orderId: orderId,
        context: CustomerOrderContext(storeId: storeId, channel: channel!),
      );
    }

    return CustomerOrderNotificationIntent(orderId: orderId);
  }
}

class CustomerOrderRefreshPolicy {
  const CustomerOrderRefreshPolicy._();

  static const Duration openOrderPollInterval = Duration(seconds: 20);

  static const Set<String> terminalStatuses = <String>{
    'delivered',
    'failed',
    'cancelled',
  };

  static bool isTerminal(String status) =>
      terminalStatuses.contains(status.toLowerCase());

  static bool shouldPoll(String status) => !isTerminal(status);
}

Map<String, dynamic> _map(Object? value) =>
    value is Map ? Map<String, dynamic>.from(value) : const <String, dynamic>{};

double? _firstNullableDouble(List<Object?> values) {
  for (final value in values) {
    if (value == null) continue;
    if (value is num) return value.toDouble();
    final parsed = double.tryParse(value.toString());
    if (parsed != null) return parsed;
  }
  return null;
}

String? _firstNullableText(List<Object?> values) {
  for (final value in values) {
    final text = _nullableText(value);
    if (text != null) return text;
  }
  return null;
}

bool? _firstNullableBool(List<Object?> values) {
  for (final value in values) {
    if (value is bool) return value;
    if (value is num) return value != 0;
    final normalized = value?.toString().trim().toLowerCase();
    if (normalized == 'true' || normalized == '1') return true;
    if (normalized == 'false' || normalized == '0') return false;
  }
  return null;
}

int _int(Object? value, {int fallback = 0}) {
  if (value is num) return value.toInt();
  return int.tryParse(value?.toString() ?? '') ?? fallback;
}

double _double(Object? value, {double fallback = 0}) {
  if (value is num) return value.toDouble();
  return double.tryParse(value?.toString() ?? '') ?? fallback;
}

DateTime? _date(Object? value) {
  final text = _nullableText(value);
  return text == null ? null : DateTime.tryParse(text);
}

String? _nullableText(Object? value) {
  final text = value?.toString().trim();
  return text == null || text.isEmpty ? null : text;
}
