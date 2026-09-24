import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/routing/customer_routes.dart';

void main() {
  test('B2C browsing routes stay public while account and checkout stay gated', () {
    CustomerRouteDefinition route(String pattern) =>
        customerRouteDefinitions.single(
          (definition) => definition.pattern == pattern,
        );

    for (final publicRoute in <String>[
      CustomerRoutePaths.stores,
      CustomerRoutePaths.home,
      CustomerRoutePaths.offers,
      CustomerRoutePaths.products,
      CustomerRoutePaths.productDetails,
      CustomerRoutePaths.cart,
    ]) {
      expect(
        route(publicRoute).requiresAuth,
        isFalse,
        reason: '$publicRoute must remain guest-accessible',
      );
    }

    for (final protectedRoute in <String>[
      CustomerRoutePaths.checkoutAddressPayment,
      CustomerRoutePaths.orderTracking,
      CustomerRoutePaths.profile,
    ]) {
      expect(
        route(protectedRoute).requiresAuth,
        isTrue,
        reason: '$protectedRoute must remain authentication-gated',
      );
    }
  });
}
