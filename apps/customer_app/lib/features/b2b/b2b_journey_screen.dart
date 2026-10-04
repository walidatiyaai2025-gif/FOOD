import 'package:flutter/material.dart';

import '../../core/api/b2b_api.dart';
import '../../core/api/b2c_account_api.dart';
import '../../core/api/customer_action_api.dart';
import '../../core/auth/customer_session.dart';
import '../../core/diagnostics/customer_diagnostics.dart';
import '../../core/localization/app_translations.dart';
import '../../core/routing/customer_commerce_context.dart';
import '../../core/routing/customer_routes.dart';
import '../customer_account/customer_address_book_screen.dart';
import '../customer_account/customer_account_data.dart';
import '../customer_account/customer_notification_center_screen.dart';
import '../customer_orders/customer_order_screens.dart';
import 'business_account_profile.dart';
import '../customer_orders/customer_orders_api.dart';
import '../../shared/customer_action_widgets.dart';
import '../../shared/customer_persistent_footer.dart';

class B2bJourneyScreen extends StatelessWidget {
  const B2bJourneyScreen({
    required this.definition,
    required this.location,
    required this.actionApi,
    this.api,
    this.accountApi,
    this.ordersApi,
    super.key,
  });

  final CustomerRouteDefinition definition;
  final String location;
  final B2bApi? api;
  final B2cAccountApi? accountApi;
  final CustomerOrdersApi? ordersApi;
  final CustomerActionApi actionApi;

