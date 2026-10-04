import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_driver_app/core/auth/driver_session.dart';
import 'package:foodex_driver_app/features/delivery/active/driver_active_journey.dart';
import 'package:foodex_driver_app/features/delivery/driver_assignment_contract.dart';

class _FakeActiveRepo
    implements DriverAssignmentRepository, DriverInvoiceDocumentRepository {
  _FakeActiveRepo(this.current, {this.offline = false});

  DriverAssignment current;
  final bool offline;
  String? transitionedStatus;
  String? transitionedNote;
  int transitionCount = 0;
  int? downloadedInvoiceAssignmentId;
  String? downloadedInvoiceLocale;
  final List<String> transitionedStatuses = <String>[];

  @override
  Future<List<DriverAssignment>> list(DriverChannel channel) async {
    if (offline) throw const DriverOfflineException();
    return [current];
  }

  @override
  Future<List<int>> downloadInvoicePdf(
    int assignmentId, {
    required String locale,
  }) async {
    downloadedInvoiceAssignmentId = assignmentId;
    downloadedInvoiceLocale = locale;
    return const [37, 80, 68, 70];
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
    transitionedStatuses.add(status);
    transitionedStatus = status;
    transitionedNote = note;

    final nextAvailable = switch (status) {
      'accepted' => const ['picked_up', 'failed'],
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
      settlement: current.settlement,
      items: current.items,
      availableStatuses: nextAvailable,
      assignedAt: current.assignedAt,
      completedAt: current.completedAt,
      createdAt: current.createdAt,
      invoice: current.invoice,
    );
  }
}

class _StaticActiveRepo implements DriverAssignmentRepository {
  const _StaticActiveRepo(this.rows);

  final List<DriverAssignment> rows;

  @override
  Future<List<DriverAssignment>> list(DriverChannel channel) async => rows;

  @override
  Future<void> transition(
    int id,
    DriverChannel channel,
    String status, {
    String? note,
    String? failureReason,
  }) async {}
}

