import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_driver_app/app.dart';
import 'package:foodex_driver_app/core/auth/driver_session.dart';
import 'package:foodex_driver_app/core/navigation/driver_shell.dart';
import 'package:foodex_driver_app/features/delivery/driver_assignment_contract.dart';
import 'package:foodex_driver_app/features/wallet/driver_wallet_contract.dart';
import 'package:foodex_driver_app/navigation.dart';

class _StatusRepo implements DriverAssignmentRepository {
  @override
  Future<List<DriverAssignment>> list(DriverChannel channel) async => const [
        DriverAssignment(
          id: 0,
          channel: DriverChannel.b2c,
          reference: 'PENDING-1',
          status: 'assigned',
        ),
        DriverAssignment(
          id: 1,
          channel: DriverChannel.b2c,
          reference: 'ACCEPTED-1',
          status: 'accepted',
        ),
        DriverAssignment(
          id: 2,
          channel: DriverChannel.b2c,
          reference: 'PICKED-1',
          status: 'picked_up',
        ),
        DriverAssignment(
          id: 3,
          channel: DriverChannel.b2c,
          reference: 'OUT-1',
          status: 'out_for_delivery',
        ),
        DriverAssignment(
          id: 4,
          channel: DriverChannel.b2c,
          reference: 'FAILED-1',
          status: 'failed',
        ),
        DriverAssignment(
          id: 5,
          channel: DriverChannel.b2c,
          reference: 'DONE-1',
          status: 'delivered',
        ),
      ];

  @override
  Future<void> transition(
    int id,
    DriverChannel channel,
    String status, {
    String? note,
    String? failureReason,
  }) async {}
}

class _WalletStatusRepo extends _StatusRepo implements DriverWalletRepository {
  @override
  Future<List<DriverWalletAccount>> wallet() async => const [
        DriverWalletAccount(
          id: 81,
          currency: 'KWD',
          status: 'active',
          custodyBalance: 42.75,
          availableToRemit: 30,
          totalCollected: 15,
          collectedOrders: [
            DriverCollectedOrder(
              orderId: 123,
              orderNumber: 'FO-123',
              currency: 'KWD',
              orderTotal: 30,
              collectedAmount: 15,
              remainingAmount: 15,
              paymentState: 'partially_settled',
            ),
          ],
        ),
      ];

  @override
  Future<DriverCollectionResult> collect(
    int assignmentId, {
    required double amount,
    required String idempotencyKey,
  }) async =>
      DriverCollectionResult(
        receipt: const DriverCollectionReceipt(
          id: 1,
          amount: 1,
          currency: 'KWD',
          status: 'posted',
          createdAt: '2026-10-27T00:00:00Z',
        ),
        remainingAmount: 0,
        currency: 'KWD',
      );

  @override
  Future<DriverRemittance> submitRemittance({
    required int collectionAccountId,
    required double amount,
    required String method,
    String? reference,
    String? note,
    required String idempotencyKey,
  }) async =>
      DriverRemittance(
        id: 1,
        amount: amount,
        currency: 'KWD',
        method: method,
        status: 'pending',
        createdAt: '2026-10-27T00:00:00Z',
      );
}

class _LiveStatusRepo implements DriverAssignmentRepository {
  List<DriverAssignment> rows = const [
    DriverAssignment(
      id: 9,
      channel: DriverChannel.b2c,
      reference: 'LIVE-PENDING',
      status: 'assigned',
    ),
    DriverAssignment(
      id: 10,
      channel: DriverChannel.b2c,
      reference: 'LIVE-ACCEPTED',
      status: 'accepted',
    ),
  ];
  int listCalls = 0;

  @override
  Future<List<DriverAssignment>> list(DriverChannel channel) async {
    listCalls++;
    return List<DriverAssignment>.unmodifiable(rows);
  }

  @override
  Future<void> transition(
    int id,
    DriverChannel channel,
    String status, {
    String? note,
    String? failureReason,
  }) async {}
}

class _StaleStatusRepo implements DriverAssignmentRepository {
  int listCalls = 0;

