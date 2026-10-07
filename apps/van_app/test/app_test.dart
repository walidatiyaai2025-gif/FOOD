import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_van_app/app.dart';
import 'package:foodex_van_app/core/auth/van_session.dart';
import 'package:foodex_van_app/features/foundation/van_screen_inventory.dart';
import 'package:foodex_van_app/features/wallet/van_wallet_contract.dart';
import 'package:foodex_van_app/features/visits/van_visit_contract.dart';

void main() {
  test('approved Van production inventory stays locked to 19 surfaces', () {
    expect(vanProductionScreenInventory.length, 19);
    expect(vanProductionScreenInventory.first, VanScreenId.login);
    expect(vanProductionScreenInventory.last, VanScreenId.profile);
    expect(
      vanProductionScreenInventory.toSet().length,
      vanProductionScreenInventory.length,
    );
  });

  testWidgets('authenticated Van shell exposes production navigation instead of legacy tabs', (tester) async {
    await tester.pumpWidget(
      const FoodexVanApp(
        locale: Locale('en'),
        walletRepository: _EmptyWalletRepository(),
        initialSession: VanSession(
          token: 'test-token',
          name: 'Van Operator',
          email: 'van@example.test',
          locale: 'en',
          permissions: {'van.login'},
        ),
      ),
    );

    expect(find.text('FOODEX Van'), findsOneWidget);
    expect(find.text('Van Operator'), findsOneWidget);
    expect(find.text('Home Dashboard'), findsWidgets);
    expect(find.byType(NavigationBar), findsOneWidget);
    expect(find.byType(TabBar), findsNothing);

    final scaffold = tester.widget<Scaffold>(find.byType(Scaffold).last);
    scaffold.openDrawer();
    await tester.pumpAndSettle();

    expect(
      find.byKey(const ValueKey('van-production-screen-menu')),
      findsOneWidget,
    );
    expect(find.text('Routes'), findsWidgets);
    expect(find.text('Customers'), findsWidgets);
    expect(find.text('Product Catalog'), findsOneWidget);
    expect(find.text('Order Builder'), findsOneWidget);
    expect(find.text('Notifications'), findsOneWidget);
    expect(find.text('Profile & Settings'), findsOneWidget);

    await tester.tap(find.text('Customers').first);
    await tester.pumpAndSettle();
    expect(find.text('No assigned customers'), findsOneWidget);
  });

  testWidgets('Van Customer 360 renders authoritative customer financial context',
      (tester) async {
    await tester.pumpWidget(
      const FoodexVanApp(
        locale: Locale('en'),
        walletRepository: _CustomerWalletRepository(),
        initialSession: VanSession(
          token: 'test-token',
          name: 'Van Operator',
          email: 'van@example.test',
          locale: 'en',
          permissions: {'van.login'},
        ),
      ),
    );
    await tester.pumpAndSettle();

    final scaffold = tester.widget<Scaffold>(find.byType(Scaffold).last);
    scaffold.openDrawer();
    await tester.pumpAndSettle();

    final target =
        find.byKey(const ValueKey('van-screen-customer360'));
    await tester.scrollUntilVisible(
      target,
      180,
      scrollable:
          find.byKey(const ValueKey('van-production-screen-menu')),
    );
    await tester.tap(target);
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('van-customer-360')), findsOneWidget);
    expect(find.text('Acme Grocery'), findsWidgets);
    expect(find.text('INV-42'), findsOneWidget);
    expect(find.text('12.500 KWD'), findsOneWidget);
  });


  testWidgets('Van Collection posts through authoritative repository contract',
      (tester) async {
    await tester.pumpWidget(
      const FoodexVanApp(
        locale: Locale('en'),
        walletRepository: _CollectionWalletRepository(),
        initialSession: VanSession(
          token: 'test-token',
          name: 'Van Operator',
          email: 'van@example.test',
          locale: 'en',
          permissions: {'van.login'},
        ),
      ),
    );
    await tester.pumpAndSettle();

    final scaffold = tester.widget<Scaffold>(find.byType(Scaffold).last);
    scaffold.openDrawer();
    await tester.pumpAndSettle();

    final target = find.byKey(const ValueKey('van-screen-collection'));
    await tester.scrollUntilVisible(
      target,
      180,
      scrollable: find.byKey(const ValueKey('van-production-screen-menu')),
    );
    await tester.tap(target);
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('van-collection-page')), findsOneWidget);
    expect(find.textContaining('INV-42'), findsWidgets);

    await tester.enterText(
      find.byKey(const ValueKey('van-collection-amount')),
      '5.000',
    );
    await tester.tap(find.byKey(const ValueKey('van-collection-submit')));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('van-collection-receipt')), findsOneWidget);
    expect(find.textContaining('#77'), findsOneWidget);
    expect(find.textContaining('7.500 KWD'), findsOneWidget);
  });


  testWidgets('Van Receipt renders custody-ledger receipt history',
      (tester) async {
    await tester.pumpWidget(
      const FoodexVanApp(
        locale: Locale('en'),
        walletRepository: _ReceiptsWalletRepository(),
        initialSession: VanSession(
          token: 'test-token',
          name: 'Van Operator',
          email: 'van@example.test',
          locale: 'en',
          permissions: {'van.login'},
        ),
      ),
    );
    await tester.pumpAndSettle();

    final scaffold = tester.widget<Scaffold>(find.byType(Scaffold).last);
    scaffold.openDrawer();
    await tester.pumpAndSettle();

    final target = find.byKey(const ValueKey('van-screen-receipt'));
    await tester.scrollUntilVisible(
      target,
      180,
      scrollable: find.byKey(const ValueKey('van-production-screen-menu')),
    );
    await tester.tap(target);
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('van-receipts-page')), findsOneWidget);
    expect(find.byKey(const ValueKey('van-receipt-77')), findsOneWidget);
    expect(find.text('#77'), findsOneWidget);
    expect(find.text('5.000 KWD'), findsOneWidget);
  });


  testWidgets('Van Remittance submits through authoritative repository contract',
      (tester) async {
    await tester.pumpWidget(
      const FoodexVanApp(
        locale: Locale('en'),
        walletRepository: _RemittanceWalletRepository(),
        initialSession: VanSession(
          token: 'test-token',
          name: 'Van Operator',
          email: 'van@example.test',
          locale: 'en',
          permissions: {'van.login'},
        ),
      ),
    );
    await tester.pumpAndSettle();

    final scaffold = tester.widget<Scaffold>(find.byType(Scaffold).last);
    scaffold.openDrawer();
    await tester.pumpAndSettle();

    final target = find.byKey(const ValueKey('van-screen-remittance'));
    await tester.scrollUntilVisible(
      target,
      180,
      scrollable: find.byKey(const ValueKey('van-production-screen-menu')),
    );
    await tester.tap(target);
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('van-remittance-page')), findsOneWidget);
    expect(find.text('10.000 KWD'), findsOneWidget);

    await tester.enterText(
      find.byKey(const ValueKey('van-remittance-amount')),
      '5.000',
    );
    await tester.tap(find.byKey(const ValueKey('van-remittance-submit')));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('van-remittance-result')), findsOneWidget);
    expect(find.textContaining('5.000 KWD'), findsWidgets);
  });


  testWidgets('Van Profile renders authenticated session identity and access scope',
      (tester) async {
    await tester.pumpWidget(
      const FoodexVanApp(
        locale: Locale('en'),
        walletRepository: _EmptyWalletRepository(),
        initialSession: VanSession(
          token: 'test-token',
          name: 'Van Operator',
          email: 'van@example.test',
          locale: 'en',
          permissions: {'van.login', 'finance.view'},
        ),
      ),
    );
    await tester.pumpAndSettle();

    final scaffold = tester.widget<Scaffold>(find.byType(Scaffold).last);
    scaffold.openDrawer();
    await tester.pumpAndSettle();

    final target = find.byKey(const ValueKey('van-screen-profile'));
    await tester.scrollUntilVisible(
      target,
      180,
      scrollable: find.byKey(const ValueKey('van-production-screen-menu')),
    );
    await tester.tap(target);
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('van-profile-page')), findsOneWidget);
    expect(find.text('Van Operator'), findsWidgets);
    expect(find.text('van@example.test'), findsOneWidget);
    expect(find.text('EN'), findsOneWidget);
    expect(find.text('2'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('van-profile-permissions')));
    await tester.pumpAndSettle();
    expect(find.text('finance.view'), findsOneWidget);
    expect(find.text('van.login'), findsOneWidget);
  });


  testWidgets('Van Dashboard renders authoritative operational totals',
      (tester) async {
    await tester.pumpWidget(
      const FoodexVanApp(
        locale: Locale('en'),
        walletRepository: _DashboardWalletRepository(),
        initialSession: VanSession(
          token: 'test-token',
          name: 'Van Operator',
          email: 'van@example.test',
          locale: 'en',
          permissions: {'van.login'},
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('van-dashboard-page')), findsOneWidget);
    expect(find.text('2'), findsOneWidget);
    expect(find.text('15.000 KWD'), findsOneWidget);
    expect(find.text('9.000 KWD'), findsOneWidget);
    expect(find.text('1'), findsWidgets);
  });


  testWidgets('Van Visit Workspace uses canonical visit lifecycle transitions',
      (tester) async {
    await tester.pumpWidget(
      const FoodexVanApp(
        locale: Locale('en'),
        walletRepository: _CustomerWalletRepository(),
        visitRepository: _VisitRepository(),
        initialSession: VanSession(
          token: 'test-token',
          name: 'Van Operator',
          email: 'van@example.test',
          locale: 'en',
          permissions: {'van.login'},
        ),
      ),
    );
    await tester.pumpAndSettle();

    final scaffold = tester.widget<Scaffold>(find.byType(Scaffold).last);
    scaffold.openDrawer();
    await tester.pumpAndSettle();

    final target = find.byKey(const ValueKey('van-screen-visit'));
    await tester.scrollUntilVisible(
      target,
      180,
      scrollable: find.byKey(const ValueKey('van-production-screen-menu')),
    );
    await tester.tap(target);
    await tester.pumpAndSettle();

    expect(
      find.byKey(const ValueKey('van-visit-workspace-page')),
      findsOneWidget,
    );
    expect(find.text('Acme Grocery'), findsOneWidget);
    expect(find.text('Planned'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('van-visit-start-501')));
    await tester.pumpAndSettle();

    expect(find.text('Started'), findsOneWidget);
    expect(find.text('Planned'), findsNothing);
  });


  testWidgets('Van Routes renders canonical route context from visit feed',
      (tester) async {
    await tester.pumpWidget(
      const FoodexVanApp(
        locale: Locale('en'),
        walletRepository: _EmptyWalletRepository(),
        visitRepository: _RoutesVisitRepository(),
        initialSession: VanSession(
          token: 'test-token',
          name: 'Van Operator',
          email: 'van@example.test',
          locale: 'en',
          permissions: {'van.login'},
        ),
      ),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.text('Routes').last);
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('van-routes-page')), findsOneWidget);
    expect(find.text('ROUTE-A'), findsOneWidget);
    expect(find.text('2 assigned visits'), findsOneWidget);
  });


  testWidgets('Van Route Detail renders route visit KPIs from visit repository',
      (tester) async {
    await tester.pumpWidget(
      const FoodexVanApp(
        locale: Locale('en'),
        walletRepository: _EmptyWalletRepository(),
        visitRepository: _RoutesVisitRepository(),
        initialSession: VanSession(
          token: 'test-token',
          name: 'Van Operator',
          email: 'van@example.test',
          locale: 'en',
          permissions: {'van.login'},
        ),
      ),
    );
    await tester.pumpAndSettle();

    final scaffold = tester.widget<Scaffold>(find.byType(Scaffold).last);
    scaffold.openDrawer();
    await tester.pumpAndSettle();

    final target = find.byKey(const ValueKey('van-screen-routeDetail'));
    await tester.scrollUntilVisible(
      target,
      180,
      scrollable: find.byKey(const ValueKey('van-production-screen-menu')),
    );
    await tester.tap(target);
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('van-route-detail-page')), findsOneWidget);
    expect(find.text('ROUTE-A'), findsWidgets);
    expect(find.text('Visits · 2'), findsOneWidget);
    expect(find.text('Planned · 1'), findsOneWidget);
    expect(find.text('Started · 1'), findsOneWidget);
  });

}


