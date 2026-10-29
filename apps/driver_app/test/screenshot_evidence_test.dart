import 'dart:async';
import 'dart:io';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_driver_app/app.dart';
import 'package:foodex_driver_app/core/auth/driver_auth_persistence.dart';
import 'package:foodex_driver_app/core/auth/driver_session.dart';
import 'package:foodex_driver_app/core/localization/driver_translations.dart';
import 'package:foodex_driver_app/core/push/firebase_push_service.dart';
import 'package:foodex_driver_app/core/theme/foodex_theme.dart';
import 'package:foodex_driver_app/features/auth/driver_login.dart';
import 'package:foodex_driver_app/features/delivery/driver_assignment_contract.dart';
import 'package:foodex_driver_app/features/notifications/notification_feed.dart';
import 'package:foodex_driver_app/features/wallet/driver_wallet_contract.dart';
import 'package:foodex_driver_app/features/wallet/driver_wallet_page.dart';

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
      token: 'evidence-token-b2b',
      name: localeCode == 'ar' ? 'سائق متجر الجملة' : 'Wholesale Store Driver',
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

    for (final channel in DriverChannel.values) {
      testWidgets('capture saved biometric login ${channel.name} $localeCode',
          (tester) async {
        await _setup(tester);
        await _captureApp(
          tester,
          MaterialApp(
            debugShowCheckedModeBanner: false,
            locale: locale,
            supportedLocales: const [Locale('ar'), Locale('en')],
            localizationsDelegates: const [
              GlobalMaterialLocalizations.delegate,
              GlobalWidgetsLocalizations.delegate,
              GlobalCupertinoLocalizations.delegate,
            ],
            theme: FoodexTheme.light(fontFamily: _evidenceFontFamily),
            home: DriverTranslations(
              locale: locale,
              overrides: const {},
              child: DriverLoginPage(
                repository: const _EvidenceAuthRepository(),
                onAuthenticated: (_, __, ___) {},
                sessionStore: _EvidenceRememberedSessionStore(
                  DriverSession(
                    token: 'saved-evidence-token',
                    name: 'FOODEX Driver',
                    email: 'driver@foodex.test',
                    locale: localeCode,
                    channel: channel,
                    storeId: 7,
                  ),
                ),
                biometricAuthenticator: const _EvidenceBiometric(),
              ),
            ),
          ),
          '01_Mobile/Driver_${channel == DriverChannel.b2b ? 'B2B' : 'B2C'}/'
          '01_driver_login__biometric_saved__$localeCode.png',
        );
        expect(
          find.byKey(const Key('driver-login-biometric')),
          findsOneWidget,
        );
        expect(
          find.byKey(const Key('driver-login-logout')),
          findsOneWidget,
        );
        expect(
          find.byKey(const Key('driver-login-email')),
          findsNothing,
        );
        expect(
          find.byKey(const Key('driver-login-password')),
          findsNothing,
        );
      });
    }

    testWidgets('capture driver collected wallet and order details $localeCode',
        (tester) async {
      await _setup(tester);
      final key = GlobalKey();
      final theme = FoodexTheme.light(fontFamily: _evidenceFontFamily);
      await tester.pumpWidget(
        RepaintBoundary(
          key: key,
          child: MaterialApp(
            debugShowCheckedModeBanner: false,
            locale: locale,
            supportedLocales: const [Locale('ar'), Locale('en')],
            localizationsDelegates: const [
              GlobalMaterialLocalizations.delegate,
              GlobalWidgetsLocalizations.delegate,
              GlobalCupertinoLocalizations.delegate,
            ],
            theme: theme.copyWith(
              appBarTheme: theme.appBarTheme.copyWith(
                titleTextStyle: theme.appBarTheme.titleTextStyle?.copyWith(
                  fontFamily: _evidenceFontFamily,
                ),
              ),
            ),
            home: const DriverWalletPage(
              repository: _EvidenceCollectedWallet(),
            ),
          ),
        ),
      );
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 150));

      final summary = find.byKey(const Key('driver-collected-summary-6'));
      await tester.ensureVisible(summary);
      await tester.pump();
      expect(summary, findsOneWidget);
      await _writeBoundary(
        tester,
        key,
        '01_Mobile/Driver_B2C/10_driver_collected_summary__partial__$localeCode.png',
      );
      await _writeBoundary(
        tester,
        key,
        '01_Mobile/Driver_B2C/10b_driver_collection_wallet__premium__$localeCode.png',
      );

      await tester.tap(summary);
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 150));
      expect(
          find.byKey(const Key('driver-collected-order-123')), findsOneWidget);
      await _writeBoundary(
        tester,
        key,
        '01_Mobile/Driver_B2C/11_driver_collected_orders__partial__$localeCode.png',
      );
    });

    testWidgets('capture full premium B2C wallet $localeCode', (tester) async {
      await _setup(tester);
      await _captureApp(
        tester,
        FoodexDriverApp(
          theme: FoodexTheme.light(fontFamily: _evidenceFontFamily),
          initialSession: b2cSession,
          initialRoute: '/driver/b2c/wallet',
          locale: locale,
          authRepository: const _EvidenceAuthRepository(),
          assignmentRepositoryFactory: (_) =>
              const _EvidenceDashboardAssignments(),
        ),
        '01_Mobile/Driver_B2C/12_driver_wallet__premium__$localeCode.png',
      );
    });

    for (final item in <({DriverSession session, String route, String path})>[
      (
        session: b2cSession,
        route: '/driver/b2c/home',
        path: '01_Mobile/Driver_B2C/02_driver_home__default__$localeCode.png',
      ),
      (
        session: b2bSession,
        route: '/driver/b2b/home',
        path: '01_Mobile/Driver_B2B/02_driver_home__default__$localeCode.png',
      ),
      (
        session: b2cSession,
        route: '/driver/b2c/deliveries',
        path:
            '01_Mobile/Driver_B2C/03_driver_deliveries__populated__$localeCode.png',
      ),
      (
        session: b2bSession,
        route: '/driver/b2b/deliveries',
        path:
            '01_Mobile/Driver_B2B/03_driver_deliveries__premium__$localeCode.png',
      ),
      (
        session: b2cSession,
        route: '/driver/b2c/notifications',
        path:
            '01_Mobile/Driver_B2C/08_driver_notifications__populated__$localeCode.png',
      ),
    ]) {
      testWidgets('capture ${item.path}', (tester) async {
        await _setup(tester);
        await _captureApp(
          tester,
          FoodexDriverApp(
            theme: FoodexTheme.light(fontFamily: _evidenceFontFamily),
            initialSession: item.session,
            // The Home capture starts at the same root as a real login, so
            // no synthetic back arrow is added by Flutter's initial route stack.
            initialRoute: item.route.endsWith('/home') ? '/' : item.route,
            locale: locale,
            authRepository: const _EvidenceAuthRepository(),
            assignmentRepositoryFactory: (_) => item.route.endsWith('/home')
                ? const _EvidenceDashboardAssignments()
                : const _EvidenceAssignments(),
            notificationRepositoryFactory: (_) =>
                const _EvidenceNotifications(),
          ),
          item.path,
        );
      });
    }

    testWidgets('capture B2C delivery detail action sheet $localeCode',
        (tester) async {
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
            notificationRepositoryFactory: (_) =>
                const _EvidenceNotifications(),
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

      expect(find.textContaining(localeCode == 'ar' ? 'طلب #41' : 'Order #41'),
          findsOneWidget);
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
    throw StateError(
        'FLUTTER_ROOT is required for readable Material Icons evidence.');
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

class _EvidenceCollectedWallet implements DriverWalletRepository {
  const _EvidenceCollectedWallet();

  @override
  Future<List<DriverWalletAccount>> wallet() async => const [
        DriverWalletAccount(
          id: 6,
          currency: 'KWD',
          status: 'active',
          custodyBalance: 15,
          availableToRemit: 15,
          totalCollected: 15,
          collectedOrders: [
            DriverCollectedOrder(
              orderId: 123,
              orderNumber: 'FO-123',
              currency: 'KWD',
              orderTotal: 30,
              collectedAmount: 15,
              remainingAmount: 15,
              paymentState: 'partially_settled',
            ),
          ],
        ),
      ];

  @override
  Future<DriverCollectionResult> collect(
    int assignmentId, {
    required double amount,
    required String idempotencyKey,
  }) {
    throw UnimplementedError();
  }

  @override
  Future<DriverRemittance> submitRemittance({
    required int collectionAccountId,
    required double amount,
    required String method,
    String? reference,
    String? note,
    required String idempotencyKey,
  }) {
    throw UnimplementedError();
  }
}

class _EvidenceRememberedSessionStore implements DriverSessionStore {
  const _EvidenceRememberedSessionStore(this.session);

  final DriverSession session;

  @override
  Future<DriverStoredSession?> read() async =>
      DriverStoredSession(session: session, biometricEnabled: true);

  @override
  Future<void> write(
    DriverSession session, {
    required bool biometricEnabled,
  }) async {}

  @override
  Future<void> clear() async {}
}

class _EvidenceBiometric implements DriverBiometricAuthenticator {
  const _EvidenceBiometric();

  @override
  Future<bool> isAvailable() async => true;

  @override
  Future<bool> authenticate({required String reason}) async => true;
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
          reference:
              channel == DriverChannel.b2c ? '#FOODEX-41' : '#FOODEX-B2B-141',
          status: 'assigned',
          storeName: channel == DriverChannel.b2b ? 'FOODEX Wholesale' : '',
          customerName: channel == DriverChannel.b2b ? 'سوبر ماركت - 1' : '',
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
          reference:
              channel == DriverChannel.b2c ? '#FOODEX-42' : '#FOODEX-B2B-142',
          status: 'out_for_delivery',
          availableStatuses: const ['delivered'],
        ),
      ];

  @override
  Future<void> transition(int id, DriverChannel channel, String status,
      {String? note, String? failureReason}) async {}
}

