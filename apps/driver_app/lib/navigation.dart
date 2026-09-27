import 'package:flutter/material.dart';

import 'core/auth/driver_session.dart';
import 'core/localization/driver_translations.dart';
import 'core/theme/foodex_theme.dart';
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
        child: ListView(
          padding: const EdgeInsets.fromLTRB(16, 14, 16, 28),
          children: [
            Container(
              padding: const EdgeInsets.all(22),
              decoration: BoxDecoration(
                gradient: const LinearGradient(
                  begin: AlignmentDirectional.topStart,
                  end: AlignmentDirectional.bottomEnd,
                  colors: [FoodexBrand.greenDark, FoodexBrand.green],
                ),
                borderRadius: BorderRadius.circular(26),
                boxShadow: const [
                  BoxShadow(
                    color: Color(0x24165D2D),
                    blurRadius: 24,
                    offset: Offset(0, 12),
                  ),
                ],
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  const Row(
                    children: [
                      CircleAvatar(
                        radius: 24,
                        backgroundColor: Colors.white24,
                        foregroundColor: Colors.white,
                        child: Icon(Icons.local_shipping_rounded),
                      ),
                      SizedBox(width: 12),
                      Expanded(
                        child: Text(
                          'FOODEX DRIVER',
                          style: TextStyle(
                            color: Colors.white,
                            fontWeight: FontWeight.w900,
                            letterSpacing: .8,
                          ),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 22),
                  Text(
                    context.tr('driver.home.title'),
                    style: const TextStyle(
                      color: Colors.white,
                      fontSize: 28,
                      fontWeight: FontWeight.w900,
                    ),
                  ),
                  const SizedBox(height: 6),
                  Text(
                    context.tr('driver.home.subtitle'),
                    style: const TextStyle(color: Color(0xFFE3F5E9)),
                  ),
                  const SizedBox(height: 18),
                  FilledButton.icon(
                    key: const Key('driver-open-deliveries'),
                    onPressed: () => Navigator.of(context).pushNamed(deliveriesRoute),
                    style: FilledButton.styleFrom(
                      backgroundColor: Colors.white,
                      foregroundColor: FoodexBrand.greenDark,
                    ),
                    icon: const Icon(Icons.route_rounded),
                    label: Text(context.tr('driver.home.open_deliveries')),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 16),
            Row(
              children: [
                Expanded(
                  child: _DriverHomeMetric(
                    icon: Icons.verified_rounded,
                    label: context.tr('driver.home.ready'),
                    value: '✓',
                    tone: FoodexBrand.greenSoft,
                    foreground: FoodexBrand.greenDark,
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: _DriverHomeMetric(
                    icon: Icons.hub_rounded,
                    label: context.tr('driver.home.channel'),
                    value: routeName.contains('/b2b/') ? 'B2B' : 'Retail',
                    tone: FoodexBrand.orangeSoft,
                    foreground: FoodexBrand.orange,
                  ),
                ),
              ],
            ),
            const SizedBox(height: 12),
            Card(
              child: ListTile(
                leading: const Icon(Icons.shield_outlined),
                title: Text(context.tr('driver.app.title')),
                subtitle: Text(routeName, key: const Key('driver-route')),
                trailing: const Icon(Icons.lock_outline_rounded),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _DriverHomeMetric extends StatelessWidget {
  const _DriverHomeMetric({
    required this.icon,
    required this.label,
    required this.value,
    required this.tone,
    required this.foreground,
  });

  final IconData icon;
  final String label;
  final String value;
  final Color tone;
  final Color foreground;

  @override
  Widget build(BuildContext context) => Container(
        minHeight: 122,
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: tone,
          borderRadius: BorderRadius.circular(20),
          border: Border.all(color: foreground.withAlpha(31)),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(icon, color: foreground),
            const Spacer(),
            Text(value, style: TextStyle(color: foreground, fontSize: 22, fontWeight: FontWeight.w900)),
            const SizedBox(height: 3),
            Text(label, maxLines: 2, overflow: TextOverflow.ellipsis),
          ],
        ),
      );
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