Widget _host(
  DriverAssignmentRepository repository, {
  DriverActiveAssignmentCallback? onNavigationRequested,
  DriverActiveFailureCallback? onFailedDeliveryRequested,
  DriverActiveAssignmentCallback? onDeliveredRequested,
  Locale locale = const Locale('en'),
  DriverChannel channel = DriverChannel.b2c,
  int? focusAssignmentId,
  String? initialAssignmentStatus,
}) {
  return MaterialApp(
    locale: locale,
    supportedLocales: const [Locale('ar'), Locale('en')],
    localizationsDelegates: GlobalMaterialLocalizations.delegates,
    home: Scaffold(
      body: DriverActiveJourneyPage(
        channel: channel,
        repository: repository,
        onNavigationRequested:
            onNavigationRequested ?? (assignment) async {},
        onFailedDeliveryRequested:
            onFailedDeliveryRequested ?? (assignment, {note}) async {},
        onDeliveredRequested:
            onDeliveredRequested ?? (assignment) async {},
        focusAssignmentId: focusAssignmentId,
        initialAssignmentStatus: initialAssignmentStatus,
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
    expect(find.byKey(const Key('driver-active-pickup-1')), findsNothing);
    expect(
      find.byKey(const Key('driver-active-card-failed-1')),
      findsOneWidget,
    );
    expect(find.text('Delivery failed'), findsOneWidget);
    expect(find.byKey(const Key('driver-active-start-1')), findsNothing);
  });

  for (final channel in [DriverChannel.b2c, DriverChannel.b2b]) {
    testWidgets(
        'receive auto-starts delivery for ${channel.name} after accepted',
        (tester) async {
      final repo = _FakeActiveRepo(
        DriverAssignment(
          id: channel == DriverChannel.b2c ? 12 : 13,
          channel: channel,
          reference: channel == DriverChannel.b2c ? 'RET-12' : 'B2B-13',
          status: 'accepted',
          availableStatuses: const ['picked_up', 'failed'],
        ),
      );
      final id = repo.current.id;

      await tester.pumpWidget(_host(repo, channel: channel));
      await tester.pumpAndSettle();

      expect(
        find.byKey(Key('driver-active-pickup-$id')),
        findsNothing,
      );
      expect(
        find.byKey(Key('driver-active-card-failed-$id')),
        findsOneWidget,
      );
      expect(
        find.byKey(Key('driver-active-start-$id')),
        findsNothing,
      );

      await tester.tap(find.byKey(Key('driver-active-assignment-$id')));
      await tester.pumpAndSettle();
      expect(
        find.byKey(Key('driver-detail-pickup-$id')),
        findsOneWidget,
      );
      await tester.tap(find.byKey(Key('driver-detail-pickup-$id')));
      await tester.pumpAndSettle();

      expect(repo.transitionedStatuses, ['picked_up', 'out_for_delivery']);
      expect(repo.transitionCount, 2);
      expect(repo.current.status, 'out_for_delivery');
      expect(
        find.byKey(Key('driver-active-delivered-$id')),
        findsOneWidget,
      );
    });
  }

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

  testWidgets(
      'Assignment Details drives authoritative accepted pickup and delivery-start states',
      (tester) async {
    final repo = _FakeActiveRepo(
      const DriverAssignment(
        id: 50,
        channel: DriverChannel.b2c,
        reference: 'RET-50',
        status: 'assigned',
        availableStatuses: ['accepted'],
      ),
    );

    await tester.pumpWidget(_host(repo));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('driver-active-assignment-50')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('driver-detail-accept-50')), findsOneWidget);

    await tester.tap(find.byKey(const Key('driver-detail-accept-50')));
    await tester.pumpAndSettle();
    expect(repo.current.status, 'accepted');
    expect(find.byKey(const Key('driver-active-detail-50')), findsOneWidget);
    expect(find.byKey(const Key('driver-detail-pickup-50')), findsOneWidget);
    expect(find.byKey(const Key('driver-detail-start-50')), findsNothing);
    expect(find.byKey(const Key('driver-detail-failed-50')), findsOneWidget);

    await tester.tap(find.byKey(const Key('driver-detail-pickup-50')));
    await tester.pumpAndSettle();
    expect(repo.transitionedStatuses, ['accepted', 'picked_up', 'out_for_delivery']);
    expect(repo.current.status, 'out_for_delivery');
    expect(find.byKey(const Key('driver-detail-start-50')), findsNothing);
    expect(find.byKey(const Key('driver-active-detail-50')), findsOneWidget);
    expect(find.byKey(const Key('driver-detail-delivered-50')), findsOneWidget);
    expect(find.byKey(const Key('driver-detail-failed-50')), findsOneWidget);
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
            sku: 'PROD-A',
            variant: 'Large',
            unit: 'Box',
            note: 'Handle with care',
            quantity: 2,
            unitPrice: 6.25,
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
      find.byKey(const Key('driver-detail-address-card-5')),
      findsOneWidget,
    );
    final detailScrollable = find.descendant(
      of: detail,
      matching: find.byType(Scrollable),
    );
    expect(detailScrollable, findsOneWidget);

    await tester.scrollUntilVisible(
      find.text('Product A'),
      160,
      scrollable: detailScrollable,
    );
    await tester.pumpAndSettle();
    expect(
      find.descendant(of: detail, matching: find.text('Product A')),
      findsOneWidget,
    );
    expect(find.descendant(of: detail, matching: find.text('PROD-A')), findsOneWidget);
    expect(
      find.descendant(
        of: detail,
        matching: find.textContaining('Variant / option: Large'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(of: detail, matching: find.textContaining('Unit: Box')),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: detail,
        matching: find.textContaining('Line note: Handle with care'),
      ),
      findsOneWidget,
    );
    expect(
      find.byKey(const Key('driver-detail-item-image-5-0')),
      findsOneWidget,
    );

    await tester.drag(detailScrollable, const Offset(0, 600));
    await tester.pumpAndSettle();
    expect(
      find.byKey(const Key('driver-active-navigate-5')),
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

  testWidgets('new journey keeps the backend channel boundary',
      (tester) async {
    const repo = _StaticActiveRepo([
      DriverAssignment(
        id: 18,
        channel: DriverChannel.b2c,
        reference: 'B2C-18',
        status: 'assigned',
      ),
      DriverAssignment(
        id: 19,
        channel: DriverChannel.b2b,
        reference: 'B2B-19',
        status: 'assigned',
      ),
    ]);

    await tester.pumpWidget(_host(repo));
    await tester.pumpAndSettle();

    expect(find.text('B2C-18'), findsOneWidget);
    expect(find.text('B2B-19'), findsNothing);
  });

  testWidgets('exact status route filters the authoritative assignment list',
      (tester) async {
    const repo = _StaticActiveRepo([
      DriverAssignment(
        id: 20,
        channel: DriverChannel.b2c,
        reference: 'ACCEPTED-20',
        status: 'accepted',
      ),
      DriverAssignment(
        id: 21,
        channel: DriverChannel.b2c,
        reference: 'OUT-21',
        status: 'out_for_delivery',
      ),
      DriverAssignment(
        id: 22,
        channel: DriverChannel.b2c,
        reference: 'DONE-22',
        status: 'delivered',
      ),
    ]);

    await tester.pumpWidget(
      _host(repo, initialAssignmentStatus: 'out_for_delivery'),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('driver-active-status-filter')), findsOneWidget);
    expect(find.text('OUT-21'), findsOneWidget);
    expect(find.text('ACCEPTED-20'), findsNothing);
    expect(find.text('DONE-22'), findsNothing);
  });

  testWidgets('push focus refetches and opens only the exact assignment',
      (tester) async {
    const repo = _StaticActiveRepo([
      DriverAssignment(
        id: 30,
        channel: DriverChannel.b2c,
        reference: 'PUSH-30',
        status: 'assigned',
      ),
      DriverAssignment(
        id: 31,
        channel: DriverChannel.b2c,
        reference: 'PUSH-31',
        status: 'assigned',
      ),
    ]);

    await tester.pumpWidget(_host(repo, focusAssignmentId: 31));
    await tester.pumpAndSettle();

    expect(find.text('PUSH-30'), findsNothing);
    expect(find.text('PUSH-31'), findsWidgets);
    expect(find.byKey(const Key('driver-active-detail-31')), findsOneWidget);
  });

  testWidgets('new detail preserves assigned invoice access', (tester) async {
    final repo = _FakeActiveRepo(
      const DriverAssignment(
        id: 40,
        channel: DriverChannel.b2c,
        reference: 'INV-ORDER-40',
        status: 'accepted',
        settlement: DriverSettlement(
          currency: 'KWD',
          orderTotal: 100,
          balanceApplied: 30,
          paidAmount: 0,
          remainingAmount: 70,
          remainderMethod: 'cash_on_delivery',
          paymentState: 'partially_settled',
          amountToCollectNow: 70,
          invoiceOutstandingAmount: 70,
        ),
        invoice: DriverInvoice(
          id: 91,
          number: 'INV-B2C-40',
          status: 'issued',
          currency: 'KWD',
          subtotal: 25,
          grandTotal: 27,
          deliveryTotal: 2,
          paymentMethod: 'cash_on_delivery',
          paymentStatus: 'pending',
          outstandingAmount: 70,
          downloadPath: '/api/v1/driver/assignments/40/invoice/download',
          items: [
            DriverOrderItem(
              name: 'Rice',
              sku: 'RICE-1',
              quantity: 2,
              lineTotal: 25,
            ),
          ],
        ),
      ),
    );

    await tester.pumpWidget(_host(repo));
    await tester.pumpAndSettle();
    await tester.tap(
      find.byKey(const Key('driver-active-assignment-40')),
    );
    await tester.pumpAndSettle();

    expect(
      find.byKey(const Key('driver-active-open-invoice-40')),
      findsOneWidget,
    );
    expect(
      find.byKey(const Key('driver-active-download-invoice-40')),
      findsOneWidget,
    );
    expect(find.byKey(const Key('driver-settlement-40')), findsOneWidget);
    expect(find.text('Customer balance applied'), findsOneWidget);
    expect(find.text('Cash on delivery'), findsWidgets);
    expect(find.text('Partially settled'), findsOneWidget);
    expect(find.text('Amount to collect now'), findsOneWidget);
    expect(find.text('70.000 KWD'), findsWidgets);
    await tester.tap(
      find.byKey(const Key('driver-active-open-invoice-40')),
    );
    await tester.pumpAndSettle();

    expect(
      find.byKey(const Key('driver-active-invoice-detail-91')),
      findsOneWidget,
    );
    expect(find.textContaining('INV-B2C-40'), findsWidgets);
    expect(find.textContaining('27.000 KWD'), findsWidgets);
  });

  testWidgets('account debt remainder explicitly tells driver not to collect',
      (tester) async {
    final repo = _FakeActiveRepo(
      const DriverAssignment(
        id: 41,
        channel: DriverChannel.b2c,
        reference: 'DEBT-41',
        status: 'accepted',
        settlement: DriverSettlement(
          currency: 'KWD',
          orderTotal: 100,
          balanceApplied: 30,
          paidAmount: 0,
          remainingAmount: 70,
          remainderMethod: 'account_debt',
          paymentState: 'partially_settled',
          amountToCollectNow: 0,
          invoiceOutstandingAmount: 70,
        ),
      ),
    );

    await tester.pumpWidget(_host(repo));
    await tester.pumpAndSettle();
    await tester.tap(
      find.byKey(const Key('driver-active-assignment-41')),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('driver-settlement-41')), findsOneWidget);
    expect(find.text('Account debt'), findsOneWidget);
    expect(find.text('Do not collect'), findsOneWidget);
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
