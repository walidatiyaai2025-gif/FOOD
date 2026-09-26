import '../auth/customer_session.dart';

abstract final class CustomerRoutePaths {
  static const splash = '/splash';
  static const entry = '/entry';
  static const stores = '/stores';
  static const home = '/home';
  static const offers = '/offers';
  static const products = '/products';
  static const productDetails = '/products/:id';
  static const categories = '/categories';
  static const favorites = '/favorites';
  static const orders = '/orders';
  static const notifications = '/notifications';
  static const addresses = '/profile/addresses';
  static const settings = '/profile/settings';
  static const cart = '/cart';
  static const checkoutAuth = '/auth/checkout';
  static const checkoutAddressPayment = '/checkout/address-payment';
  static const orderTracking = '/orders/:id/track';
  static const profile = '/profile';

  static const b2bLogin = '/b2b/login';
  static const b2bDashboard = '/b2b/dashboard';
  static const b2bPurchaseReports = '/b2b/reports/purchases';
  static const b2bTopProducts = '/b2b/products/top';
  static const b2bProducts = '/b2b/products';
  static const b2bProductDetails = '/b2b/products/:id';
  static const b2bInvoices = '/b2b/invoices';
  static const b2bInvoiceDetails = '/b2b/invoices/:id';
  static const b2bAccountStatement = '/b2b/account-statement';
  static const b2bOrders = '/b2b/orders';
  static const b2bOrderDetails = '/b2b/orders/:id';
  static const b2bCart = '/b2b/cart';
  static const b2bCheckout = '/b2b/checkout';
  static const b2bProfile = '/b2b/profile';
}

class CustomerRouteDefinition {
  const CustomerRouteDefinition({
    required this.pattern,
    required this.label,
    this.channel,
    this.requiresAuth = false,
  });

  final String pattern;
  final String label;
  final CustomerChannel? channel;
  final bool requiresAuth;

  bool matches(String location) {
    final path = Uri.parse(location).path;
    final actual = Uri.parse(path).pathSegments;
    final expected = Uri.parse(pattern).pathSegments;

    if (actual.length != expected.length) {
      return false;
    }

    for (var index = 0; index < expected.length; index++) {
      final expectedSegment = expected[index];
      final actualSegment = actual[index];

      if (expectedSegment.startsWith(':')) {
        if (actualSegment.isEmpty) {
          return false;
        }

        continue;
      }

      if (expectedSegment != actualSegment) {
        return false;
      }
    }

    return true;
  }
}