class _EmptyWalletRepository implements VanWalletRepository {
  const _EmptyWalletRepository();

  @override
  Future<List<VanWalletAccount>> wallet() async => const [];

  @override
  Future<List<VanCustomerScope>> customers() async => const [];

  @override
  Future<VanCollectionContext> collectionContext(
    VanCustomerScope customer,
  ) async =>
      const VanCollectionContext(storeId: 1, invoices: []);

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


class _CustomerWalletRepository implements VanWalletRepository {
  const _CustomerWalletRepository();

  @override
  Future<List<VanWalletAccount>> wallet() async => const [];

  @override
  Future<List<VanCustomerScope>> customers() async => const [
        VanCustomerScope(
          type: 'b2b',
          id: 42,
          name: 'Acme Grocery',
          storeId: 7,
        ),
      ];

  @override
  Future<VanCollectionContext> collectionContext(
    VanCustomerScope customer,
  ) async =>
      const VanCollectionContext(
        storeId: 7,
        invoices: [
          VanInvoiceBalance(
            id: 42,
            number: 'INV-42',
            currency: 'KWD',
            total: 20,
            outstandingAmount: 12.5,
          ),
        ],
      );

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


class _CollectionWalletRepository implements VanWalletRepository {
  const _CollectionWalletRepository();

