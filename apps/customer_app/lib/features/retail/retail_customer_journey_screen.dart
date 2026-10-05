import 'package:flutter/material.dart';

import '../../core/api/b2c_account_api.dart';
import '../../core/api/b2c_catalog_api.dart';
import '../../core/api/customer_action_api.dart';
import '../../core/auth/customer_session.dart';
import '../../core/location/customer_location_service.dart';
import '../../core/location/customer_map_pin_selector.dart';
import '../../core/routing/customer_commerce_context.dart';
import '../../core/routing/customer_routes.dart';
import '../../shared/customer_action_widgets.dart';
import '../customer_account/customer_account_data.dart';
import '../customer_account/customer_account_screen.dart';
import '../customer_account/customer_address_book_screen.dart';
import '../customer_account/customer_favorites_screen.dart';
import '../customer_account/customer_notification_center_screen.dart';
import '../customer_orders/customer_order_models.dart';
import '../customer_orders/customer_order_screens.dart';
import '../customer_orders/customer_orders_api.dart';
import 'auth/retail_checkout_auth_screen.dart';
import 'catalog/retail_catalog_screens.dart';
import 'commerce/retail_commerce_api.dart';
import 'commerce/retail_commerce_screens.dart';
import 'customer_ui_v3/customer_retail_shell.dart';
import 'customer_ui_v3/retail_home_v3_screen.dart';

class RetailCustomerJourneyScreen extends StatelessWidget {
  const RetailCustomerJourneyScreen({
    required this.definition,
    required this.location,
    required this.session,
    this.currentSession,
    required this.catalogApi,
    required this.accountApi,
    required this.actionApi,
    required this.commerceApi,
    required this.commerceForToken,
    required this.onAuthenticated,
    required this.onPlatformAuthenticated,
    required this.locationService,
    required this.mapPinPicker,
    this.ordersApi,
    this.favoritesApi,
    super.key,
  });

  final CustomerRouteDefinition definition;
  final String location;
  final CustomerSession session;
  final CustomerSession Function()? currentSession;
  final B2cCatalogApi catalogApi;
  final B2cAccountApi accountApi;
  final CustomerActionApi actionApi;
  final RetailCommerceApi commerceApi;
  final RetailCommerceTokenFactory commerceForToken;
  final CustomerOrdersApi? ordersApi;
  final B2cRetailFavoritesApi? favoritesApi;
  final CustomerAuthenticated onAuthenticated;
  final ValueChanged<String> onPlatformAuthenticated;
  final CustomerLocationService locationService;
  final CustomerMapPinPicker mapPinPicker;

  CustomerSession get _currentSession =>
      currentSession?.call() ?? session;

  CustomerCommerceContext? get _context {
    final parsed = CustomerCommerceContext.tryParseLocation(location);
    if (parsed == null || !parsed.isRetail) return null;
    return parsed;
  }