/// Snapshot-only fixture: no mock currency or balances enter production.
class _EvidenceDashboardAssignments extends _EvidenceAssignments
    implements DriverWalletRepository {
  const _EvidenceDashboardAssignments();

  @override
  Future<List<DriverWalletAccount>> wallet() async => const [
        DriverWalletAccount(
          id: 71,
          currency: 'EGP',
          status: 'active',
          custodyBalance: 1712.5,
          unsettledCollections: 1712.5,
          availableToRemit: 1712.5,
          totalCollected: 1712.5,
          transactions: [
            DriverCollectionReceipt(
              id: 701,
              amount: 187.5,
              currency: 'EGP',
              status: 'posted',
              createdAt: '2026-10-29T09:00:00Z',
            ),
            DriverCollectionReceipt(
              id: 702,
              amount: 662.5,
              currency: 'EGP',
              status: 'posted',
              createdAt: '2026-10-28T18:00:00Z',
            ),
            DriverCollectionReceipt(
              id: 703,
              amount: 862.5,
              currency: 'EGP',
              status: 'posted',
              createdAt: '2026-10-27T16:00:00Z',
            ),
          ],
        ),
      ];

  @override
  Future<DriverCollectionResult> collect(
    int assignmentId, {
    required double amount,
    required String idempotencyKey,
  }) async =>
      throw UnsupportedError('Screenshot evidence must not mutate finance');

  @override
  Future<DriverRemittance> submitRemittance({
    required int collectionAccountId,
    required double amount,
    required String method,
    String? reference,
    String? note,
    required String idempotencyKey,
  }) async =>
      throw UnsupportedError('Screenshot evidence must not mutate finance');
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
  Future<void> transition(int id, DriverChannel channel, String status,
      {String? note, String? failureReason}) async {}
}

class _OfflineAssignments implements DriverAssignmentRepository {
  const _OfflineAssignments();

  @override
  Future<List<DriverAssignment>> list(DriverChannel channel) async =>
      throw const DriverOfflineException();

  @override
  Future<void> transition(int id, DriverChannel channel, String status,
          {String? note, String? failureReason}) async =>
      throw const DriverOfflineException();
}
