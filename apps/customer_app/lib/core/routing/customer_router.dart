import 'package:flutter/material.dart';

import '../api/b2b_api.dart';
import '../api/b2c_catalog_api.dart';
import '../api/b2c_account_api.dart';
import '../api/customer_action_api.dart';
import '../auth/customer_session.dart';
import '../../features/b2b/b2b_journey_screen.dart';
import '../../features/home/b2c_journey_screen.dart';
import '../../shared/customer_action_widgets.dart';
import 'customer_routes.dart';

class CustomerAppRouter {
  const CustomerAppRouter(
    this.session, {
    required this.actionApi,
    required this.onAuthenticated,
    this.b2bApi,
    required this.b2cCatalogApi,
    required this.b2cAccountApi,
  });

  final CustomerSession session;
  final B2bApi? b2bApi;
  final B2cCatalogApi b2cCatalogApi;
  final B2cAccountApi b2cAccountApi;
  final CustomerActionApi actionApi;
  final CustomerAuthenticated onAuthenticated;

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

    if (session.channel != requested.channel) {
      return definitionFor(
        requested.channel == CustomerChannel.b2b
            ? CustomerRoutePaths.b2bLogin
            : CustomerRoutePaths.entry,
      );
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
      builder: (_) => definition.channel == CustomerChannel.b2b
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
            ),
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
