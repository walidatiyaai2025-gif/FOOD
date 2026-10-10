import '../../core/api/http_van_api.dart';
import '../../core/auth/van_session.dart';
import '../wallet/van_wallet_contract.dart';
import 'van_order_contract.dart';

class HttpVanOrderRepository implements VanOrderRepository {
  HttpVanOrderRepository(this.api);

  final VanApiClient api;

  @override
  Future<List<VanCatalogProduct>> catalog(
    VanCustomerScope customer, {
    String search = '',
  }) async {
    final query = StringBuffer(
      'van/customers/${customer.type}/${customer.id}/catalog'
      '?store_id=${customer.storeId}',
    );
    if (search.trim().isNotEmpty) {
      query.write('&q=${Uri.encodeQueryComponent(search.trim())}');
    }
    final decoded = _map(await api.getJson(query.toString()));
    final currency = _string(decoded['currency']);
    return _list(decoded['data']).map((row) {
      final data = _map(row);
      return VanCatalogProduct(
        id: _requiredInt(data['id']),
        name: _string(data['name']),
        sku: _string(data['sku']),
        barcode: _nullableString(data['barcode']),
        unitPrice: _double(data['unit_price']),
        minimumQuantity: _double(data['minimum_quantity'], fallback: 1),
        orderingIncrement:
            _double(data['ordering_increment'], fallback: 1),
        availableQuantity: _nullableDouble(data['available_quantity']),
        isAvailable: data['is_available'] != false,
        currency: currency.isEmpty ? 'KWD' : currency,
      );
    }).toList(growable: false);
  }

  @override
  Future<VanOrderOptions> options(VanCustomerScope customer) async {
    final decoded = _map(
      await api.getJson(
        'van/customers/${customer.type}/${customer.id}/order-options'
        '?store_id=${customer.storeId}',
      ),
    );
    final data = _map(decoded['data']);
    return VanOrderOptions(
      addresses: _list(data['addresses']).map((row) {
        final item = _map(row);
        return VanOrderAddressOption(
          id: _requiredInt(item['id']),
          label: _string(item['label']),
          address: _string(item['address']),
          isDefault: item['is_default'] == true,
        );
      }).toList(growable: false),
      warehouses: _list(data['warehouses']).map((row) {
        final item = _map(row);
        return VanWarehouseOption(
          id: _requiredInt(item['id']),
          code: _string(item['code']),
          name: _string(item['name']),
        );
      }).toList(growable: false),
      paymentMethods:
          _list(data['payment_methods']).map(_string).toList(growable: false),
      defaultPaymentMethod: _string(data['default_payment_method']),
    );
  }

  Map<String, Object?> _payload({
    required VanCustomerScope customer,
    required List<VanOrderLine> lines,
    required String paymentMethod,
    int? addressId,
    int? warehouseId,
    String? customerNote,
  }) =>
      {
        'store_id': customer.storeId,
        if (warehouseId != null) 'warehouse_id': warehouseId,
        if (addressId != null) 'address_id': addressId,
        'payment_method': paymentMethod,
        if (customerNote != null && customerNote.trim().isNotEmpty)
          'customer_note': customerNote.trim(),
        'items': [
          for (final line in lines)
            {
              'product_id': line.product.id,
              'quantity': line.quantity,
            },
        ],
      };

  @override
  Future<VanOrderQuote> quote({
    required VanCustomerScope customer,
    required List<VanOrderLine> lines,
    required String paymentMethod,
    int? addressId,
    int? warehouseId,
    String? customerNote,
  }) async {
    final decoded = _map(
      await api.postJson(
        'van/customers/${customer.type}/${customer.id}/order-quote',
        body: _payload(
          customer: customer,
          lines: lines,
          paymentMethod: paymentMethod,
          addressId: addressId,
          warehouseId: warehouseId,
          customerNote: customerNote,
        ),
      ),
    );
    return _quote(_map(decoded['data']));
  }

  @override
  Future<VanOrderRecord> createOrder({
    required VanCustomerScope customer,
    required List<VanOrderLine> lines,
    required String paymentMethod,
    required String idempotencyKey,
    int? addressId,
    int? warehouseId,
    String? customerNote,
  }) async {
    final decoded = _map(
      await api.postJson(
        'van/customers/${customer.type}/${customer.id}/orders',
        headers: {'Idempotency-Key': idempotencyKey},
        body: _payload(
          customer: customer,
          lines: lines,
          paymentMethod: paymentMethod,
          addressId: addressId,
          warehouseId: warehouseId,
          customerNote: customerNote,
        ),
      ),
    );
    return _order(_map(decoded['data']));
  }

  @override
  Future<List<VanOrderRecord>> orders({
    VanCustomerScope? customer,
    String? status,
  }) async {
    final params = <String>[
      'per_page=100',
      if (customer != null) 'customer_type=${customer.type}',
      if (customer != null) 'customer_id=${customer.id}',
      if (status != null && status.trim().isNotEmpty)
        'status=${Uri.encodeQueryComponent(status.trim())}',
    ];
    final decoded =
        _map(await api.getJson('van/orders?${params.join('&')}'));
    return _list(decoded['data'])
        .map((row) => _order(_map(row)))
        .toList(growable: false);
  }

