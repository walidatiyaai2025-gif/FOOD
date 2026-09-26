import 'package:flutter/material.dart';

import '../../core/api/b2c_catalog_api.dart';
import '../../core/api/b2c_account_api.dart';
import '../../core/api/customer_action_api.dart';
import '../../core/auth/customer_session.dart';
import '../../core/localization/app_translations.dart';
import '../../core/routing/customer_routes.dart';
import '../../shared/customer_action_widgets.dart';

class B2cJourneyScreen extends StatefulWidget {
  const B2cJourneyScreen({
    required this.definition,
    required this.location,
    required this.actionApi,
    required this.catalogApi,
    required this.accountApi,
    required this.onAuthenticated,
    super.key,
  });

  final CustomerRouteDefinition definition;
  final String location;
  final CustomerActionApi actionApi;
  final B2cCatalogApi catalogApi;
  final B2cAccountApi accountApi;
  final CustomerAuthenticated onAuthenticated;

  @override
  State<B2cJourneyScreen> createState() => _B2cJourneyScreenState();
}

class _B2cJourneyScreenState extends State<B2cJourneyScreen> {
  final _search = TextEditingController();
  late Future<Object?>? _remote;

  int? get _storeId => int.tryParse(
        Uri.parse(widget.location).queryParameters['store'] ??
            Uri.parse(widget.location).queryParameters['store_id'] ??
            '',
      );

  int? get _orderId {
    final segments = Uri.parse(widget.location).pathSegments;
    return segments.length < 2 ? null : int.tryParse(segments[1]);
  }

  int? get _productId {
    final segments = Uri.parse(widget.location).pathSegments;
    return segments.isEmpty ? null : int.tryParse(segments.last);
  }

  @override
  void initState() {
    super.initState();
    _remote = _load();
  }

  @override
  void didUpdateWidget(covariant B2cJourneyScreen oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.location != widget.location ||
        oldWidget.definition.pattern != widget.definition.pattern ||
        oldWidget.catalogApi != widget.catalogApi) {
      _remote = _load();
    }
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  Future<Object?>? _load() {
    final storeId = _storeId;
    switch (widget.definition.pattern) {
      case CustomerRoutePaths.stores:
        return widget.catalogApi.stores();
      case CustomerRoutePaths.home:
        return storeId == null ? null : _loadHome(storeId);
      case CustomerRoutePaths.offers:
        return storeId == null ? null : widget.catalogApi.offers(storeId);
      case CustomerRoutePaths.products:
        return storeId == null
            ? null
            : widget.catalogApi.products(storeId, query: _search.text);
      case CustomerRoutePaths.productDetails:
        final productId = _productId;
        return storeId == null || productId == null
            ? null
            : widget.catalogApi.product(productId, storeId: storeId);
      case CustomerRoutePaths.cart:
        return widget.accountApi.cart(storeId: storeId);
      case CustomerRoutePaths.orderTracking:
        final orderId = _orderId;
        return orderId == null ? null : widget.accountApi.order(orderId);
      case CustomerRoutePaths.profile:
        return _loadProfile();
      default:
        return null;
    }
  }

  Future<_ProfileData> _loadProfile() async {
    final results = await Future.wait<Object?>([
      widget.accountApi.profile(),
      widget.accountApi.addresses(),
      widget.accountApi.favorites(),
    ]);
    return _ProfileData(profile: results[0], addresses: results[1], favorites: results[2]);
  }

  Future<_HomeData> _loadHome(int storeId) async {
    final results = await Future.wait<Object>([
      widget.catalogApi.categories(storeId),
      widget.catalogApi.offers(storeId),
      widget.catalogApi.products(storeId),
    ]);
    return _HomeData(
      categories: results[0] as List<B2cCategory>,
      offers: results[1] as List<B2cOffer>,
      products: results[2] as List<B2cProduct>,
    );
  }

  void _reload() => setState(() => _remote = _load());

  String _withStore(String path) {
    final storeId = _storeId;
    if (storeId == null) return path;
    return Uri(path: path, queryParameters: {'store': '$storeId'}).toString();
  }

