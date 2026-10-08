import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/routing/customer_route_authority.dart';
import 'package:foodex_customer_app/core/routing/customer_routes.dart';

void main() {
  CustomerRouteDefinition definition(String pattern) =>
      customerRouteDefinitions.firstWhere((item) => item.pattern == pattern);

  test('explicit retail store routes use the shared retail production authority', () {
    expect(
      customerRouteAuthorityFor(
        definition(CustomerRoutePaths.retailHome),
        '/retail/7/home',
      ),
      CustomerRouteAuthority.retailJourney,
    );
    expect(
      customerRouteAuthorityFor(
        definition(CustomerRoutePaths.retailProductDetails),
        '/retail/7/products/42',
      ),
      CustomerRouteAuthority.retailJourney,
    );
  });

  test('legacy generic retail routes stay on one retail journey authority', () {
    expect(
      customerRouteAuthorityFor(
        definition(CustomerRoutePaths.home),
        '/home?channel=retail&store_id=7',
      ),
      CustomerRouteAuthority.retailJourney,
    );
    expect(
      customerRouteAuthorityFor(
        definition(CustomerRoutePaths.orders),
        '/orders?channel=retail&store_id=7',
      ),
      CustomerRouteAuthority.retailJourney,
    );
  });

  test('wholesale orders have one multi-store renderer', () {
    for (final route in <String>[
      CustomerRoutePaths.b2bOrders,
      CustomerRoutePaths.b2bOrderDetails,
    ]) {
      expect(
        customerRouteAuthorityFor(definition(route), route),
        CustomerRouteAuthority.multiStore,
        reason: route,
      );
    }
  });

  test('wholesale account routes stay on B2B account authority', () {
    for (final route in <String>[
      CustomerRoutePaths.b2bDashboard,
      CustomerRoutePaths.b2bInvoices,
      CustomerRoutePaths.b2bAccountStatement,
      CustomerRoutePaths.b2bProfile,
    ]) {
      expect(
        customerRouteAuthorityFor(definition(route), route),
        CustomerRouteAuthority.b2bJourney,
        reason: route,
      );
    }
  });

  test('wholesale product compatibility is deterministic by store scope', () {
    final details = definition(CustomerRoutePaths.b2bProductDetails);

    expect(
      customerRouteAuthorityFor(details, '/b2b/products/42'),
      CustomerRouteAuthority.b2bJourney,
    );
    expect(
      customerRouteAuthorityFor(
        details,
        '/b2b/products/42?channel=wholesale&store_id=70',
      ),
      CustomerRouteAuthority.multiStore,
    );
  });
}
