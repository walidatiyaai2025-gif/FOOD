import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/routing/customer_screen_registry.dart';
import 'package:foodex_customer_app/core/routing/customer_routes.dart';

void main() {
  test('Customer VNext final certification owns every route with full evidence', () {
    final expectedRoutes =
        customerRouteDefinitions.map((route) => route.pattern).toSet();
    final registryRoutes =
        customerVNextScreenRegistry.map((entry) => entry.routePattern).toSet();

    expect(registryRoutes, expectedRoutes);
    expect(registryRoutes.length, customerVNextScreenRegistry.length);

    const requiredEvidence = <CustomerScreenEvidence>{
      CustomerScreenEvidence.ar,
      CustomerScreenEvidence.en,
      CustomerScreenEvidence.smallPhone,
      CustomerScreenEvidence.largePhone,
      CustomerScreenEvidence.loading,
      CustomerScreenEvidence.empty,
      CustomerScreenEvidence.error,
      CustomerScreenEvidence.offline,
      CustomerScreenEvidence.visual,
    };

    for (final entry in customerVNextScreenRegistry) {
      expect(
        entry.evidence.containsAll(requiredEvidence),
        isTrue,
        reason: entry.routePattern,
      );
    }
  });

  test('Customer VNext final matrix keeps one owner and explicit QA rows', () {
    final payload = jsonDecode(
      File('../../docs/execution/FOODEX_CUSTOMER_VNEXT_REQUIREMENTS.json')
          .readAsStringSync(),
    ) as Map<String, dynamic>;
    final requirements =
        (payload['requirements'] as List<dynamic>).cast<Map<String, dynamic>>();

    final ids = requirements.map((row) => row['id'] as String).toList();
    expect(ids.toSet().length, ids.length);

    for (final row in requirements) {
      final owner = row['owner_issue'];
      expect(owner, isA<int>(), reason: row['id'] as String);
      expect(owner as int, inInclusiveRange(1324, 1333));
    }

    final qaIds = requirements
        .where((row) => row['owner_issue'] == 1333)
        .map((row) => row['id'])
        .toSet();

    expect(
      qaIds,
      equals(<dynamic>{
        'CVN-QA-ROUTES',
        'CVN-QA-STATES',
        'CVN-QA-JOURNEY',
      }),
    );
  });

  test('final QA is bound to the real AR EN runtime screenshot harness', () {
    final source = File('test/screenshot_evidence_test.dart').readAsStringSync();

    for (final marker in <String>[
      '/home?channel=retail&store_id=7',
      '/products?channel=retail&store_id=7',
      '/cart?channel=retail&store_id=7',
      '/orders/101/track?channel=retail&store_id=7',
      '/profile?channel=retail&store_id=7',
    ]) {
      expect(source, contains(marker), reason: marker);
    }

    expect(source, contains("Locale('ar')"));
    expect(source, contains("Locale('en')"));
  });
}