  @override
  Future<VanOrderDetail> order(int orderId) async {
    final decoded = _map(await api.getJson('van/orders/$orderId'));
    final data = _map(decoded['data']);
    final customer = data['customer'] is Map ? _map(data['customer']) : null;
    final address =
        data['delivery_address'] is Map ? _map(data['delivery_address']) : null;
    final invoice = data['invoice'] is Map ? _map(data['invoice']) : null;

    return VanOrderDetail(
      summary: _order(data),
      paymentMethod: _nullableString(data['payment_method']),
      customer: customer == null
          ? null
          : VanOrderCustomer(
              name: _string(customer['name']),
              phone: _nullableString(customer['phone']),
              email: _nullableString(customer['email']),
            ),
      deliveryAddress: address == null
          ? null
          : VanOrderDeliveryAddress(
              formatted: _string(address['formatted']),
              hasCoordinates: address['has_coordinates'] == true,
              latitude: _nullableDouble(address['latitude']),
              longitude: _nullableDouble(address['longitude']),
            ),
      items: _list(data['items'])
          .map((row) {
            final item = _map(row);
            return VanOrderItemRecord(
              name: _string(item['name']),
              sku: _string(item['sku']),
              quantity: _double(item['quantity']),
              lineTotal: _double(item['line_total']),
            );
          })
          .toList(growable: false),
      invoice: invoice == null
          ? null
          : VanOrderInvoiceRecord(
              number: _string(invoice['invoice_number']),
              status: _string(invoice['status']),
              currency: _string(invoice['currency']),
              total: _double(invoice['total']),
              paidAmount: _double(invoice['paid_amount']),
              outstandingAmount: _double(invoice['outstanding_amount']),
            ),
      payments: _list(data['payments'])
          .map((row) {
            final item = _map(row);
            return VanOrderPaymentRecord(
              provider: _string(item['provider']),
              status: _string(item['status']),
              amount: _double(item['amount']),
              currency: _string(item['currency']),
            );
          })
          .toList(growable: false),
      collections: _list(data['collections'])
          .map((row) {
            final item = _map(row);
            return VanOrderCollectionRecord(
              status: _string(item['status']),
              source: _string(item['source']),
              amount: _double(item['amount']),
              currency: _string(item['currency']),
            );
          })
          .toList(growable: false),
      timeline: _list(data['timeline'])
          .map((row) {
            final item = _map(row);
            return VanOrderTimelineRecord(
              stage: _string(item['stage']),
              status: _string(item['status']),
              source: _string(item['source']),
              occurredAt: _nullableString(item['occurred_at']),
            );
          })
          .toList(growable: false),
    );
  }

  @override
  Future<VanOrderExecutionState> execution(int orderId) async {
    final decoded = _map(await api.getJson('van/orders/$orderId/execution'));
    return _execution(_map(decoded['data']));
  }

  @override
  Future<VanOrderExecutionState> transitionOrder({
    required int orderId,
    required String status,
    required String idempotencyKey,
  }) async {
    final decoded = _map(
      await api.postJson(
        'van/orders/$orderId/execution/transition',
        headers: {'Idempotency-Key': idempotencyKey},
        body: {'status': status},
      ),
    );
    return _execution(_map(decoded['data']));
  }

  VanOrderQuote _quote(Map<String, dynamic> data) => VanOrderQuote(
        currency: _string(data['currency']),
        subtotal: _double(data['subtotal']),
        discountTotal: _double(data['discount_total']),
        deliveryTotal: _double(data['delivery_total']),
        taxTotal: _double(data['tax_total']),
        grandTotal: _double(data['grand_total']),
        hasUnavailableItems: data['has_unavailable_items'] == true,
      );

  VanOrderRecord _order(Map<String, dynamic> data) => VanOrderRecord(
        id: _requiredInt(data['id']),
        orderNumber: _string(data['order_number']),
        customerType: _string(data['customer_type']),
        customerId: _requiredInt(data['customer_id']),
        storeId: _requiredInt(data['store_id']),
        status: _string(data['status']),
        currency: _string(data['currency']),
        grandTotal: _double(data['grand_total']),
        createdAt: _nullableString(data['created_at']),
        vanExecutionStatus: _nullableString(data['van_execution_status']),
        vanFailureReasonCode:
            _nullableString(data['van_failure_reason_code']),
        vanLastTransitionAt:
            _nullableString(data['van_last_transition_at']),
      );

  VanOrderExecutionState _execution(Map<String, dynamic> data) =>
      VanOrderExecutionState(
        orderId: _requiredInt(data['order_id']),
        orderStatus: _string(data['order_status']),
        status: _string(data['status']),
        allowedActions: _list(data['allowed_actions'])
            .map(_string)
            .where((value) => value.isNotEmpty)
            .toList(growable: false),
        proofRequiredForDelivered:
            data['proof_required_for_delivered'] == true,
        failureReasonCode:
            _nullableString(data['failure_reason_code']),
        lastTransitionAt:
            _nullableString(data['last_transition_at']),
      );

  Map<String, dynamic> _map(Object? value) {
    if (value is Map<String, dynamic>) return value;
    if (value is Map) return Map<String, dynamic>.from(value);
    throw const VanApiException('Invalid Van order response.');
  }

  List<Object?> _list(Object? value) {
    if (value is List) return List<Object?>.from(value);
    throw const VanApiException('Invalid Van order list response.');
  }

  int _requiredInt(Object? value) {
    if (value is int) return value;
    final parsed = int.tryParse(value?.toString() ?? '');
    if (parsed == null) throw const VanApiException('Invalid order identifier.');
    return parsed;
  }

  double _double(Object? value, {double fallback = 0}) {
    if (value is num) return value.toDouble();
    return double.tryParse(value?.toString() ?? '') ?? fallback;
  }

  double? _nullableDouble(Object? value) {
    if (value == null) return null;
    if (value is num) return value.toDouble();
    return double.tryParse(value.toString());
  }

  String _string(Object? value) => value?.toString() ?? '';

  String? _nullableString(Object? value) {
    final text = value?.toString();
    return text == null || text.isEmpty ? null : text;
  }
}
