import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/auth/customer_session.dart';
import 'package:foodex_customer_app/core/routing/customer_routes.dart';
import 'package:foodex_customer_app/features/storefront/multistore_design_screen.dart';

void main() {
  CustomerRouteDefinition definition(String pattern) =>
      customerRouteDefinitions.firstWhere((item) => item.pattern == pattern);

  test('legacy B2B dashboard stays separate from wholesale storefront design', () {
    expect(
      shouldUseMultiStoreDesign(
        definition(CustomerRoutePaths.b2bDashboard),
        CustomerRoutePaths.b2bDashboard,
      ),
      isFalse,
    );

    expect(
      shouldUseMultiStoreDesign(
        definition(CustomerRoutePaths.b2bHome),
        '${CustomerRoutePaths.b2bHome}?store_id=70',
      ),
      isTrue,
    );
  });

  test('wholesale product details require explicit store context for new design', () {
    final productDetails = definition(CustomerRoutePaths.b2bProductDetails);

    expect(
      shouldUseMultiStoreDesign(productDetails, '/b2b/products/42'),
      isFalse,
    );
    expect(
      shouldUseMultiStoreDesign(
        productDetails,
        '/b2b/products/42?store_id=70',
      ),
      isTrue,
    );
  });

  test('wholesale cart checkout and orders stay on multi-store surfaces', () {
    for (final pattern in <String>[
      CustomerRoutePaths.b2bCart,
      CustomerRoutePaths.b2bCheckout,
      CustomerRoutePaths.b2bOrders,
      CustomerRoutePaths.b2bOrderDetails,
    ]) {
      expect(
        shouldUseMultiStoreDesign(definition(pattern), pattern),
        isTrue,
        reason: pattern,
      );
    }
  });

  test('address books are separate authenticated B2B and B2C routes', () {
    final retail = definition(CustomerRoutePaths.addresses);
    final wholesale = definition(CustomerRoutePaths.b2bAddresses);

    expect(retail.channel, CustomerChannel.b2c);
    expect(retail.requiresAuth, isTrue);
    expect(wholesale.channel, CustomerChannel.b2b);
    expect(wholesale.requiresAuth, isTrue);
    expect(CustomerRoutePaths.addresses, isNot(CustomerRoutePaths.b2bAddresses));
  });

}
