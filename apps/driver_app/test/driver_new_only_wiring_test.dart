import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

void main() {
  test('production Driver runtime is new-only and legacy cannot be rewired', () {
    const legacyPath = 'lib/features/tasks/driver_journey.dart';
    expect(
      File(legacyPath).existsSync(),
      isFalse,
      reason: 'The monolithic legacy Driver journey must stay deleted.',
    );

    const productionFiles = <String>[
      'lib/app.dart',
      'lib/navigation.dart',
      'lib/core/api/http_driver_api.dart',
      'lib/features/delivery/active/driver_active_journey.dart',
      'lib/features/delivery/driver_journey_runtime.dart',
    ];

    for (final path in productionFiles) {
      final source = File(path).readAsStringSync();
      expect(
        source,
        isNot(contains('features/tasks/driver_journey.dart')),
        reason: '$path must not wire the legacy Driver journey.',
      );
      expect(
        source,
        isNot(contains('tasks/driver_journey.dart')),
        reason: '$path must not import the legacy Driver journey.',
      );
    }

    final navigation = File('lib/navigation.dart').readAsStringSync();
    expect(navigation, contains('DriverJourneyRuntimePage('));
    expect(navigation, isNot(contains('DriverJourneyPage(')));

    final runtime =
        File('lib/features/delivery/driver_journey_runtime.dart').readAsStringSync();
    expect(runtime, contains('DriverActiveJourneyPage('));
    expect(runtime, contains('showDriverCompletionDecisionSheet('));
  });
}
