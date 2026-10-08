import 'customer_commerce_context.dart';
import 'customer_routes.dart';

/// Single production renderer authority for every Customer route.
///
/// The resolver exists so route selection is not an accidental consequence of
/// router if-statement order. A route can have only one renderer for a given
/// commerce scope.
enum CustomerRouteAuthority {
  unifiedAuth,
  diagnostics,
  retailJourney,
  multiStore,
  b2bJourney,
  placeholder,
}

CustomerRouteAuthority customerRouteAuthorityFor(
  CustomerRouteDefinition definition,
  String location,
) {
  switch (definition.pattern) {
    case CustomerRoutePaths.splash:
    case CustomerRoutePaths.entry:
    case CustomerRoutePaths.checkoutAuth:
      return CustomerRouteAuthority.unifiedAuth;

    case CustomerRoutePaths.diagnostics:
      return CustomerRouteAuthority.diagnostics;

    // Marketplace and wholesale commerce routes are owned by the multi-store
    // design. Retail store-scoped routes intentionally stay on the shared
    // RetailCustomerJourneyScreen used by production preview/runtime.
    case CustomerRoutePaths.marketplace:
    case CustomerRoutePaths.b2bHome:
    case CustomerRoutePaths.b2bProducts:
    case CustomerRoutePaths.b2bCart:
    case CustomerRoutePaths.b2bCheckout:
    case CustomerRoutePaths.b2bOrders:
    case CustomerRoutePaths.b2bOrderDetails:
      return CustomerRouteAuthority.multiStore;

    // Product detail without wholesale store context remains a compatibility
    // route. Once scoped, the multi-store implementation is authoritative.
    case CustomerRoutePaths.b2bProductDetails:
      final context = CustomerCommerceContext.tryParseLocation(location);
      return context?.isWholesale == true
          ? CustomerRouteAuthority.multiStore
          : CustomerRouteAuthority.b2bJourney;

    case CustomerRoutePaths.home:
    case CustomerRoutePaths.retailHome:
    case CustomerRoutePaths.retailProductDetails:
    case CustomerRoutePaths.offers:
    case CustomerRoutePaths.products:
    case CustomerRoutePaths.productDetails:
    case CustomerRoutePaths.categories:
    case CustomerRoutePaths.favorites:
    case CustomerRoutePaths.orders:
    case CustomerRoutePaths.notifications:
    case CustomerRoutePaths.addresses:
    case CustomerRoutePaths.settings:
    case CustomerRoutePaths.cart:
    case CustomerRoutePaths.checkoutAddressPayment:
    case CustomerRoutePaths.orderTracking:
    case CustomerRoutePaths.profile:
      return CustomerRouteAuthority.retailJourney;

    case CustomerRoutePaths.b2bDashboard:
    case CustomerRoutePaths.b2bPurchaseReports:
    case CustomerRoutePaths.b2bTopProducts:
    case CustomerRoutePaths.b2bInvoices:
    case CustomerRoutePaths.b2bInvoiceDetails:
    case CustomerRoutePaths.b2bAccountStatement:
    case CustomerRoutePaths.b2bNotifications:
    case CustomerRoutePaths.b2bAddresses:
    case CustomerRoutePaths.b2bProfile:
      return CustomerRouteAuthority.b2bJourney;

    default:
      return CustomerRouteAuthority.placeholder;
  }
}
