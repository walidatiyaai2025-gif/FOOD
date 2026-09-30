import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_driver_app/app.dart';
import 'package:foodex_driver_app/core/auth/driver_session.dart';
import 'package:foodex_driver_app/core/preview/driver_preview_context.dart';
import 'package:foodex_driver_app/features/tasks/driver_journey.dart';
import 'package:foodex_driver_app/navigation.dart';

class _PreviewRepo implements DriverAssignmentRepository {
  _PreviewRepo(this.rows);

  final List<DriverAssignment> rows;
  int transitionCount = 0;

  @override
  Future<List<DriverAssignment>> list(DriverChannel channel) async => rows;

  @override
  Future<void> transition(
    int id,
    DriverChannel channel,
    String status, {
    String? note,
    String? failureReason,
  }) async {
    transitionCount++;
  }
}

DriverSession _session(DriverChannel channel) => DriverSession(
      token: 'preview-resolved-session',
      name: 'Preview Driver',
      email: 'preview-driver@example.test',
      locale: 'en',
      channel: channel,
    );

void main() {
  test('retail preview context enforces exact store and channel scope', () {
    const preview = DriverPreviewContext(
      channel: DriverChannel.b2c,
      storeId: 41,
    );

    expect(
      preview.allowsAssignment(
        assignmentChannel: DriverChannel.b2c,
        assignmentStoreId: 41,
      ),
      isTrue,
    );
    expect(
      preview.allowsAssignment(
        assignmentChannel: DriverChannel.b2c,
        assignmentStoreId: 42,
      ),
      isFalse,
    );
    expect(
      preview.allowsAssignment(
        assignmentChannel: DriverChannel.b2b,
        assignmentStoreId: 41,
      ),
      isFalse,
    );
    expect(preview.mutationsAllowed, isFalse);
    expect(preview.nativeNavigationEnabled, isFalse);
  });

  test('resolved #500 driver context never becomes a normal auth token', () {
    final preview = DriverPreviewContext.fromResolvedSession({
      'session_id': 'preview-session-id',
      'target_type': 'driver',
      'channel': 'b2b',
      'store_id': 9,
      'mode': 'read_only',
      'read_only': true,
      'audit_correlation_id': 'audit-id',
      'expires_at': '2026-09-30T06:30:00+03:00',
      'target': {
        'user_id': 17,
        'driver_id': 23,
        'name': 'Wholesale Driver',
        'locale': 'ar',
      },
    });

    expect(preview.channel, DriverChannel.b2b);
    expect(preview.storeId, 9);
    expect(preview.driverId, 23);
    expect(preview.auditCorrelationId, 'audit-id');
    expect(preview.runtimeIdentity.token, isEmpty);
    expect(preview.runtimeIdentity.channel, DriverChannel.b2b);
  });

  testWidgets('real B2C journey filters preview to the selected retail store',
      (tester) async {
    final repo = _PreviewRepo(const [
      DriverAssignment(
        id: 1,
        storeId: 41,
        channel: DriverChannel.b2c,
        reference: 'STORE-41',
        status: 'assigned',
      ),
      DriverAssignment(
        id: 2,
        storeId: 42,
        channel: DriverChannel.b2c,
        reference: 'STORE-42',
        status: 'assigned',
      ),
      DriverAssignment(
        id: 3,
        channel: DriverChannel.b2b,
        reference: 'WHOLESALE',
        status: 'assigned',
      ),
    ]);

    await tester.pumpWidget(
      FoodexDriverApp.preview(
        locale: const Locale('en'),
        initialRoute: DriverRoutes.b2cDeliveries,
        assignmentRepository: repo,
        previewContext: const DriverPreviewContext(
          channel: DriverChannel.b2c,
          storeId: 41,
          configurationRevision: 'draft-17',
          runtimeVersion: 'preview-web-1',
        ),
        previewViewport: const DriverPreviewViewport(
          profile: 'iphone_common',
          platform: 'ios',
          width: 390,
          height: 844,
          safeAreaTop: 47,
          safeAreaRight: 0,
          safeAreaBottom: 34,
          safeAreaLeft: 0,
          textScale: 1.2,
          orientation: 'portrait',
          keyboardInsetBottom: 260,
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('STORE-41'), findsOneWidget);
    expect(find.text('STORE-42'), findsNothing);
    expect(find.text('WHOLESALE'), findsNothing);
    expect(find.textContaining('Safe preview'), findsOneWidget);
    expect(find.textContaining('draft-17'), findsOneWidget);

    final scaffold = find.byType(Scaffold).first;
    final media = MediaQuery.of(tester.element(scaffold));
    expect(media.size, const Size(390, 844));
    expect(media.padding.top, 47);
    expect(media.padding.bottom, 34);
    expect(media.viewInsets.bottom, 260);
    expect(media.textScaler.scale(10), 12);
  });

  testWidgets('safe preview renders lifecycle action but never mutates production',
      (tester) async {
    final repo = _PreviewRepo(const [
      DriverAssignment(
        id: 7,
        storeId: 41,
        channel: DriverChannel.b2c,
        reference: 'SAFE-7',
        status: 'assigned',
        availableStatuses: ['accepted'],
      ),
    ]);

    await tester.pumpWidget(
      MaterialApp(
        locale: const Locale('en'),
        home: DriverJourneyPage(
          channel: DriverChannel.b2c,
          repository: repo,
          previewContext: const DriverPreviewContext(
            channel: DriverChannel.b2c,
            storeId: 41,
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('assignment-7')));
    await tester.pumpAndSettle();
    expect(
      find.byKey(const Key('assignment-status-7-accepted')),
      findsOneWidget,
    );

    await tester.tap(find.byKey(const Key('assignment-status-7-accepted')));
    await tester.pumpAndSettle();

    expect(repo.transitionCount, 0);
    expect(find.byKey(const Key('driver-action-error')), findsOneWidget);
    expect(find.textContaining('Safe preview'), findsOneWidget);
  });

  testWidgets('safe preview simulates native navigation without launching maps',
      (tester) async {
    final repo = _PreviewRepo(const [
      DriverAssignment(
        id: 9,
        storeId: 41,
        channel: DriverChannel.b2c,
        reference: 'NAV-9',
        status: 'assigned',
        navigationLatitude: 29.3759,
        navigationLongitude: 47.9774,
      ),
    ]);
    var launched = false;

    await tester.pumpWidget(
      MaterialApp(
        locale: const Locale('en'),
        home: DriverJourneyPage(
          channel: DriverChannel.b2c,
          repository: repo,
          previewContext: const DriverPreviewContext(
            channel: DriverChannel.b2c,
            storeId: 41,
          ),
          navigationLauncher: (latitude, longitude) async {
            launched = true;
            return true;
          },
        ),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('assignment-9')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('driver-navigate-9')));
    await tester.pump();

    expect(launched, isFalse);
    expect(find.textContaining('Native navigation is disabled'), findsOneWidget);
  });

  testWidgets('preview refuses a session from the wrong driver channel',
      (tester) async {
    await tester.pumpWidget(
      FoodexDriverApp(
        locale: const Locale('en'),
        initialSession: _session(DriverChannel.b2b),
        assignmentRepositoryFactory: (_) => _PreviewRepo(const []),
        previewContext: const DriverPreviewContext(
          channel: DriverChannel.b2c,
          storeId: 41,
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(
      find.byKey(const Key('driver-preview-configuration-invalid')),
      findsOneWidget,
    );
    expect(find.byKey(const Key('driver-login-email')), findsNothing);
  });
}
