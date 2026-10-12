import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/routing/customer_screen_registry.dart';
import 'package:foodex_customer_app/core/routing/customer_routes.dart';
import 'package:foodex_customer_app/shared/customer_ui_v3/customer_ui_v3.dart';

void main() {
  test(
    'Customer VNext final certification owns every route with full evidence',
    () {
      final expectedRoutes =
          customerRouteDefinitions.map((route) => route.pattern).toSet();
      final registryRoutes = customerVNextScreenRegistry
          .map((entry) => entry.routePattern)
          .toSet();

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
    },
  );

  test(
    'Customer VNext final matrix keeps one owner and explicit QA rows',
    () {
      final payload = jsonDecode(
        File('../../docs/execution/FOODEX_CUSTOMER_VNEXT_REQUIREMENTS.json')
            .readAsStringSync(),
      ) as Map<String, dynamic>;
      final requirements = (payload['requirements'] as List<dynamic>)
          .cast<Map<String, dynamic>>();

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
    },
  );

  test(
    'final QA is bound to the real AR EN runtime screenshot harness',
    () {
      final source =
          File('test/screenshot_evidence_test.dart').readAsStringSync();

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
    },
  );

  test(
    'final QA binds slow long-text offline and out-of-stock regressions',
    () {
      final slowApi = File('test/b2b_journey_test.dart').readAsStringSync();
      final longText =
          File('test/customer_ui_v3_design_system_test.dart').readAsStringSync();
      final offline =
          File('test/customer_ui_v3_shell_home_test.dart').readAsStringSync();
      final outOfStock =
          File('test/customer_ui_v3_browse_test.dart').readAsStringSync();

      expect(
        slowApi,
        contains(
          'B2B cart coalesces rapid plus taps while the first quantity update is slow',
        ),
      );
      expect(
        longText,
        contains('منتج تجريبي طويل لاختبار تكبير الخط'),
      );
      expect(
        offline,
        contains(
          'Customer VNext Home exposes an explicit retryable offline state',
        ),
      );
      expect(
        outOfStock,
        contains(
          'out-of-stock product is dimmed, labeled and cannot be added or increased',
        ),
      );
    },
  );

  testWidgets(
    'missing storefront image renders the canonical product fallback',
    (tester) async {
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
    },
  );
}
