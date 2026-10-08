import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

void main() {
  test('merchant intelligence keeps canonical wholesale customer journeys connected', () {
    final authority =
        File('lib/core/routing/customer_route_authority.dart').readAsStringSync();
    final journey =
        File('lib/features/b2b/b2b_journey_screen.dart').readAsStringSync();

    expect(authority, contains('CustomerRoutePaths.b2bOrders'));
    expect(authority, contains('CustomerRoutePaths.b2bCart'));
    expect(authority, contains('CustomerRoutePaths.b2bCheckout'));
    expect(journey, contains('FoodexBarChart('));
    expect(journey, contains('FoodexDonutChart('));
    expect(journey, contains("path: '/b2b/orders/\$id'"));
    expect(journey, contains("'channel': 'wholesale'"));
  });
}
