import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;

import '../api/b2b_api.dart';
import '../api/b2c_catalog_api.dart';
import '../api/b2c_account_api.dart';
import '../api/customer_action_api.dart';
import '../api/storefront_api.dart';
import '../api/wholesale_commerce_api.dart';
import '../auth/customer_session.dart';
import '../location/customer_location_service.dart';
import '../location/customer_map_pin_selector.dart';
import '../../features/b2b/b2b_journey_screen.dart';
import '../../features/customer_orders/customer_orders_api.dart';
import '../../features/diagnostics/customer_diagnostics_screen.dart';
import '../../features/retail/auth/retail_checkout_auth_screen.dart';
import '../../features/retail/commerce/retail_commerce_api.dart';
import '../../features/retail/retail_customer_journey_screen.dart';
import '../../features/storefront/marketplace_barcode_scanner.dart';
import '../../features/storefront/multistore_design_screen.dart';
import '../../shared/customer_action_widgets.dart';
import 'customer_commerce_context.dart';
import 'customer_routes.dart';

class CustomerAppRouter {
  const CustomerAppRouter(
    this.session, {
    required this.actionApi,
    required this.onAuthenticated,
    required this.onSessionExpired,
    required this.onEnterWholesale,
    required this.onPlatformRegistered,
    required this.onLocaleChanged,
    this.b2bApi,
    this.storefrontApi,
    this.wholesaleApi,
    required this.retailCommerceApi,
    required this.retailCommerceForToken,
    this.customerOrdersApi,
    this.favoritesApi,
    required this.b2cCatalogApi,
    required this.b2cAccountApi,
    required this.locationService,
    required this.mapPinPicker,
    this.marketplaceClient,
    this.marketplaceBarcodeScanner,
  });

  final CustomerSession session;
  final B2bApi? b2bApi;
  final B2cCatalogApi b2cCatalogApi;
  final B2cAccountApi b2cAccountApi;
  final CustomerLocationService locationService;
  final CustomerMapPinPicker mapPinPicker;
  final http.Client? marketplaceClient;
  final MarketplaceBarcodeScanner? marketplaceBarcodeScanner;
  final CustomerActionApi actionApi;
  final StorefrontApi? storefrontApi;
  final WholesaleCommerceApi? wholesaleApi;
  final RetailCommerceApi retailCommerceApi;
  final RetailCommerceTokenFactory retailCommerceForToken;
  final CustomerOrdersApi? customerOrdersApi;
  final B2cRetailFavoritesApi? favoritesApi;
  final CustomerAuthenticated onAuthenticated;
  final VoidCallback onSessionExpired;
  final ValueChanged<int?> onEnterWholesale;
  final ValueChanged<String> onPlatformRegistered;
  final ValueChanged<Locale> onLocaleChanged;

  Route<dynamic> onGenerateRoute(RouteSettings settings) {
    var requestedLocation = settings.name ?? CustomerRoutePaths.splash;
    var requested = definitionFor(requestedLocation);

    if (requested == null) {
      return _pageRoute(
        settings: settings,
        definition: const CustomerRouteDefinition(
          pattern: '/not-found',
          label: 'Not found',
        ),
        requestedLocation: requestedLocation,
      );
    }

    if (requested.pattern == CustomerRoutePaths.splash ||
        requested.pattern == CustomerRoutePaths.entry) {
      requestedLocation = CustomerRoutePaths.marketplace;
      requested = definitionFor(requestedLocation)!;
    }

    if (_isRetailJourney(requested)) {
      final normalized = _normalizeRetailLocation(
        requested,
        requestedLocation,
      );
      if (normalized == null) {
        final selector = definitionFor(CustomerRoutePaths.storeSelector)!;
        return _pageRoute(
          settings: const RouteSettings(
            name: CustomerRoutePaths.storeSelector,
          ),
          definition: selector,
          requestedLocation: CustomerRoutePaths.storeSelector,
        );
      }
      requestedLocation = normalized;
    }

    final redirect = _redirectFor(requested);
    final redirectLocation = redirect == null
        ? requestedLocation
        : _redirectLocation(
            redirect,
            requested,
            requestedLocation,
          );

    return _pageRoute(
      settings: RouteSettings(
        name: redirectLocation,
        arguments: settings.arguments,
      ),
      definition: redirect ?? requested,
      requestedLocation: redirectLocation,
    );
  }

  CustomerRouteDefinition? definitionFor(String location) {
    for (final definition in customerRouteDefinitions) {
      if (definition.matches(location)) {
        return definition;
      }
    }

    return null;
  }

  bool _isRetailJourney(CustomerRouteDefinition definition) =>
      const <String>{
        CustomerRoutePaths.home,
        CustomerRoutePaths.retailHome,
        CustomerRoutePaths.offers,
        CustomerRoutePaths.products,
        CustomerRoutePaths.productDetails,
        CustomerRoutePaths.retailProductDetails,
        CustomerRoutePaths.categories,
        CustomerRoutePaths.favorites,
        CustomerRoutePaths.orders,
        CustomerRoutePaths.notifications,
        CustomerRoutePaths.addresses,
        CustomerRoutePaths.settings,
        CustomerRoutePaths.cart,
        CustomerRoutePaths.checkoutAuth,
        CustomerRoutePaths.checkoutAddressPayment,
        CustomerRoutePaths.orderTracking,
        CustomerRoutePaths.profile,
      }.contains(definition.pattern);

