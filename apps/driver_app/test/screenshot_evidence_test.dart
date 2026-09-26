import 'dart:io';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_driver_app/app.dart';
import 'package:foodex_driver_app/core/auth/driver_session.dart';
import 'package:foodex_driver_app/features/tasks/driver_journey.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  const b2cSession = DriverSession(
    token: 'evidence-token',
    name: 'ناصر · سائق FOODEX',
    email: 'driver.b2c@foodex.test',
    locale: 'ar',
    channel: DriverChannel.b2c,
  );
  const b2bSession = DriverSession(
    token: 'evidence-token',
    name: 'سالم · سائق FOODEX أعمال',
    email: 'driver.b2b@foodex.test',
    locale: 'ar',
    channel: DriverChannel.b2b,
  );

  testWidgets('capture driver login', (tester) async {
    await _setup(tester);
    await _captureApp(
      tester,
      const FoodexDriverApp(authRepository: _EvidenceAuthRepository()),
      '01_Mobile/Driver_B2C/01_driver_login__default__ar.png',
    );
  });

  for (final item in <({DriverSession session, String route, String path})>[
    (session: b2cSession, route: '/driver/b2c/home', path: '01_Mobile/Driver_B2C/02_driver_home__default__ar.png'),
    (session: b2cSession, route: '/driver/b2c/deliveries', path: '01_Mobile/Driver_B2C/03_driver_deliveries__populated__ar.png'),
    (session: b2bSession, route: '/driver/b2b/home', path: '01_Mobile/Driver_B2B/01_driver_home__default__ar.png'),
    (session: b2bSession, route: '/driver/b2b/deliveries', path: '01_Mobile/Driver_B2B/02_driver_deliveries__populated__ar.png'),
  ]) {
    testWidgets('capture ${item.path}', (tester) async {
      await _setup(tester);
      await _captureApp(
        tester,
        FoodexDriverApp(
          initialSession: item.session,
          initialRoute: item.route,
          authRepository: const _EvidenceAuthRepository(),
          assignmentRepositoryFactory: (_) => const _EvidenceAssignments(),
        ),
        item.path,
      );
    });
  }

  testWidgets('capture B2C delivery detail action sheet', (tester) async {
    await _setup(tester);
    final key = GlobalKey();
    await tester.pumpWidget(
      RepaintBoundary(
        key: key,
        child: FoodexDriverApp(
          initialSession: b2cSession,
          initialRoute: '/driver/b2c/deliveries',
          authRepository: const _EvidenceAuthRepository(),
          assignmentRepositoryFactory: (_) => const _EvidenceAssignments(),
        ),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('assignment-3')));
    await tester.pumpAndSettle();
    await _writeBoundary(
      key,
      '01_Mobile/Driver_B2C/04_driver_delivery_detail__actions__ar.png',
    );
  });

  testWidgets('capture B2C empty deliveries', (tester) async {
    await _setup(tester);
    await _captureApp(
      tester,
      FoodexDriverApp(
        initialSession: b2cSession,
        initialRoute: '/driver/b2c/deliveries',
        authRepository: const _EvidenceAuthRepository(),
        assignmentRepositoryFactory: (_) => const _EmptyAssignments(),
      ),
      '01_Mobile/Driver_B2C/05_driver_deliveries__empty__ar.png',
    );
  });

  testWidgets('capture B2C offline recovery', (tester) async {
    await _setup(tester);
    await _captureApp(
      tester,
      FoodexDriverApp(
        initialSession: b2cSession,
        initialRoute: '/driver/b2c/deliveries',
        authRepository: const _EvidenceAuthRepository(),
        assignmentRepositoryFactory: (_) => const _OfflineAssignments(),
      ),
      '01_Mobile/Driver_B2C/06_driver_deliveries__offline__ar.png',
    );
  });
}

Future<void> _setup(WidgetTester tester) async {
  tester.view.physicalSize = const Size(430, 932);
  tester.view.devicePixelRatio = 1;
  addTearDown(() {
    tester.view.resetPhysicalSize();
    tester.view.resetDevicePixelRatio();
  });
}

Future<void> _captureApp(
  WidgetTester tester,
  Widget app,
  String relativePath,
) async {
  final key = GlobalKey();
  await tester.pumpWidget(RepaintBoundary(key: key, child: app));
  await tester.pumpAndSettle();
  await _writeBoundary(key, relativePath);
}

Future<void> _writeBoundary(GlobalKey key, String relativePath) async {
  final boundary = key.currentContext!.findRenderObject()! as RenderRepaintBoundary;
  final image = await boundary.toImage(pixelRatio: 1);
  final data = await image.toByteData(format: ui.ImageByteFormat.png);
  final file = File('../../ScreenShots/$relativePath');
  await file.parent.create(recursive: true);
  await file.writeAsBytes(data!.buffer.asUint8List(), flush: true);
  if (await file.length() <= 1000) {
    throw StateError('Screenshot is unexpectedly small: $relativePath');
  }
}

class _EvidenceAuthRepository implements DriverAuthRepository {
  const _EvidenceAuthRepository();

  @override
  Future<DriverSession> login({
    required String email,
    required String password,
  }) async =>
      const DriverSession(
        token: 'evidence-token',
        name: 'ناصر · سائق FOODEX',
        email: 'driver.b2c@foodex.test',
        locale: 'ar',
        channel: DriverChannel.b2c,
      );

  @override
  Future<void> logout(String token) async {}
}

class _EvidenceAssignments implements DriverAssignmentRepository {
  const _EvidenceAssignments();

  @override
  Future<List<DriverAssignment>> list(DriverChannel channel) async => [
        DriverAssignment(
          id: channel == DriverChannel.b2c ? 3 : 13,
          orderId: channel == DriverChannel.b2c ? 41 : 141,
          channel: channel,
          reference: channel == DriverChannel.b2c ? '#FOODEX-41' : '#FOODEX-B2B-141',
          status: 'assigned',
          availableStatuses: const ['accepted', 'picked_up'],
        ),
        DriverAssignment(
          id: channel == DriverChannel.b2c ? 4 : 14,
          orderId: channel == DriverChannel.b2c ? 42 : 142,
          channel: channel,
          reference: channel == DriverChannel.b2c ? '#FOODEX-42' : '#FOODEX-B2B-142',
          status: 'out_for_delivery',
          availableStatuses: const ['delivered'],
        ),
      ];

  @override
  Future<void> transition(int id, DriverChannel channel, String status) async {}
}

class _EmptyAssignments implements DriverAssignmentRepository {
  const _EmptyAssignments();

  @override
  Future<List<DriverAssignment>> list(DriverChannel channel) async => const [];

  @override
  Future<void> transition(int id, DriverChannel channel, String status) async {}
}

class _OfflineAssignments implements DriverAssignmentRepository {
  const _OfflineAssignments();

  @override
  Future<List<DriverAssignment>> list(DriverChannel channel) async =>
      throw const DriverOfflineException();

  @override
  Future<void> transition(int id, DriverChannel channel, String status) async =>
      throw const DriverOfflineException();
}
