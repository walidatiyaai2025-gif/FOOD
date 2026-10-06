import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;

import '../../../core/config/foodex_environment.dart';
import '../commerce/retail_commerce_api.dart';

class CustomerFlashSellingUnit {
  const CustomerFlashSellingUnit({
    required this.id,
    required this.label,
    required this.conversionFactor,
    required this.flashPrice,
  });

  final int id;
  final String label;
  final double conversionFactor;
  final double flashPrice;

  factory CustomerFlashSellingUnit.fromMap(Map<String, dynamic> map) =>
      CustomerFlashSellingUnit(
        id: _int(map['id']),
        label: map['label']?.toString() ?? map['name']?.toString() ?? '',
        conversionFactor: _double(map['conversion_factor']),
        flashPrice: _double(map['flash_price']),
      );
}

class CustomerFlashOffer {
  const CustomerFlashOffer({
    required this.id,
    required this.title,
    required this.body,
    required this.currency,
    required this.flashPrice,
    required this.normalPrice,
    required this.endsAt,
    required this.serverTime,
    required this.remainingAllocation,
    required this.remainingCustomerLimit,
    required this.eligible,
    required this.sellingUnits,
    this.imageUrl,
  });

  final int id;
  final String title;
  final String body;
  final String currency;
  final double flashPrice;
  final double normalPrice;
  final DateTime endsAt;
  final DateTime serverTime;
  final int remainingAllocation;
  final int remainingCustomerLimit;
  final bool eligible;
  final List<CustomerFlashSellingUnit> sellingUnits;
  final String? imageUrl;

  Duration remainingAt(DateTime localNow) {
    final drift = localNow.toUtc().difference(serverTime.toUtc());
    final effectiveServerNow = serverTime.toUtc().add(drift);
    final remaining = endsAt.toUtc().difference(effectiveServerNow);
    return remaining.isNegative ? Duration.zero : remaining;
  }

  factory CustomerFlashOffer.fromMap(Map<String, dynamic> map) {
    return CustomerFlashOffer(
      id: _int(map['id']),
      title: map['title']?.toString() ?? '',
      body: map['body']?.toString() ?? '',
      currency: map['currency']?.toString() ?? 'KWD',
      flashPrice: _double(map['flash_price']),
      normalPrice: _double(map['normal_price']),
      endsAt: _date(map['ends_at']),
      serverTime: _date(map['server_time']),
      remainingAllocation: _int(map['remaining_allocation']),
      remainingCustomerLimit: _int(map['remaining_customer_limit']),
      eligible: map['eligible'] != false,
      sellingUnits: _list(map['selling_units'])
          .whereType<Map>()
          .map((row) => CustomerFlashSellingUnit.fromMap(
                Map<String, dynamic>.from(row),
              ))
          .where((unit) => unit.id > 0)
          .toList(growable: false),
      imageUrl: map['image_url']?.toString(),
    );
  }
}

class CustomerFlashReservation {
  const CustomerFlashReservation({
    required this.id,
    required this.offerId,
    required this.expiresAt,
    required this.serverTime,
    required this.status,
  });

  final int id;
  final int offerId;
  final DateTime expiresAt;
  final DateTime serverTime;
  final String status;

  bool get active => status.toUpperCase() == 'ACTIVE';

  Duration remainingAt(DateTime localNow) {
    final drift = localNow.toUtc().difference(serverTime.toUtc());
    final effectiveServerNow = serverTime.toUtc().add(drift);
    final remaining = expiresAt.toUtc().difference(effectiveServerNow);
    return remaining.isNegative ? Duration.zero : remaining;
  }

  factory CustomerFlashReservation.fromMap(Map<String, dynamic> map) =>
      CustomerFlashReservation(
        id: _int(map['id']),
        offerId: _int(map['offer_id']),
        expiresAt: _date(map['expires_at']),
        serverTime: _date(map['server_time']),
        status: map['status']?.toString() ?? 'ACTIVE',
      );
}

