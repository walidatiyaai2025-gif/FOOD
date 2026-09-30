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

Widget _gate({
  required _FakeLocationGateService service,
  required Widget child,
  Duration recheckInterval = const Duration(days: 1),
  Future<void> Function()? onLogout,
}) {
  return MaterialApp(
    home: DriverLocationGate(
      service: service,
      recheckInterval: recheckInterval,
      onLogout: onLogout ?? () async {},
      child: child,
    ),
  );
}

void main() {
  testWidgets('GPS off blocks operations and retry resumes without login', (tester) async {
    final service = _FakeLocationGateService(
      DriverLocationGateStatus.serviceDisabled,
    );

    await tester.pumpWidget(
      _gate(
        service: service,
        child: const Text('OPERATIONS', key: Key('operations')),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('driver-location-gate')), findsOneWidget);
    expect(find.byKey(const Key('operations')), findsNothing);

    await tester.tap(
      find.byKey(const Key('driver-location-location-settings')),
    );
    await tester.pump();
    expect(service.locationSettings, 1);

    await tester.tap(find.byKey(const Key('driver-location-app-settings')));
    await tester.pump();
    expect(service.appSettings, 1);

    service.status = DriverLocationGateStatus.ready;
    await tester.tap(find.byKey(const Key('driver-location-retry')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('operations')), findsOneWidget);
    await tester.pumpWidget(const SizedBox.shrink());
  });

  testWidgets('permission denied and reduced accuracy remain blocking', (tester) async {
    final service = _FakeLocationGateService(
      DriverLocationGateStatus.permissionDenied,
    );

    await tester.pumpWidget(
      _gate(
        service: service,
        child: const Text('OPERATIONS', key: Key('operations')),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('driver-location-gate')), findsOneWidget);
    expect(find.byKey(const Key('operations')), findsNothing);
    expect(find.byKey(const Key('driver-location-app-settings')), findsOneWidget);
    expect(
      find.byKey(const Key('driver-location-location-settings')),
      findsOneWidget,
    );

    service.status = DriverLocationGateStatus.reducedAccuracy;
    await tester.tap(find.byKey(const Key('driver-location-retry')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('driver-location-gate')), findsOneWidget);
    expect(find.byKey(const Key('operations')), findsNothing);
    await tester.pumpWidget(const SizedBox.shrink());
  });

  testWidgets('periodic recheck blocks operations after permission is revoked', (tester) async {
    final service = _FakeLocationGateService(DriverLocationGateStatus.ready);

    await tester.pumpWidget(
      _gate(
        service: service,
        recheckInterval: const Duration(seconds: 1),
        child: const Text('OPERATIONS', key: Key('operations')),
      ),
    );
    await tester.pump();

    expect(find.byKey(const Key('operations')), findsOneWidget);

    service.status = DriverLocationGateStatus.permissionDenied;
    await tester.pump(const Duration(seconds: 1));
    await tester.pump();

    expect(find.byKey(const Key('driver-location-gate')), findsOneWidget);
    expect(find.byKey(const Key('operations')), findsNothing);
    await tester.pumpWidget(const SizedBox.shrink());
  });

  testWidgets('app resume rechecks location state', (tester) async {
    final service = _FakeLocationGateService(DriverLocationGateStatus.ready);

    await tester.pumpWidget(
      _gate(
        service: service,
        child: const Text('OPERATIONS', key: Key('operations')),
      ),
    );
    await tester.pump();
    final checksBeforeResume = service.checks;

    service.status = DriverLocationGateStatus.serviceDisabled;
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pump();

    expect(service.checks, greaterThan(checksBeforeResume));
    expect(find.byKey(const Key('driver-location-gate')), findsOneWidget);
    expect(find.byKey(const Key('operations')), findsNothing);
    await tester.pumpWidget(const SizedBox.shrink());
  });

  testWidgets('denied forever keeps logout and both settings actions available', (tester) async {
    final service = _FakeLocationGateService(
      DriverLocationGateStatus.permissionDeniedForever,
    );
    var loggedOut = false;

    await tester.pumpWidget(
      _gate(
        service: service,
        onLogout: () async => loggedOut = true,
        child: const Text('OPERATIONS'),
      ),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('driver-location-app-settings')));
    await tester.pump();
    expect(service.appSettings, 1);

    await tester.tap(
      find.byKey(const Key('driver-location-location-settings')),
    );
    await tester.pump();
    expect(service.locationSettings, 1);

    await tester.tap(find.byKey(const Key('driver-location-logout')));
    await tester.pump();
    expect(loggedOut, isTrue);
    await tester.pumpWidget(const SizedBox.shrink());
  });
}
