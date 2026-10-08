import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

void main() {
  test('merchant intelligence keeps canonical wholesale customer journeys connected', () {
    final authority =
        File('lib/core/routing/customer_route_authority.dart').readAsStringSync();
    final journey =
        File('lib/features/b2b/b2b_journey_screen.dart').readAsStringSync();

    expect(authority, contains('/b2b/orders'));
    expect(authority, contains('/b2b/cart'));
    expect(authority, contains('/b2b/checkout'));
    expect(journey, contains('FoodexBarChart('));
    expect(journey, contains('FoodexDonutChart('));
    expect(journey, contains("path: '/b2b/orders/\$id'"));
    expect(journey, contains("'channel': 'wholesale'"));
  });
}