abstract interface class CustomerFlashOffersApi {
  Future<List<CustomerFlashOffer>> activeOffers({required int storeId});
  Future<CustomerFlashReservation?> activeReservation({required int storeId});
  Future<CustomerFlashReservation> reserve({
    required int storeId,
    required int offerId,
    required int quantity,
    int? sellingUnitId,
  });
  Future<RetailCheckoutOptions> checkoutOptions({required int storeId});
  Future<RetailCreatedOrder> confirm({
    required int storeId,
    required int reservationId,
    required int addressId,
    required String paymentMethod,
    required String idempotencyKey,
  });
  Future<void> release({required int reservationId});
}

class HttpCustomerFlashOffersApi implements CustomerFlashOffersApi {
  HttpCustomerFlashOffersApi({
    required this.token,
    String? baseUrl,
    http.Client? client,
  })  : baseUrl = baseUrl ?? FoodexEnvironment.apiBaseUrl,
        _client = client ?? http.Client();

  final String baseUrl;
  final String? token;
  final http.Client _client;

  Map<String, String> get _headers => {
        'Accept': 'application/json',
        if (token != null && token!.trim().isNotEmpty)
          'Authorization': 'Bearer ${token!.trim()}',
        'X-FOODEX-Customer-Domain': 'b2c',
      };

  @override
  Future<List<CustomerFlashOffer>> activeOffers({required int storeId}) async {
    final response = await _client.get(
      Uri.parse('$baseUrl/api/v1/flash-offers/active').replace(
        queryParameters: {'store_id': '$storeId', 'channel': 'customer'},
      ),
      headers: {..._headers, 'X-FOODEX-Store-ID': '$storeId'},
    );
    final body = _decode(response);
    final rows = body is Map ? body['data'] : null;
    if (rows is! List) return const <CustomerFlashOffer>[];
    return rows
        .whereType<Map>()
        .map((row) => CustomerFlashOffer.fromMap(Map<String, dynamic>.from(row)))
        .where((offer) => offer.id > 0)
        .toList(growable: false);
  }

  @override
  Future<CustomerFlashReservation?> activeReservation({
    required int storeId,
  }) async {
    if (token == null || token!.trim().isEmpty) return null;
    final response = await _client.get(
      Uri.parse('$baseUrl/api/v1/flash-reservations/active').replace(
        queryParameters: {'store_id': '$storeId', 'channel': 'customer'},
      ),
      headers: {..._headers, 'X-FOODEX-Store-ID': '$storeId'},
    );
    if (response.statusCode == 404) return null;
    final body = _decode(response);
    final data = body is Map ? body['data'] : body;
    if (data is! Map) return null;
    final reservation = CustomerFlashReservation.fromMap(
      Map<String, dynamic>.from(data),
    );
    return reservation.id > 0 && reservation.active ? reservation : null;
  }

  @override
  Future<CustomerFlashReservation> reserve({
    required int storeId,
    required int offerId,
    required int quantity,
    int? sellingUnitId,
  }) async {
    if (token == null || token!.trim().isEmpty) {
      throw const RetailCommerceException('authentication_required');
    }
    final response = await _client.post(
      Uri.parse('$baseUrl/api/v1/flash-offers/$offerId/reservations'),
      headers: {
        ..._headers,
        'Content-Type': 'application/json',
        'X-FOODEX-Store-ID': '$storeId',
      },
      body: jsonEncode({
        'store_id': storeId,
        'channel': 'customer',
        'quantity': quantity,
        if (sellingUnitId != null && sellingUnitId > 0)
          'selling_unit_id': sellingUnitId,
      }),
    );
    final body = _decode(response);
    final data = body is Map ? body['data'] : body;
    if (data is! Map) {
      throw const RetailCommerceException('invalid_flash_reservation_response');
    }
    return CustomerFlashReservation.fromMap(Map<String, dynamic>.from(data));
  }

  @override
  Future<RetailCheckoutOptions> checkoutOptions({required int storeId}) async {
    final response = await _client.get(
      Uri.parse('$baseUrl/api/v1/checkout/options').replace(
        queryParameters: {'store_id': '$storeId'},
      ),
      headers: {..._headers, 'X-FOODEX-Store-ID': '$storeId'},
    );
    final body = _decode(response);
    return RetailCheckoutOptions.fromPayload(body, expectedStoreId: storeId);
  }

