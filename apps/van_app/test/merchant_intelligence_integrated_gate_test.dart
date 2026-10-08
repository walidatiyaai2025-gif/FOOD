import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

void main() {
  test('merchant intelligence keeps Van analytics connected to operational screens', () {
    final dashboard =
        File('lib/features/foundation/van_dashboard_page.dart').readAsStringSync();
    final foundation =
        File('lib/features/foundation/van_foundation_screen.dart').readAsStringSync();
    final orders =
        File('lib/features/orders/van_orders_page.dart').readAsStringSync();
    final routes =
        File('lib/features/visits/van_routes_page.dart').readAsStringSync();
    final catalog =
        File('lib/features/orders/van_product_catalog_page.dart').readAsStringSync();

    expect(dashboard, contains('FoodexBarChart('));
    expect(orders, contains('FoodexDonutChart('));
    expect(routes, contains('FoodexDonutChart('));
    expect(catalog, contains('FoodexDonutChart('));

    for (final callback in <String>[
      'onOpenCustomers',
      'onOpenWallet',
      'onOpenReceipts',
      'onOpenRemittance',
    ]) {
      expect(dashboard, contains(callback));
      expect(foundation, contains(callback));
    }
  });
}
