import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_van_app/features/visits/van_route_map_page.dart';
import 'package:foodex_van_app/features/visits/van_visit_contract.dart';
import 'package:foodex_van_app/features/wallet/van_wallet_contract.dart';

void main() {
  testWidgets(
    'Van Route Map exposes every assigned stop in ordered semantics',
    (tester) async {
      final semantics = tester.ensureSemantics();

      await tester.pumpWidget(
        MaterialApp(
          locale: const Locale('en'),
          home: Scaffold(
            body: VanRouteMapPage(
              visitRepository: const _Visits(),
              customerRepository: const _Customers(),
              onSessionExpired: () async {},
            ),
          ),
        ),
      );
      await tester.pumpAndSettle();

      expect(
          find.byKey(const ValueKey('van-route-map-canvas')), findsOneWidget);
      expect(
        find.textContaining(
          '1 stop(s) are omitted from the map because the authoritative address has no coordinates.',
        ),
        findsOneWidget,
      );

      final page = find.byKey(const ValueKey('van-route-map-page'));
      final scrollable = find
          .descendant(
            of: page,
            matching: find.byType(Scrollable),
          )
          .first;

      final firstStop = find.byKey(const ValueKey('van-route-map-stop-601'));
      await tester.scrollUntilVisible(firstStop, 180, scrollable: scrollable);
      final firstSemantics = tester.getSemantics(firstStop);
      expect(firstSemantics.label, contains('Stop 1 of 3'));
      expect(firstSemantics.label, contains('Acme Grocery'));
      expect(firstSemantics.label, contains('Status: Planned'));
      expect(firstSemantics.label, contains('Address: Block 3 · Street 17'));
      expect(firstSemantics.label, contains('Plotted on map'));

      final secondStop = find.byKey(const ValueKey('van-route-map-stop-602'));
      await tester.scrollUntilVisible(secondStop, 180, scrollable: scrollable);
      final secondSemantics = tester.getSemantics(secondStop);
      expect(secondSemantics.label, contains('Stop 2 of 3'));
      expect(secondSemantics.label, contains('City Market'));
      expect(secondSemantics.label, contains('Status: Started'));
      expect(secondSemantics.label, contains('Address: Block 5 · Street 9'));
      expect(
        secondSemantics.label,
        contains('Not plotted — coordinates missing'),
      );

      final thirdStop = find.byKey(const ValueKey('van-route-map-stop-603'));
      await tester.scrollUntilVisible(thirdStop, 180, scrollable: scrollable);
      final thirdSemantics = tester.getSemantics(thirdStop);
      expect(thirdSemantics.label, contains('Stop 3 of 3'));
      expect(thirdSemantics.label, contains('Harbor Store'));
      expect(thirdSemantics.label, contains('Status: Closed'));
      expect(thirdSemantics.label, contains('Plotted on map'));
      semantics.dispose();
    },
  );

  testWidgets(
    'Van Route Map keeps Arabic missing-location state usable at large text',
    (tester) async {
      tester.view.physicalSize = const Size(360, 800);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);

      await tester.pumpWidget(
        MaterialApp(
          localizationsDelegates: GlobalMaterialLocalizations.delegates,
          supportedLocales: const [Locale('en'), Locale('ar')],
          locale: const Locale('ar'),
          builder: (context, child) => MediaQuery(
            data: MediaQuery.of(context).copyWith(
              textScaler: TextScaler.linear(2),
            ),
            child: child!,
          ),
          home: Scaffold(
            body: VanRouteMapPage(
              visitRepository: const _Visits(),
              customerRepository: const _Customers(),
              onSessionExpired: () async {},
            ),
          ),
        ),
      );
      await tester.pumpAndSettle();

      final page = find.byKey(const ValueKey('van-route-map-page'));
      final secondStop = find.byKey(const ValueKey('van-route-map-stop-602'));
      await tester.scrollUntilVisible(
        secondStop,
        180,
        scrollable: find
            .descendant(
              of: page,
              matching: find.byType(Scrollable),
            )
            .first,
      );
      await tester.pumpAndSettle();

      expect(find.text('City Market'), findsOneWidget);
      expect(find.text('بدأت'), findsOneWidget);
      expect(
        find.text('غير ظاهر على الخريطة — الإحداثيات غير متاحة'),
        findsOneWidget,
      );
      expect(tester.takeException(), isNull);
    },
  );
}

class _Visits implements VanVisitRepository {
  const _Visits();

  @override
  Future<List<VanVisitRecord>> visits({String? status}) async => const [
        VanVisitRecord(
          id: 601,
          customerType: 'b2b',
          customerId: 42,
          storeId: 7,
          routeKey: 'ROUTE-A',
          latitude: 29.3375,
          longitude: 47.6581,
          address: 'Block 3 · Street 17',
          status: 'planned',
          allowedTransitions: ['started'],
        ),
        VanVisitRecord(
          id: 602,
          customerType: 'b2c',
          customerId: 43,
          storeId: 8,
          routeKey: 'ROUTE-A',
          address: 'Block 5 · Street 9',
          status: 'started',
          allowedTransitions: ['completed_no_order'],
        ),
        VanVisitRecord(
          id: 603,
          customerType: 'b2b',
          customerId: 44,
          storeId: 9,
          routeKey: 'ROUTE-A',
          latitude: 29.3500,
          longitude: 47.6800,
          address: 'Block 7 · Street 2',
          status: 'closed',
          allowedTransitions: [],
        ),
      ];

  @override
  Future<List<VanNoOrderReasonRecord>> noOrderReasons() async => const [];

  @override
  Future<VanVisitRecord> transition({
    required int visitId,
    required String status,
    int? orderId,
    int? noOrderReasonId,
  }) {
    throw UnimplementedError();
  }
}

class _Customers implements VanWalletRepository {
  const _Customers();

  @override
  Future<List<VanCustomerScope>> customers() async => const [
        VanCustomerScope(
          type: 'b2b',
          id: 42,
          name: 'Acme Grocery',
          storeId: 7,
        ),
        VanCustomerScope(
          type: 'b2c',
          id: 43,
          name: 'City Market',
          storeId: 8,
        ),
        VanCustomerScope(
          type: 'b2b',
          id: 44,
          name: 'Harbor Store',
          storeId: 9,
        ),
      ];

  @override
  Future<List<VanWalletAccount>> wallet() async => const [];

  @override
  Future<VanCollectionContext> collectionContext(
    VanCustomerScope customer,
  ) async =>
      VanCollectionContext(storeId: customer.storeId, invoices: const []);

  @override
  Future<VanCollectionResult> collect({
    required VanCustomerScope customer,
    required int invoiceId,
    required double amount,
    required String idempotencyKey,
  }) {
    throw UnimplementedError();
  }

  @override
  Future<VanWalletAccount> remit({
    required int collectionAccountId,
    required double amount,
    required String method,
    required String idempotencyKey,
    String? reference,
    String? note,
  }) {
    throw UnimplementedError();
  }
}
