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
  favorites,
  invoices,
  account,
}

class CustomerPersistentFooterShell extends StatelessWidget {
  const CustomerPersistentFooterShell({
    required this.activeDestination,
    required this.child,
    this.commerceContext,
    this.forceWholesale = false,
    this.keyPrefix = 'customer-footer',
    this.isPlatformHome = false,
    this.disableProducts = false,
    super.key,
  }) : assert(commerceContext != null || forceWholesale);

  final CustomerCommerceContext? commerceContext;
  final CustomerFooterDestination activeDestination;
  final Widget child;
  final bool forceWholesale;
  final String keyPrefix;
  final bool isPlatformHome;
  final bool disableProducts;

  @override
  Widget build(BuildContext context) {
    final visible = MediaQuery.of(context).viewInsets.bottom < 24;

    return ColoredBox(
      color: Theme.of(context).scaffoldBackgroundColor,
      child: Column(
        children: [
          Expanded(child: child),
          if (visible) ...[
            CustomerPersistentFooterDock(
              commerceContext: commerceContext,
              activeDestination: activeDestination,
              forceWholesale: forceWholesale,
              keyPrefix: keyPrefix,
              isPlatformHome: isPlatformHome,
              disableProducts: disableProducts,
            ),
          ],
        ],
      ),
    );
  }
}

class CustomerPersistentFooterDock extends StatelessWidget {
  const CustomerPersistentFooterDock({
    required this.activeDestination,
    this.commerceContext,
    this.forceWholesale = false,
    this.keyPrefix = 'customer-footer',
    this.isPlatformHome = false,
    this.disableProducts = false,
    super.key,
  }) : assert(commerceContext != null || forceWholesale);

  final CustomerCommerceContext? commerceContext;
  final CustomerFooterDestination activeDestination;
  final bool forceWholesale;
  final String keyPrefix;
  final bool isPlatformHome;
  final bool disableProducts;

  @override
  Widget build(BuildContext context) {
    final media = MediaQuery.of(context);
    if (media.viewInsets.bottom >= 24) return const SizedBox.shrink();

    return ColoredBox(
      color: Theme.of(context).cardColor,
      child: CustomerPersistentFooter(
        commerceContext: commerceContext,
        activeDestination: activeDestination,
        forceWholesale: forceWholesale,
        keyPrefix: keyPrefix,
        isPlatformHome: isPlatformHome,
        disableProducts: disableProducts,
      ),
    );
  }
}

class CustomerPersistentFooter extends StatelessWidget {
  const CustomerPersistentFooter({
    required this.activeDestination,
    this.commerceContext,
    this.forceWholesale = false,
    this.keyPrefix = 'customer-footer',
    this.isPlatformHome = false,
    this.disableProducts = false,
    super.key,
  }) : assert(commerceContext != null || forceWholesale);

  final CustomerCommerceContext? commerceContext;
  final CustomerFooterDestination activeDestination;
  final bool forceWholesale;
  final String keyPrefix;
  final bool isPlatformHome;
  final bool disableProducts;

