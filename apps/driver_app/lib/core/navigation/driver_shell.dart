import 'package:flutter/material.dart';

import '../localization/driver_translations.dart';
import '../theme/foodex_theme.dart';

enum DriverShellDestination {
  home,
  deliveries,
  notifications,
}

class DriverShellScaffold extends StatelessWidget {
  const DriverShellScaffold({
    super.key,
    required this.destination,
    required this.body,
    this.appBar,
    this.title,
    this.homeRoute,
    this.deliveriesRoute,
    this.notificationsRoute,
  });

  final DriverShellDestination destination;
  final Widget body;
  final PreferredSizeWidget? appBar;
  final Widget? title;
  final String? homeRoute;
  final String? deliveriesRoute;
  final String? notificationsRoute;

  String? _routeFor(DriverShellDestination target) => switch (target) {
        DriverShellDestination.home => homeRoute,
        DriverShellDestination.deliveries => deliveriesRoute,
        DriverShellDestination.notifications => notificationsRoute,
      };

  void _open(BuildContext context, DriverShellDestination target) {
    if (target == destination) return;
    final route = _routeFor(target);
    if (route == null || route.trim().isEmpty) return;
    Navigator.of(context).pushNamedAndRemoveUntil(route, (_) => false);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      key: const Key('driver-shell'),
      backgroundColor: Theme.of(context).scaffoldBackgroundColor,
      appBar: appBar ??
          AppBar(
            title: title ?? Text(context.tr('driver.app.title')),
          ),
      body: ColoredBox(
        color: Theme.of(context).scaffoldBackgroundColor,
        child: body,
      ),
      bottomNavigationBar: NavigationBar(
        key: const Key('driver-shell-navigation'),
        selectedIndex: destination.index,
        onDestinationSelected: (index) =>
            _open(context, DriverShellDestination.values[index]),
        backgroundColor: FoodexBrand.surface,
        indicatorColor: FoodexBrand.greenSoft,
        destinations: [
          NavigationDestination(
            icon: const Icon(
              Icons.home_outlined,
              key: Key('driver-shell-home'),
            ),
            selectedIcon: const Icon(Icons.home_rounded),
            label: context.tr('driver.nav.home'),
          ),
          NavigationDestination(
            icon: const Icon(
              Icons.route_outlined,
              key: Key('driver-shell-deliveries'),
            ),
            selectedIcon: const Icon(Icons.route_rounded),
            label: context.tr('driver.nav.deliveries'),
          ),
          NavigationDestination(
            icon: const Icon(
              Icons.notifications_none_rounded,
              key: Key('driver-shell-notifications'),
            ),
            selectedIcon: const Icon(Icons.notifications_rounded),
            label: context.tr('driver.nav.notifications'),
          ),
        ],
      ),
    );
  }
}
