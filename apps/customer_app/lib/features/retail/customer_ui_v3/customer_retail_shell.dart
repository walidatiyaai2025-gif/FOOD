import 'package:flutter/material.dart';

import '../../../core/localization/app_translations.dart';
import '../../../core/routing/customer_commerce_context.dart';
import '../../../core/routing/customer_routes.dart';
import '../../../core/theme/customer_ui_v3_tokens.dart';

enum CustomerRetailDestination {
  home,
  products,
  cart,
  orders,
  account,
}

class CustomerRetailShell extends StatelessWidget {
  const CustomerRetailShell({
    required this.commerceContext,
    required this.activeDestination,
    required this.isAuthenticated,
    required this.child,
    super.key,
  });

  final CustomerCommerceContext commerceContext;
  final CustomerRetailDestination activeDestination;
  final bool isAuthenticated;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    final media = MediaQuery.of(context);
    final navVisible = media.viewInsets.bottom < 24;
    final footerClearance = 42.0 + media.viewPadding.bottom;
    final navHeight = 72.0;
    final reservedBottom =
        navVisible ? footerClearance + navHeight + CustomerUiSpacing.sm : 0.0;

    return ColoredBox(
      color: CustomerUiColors.mint,
      child: Stack(
        children: [
          Positioned.fill(
            bottom: reservedBottom,
            child: child,
          ),
          if (navVisible)
            PositionedDirectional(
              start: CustomerUiSpacing.md,
              end: CustomerUiSpacing.md,
              bottom: footerClearance,
              child: _FloatingCustomerNavigation(
                commerceContext: commerceContext,
                activeDestination: activeDestination,
                isAuthenticated: isAuthenticated,
              ),
            ),
        ],
      ),
    );
  }
}

class _FloatingCustomerNavigation extends StatelessWidget {
  const _FloatingCustomerNavigation({
    required this.commerceContext,
    required this.activeDestination,
    required this.isAuthenticated,
  });

  final CustomerCommerceContext commerceContext;
  final CustomerRetailDestination activeDestination;
  final bool isAuthenticated;

  @override
  Widget build(BuildContext context) {
    final items = <_RetailNavItem>[
      _RetailNavItem(
        destination: CustomerRetailDestination.home,
        label: context.tr('customer.nav.home'),
        icon: Icons.home_outlined,
        activeIcon: Icons.home_rounded,
      ),
      _RetailNavItem(
        destination: CustomerRetailDestination.products,
        label: context.tr('customer.nav.products'),
        icon: Icons.grid_view_outlined,
        activeIcon: Icons.grid_view_rounded,
      ),
      _RetailNavItem(
        destination: CustomerRetailDestination.cart,
        label: context.tr('customer.nav.cart'),
        icon: Icons.shopping_bag_outlined,
        activeIcon: Icons.shopping_bag_rounded,
      ),
      if (isAuthenticated)
        _RetailNavItem(
          destination: CustomerRetailDestination.orders,
          label: context.tr('customer.profile.orders'),
          icon: Icons.receipt_long_outlined,
          activeIcon: Icons.receipt_long_rounded,
        ),
      if (isAuthenticated)
        _RetailNavItem(
          destination: CustomerRetailDestination.account,
          label: context.tr('customer.nav.profile'),
          icon: Icons.person_outline_rounded,
          activeIcon: Icons.person_rounded,
        ),
    ];

    return Material(
      color: CustomerUiColors.white,
      elevation: CustomerUiElevation.floating,
      shadowColor: CustomerUiColors.shadow,
      borderRadius: BorderRadius.circular(CustomerUiRadii.xl),
      clipBehavior: Clip.antiAlias,
      child: DecoratedBox(
        decoration: BoxDecoration(
          border: Border.all(color: CustomerUiColors.border),
          borderRadius: BorderRadius.circular(CustomerUiRadii.xl),
        ),
        child: SizedBox(
          height: 72,
          child: Row(
            children: [
              for (final item in items)
                Expanded(
                  child: _RetailNavButton(
                    key: ValueKey(
                      'retail-shell-nav-${item.destination.name}',
                    ),
                    item: item,
                    active: item.destination == activeDestination,
                    onTap: item.destination == activeDestination
                        ? null
                        : () => Navigator.of(context).pushNamed(
                              _locationFor(item.destination),
                            ),
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }

  String _locationFor(CustomerRetailDestination destination) {
    switch (destination) {
      case CustomerRetailDestination.home:
        return CustomerRouteLocations.retailHome(commerceContext);
      case CustomerRetailDestination.products:
        return Uri(
          path: CustomerRoutePaths.products,
          queryParameters: commerceContext.toQueryParameters(),
        ).toString();
      case CustomerRetailDestination.cart:
        return CustomerRouteLocations.retailCart(commerceContext);
      case CustomerRetailDestination.orders:
        return CustomerRouteLocations.retailOrders(commerceContext);
      case CustomerRetailDestination.account:
        return CustomerRouteLocations.retailProfile(commerceContext);
    }
  }
}

class _RetailNavItem {
  const _RetailNavItem({
    required this.destination,
    required this.label,
    required this.icon,
    required this.activeIcon,
  });

  final CustomerRetailDestination destination;
  final String label;
  final IconData icon;
  final IconData activeIcon;
}

class _RetailNavButton extends StatelessWidget {
  const _RetailNavButton({
    required this.item,
    required this.active,
    required this.onTap,
    super.key,
  });

  final _RetailNavItem item;
  final bool active;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final duration = CustomerUiMotion.resolve(
      context,
      CustomerUiMotion.standard,
    );
    final labelStyle = Theme.of(context).textTheme.labelSmall?.copyWith(
          color: active
              ? CustomerUiColors.deepGreenStrong
              : CustomerUiColors.muted,
          fontWeight: active ? FontWeight.w800 : FontWeight.w700,
          fontSize: 10.5,
        );

    return Semantics(
      button: true,
      selected: active,
      label: item.label,
      child: InkWell(
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.symmetric(
            horizontal: CustomerUiSpacing.xxs,
            vertical: CustomerUiSpacing.xs,
          ),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              AnimatedContainer(
                duration: duration,
                curve: CustomerUiMotion.emphasisCurve,
                width: active ? 48 : 40,
                height: active ? 42 : 36,
                decoration: BoxDecoration(
                  color: active
                      ? CustomerUiColors.limeSoft
                      : Colors.transparent,
                  borderRadius: BorderRadius.circular(CustomerUiRadii.pill),
                  border: active
                      ? Border.all(
                          color: CustomerUiColors.lime,
                          width: CustomerUiStroke.emphasis,
                        )
                      : null,
                ),
                child: Icon(
                  active ? item.activeIcon : item.icon,
                  size: active ? 24 : 22,
                  color: active
                      ? CustomerUiColors.deepGreenStrong
                      : CustomerUiColors.muted,
                ),
              ),
              const SizedBox(height: 2),
              SizedBox(
                height: 13,
                child: FittedBox(
                  fit: BoxFit.scaleDown,
                  child: Text(
                    item.label,
                    maxLines: 1,
                    style: labelStyle,
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