  @override
  Future<RetailCreatedOrder> confirm({
    required int storeId,
    required int reservationId,
    required int addressId,
    required String paymentMethod,
    required String idempotencyKey,
  }) async {
    final response = await _client.post(
      Uri.parse('$baseUrl/api/v1/flash-reservations/$reservationId/confirm'),
      headers: {
        ..._headers,
        'Content-Type': 'application/json',
        'X-FOODEX-Store-ID': '$storeId',
        'Idempotency-Key': idempotencyKey,
      },
      body: jsonEncode({
        'store_id': storeId,
        'channel': 'customer',
        'address_id': addressId,
        'payment_method': paymentMethod,
      }),
    );
    final body = _decode(response);
    final data = body is Map && body['data'] != null ? body['data'] : body;
    return RetailCreatedOrder.fromPayload(data, expectedStoreId: storeId);
  }

  @override
  Future<void> release({required int reservationId}) async {
    if (reservationId <= 0) return;
    final response = await _client.post(
      Uri.parse('$baseUrl/api/v1/flash-reservations/$reservationId/release'),
      headers: {..._headers, 'Content-Type': 'application/json'},
    );
    if (response.statusCode == 404 || response.statusCode == 409) return;
    _decode(response);
  }

  Object? _decode(http.Response response) {
    Object? body;
    if (response.body.isNotEmpty) {
      try {
        body = jsonDecode(response.body);
      } catch (_) {
        body = null;
      }
    }
    if (response.statusCode < 200 || response.statusCode >= 300) {
      var code = 'http_${response.statusCode}';
      if (body is Map && body['code'] != null) {
        code = body['code'].toString();
      }
      throw RetailCommerceException(code);
    }
    return body;
  }
}

class CustomerFlashOffersScreen extends StatefulWidget {
  const CustomerFlashOffersScreen({
    required this.storeId,
    required this.api,
    required this.isAuthenticated,
    required this.onAuthenticationRequired,
    required this.onOpenNormalOffers,
    super.key,
  });

  final int storeId;
  final CustomerFlashOffersApi api;
  final bool isAuthenticated;
  final VoidCallback onAuthenticationRequired;
  final VoidCallback onOpenNormalOffers;

  @override
  State<CustomerFlashOffersScreen> createState() =>
      _CustomerFlashOffersScreenState();
}

class _CustomerFlashOffersScreenState extends State<CustomerFlashOffersScreen> {
  List<CustomerFlashOffer> _offers = const [];
  CustomerFlashReservation? _reservation;
  Object? _error;
  bool _busy = true;
  Timer? _ticker;
  DateTime _now = DateTime.now();
  final Map<int, int> _selectedUnits = <int, int>{};

  bool get _ar => Localizations.localeOf(context).languageCode == 'ar';

