import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_driver_app/app.dart';
import 'package:foodex_driver_app/core/auth/driver_session.dart';
import 'package:foodex_driver_app/core/location/driver_location_gate_service.dart';
import 'package:foodex_driver_app/core/location/driver_location_tracking_service.dart';
import 'package:foodex_driver_app/core/preview/driver_preview_context.dart';
import 'package:foodex_driver_app/features/tasks/driver_journey.dart';
import 'package:foodex_driver_app/navigation.dart';

class _FakeGateService implements DriverLocationGateService {
  _FakeGateService(this.status);

  DriverLocationGateStatus status;

  @override
  Future<DriverLocationGateStatus> check({bool requestPermission = false}) async =>
      status;

  @override
  Future<bool> openAppSettings() async => true;

  @override
  Future<bool> openLocationSettings() async => true;
}

class _FakeTrackingController implements DriverLocationTrackingController {
  int starts = 0;
  int stops = 0;
  int disposes = 0;
  final List<bool> gateStates = <bool>[];
  final List<bool> foregroundStates = <bool>[];
  final List<String?> assignmentStatuses = <String?>[];

  @override
  void start() => starts++;

  @override
  void setAppInForeground(bool foreground) => foregroundStates.add(foreground);

  @override
  void setGateReady(bool ready) => gateStates.add(ready);

  @override
  void setAssignmentStatus(String? status) => assignmentStatuses.add(status);

  @override
  void stop({bool clearQueue = true}) => stops++;

  @override
  void dispose() => disposes++;
}

class _EmptyAssignments implements DriverAssignmentRepository {
  @override
  Future<List<DriverAssignment>> list(DriverChannel channel) async => const [];

  @override
  Future<void> transition(
    int id,
    DriverChannel channel,
    String status, {
    String? note,
    String? failureReason,
  }) async {}
}

void main() {
  testWidgets(
      'authenticated app starts tracking only through gate and stops on logout',
      (tester) async {
    final gate = _FakeGateService(DriverLocationGateStatus.ready);
    final tracking = _FakeTrackingController();
    final session = DriverSession(
      token: 'driver-session-secret',
      name: 'Driver',
      email: 'driver@example.test',
      locale: 'en',
      channel: DriverChannel.b2c,
    );

    await tester.pumpWidget(
      FoodexDriverApp(
        locale: const Locale('en'),
        initialRoute: DriverRoutes.b2cHome,
        initialSession: session,
        assignmentRepositoryFactory: (_) => _EmptyAssignments(),
        locationGateService: gate,
        locationTrackingFactory: (resolvedSession, onSessionInvalid) {
          expect(resolvedSession, same(session));
          return tracking;
        },
      ),
    );
    await tester.pump();

    expect(tracking.starts, 1);
    expect(tracking.foregroundStates, [true]);
    expect(tracking.gateStates, contains(true));
    expect(find.byKey(const Key('driver-location-gate')), findsNothing);

    gate.status = DriverLocationGateStatus.permissionDenied;
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pump();

    expect(tracking.foregroundStates, containsAllInOrder([true, false, true]));
    expect(tracking.gateStates.last, isFalse);
    expect(find.byKey(const Key('driver-location-gate')), findsOneWidget);

    gate.status = DriverLocationGateStatus.ready;
    await tester.tap(find.byKey(const Key('driver-location-retry')));
    await tester.pump();

    expect(tracking.gateStates.last, isTrue);

    await tester.tap(find.byKey(const Key('driver-global-logout')));
    await tester.pump();

    expect(tracking.stops, greaterThanOrEqualTo(1));
    expect(tracking.disposes, 1);
    expect(find.byKey(const Key('driver-login-email')), findsOneWidget);

    await tester.pumpWidget(const SizedBox.shrink());
  });

  testWidgets(
      'replacing authenticated session disposes old tracking and creates exactly one new controller',
      (tester) async {
    final gate = _FakeGateService(DriverLocationGateStatus.ready);
    final firstTracking = _FakeTrackingController();
    final secondTracking = _FakeTrackingController();
    final createdFor = <DriverSession>[];

    final firstSession = DriverSession(
      token: 'first-session-secret',
      name: 'Driver One',
      email: 'one@example.test',
      locale: 'en',
      channel: DriverChannel.b2c,
    );
    final secondSession = DriverSession(
      token: 'second-session-secret',
      name: 'Driver Two',
      email: 'two@example.test',
      locale: 'en',
      channel: DriverChannel.b2c,
    );

    Widget build(DriverSession session) => FoodexDriverApp(
          key: const ValueKey('driver-app'),
          locale: const Locale('en'),
          initialRoute: DriverRoutes.b2cHome,
          initialSession: session,
          assignmentRepositoryFactory: (_) => _EmptyAssignments(),
          locationGateService: gate,
          locationTrackingFactory: (resolvedSession, onSessionInvalid) {
            createdFor.add(resolvedSession);
            if (identical(resolvedSession, firstSession)) {
              return firstTracking;
            }
            if (identical(resolvedSession, secondSession)) {
              return secondTracking;
            }
            fail('Unexpected session passed to tracking factory.');
          },
        );

    await tester.pumpWidget(build(firstSession));
    await tester.pump();

    expect(createdFor, [firstSession]);
    expect(firstTracking.starts, 1);
    expect(firstTracking.disposes, 0);

    await tester.pumpWidget(build(secondSession));
    await tester.pump();

    expect(createdFor, [firstSession, secondSession]);
    expect(firstTracking.stops, 1);
    expect(firstTracking.disposes, 1);
    expect(secondTracking.starts, 1);
    expect(secondTracking.disposes, 0);

    await tester.pumpWidget(const SizedBox.shrink());

    expect(secondTracking.stops, 1);
    expect(secondTracking.disposes, 1);
  });

  testWidgets('FoodexDriverApp.preview never creates location tracking',
      (tester) async {
    final preview = DriverPreviewContext(
      channel: DriverChannel.b2c,
      storeId: 7,
      targetName: 'Preview Driver',
      targetLocale: 'en',
    );

    final app = FoodexDriverApp.preview(
      previewContext: preview,
      assignmentRepository: _EmptyAssignments(),
      initialRoute: DriverRoutes.b2cHome,
      locale: const Locale('en'),
    );

    expect(app.previewContext, same(preview));
    expect(app.locationGateService, isNull);
    expect(app.locationTrackingFactory, isNull);

    await tester.pumpWidget(app);
    await tester.pump();

    expect(find.byKey(const Key('driver-location-gate')), findsNothing);
    expect(find.byKey(const Key('driver-app-version-footer')), findsOneWidget);

    await tester.pumpWidget(const SizedBox.shrink());
  });

}