  @override
  Widget build(BuildContext context) {
    final commerceContext =
        CustomerCommerceContext.tryParseLocation(location);

    Widget withFooter(
      Widget child,
      CustomerFooterDestination destination,
    ) {
      if (commerceContext == null || !commerceContext.isWholesale) {
        return child;
      }
      return CustomerPersistentFooterShell(
        commerceContext: commerceContext,
        activeDestination: destination,
        child: child,
      );
    }

    if (definition.pattern == CustomerRoutePaths.b2bAddresses &&
        accountApi != null) {
      return withFooter(
        CustomerAddressBookScreen(api: accountApi!),
        CustomerFooterDestination.account,
      );
    }

    if (definition.pattern == CustomerRoutePaths.b2bNotifications &&
        accountApi != null) {
      return withFooter(
        CustomerNotificationCenterScreen(
          api: accountApi!,
          onOpenOrder: (target) => _openNotificationOrder(context, target),
        ),
        CustomerFooterDestination.account,
      );
    }

    if (definition.pattern == CustomerRoutePaths.b2bOrders &&
        ordersApi != null) {
      return withFooter(
        CustomerOrdersScreen(
          api: ordersApi!,
          actionApi: actionApi,
          onOpenCart: (order) {
            final orderContext = CustomerCommerceContext(
              channel: CustomerCommerceChannel.wholesale,
              storeId: order.storeId,
            );
            Navigator.of(context).pushNamed(
              Uri(
                path: CustomerRoutePaths.b2bCart,
                queryParameters: orderContext.toQueryParameters(),
              ).toString(),
            );
          },
          onOpenOrder: (order) {
            final orderContext = CustomerCommerceContext(
              channel: order.channel == 'b2b'
                  ? CustomerCommerceChannel.wholesale
                  : CustomerCommerceChannel.retail,
              storeId: order.storeId,
            );
            final target = order.channel == 'b2b'
                ? Uri(
                    path: '/b2b/orders/${order.id}',
                    queryParameters: orderContext.toQueryParameters(),
                  ).toString()
                : Uri(
                    path: '/orders/${order.id}/track',
                    queryParameters: orderContext.toQueryParameters(),
                  ).toString();
            Navigator.of(context).pushNamed(target);
          },
        ),
        CustomerFooterDestination.orders,
      );
    }

    final content = _contentFor(context, definition.pattern);
    final hasRemoteState = api != null && _endpoint() != null;
    final isProfile =
        definition.pattern == CustomerRoutePaths.b2bProfile;
    final keepLocalActions =
        definition.pattern == CustomerRoutePaths.b2bProductDetails ||
        definition.pattern == CustomerRoutePaths.b2bCart;

    final destination = switch (definition.pattern) {
      CustomerRoutePaths.b2bProducts ||
      CustomerRoutePaths.b2bProductDetails =>
        CustomerFooterDestination.products,
      CustomerRoutePaths.b2bCart ||
      CustomerRoutePaths.b2bCheckout =>
        CustomerFooterDestination.cart,
      CustomerRoutePaths.b2bOrders ||
      CustomerRoutePaths.b2bOrderDetails =>
        CustomerFooterDestination.orders,
      CustomerRoutePaths.b2bProfile ||
      CustomerRoutePaths.b2bAddresses ||
      CustomerRoutePaths.b2bNotifications =>
        CustomerFooterDestination.account,
      _ => CustomerFooterDestination.home,
    };

    return withFooter(
      Scaffold(
      appBar: AppBar(title: Text(context.tr('b2b.app.title'))),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(20),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
            Text(
              content.$1,
              key: const ValueKey('customer-route-label'),
              style: Theme.of(context).textTheme.headlineSmall,
            ),
            const SizedBox(height: 8),
            Text(content.$2),
            const SizedBox(height: 20),
            if (!hasRemoteState || keepLocalActions) ...content.$3,
            if (hasRemoteState && isProfile)
              B2bBusinessAccountProfile(
                api: api!,
                endpoint: _endpoint()!,
                addressesRoute:
                    _scopedB2bRoute(CustomerRoutePaths.b2bAddresses),
              ),
            if (hasRemoteState && keepLocalActions)
              definition.pattern == CustomerRoutePaths.b2bProductDetails
                  ? _B2bProductDetailRemoteState(
                      api: api!,
                      endpoint: _endpoint()!,
                    )
                  : _RemoteState(
                      api: api!,
                      endpoint: _endpoint()!,
                      routePattern: definition.pattern,
                      showEmpty: false,
                    ),
            if (hasRemoteState && !keepLocalActions && !isProfile)
              definition.pattern == CustomerRoutePaths.b2bTopProducts
                  ? _TopProductsRemoteState(api: api!, endpoint: _endpoint()!)
                  : _RemoteState(
                      api: api!,
                      endpoint: _endpoint()!,
                      routePattern: definition.pattern,
                    ),
            ],
          ),
        ),
      ),
      ),
      destination,
    );
  }

  (String, String, List<Widget>) _contentFor(
    BuildContext context,
    String pattern,
  ) {
    switch (pattern) {
      case CustomerRoutePaths.b2bDashboard:
        return (
          context.tr('b2b.dashboard.title'),
          context.tr('b2b.dashboard.subtitle'),
          [
            _section(context.tr('b2b.finance.purchases')),
            _section(context.tr('b2b.finance.invoices')),
            _section(context.tr('b2b.finance.statement')),
          ],
        );
      case CustomerRoutePaths.b2bPurchaseReports:
        return (
          context.tr('b2b.purchase_reports.title'),
          context.tr('b2b.purchase_reports.subtitle'),
          [_empty(context.tr('b2b.empty.period'))],
        );
      case CustomerRoutePaths.b2bTopProducts:
        return (
          context.tr('b2b.top_products.title'),
          context.tr('b2b.top_products.subtitle'),
          [_empty(context.tr('b2b.empty.purchases'))],
        );
      case CustomerRoutePaths.b2bProducts:
        return (
          context.tr('b2b.products.title'),
          context.tr('b2b.products.subtitle'),
          [
            SearchBar(hintText: context.tr('customer.products.search')),
            _empty(context.tr('b2b.empty.products')),
          ],
        );
      case CustomerRoutePaths.b2bProductDetails:
        return (
          context.tr('b2b.product.title'),
          context.tr('b2b.product.subtitle'),
          [
            AddCartAction(
              api: actionApi,
              location: location,
              cartRoute: _b2bCartRoute(),
            ),
          ],
        );
      case CustomerRoutePaths.b2bInvoices:
        return (
          context.tr('b2b.invoices.title'),
          context.tr('b2b.invoices.subtitle'),
          [_empty(context.tr('b2b.empty.invoices'))],
        );
      case CustomerRoutePaths.b2bInvoiceDetails:
        return (
          context.tr('b2b.invoice.title'),
          context.tr('b2b.invoice.subtitle'),
          [
            _section(context.tr('b2b.invoice.items')),
            _section(context.tr('b2b.invoice.payment_status')),
          ],
        );
      case CustomerRoutePaths.b2bAccountStatement:
        return (
          context.tr('b2b.statement.title'),
          context.tr('b2b.statement.subtitle'),
          [_empty(context.tr('b2b.empty.statement'))],
        );
      case CustomerRoutePaths.b2bOrders:
        return (
          context.tr('b2b.orders.title'),
          context.tr('b2b.orders.subtitle'),
          [_empty(context.tr('b2b.empty.orders'))],
        );
      case CustomerRoutePaths.b2bOrderDetails:
        return (
          context.tr('b2b.order.title'),
          context.tr('b2b.order.subtitle'),
          [
            _section(context.tr('b2b.order.status')),
            _section(context.tr('b2b.order.tracking')),
          ],
        );
      case CustomerRoutePaths.b2bNotifications:
        return (
          context.tr('customer.notifications.title'),
          context.tr('customer.notifications.subtitle'),
          const <Widget>[],
        );
      case CustomerRoutePaths.b2bCart:
        return (
          context.tr('b2b.cart.title'),
          context.tr('b2b.cart.subtitle'),
          [
            _empty(context.tr('b2b.cart.empty')),
            _button(
              context,
              context.tr('b2b.action.checkout'),
              CustomerRoutePaths.b2bCheckout,
            ),
          ],
        );
      case CustomerRoutePaths.b2bCheckout:
        return (
          context.tr('customer.checkout.title'),
          context.tr('customer.checkout.subtitle'),
          [
            CheckoutAction(
              api: actionApi,
              channel: CustomerChannel.b2b,
              accountApi: accountApi,
            ),
          ],
        );
      case CustomerRoutePaths.b2bAddresses:
        return (
          context.tr('customer.profile.addresses'),
          context.tr('customer.addresses.empty'),
          [_empty(context.tr('customer.addresses.empty'))],
        );
      case CustomerRoutePaths.b2bProfile:
        return (
          context.tr('b2b.profile.title'),
          context.tr('b2b.profile.subtitle'),
          [
            _section(context.tr('b2b.profile.company')),
            _button(
              context,
              context.tr('customer.profile.addresses'),
              _scopedB2bRoute(CustomerRoutePaths.b2bAddresses),
            ),
            _section(context.tr('b2b.profile.settings')),
          ],
        );
      default:
        return (
          definition.label,
          'FOODEX Business',
          [_empty(context.tr('customer.empty'))],
        );
    }
  }

  void _openNotificationOrder(
    BuildContext context,
    CustomerNotificationTarget target,
  ) {
    final uri = Uri.parse(location);
    final currentStoreId = int.tryParse(
      uri.queryParameters['store_id'] ??
          uri.queryParameters['store'] ??
          '',
    );

    if (target.channel.toLowerCase() == 'b2b') {
      final storeId = target.storeId ?? currentStoreId;
      if (storeId == null || storeId <= 0) return;
      Navigator.of(context).pushNamed(
        Uri(
          path: '/b2b/orders/${target.orderId}',
          queryParameters: <String, String>{
            'channel': 'wholesale',
            'store_id': storeId.toString(),
          },
        ).toString(),
      );
      return;
    }

    if (target.channel.toLowerCase() == 'b2c') {
      final storeId = target.storeId;
      if (storeId == null || storeId <= 0) return;
      Navigator.of(context).pushNamed(
        Uri(
          path: '/orders/${target.orderId}/track',
          queryParameters: <String, String>{
            'channel': 'retail',
            'store_id': storeId.toString(),
          },
        ).toString(),
      );
    }
  }

  String _scopedB2bRoute(String path) {
    final commerceContext =
        CustomerCommerceContext.tryParseLocation(location);
    if (commerceContext == null || !commerceContext.isWholesale) {
      return path;
    }
    return Uri(
      path: path,
      queryParameters: commerceContext.toQueryParameters(),
    ).toString();
  }

  String _b2bCartRoute() {
    final uri = Uri.parse(location);
    final storeId =
        uri.queryParameters['store_id'] ?? uri.queryParameters['store'];

    if (storeId == null || storeId.isEmpty) {
      return CustomerRoutePaths.b2bCart;
    }

    return Uri(
      path: CustomerRoutePaths.b2bCart,
      queryParameters: {'store': storeId},
    ).toString();
  }

  String? _endpoint() {
    final uri = Uri.parse(location);
    final segments = uri.pathSegments;
    switch (definition.pattern) {
      case CustomerRoutePaths.b2bDashboard:
        return '/api/v1/b2b/dashboard';
      case CustomerRoutePaths.b2bPurchaseReports:
        return '/api/v1/b2b/reports/purchases';
      case CustomerRoutePaths.b2bTopProducts:
        return '/api/v1/b2b/products/top${uri.hasQuery ? '?${uri.query}' : ''}';
      case CustomerRoutePaths.b2bProducts:
        return '/api/v1/b2b/products${uri.hasQuery ? '?${uri.query}' : ''}';
      case CustomerRoutePaths.b2bProductDetails:
        final storeId =
            uri.queryParameters['store_id'] ?? uri.queryParameters['store'];
        if (storeId == null || storeId.isEmpty) return null;
        return '/api/v1/b2b/products/${segments.last}?store_id=$storeId';
      case CustomerRoutePaths.b2bInvoices:
        return '/api/v1/b2b/invoices';
      case CustomerRoutePaths.b2bInvoiceDetails:
        return '/api/v1/b2b/invoices/${segments.last}';
      case CustomerRoutePaths.b2bAccountStatement:
        return '/api/v1/b2b/account-statement';
      case CustomerRoutePaths.b2bOrders:
        return '/api/v1/b2b/orders';
      case CustomerRoutePaths.b2bOrderDetails:
        return '/api/v1/b2b/orders/${segments.last}';
      case CustomerRoutePaths.b2bCart:
        return '/api/v1/cart${uri.hasQuery ? '?${uri.query}' : ''}';
      case CustomerRoutePaths.b2bProfile:
        return '/api/v1/profile';
      default:
        return null;
    }
  }

  Widget _button(
    BuildContext context,
    String label,
    String route,
  ) =>
      Padding(
        padding: const EdgeInsets.only(bottom: 12),
        child: FilledButton(
          onPressed: () => Navigator.of(context).pushNamed(route),
          child: Text(label),
        ),
      );

  Widget _section(String label) => Card(
        child: ListTile(
          title: Text(label),
          trailing: const Icon(Icons.chevron_right),
        ),
      );

  Widget _empty(String label) => Card(
        key: const ValueKey('b2b-empty'),
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Center(child: Text(label)),
        ),
      );
}


