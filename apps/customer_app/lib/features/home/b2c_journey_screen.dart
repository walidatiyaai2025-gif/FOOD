import 'dart:async';

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
    required this.onSessionExpired,
    super.key,
  });

  final CustomerRouteDefinition definition;
  final String location;
  final CustomerActionApi actionApi;
  final B2cCatalogApi catalogApi;
  final B2cAccountApi accountApi;
  final CustomerAuthenticated onAuthenticated;
  final VoidCallback onSessionExpired;

  @override
  State<B2cJourneyScreen> createState() => _B2cJourneyScreenState();
}

class _B2cJourneyScreenState extends State<B2cJourneyScreen> {
  final _search = TextEditingController();
  late Future<Object?>? _remote;
  Timer? _splashTimer;

  int? get _categoryId => int.tryParse(Uri.parse(widget.location).queryParameters['category'] ?? '');
  String? get _query => Uri.parse(widget.location).queryParameters['q'];
  String get _sort => Uri.parse(widget.location).queryParameters['sort'] == 'price' ? 'price' : 'name';
  String get _direction => Uri.parse(widget.location).queryParameters['direction'] == 'desc' ? 'desc' : 'asc';

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
    _search.text = _query ?? '';
    _remote = _load();
    _scheduleSplash();
  }

  @override
  void didUpdateWidget(covariant B2cJourneyScreen oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.location != widget.location ||
        oldWidget.definition.pattern != widget.definition.pattern ||
        oldWidget.catalogApi != widget.catalogApi ||
        oldWidget.accountApi != widget.accountApi) {
      _search.text = _query ?? '';
      _remote = _load();
      _scheduleSplash();
    }
  }

  void _scheduleSplash() {
    _splashTimer?.cancel();
    if (widget.definition.pattern != CustomerRoutePaths.splash) return;
    _splashTimer = Timer(const Duration(milliseconds: 1100), () {
      if (mounted) Navigator.of(context).pushReplacementNamed(CustomerRoutePaths.entry);
    });
  }

  @override
  void dispose() {
    _splashTimer?.cancel();
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
      case CustomerRoutePaths.categories:
        return storeId == null ? null : widget.catalogApi.categories(storeId);
      case CustomerRoutePaths.offers:
        return storeId == null ? null : widget.catalogApi.offers(storeId);
      case CustomerRoutePaths.products:
        return storeId == null
            ? null
            : widget.catalogApi.products(
                storeId,
                query: _query,
                categoryId: _categoryId,
                sort: _sort,
                direction: _direction,
              );
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
      case CustomerRoutePaths.orders:
        return widget.accountApi.orders();
      case CustomerRoutePaths.favorites:
        return widget.accountApi.favorites();
      case CustomerRoutePaths.notifications:
        return widget.accountApi.notifications(
          locale: WidgetsBinding.instance.platformDispatcher.locale.languageCode,
        );
      case CustomerRoutePaths.addresses:
        return widget.accountApi.addresses();
      case CustomerRoutePaths.settings:
        return widget.accountApi.profile();
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
      widget.accountApi.orders(),
    ]);
    return _ProfileData(
      profile: results[0],
      addresses: results[1],
      favorites: results[2],
      orders: results[3],
    );
  }

  Future<_HomeData> _loadHome(int storeId) async {
    final results = await Future.wait<Object>([
      widget.catalogApi.categories(storeId),
      widget.catalogApi.offers(storeId),
      widget.catalogApi.products(storeId, sort: 'name', direction: 'asc'),
      widget.catalogApi.banners(storeId),
    ]);
    return _HomeData(
      categories: results[0] as List<B2cCategory>,
      offers: results[1] as List<B2cOffer>,
      products: results[2] as List<B2cProduct>,
      banners: results[3] as List<B2cBanner>,
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
    if (widget.definition.pattern == CustomerRoutePaths.splash) {
      return _buildSplash();
    }
    final content = _contentFor(context, widget.definition.pattern);
    return Scaffold(
      appBar: AppBar(title: Text(context.tr('customer.app.title'))),
      bottomNavigationBar:
          widget.definition.pattern == CustomerRoutePaths.home ||
                  widget.definition.pattern == CustomerRoutePaths.categories ||
                  widget.definition.pattern == CustomerRoutePaths.products ||
                  widget.definition.pattern == CustomerRoutePaths.offers ||
                  widget.definition.pattern == CustomerRoutePaths.favorites ||
                  widget.definition.pattern == CustomerRoutePaths.cart ||
                  widget.definition.pattern == CustomerRoutePaths.profile
              ? NavigationBar(
                  labelBehavior: NavigationDestinationLabelBehavior.alwaysHide,
                  onDestinationSelected: (index) {
                    final routes = [
                      _withStore(CustomerRoutePaths.home),
                      _withStore(CustomerRoutePaths.categories),
                      _withStore(CustomerRoutePaths.offers),
                      _withStore(CustomerRoutePaths.favorites),
                      _withStore(CustomerRoutePaths.cart),
                      CustomerRoutePaths.profile,
                    ];
                    Navigator.of(context).pushReplacementNamed(routes[index]);
                  },
                  destinations: [
                    NavigationDestination(icon: const Icon(Icons.home_rounded), label: context.tr('customer.nav.home')),
                    NavigationDestination(icon: const Icon(Icons.grid_view_rounded), label: context.tr('customer.nav.categories')),
                    NavigationDestination(icon: const Icon(Icons.local_offer_rounded), label: context.tr('customer.nav.offers')),
                    NavigationDestination(icon: const Icon(Icons.favorite_rounded), label: context.tr('customer.nav.favorites')),
                    NavigationDestination(icon: const Icon(Icons.shopping_bag_rounded), label: context.tr('customer.nav.cart')),
                    NavigationDestination(icon: const Icon(Icons.person_rounded), label: context.tr('customer.nav.profile')),
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

  Widget _buildSplash() => Scaffold(
        backgroundColor: const Color(0xFF005C3F),
        body: SafeArea(
          child: Stack(
            fit: StackFit.expand,
            children: [
              Positioned(
                left: -60,
                bottom: 30,
                child: Icon(
                  Icons.eco_rounded,
                  size: 280,
                  color: Colors.white.withValues(alpha: 0.06),
                ),
              ),
              Center(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Container(
                      width: 92,
                      height: 92,
                      decoration: BoxDecoration(
                        color: Colors.white,
                        borderRadius: BorderRadius.circular(28),
                      ),
                      child: const Icon(
                        Icons.shopping_bag_rounded,
                        size: 52,
                        color: Color(0xFF087347),
                      ),
                    ),
                    const SizedBox(height: 24),
                    Text(
                      'FOODEX',
                      key: const ValueKey('foodex-splash-logo'),
                      style: Theme.of(context).textTheme.displaySmall?.copyWith(
                            color: Colors.white,
                            fontWeight: FontWeight.w700,
                          ),
                    ),
                    const SizedBox(height: 8),
                    Text(
                      context.tr('customer.splash.subtitle'),
                      style: TextStyle(color: Colors.white.withValues(alpha: 0.82)),
                    ),
                  ],
                ),
              ),
              Positioned(
                left: 20,
                right: 20,
                bottom: 22,
                child: Text(
                  context.tr('customer.splash.copyright'),
                  textAlign: TextAlign.center,
                  style: TextStyle(
                    color: Colors.white.withValues(alpha: 0.58),
                    fontSize: 12,
                  ),
                ),
              ),
            ],
          ),
        ),
      );

  String _productsRoute({
    int? categoryId,
    String? query,
    String? sort,
    String? direction,
  }) {
    final params = <String, String>{
      if (_storeId != null) 'store': '$_storeId',
      if (categoryId != null) 'category': '$categoryId',
      if (query != null && query.trim().isNotEmpty) 'q': query.trim(),
      'sort': sort ?? _sort,
      'direction': direction ?? _direction,
    };
    return Uri(path: CustomerRoutePaths.products, queryParameters: params).toString();
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
      case CustomerRoutePaths.categories:
        return (
          context.tr('customer.home.categories'),
          context.tr('customer.products.subtitle'),
          [
            if (_storeId == null)
              _storeRequired()
            else
              _remoteBuilder(_buildCategories, context.tr('customer.empty')),
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
                onSubmitted: (value) => Navigator.of(context).pushReplacementNamed(
                  _productsRoute(
                    categoryId: _categoryId,
                    query: value,
                    sort: _sort,
                    direction: _direction,
                  ),
                ),
                trailing: [
                  IconButton(
                    onPressed: () => Navigator.of(context).pushReplacementNamed(
                      _productsRoute(
                        categoryId: _categoryId,
                        query: _search.text,
                        sort: _sort,
                        direction: _direction,
                      ),
                    ),
                    icon: const Icon(Icons.search),
                  ),
                ],
              ),
              const SizedBox(height: 12),
              _remoteBuilder(
                _buildProducts,
                context.tr('customer.products.empty'),
              ),
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
      case CustomerRoutePaths.orders:
        return (
          context.tr('customer.profile.orders'),
          context.tr('customer.orders.subtitle'),
          [_remoteBuilder(_buildOrders, context.tr('customer.orders.empty'))],
        );
      case CustomerRoutePaths.favorites:
        return (
          context.tr('customer.profile.favorites'),
          context.tr('customer.favorites.subtitle'),
          [_remoteBuilder(_buildFavorites, context.tr('customer.favorites.empty'))],
        );
      case CustomerRoutePaths.notifications:
        return (
          context.tr('customer.notifications.title'),
          context.tr('customer.notifications.subtitle'),
          [
            _remoteBuilder(
              _buildNotifications,
              context.tr('customer.notifications.empty'),
            ),
          ],
        );
      case CustomerRoutePaths.addresses:
        return (
          context.tr('customer.profile.addresses'),
          context.tr('customer.addresses.subtitle'),
          [
            FilledButton.icon(
              key: const ValueKey('b2c-address-add'),
              onPressed: _addAddress,
              icon: const Icon(Icons.add_location_alt_outlined),
              label: Text(context.tr('customer.addresses.add')),
            ),
            const SizedBox(height: 12),
            _remoteBuilder(
              _buildAddresses,
              context.tr('customer.addresses.empty'),
            ),
          ],
        );
      case CustomerRoutePaths.settings:
        return (
          context.tr('customer.settings.title'),
          context.tr('customer.settings.subtitle'),
          [_remoteBuilder(_buildSettings, context.tr('customer.empty'))],
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
          return _remoteError(snapshot.error);
        }

        final data = snapshot.data;
        if (data is List && data.isEmpty) return _empty(emptyLabel);
        return builder(data as Object);
      },
    );
  }

  Widget _remoteError(Object? error) {
    var messageKey = 'customer.error.action_failed';
    var stateKey = 'b2c-catalog-error';
    var sessionExpired = false;

    if (error is B2cAccountException) {
      if (error.code == 'network_unavailable') {
        messageKey = 'customer.error.offline';
        stateKey = 'b2c-offline';
      } else if (error.code == 'session_expired' ||
          error.code == 'authentication_required') {
        messageKey = 'customer.error.session_expired';
        stateKey = 'b2c-session-expired';
        sessionExpired = true;
      } else if (error.code == 'forbidden') {
        messageKey = 'customer.error.forbidden';
        stateKey = 'b2c-forbidden';
      }
    }

    return Card(
      key: ValueKey(stateKey),
      child: Padding(
        padding: const EdgeInsets.all(20),
        child: Column(
          children: [
            Text(context.tr(messageKey)),
            const SizedBox(height: 8),
            if (sessionExpired)
              FilledButton(
                key: const ValueKey('b2c-sign-in-recovery'),
                onPressed: () {
                  widget.onSessionExpired();
                  Navigator.of(context).pushNamedAndRemoveUntil(
                    CustomerRoutePaths.checkoutAuth,
                    (route) => false,
                  );
                },
                child: Text(context.tr('customer.action.login')),
              )
            else
              OutlinedButton(
                key: const ValueKey('b2c-retry'),
                onPressed: _reload,
                child: Text(context.tr('customer.action.retry')),
              ),
          ],
        ),
      ),
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

  Widget _buildOrders(Object data) {
    final rows = _rows(data);
    return Column(
      key: const ValueKey('b2c-orders-data'),
      children: rows.map((order) {
        final id = (order['id'] as num?)?.toInt();
        return Card(
          child: ListTile(
            title: Text(order['order_number']?.toString() ?? '#${id ?? ''}'),
            subtitle: Text(
              '${order['status'] ?? ''} · ${order['grand_total'] ?? order['total'] ?? ''} ${order['currency'] ?? 'KWD'}',
            ),
            trailing: const Icon(Icons.chevron_right),
            onTap: id == null ? null : () => Navigator.of(context).pushNamed('/orders/$id/track'),
          ),
        );
      }).toList(growable: false),
    );
  }

  Widget _buildFavorites(Object data) {
    final rows = _rows(data);
    return Column(
      key: const ValueKey('b2c-favorites-data'),
      children: rows.map((product) {
        final id = (product['id'] as num?)?.toInt();
        return Card(
          child: ListTile(
            title: Text(product['name']?.toString() ?? ''),
            subtitle: Text(product['sku']?.toString() ?? ''),
            trailing: IconButton(
              key: ValueKey('b2c-favorite-remove-${id ?? 'unknown'}'),
              onPressed: id == null ? null : () => _removeFavorite(id),
              icon: const Icon(Icons.favorite_rounded, color: Color(0xFFEE731C)),
            ),
          ),
        );
      }).toList(growable: false),
    );
  }

  Widget _buildNotifications(Object data) {
    final rows = _rows(data);
    return Column(
      key: const ValueKey('b2c-notifications-data'),
      children: rows.map((notification) {
        final id = (notification['id'] as num?)?.toInt();
        final unread = notification['read_at'] == null;
        return Card(
          child: ListTile(
            leading: Icon(
              unread ? Icons.notifications_active_rounded : Icons.notifications_none_rounded,
              color: unread ? const Color(0xFF087347) : Colors.grey,
            ),
            title: Text(
              notification['title']?.toString() ?? '',
              style: TextStyle(fontWeight: unread ? FontWeight.w700 : FontWeight.w400),
            ),
            subtitle: Text(notification['body']?.toString() ?? ''),
            onTap: id == null || !unread ? null : () => _markNotification(id),
          ),
        );
      }).toList(growable: false),
    );
  }

  Widget _buildAddresses(Object data) {
    final rows = _rows(data);
    return Column(
      key: const ValueKey('b2c-addresses-data'),
      children: rows.map((address) {
        final id = (address['id'] as num?)?.toInt();
        return Card(
          child: ListTile(
            leading: Icon(address['is_default'] == true ? Icons.home_rounded : Icons.location_on_outlined),
            title: Text(address['label']?.toString() ?? context.tr('customer.addresses.address')),
            subtitle: Text(
              [address['line1'], address['area'], address['city']]
                  .where((value) => value != null && value.toString().isNotEmpty)
                  .join(' · '),
            ),
            trailing: id == null
                ? null
                : IconButton(
                    onPressed: () => _removeAddress(id),
                    icon: const Icon(Icons.delete_outline),
                  ),
          ),
        );
      }).toList(growable: false),
    );
  }

  Widget _buildSettings(Object data) {
    final profile = data is Map ? Map<String, dynamic>.from(data) : <String, dynamic>{};
    return Column(
      key: const ValueKey('b2c-settings-data'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        ..._mapCards(profile, skip: const {'roles', 'store_ids', 'customer', 'addresses', 'favorites'}),
        const SizedBox(height: 12),
        FilledButton.icon(
          key: const ValueKey('b2c-profile-edit'),
          onPressed: () => _editProfile(profile),
          icon: const Icon(Icons.edit_outlined),
          label: Text(context.tr('customer.settings.edit_profile')),
        ),
      ],
    );
  }

  List<Map<String, dynamic>> _rows(Object? value) {
    if (value is Map && value['data'] is List) {
      return (value['data'] as List)
          .whereType<Map>()
          .map((item) => Map<String, dynamic>.from(item))
          .toList(growable: false);
    }
    if (value is List) {
      return value
          .whereType<Map>()
          .map((item) => Map<String, dynamic>.from(item))
          .toList(growable: false);
    }
    return const [];
  }

  Future<void> _addFavorite(int productId) async {
    try {
      await widget.accountApi.addFavorite(productId);
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(context.tr('customer.favorites.added'))),
        );
      }
    } on B2cAccountException catch (error) {
      if (!mounted) return;
      if (error.code == 'authentication_required' || error.code == 'session_expired') {
        Navigator.of(context).pushNamed(CustomerRoutePaths.checkoutAuth);
      } else {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(context.tr('customer.error.action_failed'))),
        );
      }
    }
  }

  Future<void> _removeFavorite(int productId) async {
    try {
      await widget.accountApi.removeFavorite(productId);
      _reload();
    } catch (_) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(context.tr('customer.error.action_failed'))),
        );
      }
    }
  }

  Future<void> _markNotification(int id) async {
    try {
      await widget.accountApi.markNotificationRead(id);
      _reload();
    } catch (_) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(context.tr('customer.error.action_failed'))),
        );
      }
    }
  }

  Future<void> _removeAddress(int id) async {
    try {
      await widget.accountApi.removeAddress(id);
      _reload();
    } catch (_) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(context.tr('customer.error.action_failed'))),
        );
      }
    }
  }

  Future<void> _addAddress() async {
    final line1 = TextEditingController();
    final city = TextEditingController(text: 'Kuwait City');
    final area = TextEditingController();
    final accepted = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: Text(context.tr('customer.addresses.add')),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            TextField(controller: line1, decoration: InputDecoration(labelText: context.tr('customer.addresses.line1'))),
            TextField(controller: area, decoration: InputDecoration(labelText: context.tr('customer.addresses.area'))),
            TextField(controller: city, decoration: InputDecoration(labelText: context.tr('customer.addresses.city'))),
          ],
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(dialogContext, false), child: Text(context.tr('customer.action.cancel'))),
          FilledButton(onPressed: () => Navigator.pop(dialogContext, true), child: Text(context.tr('customer.action.save'))),
        ],
      ),
    );
    if (accepted == true && line1.text.trim().isNotEmpty && city.text.trim().isNotEmpty) {
      try {
        await widget.accountApi.createAddress({
          'line1': line1.text.trim(),
          'city': city.text.trim(),
          'area': area.text.trim().isEmpty ? null : area.text.trim(),
          'country_code': 'KW',
        });
        _reload();
      } catch (_) {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text(context.tr('customer.error.action_failed'))),
          );
        }
      }
    }
    line1.dispose();
    city.dispose();
    area.dispose();
  }

  Future<void> _editProfile(Map<String, dynamic> profile) async {
    final name = TextEditingController(text: profile['name']?.toString() ?? '');
    final email = TextEditingController(text: profile['email']?.toString() ?? '');
    final accepted = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: Text(context.tr('customer.settings.edit_profile')),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            TextField(controller: name, decoration: InputDecoration(labelText: context.tr('customer.settings.name'))),
            TextField(controller: email, decoration: InputDecoration(labelText: context.tr('customer.settings.email'))),
          ],
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(dialogContext, false), child: Text(context.tr('customer.action.cancel'))),
          FilledButton(onPressed: () => Navigator.pop(dialogContext, true), child: Text(context.tr('customer.action.save'))),
        ],
      ),
    );
    if (accepted == true) {
      try {
        await widget.accountApi.updateProfile({
          'name': name.text.trim(),
          'email': email.text.trim(),
          'locale': WidgetsBinding.instance.platformDispatcher.locale.languageCode,
        });
        _reload();
      } catch (_) {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text(context.tr('customer.error.action_failed'))),
          );
        }
      }
    }
    name.dispose();
    email.dispose();
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
        _section(
          context.tr('customer.profile.favorites'),
          onTap: () => Navigator.of(context).pushNamed(_withStore(CustomerRoutePaths.favorites)),
        ),
        ..._objectCards(profile.favorites),
        _section(
          context.tr('customer.profile.orders'),
          onTap: () => Navigator.of(context).pushNamed(CustomerRoutePaths.orders),
        ),
        ..._objectCards(profile.orders),
        _section(
          context.tr('customer.notifications.title'),
          onTap: () => Navigator.of(context).pushNamed(CustomerRoutePaths.notifications),
        ),
        _section(
          context.tr('customer.settings.title'),
          onTap: () => Navigator.of(context).pushNamed(CustomerRoutePaths.settings),
        ),
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
        Container(
          padding: const EdgeInsets.all(20),
          decoration: BoxDecoration(
            color: const Color(0xFF005C3F),
            borderRadius: BorderRadius.circular(26),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Row(
                children: [
                  const Icon(Icons.shopping_bag_rounded, color: Colors.white, size: 30),
                  const SizedBox(width: 10),
                  const Expanded(
                    child: Text(
                      'FOODEX',
                      style: TextStyle(color: Colors.white, fontSize: 22, fontWeight: FontWeight.w700),
                    ),
                  ),
                  IconButton(
                    key: const ValueKey('b2c-home-notifications'),
                    onPressed: () => Navigator.of(context).pushNamed(CustomerRoutePaths.notifications),
                    icon: const Icon(Icons.notifications_none_rounded, color: Colors.white),
                  ),
                ],
              ),
              const SizedBox(height: 14),
              SearchBar(
                key: const ValueKey('b2c-home-search'),
                hintText: context.tr('customer.products.search'),
                leading: const Icon(Icons.search),
                onSubmitted: (value) => Navigator.of(context).pushNamed(_productsRoute(query: value)),
              ),
            ],
          ),
        ),
        const SizedBox(height: 18),
        _section(
          context.tr('customer.home.categories'),
          onTap: () => Navigator.of(context).pushNamed(_withStore(CustomerRoutePaths.categories)),
        ),
        SizedBox(
          height: 102,
          child: ListView.separated(
            scrollDirection: Axis.horizontal,
            itemCount: home.categories.length,
            separatorBuilder: (_, __) => const SizedBox(width: 10),
            itemBuilder: (_, index) {
              final category = home.categories[index];
              return InkWell(
                key: ValueKey('b2c-home-category-${category.id}'),
                onTap: () => Navigator.of(context).pushNamed(_productsRoute(categoryId: category.id)),
                child: SizedBox(
                  width: 78,
                  child: Column(
                    children: [
                      CircleAvatar(
                        radius: 30,
                        backgroundColor: const Color(0xFFEAF7EF),
                        foregroundImage: category.imageUrl == null ? null : NetworkImage(category.imageUrl!),
                        child: category.imageUrl == null
                            ? const Icon(Icons.category_rounded, color: Color(0xFF087347))
                            : null,
                      ),
                      const SizedBox(height: 6),
                      Text(category.name, maxLines: 1, overflow: TextOverflow.ellipsis, textAlign: TextAlign.center),
                    ],
                  ),
                ),
              );
            },
          ),
        ),
        _section(
          context.tr('customer.home.offers'),
          onTap: () => Navigator.of(context).pushNamed(_withStore(CustomerRoutePaths.offers)),
        ),
        ...home.offers.take(3).map(
              (offer) => Card(
                color: const Color(0xFFFFF2E8),
                child: ListTile(
                  leading: const Icon(Icons.local_offer_rounded, color: Color(0xFFEE731C)),
                  title: Text(offer.name),
                  subtitle: Text(offer.type),
                ),
              ),
            ),
        if (home.banners.isNotEmpty)
          Container(
            height: 130,
            margin: const EdgeInsets.symmetric(vertical: 8),
            clipBehavior: Clip.antiAlias,
            decoration: BoxDecoration(
              color: const Color(0xFF087347),
              borderRadius: BorderRadius.circular(24),
            ),
            child: Stack(
              fit: StackFit.expand,
              children: [
                if (home.banners.first.imageUrl != null)
                  Image.network(
                    home.banners.first.imageUrl!,
                    fit: BoxFit.cover,
                    errorBuilder: (_, __, ___) => const SizedBox.shrink(),
                  ),
                Container(color: const Color(0x55004B35)),
                Padding(
                  padding: const EdgeInsets.all(18),
                  child: Align(
                    alignment: AlignmentDirectional.centerStart,
                    child: Text(
                      home.banners.first.title,
                      style: const TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.w700),
                    ),
                  ),
                ),
              ],
            ),
          ),
        _section(
          context.tr('customer.home.recent'),
          onTap: () => Navigator.of(context).pushNamed(_withStore(CustomerRoutePaths.products)),
        ),
        SizedBox(
          height: 190,
          child: ListView.separated(
            scrollDirection: Axis.horizontal,
            itemCount: home.products.take(8).length,
            separatorBuilder: (_, __) => const SizedBox(width: 10),
            itemBuilder: (_, index) => SizedBox(width: 210, child: _productCard(home.products[index])),
          ),
        ),
        _section(
          context.tr('customer.home.all_products'),
          onTap: () => Navigator.of(context).pushNamed(_withStore(CustomerRoutePaths.products)),
        ),
        LayoutBuilder(
          builder: (context, constraints) {
            final tileWidth = (constraints.maxWidth - 12) / 2;
            return Wrap(
              spacing: 12,
              runSpacing: 12,
              children: home.products
                  .take(6)
                  .map(
                    (product) => SizedBox(
                      width: tileWidth,
                      child: _homeProductTile(product),
                    ),
                  )
                  .toList(growable: false),
            );
          },
        ),
      ],
    );
  }

  Widget _buildCategories(Object data) => Column(
        key: const ValueKey('b2c-category-results'),
        children: (data as List<B2cCategory>)
            .map(
              (category) => Card(
                child: ListTile(
                  key: ValueKey('b2c-category-${category.id}'),
                  leading: CircleAvatar(
                    backgroundColor: const Color(0xFFEAF7EF),
                    foregroundImage: category.imageUrl == null ? null : NetworkImage(category.imageUrl!),
                    child: category.imageUrl == null ? const Icon(Icons.category_rounded) : null,
                  ),
                  title: Text(category.name),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => Navigator.of(context).pushNamed(_productsRoute(categoryId: category.id)),
                ),
              ),
            )
            .toList(growable: false),
      );

  Widget _buildOffers(Object data) => Column(
        key: const ValueKey('b2c-offer-results'),
        children: (data as List<B2cOffer>)
            .map((offer) => _dataCard(
                  offer.name,
                  [offer.type, if (offer.value != null) offer.value!.toStringAsFixed(2)].join(' · '),
                ))
            .toList(growable: false),
      );

  Widget _buildProducts(Object data) {
    final products = data as List<B2cProduct>;
    return Column(
      key: const ValueKey('b2c-product-results'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            ActionChip(
              label: Text(context.tr('customer.home.categories')),
              avatar: const Icon(Icons.category_outlined, size: 18),
              onPressed: () => Navigator.of(context).pushNamed(_withStore(CustomerRoutePaths.categories)),
            ),
            ChoiceChip(
              label: Text(context.tr('customer.products.sort_name')),
              selected: _sort == 'name',
              onSelected: (_) => Navigator.of(context).pushReplacementNamed(
                _productsRoute(categoryId: _categoryId, query: _query, sort: 'name', direction: _direction),
              ),
            ),
            ChoiceChip(
              label: Text(context.tr('customer.products.sort_price')),
              selected: _sort == 'price',
              onSelected: (_) => Navigator.of(context).pushReplacementNamed(
                _productsRoute(categoryId: _categoryId, query: _query, sort: 'price', direction: _direction),
              ),
            ),
            ActionChip(
              label: Text(_direction == 'asc'
                  ? context.tr('customer.products.ascending')
                  : context.tr('customer.products.descending')),
              avatar: Icon(_direction == 'asc' ? Icons.arrow_upward : Icons.arrow_downward, size: 18),
              onPressed: () => Navigator.of(context).pushReplacementNamed(
                _productsRoute(
                  categoryId: _categoryId,
                  query: _query,
                  sort: _sort,
                  direction: _direction == 'asc' ? 'desc' : 'asc',
                ),
              ),
            ),
          ],
        ),
        const SizedBox(height: 12),
        if (products.isEmpty)
          _empty(context.tr('customer.products.empty'))
        else
          ...products.map(_productCard),
      ],
    );
  }

  Widget _homeProductTile(B2cProduct product) => Card(
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          key: ValueKey('b2c-home-product-${product.id}'),
          onTap: () => Navigator.of(context).pushNamed(
            Uri(
              path: '/products/${product.id}',
              queryParameters: {'store': '${_storeId!}'},
            ).toString(),
          ),
          child: Padding(
            padding: const EdgeInsets.all(12),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                AspectRatio(
                  aspectRatio: 1.35,
                  child: Container(
                    decoration: BoxDecoration(
                      color: const Color(0xFFF2F4F7),
                      borderRadius: BorderRadius.circular(14),
                    ),
                    clipBehavior: Clip.antiAlias,
                    child: product.imageUrl == null
                        ? const Icon(
                            Icons.shopping_basket_outlined,
                            color: Color(0xFF087347),
                            size: 38,
                          )
                        : Image.network(
                            product.imageUrl!,
                            fit: BoxFit.cover,
                            errorBuilder: (_, __, ___) => const Icon(
                              Icons.shopping_basket_outlined,
                              color: Color(0xFF087347),
                              size: 38,
                            ),
                          ),
                  ),
                ),
                const SizedBox(height: 10),
                Text(
                  product.name,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(fontWeight: FontWeight.w700),
                ),
                const SizedBox(height: 6),
                Text(
                  product.price == null
                      ? product.sku
                      : '${product.price!.toStringAsFixed(3)} ${product.currency}',
                  style: const TextStyle(
                    color: Color(0xFF087347),
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ],
            ),
          ),
        ),
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
        OutlinedButton.icon(
          key: const ValueKey('b2c-favorite-add'),
          onPressed: () => _addFavorite(product.id),
          icon: const Icon(Icons.favorite_border_rounded),
          label: Text(context.tr('customer.favorites.add')),
        ),
        const SizedBox(height: 8),
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
    required this.banners,
  });

  final List<B2cCategory> categories;
  final List<B2cOffer> offers;
  final List<B2cProduct> products;
  final List<B2cBanner> banners;
}

class _ProfileData {
  const _ProfileData({
    required this.profile,
    required this.addresses,
    required this.favorites,
    required this.orders,
  });
  final Object? profile;
  final Object? addresses;
  final Object? favorites;
  final Object? orders;
}