  @override
  Widget build(BuildContext context) {
    final isWholesale = commerceContext?.isWholesale ?? forceWholesale;
    final isRetail = commerceContext?.isRetail == true;
    final items = isWholesale
        ? <_FooterItem>[
            _FooterItem(
              destination: CustomerFooterDestination.home,
              label: context.tr('customer.nav.home'),
              icon: Icons.home_outlined,
              activeIcon: Icons.home_rounded,
            ),
            _FooterItem(
              destination: CustomerFooterDestination.products,
              label: context.tr('customer.nav.shopping'),
              icon: Icons.shopping_cart_outlined,
              activeIcon: Icons.shopping_cart_rounded,
            ),
            _FooterItem(
              destination: CustomerFooterDestination.orders,
              label: context.tr('customer.profile.orders'),
              icon: Icons.receipt_long_outlined,
              activeIcon: Icons.receipt_long_rounded,
            ),
            _FooterItem(
              destination: CustomerFooterDestination.favorites,
              label: context.tr('customer.profile.favorites'),
              icon: Icons.favorite_border_rounded,
              activeIcon: Icons.favorite_rounded,
            ),
            _FooterItem(
              destination: CustomerFooterDestination.invoices,
              label: context.tr('customer.nav.invoices'),
              icon: Icons.description_outlined,
              activeIcon: Icons.description_rounded,
            ),
            _FooterItem(
              destination: CustomerFooterDestination.account,
              label: context.tr('customer.nav.more'),
              icon: Icons.more_horiz_rounded,
              activeIcon: Icons.more_horiz_rounded,
            ),
          ]
        : <_FooterItem>[
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
      color: Theme.of(context).cardColor,
      elevation: CustomerUiElevation.floating,
      shadowColor: CustomerUiColors.shadow,
      clipBehavior: Clip.hardEdge,
      child: DecoratedBox(
        decoration: BoxDecoration(
          color: Theme.of(context).cardColor,
          border: Border(
            top: BorderSide(color: CustomerUiColors.border),
          ),
        ),
        child: ConstrainedBox(
          constraints: const BoxConstraints(minHeight: 82),
          child: Directionality(
            textDirection: Directionality.of(context),
            child: Row(
              children: [
                for (final item in items)
                  Expanded(
                    child: Builder(
                      builder: (context) {
                        final disabled = disableProducts &&
                            item.destination ==
                                CustomerFooterDestination.products;
                        return _FooterButton(
                          key: ValueKey(
                            '$keyPrefix-${item.destination.name}',
                          ),
                          item: item,
                          active: item.destination == activeDestination,
                          enabled: !disabled,
                          onTap: disabled ||
                                  (isRetail &&
                                      item.destination == activeDestination &&
                                      (item.destination !=
                                              CustomerFooterDestination.home ||
                                          isPlatformHome))
                              ? null
                              : () => Navigator.of(context).pushNamed(
                                    _locationFor(item.destination),
                                  ),
                        );
                      },
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
    final scope = commerceContext;
    if (scope?.isRetail == true) {
      switch (destination) {
        case CustomerFooterDestination.home:
          return CustomerRoutePaths.b2bHome;
        case CustomerFooterDestination.products:
          return Uri(
            path: CustomerRoutePaths.products,
            queryParameters: scope!.toQueryParameters(),
          ).toString();
        case CustomerFooterDestination.cart:
          return CustomerRouteLocations.retailCart(scope!);
        case CustomerFooterDestination.orders:
          return CustomerRouteLocations.retailOrders(scope!);
        case CustomerFooterDestination.favorites:
          return CustomerRouteLocations.retailFavorites(scope!);
        case CustomerFooterDestination.invoices:
          return CustomerRouteLocations.retailProfile(scope!);
        case CustomerFooterDestination.account:
          return CustomerRouteLocations.retailProfile(scope!);
      }
    }

    if (scope?.isWholesale == true) {
      switch (destination) {
        case CustomerFooterDestination.home:
          return CustomerRouteLocations.wholesaleHome(scope!);
        case CustomerFooterDestination.products:
          return Uri(
            path: CustomerRoutePaths.b2bProducts,
            queryParameters: scope!.toQueryParameters(),
          ).toString();
        case CustomerFooterDestination.cart:
          return CustomerRouteLocations.wholesaleCart(scope!);
        case CustomerFooterDestination.orders:
          return CustomerRouteLocations.wholesaleOrders(scope!);
        case CustomerFooterDestination.favorites:
          return CustomerRouteLocations.wholesaleFavorites(scope!);
        case CustomerFooterDestination.invoices:
          return Uri(
            path: CustomerRoutePaths.b2bInvoices,
            queryParameters: scope!.toQueryParameters(),
          ).toString();
        case CustomerFooterDestination.account:
          return CustomerRouteLocations.wholesaleProfile(scope!);
      }
    }

    switch (destination) {
      case CustomerFooterDestination.home:
        return CustomerRoutePaths.b2bHome;
      case CustomerFooterDestination.products:
        return CustomerRoutePaths.b2bProducts;
      case CustomerFooterDestination.cart:
        return CustomerRoutePaths.b2bCart;
      case CustomerFooterDestination.orders:
        return CustomerRoutePaths.b2bOrders;
      case CustomerFooterDestination.favorites:
        return CustomerRoutePaths.b2bFavorites;
      case CustomerFooterDestination.invoices:
        return CustomerRoutePaths.b2bInvoices;
      case CustomerFooterDestination.account:
        return CustomerRoutePaths.b2bProfile;
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
    required this.enabled,
    required this.onTap,
    super.key,
  });

  final _FooterItem item;
  final bool active;
  final bool enabled;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final duration = CustomerUiMotion.resolve(
      context,
      CustomerUiMotion.standard,
    );

    return Semantics(
      button: true,
      enabled: enabled,
      selected: active,
      label: item.label,
      child: Opacity(
        key: ValueKey(
          enabled
              ? 'footer-enabled-${item.destination.name}'
              : 'footer-disabled-${item.destination.name}',
        ),
        opacity: enabled ? 1 : .36,
        child: InkWell(
          onTap: enabled ? onTap : null,
          child: Padding(
            padding: const EdgeInsets.symmetric(
              horizontal: CustomerUiSpacing.xxs,
              vertical: 5,
            ),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                AnimatedContainer(
                  duration: duration,
                  curve: CustomerUiMotion.emphasisCurve,
                  width: active ? 58 : 46,
                  height: 42,
                  decoration: BoxDecoration(
                    color:
                        active ? CustomerUiColors.limeSoft : Colors.transparent,
                    borderRadius: BorderRadius.circular(24),
                  ),
                  child: Icon(
                    active ? item.activeIcon : item.icon,
                    size: active ? 27 : 24,
                    color: active
                        ? CustomerUiColors.deepGreenStrong
                        : Theme.of(context).colorScheme.onSurfaceVariant,
                  ),
                ),
                const SizedBox(height: 2),
                ConstrainedBox(
                  constraints: const BoxConstraints(minHeight: 15),
                  child: Text(
                    item.label,
                    maxLines: 2,
                    softWrap: true,
                    overflow: TextOverflow.ellipsis,
                    textAlign: TextAlign.center,
                    style: Theme.of(context).textTheme.labelSmall?.copyWith(
                          color: active
                              ? CustomerUiColors.deepGreenStrong
                              : Theme.of(context).colorScheme.onSurfaceVariant,
                          fontWeight:
                              active ? FontWeight.w900 : FontWeight.w700,
                          fontSize: 10.5,
                          height: 1.05,
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
}
