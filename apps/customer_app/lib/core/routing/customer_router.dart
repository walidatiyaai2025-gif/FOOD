import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;

import '../api/b2b_api.dart';
import '../api/b2c_catalog_api.dart';
import '../api/b2c_account_api.dart';
import '../api/customer_action_api.dart';
import '../api/storefront_api.dart';
import '../api/wholesale_commerce_api.dart';
import '../auth/customer_auth_persistence.dart';
import '../auth/customer_session.dart';
import '../auth/customer_session_store.dart';
import '../location/customer_location_service.dart';
import '../location/customer_map_pin_selector.dart';
import '../../features/b2b/b2b_journey_screen.dart';
import '../../features/customer_orders/customer_orders_api.dart';
import '../../features/auth/unified_customer_auth_screen.dart';
import '../../features/diagnostics/customer_diagnostics_screen.dart';
import '../../features/retail/auth/retail_checkout_auth_screen.dart';
import '../../features/retail/commerce/retail_commerce_api.dart';
import '../../features/retail/retail_customer_journey_screen.dart';
import '../../features/storefront/marketplace_barcode_scanner.dart';
import '../../features/storefront/multistore_design_screen.dart';
import '../../shared/customer_action_widgets.dart';
import 'customer_commerce_context.dart';
import 'customer_route_authority.dart';
import 'customer_pending_action.dart';
import 'customer_routes.dart';

class CustomerAppRouter {
  const CustomerAppRouter(
    this.session, {
    required this.actionApi,
    required this.onAuthenticated,
    required this.onSessionExpired,
    required this.onEnterWholesale,
    required this.onPlatformRegistered,
    this.onUnifiedAuthenticated,
    this.onAuthenticatedRouteResume,
    this.onLogout,
    this.sessionStore,
    this.authPreferences = const CustomerAuthPreferences(),
    this.biometricAuthenticator,
    this.pendingActionStore,
    this.pendingActionExecutor,
    required this.onLocaleChanged,
    this.b2bApi,
    this.storefrontApi,
    this.wholesaleApi,
    required this.retailCommerceApi,
    required this.retailCommerceForToken,
    this.customerOrdersApi,
    this.favoritesApi,
    this.wholesaleFavoritesApi,
    required this.b2cCatalogApi,
    required this.b2cAccountApi,
    this.b2bAccountApi,
    required this.locationService,
    required this.mapPinPicker,
    this.marketplaceClient,
    this.marketplaceBarcodeScanner,
    this.currentSession,
    this.currentCommerceContext,
    this.onCommerceContextChanged,
  });

  final CustomerSession session;
  final B2bApi? b2bApi;
  final B2cCatalogApi b2cCatalogApi;
  final B2cAccountApi b2cAccountApi;
  final B2cAccountApi? b2bAccountApi;
  final CustomerLocationService locationService;
  final CustomerMapPinPicker mapPinPicker;
  final http.Client? marketplaceClient;
  final MarketplaceBarcodeScanner? marketplaceBarcodeScanner;
  final CustomerSession Function()? currentSession;
  final CustomerCommerceContext? Function()? currentCommerceContext;
  final ValueChanged<CustomerCommerceContext>? onCommerceContextChanged;
  final CustomerActionApi actionApi;
  final StorefrontApi? storefrontApi;
  final WholesaleCommerceApi? wholesaleApi;
  final RetailCommerceApi retailCommerceApi;
  final RetailCommerceTokenFactory retailCommerceForToken;
  final CustomerOrdersApi? customerOrdersApi;
  final B2cRetailFavoritesApi? favoritesApi;
  final B2cRetailFavoritesApi? wholesaleFavoritesApi;
  final CustomerAuthenticated onAuthenticated;
  final VoidCallback onSessionExpired;
  final ValueChanged<int?> onEnterWholesale;
  final ValueChanged<String> onPlatformRegistered;
  final CustomerUnifiedAuthenticated? onUnifiedAuthenticated;
  final CustomerAuthenticatedRouteResume? onAuthenticatedRouteResume;
  final Future<void> Function()? onLogout;
  final CustomerSessionStore? sessionStore;
  final CustomerAuthPreferences authPreferences;
  final CustomerBiometricAuthenticator? biometricAuthenticator;
  final CustomerPendingActionStore? pendingActionStore;
  final CustomerPendingActionExecutor? pendingActionExecutor;
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

    if (requested.pattern == CustomerRoutePaths.splash) {
      requestedLocation = CustomerRoutePaths.entry;
      requested = definitionFor(requestedLocation)!;
    }

    final wholesaleAuthHandoff =
        requested.pattern == CustomerRoutePaths.checkoutAuth &&
        safeCustomerReturnLocation(
              Uri.tryParse(requestedLocation)?.queryParameters['next'],
              channel: CustomerChannel.b2b,
            ) !=
            null;

    final requestedAuthority = customerRouteAuthorityFor(
      requested,
      requestedLocation,
    );
    final shouldNormalizeRetail =
        requested.channel == CustomerChannel.b2c &&
        (requestedAuthority == CustomerRouteAuthority.retailJourney ||
            requestedAuthority == CustomerRouteAuthority.multiStore ||
            requested.pattern == CustomerRoutePaths.checkoutAuth);

