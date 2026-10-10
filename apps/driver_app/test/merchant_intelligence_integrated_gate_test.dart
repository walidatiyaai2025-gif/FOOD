import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

void main() {
  test(
      'merchant intelligence keeps Driver workload analytics on canonical runtime',
      () {
    final navigation = File('lib/navigation.dart').readAsStringSync();

    expect(navigation, contains('_DriverWorkloadChart'));
    expect(navigation, contains('FoodexDonutChart('));
    expect(navigation, contains('driver-home-workload-chart'));
    expect(navigation, contains('/driver/b2c/deliveries'));
    expect(navigation, isNot(contains('/driver/b2b/deliveries')));
  });
}
