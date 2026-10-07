
import 'dart:io';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_van_app/app.dart';
import 'package:foodex_van_app/core/auth/van_auth_persistence.dart';
import 'package:foodex_van_app/core/auth/van_session.dart';
import 'package:foodex_van_app/core/auth/van_session_store.dart';
import 'package:foodex_van_app/core/theme/foodex_van_theme.dart';
import 'package:foodex_van_app/features/commercial/van_commercial_contract.dart';
import 'package:foodex_van_app/features/foundation/van_screen_inventory.dart';
import 'package:foodex_van_app/features/notifications/van_notification_contract.dart';
import 'package:foodex_van_app/features/orders/van_order_contract.dart';
import 'package:foodex_van_app/features/visits/van_visit_contract.dart';
import 'package:foodex_van_app/features/wallet/van_wallet_contract.dart';

const _fontFamily = 'FoodexEvidence';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  setUpAll(_loadEvidenceFont);

  for (final locale in const [Locale('ar'), Locale('en')]) {
    final code = locale.languageCode;

    testWidgets('capture Van login $code', (tester) async {
      await _setup(tester, const Size(430, 932));
      await _capture(
        tester,
        FoodexVanApp(
          locale: locale,
          theme: FoodexVanTheme.light(fontFamily: _fontFamily),
          authRepository: const _AuthRepository(),
          sessionStore: const _EmptySessionStore(),
          authPreferenceStore: const _EmptyPreferenceStore(),
          biometricAuthenticator: const _NoBiometric(),
        ),
        '03_Van/login__$code.png',
      );
    });

    for (final screen in vanProductionScreenInventory.where(
      (screen) => screen != VanScreenId.login,
    )) {
      testWidgets('capture Van ${screen.name} $code', (tester) async {
        await _setup(tester, const Size(430, 932));
        final key = GlobalKey();
        await tester.pumpWidget(
          RepaintBoundary(
            key: key,
            child: FoodexVanApp(
              locale: locale,
              theme: FoodexVanTheme.light(fontFamily: _fontFamily),
              initialSession: VanSession(
                token: 'evidence-token',
                name: code == 'ar' ? 'أحمد · مندوب FOODEX' : 'FOODEX Van Ahmed',
                email: 'van@foodex.test',
                locale: code,
                permissions: const {'van.login', 'finance.view'},
              ),
              walletRepository: const _Wallet(),
              commercialRepository: const _Commercial(),
              visitRepository: const _Visits(),
              orderRepository: _Orders(),
              notificationRepository: const _Notifications(),
            ),
          ),
        );
        await tester.pumpAndSettle();

        if (screen == VanScreenId.orderReview) {
          await _openScreen(tester, VanScreenId.catalog);
          await tester.tap(find.byKey(const ValueKey('van-catalog-add-301')));
          await tester.pumpAndSettle();
          await tester.tap(find.byKey(const ValueKey('van-catalog-open-cart')));
          await tester.pumpAndSettle();
          await tester.tap(
            find.byKey(const ValueKey('van-order-builder-review')),
          );
          await tester.pumpAndSettle();
        } else if (screen != VanScreenId.dashboard) {
          await _openScreen(tester, screen);
        }

        await _writeBoundary(
          tester,
          key,
          '03_Van/${screen.name}__$code.png',
        );
      });
    }

    for (final screen in const [
      VanScreenId.dashboard,
      VanScreenId.routeMap,
      VanScreenId.catalog,
    ]) {
      testWidgets('capture compact Van ${screen.name} $code',
          (tester) async {
        await _setup(tester, const Size(360, 800));
        final key = GlobalKey();
        await tester.pumpWidget(
          RepaintBoundary(
            key: key,
            child: FoodexVanApp(
              locale: locale,
              theme: FoodexVanTheme.light(fontFamily: _fontFamily),
              initialSession: VanSession(
                token: 'evidence-token',
                name: code == 'ar' ? 'أحمد · مندوب FOODEX' : 'FOODEX Van Ahmed',
                email: 'van@foodex.test',
                locale: code,
                permissions: const {'van.login', 'finance.view'},
              ),
              walletRepository: const _Wallet(),
              commercialRepository: const _Commercial(),
              visitRepository: const _Visits(),
              orderRepository: _Orders(),
              notificationRepository: const _Notifications(),
            ),
          ),
        );
        await tester.pumpAndSettle();
        if (screen != VanScreenId.dashboard) {
          await _openScreen(tester, screen);
        }
        await _writeBoundary(
          tester,
          key,
          '03_Van_Compact/${screen.name}__$code.png',
        );
      });
    }
  }
}