class _B2bProductDetailRemoteState extends StatefulWidget {
  const _B2bProductDetailRemoteState({
    required this.api,
    required this.endpoint,
  });

  final B2bApi api;
  final String endpoint;

  @override
  State<_B2bProductDetailRemoteState> createState() =>
      _B2bProductDetailRemoteStateState();
}

class _B2bProductDetailRemoteStateState
    extends State<_B2bProductDetailRemoteState> {
  late Future<Object?> _future;

  @override
  void initState() {
    super.initState();
    _future = _loadB2bRemote(widget.api, widget.endpoint);
  }

  @override
  void didUpdateWidget(covariant _B2bProductDetailRemoteState oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.api != widget.api || oldWidget.endpoint != widget.endpoint) {
      _future = _loadB2bRemote(widget.api, widget.endpoint);
    }
  }

  void _retry() {
    setState(() {
      _future = _loadB2bRemote(widget.api, widget.endpoint);
    });
  }

  @override
  Widget build(BuildContext context) => FutureBuilder<Object?>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done) {
            return const Center(
              key: ValueKey('b2b-loading'),
              child: CircularProgressIndicator(),
            );
          }

          if (snapshot.hasError) {
            return _B2bRemoteErrorCard(
              error: snapshot.error,
              onRetry: _retry,
              backRoute: _productsBackRoute(widget.endpoint),
            );
          }

          final value = snapshot.data;
          if (value is! Map) {
            return Card(
              key: const ValueKey('b2b-empty'),
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Text(context.tr('b2b.remote.empty')),
              ),
            );
          }

          final name = value['name']?.toString() ?? '';
          final sku = value['sku']?.toString() ?? '';
          final price = value['account_price']?.toString() ?? '-';
          final minimum =
              value['minimum_order_quantity']?.toString() ?? '-';
          final available = value['available_quantity']?.toString();
          final currency = value['currency']?.toString() ?? 'KWD';
          final tier = value['price_tier']?.toString() ?? '-';
          final images = (value['images'] as List? ?? const [])
              .whereType<String>()
              .where((url) => url.trim().isNotEmpty)
              .toList(growable: false);
          final primaryImage = value['image_url']?.toString();
          final gallery = images.isNotEmpty
              ? images
              : (primaryImage == null || primaryImage.isEmpty
                  ? const <String>[]
                  : <String>[primaryImage]);

          return Card(
            key: const ValueKey('b2b-product-detail-data'),
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  if (gallery.isNotEmpty)
                    SizedBox(
                      key: const ValueKey('b2b-product-gallery'),
                      height: 240,
                      child: PageView.builder(
                        itemCount: gallery.length,
                        itemBuilder: (_, index) => Padding(
                          padding: const EdgeInsets.only(bottom: 12),
                          child: _B2bCatalogImage(url: gallery[index]),
                        ),
                      ),
                    ),
                  Text(
                    name,
                    style: Theme.of(context).textTheme.titleLarge,
                  ),
                  const SizedBox(height: 6),
                  Text(sku),
                  const SizedBox(height: 12),
                  Text(
                    '${context.tr('b2b.product.account_price')}: $price $currency',
                  ),
                  Text(
                    '${context.tr('b2b.minimum_order')}: $minimum',
                  ),
                  Text(
                    '${context.tr('b2b.product.inventory')}: ${available ?? context.tr('b2b.product.inventory_unbounded')}',
                  ),
                  Text(
                    '${context.tr('b2b.product.price_tier')}: $tier',
                  ),
                ],
              ),
            ),
          );
        },
      );
}

