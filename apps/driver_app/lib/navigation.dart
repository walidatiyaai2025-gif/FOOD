import 'package:flutter/material.dart';

import 'core/auth/driver_session.dart';
import 'core/localization/driver_translations.dart';
import 'features/tasks/driver_journey.dart';

export 'core/auth/driver_session.dart' show DriverChannel;

abstract final class DriverRoutes {
  static const root = '/';
  static const b2cHome = '/driver/b2c/home';
  static const b2cDeliveries = '/driver/b2c/deliveries';
  static const b2bHome = '/driver/b2b/home';
  static const b2bDeliveries = '/driver/b2b/deliveries';

  static bool belongsTo(String route, DriverChannel channel) {
    final prefix =
        channel == DriverChannel.b2c ? '/driver/b2c/' : '/driver/b2b/';
    return route.startsWith(prefix);
  }
}

class DriverNavigator {
  const DriverNavigator(
    this.channel, {
    required this.repository,
    this.onSessionExpired,
    this.onLogout,
  });

  final DriverChannel channel;
  final DriverAssignmentRepository repository;
  final VoidCallback? onSessionExpired;
  final VoidCallback? onLogout;

  Route<dynamic> onGenerateRoute(RouteSettings settings) {
    final name = settings.name ?? DriverRoutes.root;

    if (name == DriverRoutes.root) return _page(_homeFor(channel), settings);

    if (!DriverRoutes.belongsTo(name, channel)) {
      return _page(const _DriverRouteDenied(), settings);
    }

    switch (name) {
      case DriverRoutes.b2cHome:
      case DriverRoutes.b2bHome:
        return _page(_homeFor(channel), settings);
      case DriverRoutes.b2cDeliveries:
      case DriverRoutes.b2bDeliveries:
        return _page(
          DriverJourneyPage(
            channel: channel,
            repository: repository,
            onSessionExpired: onSessionExpired,
          ),
          settings,
        );
      default:
        return _page(const _DriverRouteNotFound(), settings);
    }
  }

  Widget _homeFor(DriverChannel channel) {
    final route = channel == DriverChannel.b2c
        ? DriverRoutes.b2cHome
        : DriverRoutes.b2bHome;
    final deliveries = channel == DriverChannel.b2c
        ? DriverRoutes.b2cDeliveries
        : DriverRoutes.b2bDeliveries;

    return _DriverHomePage(
      routeName: route,
      deliveriesRoute: deliveries,
      onLogout: onLogout,
    );
  }

  MaterialPageRoute<void> _page(Widget child, RouteSettings settings) {
    return MaterialPageRoute<void>(settings: settings, builder: (_) => child);
  }
}

class _DriverHomePage extends StatelessWidget {
  const _DriverHomePage({
    required this.routeName,
    required this.deliveriesRoute,
    this.onLogout,
  });

  final String routeName;
  final String deliveriesRoute;
  final VoidCallback? onLogout;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(context.tr('driver.app.title')),
        actions: [
          if (onLogout != null)
            IconButton(
              key: const Key('driver-logout'),
              onPressed: onLogout,
              tooltip: context.tr('driver.logout'),
              icon: const Icon(Icons.logout),
            ),
        ],
      ),
      body: SafeArea(
        child: Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(
                context.tr('driver.home.title'),
                style: const TextStyle(fontSize: 28, fontWeight: FontWeight.bold),
              ),
              const SizedBox(height: 8),
              Text(routeName, key: const Key('driver-route')),
              const SizedBox(height: 20),
              FilledButton.icon(
                key: const Key('driver-open-deliveries'),
                onPressed: () => Navigator.of(context).pushNamed(deliveriesRoute),
                icon: const Icon(Icons.local_shipping_outlined),
                label: Text(context.tr('driver.deliveries.title')),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _DriverRouteDenied extends StatelessWidget {
  const _DriverRouteDenied();

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Center(
        child: Text(
          context.tr('driver.route.denied'),
          key: const Key('driver-route-denied'),
        ),
      ),
    );
  }
}

class _DriverRouteNotFound extends StatelessWidget {
  const _DriverRouteNotFound();

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Center(
        child: Text(
          context.tr('driver.route.not_found'),
          key: const Key('driver-route-not-found'),
        ),
      ),
    );
  }
}