  @override
  Widget build(BuildContext context) {
    final commerceContext = _context;
    if (commerceContext == null) {
      return const _RetailContextMissing();
    }

    final storeId = commerceContext.storeId;
    final uri = Uri.parse(location);
    final navigation = RetailCatalogNavigation(
      openProducts: (
        context, {
        required storeId,
        query,
        categoryId,
      }) {
        Navigator.of(context).pushNamed(
          Uri(
            path: CustomerRoutePaths.products,
            queryParameters: <String, String>{
              ...commerceContext.toQueryParameters(),
              if (query != null && query.trim().isNotEmpty)
                'q': query.trim(),
              if (categoryId != null) 'category_id': '$categoryId',
            },
          ).toString(),
        );
      },
      openProduct: (
        context, {
        required storeId,
        required productId,
      }) {
        Navigator.of(context).pushNamed(
          CustomerRouteLocations.retailProduct(commerceContext, productId),
        );
      },
      openCart: (context, {required storeId}) {
        Navigator.of(context).pushNamed(
          CustomerRouteLocations.retailCart(commerceContext),
        );
      },
      openNotifications: (context, {required storeId}) {
        Navigator.of(context).pushNamed(
          CustomerRouteLocations.retailNotifications(commerceContext),
        );
      },
    );

    Future<void> addToCart({
      required int storeId,
      required int productId,
      required double quantity,
    }) async {
      await actionApi.addCartItem(
        storeId: storeId,
        productId: productId,
        quantity: quantity,
      );
    }

    Widget withShell(
      CustomerRetailDestination destination,
      Widget child,
    ) =>
        CustomerRetailShell(
          commerceContext: commerceContext,
          activeDestination: destination,
          isAuthenticated: _currentSession.isAuthenticated,
          child: child,
        );

    switch (definition.pattern) {
      case CustomerRoutePaths.home:
      case CustomerRoutePaths.retailHome:
        return withShell(
          CustomerRetailDestination.home,
          RetailHomeV3Screen(
            storeId: storeId,
            catalogApi: catalogApi,
            accountApi: accountApi,
            isAuthenticated: _currentSession.isAuthenticated,
            navigation: navigation,
            onAddToCart: addToCart,
          ),
        );

      case CustomerRoutePaths.products:
        return withShell(
          CustomerRetailDestination.products,
          RetailCatalogProductsScreen(
            storeId: storeId,
            catalogApi: catalogApi,
            navigation: navigation,
            initialQuery: uri.queryParameters['q'],
            categoryId: int.tryParse(uri.queryParameters['category_id'] ?? ''),
            onAddToCart: addToCart,
          ),
        );

      case CustomerRoutePaths.categories:
        return withShell(
          CustomerRetailDestination.products,
          RetailCatalogCategoriesScreen(
            storeId: storeId,
            catalogApi: catalogApi,
            navigation: navigation,
          ),
        );

      case CustomerRoutePaths.offers:
        return withShell(
          CustomerRetailDestination.products,
          RetailCatalogOffersScreen(
            storeId: storeId,
            catalogApi: catalogApi,
            navigation: navigation,
          ),
        );

      case CustomerRoutePaths.productDetails:
      case CustomerRoutePaths.retailProductDetails:
        final productId = int.tryParse(uri.pathSegments.last);
        if (productId == null || productId <= 0) {
          return const _RetailContextMissing();
        }
        return withShell(
          CustomerRetailDestination.products,
          RetailCatalogProductScreen(
            storeId: storeId,
            productId: productId,
            catalogApi: catalogApi,
            navigation: navigation,
            onAddToCart: addToCart,
          ),
        );

      case CustomerRoutePaths.cart:
        return withShell(
          CustomerRetailDestination.cart,
          RetailCartScreen(
            storeId: storeId,
            api: commerceApi,
            isAuthenticated: _currentSession.isAuthenticated,
            onCheckout: (_) => Navigator.of(context).pushNamed(
              CustomerRouteLocations.retailCheckout(commerceContext),
            ),
            onAuthenticate: (intent, _) async {
              await Navigator.of(context).pushNamed(
                CustomerRouteLocations.authHandoff(
                  context: commerceContext,
                  next: CustomerRouteLocations.retailCheckout(commerceContext),
                  entry: intent == RetailAuthIntent.register
                      ? CustomerAuthEntry.register
                      : CustomerAuthEntry.login,
                ),
              );
              return null;
            },
          ),
        );

      case CustomerRoutePaths.checkoutAuth:
        final next = safeCustomerContextReturnLocation(
              uri.queryParameters['next'],
              context: commerceContext,
            ) ??
            CustomerRouteLocations.retailCheckout(commerceContext);
        return RetailCheckoutAuthScreen(
          storeId: storeId,
          nextRoute: next,
          actionApi: actionApi,
          onAuthenticated: onAuthenticated,
          onPlatformAuthenticated: onPlatformAuthenticated,
          commerceForToken: commerceForToken,
          registerInitially: uri.queryParameters['entry'] == 'register',
        );

      case CustomerRoutePaths.checkoutAddressPayment:
        return withShell(
          CustomerRetailDestination.cart,
          RetailCheckoutScreen(
            storeId: storeId,
            api: commerceApi,
            onAddAddress: (_) async {
              await Navigator.of(context).pushNamed(
                CustomerRouteLocations.retailAddresses(commerceContext),
              );
            },
            onEditAddress: (_, __) async {
              await Navigator.of(context).pushNamed(
                CustomerRouteLocations.retailAddresses(commerceContext),
              );
            },
            onOrderCreated: (orderId, _) {
              Navigator.of(context).pushReplacementNamed(
                _orderTrackingLocation(orderId, commerceContext),
              );
            },
          ),
        );

      case CustomerRoutePaths.profile:
      case CustomerRoutePaths.settings:
        final retailFavorites = favoritesApi;
        if (retailFavorites == null) return const _RetailContextMissing();
        return withShell(
          CustomerRetailDestination.account,
          CustomerAccountScreen(
            api: accountApi,
            favoritesApi: retailFavorites,
            retailStoreId: storeId,
            onOpenAddresses: () => Navigator.of(context).pushNamed(
              CustomerRouteLocations.retailAddresses(commerceContext),
            ),
            onOpenFavorites: () => Navigator.of(context).pushNamed(
              CustomerRouteLocations.retailFavorites(commerceContext),
            ),
            onOpenNotifications: () => Navigator.of(context).pushNamed(
              CustomerRouteLocations.retailNotifications(commerceContext),
            ),
            onOpenOrders: () => Navigator.of(context).pushNamed(
              CustomerRouteLocations.retailOrders(commerceContext),
            ),
          ),
        );

      case CustomerRoutePaths.addresses:
        return withShell(
          CustomerRetailDestination.account,
          CustomerAddressBookScreen(
            api: accountApi,
            locationService: locationService,
            mapPinPicker: mapPinPicker,
          ),
        );

      case CustomerRoutePaths.favorites:
        final retailFavorites = favoritesApi;
        if (retailFavorites == null) return const _RetailContextMissing();
        return withShell(
          CustomerRetailDestination.account,
          CustomerFavoritesScreen(
            api: accountApi,
            favoritesApi: retailFavorites,
            retailStoreId: storeId,
            onOpenProduct: (productId) => Navigator.of(context).pushNamed(
              CustomerRouteLocations.retailProduct(
                commerceContext,
                productId,
              ),
            ),
          ),
        );

      case CustomerRoutePaths.notifications:
        return withShell(
          CustomerRetailDestination.account,
          CustomerNotificationCenterScreen(
            api: accountApi,
            onOpenOrder: (target) =>
                _openNotificationOrder(context, target, commerceContext),
          ),
        );

      case CustomerRoutePaths.orders:
        final api = ordersApi;
        if (api == null) return const _RetailContextMissing();
        return withShell(
          CustomerRetailDestination.orders,
          CustomerOrdersScreen(
            api: api,
            initialChannel: 'b2c',
            onOpenOrder: (order) {
              final orderContext = CustomerCommerceContext(
                channel: order.channel == 'b2b'
                    ? CustomerCommerceChannel.wholesale
                    : CustomerCommerceChannel.retail,
                storeId: order.storeId,
              );
              final location = order.channel == 'b2b'
                  ? Uri(
                      path: '/b2b/orders/${order.id}',
                      queryParameters: orderContext.toQueryParameters(),
                    ).toString()
                  : _orderTrackingLocation(order.id, orderContext);
              Navigator.of(context).pushNamed(location);
            },
          ),
        );

      case CustomerRoutePaths.orderTracking:
        final api = ordersApi;
        final orderId = _orderId(uri);
        if (api == null || orderId == null) {
          return const _RetailContextMissing();
        }
        return withShell(
          CustomerRetailDestination.orders,
          CustomerOrderTrackingScreen(
            api: api,
            orderId: orderId,
            orderContext: CustomerOrderContext(
              storeId: storeId,
              channel: 'b2c',
            ),
          ),
        );

      default:
        return const _RetailContextMissing();
    }
  }

