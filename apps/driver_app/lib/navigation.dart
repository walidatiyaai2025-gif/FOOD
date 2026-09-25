import 'package:flutter/material.dart';

import 'core/localization/driver_translations.dart';

enum DriverChannel { b2c, b2b }

abstract final class DriverRoutes {
  static const root = '/';
  static const b2cHome = '/driver/b2c/home';
  static const b2cDeliveries = '/driver/b2c/deliveries';
  static const b2bHome = '/driver/b2b/home';
  static const b2bDeliveries = '/driver/b2b/deliveries';

  static bool belongsTo(String route, DriverChannel channel) {
    final prefix = channel == DriverChannel.b2c ? '/driver/b2c/' : '/driver/b2b/';
    return route.startsWith(prefix);
  }
}

class DriverNavigator {
  const DriverNavigator(this.channel);

  final DriverChannel channel;

  Route<dynamic> onGenerateRoute(RouteSettings settings) {
    final name = settings.name ?? DriverRoutes.root;

    if (name == DriverRoutes.root) {
      return _page(_homeFor(channel), settings);
    }

    if (!DriverRoutes.belongsTo(name, channel)) {
      return _page(const _DriverRouteDenied(), settings);
    }

    switch (name) {
      case DriverRoutes.b2cHome:
      case DriverRoutes.b2bHome:
        return _page(_DriverRoutePage(routeName: name, titleKey: 'driver.home.title'), settings);
      case DriverRoutes.b2cDeliveries:
      case DriverRoutes.b2bDeliveries:
        return _page(_DriverRoutePage(routeName: name, titleKey: 'driver.deliveries.title'), settings);
      default:
        return _page(const _DriverRouteNotFound(), settings);
    }
  }

  Widget _homeFor(DriverChannel channel) {
    final route = channel == DriverChannel.b2c
        ? DriverRoutes.b2cHome
        : DriverRoutes.b2bHome;

    return _DriverRoutePage(routeName: route, titleKey: 'driver.app.title');
  }

  MaterialPageRoute<void> _page(Widget child, RouteSettings settings) {
    return MaterialPageRoute<void>(settings: settings, builder: (_) => child);
  }
}

class _DriverRoutePage extends StatelessWidget {
  const _DriverRoutePage({required this.routeName, required this.titleKey});

  final String routeName;
  final String titleKey;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        child: Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(context.tr(titleKey), style: const TextStyle(fontSize: 28, fontWeight: FontWeight.bold)),
              const SizedBox(height: 8),
              Text(routeName, key: const Key('driver-route')),
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
        child: Text(context.tr('driver.route.denied'), key: const Key('driver-route-denied')),
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
        child: Text(context.tr('driver.route.not_found'), key: const Key('driver-route-not-found')),
      ),
    );
  }
}
