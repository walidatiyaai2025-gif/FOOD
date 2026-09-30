import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_driver_app/app.dart';
import 'package:foodex_driver_app/core/auth/driver_session.dart';
import 'package:foodex_driver_app/core/location/driver_location_gate_service.dart';
import 'package:foodex_driver_app/core/location/driver_location_tracking_service.dart';
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

  @override
  void start() => starts++;

  @override
  void setGateReady(bool ready) => gateStates.add(ready);

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
    expect(tracking.gateStates, contains(true));
    expect(find.byKey(const Key('driver-location-gate')), findsNothing);

    gate.status = DriverLocationGateStatus.permissionDenied;
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pump();

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
}
