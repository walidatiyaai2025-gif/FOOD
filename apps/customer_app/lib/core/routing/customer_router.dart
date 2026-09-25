import 'package:flutter/material.dart';

import '../auth/customer_session.dart';
import 'customer_routes.dart';
import '../../features/home/b2c_journey_screen.dart';
import '../../features/b2b/b2b_journey_screen.dart';

class CustomerAppRouter {
  const CustomerAppRouter(this.session);

  final CustomerSession session;

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
          ? B2bJourneyScreen(definition: definition, location: requestedLocation)
          : B2cJourneyScreen(definition: definition, location: requestedLocation),
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