  @override
  Widget build(BuildContext context) {
    final content = _contentFor(context, widget.definition.pattern);
    return Scaffold(
      appBar: AppBar(title: Text(context.tr('customer.app.title'))),
      bottomNavigationBar:
          widget.definition.pattern == CustomerRoutePaths.home ||
                  widget.definition.pattern == CustomerRoutePaths.products ||
                  widget.definition.pattern == CustomerRoutePaths.cart ||
                  widget.definition.pattern == CustomerRoutePaths.profile
              ? NavigationBar(
                  onDestinationSelected: (index) {
                    final routes = [
                      _withStore(CustomerRoutePaths.home),
                      _withStore(CustomerRoutePaths.products),
                      CustomerRoutePaths.cart,
                      CustomerRoutePaths.profile,
                    ];
                    Navigator.of(context).pushReplacementNamed(routes[index]);
                  },
                  destinations: [
                    NavigationDestination(
                      icon: const Icon(Icons.home_outlined),
                      label: context.tr('customer.nav.home'),
                    ),
                    NavigationDestination(
                      icon: const Icon(Icons.grid_view_outlined),
                      label: context.tr('customer.nav.products'),
                    ),
                    NavigationDestination(
                      icon: const Icon(Icons.shopping_cart_outlined),
                      label: context.tr('customer.nav.cart'),
                    ),
                    NavigationDestination(
                      icon: const Icon(Icons.person_outline),
                      label: context.tr('customer.nav.profile'),
                    ),
                  ],
                )
              : null,
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.all(20),
          children: [
            Text(
              content.$1,
              key: const ValueKey('customer-route-label'),
              style: Theme.of(context).textTheme.headlineSmall,
            ),
            const SizedBox(height: 8),
            Text(content.$2),
            const SizedBox(height: 20),
            ...content.$3,
            Text(
              widget.location,
              key: const ValueKey('customer-route-location'),
              style: Theme.of(context).textTheme.labelSmall,
            ),
          ],
        ),
      ),
    );
  }

  (String, String, List<Widget>) _contentFor(
    BuildContext context,
    String pattern,
  ) {
    switch (pattern) {
      case CustomerRoutePaths.splash:
        return (
          context.tr('customer.splash.title'),
          context.tr('customer.splash.subtitle'),
          [const LinearProgressIndicator()],
        );
      case CustomerRoutePaths.entry:
        return (
          context.tr('customer.entry.title'),
          context.tr('customer.entry.subtitle'),
          [
            _button(
              context,
              context.tr('customer.action.guest'),
              CustomerRoutePaths.stores,
            ),
            _button(
              context,
              context.tr('customer.action.login'),
              CustomerRoutePaths.checkoutAddressPayment,
            ),
          ],
        );
      case CustomerRoutePaths.stores:
        return (
          context.tr('customer.store.title'),
          context.tr('customer.store.subtitle'),
          [_remoteBuilder(_buildStores, context.tr('customer.store.empty'))],
        );
      case CustomerRoutePaths.home:
        return (
          context.tr('customer.home.title'),
          context.tr('customer.home.subtitle'),
          [
            if (_storeId == null)
              _storeRequired()
            else
              _remoteBuilder(_buildHome, context.tr('customer.empty')),
          ],
        );
      case CustomerRoutePaths.offers:
        return (
          context.tr('customer.offers.title'),
          context.tr('customer.offers.subtitle'),
          [
            if (_storeId == null)
              _storeRequired()
            else
              _remoteBuilder(_buildOffers, context.tr('customer.offers.empty')),
          ],
        );
      case CustomerRoutePaths.products:
        return (
          context.tr('customer.products.title'),
          context.tr('customer.products.subtitle'),
          [
            if (_storeId == null)
              _storeRequired()
            else ...[
              SearchBar(
                key: const ValueKey('b2c-product-search'),
                controller: _search,
                hintText: context.tr('customer.products.search'),
                onSubmitted: (_) => _reload(),
                trailing: [
                  IconButton(
                    onPressed: _reload,
                    icon: const Icon(Icons.search),
                  ),
                ],
              ),
              const SizedBox(height: 12),
              _remoteBuilder(_buildProducts, context.tr('customer.products.empty')),
            ],
          ],
        );
      case CustomerRoutePaths.productDetails:
        return (
          context.tr('customer.product.title'),
          context.tr('customer.product.subtitle'),
          [
            if (_storeId == null)
              _storeRequired()
            else
              _remoteBuilder(_buildProduct, context.tr('customer.empty')),
          ],
        );
      case CustomerRoutePaths.cart:
        return (
          context.tr('customer.cart.title'),
          context.tr('customer.cart.subtitle'),
          [
            _remoteBuilder(_buildCart, context.tr('customer.cart.empty')),
            _button(
              context,
              context.tr('customer.action.checkout'),
              CustomerRoutePaths.checkoutAddressPayment,
            ),
          ],
        );
      case CustomerRoutePaths.checkoutAuth:
        return (
          context.tr('customer.checkout_login.title'),
          context.tr('customer.checkout_login.subtitle'),
          [
            CustomerLoginAction(
              channel: CustomerChannel.b2c,
              api: widget.actionApi,
              onAuthenticated: widget.onAuthenticated,
              successRoute: CustomerRoutePaths.checkoutAddressPayment,
            ),
          ],
        );
      case CustomerRoutePaths.checkoutAddressPayment:
        return (
          context.tr('customer.checkout.title'),
          context.tr('customer.checkout.subtitle'),
          [
            CheckoutAction(
              api: widget.actionApi,
              channel: CustomerChannel.b2c,
            ),
          ],
        );
      case CustomerRoutePaths.orderTracking:
        return (
          context.tr('customer.tracking.title'),
          context.tr('customer.tracking.subtitle'),
          [_remoteBuilder(_buildOrder, context.tr('customer.empty'))],
        );
      case CustomerRoutePaths.profile:
        return (
          context.tr('customer.profile.title'),
          context.tr('customer.profile.subtitle'),
          [_remoteBuilder(_buildProfile, context.tr('customer.empty'))],
        );
      default:
        return (
          widget.definition.label,
          'FOODEX Customer',
          [_empty(context.tr('customer.empty'))],
        );
    }
  }

  Widget _remoteBuilder(
    Widget Function(Object data) builder,
    String emptyLabel,
  ) {
    final future = _remote;
    if (future == null) return _storeRequired();

    return FutureBuilder<Object?>(
      future: future,
      builder: (context, snapshot) {
        if (snapshot.connectionState != ConnectionState.done) {
          return const Center(
            child: Padding(
              padding: EdgeInsets.all(24),
              child: CircularProgressIndicator(
                key: ValueKey('b2c-catalog-loading'),
              ),
            ),
          );
        }
        if (snapshot.hasError) {
          return Card(
            key: const ValueKey('b2c-catalog-error'),
            child: Padding(
              padding: const EdgeInsets.all(20),
              child: Column(
                children: [
                  Text(context.tr('customer.error.action_failed')),
                  const SizedBox(height: 8),
                  OutlinedButton(onPressed: _reload, child: const Icon(Icons.refresh)),
                ],
              ),
            ),
          );
        }

        final data = snapshot.data;
        if (data is List && data.isEmpty) return _empty(emptyLabel);
        return builder(data as Object);
      },
    );
  }

  Future<void> _changeCartItem(int itemId, double quantity) async {
    try {
      if (quantity <= 0) {
        await widget.accountApi.removeCartItem(itemId);
      } else {
        await widget.accountApi.updateCartItem(itemId, quantity);
      }

      final refreshed = await widget.accountApi.cart(storeId: _storeId);
      if (!mounted) return;
      setState(() {
        _remote = Future<Object?>.value(refreshed);
      });
    } catch (_) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(context.tr('customer.error.action_failed'))),
        );
      }
    }
  }

  Widget _buildCart(Object data) {
    final cart = data is Map ? Map<String, dynamic>.from(data) : <String, dynamic>{};
    final rawItems = cart['items'];
    final items = rawItems is List ? rawItems.whereType<Map>().toList() : <Map>[];
    if (items.isEmpty) return _empty(context.tr('customer.cart.empty'));
    return Column(
      key: const ValueKey('b2c-cart-data'),
      children: [
        ...items.map((raw) {
          final item = Map<String, dynamic>.from(raw);
          final product = item['product'] is Map
              ? Map<String, dynamic>.from(item['product'] as Map)
              : <String, dynamic>{};
          final itemId = (item['id'] as num?)?.toInt();
          final quantity = (item['quantity'] as num?)?.toDouble() ?? 0;
          return Card(
            child: ListTile(
              key: ValueKey('b2c-cart-item-${itemId ?? 'unknown'}'),
              title: Text(product['name']?.toString() ?? ''),
              subtitle: Text(
                '${quantity.toStringAsFixed(2)} · ${item['line_total'] ?? ''} ${cart['currency'] ?? 'KWD'}',
              ),
              trailing: itemId == null
                  ? null
                  : Wrap(
                      spacing: 2,
                      children: [
                        IconButton(
                          key: ValueKey('b2c-cart-dec-$itemId'),
                          onPressed: () => _changeCartItem(itemId, quantity - 1),
                          icon: const Icon(Icons.remove),
                        ),
                        IconButton(
                          key: ValueKey('b2c-cart-inc-$itemId'),
                          onPressed: () => _changeCartItem(itemId, quantity + 1),
                          icon: const Icon(Icons.add),
                        ),
                        IconButton(
                          key: ValueKey('b2c-cart-remove-$itemId'),
                          onPressed: () => _changeCartItem(itemId, 0),
                          icon: const Icon(Icons.delete_outline),
                        ),
                      ],
                    ),
            ),
          );
        }),
        _dataCard('Subtotal', '${cart['subtotal'] ?? 0} ${cart['currency'] ?? 'KWD'}'),
      ],
    );
  }

  Widget _buildOrder(Object data) {
    final order = data is Map ? Map<String, dynamic>.from(data) : <String, dynamic>{};
    final status = order['status']?.toString() ?? '';
    return Column(
      key: const ValueKey('b2c-order-data'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _dataCard('#${order['id'] ?? ''}', status),
        ..._mapCards(order, skip: const {'id', 'status', 'items'}),
        if (order['items'] is List)
          ...(order['items'] as List).whereType<Map>().map(
                (item) => _dataCard(
                  item['product_name']?.toString() ??
                      (item['product'] is Map ? (item['product'] as Map)['name']?.toString() : null) ??
                      '#${item['product_id'] ?? ''}',
                  'x${item['quantity'] ?? ''} · ${item['line_total'] ?? item['total'] ?? ''}',
                ),
              ),
      ],
    );
  }

  Widget _buildProfile(Object data) {
    final profile = data as _ProfileData;
    return Column(
      key: const ValueKey('b2c-profile-data'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        ..._objectCards(profile.profile),
        _section(context.tr('customer.profile.addresses')),
        ..._objectCards(profile.addresses),
        _section(context.tr('customer.profile.favorites')),
        ..._objectCards(profile.favorites),
      ],
    );
  }

  List<Widget> _objectCards(Object? value) {
    if (value is Map) {
      final map = Map<String, dynamic>.from(value);
      if (map['data'] is List) {
        return (map['data'] as List)
            .whereType<Map>()
            .expand((item) => _mapCards(Map<String, dynamic>.from(item)))
            .toList();
      }
      return _mapCards(map);
    }
    if (value is List) {
      return value.whereType<Map>().expand((item) => _mapCards(Map<String, dynamic>.from(item))).toList();
    }
    return value == null ? <Widget>[] : [_dataCard('', value.toString())];
  }

  List<Widget> _mapCards(Map<String, dynamic> map, {Set<String> skip = const {}}) => map.entries
      .where((entry) => !skip.contains(entry.key) && entry.value != null && entry.value is! Map && entry.value is! List)
      .map((entry) => _dataCard(entry.key.replaceAll('_', ' '), entry.value.toString()))
      .toList(growable: false);

  Widget _buildStores(Object data) {
    final stores = data as List<B2cStore>;
    return Column(
      key: const ValueKey('b2c-store-results'),
      children: stores
          .map(
            (store) => Card(
              child: ListTile(
                key: ValueKey('b2c-store-${store.id}'),
                title: Text(store.name),
                subtitle: Text(store.code),
                trailing: const Icon(Icons.chevron_right),
                onTap: () => Navigator.of(context).pushReplacementNamed(
                  Uri(
                    path: CustomerRoutePaths.home,
                    queryParameters: {'store': '${store.id}'},
                  ).toString(),
                ),
              ),
            ),
          )
          .toList(growable: false),
    );
  }

  Widget _buildHome(Object data) {
    final home = data as _HomeData;
    return Column(
      key: const ValueKey('b2c-home-data'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _section(
          context.tr('customer.home.offers'),
          onTap: () => Navigator.of(context).pushNamed(_withStore(CustomerRoutePaths.offers)),
        ),
        ...home.offers.take(3).map((offer) => _dataCard(offer.name, offer.type)),
        _section(context.tr('customer.home.categories')),
        ...home.categories.take(6).map((category) => _dataCard(category.name, '#${category.id}')),
        _section(
          context.tr('customer.home.popular'),
          onTap: () => Navigator.of(context).pushNamed(_withStore(CustomerRoutePaths.products)),
        ),
        ...home.products.take(6).map(_productCard),
      ],
    );
  }

  Widget _buildOffers(Object data) => Column(
        key: const ValueKey('b2c-offer-results'),
        children: (data as List<B2cOffer>)
            .map((offer) => _dataCard(
                  offer.name,
                  [offer.type, if (offer.value != null) offer.value!.toStringAsFixed(2)].join(' · '),
                ))
            .toList(growable: false),
      );

  Widget _buildProducts(Object data) => Column(
        key: const ValueKey('b2c-product-results'),
        children: (data as List<B2cProduct>).map(_productCard).toList(growable: false),
      );

  Widget _productCard(B2cProduct product) => Card(
        child: ListTile(
          key: ValueKey('b2c-product-${product.id}'),
          title: Text(product.name),
          subtitle: Text(
            product.price == null
                ? product.sku
                : '${product.sku} · ${product.price!.toStringAsFixed(3)} ${product.currency}',
          ),
          trailing: const Icon(Icons.chevron_right),
          onTap: () => Navigator.of(context).pushNamed(
            Uri(
              path: '/products/${product.id}',
              queryParameters: {'store': '${_storeId!}'},
            ).toString(),
          ),
        ),
      );

  Widget _buildProduct(Object data) {
    final product = data as B2cProduct;
    return Column(
      key: const ValueKey('b2c-product-detail'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _dataCard(
          product.name,
          [
            product.sku,
            if (product.price != null) '${product.price!.toStringAsFixed(3)} ${product.currency}',
          ].join(' · '),
        ),
        if (product.description != null && product.description!.trim().isNotEmpty)
          Padding(
            padding: const EdgeInsets.only(bottom: 12),
            child: Text(product.description!),
          ),
        AddCartAction(
          api: widget.actionApi,
          location: widget.location,
          cartRoute: CustomerRoutePaths.cart,
        ),
      ],
    );
  }

  Widget _storeRequired() => Card(
        key: const ValueKey('b2c-store-required'),
        child: Padding(
          padding: const EdgeInsets.all(20),
          child: Column(
            children: [
              Text(context.tr('customer.validation.store_required')),
              const SizedBox(height: 8),
              FilledButton(
                onPressed: () => Navigator.of(context).pushReplacementNamed(CustomerRoutePaths.stores),
                child: Text(context.tr('customer.store.title')),
              ),
            ],
          ),
        ),
      );

  Widget _button(BuildContext context, String label, String route) => Padding(
        padding: const EdgeInsets.only(bottom: 12),
        child: FilledButton(
          onPressed: () => Navigator.of(context).pushNamed(route),
          child: Text(label),
        ),
      );

  Widget _section(String label, {VoidCallback? onTap}) => Card(
        child: ListTile(
          title: Text(label),
          trailing: const Icon(Icons.chevron_right),
          onTap: onTap,
        ),
      );

  Widget _dataCard(String title, String subtitle) => Card(
        child: ListTile(title: Text(title), subtitle: Text(subtitle)),
      );

  Widget _empty(String label) => Card(
        key: const ValueKey('b2c-catalog-empty'),
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Center(child: Text(label)),
        ),
      );
}

class _HomeData {
  const _HomeData({
    required this.categories,
    required this.offers,
    required this.products,
  });

  final List<B2cCategory> categories;
  final List<B2cOffer> offers;
  final List<B2cProduct> products;
}

class _ProfileData {
  const _ProfileData({required this.profile, required this.addresses, required this.favorites});
  final Object? profile;
  final Object? addresses;
  final Object? favorites;
}
