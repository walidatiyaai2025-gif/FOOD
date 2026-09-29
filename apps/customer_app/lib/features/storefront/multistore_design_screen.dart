import 'package:flutter/material.dart';

import '../../core/api/b2b_api.dart';
import '../../core/api/b2c_account_api.dart';
import '../../core/api/b2c_catalog_api.dart';
import '../../core/api/customer_action_api.dart';
import '../../core/api/storefront_api.dart';
import '../../core/api/wholesale_commerce_api.dart';
import '../../core/auth/customer_session.dart';
import '../../core/routing/customer_routes.dart';
import '../../shared/customer_action_widgets.dart';
import 'retail_multistore_screens.dart';
import 'professional_store_selector_screen.dart';
import 'platform_marketplace_screen.dart';
import 'wholesale_multistore_screens.dart';

bool shouldUseMultiStoreDesign(
  CustomerRouteDefinition definition,
  String location,
) {
  switch (definition.pattern) {
    case CustomerRoutePaths.marketplace:
    case CustomerRoutePaths.stores:
    case CustomerRoutePaths.storeSelector:
    case CustomerRoutePaths.retailHome:
    case CustomerRoutePaths.retailProductDetails:
    case CustomerRoutePaths.b2bHome:
    case CustomerRoutePaths.b2bCart:
    case CustomerRoutePaths.b2bCheckout:
    case CustomerRoutePaths.b2bOrders:
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
    required this.onAuthenticated,
    required this.onPlatformRegistered,
    required this.catalogApi,
    required this.accountApi,
    required this.actionApi,
    required this.enterWholesale,
    this.b2bApi,
    this.storefrontApi,
    this.wholesaleApi,
    super.key,
  });

  final CustomerRouteDefinition definition;
  final String location;
  final CustomerSession session;
  final CustomerAuthenticated onAuthenticated;
  final ValueChanged<String> onPlatformRegistered;
  final B2cCatalogApi catalogApi;
  final B2cAccountApi accountApi;
  final CustomerActionApi actionApi;
  final B2bApi? b2bApi;
  final StorefrontApi? storefrontApi;
  final WholesaleCommerceApi? wholesaleApi;
  final ValueChanged<int?> enterWholesale;

  @override
  Widget build(BuildContext context) {
    switch (definition.pattern) {
      case CustomerRoutePaths.marketplace:
        return PlatformMarketplaceScreen(
          session: session,
          onPlatformRegistered: onPlatformRegistered,
        );
      case CustomerRoutePaths.stores:
      case CustomerRoutePaths.storeSelector:
        return ProfessionalStoreSelectorScreen(
          catalogApi: catalogApi,
          accountApi: accountApi,
          storefrontApi: storefrontApi,
          session: session,
          enterWholesale: enterWholesale,
        );
      case CustomerRoutePaths.home:
      case CustomerRoutePaths.retailHome:
        return RetailStorefrontDesignScreen(
          location: location,
          catalogApi: catalogApi,
          storefrontApi: storefrontApi,
          actionApi: actionApi,
        );
      case CustomerRoutePaths.productDetails:
      case CustomerRoutePaths.retailProductDetails:
        return RetailProductDetailsDesignScreen(
          location: location,
          catalogApi: catalogApi,
          actionApi: actionApi,
        );
      case CustomerRoutePaths.b2bHome:
        return WholesaleHomeDesignScreen(
          location: location,
          api: b2bApi,
          storefrontApi: storefrontApi,
          actionApi: actionApi,
        );
      case CustomerRoutePaths.b2bProductDetails:
        return WholesaleProductDetailsDesignScreen(
          location: location,
          api: b2bApi,
          actionApi: actionApi,
        );
      case CustomerRoutePaths.b2bCart:
        return WholesaleCartDesignScreen(
          location: location,
          api: b2bApi,
          commerceApi: wholesaleApi,
        );
      case CustomerRoutePaths.b2bCheckout:
        return WholesaleCheckoutDesignScreen(
          location: location,
          storefrontApi: storefrontApi,
          commerceApi: wholesaleApi,
        );
      case CustomerRoutePaths.b2bOrders:
        return WholesaleOrdersDesignScreen(api: b2bApi);
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
