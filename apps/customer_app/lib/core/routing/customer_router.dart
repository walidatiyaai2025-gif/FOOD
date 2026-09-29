import 'package:flutter/material.dart';

import '../api/b2b_api.dart';
import '../api/b2c_catalog_api.dart';
import '../api/b2c_account_api.dart';
import '../api/customer_action_api.dart';
import '../api/storefront_api.dart';
import '../api/wholesale_commerce_api.dart';
import '../auth/customer_session.dart';
import '../../features/b2b/b2b_journey_screen.dart';
import '../../features/home/b2c_journey_screen.dart';
import '../../features/storefront/multistore_design_screen.dart';
import '../../shared/customer_action_widgets.dart';
import 'customer_routes.dart';

class CustomerAppRouter {
  const CustomerAppRouter(
    this.session, {
    required this.actionApi,
    required this.onAuthenticated,
    required this.onPlatformRegistered,
    required this.onSessionExpired,
    required this.onEnterWholesale,
    this.b2bApi,
    this.storefrontApi,
    this.wholesaleApi,
    required this.b2cCatalogApi,
    required this.b2cAccountApi,
  });

  final CustomerSession session;
  final B2bApi? b2bApi;
  final B2cCatalogApi b2cCatalogApi;
  final B2cAccountApi b2cAccountApi;
  final CustomerActionApi actionApi;
  final StorefrontApi? storefrontApi;
  final WholesaleCommerceApi? wholesaleApi;
  final CustomerAuthenticated onAuthenticated;
  final PlatformCustomerRegistered onPlatformRegistered;
  final VoidCallback onSessionExpired;
  final ValueChanged<int?> onEnterWholesale;

  Route<dynamic> onGenerateRoute(RouteSettings settings) {
    final requestedLocation = settings.name ?? CustomerRoutePaths.splash;
    final requested = definitionFor(requestedLocation);

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

    final redirect = _redirectFor(requested);

    return _pageRoute(
      settings: RouteSettings(
        name: redirect?.pattern ?? requestedLocation,
        arguments: settings.arguments,
      ),
      definition: redirect ?? requested,
      requestedLocation: redirect?.pattern ?? requestedLocation,
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

    if (session.isPlatformCustomer) {
      return null;
    }

    if (session.channel != requested.channel) {
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

  MaterialPageRoute<void> _pageRoute({
    required RouteSettings settings,
    required CustomerRouteDefinition definition,
    required String requestedLocation,
  }) {
    return MaterialPageRoute<void>(
      settings: settings,
      builder: (_) {
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
          );
        }

        return definition.channel == CustomerChannel.b2b
            ? B2bJourneyScreen(
                definition: definition,
                location: requestedLocation,
                api: b2bApi,
                actionApi: actionApi,
                onAuthenticated: onAuthenticated,
              )
            : B2cJourneyScreen(
                definition: definition,
                location: requestedLocation,
                actionApi: actionApi,
                catalogApi: b2cCatalogApi,
                accountApi: b2cAccountApi,
                onAuthenticated: onAuthenticated,
                onPlatformRegistered: onPlatformRegistered,
                onSessionExpired: onSessionExpired,
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
