import 'customer_routes.dart';

enum CustomerScreenEvidence {
  ar,
  en,
  smallPhone,
  largePhone,
  loading,
  empty,
  error,
  offline,
  visual,
}

class CustomerScreenRegistryEntry {
  const CustomerScreenRegistryEntry({
    required this.routePattern,
    required this.ownerIssue,
    required this.surface,
    required this.evidence,
  });

  final String routePattern;
  final int ownerIssue;
  final String surface;
  final Set<CustomerScreenEvidence> evidence;
}

const _fullEvidence = <CustomerScreenEvidence>{
  CustomerScreenEvidence.ar,
  CustomerScreenEvidence.en,
  CustomerScreenEvidence.smallPhone,
  CustomerScreenEvidence.largePhone,
  CustomerScreenEvidence.loading,
  CustomerScreenEvidence.empty,
  CustomerScreenEvidence.error,
  CustomerScreenEvidence.offline,
  CustomerScreenEvidence.visual,
};

const customerVNextScreenRegistry = <CustomerScreenRegistryEntry>[
  CustomerScreenRegistryEntry(
    routePattern: CustomerRoutePaths.splash,
    ownerIssue: 1324,
    surface: 'bootstrap/splash handoff',
    evidence: _fullEvidence,
  ),
  CustomerScreenRegistryEntry(
    routePattern: CustomerRoutePaths.entry,
    ownerIssue: 1329,
    surface: 'customer auth entry',
    evidence: _fullEvidence,
  ),
  CustomerScreenRegistryEntry(
    routePattern: CustomerRoutePaths.marketplace,
    ownerIssue: 1329,
    surface: 'store marketplace',
    evidence: _fullEvidence,
  ),
  CustomerScreenRegistryEntry(
    routePattern: CustomerRoutePaths.home,
    ownerIssue: 1325,
    surface: 'shopping home redirect/authority',
    evidence: _fullEvidence,
  ),
  CustomerScreenRegistryEntry(
    routePattern: CustomerRoutePaths.retailHome,
    ownerIssue: 1325,
    surface: 'shopping home',
    evidence: _fullEvidence,
  ),
  CustomerScreenRegistryEntry(
    routePattern: CustomerRoutePaths.products,
    ownerIssue: 1326,
    surface: 'catalog/search',
    evidence: _fullEvidence,
  ),
  CustomerScreenRegistryEntry(
    routePattern: CustomerRoutePaths.categories,
    ownerIssue: 1326,
    surface: 'category browse',
    evidence: _fullEvidence,
  ),
  CustomerScreenRegistryEntry(
    routePattern: CustomerRoutePaths.productDetails,
    ownerIssue: 1326,
    surface: 'product details',
    evidence: _fullEvidence,
  ),
  CustomerScreenRegistryEntry(
    routePattern: CustomerRoutePaths.retailProductDetails,
    ownerIssue: 1326,
    surface: 'store-scoped product details',
    evidence: _fullEvidence,
  ),
  CustomerScreenRegistryEntry(
    routePattern: CustomerRoutePaths.cart,
    ownerIssue: 1327,
    surface: 'cart',
    evidence: _fullEvidence,
  ),
  CustomerScreenRegistryEntry(
    routePattern: CustomerRoutePaths.checkoutAuth,
    ownerIssue: 1327,
    surface: 'checkout auth handoff',
    evidence: _fullEvidence,
  ),
  CustomerScreenRegistryEntry(
    routePattern: CustomerRoutePaths.checkoutAddressPayment,
    ownerIssue: 1327,
    surface: 'checkout/address/payment',
    evidence: _fullEvidence,
  ),
  CustomerScreenRegistryEntry(
    routePattern: CustomerRoutePaths.orders,
    ownerIssue: 1328,
    surface: 'orders',
    evidence: _fullEvidence,
  ),
  CustomerScreenRegistryEntry(
    routePattern: CustomerRoutePaths.orderTracking,
    ownerIssue: 1328,
    surface: 'order details/tracking',
    evidence: _fullEvidence,
  ),
  CustomerScreenRegistryEntry(
    routePattern: CustomerRoutePaths.profile,
    ownerIssue: 1329,
    surface: 'account/profile',
    evidence: _fullEvidence,
  ),
  CustomerScreenRegistryEntry(
    routePattern: CustomerRoutePaths.addresses,
    ownerIssue: 1329,
    surface: 'addresses',
    evidence: _fullEvidence,
  ),
  CustomerScreenRegistryEntry(
    routePattern: CustomerRoutePaths.notifications,
    ownerIssue: 1329,
    surface: 'notifications',
    evidence: _fullEvidence,
  ),
  CustomerScreenRegistryEntry(
    routePattern: CustomerRoutePaths.offers,
    ownerIssue: 1329,
    surface: 'offers/flash offers',
    evidence: _fullEvidence,
  ),
  CustomerScreenRegistryEntry(
    routePattern: CustomerRoutePaths.favorites,
    ownerIssue: 1329,
    surface: 'favorites',
    evidence: _fullEvidence,
  ),
  CustomerScreenRegistryEntry(
    routePattern: CustomerRoutePaths.settings,
    ownerIssue: 1329,
    surface: 'customer settings',
    evidence: _fullEvidence,
  ),
  CustomerScreenRegistryEntry(
    routePattern: CustomerRoutePaths.diagnostics,
    ownerIssue: 1329,
    surface: 'diagnostics',
    evidence: _fullEvidence,
  ),
];

CustomerScreenRegistryEntry? customerVNextRegistryFor(String routePattern) {
  for (final entry in customerVNextScreenRegistry) {
    if (entry.routePattern == routePattern) return entry;
  }
  return null;
}
