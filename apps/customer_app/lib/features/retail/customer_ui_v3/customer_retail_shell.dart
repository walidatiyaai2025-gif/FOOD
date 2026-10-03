import 'package:flutter/material.dart';

import '../../../core/routing/customer_commerce_context.dart';
import '../../../shared/customer_persistent_footer.dart';

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
  Widget build(BuildContext context) => CustomerPersistentFooterShell(
        commerceContext: commerceContext,
        activeDestination: _footerDestination(activeDestination),
        keyPrefix: 'retail-shell-nav',
        child: child,
      );

  CustomerFooterDestination _footerDestination(
    CustomerRetailDestination destination,
  ) {
    switch (destination) {
      case CustomerRetailDestination.home:
        return CustomerFooterDestination.home;
      case CustomerRetailDestination.products:
        return CustomerFooterDestination.products;
      case CustomerRetailDestination.cart:
        return CustomerFooterDestination.cart;
      case CustomerRetailDestination.orders:
        return CustomerFooterDestination.orders;
      case CustomerRetailDestination.account:
        return CustomerFooterDestination.account;
    }
  }
}
