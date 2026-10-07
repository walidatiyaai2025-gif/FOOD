import 'dart:async';
import 'dart:io';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_driver_app/app.dart';
import 'package:foodex_driver_app/core/auth/driver_session.dart';
import 'package:foodex_driver_app/core/push/firebase_push_service.dart';
import 'package:foodex_driver_app/core/theme/foodex_theme.dart';
import 'package:foodex_driver_app/features/delivery/driver_assignment_contract.dart';
import 'package:foodex_driver_app/features/notifications/notification_feed.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  setUpAll(_loadEvidenceFont);

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
          theme: FoodexTheme.light(fontFamily: _evidenceFontFamily),
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
      (
        session: b2cSession,
        route: '/driver/b2c/notifications',
        path: '01_Mobile/Driver_B2C/08_driver_notifications__populated__$localeCode.png',
      ),
    ]) {
      testWidgets('capture ${item.path}', (tester) async {
        await _setup(tester);
        await _captureApp(
          tester,
          FoodexDriverApp(
          theme: FoodexTheme.light(fontFamily: _evidenceFontFamily),
            initialSession: item.session,
            initialRoute: item.route,
            locale: locale,
            authRepository: const _EvidenceAuthRepository(),
            assignmentRepositoryFactory: (_) => const _EvidenceAssignments(),
            notificationRepositoryFactory: (_) => const _EvidenceNotifications(),
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
          theme: FoodexTheme.light(fontFamily: _evidenceFontFamily),
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
      await tester.tap(find.byKey(const Key('driver-active-assignment-3')));
      await tester.pump();
        await tester.pump(const Duration(milliseconds: 150));
      await _writeBoundary(
        tester,
        key,
        '01_Mobile/Driver_B2C/04_driver_delivery_detail__actions__$localeCode.png',
      );
      expect(find.byKey(const Key('driver-active-navigate-3')), findsOneWidget);
      await _writeBoundary(
        tester,
        key,
        '01_Mobile/Driver_B2C/07_driver_navigation__available__$localeCode.png',
      );
    });

    testWidgets('capture foreground push alert $localeCode', (tester) async {
      await _setup(tester);
      final key = GlobalKey();
      final push = _EvidencePushService();
      addTearDown(push.dispose);

      await tester.pumpWidget(
        RepaintBoundary(
          key: key,
          child: FoodexDriverApp(
            theme: FoodexTheme.light(fontFamily: _evidenceFontFamily),
            initialSession: b2cSession,
            initialRoute: '/driver/b2c/home',
            locale: locale,
            authRepository: const _EvidenceAuthRepository(),
            assignmentRepositoryFactory: (_) => const _EvidenceAssignments(),
            notificationRepositoryFactory: (_) => const _EvidenceNotifications(),
            pushService: push,
            showPersistentFooter: false,
          ),
        ),
      );
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 150));

      push.emitAlert(
        DriverPushAlert(
          title: localeCode == 'ar' ? 'طلب جديد' : 'New order',
          body: localeCode == 'ar'
              ? 'تم إسناد الطلب إليك'
              : 'A new order was assigned to you',
          open: const DriverPushOpen(
            assignmentId: 3,
            orderId: 41,
          ),
        ),
      );
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 150));

      expect(find.textContaining(localeCode == 'ar' ? 'طلب #41' : 'Order #41'), findsOneWidget);
      expect(
        find.text(localeCode == 'ar' ? 'عرض الطلب' : 'View order'),
        findsOneWidget,
      );
      await _writeBoundary(
        tester,
        key,
        '01_Mobile/Driver_B2C/09_driver_foreground_alert__order_41__$localeCode.png',
      );
    });

    testWidgets('capture B2C empty deliveries $localeCode', (tester) async {
      await _setup(tester);
      await _captureApp(
        tester,
        FoodexDriverApp(
          theme: FoodexTheme.light(fontFamily: _evidenceFontFamily),
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
          theme: FoodexTheme.light(fontFamily: _evidenceFontFamily),
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


const _evidenceFontFamily = 'FoodexEvidence';

Future<void> _loadEvidenceFont() async {
  const candidates = <String>[
    '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
    '/usr/share/fonts/truetype/noto/NotoSansArabic-Regular.ttf',
    '/System/Library/Fonts/Supplemental/Arial Unicode.ttf',
    'C:/Windows/Fonts/arial.ttf',
  ];

  File? fontFile;
  for (final path in candidates) {
    final candidate = File(path);
    if (candidate.existsSync()) {
      fontFile = candidate;
      break;
    }
  }

  if (fontFile == null) {
    throw StateError(
      'No Arabic-capable evidence font found. Install DejaVu Sans, Noto Sans Arabic, or Arial before screenshot capture.',
    );
  }

  final bytes = await fontFile.readAsBytes();
  final loader = FontLoader(_evidenceFontFamily)
    ..addFont(Future<ByteData>.value(ByteData.sublistView(bytes)));
  await loader.load();

  final flutterRoot = Platform.environment['FLUTTER_ROOT'];
  if (flutterRoot == null || flutterRoot.isEmpty) {
    throw StateError('FLUTTER_ROOT is required for readable Material Icons evidence.');
  }

  final materialIcons = File(
    '$flutterRoot/bin/cache/artifacts/material_fonts/MaterialIcons-Regular.otf',
  );
  if (!materialIcons.existsSync()) {
    throw StateError('Material Icons font not found at ${materialIcons.path}.');
  }

  final iconBytes = await materialIcons.readAsBytes();
  final iconLoader = FontLoader('MaterialIcons')
    ..addFont(Future<ByteData>.value(ByteData.sublistView(iconBytes)));
  await iconLoader.load();
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
  await tester.runAsync(() async {
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
  });
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
          address: 'Bayan Block 1 · Street 5 · Building 12',
          navigationLatitude: 29.3031,
          navigationLongitude: 48.0489,
          customerNote: 'Call on arrival',
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
  Future<void> transition(int id, DriverChannel channel, String status, {String? note, String? failureReason}) async {}
}

class _EvidenceNotifications implements DriverNotificationRepository {
  const _EvidenceNotifications();

  @override
  Future<List<DriverNotification>> list({required String locale}) async => [
        DriverNotification(
          id: 701,
          title: locale == 'ar' ? 'طلب جديد #41' : 'New order #41',
          body: locale == 'ar'
              ? 'تم إسناد الطلب إليك'
              : 'A new order was assigned to you',
          readAt: null,
          data: const {
            'assignment_id': 3,
            'order_id': 41,
            'access_revoked': false,
          },
        ),
      ];

  @override
  Future<void> markRead(int notificationId) async {}
}

class _EvidencePushService implements DriverPushService {
  final StreamController<DriverPushOpen> _opens =
      StreamController<DriverPushOpen>.broadcast();
  final StreamController<DriverPushAlert> _alerts =
      StreamController<DriverPushAlert>.broadcast();

  @override
  Stream<DriverPushOpen> get opens => _opens.stream;

  @override
  Stream<DriverPushAlert> get alerts => _alerts.stream;

  void emitAlert(DriverPushAlert alert) => _alerts.add(alert);

  @override
  DriverPushOpen? takePendingOpen() => null;

  @override
  Future<void> bindSession(String accessToken) async {}

  @override
  Future<void> revokeSession() async {}

  Future<void> dispose() async {
    await _opens.close();
    await _alerts.close();
  }
}

class _EmptyAssignments implements DriverAssignmentRepository {
  const _EmptyAssignments();

  @override
  Future<List<DriverAssignment>> list(DriverChannel channel) async => const [];

  @override
  Future<void> transition(int id, DriverChannel channel, String status, {String? note, String? failureReason}) async {}
}

class _OfflineAssignments implements DriverAssignmentRepository {
  const _OfflineAssignments();

  @override
  Future<List<DriverAssignment>> list(DriverChannel channel) async =>
      throw const DriverOfflineException();

  @override
  Future<void> transition(int id, DriverChannel channel, String status, {String? note, String? failureReason}) async =>
      throw const DriverOfflineException();
}
