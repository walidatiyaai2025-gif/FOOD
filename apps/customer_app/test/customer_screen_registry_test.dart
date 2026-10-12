import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/routing/customer_screen_registry.dart';
import 'package:foodex_customer_app/core/routing/customer_routes.dart';

void main() {
  test('Customer VNext registry owns every global/B2C customer route', () {
    final expected = customerRouteDefinitions
        .where((route) => route.channel == null || route.channel?.name == 'b2c')
        .map((route) => route.pattern)
        .toSet();

    final registered = customerVNextScreenRegistry
        .map((entry) => entry.routePattern)
        .toSet();

    expect(registered, containsAll(expected));
    expect(registered.length, customerVNextScreenRegistry.length);
  });

  test('Customer VNext registry keeps atomic child ownership fences', () {
    for (final entry in customerVNextScreenRegistry) {
      expect(entry.ownerIssue, inInclusiveRange(1324, 1329));
      expect(entry.evidence, contains(CustomerScreenEvidence.ar));
      expect(entry.evidence, contains(CustomerScreenEvidence.en));
      expect(entry.evidence, contains(CustomerScreenEvidence.smallPhone));
      expect(entry.evidence, contains(CustomerScreenEvidence.largePhone));
      expect(entry.evidence, contains(CustomerScreenEvidence.visual));
    }

    expect(
      customerVNextRegistryFor(CustomerRoutePaths.retailHome)?.ownerIssue,
      1325,
    );
    expect(
      customerVNextRegistryFor(CustomerRoutePaths.products)?.ownerIssue,
      1326,
    );
    expect(
      customerVNextRegistryFor(CustomerRoutePaths.cart)?.ownerIssue,
      1327,
    );
    expect(
      customerVNextRegistryFor(CustomerRoutePaths.orders)?.ownerIssue,
      1328,
    );
    expect(
      customerVNextRegistryFor(CustomerRoutePaths.profile)?.ownerIssue,
      1329,
    );
  });
}
