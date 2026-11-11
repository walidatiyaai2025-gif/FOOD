import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_driver_app/app.dart';
import 'package:foodex_driver_app/core/auth/driver_session.dart';
import 'package:foodex_driver_app/core/location/driver_location_gate_service.dart';
import 'package:foodex_driver_app/core/location/driver_location_tracking_service.dart';
import 'package:foodex_driver_app/features/delivery/driver_assignment_contract.dart';
import 'package:foodex_driver_app/navigation.dart';

class _Gate implements DriverLocationGateService {
  @override
  Future<DriverLocationGateStatus> check({
    bool requestPermission = false,
  }) async =>
      DriverLocationGateStatus.ready;

  @override
  Future<bool> openAppSettings() async => true;

  @override
  Future<bool> openLocationSettings() async => true;
}

class _Tracking implements DriverLocationTrackingController {
  int starts = 0;
  int stops = 0;
  int disposes = 0;

  @override
  void start() => starts++;

  @override
  void stop({bool clearQueue = true}) => stops++;

  @override
  void dispose() => disposes++;

  @override
  void setAppInForeground(bool foreground) {}

  @override
  void setGateReady(bool ready) {}

  @override
  void setAssignmentStatus(String? status) {}
}

class _Assignments implements DriverAssignmentRepository {
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

DriverSession _session(String token) => DriverSession(
      token: token,
      name: token == 'old-token' ? 'Previous Driver' : 'Current Driver',
      email: 'driver@example.test',
      locale: 'en',
      channel: DriverChannel.b2c,
    );

void main() {
  for (final testCase in const <(Locale, String)>[
    (Locale('ar'), 'Arabic Driver session renewal'),
    (Locale('en'), 'English Driver session renewal'),
  ]) {
    testWidgets(
      'stale 401/403 callback cannot revoke a newer ${testCase.$2}',
      (tester) async {
        final gate = _Gate();
        final oldSession = _session('old-token');
        final currentSession = _session('new-token');
        final callbacks = <void Function()>[];
        final oldTracker = _Tracking();
        final currentTracker = _Tracking();

        Widget appFor(DriverSession session) => FoodexDriverApp(
              key: const ValueKey('driver-session-identity-test'),
              locale: testCase.$1,
              translationFetcher: (_) async => const <String, String>{},
              initialRoute: DriverRoutes.b2cHome,
              initialSession: session,
              assignmentRepositoryFactory: (_) => _Assignments(),
              locationGateService: gate,
              locationTrackingFactory: (actor, onSessionInvalid) {
                callbacks.add(onSessionInvalid);
                return actor.token == oldSession.token
                    ? oldTracker
                    : currentTracker;
              },
            );

        await tester.pumpWidget(appFor(oldSession));
        await tester.pump();

        expect(callbacks.length, 1);
        expect(oldTracker.starts, 1);
        expect(find.byKey(const Key('driver-login-email')), findsNothing);

        await tester.pumpWidget(appFor(currentSession));
        await tester.pump();

        expect(callbacks.length, 2);
        expect(oldTracker.disposes, 1);
        expect(currentTracker.starts, 1);

        // A suspended request made with the old token is rejected after
        // re-authentication. It has no authority over the new identity.
        callbacks.first();
        await tester.pump();

        expect(find.byKey(const Key('driver-login-email')), findsNothing);
        expect(currentTracker.disposes, 0);

        // Rejection of the current token does invalidate the live session,
        // including stopping GPS and returning to the localized login.
        callbacks.last();
        await tester.pump();

        expect(currentTracker.disposes, 1);
        expect(find.byKey(const Key('driver-login-email')), findsOneWidget);

        // Replaying either callback cannot disturb the logged-out state.
        callbacks.first();
        callbacks.last();
        await tester.pump();
        expect(find.byKey(const Key('driver-login-email')), findsOneWidget);

        await tester.pumpWidget(const SizedBox.shrink());
      },
    );
  }
}