class _B2bCatalogImage extends StatelessWidget {
  const _B2bCatalogImage({required this.url});

  final String url;

  @override
  Widget build(BuildContext context) => ClipRRect(
        borderRadius: BorderRadius.circular(18),
        child: ColoredBox(
          color: const Color(0xFFF2F4F7),
          child: Image.network(
            url,
            fit: BoxFit.cover,
            loadingBuilder: (context, child, progress) => progress == null
                ? child
                : const Center(child: CircularProgressIndicator()),
            errorBuilder: (_, __, ___) => const Center(
              child: Icon(
                Icons.shopping_basket_outlined,
                color: Color(0xFF087347),
                size: 42,
              ),
            ),
          ),
        ),
      );
}

class _TopProductsRemoteState extends StatefulWidget {
  const _TopProductsRemoteState({required this.api, required this.endpoint});

  final B2bApi api;
  final String endpoint;

  @override
  State<_TopProductsRemoteState> createState() => _TopProductsRemoteStateState();
}

class _TopProductsRemoteStateState extends State<_TopProductsRemoteState> {
  late Future<Object?> _future;

  @override
  void initState() {
    super.initState();
    _future = _loadB2bRemote(widget.api, widget.endpoint);
  }

  @override
  void didUpdateWidget(covariant _TopProductsRemoteState oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.api != widget.api || oldWidget.endpoint != widget.endpoint) {
      _future = _loadB2bRemote(widget.api, widget.endpoint);
    }
  }

  void _retry() {
    setState(() {
      _future = _loadB2bRemote(widget.api, widget.endpoint);
    });
  }

  @override
  Widget build(BuildContext context) => FutureBuilder<Object?>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done) {
            return const Center(
              key: ValueKey('b2b-loading'),
              child: CircularProgressIndicator(),
            );
          }
          if (snapshot.hasError) {
            return _B2bRemoteErrorCard(
              error: snapshot.error,
              onRetry: _retry,
            );
          }

          final value = snapshot.data;
          final rows = value is Map && value['data'] is List
              ? (value['data'] as List).whereType<Map>().toList(growable: false)
              : const <Map>[];
          if (rows.isEmpty) {
            return Card(
              key: const ValueKey('b2b-empty'),
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Text(context.tr('b2b.empty.purchases')),
              ),
            );
          }

          return Column(
            key: const ValueKey('b2b-top-products-data'),
            children: rows.map((row) {
              final rank = row['rank']?.toString() ?? '-';
              final name = row['name']?.toString() ?? '';
              final sku = row['sku']?.toString() ?? '';
              final quantity = row['quantity']?.toString() ?? '0';
              final total = row['total']?.toString() ?? '0';
              final currency = row['currency']?.toString() ?? 'KWD';
              return Card(
                child: ListTile(
                  key: ValueKey('b2b-top-product-$rank'),
                  title: Text('#$rank · $name'),
                  subtitle: Text('$sku · $quantity · $total $currency'),
                ),
              );
            }).toList(growable: false),
          );
        },
      );
}