  @override
  void initState() {
    super.initState();
    _ticker = Timer.periodic(const Duration(seconds: 1), (_) {
      if (!mounted) return;
      setState(() => _now = DateTime.now());
      final reservation = _reservation;
      if (reservation != null &&
          reservation.remainingAt(_now) == Duration.zero) {
        setState(() => _reservation = null);
      }
    });
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final results = await Future.wait<Object?>([
        widget.api.activeOffers(storeId: widget.storeId),
        widget.api.activeReservation(storeId: widget.storeId),
      ]);
      if (!mounted) return;
      setState(() {
        _offers = results[0] as List<CustomerFlashOffer>;
        _reservation = results[1] as CustomerFlashReservation?;
      });
    } catch (error) {
      if (mounted) setState(() => _error = error);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _openCheckout(CustomerFlashReservation reservation) async {
    await Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => CustomerFlashCheckoutScreen(
          storeId: widget.storeId,
          reservation: reservation,
          api: widget.api,
        ),
      ),
    );
    if (mounted) await _load();
  }

  Future<void> _buyNow(CustomerFlashOffer offer) async {
    if (!widget.isAuthenticated) {
      widget.onAuthenticationRequired();
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final reservation = await widget.api.reserve(
        storeId: widget.storeId,
        offerId: offer.id,
        quantity: 1,
        sellingUnitId: _selectedUnits[offer.id] ??
            (offer.sellingUnits.isNotEmpty ? offer.sellingUnits.first.id : null),
      );
      if (!mounted) return;
      setState(() => _reservation = reservation);
      await _openCheckout(reservation);
    } catch (error) {
      if (mounted) setState(() => _error = error);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  void dispose() {
    _ticker?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      key: const ValueKey('customer-flash-offers-screen'),
      appBar: AppBar(
        title: Text(_ar ? 'العروض السريعة' : 'Flash Offers'),
        actions: [
          TextButton(
            onPressed: widget.onOpenNormalOffers,
            child: Text(_ar ? 'العروض العادية' : 'Regular offers'),
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            if (_reservation != null)
              _ActiveReservationBanner(
                reservation: _reservation!,
                remaining: _reservation!.remainingAt(_now),
                isArabic: _ar,
              ),
            if (_busy) const LinearProgressIndicator(),
            if (_error != null)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 12),
                child: Text(
                  _ar
                      ? 'تعذر تحديث العروض. اسحب لإعادة المحاولة.'
                      : 'Could not refresh offers. Pull to retry.',
                ),
              ),
            if (!_busy && _offers.isEmpty)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 48),
                child: Center(
                  child: Text(_ar ? 'لا توجد عروض سريعة الآن.' : 'No active Flash Offers right now.'),
                ),
              ),
            ..._offers.map((offer) {
              final remaining = offer.remainingAt(_now);
              final canBuy = offer.eligible &&
                  offer.remainingAllocation > 0 &&
                  offer.remainingCustomerLimit > 0 &&
                  remaining > Duration.zero;
              return Card(
                margin: const EdgeInsets.only(bottom: 14),
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      if (offer.imageUrl != null && offer.imageUrl!.isNotEmpty)
                        ClipRRect(
                          borderRadius: BorderRadius.circular(14),
                          child: Image.network(
                            offer.imageUrl!,
                            height: 150,
                            fit: BoxFit.cover,
                            errorBuilder: (_, __, ___) => const SizedBox.shrink(),
                          ),
                        ),
                      const SizedBox(height: 10),
                      Text(
                        offer.title,
                        style: Theme.of(context).textTheme.titleLarge?.copyWith(
                              fontWeight: FontWeight.w900,
                            ),
                      ),
                      if (offer.body.isNotEmpty) ...[
                        const SizedBox(height: 6),
                        Text(offer.body),
                      ],
                      const SizedBox(height: 12),
                      Row(
                        children: [
                          Text(
                            '${offer.flashPrice.toStringAsFixed(3)} ${offer.currency}',
                            style: Theme.of(context).textTheme.titleMedium?.copyWith(
                                  fontWeight: FontWeight.w900,
                                ),
                          ),
                          if (offer.normalPrice > offer.flashPrice) ...[
                            const SizedBox(width: 10),
                            Text(
                              '${offer.normalPrice.toStringAsFixed(3)} ${offer.currency}',
                              style: const TextStyle(
                                decoration: TextDecoration.lineThrough,
                              ),
                            ),
                          ],
                          const Spacer(),
                          Text(_duration(remaining)),
                        ],
                      ),
                      if (offer.sellingUnits.isNotEmpty) ...[
                        const SizedBox(height: 12),
                        DropdownButtonFormField<int>(
                          value: _selectedUnits[offer.id] ??
                              offer.sellingUnits.first.id,
                          decoration: InputDecoration(
                            labelText: _ar ? 'وحدة البيع' : 'Selling unit',
                          ),
                          items: offer.sellingUnits
                              .map(
                                (unit) => DropdownMenuItem<int>(
                                  value: unit.id,
                                  child: Text(
                                    unit.label.isEmpty
                                        ? (_ar ? 'وحدة' : 'Unit')
                                        : unit.label,
                                  ),
                                ),
                              )
                              .toList(growable: false),
                          onChanged: _busy
                              ? null
                              : (value) {
                                  if (value == null) return;
                                  setState(() => _selectedUnits[offer.id] = value);
                                },
                        ),
                      ],
                      const SizedBox(height: 8),
                      Text(
                        _ar
                            ? 'المتبقي لك: ${offer.remainingCustomerLimit} · المتاح: ${offer.remainingAllocation}'
                            : 'Your remaining limit: ${offer.remainingCustomerLimit} · Available: ${offer.remainingAllocation}',
                        style: Theme.of(context).textTheme.bodySmall,
                      ),
                      const SizedBox(height: 12),
                      FilledButton(
                        key: ValueKey('flash-buy-now-${offer.id}'),
                        onPressed: canBuy && !_busy ? () => _buyNow(offer) : null,
                        child: Text(_ar ? 'اشترِ الآن' : 'BUY NOW'),
                      ),
                    ],
                  ),
                ),
              );
            }),
          ],
        ),
      ),
    );
  }
}

