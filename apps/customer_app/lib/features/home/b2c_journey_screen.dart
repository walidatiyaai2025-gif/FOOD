import 'package:flutter/material.dart';

import '../../core/localization/app_translations.dart';
import '../../core/routing/customer_routes.dart';

class B2cJourneyScreen extends StatelessWidget {
  const B2cJourneyScreen({required this.definition, required this.location, super.key});
  final CustomerRouteDefinition definition;
  final String location;

  @override
  Widget build(BuildContext context) {
    final content = _contentFor(context, definition.pattern);
    return Scaffold(
      appBar: AppBar(title: Text(context.tr('customer.app.title'))),
      bottomNavigationBar: definition.pattern == CustomerRoutePaths.home ||
              definition.pattern == CustomerRoutePaths.products ||
              definition.pattern == CustomerRoutePaths.cart ||
              definition.pattern == CustomerRoutePaths.profile
          ? NavigationBar(
              onDestinationSelected: (index) {
                const routes = [CustomerRoutePaths.home, CustomerRoutePaths.products, CustomerRoutePaths.cart, CustomerRoutePaths.profile];
                Navigator.of(context).pushReplacementNamed(routes[index]);
              },
              destinations: [
                NavigationDestination(icon: const Icon(Icons.home_outlined), label: context.tr('customer.nav.home')),
                NavigationDestination(icon: const Icon(Icons.grid_view_outlined), label: context.tr('customer.nav.products')),
                NavigationDestination(icon: const Icon(Icons.shopping_cart_outlined), label: context.tr('customer.nav.cart')),
                NavigationDestination(icon: const Icon(Icons.person_outline), label: context.tr('customer.nav.profile')),
              ],
            )
          : null,
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.all(20),
          children: [
            Text(content.$1, key: const ValueKey('customer-route-label'), style: Theme.of(context).textTheme.headlineSmall),
            const SizedBox(height: 8),
            Text(content.$2),
            const SizedBox(height: 20),
            ...content.$3,
            Text(location, key: const ValueKey('customer-route-location'), style: Theme.of(context).textTheme.labelSmall),
          ],
        ),
      ),
    );
  }

  (String, String, List<Widget>) _contentFor(BuildContext context, String pattern) {
    switch (pattern) {
      case CustomerRoutePaths.splash:
        return (context.tr('customer.splash.title'), context.tr('customer.splash.subtitle'), [const LinearProgressIndicator()]);
      case CustomerRoutePaths.entry:
        return (context.tr('customer.entry.title'), context.tr('customer.entry.subtitle'), [_button(context, context.tr('customer.action.guest'), CustomerRoutePaths.stores), _button(context, context.tr('customer.action.login'), CustomerRoutePaths.checkoutAuth)]);
      case CustomerRoutePaths.stores:
        return (context.tr('customer.store.title'), context.tr('customer.store.subtitle'), [_empty(context.tr('customer.store.empty'))]);
      case CustomerRoutePaths.home:
        return (context.tr('customer.home.title'), context.tr('customer.home.subtitle'), [_section(context.tr('customer.home.offers')), _section(context.tr('customer.home.categories')), _section(context.tr('customer.home.popular'))]);
      case CustomerRoutePaths.offers:
        return (context.tr('customer.offers.title'), context.tr('customer.offers.subtitle'), [_empty(context.tr('customer.offers.empty'))]);
      case CustomerRoutePaths.products:
        return (context.tr('customer.products.title'), context.tr('customer.products.subtitle'), [SearchBar(hintText: context.tr('customer.products.search')), _empty(context.tr('customer.products.empty'))]);
      case CustomerRoutePaths.productDetails:
        return (context.tr('customer.product.title'), context.tr('customer.product.subtitle'), [_button(context, context.tr('customer.action.add_cart'), CustomerRoutePaths.cart)]);
      case CustomerRoutePaths.cart:
        return (context.tr('customer.cart.title'), context.tr('customer.cart.subtitle'), [_empty(context.tr('customer.cart.empty')), _button(context, context.tr('customer.action.checkout'), CustomerRoutePaths.checkoutAuth)]);
      case CustomerRoutePaths.checkoutAuth:
        return (context.tr('customer.checkout_login.title'), context.tr('customer.checkout_login.subtitle'), [_button(context, context.tr('customer.action.login'), CustomerRoutePaths.checkoutAuth)]);
      case CustomerRoutePaths.checkoutAddressPayment:
        return (context.tr('customer.checkout.title'), context.tr('customer.checkout.subtitle'), [_section(context.tr('customer.checkout.address')), _section(context.tr('customer.checkout.payment')), _button(context, context.tr('customer.action.confirm_order'), CustomerRoutePaths.orderTracking)]);
      case CustomerRoutePaths.orderTracking:
        return (context.tr('customer.tracking.title'), context.tr('customer.tracking.subtitle'), [_section(context.tr('customer.tracking.received')), _section(context.tr('customer.tracking.preparing')), _section(context.tr('customer.tracking.on_way'))]);
      case CustomerRoutePaths.profile:
        return (context.tr('customer.profile.title'), context.tr('customer.profile.subtitle'), [_section(context.tr('customer.profile.addresses')), _section(context.tr('customer.profile.favorites')), _section(context.tr('customer.profile.orders'))]);
      default:
        return (definition.label, 'FOODEX Customer', [_empty(context.tr('customer.empty'))]);
    }
  }

  Widget _button(BuildContext context, String label, String route) => Padding(
        padding: const EdgeInsets.only(bottom: 12),
        child: FilledButton(onPressed: () => Navigator.of(context).pushNamed(route), child: Text(label)),
      );

  Widget _section(String label) => Card(
        child: ListTile(title: Text(label), trailing: const Icon(Icons.chevron_right)),
      );

  Widget _empty(String label) => Card(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Center(child: Text(label)),
        ),
      );
}
