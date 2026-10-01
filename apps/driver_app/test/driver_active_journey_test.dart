import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_driver_app/core/auth/driver_session.dart';
import 'package:foodex_driver_app/features/delivery/active/driver_active_journey.dart';
import 'package:foodex_driver_app/features/delivery/driver_assignment_contract.dart';

class _FakeActiveRepo implements DriverAssignmentRepository {
  _FakeActiveRepo(this.current, {this.offline = false});

  DriverAssignment current;
  final bool offline;
  String? transitionedStatus;
  String? transitionedNote;
  int transitionCount = 0;

  @override
  Future<List<DriverAssignment>> list(DriverChannel channel) async {
    if (offline) throw const DriverOfflineException();
    return [current];
  }

  @override
  Future<void> transition(
    int id,
    DriverChannel channel,
    String status, {
    String? note,
    String? failureReason,
  }) async {
    transitionCount += 1;
    transitionedStatus = status;
    transitionedNote = note;

    final nextAvailable = switch (status) {
      'accepted' => const ['picked_up', 'out_for_delivery', 'failed'],
      'picked_up' => const ['out_for_delivery', 'failed'],
      'out_for_delivery' => const ['delivered', 'failed'],
      _ => const <String>[],
    };

    current = DriverAssignment(
      id: current.id,
      orderId: current.orderId,
      storeId: current.storeId,
      channel: current.channel,
      reference: current.reference,
      status: status,
      storeName: current.storeName,
      orderStatus: current.orderStatus,
      customerName: current.customerName,
      customerPhone: current.customerPhone,
      address: current.address,
      navigationLatitude: current.navigationLatitude,
      navigationLongitude: current.navigationLongitude,
      currency: current.currency,
      grandTotal: current.grandTotal,
      paymentMethod: current.paymentMethod,
      paymentStatus: current.paymentStatus,
      customerNote: current.customerNote,
      items: current.items,
      availableStatuses: nextAvailable,
      assignedAt: current.assignedAt,
      completedAt: current.completedAt,
      createdAt: current.createdAt,
      invoice: current.invoice,
    );
  }
}

Widget _host(
  DriverAssignmentRepository repository, {
  DriverActiveAssignmentCallback? onNavigationRequested,
  DriverActiveFailureCallback? onFailedDeliveryRequested,
  DriverActiveAssignmentCallback? onDeliveredRequested,
  Locale locale = const Locale('en'),
}) {
  return MaterialApp(
    locale: locale,
    supportedLocales: const [Locale('ar'), Locale('en')],
    localizationsDelegates: GlobalMaterialLocalizations.delegates,
    home: Scaffold(
      body: DriverActiveJourneyPage(
        channel: DriverChannel.b2c,
        repository: repository,
        onNavigationRequested:
            onNavigationRequested ?? (assignment) async {},
        onFailedDeliveryRequested:
            onFailedDeliveryRequested ?? (assignment, {note}) async {},
        onDeliveredRequested:
            onDeliveredRequested ?? (assignment) async {},
      ),
    ),
  );
}

