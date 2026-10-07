import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/auth/van_session.dart';
import '../../core/theme/foodex_van_theme.dart';
import '../wallet/van_wallet_contract.dart';
import 'van_commercial_contract.dart';

class VanOffersPage extends StatefulWidget {
  const VanOffersPage({
    super.key,
    required this.session,
    required this.commercialRepository,
    required this.customerRepository,
    required this.onSessionExpired,
  });

  final VanSession session;
  final VanCommercialRepository commercialRepository;
  final VanWalletRepository customerRepository;
  final Future<void> Function() onSessionExpired;

  @override
  State<VanOffersPage> createState() => _VanOffersPageState();
}

class _VanOffersPageState extends State<VanOffersPage>
    with WidgetsBindingObserver {
  List<VanCustomerScope> _customers = const [];
  VanCustomerScope? _selectedCustomer;
  VanCommercialOfferFeed? _feed;
  final Map<int, String> _overrideReasons = <int, String>{};
  bool _loading = true;
  bool _refreshing = false;
  bool _stale = false;
  Object? _error;

  bool get _canOverride =>
      widget.session.permissions.contains('orders.approve') ||
      widget.session.permissions.contains('platform.manage');

  String _text(String en, String ar) =>
      Localizations.localeOf(context).languageCode == 'ar' ? ar : en;

  String _customerTypeLabel(String value) => switch (value.trim().toLowerCase()) {
        'b2b' || 'wholesale' => _text('Wholesale', 'جملة'),
        'b2c' || 'retail' => _text('Retail', 'تجزئة'),
        _ => _text('Customer', 'عميل'),
      };

  String _offerTypeLabel(String value) {
    final normalized = value.trim().toLowerCase();
    if (normalized.contains('percent')) {
      return _text('Percentage discount', 'خصم بالنسبة المئوية');
    }
    if (normalized.contains('fixed') || normalized.contains('amount')) {
      return _text('Fixed discount', 'خصم بقيمة ثابتة');
    }
    if (normalized.contains('bundle') || normalized.contains('buy')) {
      return _text('Bundle offer', 'عرض باقة');
    }
    return _text('Offer', 'عرض');
  }

  String _offerStatusLabel(String value) => switch (value.trim().toLowerCase()) {
        'active' => _text('Active', 'نشط'),
        'scheduled' || 'upcoming' => _text('Scheduled', 'مجدول'),
        'expired' || 'ended' => _text('Ended', 'منتهي'),
        'paused' || 'inactive' => _text('Paused', 'متوقف'),
        _ => _text('Offer status', 'حالة العرض'),
      };

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _loadCustomers();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state != AppLifecycleState.resumed || _loading || _refreshing) return;
    if (_selectedCustomer == null) {
      unawaited(_loadCustomers());
      return;
    }
    unawaited(_loadOffers(background: true));
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  Future<void> _loadCustomers() async {
    setState(() {
      _loading = true;
      _error = null;
    });

    try {
      final customers = await widget.customerRepository.customers();
      if (!mounted) return;
      setState(() {
        _customers = customers;
        _selectedCustomer = customers.isEmpty ? null : customers.first;
      });
      if (customers.isNotEmpty) {
        await _loadOffers();
      }
    } on VanSessionExpiredException {
      await widget.onSessionExpired();
      return;
    } catch (error) {
      if (!mounted) return;
      setState(() => _error = error);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _loadOffers({bool background = false}) async {
    final customer = _selectedCustomer;
    if (customer == null) {
      if (mounted) setState(() => _feed = null);
      return;
    }

    setState(() {
      if (background && _feed != null) {
        _refreshing = true;
      } else {
        _loading = true;
      }
      _error = null;
      _overrideReasons.clear();
    });
    try {
      final feed = await widget.commercialRepository.offersFor(customer);
      if (!mounted) return;
      setState(() {
        _feed = feed;
        _stale = false;
      });
    } on VanSessionExpiredException {
      await widget.onSessionExpired();
      return;
    } catch (error) {
      if (!mounted) return;
      setState(() {
        if (background && _feed != null) {
          _stale = true;
        } else {
          _error = error;
        }
      });
    } finally {
      if (mounted) {
        setState(() {
          _loading = false;
          _refreshing = false;
        });
      }
    }
  }

  Future<void> _attemptFlashSale(
    VanCommercialOfferProduct product,
  ) async {
    final customer = _selectedCustomer;
    final unitCode = product.sellingUnitCode?.trim();
    if (customer == null) return;

    if (unitCode == null || unitCode.isEmpty) {
      _showOnlineValidationRequired();
      return;
    }

    final overrideReason = _canOverride
        ? (_overrideReasons[product.id]?.trim() ?? '')
        : '';

    try {
      final quote = await widget.commercialRepository.quoteForCustomer(
        customer: customer,
        productId: product.productId,
        sellingUnitCode: unitCode,
        quantity: 1,
        overrideReason: overrideReason.isEmpty ? null : overrideReason,
      );

      if (!quote.allowed) {
        if (!mounted) return;
        final reasons = quote.reasonCodes.isEmpty
            ? 'ONLINE_VALIDATION_REQUIRED'
            : quote.reasonCodes.join(', ');
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            key: const Key('van-commercial-policy-blocked'),
            content: Text(
              _text(
                'Sale blocked by commercial policy: $reasons',
                'تم منع البيع بواسطة السياسة التجارية: $reasons',
              ),
            ),
          ),
        );
        return;
      }

      await widget.commercialRepository.reserveFlashForCustomer(
        customer: customer,
        offerProductId: product.id,
        quantity: 1,
        idempotencyKey:
            'van-${customer.type}-${customer.id}-${product.id}-${DateTime.now().millisecondsSinceEpoch}',
        overrideReason: overrideReason.isEmpty ? null : overrideReason,
      );

      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          key: const Key('van-flash-reserved'),
          content: Text(
            _text(
              'Flash quantity reserved after online validation.',
              'تم حجز كمية Flash بعد التحقق عبر الإنترنت.',
            ),
          ),
        ),
      );
    } on VanCommercialContractPendingException {
      _showOnlineValidationRequired();
    } on VanOfflineException {
      _showOnlineValidationRequired();
    } on VanAccessDeniedException {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          key: const Key('van-commercial-override-denied'),
          content: Text(
            _text(
              'Supervisor/admin approval is required for this override.',
              'يتطلب هذا التجاوز موافقة مشرف أو مدير.',
            ),
          ),
        ),
      );
    } on VanSessionExpiredException {
      await widget.onSessionExpired();
    }
  }

  void _showOnlineValidationRequired() {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        key: const Key('van-flash-online-validation-required'),
        content: Text(
          _text(
            'Online validation required.',
            'يلزم التحقق عبر الإنترنت.',
          ),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final languageCode = Localizations.localeOf(context).languageCode;

    if (_loading && _customers.isEmpty) {
      return const Center(child: CircularProgressIndicator());
    }

    return RefreshIndicator(
      onRefresh: _loadOffers,
      child: ListView(
        key: const Key('van-offers-page'),
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
        children: [
          Text(
            _text('Offers', 'العروض'),
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: Theme.of(context).textTheme.titleLarge,
          ),
          const SizedBox(height: 2),
          Text(
            _text(
              'Normal and Flash offers are server-driven for the selected customer/store. Flash sales never fall back to offline validation.',
              'العروض العادية وFlash تعتمد على الخادم حسب العميل والمتجر المحددين. بيع Flash لا يستخدم تحققًا محليًا عند انقطاع الاتصال.',
            ),
            maxLines: 2,
            overflow: TextOverflow.ellipsis,
            style: Theme.of(context)
                .textTheme
                .bodySmall
                ?.copyWith(color: FoodexVanTokens.muted),
          ),
          const SizedBox(height: 8),
          if (_refreshing) ...[
            const LinearProgressIndicator(key: Key('van-offers-refreshing')),
            const SizedBox(height: 6),
          ],
          if (_stale) ...[
            _messageCard(
              _text(
                'Connection is unavailable. Showing the last server response until refresh succeeds.',
                'الاتصال غير متاح. يتم عرض آخر استجابة من الخادم حتى ينجح التحديث.',
              ),
              key: const Key('van-offers-stale-banner'),
            ),
            const SizedBox(height: 8),
          ],
          if (_customers.isEmpty)
            _messageCard(
              _text(
                'No assigned customers are available.',
                'لا يوجد عملاء معينون متاحون.',
              ),
            )
          else ...[
            DropdownButtonFormField<VanCustomerScope>(
              key: const Key('van-offers-customer-selector'),
              initialValue: _selectedCustomer,
              decoration: InputDecoration(
                labelText: _text('Selected customer', 'العميل المحدد'),
              ),
              items: _customers
                  .map(
                    (customer) => DropdownMenuItem(
                      value: customer,
                      child: Text(
                        '${customer.name} · ${_customerTypeLabel(customer.type)}',
                      ),
                    ),
                  )
                  .toList(growable: false),
              onChanged: (customer) {
                setState(() {
                  _selectedCustomer = customer;
                  _feed = null;
                });
                if (customer != null) _loadOffers();
              },
            ),
            const SizedBox(height: 16),
            if (_error != null)
              _messageCard(
                _text(
                  'Offers could not be loaded from the canonical backend.',
                  'تعذر تحميل العروض من الخادم المعتمد.',
                ),
                action: TextButton(
                  onPressed: _loadOffers,
                  child: Text(_text('Retry', 'إعادة المحاولة')),
                ),
              )
            else if (_loading)
              const Center(child: CircularProgressIndicator())
            else if ((_feed?.offers ?? const []).isEmpty &&
                (_feed?.normalOffers ?? const []).isEmpty)
              _messageCard(
                _text(
                  'No eligible active offers were returned.',
                  'لم يعُد الخادم بعروض نشطة مؤهلة.',
                ),
              )
            else ...[
              if ((_feed?.normalOffers ?? const []).isNotEmpty) ...[
                Text(
                  _text('Normal offers', 'العروض العادية'),
                  style: Theme.of(context).textTheme.titleMedium,
                ),
                const SizedBox(height: 8),
                ..._feed!.normalOffers.map(_normalOfferCard),
                const SizedBox(height: 12),
              ],
              if ((_feed?.offers ?? const []).isNotEmpty) ...[
                Text(
                  _text('Flash offers', 'عروض Flash'),
                  style: Theme.of(context).textTheme.titleMedium,
                ),
                const SizedBox(height: 8),
                ..._feed!.offers.map(
                  (offer) => _offerCard(offer, languageCode),
                ),
              ],
            ],
          ],
        ],
      ),
    );
  }

  Widget _normalOfferCard(VanNormalOffer offer) {
    final value = offer.value == null ? '' : ' · ${offer.value}';
    final offerTypeLabel = _offerTypeLabel(offer.type);
    final subtitle = '$offerTypeLabel$value';
    return Card(
      key: Key('van-normal-offer-${offer.id}'),
      margin: const EdgeInsets.only(bottom: 8),
      child: ListTile(
        leading: const Icon(Icons.local_offer_outlined),
        title: Text(offer.name),
        subtitle: Text(subtitle),
      ),
    );
  }

  Widget _offerCard(VanCommercialOffer offer, String languageCode) {
    final body = offer.bodyFor(languageCode);
    final offerStatusLabel = _offerStatusLabel(offer.status);
    return Card(
      key: Key('van-offer-${offer.id}'),
      margin: const EdgeInsets.only(bottom: 12),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                const Icon(Icons.bolt_outlined),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    offer.titleFor(languageCode),
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                ),
                Text(offerStatusLabel),
              ],
            ),
            if (body != null) ...[
              const SizedBox(height: 8),
              Text(body),
            ],
            const SizedBox(height: 12),
            ...offer.products.map(
              (product) => Padding(
                padding: const EdgeInsets.only(top: 8),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Row(
                      children: [
                        Expanded(
                          child: Text(
                            _text(
                              'Product #${product.productId} · ${product.flashPrice.toStringAsFixed(3)}'
                              '${product.sellingUnitCode == null ? '' : ' · ${product.sellingUnitCode}'}',
                              'منتج #${product.productId} · ${product.flashPrice.toStringAsFixed(3)}'
                              '${product.sellingUnitCode == null ? '' : ' · ${product.sellingUnitCode}'}',
                            ),
                          ),
                        ),
                        FilledButton.tonal(
                          key: Key('van-flash-buy-${product.id}'),
                          onPressed: () => _attemptFlashSale(product),
                          child: Text(_text('Validate online', 'تحقق أونلاين')),
                        ),
                      ],
                    ),
                    if (_canOverride) ...[
                      const SizedBox(height: 8),
                      TextField(
                        key: Key('van-commercial-override-${product.id}'),
                        decoration: InputDecoration(
                          labelText: _text(
                            'Override reason (supervisor/admin only)',
                            'سبب التجاوز (للمشرف/المدير فقط)',
                          ),
                        ),
                        onChanged: (value) => _overrideReasons[product.id] = value,
                      ),
                    ],
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _messageCard(
    String message, {
    Widget? action,
    Key? key,
  }) {
    return DecoratedBox(
      key: key,
      decoration: BoxDecoration(
        color: FoodexVanTokens.surface,
        border: Border.all(color: FoodexVanTokens.border),
        borderRadius: BorderRadius.circular(FoodexVanTokens.cardRadius),
      ),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Row(
          children: [
            Expanded(child: Text(message)),
            if (action != null) action,
          ],
        ),
      ),
    );
  }
}
