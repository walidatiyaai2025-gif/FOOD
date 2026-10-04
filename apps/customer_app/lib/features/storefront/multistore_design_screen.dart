import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;

import '../../core/api/b2b_api.dart';
import '../../core/api/b2c_account_api.dart';
import '../../core/api/b2c_catalog_api.dart';
import '../../core/api/customer_action_api.dart';
import '../../core/api/storefront_api.dart';
import '../../core/api/wholesale_commerce_api.dart';
import '../../core/auth/customer_session.dart';
import '../../core/routing/customer_commerce_context.dart';
import '../../core/routing/customer_pending_action.dart';
import '../../core/routing/customer_routes.dart';
import '../../shared/customer_action_widgets.dart';
import '../../shared/customer_persistent_footer.dart';
import 'retail_multistore_screens.dart';
import 'marketplace_barcode_scanner.dart';
import 'platform_marketplace_screen.dart';
import 'wholesale_multistore_screens.dart';

bool shouldUseMultiStoreDesign(
  CustomerRouteDefinition definition,
  String location,
) {
  switch (definition.pattern) {
    case CustomerRoutePaths.marketplace:
    case CustomerRoutePaths.retailHome:
    case CustomerRoutePaths.retailProductDetails:
    case CustomerRoutePaths.b2bHome:
    case CustomerRoutePaths.b2bProducts:
    case CustomerRoutePaths.b2bCart:
    case CustomerRoutePaths.b2bCheckout:
    case CustomerRoutePaths.b2bOrders:
    case CustomerRoutePaths.b2bOrderDetails:
      return true;
    case CustomerRoutePaths.home:
    case CustomerRoutePaths.productDetails:
      return false;
    case CustomerRoutePaths.b2bProductDetails:
      return wholesaleStoreIdFromLocation(location) > 0;
    default:
      return false;
  }
}

class MultiStoreDesignScreen extends StatelessWidget {
  const MultiStoreDesignScreen({
    required this.definition,
    required this.location,
    required this.session,
    this.currentSession,
    this.currentCommerceContext,
    required this.onAuthenticated,
    required this.onPlatformRegistered,
    required this.onLocaleChanged,
    required this.catalogApi,
    required this.accountApi,
    required this.actionApi,
    this.favoritesApi,
    this.wholesaleFavoritesApi,
    required this.enterWholesale,
    this.b2bApi,
    this.storefrontApi,
    this.wholesaleApi,
    this.pendingActionStore,
    this.marketplaceClient,
    this.marketplaceBarcodeScanner,
    super.key,
  });

  final CustomerRouteDefinition definition;
  final String location;
  final CustomerSession session;
  final CustomerSession Function()? currentSession;
  final CustomerCommerceContext? Function()? currentCommerceContext;
  final CustomerAuthenticated onAuthenticated;
  final ValueChanged<String> onPlatformRegistered;
  final ValueChanged<Locale> onLocaleChanged;
  final B2cCatalogApi catalogApi;
  final B2cAccountApi accountApi;
  final CustomerActionApi actionApi;
  final B2cRetailFavoritesApi? favoritesApi;
  final B2cRetailFavoritesApi? wholesaleFavoritesApi;
  final B2bApi? b2bApi;
  final StorefrontApi? storefrontApi;
  final WholesaleCommerceApi? wholesaleApi;
  final CustomerPendingActionStore? pendingActionStore;
  final http.Client? marketplaceClient;
  final MarketplaceBarcodeScanner? marketplaceBarcodeScanner;
  final ValueChanged<int?> enterWholesale;