Future<void> _setup(WidgetTester tester, Size size) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1;
  addTearDown(() {
    tester.view.resetPhysicalSize();
    tester.view.resetDevicePixelRatio();
  });
}

Future<void> _openScreen(WidgetTester tester, VanScreenId screen) async {
  final scaffold = tester.state<ScaffoldState>(find.byType(Scaffold).last);
  scaffold.openDrawer();
  await tester.pumpAndSettle();
  final target = find.byKey(ValueKey('van-screen-${screen.name}'));
  final menu = find.byKey(const ValueKey('van-production-screen-menu'));
  final menuScrollable = find.descendant(
    of: menu,
    matching: find.byType(Scrollable),
  );
  await tester.scrollUntilVisible(
    target,
    180,
    scrollable: menuScrollable.first,
  );
  await tester.tap(target);
  await tester.pumpAndSettle();
}

Future<void> _capture(
  WidgetTester tester,
  Widget app,
  String relativePath,
) async {
  final key = GlobalKey();
  await tester.pumpWidget(RepaintBoundary(key: key, child: app));
  await tester.pumpAndSettle();
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
    expect(file.lengthSync(), greaterThan(1000));
  });
}

Future<void> _loadEvidenceFont() async {
  const candidates = <String>[
    '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
    '/usr/share/fonts/truetype/noto/NotoSansArabic-Regular.ttf',
    '/System/Library/Fonts/Supplemental/Arial Unicode.ttf',
    'C:/Windows/Fonts/arial.ttf',
  ];
  File? fontFile;
  for (final path in candidates) {
    final file = File(path);
    if (file.existsSync()) {
      fontFile = file;
      break;
    }
  }
  if (fontFile == null) throw StateError('No Arabic-capable evidence font.');
  final loader = FontLoader(_fontFamily)
    ..addFont(
      Future<ByteData>.value(
        ByteData.sublistView(await fontFile.readAsBytes()),
      ),
    );
  await loader.load();

  final root = Platform.environment['FLUTTER_ROOT'];
  if (root == null || root.isEmpty) throw StateError('FLUTTER_ROOT missing.');
  final iconFile =
      File('$root/bin/cache/artifacts/material_fonts/MaterialIcons-Regular.otf');
  final iconLoader = FontLoader('MaterialIcons')
    ..addFont(
      Future<ByteData>.value(
        ByteData.sublistView(await iconFile.readAsBytes()),
      ),
    );
  await iconLoader.load();
}

class _AuthRepository implements VanAuthRepository {
  const _AuthRepository();
  @override
  Future<VanSession> login({
    required String email,
    required String password,
  }) async =>
      const VanSession(
        token: 'evidence-token',
        name: 'FOODEX Van',
        email: 'van@foodex.test',
        locale: 'en',
        permissions: {'van.login'},
      );
  @override
  Future<void> logout(String token) async {}
}

class _EmptySessionStore implements VanSessionStore {
  const _EmptySessionStore();
  @override
  Future<void> clear() async {}
  @override
  Future<VanSession?> read() async => null;
  @override
  Future<void> write(VanSession session) async {}
}

class _EmptyPreferenceStore implements VanAuthPreferenceStore {
  const _EmptyPreferenceStore();
  @override
  Future<void> clear() async {}
  @override
  Future<VanAuthPreferences?> read() async => const VanAuthPreferences();
  @override
  Future<void> write(VanAuthPreferences preferences) async {}
}

class _NoBiometric implements VanBiometricAuthenticator {
  const _NoBiometric();
  @override
  Future<bool> authenticate({required String reason}) async => false;
  @override
  Future<bool> isAvailable() async => true;
}