class _ActiveReservationBanner extends StatelessWidget {
  const _ActiveReservationBanner({
    required this.reservation,
    required this.remaining,
    required this.isArabic,
  });

  final CustomerFlashReservation reservation;
  final Duration remaining;
  final bool isArabic;

  @override
  Widget build(BuildContext context) => Card(
        child: ListTile(
          leading: const Icon(Icons.timer_outlined),
          title: Text(isArabic ? 'لديك حجز عرض نشط' : 'Active Flash reservation'),
          subtitle: Text(
            '${isArabic ? 'الوقت المتبقي' : 'Time remaining'}: ${_duration(remaining)}',
          ),
        ),
      );
}

class CustomerFlashCheckoutScreen extends StatefulWidget {
  const CustomerFlashCheckoutScreen({
    required this.storeId,
    required this.reservation,
    required this.api,
    super.key,
  });

  final int storeId;
  final CustomerFlashReservation reservation;
  final CustomerFlashOffersApi api;

  @override
  State<CustomerFlashCheckoutScreen> createState() =>
      _CustomerFlashCheckoutScreenState();
}

class _CustomerFlashCheckoutScreenState
    extends State<CustomerFlashCheckoutScreen> {
  RetailCheckoutOptions? _options;
  int? _addressId;
  String? _paymentMethod;
  Object? _error;
  bool _busy = true;
  bool _submitted = false;
  late Duration _remaining = widget.reservation.remainingAt(DateTime.now());
  Timer? _timer;

  bool get _ar => Localizations.localeOf(context).languageCode == 'ar';

  @override
  void initState() {
    super.initState();
    _timer = Timer.periodic(const Duration(seconds: 1), (_) {
      if (!mounted) return;
      final next = widget.reservation.remainingAt(DateTime.now());
      setState(() => _remaining = next);
      if (next == Duration.zero && !_submitted) {
        setState(() => _error =
            const RetailCommerceException('FLASH_RESERVATION_EXPIRED'));
      }
    });
    _load();
  }

  Future<void> _load() async {
    try {
      final options = await widget.api.checkoutOptions(storeId: widget.storeId);
      if (!mounted) return;
      setState(() {
        _options = options;
        if (options.addresses.isNotEmpty) {
          _addressId = options.addresses
              .firstWhere(
                (item) => item.isDefault,
                orElse: () => options.addresses.first,
              )
              .id;
        }
        if (options.paymentMethods.isNotEmpty) {
          _paymentMethod = options.paymentMethods.first;
        }
      });
    } catch (error) {
      if (mounted) setState(() => _error = error);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _confirm() async {
    final addressId = _addressId;
    final payment = _paymentMethod;
    if (_remaining == Duration.zero) {
      setState(() => _error =
          const RetailCommerceException('FLASH_RESERVATION_EXPIRED'));
      return;
    }
    if (addressId == null || payment == null) {
      setState(() => _error =
          const RetailCommerceException('checkout_fields_required'));
      return;
    }

    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final order = await widget.api.confirm(
        storeId: widget.storeId,
        reservationId: widget.reservation.id,
        addressId: addressId,
        paymentMethod: payment,
        idempotencyKey:
            'flash-${widget.reservation.id}-${DateTime.now().microsecondsSinceEpoch}',
      );
      _submitted = true;
      if (!mounted) return;
      Navigator.of(context).pop(order);
    } catch (error) {
      if (mounted) setState(() => _error = error);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _release() async {
    setState(() => _busy = true);
    try {
      await widget.api.release(reservationId: widget.reservation.id);
      if (mounted) Navigator.of(context).pop();
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final options = _options;
    final expired = _remaining == Duration.zero;
    return Scaffold(
      key: const ValueKey('customer-flash-checkout-screen'),
      appBar: AppBar(
        title: Text(_ar ? 'إتمام العرض السريع' : 'Flash checkout'),
      ),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          Text(
            _duration(_remaining),
            textAlign: TextAlign.center,
            style: Theme.of(context).textTheme.displaySmall?.copyWith(
                  fontWeight: FontWeight.w900,
                ),
          ),
          const SizedBox(height: 8),
          Text(
            _ar
                ? 'يتم التحقق من الوقت والمخزون والأهلية مرة أخرى عند التأكيد.'
                : 'Time, stock and eligibility are revalidated by the server at confirmation.',
            textAlign: TextAlign.center,
          ),
          if (_busy) ...[
            const SizedBox(height: 12),
            const LinearProgressIndicator(),
          ],
          if (_error != null) ...[
            const SizedBox(height: 12),
            Text(
              expired
                  ? (_ar ? 'انتهى الحجز.' : 'Reservation expired.')
                  : (_ar ? 'تعذر إتمام العملية.' : 'Could not complete checkout.'),
            ),
          ],
          if (options != null) ...[
            const SizedBox(height: 20),
            DropdownButtonFormField<int>(
              value: _addressId,
              decoration: InputDecoration(
                labelText: _ar ? 'عنوان التوصيل' : 'Delivery address',
              ),
              items: options.addresses
                  .map(
                    (address) => DropdownMenuItem<int>(
                      value: address.id,
                      child: Text(
                        [address.label, address.line1, address.city]
                            .where((part) => part.trim().isNotEmpty)
                            .join(' · '),
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                  )
                  .toList(growable: false),
              onChanged: expired || _busy
                  ? null
                  : (value) => setState(() => _addressId = value),
            ),
            const SizedBox(height: 12),
            DropdownButtonFormField<String>(
              value: _paymentMethod,
              decoration: InputDecoration(
                labelText: _ar ? 'طريقة الدفع' : 'Payment method',
              ),
              items: options.paymentMethods
                  .map(
                    (method) => DropdownMenuItem<String>(
                      value: method,
                      child: Text(method),
                    ),
                  )
                  .toList(growable: false),
              onChanged: expired || _busy
                  ? null
                  : (value) => setState(() => _paymentMethod = value),
            ),
          ],
          const SizedBox(height: 20),
          FilledButton(
            key: const ValueKey('flash-confirm-order'),
            onPressed: expired || _busy ? null : _confirm,
            child: Text(_ar ? 'تأكيد الطلب' : 'Confirm order'),
          ),
          const SizedBox(height: 8),
          OutlinedButton(
            onPressed: _busy ? null : _release,
            child: Text(_ar ? 'إلغاء الحجز' : 'Release reservation'),
          ),
        ],
      ),
    );
  }
}

String _duration(Duration value) {
  final total = value.inSeconds < 0 ? 0 : value.inSeconds;
  final minutes = total ~/ 60;
  final seconds = total % 60;
  return '${minutes.toString().padLeft(2, '0')}:${seconds.toString().padLeft(2, '0')}';
}

int _int(Object? value) =>
    value is int ? value : int.tryParse(value?.toString() ?? '') ?? 0;

List<Object?> _list(Object? value) =>
    value is List ? List<Object?>.from(value) : const <Object?>[];

double _double(Object? value) =>
    value is num ? value.toDouble() : double.tryParse(value?.toString() ?? '') ?? 0;

DateTime _date(Object? value) {
  final parsed = DateTime.tryParse(value?.toString() ?? '');
  if (parsed == null) {
    throw const RetailCommerceException('invalid_flash_datetime');
  }
  return parsed;
}