class _RemoteState extends StatefulWidget {
  const _RemoteState({
    required this.api,
    required this.endpoint,
    required this.routePattern,
    this.showEmpty = true,
  });

  final B2bApi api;
  final String endpoint;
  final String routePattern;
  final bool showEmpty;

  @override
  State<_RemoteState> createState() => _RemoteStateState();
}

class _RemoteStateState extends State<_RemoteState> {
  late Future<Object?> _future;

  @override
  void initState() {
    super.initState();
    _future = _loadB2bRemote(widget.api, widget.endpoint);
  }

  @override
  void didUpdateWidget(covariant _RemoteState oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.api != widget.api || oldWidget.endpoint != widget.endpoint) {
      _future = _loadB2bRemote(widget.api, widget.endpoint);
    }
  }

  void _retry() {
    setState(() {
      _future = _loadB2bRemote(widget.api, widget.endpoint);
    });
  }

  @override
  Widget build(BuildContext context) => FutureBuilder<Object?>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done) {
            return const Center(
              key: ValueKey('b2b-loading'),
              child: CircularProgressIndicator(),
            );
          }

          if (snapshot.hasError) {
            return _B2bRemoteErrorCard(
              error: snapshot.error,
              onRetry: _retry,
            );
          }

          final value = snapshot.data;
          final empty = value == null ||
              (value is List && value.isEmpty) ||
              (value is Map &&
                  value['data'] is List &&
                  (value['data'] as List).isEmpty &&
                  value.entries.every(
                    (entry) => entry.key == 'data' || entry.value == null,
                  ));

          if (empty) {
            return widget.showEmpty
                ? Card(
                    key: const ValueKey('b2b-empty'),
                    child: Padding(
                      padding: const EdgeInsets.all(16),
                      child: Text(context.tr('b2b.remote.empty')),
                    ),
                  )
                : const SizedBox.shrink();
          }

          return _AuthoritativeDataView(
            value: value,
            routePattern: widget.routePattern,
          );
        },
      );
}

