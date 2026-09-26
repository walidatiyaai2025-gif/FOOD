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
  Future<void> transition(int id, DriverChannel channel, String status) async {
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
    expect(repo.transitionedId, 7);
    expect(repo.transitionedChannel, DriverChannel.b2b);
    expect(repo.transitionedStatus, 'accepted');
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
