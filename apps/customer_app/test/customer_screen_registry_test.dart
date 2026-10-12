import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/routing/customer_screen_registry.dart';
import 'package:foodex_customer_app/core/routing/customer_routes.dart';

void main() {
  test(
    'Customer VNext registry owns every registered Customer route exactly once',
    () {
      final expected =
          customerRouteDefinitions.map((route) => route.pattern).toSet();
      final registered = customerVNextScreenRegistry
          .map((entry) => entry.routePattern)
          .toSet();

      expect(registered, expected);
      expect(registered.length, customerVNextScreenRegistry.length);
    },
  );

  test('Customer VNext registry keeps atomic child ownership fences', () {
    for (final entry in customerVNextScreenRegistry) {
      expect(
        entry.ownerIssue,
        anyOf(inInclusiveRange(1324, 1329), 1356),
      );
      expect(entry.evidence, contains(CustomerScreenEvidence.ar));
      expect(entry.evidence, contains(CustomerScreenEvidence.en));
      expect(entry.evidence, contains(CustomerScreenEvidence.smallPhone));
      expect(entry.evidence, contains(CustomerScreenEvidence.largePhone));
      expect(entry.evidence, contains(CustomerScreenEvidence.visual));
    }

    final expectedOwners = <String, int>{
      CustomerRoutePaths.splash: 1329,
      CustomerRoutePaths.entry: 1329,
      CustomerRoutePaths.marketplace: 1325,
      CustomerRoutePaths.retailHome: 1325,
      CustomerRoutePaths.products: 1326,
      CustomerRoutePaths.b2bProducts: 1326,
      CustomerRoutePaths.cart: 1327,
      CustomerRoutePaths.b2bCheckout: 1327,
      CustomerRoutePaths.orders: 1328,
      CustomerRoutePaths.b2bOrders: 1328,
      CustomerRoutePaths.profile: 1329,
      CustomerRoutePaths.b2bProfile: 1329,
      CustomerRoutePaths.b2bStoreAnalytics: 1356,
      CustomerRoutePaths.diagnostics: 1324,
    };

    for (final entry in expectedOwners.entries) {
      expect(
        customerVNextRegistryFor(entry.key)?.ownerIssue,
        entry.value,
        reason: entry.key,
      );
    }
  });
}
