import 'package:flutter/material.dart';

import '../../core/auth/van_session.dart';
import '../../core/theme/foodex_van_theme.dart';
import '../wallet/van_wallet_contract.dart';
import 'van_commercial_contract.dart';

class VanOffersPage extends StatefulWidget {
  const VanOffersPage({
    super.key,
    required this.commercialRepository,
    required this.customerRepository,
    required this.onSessionExpired,
  });

  final VanCommercialRepository commercialRepository;
  final VanWalletRepository customerRepository;
  final Future<void> Function() onSessionExpired;

  @override
  State<VanOffersPage> createState() => _VanOffersPageState();
}

class _VanOffersPageState extends State<VanOffersPage> {
  List<VanCustomerScope> _customers = const [];
  VanCustomerScope? _selectedCustomer;
  VanCommercialOfferFeed? _feed;
  bool _loading = true;
  Object? _error;

  String _text(String en, String ar) =>
      Localizations.localeOf(context).languageCode == 'ar' ? ar : en;

  @override
  void initState() {
    super.initState();
    _loadCustomers();
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

  Future<void> _loadOffers() async {
    final customer = _selectedCustomer;
    if (customer == null) {
      if (mounted) setState(() => _feed = null);
      return;
    }

    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final feed = await widget.commercialRepository.offersFor(customer);
      if (!mounted) return;
      setState(() => _feed = feed);
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

  Future<void> _attemptFlashSale(
    VanCommercialOfferProduct product,
  ) async {
    final customer = _selectedCustomer;
    if (customer == null) return;

    try {
      await widget.commercialRepository.reserveFlashForCustomer(
        customer: customer,
        offerProductId: product.id,
        quantity: 1,
        idempotencyKey:
            'van-${customer.type}-${customer.id}-${product.id}-${DateTime.now().millisecondsSinceEpoch}',
      );
    } on VanCommercialContractPendingException catch (error) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          key: const Key('van-flash-online-validation-required'),
          content: Text(
            error.reasonCode == 'ONLINE_VALIDATION_REQUIRED'
                ? _text(
                    'Online validation for the selected customer is required before a Flash sale.',
                    'يلزم التحقق عبر الإنترنت للعميل المحدد قبل بيع عرض Flash.',
                  )
                : error.reasonCode,
          ),
        ),
      );
    } on VanOfflineException {
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
    } on VanSessionExpiredException {
      await widget.onSessionExpired();
    }
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
        padding: const EdgeInsets.all(16),
        children: [
          Text(
            _text('Offers', 'العروض'),
            style: Theme.of(context).textTheme.headlineSmall,
          ),
          const SizedBox(height: 6),
          Text(
            _text(
              'Offer visibility is server-driven for the selected customer/store. Flash sales never fall back to offline validation.',
              'ظهور العروض يعتمد على الخادم حسب العميل والمتجر المحددين. بيع Flash لا يستخدم تحققًا محليًا عند انقطاع الاتصال.',
            ),
            style: Theme.of(context)
                .textTheme
                .bodyMedium
                ?.copyWith(color: FoodexVanTokens.muted),
          ),
          const SizedBox(height: 16),
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
                        '${customer.name} · ${customer.type.toUpperCase()}',
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
            else if ((_feed?.offers ?? const []).isEmpty)
              _messageCard(
                _text(
                  'No eligible active offers were returned.',
                  'لم يعُد الخادم بعروض نشطة مؤهلة.',
                ),
              )
            else
              ..._feed!.offers.map(
                (offer) => _offerCard(offer, languageCode),
              ),
          ],
        ],
      ),
    );
  }

  Widget _offerCard(VanCommercialOffer offer, String languageCode) {
    final body = offer.bodyFor(languageCode);
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
                Text(offer.status.toUpperCase()),
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
                child: Row(
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
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _messageCard(String message, {Widget? action}) {
    return DecoratedBox(
      decoration: BoxDecoration(
        color: FoodexVanTokens.surface,
        border: Border.all(color: FoodexVanTokens.border),
        borderRadius: BorderRadius.circular(FoodexVanTokens.cardRadius),
      ),
      child: Padding(
        padding: const EdgeInsets.all(20),
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
