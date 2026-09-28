import 'package:flutter_test/flutter_test.dart';
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
    ]) {
      expect(
        shouldUseMultiStoreDesign(definition(pattern), pattern),
        isTrue,
        reason: pattern,
      );
    }
  });
}