    if (shouldNormalizeRetail && !wholesaleAuthHandoff) {
      final normalized = _normalizeRetailLocation(
        requested,
        requestedLocation,
      );
      if (normalized == null) {
        final marketplace = definitionFor(CustomerRoutePaths.marketplace)!;
        return _pageRoute(
          settings: const RouteSettings(
            name: CustomerRoutePaths.marketplace,
          ),
          definition: marketplace,
          requestedLocation: CustomerRoutePaths.marketplace,
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

    final routedCommerceContext =
        CustomerCommerceContext.tryParseLocation(redirectLocation);
    if (routedCommerceContext != null) {
      onCommerceContextChanged?.call(routedCommerceContext);
    }

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
      return definitionFor(CustomerRoutePaths.checkoutAuth);
    }

    if (session.channel != requested.channel && !session.platformWide) {
      final entitledRetailManager =
          requested.channel == CustomerChannel.b2b &&
          session.b2bRetailStoreId != null;
      if (!entitledRetailManager) {
        return definitionFor(CustomerRoutePaths.marketplace);
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
        redirect.pattern == CustomerRoutePaths.checkoutAuth) {
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
        final authority = customerRouteAuthorityFor(
          definition,
          requestedLocation,
        );

        if (authority == CustomerRouteAuthority.unifiedAuth &&
            definition.pattern == CustomerRoutePaths.entry) {
          final uri = Uri.parse(requestedLocation);
          return UnifiedCustomerAuthScreen(
            nextRoute: CustomerRoutePaths.b2bDashboard,
            actionApi: actionApi,
            onAuthenticated: onUnifiedAuthenticated ??
                (token, preferences) async => onPlatformRegistered(token),
            sessionStore: sessionStore,
            preferences: authPreferences,
            biometricAuthenticator: biometricAuthenticator,
            resumeAuthenticatedRoute: onAuthenticatedRouteResume,
            onLogout: onLogout,
            onLocaleChanged: onLocaleChanged,
            registerInitially: uri.queryParameters['entry'] == 'register',
          );
        }

        if (authority == CustomerRouteAuthority.diagnostics) {
          return const CustomerDiagnosticsScreen();
        }

        if (authority == CustomerRouteAuthority.unifiedAuth &&
            definition.pattern == CustomerRoutePaths.checkoutAuth) {
          final uri = Uri.parse(requestedLocation);
          final rawNext = uri.queryParameters['next'];
          var commerceContext =
              CustomerCommerceContext.tryParseLocation(requestedLocation);

          String nextLocation;
          if (commerceContext != null) {
            nextLocation = safeCustomerContextReturnLocation(
                  rawNext,
                  context: commerceContext,
                ) ??
                (commerceContext.isRetail
                    ? CustomerRouteLocations.retailCheckout(commerceContext)
                    : CustomerRouteLocations.wholesaleHome(commerceContext));
          } else {
            final wholesaleNext = safeCustomerReturnLocation(
              rawNext,
              channel: CustomerChannel.b2b,
            );
            if (wholesaleNext != null) {
              nextLocation = wholesaleNext;
              commerceContext =
                  CustomerCommerceContext.tryParseLocation(wholesaleNext);
            } else {
              nextLocation = CustomerRoutePaths.marketplace;
            }
          }

          return UnifiedCustomerAuthScreen(
            nextRoute: nextLocation,
            actionApi: actionApi,
            onAuthenticated: onUnifiedAuthenticated ??
                (token, preferences) async => onPlatformRegistered(token),
            commerceContext: commerceContext,
            registrationStoreId:
                commerceContext != null && commerceContext.isRetail
                    ? commerceContext.storeId
                    : null,
            commerceForToken:
                commerceContext != null && commerceContext.isRetail
                    ? retailCommerceForToken
                    : null,
            pendingActionStore: pendingActionStore,
            pendingActionExecutor: pendingActionExecutor,
            sessionStore: sessionStore,
            preferences: authPreferences,
            biometricAuthenticator: biometricAuthenticator,
            resumeAuthenticatedRoute: onAuthenticatedRouteResume,
            onLogout: onLogout,
            onLocaleChanged: onLocaleChanged,
            registerInitially: uri.queryParameters['entry'] == 'register',
          );
        }

        if (authority == CustomerRouteAuthority.retailJourney) {
          return RetailCustomerJourneyScreen(
            definition: definition,
            location: requestedLocation,
            session: session,
            currentSession: currentSession,
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

        if (authority == CustomerRouteAuthority.multiStore) {
          return MultiStoreDesignScreen(
            definition: definition,
            location: requestedLocation,
            session: session,
            currentSession: currentSession,
            currentCommerceContext: currentCommerceContext,
            catalogApi: b2cCatalogApi,
            accountApi: b2cAccountApi,
            actionApi: actionApi,
            favoritesApi: favoritesApi,
            wholesaleFavoritesApi: wholesaleFavoritesApi,
            b2bApi: b2bApi,
            storefrontApi: storefrontApi,
            wholesaleApi: wholesaleApi,
            pendingActionStore: pendingActionStore,
            enterWholesale: onEnterWholesale,
            onAuthenticated: onAuthenticated,
            onPlatformRegistered: onPlatformRegistered,
            onLocaleChanged: onLocaleChanged,
            marketplaceClient: marketplaceClient,
            marketplaceBarcodeScanner: marketplaceBarcodeScanner,
          );
        }

        if (authority == CustomerRouteAuthority.b2bJourney) {
          return B2bJourneyScreen(
            definition: definition,
            location: requestedLocation,
            api: b2bApi,
            accountApi: b2bAccountApi,
            storefrontApi: storefrontApi,
            actionApi: actionApi,
            onLocaleChanged: onLocaleChanged,
            onLogout: onLogout,
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
