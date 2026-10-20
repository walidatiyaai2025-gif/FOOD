import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

int _count(String source, String token) =>
    RegExp(RegExp.escape(token)).allMatches(source).length;

void main() {
  test('every customer Scaffold uses the unified header contract', () async {
    final lib = Directory('lib');
    expect(lib.existsSync(), isTrue);

    final violations = <String>[];
    final files = lib
        .listSync(recursive: true)
        .whereType<File>()
        .where((file) => file.path.endsWith('.dart'));

    for (final file in files) {
      final source = await file.readAsString();
      final scaffoldCount = _count(source, 'Scaffold(');
      if (scaffoldCount == 0) continue;

      final unifiedCount = _count(source, 'FoodexUnifiedHeaderSurface(');
      final sharedCount = _count(source, 'FoodexWholesaleHeader(');
      final coveredCount = unifiedCount + sharedCount;

      if (coveredCount < scaffoldCount) {
        violations.add(
          '${file.path}: uncovered Scaffold(s): Scaffold=$scaffoldCount '
          'Unified=$unifiedCount Shared=$sharedCount',
        );
      }

      for (final legacy in const <String>[
        'appBar: AppBar(',
        'CustomerCurvedHeaderSurface(',
        'FoodexTopBar(',
        'CustomerAccountHeader(',
      ]) {
        if (source.contains(legacy)) {
          violations.add('${file.path}: legacy header token $legacy');
        }
      }
    }

    expect(
      violations,
      isEmpty,
      reason:
          'Every customer page/state must render the single shared header.\n'
          '${violations.join('\n')}',
    );
  });
}
