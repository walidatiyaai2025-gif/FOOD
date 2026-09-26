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

  for (final locale in const [Locale('ar'), Locale('en')]) {
    final localeCode = locale.languageCode;
    final b2cSession = DriverSession(
      token: 'evidence-token',
      name: localeCode == 'ar' ? 'ناصر · سائق FOODEX' : 'FOODEX Driver Nasser',
      email: 'driver.b2c@foodex.test',
      locale: localeCode,
      channel: DriverChannel.b2c,
    );
    final b2bSession = DriverSession(
      token: 'evidence-token',
      name: localeCode == 'ar' ? 'سالم · سائق FOODEX أعمال' : 'FOODEX B2B Driver Salem',
      email: 'driver.b2b@foodex.test',
      locale: localeCode,
      channel: DriverChannel.b2b,
    );

    testWidgets('capture driver login $localeCode', (tester) async {
      await _setup(tester);
      await _captureApp(
        tester,
        FoodexDriverApp(
          authRepository: const _EvidenceAuthRepository(),
          locale: locale,
        ),
        '01_Mobile/Driver_B2C/01_driver_login__default__$localeCode.png',
      );
    });

    for (final item in <({DriverSession session, String route, String path})>[
      (
        session: b2cSession,
        route: '/driver/b2c/home',
        path: '01_Mobile/Driver_B2C/02_driver_home__default__$localeCode.png',
      ),
      (
        session: b2cSession,
        route: '/driver/b2c/deliveries',
        path: '01_Mobile/Driver_B2C/03_driver_deliveries__populated__$localeCode.png',
      ),
      (
        session: b2bSession,
        route: '/driver/b2b/home',
        path: '01_Mobile/Driver_B2B/01_driver_home__default__$localeCode.png',
      ),
      (
        session: b2bSession,
        route: '/driver/b2b/deliveries',
        path: '01_Mobile/Driver_B2B/02_driver_deliveries__populated__$localeCode.png',
      ),
    ]) {
      testWidgets('capture ${item.path}', (tester) async {
        await _setup(tester);
        await _captureApp(
          tester,
          FoodexDriverApp(
            initialSession: item.session,
            initialRoute: item.route,
            locale: locale,
            authRepository: const _EvidenceAuthRepository(),
            assignmentRepositoryFactory: (_) => const _EvidenceAssignments(),
          ),
          item.path,
        );
      });
    }

    testWidgets('capture B2C delivery detail action sheet $localeCode', (tester) async {
      await _setup(tester);
      final key = GlobalKey();
      await tester.pumpWidget(
        RepaintBoundary(
          key: key,
          child: FoodexDriverApp(
            initialSession: b2cSession,
            initialRoute: '/driver/b2c/deliveries',
            locale: locale,
            authRepository: const _EvidenceAuthRepository(),
            assignmentRepositoryFactory: (_) => const _EvidenceAssignments(),
          ),
        ),
      );
      await tester.pump();
        await tester.pump(const Duration(milliseconds: 150));
      await tester.tap(find.byKey(const Key('assignment-3')));
      await tester.pump();
        await tester.pump(const Duration(milliseconds: 150));
      await _writeBoundary(
        tester,
        key,
        '01_Mobile/Driver_B2C/04_driver_delivery_detail__actions__$localeCode.png',
      );
    });

    testWidgets('capture B2C empty deliveries $localeCode', (tester) async {
      await _setup(tester);
      await _captureApp(
        tester,
        FoodexDriverApp(
          initialSession: b2cSession,
          initialRoute: '/driver/b2c/deliveries',
          locale: locale,
          authRepository: const _EvidenceAuthRepository(),
          assignmentRepositoryFactory: (_) => const _EmptyAssignments(),
        ),
        '01_Mobile/Driver_B2C/05_driver_deliveries__empty__$localeCode.png',
      );
    });

    testWidgets('capture B2C offline recovery $localeCode', (tester) async {
      await _setup(tester);
      await _captureApp(
        tester,
        FoodexDriverApp(
          initialSession: b2cSession,
          initialRoute: '/driver/b2c/deliveries',
          locale: locale,
          authRepository: const _EvidenceAuthRepository(),
          assignmentRepositoryFactory: (_) => const _OfflineAssignments(),
        ),
        '01_Mobile/Driver_B2C/06_driver_deliveries__offline__$localeCode.png',
      );
    });
  }
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
  await tester.pump();
        await tester.pump(const Duration(milliseconds: 150));
  await _writeBoundary(tester, key, relativePath);
}

Future<void> _writeBoundary(
  WidgetTester tester,
  GlobalKey key,
  String relativePath,
) async {
  final boundary =
        key.currentContext!.findRenderObject()! as RenderRepaintBoundary;
    final image = await boundary.toImage(pixelRatio: 1);
    final data = await image.toByteData(format: ui.ImageByteFormat.png);
    final file = File('../../ScreenShots/$relativePath');
    file.parent.createSync(recursive: true);
    file.writeAsBytesSync(data!.buffer.asUint8List(), flush: true);
    if (file.lengthSync() <= 1000) {
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
        name: 'FOODEX Driver',
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
          reference: channel == DriverChannel.b2c
              ? '#FOODEX-41'
              : '#FOODEX-B2B-141',
          status: 'assigned',
          availableStatuses: const ['accepted', 'picked_up'],
        ),
        DriverAssignment(
          id: channel == DriverChannel.b2c ? 4 : 14,
          orderId: channel == DriverChannel.b2c ? 42 : 142,
          channel: channel,
          reference: channel == DriverChannel.b2c
              ? '#FOODEX-42'
              : '#FOODEX-B2B-142',
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
