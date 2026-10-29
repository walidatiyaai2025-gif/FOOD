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
/// Wallet is shown for either driver channel when its authorized route exists.
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
    return SafeArea(
      top: false,
      minimum: const EdgeInsets.fromLTRB(10, 5, 10, 7),
      child: Material(
        color: FoodexBrand.surface,
        elevation: 9,
        shadowColor: const Color(0x260F5132),
        borderRadius: BorderRadius.circular(24),
        clipBehavior: Clip.antiAlias,
        child: NavigationBarTheme(
          data: NavigationBarThemeData(
            iconTheme: WidgetStateProperty.resolveWith<IconThemeData>(
              (states) => IconThemeData(
                color: states.contains(WidgetState.selected)
                    ? FoodexBrand.greenDark
                    : FoodexBrand.ink,
                size: 24,
              ),
            ),
            labelTextStyle: WidgetStateProperty.resolveWith<TextStyle>(
              (states) => TextStyle(
                color: states.contains(WidgetState.selected)
                    ? FoodexBrand.greenDark
                    : FoodexBrand.ink,
                fontSize: 12,
                fontWeight: states.contains(WidgetState.selected)
                    ? FontWeight.w900
                    : FontWeight.w700,
              ),
            ),
          ),
          child: NavigationBar(
            key: const Key('driver-shell-navigation'),
            height: 82,
            selectedIndex: destination.index,
            onDestinationSelected: (index) =>
                _open(context, DriverShellDestination.values[index]),
            backgroundColor: FoodexBrand.surface,
            surfaceTintColor: Colors.transparent,
            indicatorColor: FoodexBrand.greenSoft,
            indicatorShape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(18),
            ),
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
                  selectedIcon:
                      const Icon(Icons.account_balance_wallet_rounded),
                  label: context.tr('driver.nav.wallet'),
                ),
            ],
          ),
        ),
      ),
    );
  }
}