  @override
  Future<List<VanWalletAccount>> wallet() async => const [];

  @override
  Future<List<VanCustomerScope>> customers() async => const [
        VanCustomerScope(
          type: 'b2b',
          id: 42,
          name: 'Acme Grocery',
          storeId: 7,
        ),
      ];

  @override
  Future<VanCollectionContext> collectionContext(
    VanCustomerScope customer,
  ) async =>
      const VanCollectionContext(
        storeId: 7,
        invoices: [
          VanInvoiceBalance(
            id: 42,
            number: 'INV-42',
            currency: 'KWD',
            total: 20,
            outstandingAmount: 12.5,
          ),
        ],
      );

  @override
  Future<VanCollectionResult> collect({
    required VanCustomerScope customer,
    required int invoiceId,
    required double amount,
    required String idempotencyKey,
  }) async =>
      const VanCollectionResult(
        receipt: VanReceipt(
          id: 77,
          amount: 5,
          currency: 'KWD',
          status: 'posted',
          createdAt: '2026-10-07T08:30:00+03:00',
        ),
        remainingOutstanding: 7.5,
        wallet: VanWalletAccount(
          id: 5,
          storeId: 7,
          currency: 'KWD',
          status: 'active',
          custodyBalance: 5,
          availableToRemit: 5,
          receipts: [],
          remittances: [],
        ),
      );

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


class _ReceiptsWalletRepository implements VanWalletRepository {
  const _ReceiptsWalletRepository();

