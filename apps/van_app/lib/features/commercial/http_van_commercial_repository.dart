import '../../core/api/http_van_api.dart';
import '../../core/auth/van_session.dart';
import 'van_commercial_contract.dart';
import '../wallet/van_wallet_contract.dart';

class HttpVanCommercialRepository implements VanCommercialRepository {
  HttpVanCommercialRepository(this.api);

  final VanApiClient api;

  @override
  Future<VanCommercialOfferFeed> offersFor(VanCustomerScope customer) async {
    final decoded = _map(
      await api.getJson(
        'flash-offers?store_id=${customer.storeId}&channel=van',
      ),
    );
    final normalDecoded = _map(
      await api.getJson('stores/${customer.storeId}/offers?per_page=100'),
    );

    final serverTime = DateTime.tryParse(_string(decoded['server_time']));
    if (serverTime == null) {
      throw const VanApiException('Flash offer server time is unavailable.');
    }

    final rows = _list(decoded['data']);
    final offers = rows
        .map((value) => _offer(_map(value)))
        .where((offer) => offer.storeId == customer.storeId)
        .toList(growable: false);

    final normalOffers = _list(normalDecoded['data'])
        .map((value) => _map(value))
        .map(
          (data) => VanNormalOffer(
            id: _requiredInt(data['id']),
            name: _string(data['name']),
            type: _string(data['type']),
            value: _double(data['value']),
          ),
        )
        .toList(growable: false);

    return VanCommercialOfferFeed(
      serverTime: serverTime.toUtc(),
      offers: offers,
      normalOffers: normalOffers,
    );
  }

  @override
  Future<VanCommercialQuote> quoteForCustomer({
    required VanCustomerScope customer,
    required int productId,
    required String sellingUnitCode,
    required double quantity,
    String? overrideReason,
  }) async {
    final decoded = _map(
      await api.postJson(
        'van/customers/${customer.type}/${customer.id}/commercial/quote',
        body: {
          'store_id': customer.storeId,
          'product_id': productId,
          'selling_unit_code': sellingUnitCode,
          'quantity': quantity,
          if (overrideReason != null && overrideReason.trim().isNotEmpty)
            'override_reason': overrideReason.trim(),
        },
      ),
    );
    final data = _map(decoded['data']);
    final decision = _map(data['decision']);
    return VanCommercialQuote(
      allowed: data['effective_allowed'] == true,
      status: _string(decision['status']),
      reasonCodes: _list(decision['reason_codes'])
          .map((value) => _string(value))
          .where((value) => value.isNotEmpty)
          .toList(growable: false),
      sellingUnit: _sellingUnit(_map(data['selling_unit'])),
      sellingUnits: _list(data['selling_units'])
          .map((value) => _sellingUnit(_map(value)))
          .toList(growable: false),
      overrideApplied: data['override_applied'] == true,
    );
  }

  @override
  Future<void> reserveFlashForCustomer({
    required VanCustomerScope customer,
    required int offerProductId,
    required double quantity,
    required String idempotencyKey,
  }) async {
    await api.postJson(
      'van/customers/${customer.type}/${customer.id}'
      '/flash-offers/products/$offerProductId/reserve',
      body: {
        'store_id': customer.storeId,
        'quantity': quantity,
        'idempotency_key': idempotencyKey,
      },
    );
  }

  VanCommercialOffer _offer(Map<String, dynamic> data) {
    return VanCommercialOffer(
      id: _requiredInt(data['id']),
      storeId: _requiredInt(data['store_id']),
      titleAr: _string(data['title_ar']),
      titleEn: _string(data['title_en']),
      bodyAr: _nullableString(data['body_ar']),
      bodyEn: _nullableString(data['body_en']),
      status: _string(data['status']),
      startsAt: _date(data['starts_at']),
      endsAt: _date(data['ends_at']),
      priority: _requiredInt(data['priority']),
      reservationSeconds: _requiredInt(data['reservation_seconds']),
      products: _list(data['products'])
          .map((value) => _product(_map(value)))
          .toList(growable: false),
    );
  }

  VanSellingUnit _sellingUnit(Map<String, dynamic> data) {
    return VanSellingUnit(
      code: _string(data['code']),
      name: _string(data['name']),
      conversionFactor: _requiredDouble(data['conversion_factor']),
      price: _double(data['price']),
      sku: _nullableString(data['sku']),
      barcode: _nullableString(data['barcode']),
      isBase: data['is_base'] == true,
    );
  }

  VanCommercialOfferProduct _product(Map<String, dynamic> data) {
    return VanCommercialOfferProduct(
      id: _requiredInt(data['id']),
      productId: _requiredInt(data['product_id']),
      sellingUnitId: _int(data['selling_unit_id']),
      sellingUnitCode: _nullableString(data['selling_unit_code']),
      conversionFactor: _requiredDouble(data['conversion_factor']),
      flashPrice: _requiredDouble(data['flash_price']),
      allocationBase: _double(data['allocation_base']),
    );
  }

  Map<String, dynamic> _map(Object? value) {
    if (value is Map<String, dynamic>) return value;
    if (value is Map) return Map<String, dynamic>.from(value);
    throw const VanApiException('Invalid Van commercial response.');
  }

  List<Object?> _list(Object? value) {
    if (value is List) return List<Object?>.from(value);
    throw const VanApiException('Invalid Van commercial list response.');
  }

  String _string(Object? value) => value?.toString() ?? '';

  String? _nullableString(Object? value) {
    final string = value?.toString();
    return string == null || string.trim().isEmpty ? null : string;
  }

  int _requiredInt(Object? value) {
    final parsed = _int(value);
    if (parsed == null) {
      throw const VanApiException('Invalid Van commercial identifier.');
    }
    return parsed;
  }

  int? _int(Object? value) {
    if (value is int) return value;
    return int.tryParse(value?.toString() ?? '');
  }

  double _requiredDouble(Object? value) {
    final parsed = _double(value);
    if (parsed == null) {
      throw const VanApiException('Invalid Van commercial amount.');
    }
    return parsed;
  }

  double? _double(Object? value) {
    if (value is num) return value.toDouble();
    return double.tryParse(value?.toString() ?? '');
  }

  DateTime? _date(Object? value) {
    final string = value?.toString();
    if (string == null || string.isEmpty) return null;
    return DateTime.tryParse(string)?.toUtc();
  }
}