  @override
  Future<List<DriverAssignment>> list(DriverChannel channel) async {
    listCalls++;
    if (listCalls > 1) {
      throw StateError('offline');
    }
    return const [
      DriverAssignment(
        id: 20,
        channel: DriverChannel.b2c,
        reference: 'STALE-ACCEPTED',
        status: 'accepted',
      ),
      DriverAssignment(
        id: 21,
        channel: DriverChannel.b2c,
        reference: 'STALE-DONE',
        status: 'delivered',
      ),
    ];
  }

  @override
  Future<void> transition(
    int id,
    DriverChannel channel,
    String status, {
    String? note,
    String? failureReason,
  }) async {}
}

class ChannelRecordingRepo implements DriverAssignmentRepository {
  DriverChannel? requestedChannel;

  @override
  Future<List<DriverAssignment>> list(DriverChannel channel) async {
    requestedChannel = channel;
    return const [];
  }

  @override
  Future<void> transition(
    int id,
    DriverChannel channel,
    String status, {
    String? note,
    String? failureReason,
  }) async {}
}

class EmptyRepo implements DriverAssignmentRepository {
  @override
  Future<List<DriverAssignment>> list(DriverChannel channel) async => const [];

  @override
  Future<void> transition(int id, DriverChannel channel, String status,
      {String? note, String? failureReason}) async {}
}

DriverSession session(DriverChannel channel) => DriverSession(
      token: 'token',
      name: 'Driver',
      email: 'driver@example.test',
      locale: 'ar',
      channel: channel,
    );