  @override
  Future<List<VanWalletAccount>> wallet() async => const [
        VanWalletAccount(
          id: 5,
          storeId: 7,
          currency: 'KWD',
          status: 'active',
          custodyBalance: 5,
          availableToRemit: 5,
          receipts: [
            VanReceipt(
              id: 77,
              amount: 5,
              currency: 'KWD',
              status: 'posted',
              createdAt: '2026-10-07T08:30:00+03:00',
            ),
          ],
          remittances: [],
        ),
      ];

  @override
  Future<List<VanCustomerScope>> customers() async => const [];

  @override
  Future<VanCollectionContext> collectionContext(
    VanCustomerScope customer,
  ) async =>
      const VanCollectionContext(storeId: 7, invoices: []);

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


class _RemittanceWalletRepository implements VanWalletRepository {
  const _RemittanceWalletRepository();

  @override
  Future<List<VanWalletAccount>> wallet() async => const [
        VanWalletAccount(
          id: 5,
          storeId: 7,
          currency: 'KWD',
          status: 'active',
          custodyBalance: 10,
          availableToRemit: 10,
          receipts: [],
          remittances: [],
        ),
      ];

  @override
  Future<List<VanCustomerScope>> customers() async => const [];

  @override
  Future<VanCollectionContext> collectionContext(
    VanCustomerScope customer,
  ) async =>
      const VanCollectionContext(storeId: 7, invoices: []);

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
  }) async =>
      const VanWalletAccount(
        id: 5,
        storeId: 7,
        currency: 'KWD',
        status: 'active',
        custodyBalance: 5,
        availableToRemit: 5,
        receipts: [],
        remittances: [
          VanRemittance(
            id: 88,
            amount: 5,
            currency: 'KWD',
            method: 'cash_deposit',
            status: 'submitted',
            createdAt: '2026-10-07T08:45:00+03:00',
          ),
        ],
      );
}


class _DashboardWalletRepository implements VanWalletRepository {
  const _DashboardWalletRepository();

