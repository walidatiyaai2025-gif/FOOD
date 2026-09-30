import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_driver_app/core/location/driver_location_gate_service.dart';
import 'package:foodex_driver_app/features/location/driver_location_gate.dart';

class _FakeLocationGateService implements DriverLocationGateService {
  _FakeLocationGateService(this.status);

  DriverLocationGateStatus status;
  int checks = 0;
  int appSettings = 0;
  int locationSettings = 0;

  @override
  Future<DriverLocationGateStatus> check({bool requestPermission = false}) async {
    checks++;
    return status;
  }

  @override
  Future<bool> openAppSettings() async {
    appSettings++;
    return true;
  }

  @override
  Future<bool> openLocationSettings() async {
    locationSettings++;
    return true;
  }
}

void main() {
  testWidgets('GPS off blocks operations and retry resumes without login', (tester) async {
    final service = _FakeLocationGateService(
      DriverLocationGateStatus.serviceDisabled,
    );

    await tester.pumpWidget(
      MaterialApp(
        home: DriverLocationGate(
          service: service,
          recheckInterval: const Duration(days: 1),
          onLogout: () async {},
          child: const Text('OPERATIONS', key: Key('operations')),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('driver-location-gate')), findsOneWidget);
    expect(find.byKey(const Key('operations')), findsNothing);

    await tester.tap(find.byKey(const Key('driver-location-settings')));
    await tester.pump();
    expect(service.locationSettings, 1);

    service.status = DriverLocationGateStatus.ready;
    await tester.tap(find.byKey(const Key('driver-location-retry')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('operations')), findsOneWidget);
  });

  testWidgets('denied forever keeps logout and app settings available', (tester) async {
    final service = _FakeLocationGateService(
      DriverLocationGateStatus.permissionDeniedForever,
    );
    var loggedOut = false;

    await tester.pumpWidget(
      MaterialApp(
        home: DriverLocationGate(
          service: service,
          recheckInterval: const Duration(days: 1),
          onLogout: () async => loggedOut = true,
          child: const Text('OPERATIONS'),
        ),
      ),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('driver-location-settings')));
    await tester.pump();
    expect(service.appSettings, 1);

    await tester.tap(find.byKey(const Key('driver-location-logout')));
    await tester.pump();
    expect(loggedOut, isTrue);
  });
}