class _B2bFailureInfo {
  const _B2bFailureInfo({
    required this.category,
    required this.messageKey,
    required this.forbiddenVisual,
    this.statusCode,
    this.supportReference,
  });

  final String category;
  final String messageKey;
  final bool forbiddenVisual;
  final int? statusCode;
  final String? supportReference;
}

_B2bFailureInfo _b2bFailureInfo(Object? error) {
  if (error is B2bApiException) {
    final status = error.statusCode;
    if (status == 401) {
      return _B2bFailureInfo(
        category: 'unauthorized',
        messageKey: 'customer.error.session_expired',
        forbiddenVisual: false,
        statusCode: status,
        supportReference: _safeSupportReference(error.supportReference),
      );
    }
    if (status == 403 || (status == null && error.code == 'not_authorized')) {
      return _B2bFailureInfo(
        category: 'forbidden',
        messageKey: 'customer.error.forbidden',
        forbiddenVisual: true,
        statusCode: status,
        supportReference: _safeSupportReference(error.supportReference),
      );
    }
    if (status == 404) {
      return _B2bFailureInfo(
        category: 'not_found',
        messageKey: 'b2b.remote.not_found',
        forbiddenVisual: false,
        statusCode: status,
        supportReference: _safeSupportReference(error.supportReference),
      );
    }
    if (status != null && status >= 500) {
      return _B2bFailureInfo(
        category: 'server_failure',
        messageKey: 'b2b.remote.error',
        forbiddenVisual: false,
        statusCode: status,
        supportReference: _safeSupportReference(error.supportReference),
      );
    }
  }

  return const _B2bFailureInfo(
    category: 'network_or_client_failure',
    messageKey: 'b2b.remote.error',
    forbiddenVisual: false,
  );
}

String? _safeSupportReference(String? value) {
  final reference = value?.trim();
  if (reference == null || reference.isEmpty || reference.length > 128) {
    return null;
  }
  return RegExp(r'^[A-Za-z0-9._:/-]+$').hasMatch(reference)
      ? reference
      : null;
}

Future<Object?> _loadB2bRemote(B2bApi api, String endpoint) async {
  try {
    return await api.get(endpoint);
  } catch (error, stack) {
    final failure = _b2bFailureInfo(error);
    CustomerDiagnostics.instance.recordRuntimeFailure(
      operation: 'b2b_remote_load',
      path: endpoint,
      category: failure.category,
      statusCode: failure.statusCode,
      supportReference: failure.supportReference,
    );
    Error.throwWithStackTrace(error, stack);
  }
}

String _productsBackRoute(String endpoint) {
  final uri = Uri.parse(endpoint);
  final storeId =
      uri.queryParameters['store_id'] ?? uri.queryParameters['store'];
  if (storeId == null || storeId.isEmpty) {
    return CustomerRoutePaths.b2bProducts;
  }
  return Uri(
    path: CustomerRoutePaths.b2bProducts,
    queryParameters: {'store_id': storeId},
  ).toString();
}

class _B2bRemoteErrorCard extends StatelessWidget {
  const _B2bRemoteErrorCard({
    required this.error,
    required this.onRetry,
    this.backRoute,
  });

  final Object? error;
  final VoidCallback onRetry;
  final String? backRoute;