class _Wallet implements VanWalletRepository {
  const _Wallet();
  @override
  Future<List<VanCustomerScope>> customers() async => const [
        VanCustomerScope(
          type: 'b2b',
          id: 42,
          name: 'Al Noor Market',
          storeId: 7,
        ),
      ];
  @override
  Future<List<VanWalletAccount>> wallet() async => const [
        VanWalletAccount(
          id: 5,
          storeId: 7,
          currency: 'KWD',
          status: 'active',
          custodyBalance: 128.5,
          availableToRemit: 90,
          receipts: [
            VanReceipt(
              id: 77,
              amount: 24.75,
              currency: 'KWD',
              status: 'posted',
              createdAt: '2026-10-07T09:00:00+03:00',
            ),
          ],
          remittances: [],
        ),
      ];
  @override
  Future<VanCollectionContext> collectionContext(
    VanCustomerScope customer,
  ) async =>
      VanCollectionContext(
        storeId: customer.storeId,
        invoices: const [
          VanInvoiceBalance(
            id: 42,
            number: 'INV-2048',
            currency: 'KWD',
            total: 40,
            outstandingAmount: 24.75,
          ),
        ],
      );
  @override
  Future<VanCollectionResult> collect({
    required VanCustomerScope customer,
    required int invoiceId,
    required double amount,
    required String idempotencyKey,
  }) async =>
      VanCollectionResult(
        receipt: VanReceipt(
          id: 90,
          amount: amount,
          currency: 'KWD',
          status: 'posted',
          createdAt: '2026-10-07T10:00:00+03:00',
        ),
        remainingOutstanding: 0,
        wallet: const VanWalletAccount(
          id: 5,
          storeId: 7,
          currency: 'KWD',
          status: 'active',
          custodyBalance: 153.25,
          availableToRemit: 114.75,
          receipts: [],
          remittances: [],
        ),
      );
  @override
  Future<VanWalletAccount> remit({
    required int collectionAccountId,
    required double amount,
    required String method,
    required String idempotencyKey,
    String? reference,
    String? note,
  }) async =>
      const VanWalletAccount(
        id: 5,
        storeId: 7,
        currency: 'KWD',
        status: 'active',
        custodyBalance: 90,
        availableToRemit: 51.5,
        receipts: [],
        remittances: [],
      );
}

class _Commercial implements VanCommercialRepository {
  const _Commercial();

  @override
  Future<VanCommercialOfferFeed> offersFor(VanCustomerScope customer) async =>
      VanCommercialOfferFeed(
        serverTime: DateTime.parse('2026-10-07T09:00:00+03:00'),
        offers: const [
          VanCommercialOffer(
            id: 801,
            storeId: 7,
            titleAr: 'عرض المسار اليوم',
            titleEn: 'Today route offer',
            bodyAr: 'سعر خاص لعملاء المسار.',
            bodyEn: 'Special price for route customers.',
            status: 'active',
            startsAt: null,
            endsAt: null,
            priority: 10,
            reservationSeconds: 300,
            products: [
              VanCommercialOfferProduct(
                id: 901,
                productId: 301,
                sellingUnitCode: 'CASE',
                conversionFactor: 1,
                flashPrice: 10.5,
              ),
            ],
          ),
        ],
        normalOffers: const [
          VanNormalOffer(id: 701, name: 'Route discount', type: 'percentage', value: 5),
        ],
      );

  @override
  Future<VanCommercialQuote> quoteForCustomer({
    required VanCustomerScope customer,
    required int productId,
    required String sellingUnitCode,
    required double quantity,
    String? overrideReason,
  }) async =>
      const VanCommercialQuote(
        allowed: true,
        status: 'allowed',
        reasonCodes: [],
        sellingUnit: VanSellingUnit(
          code: 'CASE',
          name: 'Case',
          conversionFactor: 1,
          price: 10.5,
        ),
        sellingUnits: [
          VanSellingUnit(
            code: 'CASE',
            name: 'Case',
            conversionFactor: 1,
            price: 10.5,
          ),
        ],
        overrideApplied: false,
      );

  @override
  Future<void> reserveFlashForCustomer({
    required VanCustomerScope customer,
    required int offerProductId,
    required double quantity,
    required String idempotencyKey,
    String? overrideReason,
  }) async {}
}

