import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

void main() {
  test('Customer mobile never renders invoice due-date fields', () {
    final source = File('lib/features/b2b/b2b_journey_screen.dart').readAsStringSync();

    expect(source, isNot(contains("['due_at']")));
    expect(source, isNot(contains("'due_at'")));
  });
}