void main() {
  testWidgets('assigned action is exposed only when server allows accepted',
      (tester) async {
    final repo = _FakeActiveRepo(
      const DriverAssignment(
        id: 1,
        channel: DriverChannel.b2c,
        reference: 'RET-1',
        status: 'assigned',
        availableStatuses: ['accepted'],
      ),
    );

    await tester.pumpWidget(_host(repo));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('driver-active-accept-1')), findsOneWidget);
    expect(find.byKey(const Key('driver-active-pickup-1')), findsNothing);
    expect(find.byKey(const Key('driver-active-start-1')), findsNothing);

    await tester.tap(find.byKey(const Key('driver-active-accept-1')));
    await tester.pumpAndSettle();

    expect(repo.transitionedStatus, 'accepted');
    expect(repo.transitionCount, 1);
    expect(find.byKey(const Key('driver-active-pickup-1')), findsOneWidget);
    expect(find.byKey(const Key('driver-active-start-1')), findsOneWidget);
  });

  testWidgets('accepted order start-delivery sheet submits note and reloads',
      (tester) async {
    final repo = _FakeActiveRepo(
      const DriverAssignment(
        id: 2,
        channel: DriverChannel.b2c,
        reference: 'RET-2',
        status: 'accepted',
        availableStatuses: ['picked_up', 'out_for_delivery', 'failed'],
      ),
    );

    await tester.pumpWidget(_host(repo));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('driver-active-start-2')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('driver-active-start-note')), findsOneWidget);
    expect(find.byKey(const Key('driver-active-failed-2')), findsOneWidget);
    expect(
      find.byKey(const Key('driver-active-confirm-start-2')),
      findsOneWidget,
    );

    await tester.enterText(
      find.byKey(const Key('driver-active-start-note')),
      'Leaving store now',
    );
    await tester.tap(
      find.byKey(const Key('driver-active-confirm-start-2')),
    );
    await tester.pumpAndSettle();

    expect(repo.transitionedStatus, 'out_for_delivery');
    expect(repo.transitionedNote, 'Leaving store now');
    expect(
      find.byKey(const Key('driver-active-delivered-2')),
      findsOneWidget,
    );
  });

  testWidgets('failed delivery is delegated to completion lane with note',
      (tester) async {
    final repo = _FakeActiveRepo(
      const DriverAssignment(
        id: 3,
        channel: DriverChannel.b2c,
        reference: 'RET-3',
        status: 'picked_up',
        availableStatuses: ['out_for_delivery', 'failed'],
      ),
    );
    DriverAssignment? failedAssignment;
    String? failedNote;

    await tester.pumpWidget(
      _host(
        repo,
        onFailedDeliveryRequested: (assignment, {note}) async {
          failedAssignment = assignment;
          failedNote = note;
        },
      ),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('driver-active-start-3')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const Key('driver-active-start-note')),
      'Customer asked to cancel',
    );
    await tester.tap(find.byKey(const Key('driver-active-failed-3')));
    await tester.pumpAndSettle();

    expect(failedAssignment?.id, 3);
    expect(failedNote, 'Customer asked to cancel');
    expect(repo.transitionedStatus, isNull);
  });

  testWidgets('out-for-delivery delegates delivered proof flow', (tester) async {
    final repo = _FakeActiveRepo(
      const DriverAssignment(
        id: 4,
        channel: DriverChannel.b2c,
        reference: 'RET-4',
        status: 'out_for_delivery',
        availableStatuses: ['delivered', 'failed'],
      ),
    );
    int? deliveredId;

    await tester.pumpWidget(
      _host(
        repo,
        onDeliveredRequested: (assignment) async {
          deliveredId = assignment.id;
        },
      ),
    );
    await tester.pumpAndSettle();

    expect(
      find.byKey(const Key('driver-active-delivered-4')),
      findsOneWidget,
    );
    await tester.tap(find.byKey(const Key('driver-active-delivered-4')));
    await tester.pumpAndSettle();

    expect(deliveredId, 4);
    expect(repo.transitionedStatus, isNull);
  });

  testWidgets('detail renders authoritative data and immutable navigation target',
      (tester) async {
    final repo = _FakeActiveRepo(
      const DriverAssignment(
        id: 5,
        orderId: 55,
        storeId: 8,
        channel: DriverChannel.b2c,
        reference: 'RET-55',
        status: 'accepted',
        storeName: 'Store Eight',
        customerName: 'Customer Name',
        customerPhone: '5551234',
        address: 'Immutable delivery snapshot',
        navigationLatitude: 29.3759,
        navigationLongitude: 47.9774,
        currency: 'KWD',
        grandTotal: 12.5,
        paymentMethod: 'cash_on_delivery',
        paymentStatus: 'pending',
        customerNote: 'Call on arrival',
        items: [
          DriverOrderItem(
            name: 'Product A',
            quantity: 2,
            lineTotal: 12.5,
          ),
        ],
        availableStatuses: ['picked_up'],
      ),
    );
    int? navigatedId;

    await tester.pumpWidget(
      _host(
        repo,
        onNavigationRequested: (assignment) async {
          navigatedId = assignment.id;
        },
      ),
    );
    await tester.pumpAndSettle();

    await tester.tap(
      find.byKey(const Key('driver-active-assignment-5')),
    );
    await tester.pumpAndSettle();

    final detail = find.byKey(const Key('driver-active-detail-5'));
    expect(detail, findsOneWidget);
    expect(
      find.descendant(of: detail, matching: find.text('Store Eight')),
      findsOneWidget,
    );
    expect(
      find.descendant(of: detail, matching: find.text('Customer Name')),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: detail,
        matching: find.text('Immutable delivery snapshot'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(of: detail, matching: find.text('Product A')),
      findsOneWidget,
    );

    await tester.tap(find.byKey(const Key('driver-active-navigate-5')));
    await tester.pumpAndSettle();
    expect(navigatedId, 5);
  });

  testWidgets('offline state stays explicit and retryable', (tester) async {
    final repo = _FakeActiveRepo(
      const DriverAssignment(
        id: 6,
        channel: DriverChannel.b2c,
        reference: 'RET-6',
        status: 'assigned',
      ),
      offline: true,
    );

    await tester.pumpWidget(_host(repo));
    await tester.pumpAndSettle();

    expect(find.text('No connection. Try again when the network is back.'),
        findsOneWidget);
    expect(find.text('Retry'), findsOneWidget);
  });

  testWidgets('Arabic host keeps the new journey RTL', (tester) async {
    final repo = _FakeActiveRepo(
      const DriverAssignment(
        id: 7,
        channel: DriverChannel.b2c,
        reference: 'RET-7',
        status: 'assigned',
        availableStatuses: ['accepted'],
      ),
    );

    await tester.pumpWidget(
      _host(repo, locale: const Locale('ar')),
    );
    await tester.pumpAndSettle();

    expect(
      Directionality.of(
        tester.element(
          find.byKey(const Key('driver-active-assignment-list')),
        ),
      ),
      TextDirection.rtl,
    );
  });
}
