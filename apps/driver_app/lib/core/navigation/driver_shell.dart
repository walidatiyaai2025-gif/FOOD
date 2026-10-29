import 'package:flutter/material.dart';

import '../localization/driver_translations.dart';
import '../theme/foodex_theme.dart';

enum DriverShellDestination {
  home,
  deliveries,
  notifications,
  wallet,
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
    this.walletRoute,
  });

  final DriverShellDestination destination;
  final Widget body;
  final PreferredSizeWidget? appBar;
  final Widget? title;
  final String? homeRoute;
  final String? deliveriesRoute;
  final String? notificationsRoute;
  final String? walletRoute;

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
      bottomNavigationBar: DriverShellNavigation(
        destination: destination,
        homeRoute: homeRoute,
        deliveriesRoute: deliveriesRoute,
        notificationsRoute: notificationsRoute,
        walletRoute: walletRoute,
      ),
    );
  }
}

/// The one Driver footer used by Home, Deliveries, Notifications and Wallet.
/// Wallet is shown only when the B2C router supplies an authorized wallet route.
class DriverShellNavigation extends StatelessWidget {
  const DriverShellNavigation({
    super.key,
    required this.destination,
    this.homeRoute,
    this.deliveriesRoute,
    this.notificationsRoute,
    this.walletRoute,
  });

  final DriverShellDestination destination;
  final String? homeRoute;
  final String? deliveriesRoute;
  final String? notificationsRoute;
  final String? walletRoute;

  String? _routeFor(DriverShellDestination target) => switch (target) {
        DriverShellDestination.home => homeRoute,
        DriverShellDestination.deliveries => deliveriesRoute,
        DriverShellDestination.notifications => notificationsRoute,
        DriverShellDestination.wallet => walletRoute,
      };

  void _open(BuildContext context, DriverShellDestination target) {
    if (target == destination) return;
    final route = _routeFor(target);
    if (route == null || route.trim().isEmpty) return;
    Navigator.of(context).pushNamedAndRemoveUntil(route, (_) => false);
  }

  @override
  Widget build(BuildContext context) {
    return NavigationBar(
      key: const Key('driver-shell-navigation'),
      selectedIndex: destination.index,
      onDestinationSelected: (index) =>
          _open(context, DriverShellDestination.values[index]),
      backgroundColor: FoodexBrand.surface,
      indicatorColor: FoodexBrand.greenSoft,
      // Keep the Wallet title visible on compact phones as well as desktop previews.
      labelBehavior: NavigationDestinationLabelBehavior.alwaysShow,
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
        if (walletRoute != null)
          NavigationDestination(
            icon: const Icon(
              Icons.account_balance_wallet_outlined,
              key: Key('driver-shell-wallet'),
            ),
            selectedIcon: const Icon(Icons.account_balance_wallet_rounded),
            label: context.tr('driver.nav.wallet'),
          ),
      ],
    );
  }
}
