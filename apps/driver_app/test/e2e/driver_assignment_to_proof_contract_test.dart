import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_driver_app/core/auth/driver_session.dart';
import 'package:foodex_driver_app/features/delivery/active/driver_active_journey.dart';
import 'package:foodex_driver_app/features/delivery/completion/driver_completion_contract.dart';
import 'package:foodex_driver_app/features/delivery/driver_assignment_contract.dart';

class _JourneyRepository implements DriverAssignmentRepository {
  DriverAssignment current = const DriverAssignment(
    id: 693,
    orderId: 1693,
    storeId: 7,
    channel: DriverChannel.b2c,
    reference: 'JOURNEY-1693',
    status: 'assigned',
    availableStatuses: ['accepted'],
  );

  final List<String> transitions = <String>[];
  String? startNote;

  @override
  Future<List<DriverAssignment>> list(DriverChannel channel) async => [current];

  @override
  Future<void> transition(
    int id,
    DriverChannel channel,
    String status, {
    String? note,
    String? failureReason,
  }) async {
    expect(id, current.id);
    expect(channel, current.channel);
    transitions.add(status);
    if (status == 'out_for_delivery') startNote = note;

    final availableStatuses = switch (status) {
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
      availableStatuses: availableStatuses,
    );
  }
}

Widget _host(
  DriverAssignmentRepository repository, {
  required DriverActiveAssignmentCallback onDelivered,
}) =>
    MaterialApp(
      locale: const Locale('en'),
      supportedLocales: const [Locale('ar'), Locale('en')],
      localizationsDelegates: GlobalMaterialLocalizations.delegates,
      home: Scaffold(
        body: DriverActiveJourneyPage(
          channel: DriverChannel.b2c,
          repository: repository,
          onNavigationRequested: (assignment) async {},
          onFailedDeliveryRequested: (assignment, {note}) async {},
          onDeliveredRequested: onDelivered,
        ),
      ),
    );

Future<void> _openCardActions(WidgetTester tester, int assignmentId) async {
  await tester.tap(
    find.byKey(Key('driver-active-actions-$assignmentId')),
  );
  await tester.pumpAndSettle();
}

void main() {
  testWidgets(
    'assignment UI follows backend actions through start delivery and proof handoff',
    (tester) async {
      final repository = _JourneyRepository();
      DriverCompletionDraft? completion;

      await tester.pumpWidget(
        _host(
          repository,
          onDelivered: (assignment) async {
            expect(assignment.status, 'out_for_delivery');
            completion = const DriverCompletionDraft(
              target: DriverCompletionTarget.delivered,
              note: 'Handed to customer',
              proof: DriverProofAttachment(
                path: '/tmp/journey-proof.jpg',
                fileName: 'journey-proof.jpg',
                byteLength: 2048,
                mimeType: 'image/jpeg',
              ),
            );
          },
        ),
      );
      await tester.pumpAndSettle();

      expect(
        find.byKey(const Key('driver-active-actions-693')),
        findsOneWidget,
      );
      await _openCardActions(tester, 693);
      expect(find.byKey(const Key('driver-active-accept-693')), findsOneWidget);
      expect(find.byKey(const Key('driver-active-start-693')), findsNothing);

      await tester.tap(find.byKey(const Key('driver-active-accept-693')));
      await tester.pumpAndSettle();

      expect(repository.transitions, ['accepted']);
      expect(find.byKey(const Key('driver-active-pickup-693')), findsNothing);
      await _openCardActions(tester, 693);
      expect(
        find.byKey(const Key('driver-active-card-failed-693')),
        findsOneWidget,
      );
      expect(find.byKey(const Key('driver-active-start-693')), findsNothing);
      await tester.tapAt(const Offset(4, 4));
      await tester.pumpAndSettle();

      await tester.tap(
        find.byKey(const Key('driver-active-assignment-693')),
      );
      await tester.pumpAndSettle();
      expect(
        find.byKey(const Key('driver-detail-pickup-693')),
        findsOneWidget,
      );
      await tester.tap(find.byKey(const Key('driver-detail-pickup-693')));
      await tester.pumpAndSettle();

      expect(
        repository.transitions,
        ['accepted', 'picked_up', 'out_for_delivery'],
      );
      expect(find.byKey(const Key('driver-active-start-693')), findsNothing);
      expect(repository.startNote, isNull);
      expect(find.byKey(const Key('driver-detail-delivered-693')), findsOneWidget);

      await tester.ensureVisible(
        find.byKey(const Key('driver-detail-delivered-693')),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('driver-detail-delivered-693')));
      await tester.pumpAndSettle();

      expect(completion, isNotNull);
      expect(completion!.validationError, isNull);
      expect(completion!.canSubmit, isTrue);
    },
  );

  test('failed completion keeps the authoritative backend reason vocabulary', () {
    const failed = DriverCompletionDraft(
      target: DriverCompletionTarget.failed,
      failureReason: DriverFailureReason.customerNoAnswer,
      note: 'Called twice',
    );

    expect(failed.validationError, isNull);
    expect(DriverFailureReason.values, contains('customer_no_answer'));
    expect(DriverFailureReason.values, contains('wrong_address'));
    expect(DriverFailureReason.values, contains('customer_refused'));
    expect(DriverFailureReason.values, contains('customer_absent'));
    expect(DriverFailureReason.values, contains('payment_issue'));
    expect(DriverFailureReason.values, contains('order_issue'));
    expect(DriverFailureReason.values, contains('other'));
  });
}
