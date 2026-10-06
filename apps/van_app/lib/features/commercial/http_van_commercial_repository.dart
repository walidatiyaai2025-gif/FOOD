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

    final serverTime = DateTime.tryParse(_string(decoded['server_time']));
    if (serverTime == null) {
      throw const VanApiException('Flash offer server time is unavailable.');
    }

    final rows = _list(decoded['data']);
    final offers = rows
        .map((value) => _offer(_map(value)))
        .where((offer) => offer.storeId == customer.storeId)
        .toList(growable: false);

    return VanCommercialOfferFeed(
      serverTime: serverTime.toUtc(),
      offers: offers,
    );
  }

  @override
  Future<void> reserveFlashForCustomer({
    required VanCustomerScope customer,
    required int offerProductId,
    required double quantity,
    required String idempotencyKey,
  }) async {
    // #983 requires Van Flash reservations to be evaluated for the selected
    // customer. The current #985 contract reserves against request->user(),
    // which is the Van operator. Do not silently substitute the operator for
    // the selected customer or invent eligibility math in the mobile client.
    throw const VanCommercialContractPendingException();
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