  String? _normalizeRetailLocation(
    CustomerRouteDefinition definition,
    String location,
  ) {
    final parsed = CustomerCommerceContext.tryParseLocation(location);
    if (parsed != null && parsed.isRetail) return location;

    final uri = Uri.tryParse(location);
    if (uri == null) return null;

    int? storeId;
    final parts = uri.pathSegments;
    final retailIndex = parts.indexOf('retail');
    if (retailIndex >= 0 && parts.length > retailIndex + 1) {
      storeId = int.tryParse(parts[retailIndex + 1]);
    }
    storeId ??= int.tryParse(
      uri.queryParameters['store_id'] ??
          uri.queryParameters['store'] ??
          '',
    );
    if (storeId == null || storeId <= 0) return null;

    final context = CustomerCommerceContext(
      channel: CustomerCommerceChannel.retail,
      storeId: storeId,
    );
    return uri.replace(
      queryParameters: <String, String>{
        ...uri.queryParameters,
        ...context.toQueryParameters(),
      },
    ).toString();
  }

  CustomerRouteDefinition? _redirectFor(CustomerRouteDefinition requested) {
    if (!requested.requiresAuth) {
      return null;
    }

    if (!session.isAuthenticated) {
      return definitionFor(
        requested.channel == CustomerChannel.b2b
            ? CustomerRoutePaths.b2bLogin
            : CustomerRoutePaths.checkoutAuth,
      );
    }

    if (session.channel != requested.channel && !session.platformWide) {
      final entitledRetailManager =
          requested.channel == CustomerChannel.b2b &&
          session.b2bRetailStoreId != null;
      if (!entitledRetailManager) {
        return definitionFor(
          requested.channel == CustomerChannel.b2b
              ? CustomerRoutePaths.b2bLogin
              : CustomerRoutePaths.entry,
        );
      }
    }

    return null;
  }

  String _redirectLocation(
    CustomerRouteDefinition redirect,
    CustomerRouteDefinition requested,
    String requestedLocation,
  ) {
    if (!session.isAuthenticated &&
        requested.requiresAuth &&
        (redirect.pattern == CustomerRoutePaths.checkoutAuth ||
            redirect.pattern == CustomerRoutePaths.b2bLogin)) {
      final context =
          CustomerCommerceContext.tryParseLocation(requestedLocation);
      return Uri(
        path: redirect.pattern,
        queryParameters: <String, String>{
          if (context != null) ...context.toQueryParameters(),
          'next': requestedLocation,
        },
      ).toString();
    }

    return redirect.pattern;
  }

  MaterialPageRoute<void> _pageRoute({
    required RouteSettings settings,
    required CustomerRouteDefinition definition,
    required String requestedLocation,
  }) {
    return MaterialPageRoute<void>(
      settings: settings,
      builder: (_) {
        if (definition.pattern == CustomerRoutePaths.diagnostics) {
          return const CustomerDiagnosticsScreen();
        }

        if (_isRetailJourney(definition)) {
          return RetailCustomerJourneyScreen(
            definition: definition,
            location: requestedLocation,
            session: session,
            catalogApi: b2cCatalogApi,
            accountApi: b2cAccountApi,
            actionApi: actionApi,
            commerceApi: retailCommerceApi,
            commerceForToken: retailCommerceForToken,
            ordersApi: customerOrdersApi,
            favoritesApi: favoritesApi,
            onAuthenticated: onAuthenticated,
            onPlatformAuthenticated: onPlatformRegistered,
            locationService: locationService,
            mapPinPicker: mapPinPicker,
          );
        }

        if (shouldUseMultiStoreDesign(definition, requestedLocation)) {
          return MultiStoreDesignScreen(
            definition: definition,
            location: requestedLocation,
            session: session,
            catalogApi: b2cCatalogApi,
            accountApi: b2cAccountApi,
            actionApi: actionApi,
            b2bApi: b2bApi,
            storefrontApi: storefrontApi,
            wholesaleApi: wholesaleApi,
            enterWholesale: onEnterWholesale,
            onAuthenticated: onAuthenticated,
            onPlatformRegistered: onPlatformRegistered,
            onLocaleChanged: onLocaleChanged,
            marketplaceClient: marketplaceClient,
            marketplaceBarcodeScanner: marketplaceBarcodeScanner,
          );
        }

        if (definition.channel == CustomerChannel.b2b) {
          return B2bJourneyScreen(
            definition: definition,
            location: requestedLocation,
            api: b2bApi,
            accountApi: b2cAccountApi,
            actionApi: actionApi,
            onAuthenticated: onAuthenticated,
            onPlatformAuthenticated: onPlatformRegistered,
          );
        }

        return CustomerRoutePlaceholder(
          definition: definition,
          location: requestedLocation,
        );
      },
    );
  }
}

class CustomerRoutePlaceholder extends StatelessWidget {
  const CustomerRoutePlaceholder({
    required this.definition,
    required this.location,
    super.key,
  });

  final CustomerRouteDefinition definition;
  final String location;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      key: const ValueKey('customer-route-shell'),
      appBar: AppBar(title: const Text('FOODEX Customer')),
      body: SafeArea(
        child: Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(
                definition.label,
                key: const ValueKey('customer-route-label'),
                style: const TextStyle(
                  fontSize: 24,
                  fontWeight: FontWeight.bold,
                ),
              ),
              const SizedBox(height: 8),
              Text(
                location,
                key: const ValueKey('customer-route-location'),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