  @override
  Future<List<VanWalletAccount>> wallet() async => const [
        VanWalletAccount(
          id: 5,
          storeId: 7,
          currency: 'KWD',
          status: 'active',
          custodyBalance: 15,
          availableToRemit: 9,
          receipts: [
            VanReceipt(
              id: 77,
              amount: 6,
              currency: 'KWD',
              status: 'posted',
              createdAt: '2026-10-07T08:30:00+03:00',
            ),
          ],
          remittances: [
            VanRemittance(
              id: 88,
              amount: 6,
              currency: 'KWD',
              method: 'cash_deposit',
              status: 'submitted',
              createdAt: '2026-10-07T08:45:00+03:00',
            ),
          ],
        ),
      ];

  @override
  Future<List<VanCustomerScope>> customers() async => const [
        VanCustomerScope(type: 'b2b', id: 42, name: 'Acme Grocery', storeId: 7),
        VanCustomerScope(type: 'b2c', id: 43, name: 'City Market', storeId: 8),
      ];

  @override
  Future<VanCollectionContext> collectionContext(
    VanCustomerScope customer,
  ) async =>
      const VanCollectionContext(storeId: 7, invoices: []);

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


class _VisitRepository implements VanVisitRepository {
  const _VisitRepository();

  @override
  Future<List<VanVisitRecord>> visits({String? status}) async => const [
        VanVisitRecord(
          id: 501,
          customerType: 'b2b',
          customerId: 42,
          storeId: 7,
          status: 'planned',
          plannedAt: '2026-10-07T10:00:00+03:00',
          allowedTransitions: ['started', 'customer_unavailable'],
        ),
      ];

  @override
  Future<VanVisitRecord> transition({
    required int visitId,
    required String status,
    int? orderId,
    int? noOrderReasonId,
  }) async {
    if (visitId != 501 || status != 'started') {
      throw StateError('Unexpected visit transition in test.');
    }
    return const VanVisitRecord(
      id: 501,
      customerType: 'b2b',
      customerId: 42,
      storeId: 7,
      status: 'started',
      plannedAt: '2026-10-07T10:00:00+03:00',
      startedAt: '2026-10-07T10:01:00+03:00',
      allowedTransitions: [
        'completed_with_order',
        'completed_no_order',
        'customer_unavailable',
      ],
    );
  }
}


class _RoutesVisitRepository implements VanVisitRepository {
  const _RoutesVisitRepository();

  @override
  Future<List<VanVisitRecord>> visits({String? status}) async => const [
        VanVisitRecord(
          id: 601,
          customerType: 'b2b',
          customerId: 42,
          storeId: 7,
          routeKey: 'ROUTE-A',
          status: 'planned',
          plannedAt: '2026-10-07T10:00:00+03:00',
          allowedTransitions: ['started', 'customer_unavailable'],
        ),
        VanVisitRecord(
          id: 602,
          customerType: 'b2c',
          customerId: 43,
          storeId: 8,
          routeKey: 'ROUTE-A',
          status: 'started',
          plannedAt: '2026-10-07T11:00:00+03:00',
          allowedTransitions: [
            'completed_with_order',
            'completed_no_order',
            'customer_unavailable',
          ],
        ),
      ];

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
