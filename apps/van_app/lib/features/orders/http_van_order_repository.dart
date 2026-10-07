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