  void _openNotificationOrder(
    BuildContext context,
    CustomerNotificationTarget target,
    CustomerCommerceContext currentContext,
  ) {
    if (target.channel.toLowerCase() == 'b2b') {
      final storeId = target.storeId;
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

    final storeId = target.storeId ?? currentContext.storeId;
    Navigator.of(context).pushNamed(
      _orderTrackingLocation(
        target.orderId,
        CustomerCommerceContext(
          channel: CustomerCommerceChannel.retail,
          storeId: storeId,
        ),
      ),
    );
  }

  static int? _orderId(Uri uri) {
    final parts = uri.pathSegments;
    final orders = parts.indexOf('orders');
    if (orders < 0 || parts.length <= orders + 1) return null;
    final id = int.tryParse(parts[orders + 1]);
    return id != null && id > 0 ? id : null;
  }

  static String _orderTrackingLocation(
    int orderId,
    CustomerCommerceContext context,
  ) =>
      Uri(
        path: '/orders/$orderId/track',
        queryParameters: context.toQueryParameters(),
      ).toString();
}

class _RetailContextMissing extends StatelessWidget {
  const _RetailContextMissing();

  @override
  Widget build(BuildContext context) => Scaffold(
        key: const ValueKey('retail-context-missing'),
        body: Center(
          child: FilledButton(
            key: const ValueKey('retail-context-return-marketplace'),
            onPressed: () => Navigator.of(context)
                .pushReplacementNamed(CustomerRoutePaths.marketplace),
            child: const Text('Back to marketplace'),
          ),
        ),
      );
}