  @override
  Widget build(BuildContext context) {
    final commerceContext =
        CustomerCommerceContext.tryParseLocation(location);

    Widget withFooter(
      Widget child,
      CustomerFooterDestination destination,
    ) {
      if (commerceContext == null) return child;
      return CustomerPersistentFooterShell(
        commerceContext: commerceContext,
        activeDestination: destination,
        child: child,
      );
    }

    switch (definition.pattern) {
      case CustomerRoutePaths.marketplace:
        return PlatformMarketplaceScreen(
          session: session,
          sessionProvider: currentSession,
          commerceContextProvider: currentCommerceContext,
          onPlatformRegistered: onPlatformRegistered,
          onLocaleChanged: onLocaleChanged,
          client: marketplaceClient,
          barcodeScanner:
              marketplaceBarcodeScanner ?? showMarketplaceBarcodeScanner,
        );
      case CustomerRoutePaths.home:
      case CustomerRoutePaths.retailHome:
        return withFooter(
          RetailStorefrontDesignScreen(
            location: location,
            session: session,
            catalogApi: catalogApi,
            storefrontApi: storefrontApi,
            actionApi: actionApi,
          ),
          CustomerFooterDestination.home,
        );
      case CustomerRoutePaths.productDetails:
      case CustomerRoutePaths.retailProductDetails:
        return withFooter(
          RetailProductDetailsDesignScreen(
            location: location,
            session: session,
            catalogApi: catalogApi,
            actionApi: actionApi,
            favoritesApi: favoritesApi,
          ),
          CustomerFooterDestination.products,
        );
      case CustomerRoutePaths.b2bHome:
        return withFooter(
          WholesaleHomeDesignScreen(
            location: location,
            session: session,
            api: b2bApi,
            storefrontApi: storefrontApi,
            actionApi: actionApi,
            pendingActionStore: pendingActionStore,
            showBottomNavigation: false,
          ),
          CustomerFooterDestination.products,
        );
      case CustomerRoutePaths.b2bProducts:
        return withFooter(
          WholesaleCatalogDesignScreen(
            location: location,
            session: session,
            api: b2bApi,
            storefrontApi: storefrontApi,
            actionApi: actionApi,
            pendingActionStore: pendingActionStore,
          ),
          CustomerFooterDestination.products,
        );
      case CustomerRoutePaths.b2bProductDetails:
        return withFooter(
          WholesaleProductDetailsDesignScreen(
            location: location,
            session: session,
            api: b2bApi,
            storefrontApi: storefrontApi,
            actionApi: actionApi,
            favoritesApi: wholesaleFavoritesApi,
            pendingActionStore: pendingActionStore,
          ),
          CustomerFooterDestination.products,
        );
      case CustomerRoutePaths.b2bCart:
        return withFooter(
          WholesaleCartDesignScreen(
            location: location,
            api: b2bApi,
            commerceApi: wholesaleApi,
          ),
          CustomerFooterDestination.account,
        );
      case CustomerRoutePaths.b2bCheckout:
        return withFooter(
          WholesaleCheckoutDesignScreen(
            location: location,
            storefrontApi: storefrontApi,
            commerceApi: wholesaleApi,
          ),
          CustomerFooterDestination.account,
        );
      case CustomerRoutePaths.b2bOrders:
        return withFooter(
          WholesaleOrdersDesignScreen(api: b2bApi),
          CustomerFooterDestination.orders,
        );
      case CustomerRoutePaths.b2bOrderDetails:
        return withFooter(
          WholesaleOrderDetailsDesignScreen(
            location: location,
            api: b2bApi,
          ),
          CustomerFooterDestination.orders,
        );
      default:
        return const SizedBox.shrink();
    }
  }
}

int retailStoreIdFromLocation(String location) {
  final uri = Uri.parse(location);
  final parts = uri.pathSegments;
  final index = parts.indexOf('retail');
  if (index >= 0 && parts.length > index + 1) {
    return int.tryParse(parts[index + 1]) ?? 0;
  }
  return int.tryParse(uri.queryParameters['store'] ?? '') ?? 0;
}

int wholesaleStoreIdFromLocation(String location) {
  final uri = Uri.parse(location);
  return int.tryParse(
        uri.queryParameters['store_id'] ??
            uri.queryParameters['store'] ??
            '',
      ) ??
      0;
}
