import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

void main() {
  test('Customer production routing cannot reintroduce legacy B2C journey', () {
    final legacy =
        File('lib/features/home/b2c_journey_screen.dart');
    expect(
      legacy.existsSync(),
      isFalse,
      reason: 'Legacy B2C runtime must stay deleted after #683.',
    );

    final router = File('lib/core/routing/customer_router.dart')
        .readAsStringSync();
    expect(router, isNot(contains('B2cJourneyScreen')));
    expect(router, isNot(contains('features/home/b2c_journey_screen.dart')));
    expect(router, contains('RetailCustomerJourneyScreen'));
  });
}