const customerRouteDefinitions = <CustomerRouteDefinition>[
  CustomerRouteDefinition(
    pattern: CustomerRoutePaths.splash,
    label: 'Splash',
  ),
  CustomerRouteDefinition(
    pattern: CustomerRoutePaths.entry,
    label: 'Customer entry',
  ),
  CustomerRouteDefinition(
    pattern: CustomerRoutePaths.stores,
    label: 'Store selection',
    channel: CustomerChannel.b2c,
  ),
  CustomerRouteDefinition(
    pattern: CustomerRoutePaths.home,
    label: 'B2C home',
    channel: CustomerChannel.b2c,
  ),
  CustomerRouteDefinition(
    pattern: CustomerRoutePaths.offers,
    label: 'B2C offers',
    channel: CustomerChannel.b2c,
  ),
  CustomerRouteDefinition(
    pattern: CustomerRoutePaths.products,
    label: 'B2C products',
    channel: CustomerChannel.b2c,
  ),
  CustomerRouteDefinition(
    pattern: CustomerRoutePaths.productDetails,
    label: 'B2C product details',
    channel: CustomerChannel.b2c,
  ),
  CustomerRouteDefinition(
    pattern: CustomerRoutePaths.categories,
    label: 'B2C categories',
    channel: CustomerChannel.b2c,
  ),
  CustomerRouteDefinition(
    pattern: CustomerRoutePaths.favorites,
    label: 'B2C favorites',
    channel: CustomerChannel.b2c,
    requiresAuth: true,
  ),
  CustomerRouteDefinition(
    pattern: CustomerRoutePaths.orders,
    label: 'B2C orders',
    channel: CustomerChannel.b2c,
    requiresAuth: true,
  ),
  CustomerRouteDefinition(
    pattern: CustomerRoutePaths.notifications,
    label: 'B2C notifications',
    channel: CustomerChannel.b2c,
    requiresAuth: true,
  ),
  CustomerRouteDefinition(
    pattern: CustomerRoutePaths.addresses,
    label: 'B2C addresses',
    channel: CustomerChannel.b2c,
    requiresAuth: true,
  ),
  CustomerRouteDefinition(
    pattern: CustomerRoutePaths.settings,
    label: 'B2C settings',
    channel: CustomerChannel.b2c,
    requiresAuth: true,
  ),
  CustomerRouteDefinition(
    pattern: CustomerRoutePaths.cart,
    label: 'B2C cart',
    channel: CustomerChannel.b2c,
  ),
  CustomerRouteDefinition(
    pattern: CustomerRoutePaths.checkoutAuth,
    label: 'B2C checkout login',
    channel: CustomerChannel.b2c,
  ),
  CustomerRouteDefinition(
    pattern: CustomerRoutePaths.checkoutAddressPayment,
    label: 'B2C checkout',
    channel: CustomerChannel.b2c,
    requiresAuth: true,
  ),
  CustomerRouteDefinition(
    pattern: CustomerRoutePaths.orderTracking,
    label: 'B2C order tracking',
    channel: CustomerChannel.b2c,
    requiresAuth: true,
  ),
  CustomerRouteDefinition(
    pattern: CustomerRoutePaths.profile,
    label: 'B2C profile',
    channel: CustomerChannel.b2c,
    requiresAuth: true,
  ),
  CustomerRouteDefinition(
    pattern: CustomerRoutePaths.b2bLogin,
    label: 'B2B login',
    channel: CustomerChannel.b2b,
  ),
  CustomerRouteDefinition(
    pattern: CustomerRoutePaths.b2bDashboard,
    label: 'B2B dashboard',
    channel: CustomerChannel.b2b,
    requiresAuth: true,
  ),
  CustomerRouteDefinition(
    pattern: CustomerRoutePaths.b2bPurchaseReports,
    label: 'B2B purchase reports',
    channel: CustomerChannel.b2b,
    requiresAuth: true,
  ),
  CustomerRouteDefinition(
    pattern: CustomerRoutePaths.b2bTopProducts,
    label: 'B2B top products',
    channel: CustomerChannel.b2b,
    requiresAuth: true,
  ),
  CustomerRouteDefinition(
    pattern: CustomerRoutePaths.b2bProducts,
    label: 'B2B products',
    channel: CustomerChannel.b2b,
    requiresAuth: true,
  ),
  CustomerRouteDefinition(
    pattern: CustomerRoutePaths.b2bProductDetails,
    label: 'B2B product details',
    channel: CustomerChannel.b2b,
    requiresAuth: true,
  ),
  CustomerRouteDefinition(
    pattern: CustomerRoutePaths.b2bInvoices,
    label: 'B2B invoices',
    channel: CustomerChannel.b2b,
    requiresAuth: true,
  ),
  CustomerRouteDefinition(
    pattern: CustomerRoutePaths.b2bInvoiceDetails,
    label: 'B2B invoice details',
    channel: CustomerChannel.b2b,
    requiresAuth: true,
  ),
  CustomerRouteDefinition(
    pattern: CustomerRoutePaths.b2bAccountStatement,
    label: 'B2B account statement',
    channel: CustomerChannel.b2b,
    requiresAuth: true,
  ),
  CustomerRouteDefinition(
    pattern: CustomerRoutePaths.b2bOrders,
    label: 'B2B orders',
    channel: CustomerChannel.b2b,
    requiresAuth: true,
  ),
  CustomerRouteDefinition(
    pattern: CustomerRoutePaths.b2bOrderDetails,
    label: 'B2B order details',
    channel: CustomerChannel.b2b,
    requiresAuth: true,
  ),
  CustomerRouteDefinition(
    pattern: CustomerRoutePaths.b2bCart,
    label: 'B2B cart',
    channel: CustomerChannel.b2b,
    requiresAuth: true,
  ),
  CustomerRouteDefinition(
    pattern: CustomerRoutePaths.b2bCheckout,
    label: 'B2B checkout',
    channel: CustomerChannel.b2b,
    requiresAuth: true,
  ),
  CustomerRouteDefinition(
    pattern: CustomerRoutePaths.b2bProfile,
    label: 'B2B profile',
    channel: CustomerChannel.b2b,
    requiresAuth: true,
  ),
];