class _Visits implements VanVisitRepository {
  const _Visits();
  @override
  Future<List<VanVisitRecord>> visits({String? status}) async => const [
        VanVisitRecord(
          id: 501,
          customerType: 'b2b',
          customerId: 42,
          storeId: 7,
          routeKey: 'R-024',
          latitude: 29.3375,
          longitude: 47.6581,
          address: 'Block 3 · Street 17',
          status: 'started',
          plannedAt: '2026-10-07T09:45:00+03:00',
          allowedTransitions: [
            'completed_with_order',
            'completed_no_order',
            'customer_unavailable',
          ],
        ),
        VanVisitRecord(
          id: 502,
          customerType: 'b2b',
          customerId: 42,
          storeId: 7,
          routeKey: 'R-024',
          latitude: 29.3412,
          longitude: 47.6654,
          address: 'Block 5 · Street 9',
          status: 'planned',
          plannedAt: '2026-10-07T10:30:00+03:00',
          allowedTransitions: ['started', 'customer_unavailable'],
        ),
      ];
  @override
  Future<List<VanNoOrderReasonRecord>> noOrderReasons() async => const [
        VanNoOrderReasonRecord(
          id: 9,
          code: 'customer_declined',
          labelEn: 'Customer declined',
          labelAr: 'رفض العميل',
        ),
      ];
  @override
  Future<VanVisitRecord> transition({
    required int visitId,
    required String status,
    int? orderId,
    int? noOrderReasonId,
  }) async =>
      VanVisitRecord(
        id: visitId,
        customerType: 'b2b',
        customerId: 42,
        storeId: 7,
        routeKey: 'R-024',
        status: status,
        orderId: orderId,
        noOrderReasonId: noOrderReasonId,
        allowedTransitions: const ['closed'],
      );
}

class _Orders implements VanOrderRepository {
  @override
  Future<List<VanCatalogProduct>> catalog(
    VanCustomerScope customer, {
    String search = '',
  }) async =>
      const [
        VanCatalogProduct(
          id: 301,
          name: 'FOODEX Water Case',
          sku: 'WATER-12',
          unitPrice: 12,
          minimumQuantity: 1,
          orderingIncrement: 1,
          availableQuantity: 40,
          isAvailable: true,
          currency: 'KWD',
        ),
      ];
  @override
  Future<VanOrderOptions> options(VanCustomerScope customer) async =>
      const VanOrderOptions(
        addresses: [
          VanOrderAddressOption(
            id: 11,
            label: 'Main',
            address: 'Block 3 · Street 17',
            isDefault: true,
          ),
        ],
        warehouses: [
          VanWarehouseOption(id: 21, code: 'WH-A', name: 'Main Warehouse'),
        ],
        paymentMethods: ['cash_on_delivery'],
        defaultPaymentMethod: 'cash_on_delivery',
      );
  @override
  Future<VanOrderQuote> quote({
    required VanCustomerScope customer,
    required List<VanOrderLine> lines,
    required String paymentMethod,
    int? addressId,
    int? warehouseId,
    String? customerNote,
  }) async =>
      const VanOrderQuote(
        currency: 'KWD',
        subtotal: 12,
        discountTotal: 0,
        deliveryTotal: 0,
        taxTotal: 0,
        grandTotal: 12,
        hasUnavailableItems: false,
      );
  @override
  Future<VanOrderRecord> createOrder({
    required VanCustomerScope customer,
    required List<VanOrderLine> lines,
    required String paymentMethod,
    required String idempotencyKey,
    int? addressId,
    int? warehouseId,
    String? customerNote,
  }) async =>
      const VanOrderRecord(
        id: 7001,
        orderNumber: 'FDX-B2B-20261007-A1',
        customerType: 'b2b',
        customerId: 42,
        storeId: 7,
        status: 'pending',
        currency: 'KWD',
        grandTotal: 12,
      );
  @override
  Future<List<VanOrderRecord>> orders({
    VanCustomerScope? customer,
    String? status,
  }) async => const [
        VanOrderRecord(
          id: 7001,
          orderNumber: 'FDX-B2B-20261007-A1',
          customerType: 'b2b',
          customerId: 42,
          storeId: 7,
          status: 'pending',
          currency: 'KWD',
          grandTotal: 12,
        ),
      ];
}

class _Notifications implements VanNotificationRepository {
  const _Notifications();
  @override
  Future<List<VanNotificationRecord>> notifications({
    required String locale,
  }) async =>
      [
        VanNotificationRecord(
          id: 91,
          type: 'route_updated',
          title: locale == 'ar' ? 'تم تحديث المسار' : 'Route updated',
          body: locale == 'ar'
              ? 'تم تعديل ترتيب الزيارات.'
              : 'Stop sequence changed.',
          publishedAt: '2026-10-07T09:00:00+03:00',
          read: false,
        ),
      ];
  @override
  Future<void> markRead(int notificationId) async {}
}
