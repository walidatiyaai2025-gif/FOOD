import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/routing/customer_screen_registry.dart';
import 'package:foodex_customer_app/core/routing/customer_routes.dart';
import 'package:foodex_customer_app/shared/customer_ui_v3/customer_ui_v3.dart';

const _requirementsPath =
    '../../docs/execution/FOODEX_CUSTOMER_VNEXT_REQUIREMENTS.json';

String _read(String path) => File(path).readAsStringSync();

void main() {
  test('all customer routes have complete certification evidence', () {
    final expected = {
      for (final route in customerRouteDefinitions) route.pattern,
    };
    final registered = {
      for (final entry in customerVNextScreenRegistry) entry.routePattern,
    };

    expect(registered, expected);
    expect(registered.length, customerVNextScreenRegistry.length);

    const required = <CustomerScreenEvidence>{
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
        entry.evidence.containsAll(required),
        isTrue,
        reason: entry.routePattern,
      );
    }
  });

  test('final matrix has one owner and the three final QA rows', () {
    final payload = jsonDecode(_read(_requirementsPath));
    final rows = (payload as Map<String, dynamic>)['requirements'] as List;
    final ids = <String>{};
    final qaIds = <String>{};

    for (final raw in rows) {
      final row = raw as Map<String, dynamic>;
      final id = row['id'] as String;
      final owner = row['owner_issue'];

      expect(ids.add(id), isTrue, reason: id);
      expect(owner, isA<int>(), reason: id);
      expect(owner as int, inInclusiveRange(1324, 1333));
      if (owner == 1333) {
        qaIds.add(id);
      }
    }

    expect(
      qaIds,
      {
        'CVN-QA-ROUTES',
        'CVN-QA-STATES',
        'CVN-QA-JOURNEY',
      },
    );
  });

  test('final QA is bound to AR and EN runtime screenshots', () {
    final source = _read('test/screenshot_evidence_test.dart');

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

  test('edge-state regressions remain executable', () {
    final slow = _read('test/b2b_journey_test.dart');
    final longText = _read('test/customer_ui_v3_design_system_test.dart');
    final offline = _read('test/customer_ui_v3_shell_home_test.dart');
    final stock = _read('test/customer_ui_v3_browse_test.dart');

    expect(slow, contains('first quantity update is slow'));
    expect(longText, contains('اختبار تكبير الخط'));
    expect(offline, contains('explicit retryable offline state'));
    expect(stock, contains('out-of-stock product is dimmed'));
  });

  testWidgets('missing image uses the canonical fallback', (tester) async {
    await tester.pumpWidget(
      const MaterialApp(
        home: Scaffold(
          body: SizedBox(
            width: 160,
            child: CustomerProductImage(),
          ),
        ),
      ),
    );

    expect(find.byIcon(Icons.inventory_2_outlined), findsOneWidget);
    expect(tester.takeException(), isNull);
  });
}