void main() {
  testWidgets(
      'B2C wallet is reachable from Home footer, shows collected orders, and returns Home',
      (tester) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(() {
      tester.view.resetPhysicalSize();
      tester.view.resetDevicePixelRatio();
    });

    await tester.pumpWidget(FoodexDriverApp(
      locale: const Locale('ar'),
      initialSession: session(DriverChannel.b2c),
      assignmentRepositoryFactory: (_) => _WalletStatusRepo(),
    ));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('driver-shell-wallet')), findsOneWidget);
    expect(find.text('المحفظة'), findsOneWidget);
    expect(find.byKey(const Key('driver-open-wallet')), findsOneWidget);
    expect(find.byKey(const Key('driver-home-unsettled-collections')),
        findsOneWidget);

    await tester.tap(find.byKey(const Key('driver-shell-wallet')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('driver-wallet-page')), findsOneWidget);
    expect(
        find.byKey(const Key('driver-collected-summary-81')), findsOneWidget);
    expect(find.byKey(const Key('driver-shell-navigation')), findsOneWidget);
    expect(
      tester
          .widget<NavigationBar>(
            find.byKey(const Key('driver-shell-navigation')),
          )
          .selectedIndex,
      DriverShellDestination.wallet.index,
    );

    await tester.tap(find.byKey(const Key('driver-collected-summary-81')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('driver-collected-order-123')), findsOneWidget);
    expect(find.text('FO-123'), findsOneWidget);
    await tester.pageBack();
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('driver-shell-home')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('driver-home-unsettled-collections')),
        findsOneWidget);
    expect(find.byKey(const Key('driver-wallet-page')), findsNothing);

    await tester.tap(find.byKey(const Key('driver-open-wallet')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('driver-wallet-page')), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets(
      'English footer opens B2C wallet and preserves Deliveries navigation',
      (tester) async {
    await tester.pumpWidget(FoodexDriverApp(
      locale: const Locale('en'),
      initialSession: session(DriverChannel.b2c),
      assignmentRepositoryFactory: (_) => _WalletStatusRepo(),
    ));
    await tester.pumpAndSettle();
    expect(find.text('Wallet'), findsOneWidget);
    await tester.tap(find.byKey(const Key('driver-shell-wallet')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('driver-wallet-page')), findsOneWidget);
    await tester.tap(find.byKey(const Key('driver-shell-deliveries')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('driver-shell')), findsOneWidget);
    expect(
        tester
            .widget<NavigationBar>(
              find.byKey(const Key('driver-shell-navigation')),
            )
            .selectedIndex,
        DriverShellDestination.deliveries.index);
    expect(find.byKey(const Key('driver-shell-wallet')), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets(
      'B2B and unsupported B2C repositories do not expose a dead Wallet destination',
      (tester) async {
    for (final channel in [DriverChannel.b2b, DriverChannel.b2c]) {
      await tester.pumpWidget(FoodexDriverApp(
        initialSession: session(channel),
        assignmentRepositoryFactory: (_) => _StatusRepo(),
      ));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('driver-shell-wallet')), findsNothing);
      expect(find.byKey(const Key('driver-open-wallet')), findsNothing);
      expect(tester.takeException(), isNull);
      await tester.pumpWidget(const SizedBox.shrink());
    }
  });

  testWidgets('B2C driver starts inside the backend-derived B2C partition',
      (tester) async {
    final repo = ChannelRecordingRepo();
    await tester.pumpWidget(
      FoodexDriverApp(
        initialSession: session(DriverChannel.b2c),
        assignmentRepositoryFactory: (_) => repo,
      ),
    );
    await tester.pumpAndSettle();
    expect(repo.requestedChannel, DriverChannel.b2c);
    expect(find.byKey(const Key('driver-shell')), findsOneWidget);
    expect(find.byKey(const Key('driver-shell-navigation')), findsOneWidget);
    expect(
      tester
          .widget<NavigationBar>(
            find.byKey(const Key('driver-shell-navigation')),
          )
          .selectedIndex,
      DriverShellDestination.home.index,
    );
    expect(find.byKey(const Key('driver-shell-deliveries')), findsOneWidget);
    expect(find.byKey(const Key('driver-route-denied')), findsNothing);
  });

  testWidgets('B2B driver starts inside the manual-dispatch B2B partition',
      (tester) async {
    final repo = ChannelRecordingRepo();
    await tester.pumpWidget(
      FoodexDriverApp(
        initialSession: session(DriverChannel.b2b),
        assignmentRepositoryFactory: (_) => repo,
      ),
    );
    await tester.pumpAndSettle();
    expect(repo.requestedChannel, DriverChannel.b2b);
    expect(find.byKey(const Key('driver-shell-deliveries')), findsOneWidget);
    expect(find.byKey(const Key('driver-route-denied')), findsNothing);
  });

  testWidgets(
      'driver home shows four status cards and opens failed deliveries exactly',
      (tester) async {
    final repo = _StatusRepo();
    await tester.pumpWidget(
      FoodexDriverApp(
        initialSession: session(DriverChannel.b2c),
        assignmentRepositoryFactory: (_) => repo,
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('driver-home-driver-name')), findsOneWidget);
    expect(find.byKey(const Key('driver-home-pending-orders')), findsOneWidget);
    expect(find.byKey(const Key('driver-home-workload-chart')), findsOneWidget);
    expect(find.text('توزيع التوصيلات'), findsOneWidget);
    expect(find.text('الطلبات المعلقة'), findsOneWidget);
    final pendingCard = find.byKey(const Key('driver-home-pending-orders'));
    expect(
      find.descendant(of: pendingCard, matching: find.text('1')),
      findsOneWidget,
    );
    final pendingContainer = tester.widget<Container>(
      find.descendant(of: pendingCard, matching: find.byType(Container)).first,
    );
    final pendingDecoration = pendingContainer.decoration! as BoxDecoration;
    expect(pendingDecoration.border, isNotNull);
    expect(find.text('مقبول · 1'), findsOneWidget);
    expect(find.text('تم الاستلام · 1'), findsNothing);
    expect(find.text('قناة العمل'), findsNothing);

    final statusGrid = find.byKey(const Key('driver-home-status-grid'));
    expect(statusGrid, findsOneWidget);
    expect(
        find.byKey(const Key('driver-home-status-accepted')), findsOneWidget);
    expect(find.byKey(const Key('driver-home-status-picked_up')), findsNothing);
    expect(find.byKey(const Key('driver-home-status-out_for_delivery')),
        findsOneWidget);
    expect(find.byKey(const Key('driver-home-status-failed')), findsOneWidget);
    expect(
        find.byKey(const Key('driver-home-status-delivered')), findsOneWidget);

    final failedCard = find.byKey(const Key('driver-home-status-failed'));
    final failedTapTarget = find.descendant(
      of: failedCard,
      matching: find.byType(InkWell),
    );
    expect(failedTapTarget, findsOneWidget);
    await tester.tap(failedTapTarget);
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('driver-shell')), findsOneWidget);
    expect(find.byKey(const Key('driver-shell-navigation')), findsOneWidget);
    expect(
      tester
          .widget<NavigationBar>(
            find.byKey(const Key('driver-shell-navigation')),
          )
          .selectedIndex,
      DriverShellDestination.deliveries.index,
    );
    expect(
        find.byKey(const Key('driver-active-status-filter')), findsOneWidget);
    expect(find.text('FAILED-1'), findsOneWidget);
    expect(find.text('OUT-1'), findsNothing);
    expect(find.text('ACCEPTED-1'), findsNothing);
    expect(find.text('DONE-1'), findsNothing);
  });

  testWidgets('pending orders card opens assigned deliveries only',
      (tester) async {
    final repo = _StatusRepo();
    await tester.pumpWidget(
      FoodexDriverApp(
        initialSession: session(DriverChannel.b2c),
        assignmentRepositoryFactory: (_) => repo,
      ),
    );
    await tester.pumpAndSettle();

    final pendingCard = find.byKey(const Key('driver-home-pending-orders'));
    expect(pendingCard, findsOneWidget);
    await tester.ensureVisible(pendingCard);
    await tester.pumpAndSettle();
    await tester.tap(pendingCard);
    await tester.pumpAndSettle();

    expect(
        find.byKey(const Key('driver-active-status-filter')), findsOneWidget);
    expect(find.text('PENDING-1'), findsOneWidget);
    expect(find.text('ACCEPTED-1'), findsNothing);
    expect(find.text('OUT-1'), findsNothing);
    expect(find.text('DONE-1'), findsNothing);
  });

  testWidgets('driver home status grid is fixed and non-scrollable',
      (tester) async {
    final repo = _StatusRepo();
    await tester.pumpWidget(
      FoodexDriverApp(
        initialSession: session(DriverChannel.b2c),
        assignmentRepositoryFactory: (_) => repo,
      ),
    );
    await tester.pumpAndSettle();

    final gridFinder = find.byKey(const Key('driver-home-status-grid'));
    expect(gridFinder, findsOneWidget);
    final grid = tester.widget<GridView>(gridFinder);
    expect(grid.physics, isA<NeverScrollableScrollPhysics>());
    expect(find.byKey(const Key('driver-home-pending-orders')), findsOneWidget);
    expect(find.byKey(const Key('driver-home-workload-chart')), findsOneWidget);
  });

  testWidgets(
      'home status cards refresh from server data without manual refresh',
      (tester) async {
    final repo = _LiveStatusRepo();
    await tester.pumpWidget(
      FoodexDriverApp(
        initialSession: session(DriverChannel.b2c),
        assignmentRepositoryFactory: (_) => repo,
      ),
    );
    await tester.pumpAndSettle();

    final failedCard = find.byKey(const Key('driver-home-status-failed'));
    final pendingCard = find.byKey(const Key('driver-home-pending-orders'));
    expect(
      find.descendant(of: pendingCard, matching: find.text('1')),
      findsOneWidget,
    );

    expect(
      find.descendant(of: failedCard, matching: find.text('0')),
      findsOneWidget,
    );

    repo.rows = const [
      DriverAssignment(
        id: 10,
        channel: DriverChannel.b2c,
        reference: 'LIVE-ACCEPTED',
        status: 'accepted',
      ),
      DriverAssignment(
        id: 11,
        channel: DriverChannel.b2c,
        reference: 'LIVE-FAILED',
        status: 'failed',
      ),
    ];

    final callsBeforePoll = repo.listCalls;
    await tester.pump(const Duration(seconds: 5));
    await tester.pump();

    expect(repo.listCalls, greaterThan(callsBeforePoll));
    expect(
      find.descendant(of: failedCard, matching: find.text('1')),
      findsOneWidget,
    );

    expect(
      find.descendant(of: pendingCard, matching: find.text('0')),
      findsOneWidget,
    );

    await tester.pumpWidget(const SizedBox.shrink());
    await tester.pump();
  });

  testWidgets('home shows a one-time popup when a new assigned order arrives',
      (tester) async {
    final repo = _LiveStatusRepo();
    await tester.pumpWidget(
      FoodexDriverApp(
        initialSession: session(DriverChannel.b2c),
        assignmentRepositoryFactory: (_) => repo,
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('driver-home-update-popup')), findsNothing);

    repo.rows = const [
      DriverAssignment(
        id: 9,
        channel: DriverChannel.b2c,
        reference: 'LIVE-PENDING',
        status: 'assigned',
      ),
      DriverAssignment(
        id: 10,
        channel: DriverChannel.b2c,
        reference: 'LIVE-ACCEPTED',
        status: 'accepted',
      ),
      DriverAssignment(
        id: 12,
        orderId: 5012,
        channel: DriverChannel.b2c,
        reference: 'NEW-5012',
        status: 'assigned',
        customerName: 'New Customer',
        address: 'New Address',
        currency: 'EGP',
        grandTotal: 125.50,
      ),
    ];

    await tester.pump(const Duration(seconds: 5));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 1200));
    await tester.pump();

    expect(find.byKey(const Key('driver-home-update-popup')), findsOneWidget);
    expect(find.text('طلب جديد تم إسناده لك'), findsOneWidget);
    expect(find.text('طلب NEW-5012'), findsOneWidget);
    expect(find.textContaining('New Customer'), findsOneWidget);
    expect(find.textContaining('125.50 EGP'), findsOneWidget);

    await tester.tap(find.byKey(const Key('driver-home-update-dismiss')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('driver-home-update-popup')), findsNothing);

    await tester.pump(const Duration(seconds: 5));
    await tester.pump();
    expect(find.byKey(const Key('driver-home-update-popup')), findsNothing);
  });

  testWidgets('home popup reports a changed existing assignment',
      (tester) async {
    final repo = _LiveStatusRepo();
    await tester.pumpWidget(
      FoodexDriverApp(
        initialSession: session(DriverChannel.b2c),
        assignmentRepositoryFactory: (_) => repo,
      ),
    );
    await tester.pumpAndSettle();

    repo.rows = const [
      DriverAssignment(
        id: 9,
        channel: DriverChannel.b2c,
        reference: 'LIVE-PENDING',
        status: 'assigned',
      ),
      DriverAssignment(
        id: 10,
        channel: DriverChannel.b2c,
        reference: 'LIVE-ACCEPTED',
        status: 'picked_up',
        customerName: 'Changed Customer',
      ),
    ];

    await tester.pump(const Duration(seconds: 5));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 1200));
    await tester.pump();

    expect(find.byKey(const Key('driver-home-update-popup')), findsOneWidget);
    expect(find.text('تم تحديث طلب مسند إليك'), findsOneWidget);
    expect(find.textContaining('تم الاستلام'), findsOneWidget);
    expect(find.textContaining('Changed Customer'), findsOneWidget);

    await tester.tap(find.byKey(const Key('driver-home-update-dismiss')));
    await tester.pumpAndSettle();
  });

  testWidgets('home popup reports when an assignment is removed',
      (tester) async {
    final repo = _LiveStatusRepo();
    await tester.pumpWidget(
      FoodexDriverApp(
        initialSession: session(DriverChannel.b2c),
        assignmentRepositoryFactory: (_) => repo,
      ),
    );
    await tester.pumpAndSettle();

    repo.rows = const [
      DriverAssignment(
        id: 10,
        channel: DriverChannel.b2c,
        reference: 'LIVE-ACCEPTED',
        status: 'accepted',
      ),
    ];

    await tester.pump(const Duration(seconds: 5));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 1200));
    await tester.pump();

    expect(find.byKey(const Key('driver-home-update-popup')), findsOneWidget);
    expect(find.text('تم سحب الطلب من حسابك'), findsOneWidget);
    expect(find.text('طلب LIVE-PENDING'), findsOneWidget);
    expect(find.byKey(const Key('driver-home-update-open')), findsNothing);

    await tester.tap(find.byKey(const Key('driver-home-update-dismiss')));
    await tester.pumpAndSettle();
  });

  testWidgets('driver home cards use the reduced-height layout',
      (tester) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(() {
      tester.view.resetPhysicalSize();
      tester.view.resetDevicePixelRatio();
    });

    await tester.pumpWidget(
      FoodexDriverApp(
        initialSession: session(DriverChannel.b2c),
        assignmentRepositoryFactory: (_) => _StatusRepo(),
      ),
    );
    await tester.pumpAndSettle();

    expect(
      tester
          .getSize(find.byKey(const Key('driver-home-workload-chart')))
          .height,
      closeTo(192, 0.01),
    );
    expect(
      tester
          .getSize(find.byKey(const Key('driver-home-pending-orders')))
          .height,
      lessThanOrEqualTo(46),
    );
    expect(
      tester
          .getSize(find.byKey(const Key('driver-home-status-accepted')))
          .height,
      lessThanOrEqualTo(64),
    );
  });

  testWidgets(
      'home shows authoritative unsettled collections and a 20 percent taller workload card',
      (tester) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(() {
      tester.view.resetPhysicalSize();
      tester.view.resetDevicePixelRatio();
    });

    await tester.pumpWidget(
      FoodexDriverApp(
        initialSession: session(DriverChannel.b2c),
        assignmentRepositoryFactory: (_) => _WalletStatusRepo(),
      ),
    );
    await tester.pumpAndSettle();

    expect(
      tester
          .getSize(find.byKey(const Key('driver-home-workload-chart')))
          .height,
      closeTo(192, 0.01),
    );
    expect(
      find.byKey(const Key('driver-home-unsettled-collections')),
      findsOneWidget,
    );
    expect(find.text('إجمالي التحصيلات غير المسوّاة'), findsOneWidget);
    expect(
      find.byKey(const Key('driver-home-unsettled-collections-value')),
      findsOneWidget,
    );
    expect(find.text('42.750 KWD'), findsOneWidget);
  });

  testWidgets(
      'home workload chart marks retained data stale after refresh failure',
      (tester) async {
    final repo = _StaleStatusRepo();
    await tester.pumpWidget(
      FoodexDriverApp(
        initialSession: session(DriverChannel.b2c),
        assignmentRepositoryFactory: (_) => repo,
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('driver-home-workload-chart')), findsOneWidget);
    expect(find.text('مقبول · 1'), findsOneWidget);
    expect(find.textContaining('آخر بيانات مؤكدة'), findsNothing);

    await tester.pump(const Duration(seconds: 5));
    await tester.pump();

    expect(repo.listCalls, greaterThan(1));
    expect(find.textContaining('آخر بيانات مؤكدة'), findsOneWidget);
    expect(find.text('مقبول · 1'), findsOneWidget);
  });

  testWidgets('home live refresh timer is stopped when the page is disposed',
      (tester) async {
    final repo = _LiveStatusRepo();
    await tester.pumpWidget(
      FoodexDriverApp(
        initialSession: session(DriverChannel.b2c),
        assignmentRepositoryFactory: (_) => repo,
      ),
    );
    await tester.pumpAndSettle();

    await tester.pumpWidget(const SizedBox.shrink());
    await tester.pump();
    final callsAfterDispose = repo.listCalls;

    await tester.pump(const Duration(seconds: 45));
    expect(repo.listCalls, callsAfterDispose);
  });

  testWidgets('B2B driver can navigate its own B2B delivery route',
      (tester) async {
    final repo = ChannelRecordingRepo();
    await tester.pumpWidget(
      FoodexDriverApp(
        initialSession: session(DriverChannel.b2b),
        assignmentRepositoryFactory: (_) => repo,
        initialRoute: DriverRoutes.b2bDeliveries,
      ),
    );
    await tester.pumpAndSettle();
    expect(repo.requestedChannel, DriverChannel.b2b);
    expect(find.byKey(const Key('driver-route-denied')), findsNothing);
    expect(find.byKey(const Key('driver-shell')), findsOneWidget);
  });

  testWidgets('B2C driver cannot navigate into B2B routes', (tester) async {
    await tester.pumpWidget(
      FoodexDriverApp(
        initialSession: session(DriverChannel.b2c),
        assignmentRepositoryFactory: (_) => EmptyRepo(),
        initialRoute: '/driver/b2b/deliveries',
      ),
    );
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('driver-route-denied')), findsOneWidget);
  });

  testWidgets('B2B driver cannot navigate into B2C routes', (tester) async {
    await tester.pumpWidget(
      FoodexDriverApp(
        initialSession: session(DriverChannel.b2b),
        assignmentRepositoryFactory: (_) => EmptyRepo(),
        initialRoute: DriverRoutes.b2cDeliveries,
      ),
    );
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('driver-route-denied')), findsOneWidget);
  });
}
