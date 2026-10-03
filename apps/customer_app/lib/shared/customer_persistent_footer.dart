import 'package:flutter/material.dart';

import '../core/localization/app_translations.dart';
import '../core/routing/customer_commerce_context.dart';
import '../core/routing/customer_routes.dart';
import '../core/theme/customer_ui_v3_tokens.dart';

enum CustomerFooterDestination {
  home,
  products,
  cart,
  orders,
  account,
}

class CustomerPersistentFooterShell extends StatelessWidget {
  const CustomerPersistentFooterShell({
    required this.commerceContext,
    required this.activeDestination,
    required this.child,
    super.key,
  });

  final CustomerCommerceContext commerceContext;
  final CustomerFooterDestination activeDestination;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    final media = MediaQuery.of(context);
    final visible = media.viewInsets.bottom < 24;
    final footerClearance = 42.0 + media.viewPadding.bottom;
    const navHeight = 84.0;
    final reservedBottom =
        visible ? footerClearance + navHeight + CustomerUiSpacing.xs : 0.0;

    return ColoredBox(
      color: CustomerUiColors.mint,
      child: Stack(
        children: [
          Positioned.fill(
            bottom: reservedBottom,
            child: child,
          ),
          if (visible)
            PositionedDirectional(
              start: CustomerUiSpacing.sm,
              end: CustomerUiSpacing.sm,
              bottom: footerClearance,
              child: CustomerPersistentFooter(
                commerceContext: commerceContext,
                activeDestination: activeDestination,
              ),
            ),
        ],
      ),
    );
  }
}

class CustomerPersistentFooterDock extends StatelessWidget {
  const CustomerPersistentFooterDock({
    required this.commerceContext,
    required this.activeDestination,
    super.key,
  });

  final CustomerCommerceContext commerceContext;
  final CustomerFooterDestination activeDestination;

  @override
  Widget build(BuildContext context) {
    final media = MediaQuery.of(context);
    if (media.viewInsets.bottom >= 24) return const SizedBox.shrink();

    return ColoredBox(
      color: CustomerUiColors.mint,
      child: Padding(
        padding: EdgeInsetsDirectional.fromSTEB(
          CustomerUiSpacing.sm,
          CustomerUiSpacing.xs,
          CustomerUiSpacing.sm,
          42 + media.viewPadding.bottom,
        ),
        child: CustomerPersistentFooter(
          commerceContext: commerceContext,
          activeDestination: activeDestination,
        ),
      ),
    );
  }
}

class CustomerPersistentFooter extends StatelessWidget {
  const CustomerPersistentFooter({
    required this.commerceContext,
    required this.activeDestination,
    super.key,
  });

  final CustomerCommerceContext commerceContext;
  final CustomerFooterDestination activeDestination;