  @override
  Widget build(BuildContext context) {
    final failure = _b2bFailureInfo(error);
    return Card(
      key: ValueKey(failure.forbiddenVisual ? 'b2b-forbidden' : 'b2b-error'),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(context.tr(failure.messageKey)),
            if (failure.supportReference != null) ...[
              const SizedBox(height: 8),
              Text(
                '${context.tr('b2b.remote.support_reference')}: ${failure.supportReference}',
                key: const ValueKey('b2b-error-support-reference'),
                style: Theme.of(context).textTheme.labelMedium,
              ),
            ],
            const SizedBox(height: 12),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                FilledButton.tonalIcon(
                  key: const ValueKey('b2b-error-retry'),
                  onPressed: onRetry,
                  icon: const Icon(Icons.refresh),
                  label: Text(context.tr('customer.action.retry')),
                ),
                if (backRoute != null)
                  OutlinedButton.icon(
                    key: const ValueKey('b2b-error-back-products'),
                    onPressed: () =>
                        Navigator.of(context).pushReplacementNamed(backRoute!),
                    icon: const Icon(Icons.arrow_back),
                    label: Text(context.tr('b2b.remote.back_products')),
                  ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _AuthoritativeDataView extends StatelessWidget {
  const _AuthoritativeDataView({
    required this.value,
    required this.routePattern,
  });

  final Object? value;
  final String routePattern;

  @override
  Widget build(BuildContext context) {
    final rows = _rows(value);
    if (rows != null) {
      final summary = value is Map
          ? Map<Object?, Object?>.fromEntries(
              (value as Map).entries.where((entry) => entry.key != 'data'),
            )
          : <Object?, Object?>{};
      return Column(
        key: const ValueKey('b2b-authoritative-collection'),
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (summary.isNotEmpty) _mapCard(context, summary),
          ...rows.map((row) => _rowCard(context, row)),
          if (rows.isEmpty)
            Card(
              key: const ValueKey('b2b-empty'),
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Text(context.tr('b2b.remote.empty')),
              ),
            ),
        ],
      );
    }

    if (value is Map) {
      return _mapCard(
        context,
        Map<Object?, Object?>.from(value as Map),
        key: const ValueKey('b2b-authoritative-detail'),
      );
    }

    return Card(
      key: const ValueKey('b2b-authoritative-detail'),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Text(value.toString()),
      ),
    );
  }

  List<Map<Object?, Object?>>? _rows(Object? source) {
    if (source is List) {
      return source
          .whereType<Map>()
          .map((row) => Map<Object?, Object?>.from(row))
          .toList(growable: false);
    }
    if (source is Map && source['data'] is List) {
      return (source['data'] as List)
          .whereType<Map>()
          .map((row) => Map<Object?, Object?>.from(row))
          .toList(growable: false);
    }
    return null;
  }

  Widget _rowCard(BuildContext context, Map<Object?, Object?> row) {
    final route = _routeForRow(row);
    final title = _firstValue(
      row,
      const [
        'name',
        'product_name',
        'order_number',
        'invoice_number',
        'company_name',
        'sku',
        'reference',
        'id',
      ],
    );

    return Card(
      child: InkWell(
        onTap: route == null
            ? null
            : () => Navigator.of(context).pushNamed(route),
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              if (row['image_url'] != null && row['image_url'].toString().isNotEmpty)
                Padding(
                  padding: const EdgeInsets.only(bottom: 10),
                  child: SizedBox(
                    key: ValueKey('b2b-product-image-${row['id']}'),
                    height: 140,
                    child: _B2bCatalogImage(url: row['image_url'].toString()),
                  ),
                ),
              if (title != null && title.isNotEmpty)
                Text(
                  title,
                  style: Theme.of(context).textTheme.titleMedium,
                ),
              if (title != null && title.isNotEmpty)
                const SizedBox(height: 8),
              ..._entries(context, row),
              if (route != null)
                const Align(
                  alignment: AlignmentDirectional.centerEnd,
                  child: Icon(Icons.chevron_right),
                ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _mapCard(
    BuildContext context,
    Map<Object?, Object?> map, {
    Key? key,
  }) =>
      Card(
        key: key,
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: _entries(context, map),
          ),
        ),
      );

  List<Widget> _entries(
    BuildContext context,
    Map<Object?, Object?> map,
  ) {
    final widgets = <Widget>[];
    for (final entry in map.entries) {
      final key = entry.key.toString();
      final value = entry.value;
      if (value == null || key == 'image_url' || key == 'images') continue;

      if (value is Map) {
        widgets.add(
          Padding(
            padding: const EdgeInsets.only(top: 10, bottom: 4),
            child: Text(
              _label(context, key),
              style: Theme.of(context).textTheme.titleSmall,
            ),
          ),
        );
        widgets.addAll(
          _entries(context, Map<Object?, Object?>.from(value)),
        );
        continue;
      }

      if (value is List) {
        widgets.add(
          Padding(
            padding: const EdgeInsets.only(top: 10, bottom: 4),
            child: Text(
              _label(context, key),
              style: Theme.of(context).textTheme.titleSmall,
            ),
          ),
        );
        if (value.isEmpty) {
          widgets.add(Text(context.tr('b2b.remote.empty')));
        } else {
          for (final item in value) {
            if (item is Map) {
              widgets.add(
                _mapCard(
                  context,
                  Map<Object?, Object?>.from(item),
                ),
              );
            } else {
              widgets.add(Text(item.toString()));
            }
          }
        }
        continue;
      }

      widgets.add(
        Padding(
          padding: const EdgeInsets.only(bottom: 6),
          child: Text('${_label(context, key)}: ${_formatValue(value)}'),
        ),
      );
    }
    return widgets;
  }

  String _label(BuildContext context, String key) {
    final arabic = Directionality.of(context) == TextDirection.rtl;
    const en = <String, String>{
      'id': 'ID',
      'sku': 'SKU',
      'name': 'Name',
      'product_name': 'Product',
      'order_number': 'Order',
      'invoice_number': 'Invoice',
      'status': 'Status',
      'payment_status': 'Payment status',
      'currency': 'Currency',
      'total': 'Total',
      'grand_total': 'Grand total',
      'subtotal': 'Subtotal',
      'balance': 'Balance',
      'quantity': 'Quantity',
      'unit_price': 'Unit price',
      'account_price': 'Account price',
      'minimum_quantity': 'Minimum quantity',
      'minimum_order_quantity': 'Minimum order quantity',
      'available_quantity': 'Available quantity',
      'company_name': 'Company',
      'email': 'Email',
      'phone': 'Phone',
      'tax_number': 'Tax number',
      'created_at': 'Created',
      'updated_at': 'Updated',
      'period': 'Period',
      'items': 'Items',
      'data': 'Data',
    };
    const ar = <String, String>{
      'id': 'المعرّف',
      'sku': 'SKU',
      'name': 'الاسم',
      'product_name': 'المنتج',
      'order_number': 'الطلب',
      'invoice_number': 'الفاتورة',
      'status': 'الحالة',
      'payment_status': 'حالة السداد',
      'currency': 'العملة',
      'total': 'الإجمالي',
      'grand_total': 'الإجمالي النهائي',
      'subtotal': 'الإجمالي الفرعي',
      'balance': 'الرصيد',
      'quantity': 'الكمية',
      'unit_price': 'سعر الوحدة',
      'account_price': 'سعر الحساب',
      'minimum_quantity': 'الحد الأدنى للكمية',
      'minimum_order_quantity': 'الحد الأدنى للطلب',
      'available_quantity': 'الكمية المتاحة',
      'company_name': 'الشركة',
      'email': 'البريد الإلكتروني',
      'phone': 'الهاتف',
      'tax_number': 'الرقم الضريبي',
      'created_at': 'تاريخ الإنشاء',
      'updated_at': 'آخر تحديث',
      'period': 'الفترة',
      'items': 'البنود',
      'data': 'البيانات',
    };
    final labels = arabic ? ar : en;
    return labels[key] ??
        key
            .split('_')
            .map((part) => part.isEmpty
                ? part
                : '${part[0].toUpperCase()}${part.substring(1)}')
            .join(' ');
  }

  String _formatValue(Object? value) {
    if (value is bool) return value ? 'Yes' : 'No';
    return value.toString();
  }

  String? _firstValue(
    Map<Object?, Object?> row,
    List<String> keys,
  ) {
    for (final key in keys) {
      final value = row[key];
      if (value != null && value.toString().isNotEmpty) {
        return value.toString();
      }
    }
    return null;
  }

  String? _routeForRow(Map<Object?, Object?> row) {
    final id = row['id']?.toString();
    if (id == null || id.isEmpty) return null;

    switch (routePattern) {
      case CustomerRoutePaths.b2bInvoices:
        return '/b2b/invoices/$id';
      case CustomerRoutePaths.b2bOrders:
        return '/b2b/orders/$id';
      case CustomerRoutePaths.b2bProducts:
        final storeId = row['store_id']?.toString();
        if (storeId == null || storeId.isEmpty) return null;
        return '/b2b/products/$id?store_id=$storeId';
      default:
        return null;
    }
  }
}

