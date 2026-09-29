import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_driver_app/core/auth/driver_session.dart';
import 'package:foodex_driver_app/features/tasks/driver_journey.dart';

class FakeRepo implements DriverAssignmentRepository {
  FakeRepo(this.rows, {this.offline = false});
  final List<DriverAssignment> rows;
  final bool offline;
  int? transitionedId;
  String? transitionedStatus;
  DriverChannel? transitionedChannel;

  @override
  Future<List<DriverAssignment>> list(DriverChannel channel) async {
    if (offline) throw const DriverOfflineException();
    return rows;
  }

  @override
  Future<void> transition(int id, DriverChannel channel, String status, {String? note}) async {
    transitionedId = id;
    transitionedChannel = channel;
    transitionedStatus = status;
  }
}

void main() {
  testWidgets('B2C journey never renders B2B assignments', (tester) async {
    final repo = FakeRepo(const [
      DriverAssignment(
        id: 1,
        channel: DriverChannel.b2c,
        reference: 'B2C-1',
        status: 'assigned',
      ),
      DriverAssignment(
        id: 2,
        channel: DriverChannel.b2b,
        reference: 'B2B-1',
        status: 'assigned',
      ),
      DriverAssignment(
        id: 3,
        channel: DriverChannel.b2c,
        reference: 'B2C-OLD',
        status: 'reassigned',
      ),
    ]);
    await tester.pumpWidget(
      MaterialApp(
        home: DriverJourneyPage(
          channel: DriverChannel.b2c,
          repository: repo,
        ),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('B2C-1'), findsOneWidget);
    expect(find.text('B2B-1'), findsNothing);
    expect(find.text('B2C-OLD'), findsNothing);
  });

  testWidgets('detail action uses backend supplied status and channel boundary',
      (tester) async {
    final repo = FakeRepo(const [
      DriverAssignment(
        id: 7,
        channel: DriverChannel.b2b,
        reference: 'B2B-7',
        status: 'assigned',
        availableStatuses: ['accepted'],
      ),
    ]);
    await tester.pumpWidget(
      MaterialApp(
        home: DriverJourneyPage(
          channel: DriverChannel.b2b,
          repository: repo,
        ),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('assignment-7')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('assignment-detail-7')), findsOneWidget);
    await tester.tap(find.byKey(const Key('assignment-status-7-accepted')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('driver-status-note-accepted')), findsOneWidget);
    await tester.enterText(
      find.byKey(const Key('driver-status-note-accepted')),
      'Gate confirmed',
    );
    await tester.tap(find.byKey(const Key('driver-status-confirm-accepted')));
    await tester.pumpAndSettle();
    expect(repo.transitionedId, 7);
    expect(repo.transitionedChannel, DriverChannel.b2b);
    expect(repo.transitionedStatus, 'accepted');
  });

  testWidgets('driver can open assigned invoice receipt from order detail',
      (tester) async {
    final repo = FakeRepo(const [
      DriverAssignment(
        id: 11,
        channel: DriverChannel.b2c,
        reference: 'B2C-11',
        status: 'accepted',
        invoice: DriverInvoice(
          id: 91,
          number: 'INV-B2C-11',
          status: 'issued',
          currency: 'EGP',
          subtotal: 25,
          discountTotal: 2,
          deliveryTotal: 3,
          taxTotal: 1,
          grandTotal: 27,
          paymentMethod: 'cash_on_delivery',
          paymentStatus: 'pending',
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
    ]);

    await tester.pumpWidget(
      MaterialApp(
        home: DriverJourneyPage(
          channel: DriverChannel.b2c,
          repository: repo,
        ),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('assignment-11')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('driver-open-invoice-11')), findsOneWidget);
    await tester.tap(find.byKey(const Key('driver-open-invoice-11')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('driver-invoice-detail-91')), findsOneWidget);
    expect(find.textContaining('INV-B2C-11'), findsWidgets);
    expect(find.textContaining('27.000 EGP'), findsWidgets);
  });

  testWidgets('push-targeted assignment opens its detail after load', (tester) async {
    final repo = FakeRepo(const [
      DriverAssignment(
        id: 21,
        channel: DriverChannel.b2c,
        reference: 'B2C-21',
        status: 'assigned',
      ),
      DriverAssignment(
        id: 22,
        channel: DriverChannel.b2c,
        reference: 'B2C-22',
        status: 'assigned',
      ),
    ]);

    await tester.pumpWidget(
      MaterialApp(
        home: DriverJourneyPage(
          channel: DriverChannel.b2c,
          repository: repo,
          focusAssignmentId: 22,
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('assignment-detail-22')), findsOneWidget);
    expect(find.byKey(const Key('assignment-detail-21')), findsNothing);
  });

  testWidgets('driver can launch immutable order coordinates from detail',
      (tester) async {
    final repo = FakeRepo(const [
      DriverAssignment(
        id: 31,
        channel: DriverChannel.b2c,
        reference: 'B2C-31',
        status: 'assigned',
        address: 'Snapshot Street',
        navigationLatitude: 29.3759,
        navigationLongitude: 47.9774,
      ),
    ]);
    double? launchedLat;
    double? launchedLng;

    await tester.pumpWidget(
      MaterialApp(
        home: DriverJourneyPage(
          channel: DriverChannel.b2c,
          repository: repo,
          navigationLauncher: (latitude, longitude) async {
            launchedLat = latitude;
            launchedLng = longitude;
            return true;
          },
        ),
      ),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('assignment-31')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('driver-navigate-31')), findsOneWidget);
    await tester.tap(find.byKey(const Key('driver-navigate-31')));
    await tester.pumpAndSettle();

    expect(launchedLat, 29.3759);
    expect(launchedLng, 47.9774);
  });

  testWidgets('empty and offline states are explicit', (tester) async {
    await tester.pumpWidget(
      MaterialApp(
        home: DriverJourneyPage(
          channel: DriverChannel.b2b,
          repository: FakeRepo(const []),
        ),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('driver-empty')), findsOneWidget);
    await tester.pumpWidget(const SizedBox());
    await tester.pump();
    await tester.pumpWidget(
      MaterialApp(
        home: DriverJourneyPage(
          channel: DriverChannel.b2b,
          repository: FakeRepo(const [], offline: true),
        ),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('driver-offline')), findsOneWidget);
  });

  testWidgets('Arabic locale renders RTL', (tester) async {
    await tester.pumpWidget(
      MaterialApp(
        locale: const Locale('ar'),
        supportedLocales: const [Locale('ar'), Locale('en')],
        localizationsDelegates: GlobalMaterialLocalizations.delegates,
        home: DriverJourneyPage(
          channel: DriverChannel.b2c,
          repository: FakeRepo(const []),
        ),
      ),
    );
    await tester.pumpAndSettle();
    expect(
      Directionality.of(tester.element(find.byType(Scaffold))),
      TextDirection.rtl,
    );
  });
}