  @override
  Widget build(BuildContext context) {
    final items = <_FooterItem>[
      _FooterItem(
        destination: CustomerFooterDestination.home,
        label: context.tr('customer.nav.home'),
        icon: Icons.home_outlined,
        activeIcon: Icons.home_rounded,
      ),
      _FooterItem(
        destination: CustomerFooterDestination.products,
        label: context.tr('customer.nav.products'),
        icon: Icons.grid_view_outlined,
        activeIcon: Icons.grid_view_rounded,
      ),
      _FooterItem(
        destination: CustomerFooterDestination.cart,
        label: context.tr('customer.nav.cart'),
        icon: Icons.shopping_bag_outlined,
        activeIcon: Icons.shopping_bag_rounded,
      ),
      _FooterItem(
        destination: CustomerFooterDestination.orders,
        label: context.tr('customer.profile.orders'),
        icon: Icons.receipt_long_outlined,
        activeIcon: Icons.receipt_long_rounded,
      ),
      _FooterItem(
        destination: CustomerFooterDestination.account,
        label: context.tr('customer.nav.profile'),
        icon: Icons.person_outline_rounded,
        activeIcon: Icons.person_rounded,
      ),
    ];

    return Material(
      key: const ValueKey('customer-persistent-footer'),
      color: CustomerUiColors.white,
      elevation: CustomerUiElevation.floating,
      shadowColor: CustomerUiColors.shadow,
      borderRadius: BorderRadius.circular(32),
      clipBehavior: Clip.antiAlias,
      child: DecoratedBox(
        decoration: BoxDecoration(
          color: CustomerUiColors.white,
          border: Border.all(color: CustomerUiColors.border),
          borderRadius: BorderRadius.circular(32),
        ),
        child: SizedBox(
          height: 82,
          child: Directionality(
            textDirection: TextDirection.rtl,
            child: Row(
              children: [
                for (final item in items)
                  Expanded(
                    child: _FooterButton(
                      key: ValueKey(
                        'customer-footer-${item.destination.name}',
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
      ),
    );
  }

  String _locationFor(CustomerFooterDestination destination) {
    if (commerceContext.isRetail) {
      switch (destination) {
        case CustomerFooterDestination.home:
          return CustomerRouteLocations.retailHome(commerceContext);
        case CustomerFooterDestination.products:
          return Uri(
            path: CustomerRoutePaths.products,
            queryParameters: commerceContext.toQueryParameters(),
          ).toString();
        case CustomerFooterDestination.cart:
          return CustomerRouteLocations.retailCart(commerceContext);
        case CustomerFooterDestination.orders:
          return CustomerRouteLocations.retailOrders(commerceContext);
        case CustomerFooterDestination.account:
          return CustomerRouteLocations.retailProfile(commerceContext);
      }
    }

    switch (destination) {
      case CustomerFooterDestination.home:
        return CustomerRoutePaths.marketplace;
      case CustomerFooterDestination.products:
        return Uri(
          path: CustomerRoutePaths.b2bProducts,
          queryParameters: commerceContext.toQueryParameters(),
        ).toString();
      case CustomerFooterDestination.cart:
        return CustomerRouteLocations.wholesaleCart(commerceContext);
      case CustomerFooterDestination.orders:
        return CustomerRouteLocations.wholesaleOrders(commerceContext);
      case CustomerFooterDestination.account:
        return CustomerRouteLocations.wholesaleProfile(commerceContext);
    }
  }
}

class _FooterItem {
  const _FooterItem({
    required this.destination,
    required this.label,
    required this.icon,
    required this.activeIcon,
  });

  final CustomerFooterDestination destination;
  final String label;
  final IconData icon;
  final IconData activeIcon;
}

class _FooterButton extends StatelessWidget {
  const _FooterButton({
    required this.item,
    required this.active,
    required this.onTap,
    super.key,
  });

  final _FooterItem item;
  final bool active;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final duration = CustomerUiMotion.resolve(
      context,
      CustomerUiMotion.standard,
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
            vertical: 5,
          ),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              AnimatedContainer(
                duration: duration,
                curve: CustomerUiMotion.emphasisCurve,
                width: active ? 52 : 46,
                height: active ? 52 : 46,
                decoration: BoxDecoration(
                  color: active
                      ? CustomerUiColors.limeSoft
                      : Colors.transparent,
                  shape: BoxShape.circle,
                  border: active
                      ? Border.all(
                          color: CustomerUiColors.lime,
                          width: CustomerUiStroke.emphasis,
                        )
                      : null,
                ),
                child: Icon(
                  active ? item.activeIcon : item.icon,
                  size: active ? 27 : 25,
                  color: active
                      ? CustomerUiColors.deepGreenStrong
                      : CustomerUiColors.muted,
                ),
              ),
              const SizedBox(height: 2),
              SizedBox(
                height: 15,
                child: FittedBox(
                  fit: BoxFit.scaleDown,
                  child: Text(
                    item.label,
                    maxLines: 1,
                    style: Theme.of(context).textTheme.labelSmall?.copyWith(
                          color: active
                              ? CustomerUiColors.deepGreenStrong
                              : CustomerUiColors.muted,
                          fontWeight:
                              active ? FontWeight.w900 : FontWeight.w700,
                          fontSize: 10.5,
                        ),
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
