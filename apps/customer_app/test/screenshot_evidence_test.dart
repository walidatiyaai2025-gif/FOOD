import 'dart:io';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:foodex_customer_app/app.dart';
import 'package:foodex_customer_app/core/api/b2b_api.dart';
import 'package:foodex_customer_app/core/api/b2c_account_api.dart';
import 'package:foodex_customer_app/core/api/b2c_catalog_api.dart';
import 'package:foodex_customer_app/core/api/customer_action_api.dart';
import 'package:foodex_customer_app/core/api/storefront_api.dart';
import 'package:foodex_customer_app/core/api/wholesale_commerce_api.dart';
import 'package:foodex_customer_app/core/auth/customer_session.dart';
import 'package:foodex_customer_app/core/localization/app_translations.dart';
import 'package:foodex_customer_app/core/theme/foodex_theme.dart';
import 'package:foodex_customer_app/features/customer_orders/customer_order_models.dart';
import 'package:foodex_customer_app/features/customer_orders/customer_orders_api.dart';
import 'package:foodex_customer_app/features/retail/commerce/retail_commerce_api.dart';
import 'package:foodex_customer_app/features/retail/commerce/retail_commerce_screens.dart';
import 'package:foodex_customer_app/features/store_analytics/store_analytics_screen.dart';
import 'package:foodex_customer_app/features/storefront/wholesale_multistore_screens.dart';

const _b2b = CustomerSession.authenticated(
  CustomerChannel.b2b,
  accessToken: 'evidence-token',
);
const _b2c = CustomerSession.authenticated(
  CustomerChannel.b2c,
  accessToken: 'evidence-token',
);
const _b2cWholesale = CustomerSession.authenticated(
  CustomerChannel.b2c,
  accessToken: 'evidence-token',
  b2bRetailStoreId: 7,
);

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  setUpAll(_loadEvidenceFont);

  final cases = <_CaptureCase>[
    const _CaptureCase(
      '02_MultiStore/01_store_selection__ar.png',
      '/customer/store-selector',
      session: _b2c,
    ),
    const _CaptureCase(
      '02_MultiStore/02_retail_grocery_home__ar.png',
      '/retail/7/home',
      session: _b2c,
    ),
    const _CaptureCase(
      '02_MultiStore/03_retail_pharmacy_home__ar.png',
      '/retail/8/home',
      session: _b2c,
    ),
    const _CaptureCase(
      '02_MultiStore/04_wholesale_home__ar.png',
      '/b2b/home?store_id=70',
      session: _b2cWholesale,
    ),
    const _CaptureCase(
      '02_MultiStore/05_retail_product_details__ar.png',
      '/retail/7/products/42',
      session: _b2c,
    ),
    const _CaptureCase(
      '02_MultiStore/06_wholesale_product_details__ar.png',
      '/b2b/products/42?store_id=70',
      session: _b2cWholesale,
    ),
    const _CaptureCase(
      '02_MultiStore/07_wholesale_cart__ar.png',
      '/b2b/cart?store=70',
      session: _b2cWholesale,
    ),
    const _CaptureCase(
      '02_MultiStore/08_wholesale_checkout__ar.png',
      '/b2b/checkout?store_id=70',
      session: _b2cWholesale,
    ),
    const _CaptureCase(
      '02_MultiStore/09_wholesale_orders__ar.png',
      '/b2b/orders?channel=wholesale&store_id=70',
      session: _b2cWholesale,
    ),
    // C13 #873 final integrated exact-head matrix: capture Screens 1-13 in the existing AR/EN locale loop.
    const _CaptureCase(
      '01_Mobile/B2B_Customer/01_شاشة_الدخول__default__ar.png',
      '/entry',
    ),
    const _CaptureCase(
      '01_Mobile/B2B_Customer/02_الصفحة_الرئيسية_Dashboard__populated__ar.png',
      '/b2b/dashboard',
      session: _b2b,
    ),
    const _CaptureCase(
      '01_Mobile/B2B_Customer/03_تقارير_المشتريات_والرسوم_البيانية__populated__ar.png',
      '/b2b/reports/purchases',
      session: _b2b,
    ),
    const _CaptureCase(
      '01_Mobile/B2B_Customer/04_أكثر_المنتجات_طلبا__populated__ar.png',
      '/b2b/products/top?from=2026-09-01&to=2026-09-30',
      session: _b2b,
    ),
    const _CaptureCase(
      '01_Mobile/B2B_Customer/05_آخر_الفواتير__populated__ar.png',
      '/b2b/invoices',
      session: _b2b,
    ),
    const _CaptureCase(
      '01_Mobile/B2B_Customer/06_كشف_الحساب_والمعاملات__populated__ar.png',
      '/b2b/account-statement',
      session: _b2b,
    ),
    const _CaptureCase(
      '01_Mobile/B2B_Customer/07_طلباتي__populated__ar.png',
      '/b2b/orders?channel=wholesale&store_id=7',
      session: _b2b,
    ),
    const _CaptureCase(
      '01_Mobile/B2B_Customer/08_تفاصيل_الطلب_وتتبع_الحالة__populated__ar.png',
      '/b2b/orders/77',
      session: _b2b,
    ),
    // C13 #868 exact-head evidence: Screen 9 is captured in both AR/RTL and EN/LTR by the locale loop.
    const _CaptureCase(
      '01_Mobile/B2B_Customer/09_تفاصيل_الفاتورة__populated__ar.png',
      '/b2b/invoices/31',
      session: _b2b,
    ),
    const _CaptureCase(
      '01_Mobile/B2B_Customer/10_تصفح_المنتجات__populated__ar.png',
      '/b2b/products?channel=wholesale&store_id=7',
      session: _b2b,
    ),
    const _CaptureCase(
      '01_Mobile/B2B_Customer/10b_التصنيفات__populated__ar.png',
      '/b2b/products?channel=wholesale&store_id=7&browse=categories',
      session: _b2b,
    ),
    // C13 Screen 11: exact-head product-detail hierarchy evidence.
    const _CaptureCase(
      '01_Mobile/B2B_Customer/11_تفاصيل_المنتج_وإضافة_للسلة__populated__ar.png',
      '/b2b/products/42?store_id=7',
      session: _b2b,
    ),
    const _CaptureCase(
      '01_Mobile/B2B_Customer/12_سلة_المشتريات_وإتمام_الطلب__populated__ar.png',
      '/b2b/cart?store=7',
      session: _b2b,
    ),
    const _CaptureCase(
      '01_Mobile/B2B_Customer/13_حسابي_والإعدادات__populated__ar.png',
      '/b2b/profile',
      session: _b2b,
    ),
    const _CaptureCase(
      '01_Mobile/B2C_Customer/01_الشاشة_الافتتاحية__default__ar.png',
      '/splash',
    ),
    const _CaptureCase(
      '01_Mobile/B2C_Customer/02_تصفح_كضيف_او_تسجيل_الدخول__default__ar.png',
      '/entry',
    ),
    const _CaptureCase(
      '01_Mobile/B2C_Customer/04_الصفحة_الرئيسية_للمتجر__populated__ar.png',
      '/home?channel=retail&store_id=7',
    ),
    const _CaptureCase(
      '01_Mobile/B2C_Customer/05_عروض_وتخفيضات__populated__ar.png',
      '/offers?channel=retail&store_id=7',
    ),
    const _CaptureCase(
      '01_Mobile/B2C_Customer/06_قائمة_المنتجات_والفلاتر__populated__ar.png',
      '/products?channel=retail&store_id=7',
    ),
    const _CaptureCase(
      '01_Mobile/B2C_Customer/07_تفاصيل_المنتج__populated__ar.png',
      '/products/42?channel=retail&store_id=7',
    ),
    const _CaptureCase(
      '01_Mobile/B2C_Customer/08_سلة_التسوق__populated__ar.png',
      '/cart?channel=retail&store_id=7',
    ),
    const _CaptureCase(
      '01_Mobile/B2C_Customer/09_تسجيل_الدخول_لإتمام_الطلب__default__ar.png',
      '/auth/checkout?channel=retail&store_id=7&next=%2Fcheckout%2Faddress-payment%3Fchannel%3Dretail%26store_id%3D7',
    ),
    const _CaptureCase(
      '01_Mobile/B2C_Customer/10_العنوان_والدفع__default__ar.png',
      '/checkout/address-payment?channel=retail&store_id=7',
      session: _b2c,
    ),
    const _CaptureCase(
      '01_Mobile/B2C_Customer/11_تتبع_الطلب__populated__ar.png',
      '/orders/101/track?channel=retail&store_id=7',
      session: _b2c,
    ),
    const _CaptureCase(
      '01_Mobile/B2C_Customer/12_الملف_الشخصي_والمفضلة__populated__ar.png',
      '/profile?channel=retail&store_id=7',
      session: _b2c,
    ),
    const _CaptureCase(
      '01_Mobile/B2C_Customer/13_عناويني__populated__ar.png',
      '/profile/addresses?channel=retail&store_id=7',
      session: _b2c,
    ),
    const _CaptureCase(
      '01_Mobile/B2C_Customer/15_التصنيفات__populated__ar.png',
      '/categories?channel=retail&store_id=7',
      session: _b2c,
    ),
    const _CaptureCase(
      '01_Mobile/B2C_Customer/16_المفضلة__populated__ar.png',
      '/favorites?channel=retail&store_id=7',
      session: _b2c,
    ),
    const _CaptureCase(
      '01_Mobile/B2C_Customer/17_طلباتي__populated__ar.png',
      '/orders?channel=retail&store_id=7',
      session: _b2c,
    ),
    const _CaptureCase(
      '01_Mobile/B2C_Customer/18_الإشعارات__populated__ar.png',
      '/notifications?channel=retail&store_id=7',
      session: _b2c,
    ),
    const _CaptureCase(
      '01_Mobile/B2C_Customer/19_الإعدادات_والمساعدة__populated__ar.png',
      '/profile/settings?channel=retail&store_id=7',
      session: _b2c,
    ),
  ];

  for (final locale in const [Locale('ar'), Locale('en')]) {
    final localeCode = locale.languageCode;
    for (final item in cases) {
      final path = item.path.replaceAll('__ar.png', '__$localeCode.png');
      testWidgets('capture $path', (tester) async {
        tester.view.physicalSize = const Size(430, 932);
        tester.view.devicePixelRatio = 1;
        addTearDown(() {
          tester.view.resetPhysicalSize();
          tester.view.resetDevicePixelRatio();
        });

        final boundaryKey = GlobalKey();
        await tester.pumpWidget(
          RepaintBoundary(
            key: boundaryKey,
            child: FoodexCustomerApp(
              theme: FoodexTheme.light(fontFamily: _evidenceFontFamily),
              key: ValueKey('${item.route}-$localeCode'),
              session: item.session ?? const CustomerSession.guest(),
              initialRoute: item.route,
              b2bApi: const _EvidenceB2bApi(),
              b2cCatalogApi: _EvidenceCatalogApi(localeCode),
              b2cAccountApi: _EvidenceAccountApi(localeCode),
              actionApi: const _EvidenceActionApi(),
              storefrontApi: const _EvidenceStorefrontApi(),
              wholesaleCommerceApi: const _EvidenceWholesaleCommerceApi(),
              retailCommerceApi: _EvidenceRetailCommerceApi(localeCode),
              customerOrdersApi: _EvidenceOrdersApi(localeCode),
              locale: locale,
            ),
          ),
        );
        await tester.pump();
        await tester.pump(const Duration(milliseconds: 150));

        if (item.route == '/cart?channel=retail&store_id=7' ||
            item.route ==
                '/checkout/address-payment?channel=retail&store_id=7') {
          await tester.pumpAndSettle();
        }

        if (item.route == '/cart?channel=retail&store_id=7') {
          expect(
            find.byKey(const ValueKey('retail-cart-summary')),
            findsOneWidget,
          );
        }

        if (item.route ==
            '/checkout/address-payment?channel=retail&store_id=7') {
          expect(
            find.byKey(const ValueKey('retail-checkout-progress')),
            findsOneWidget,
          );
        }

        if (item.route == '/b2b/account-statement') {
          expect(
            find.byKey(const ValueKey('b2b-statement-summary-closing')),
            findsOneWidget,
          );
          expect(
            find.byKey(const ValueKey('b2b-statement-direction-chip')),
            findsOneWidget,
          );
        }

        await tester.runAsync(() async {
          final boundary = boundaryKey.currentContext!.findRenderObject()!
              as RenderRepaintBoundary;
          final image = await boundary.toImage(pixelRatio: 1);
          final data = await image.toByteData(format: ui.ImageByteFormat.png);
          final file = File('../../ScreenShots/$path');
          file.parent.createSync(recursive: true);
          file.writeAsBytesSync(data!.buffer.asUint8List(), flush: true);
          expect(file.lengthSync(), greaterThan(1000));
        });
      });
    }

    testWidgets('capture retail order success $localeCode', (tester) async {
      tester.view.physicalSize = const Size(430, 932);
      tester.view.devicePixelRatio = 1;
      addTearDown(() {
        tester.view.resetPhysicalSize();
        tester.view.resetDevicePixelRatio();
      });

      final boundaryKey = GlobalKey();
      await tester.pumpWidget(
        RepaintBoundary(
          key: boundaryKey,
          child: MaterialApp(
            locale: locale,
            theme: FoodexTheme.light(fontFamily: _evidenceFontFamily),
            home: Directionality(
              textDirection:
                  localeCode == 'ar' ? TextDirection.rtl : TextDirection.ltr,
              child: RetailCheckoutScreen(
                storeId: 7,
                api: const _EvidenceRetailCommerceApi(),
                onTrackOrder: (_, __) {},
                onContinueShopping: () {},
              ),
            ),
          ),
        ),
      );
      await tester.pumpAndSettle();
      await tester.ensureVisible(
        find.byKey(const ValueKey('retail-checkout-submit')),
      );
      await tester.tap(find.byKey(const ValueKey('retail-checkout-submit')));
      await tester.pumpAndSettle();

      expect(
        find.byKey(const ValueKey('customer-order-success')),
        findsOneWidget,
      );

      await tester.runAsync(() async {
        final boundary = boundaryKey.currentContext!.findRenderObject()!
            as RenderRepaintBoundary;
        final image = await boundary.toImage(pixelRatio: 1);
        final data = await image.toByteData(format: ui.ImageByteFormat.png);
        final file = File(
          '../../ScreenShots/01_Mobile/B2C_Customer/20_تأكيد_نجاح_الطلب__populated__$localeCode.png',
        );
        file.parent.createSync(recursive: true);
        file.writeAsBytesSync(data!.buffer.asUint8List(), flush: true);
        expect(file.lengthSync(), greaterThan(1000));
      });
    });

    testWidgets('capture wholesale order success $localeCode', (tester) async {
      tester.view.physicalSize = const Size(430, 932);
      tester.view.devicePixelRatio = 1;
      addTearDown(() {
        tester.view.resetPhysicalSize();
        tester.view.resetDevicePixelRatio();
      });

      final boundaryKey = GlobalKey();
      await tester.pumpWidget(
        RepaintBoundary(
          key: boundaryKey,
          child: MaterialApp(
            theme: FoodexTheme.light(fontFamily: _evidenceFontFamily),
            locale: locale,
            localizationsDelegates: const [
              GlobalMaterialLocalizations.delegate,
              GlobalWidgetsLocalizations.delegate,
              GlobalCupertinoLocalizations.delegate,
            ],
            supportedLocales: const [Locale('ar'), Locale('en')],
            home: AppTranslations(
              locale: locale,
              overrides: const {},
              child: const WholesaleCheckoutDesignScreen(
                location: '/b2b/checkout?store_id=70',
                storefrontApi: _EvidenceStorefrontApi(),
                commerceApi: _EvidenceWholesaleCommerceApi(),
              ),
            ),
          ),
        ),
      );
      await tester.pumpAndSettle();
      final submit = find.byKey(const ValueKey('b2b-checkout-submit'));
      expect(submit, findsOneWidget);
      await tester.ensureVisible(submit);
      await tester.pumpAndSettle();
      await tester.tap(submit);
      await tester.pumpAndSettle();

      expect(
        find.byKey(const ValueKey('customer-order-success')),
        findsOneWidget,
      );

      await tester.runAsync(() async {
        final boundary = boundaryKey.currentContext!.findRenderObject()!
            as RenderRepaintBoundary;
        final image = await boundary.toImage(pixelRatio: 1);
        final data = await image.toByteData(format: ui.ImageByteFormat.png);
        final file = File(
          '../../ScreenShots/01_Mobile/B2B_Customer/14_تأكيد_نجاح_الطلب__populated__$localeCode.png',
        );
        file.parent.createSync(recursive: true);
        file.writeAsBytesSync(data!.buffer.asUint8List(), flush: true);
        expect(file.lengthSync(), greaterThan(1000));
      });
    });

    for (final evidence in <({
      String suffix,
      StoreAnalyticsStatus status,
      bool stale,
      bool longName,
    })>[
      (
        suffix: '15_تحليل_المتجر_الذكي__populated',
        status: StoreAnalyticsStatus.loaded,
        stale: false,
        longName: false,
      ),
      (
        suffix: '16_تحليل_المتجر_الذكي__stale',
        status: StoreAnalyticsStatus.loaded,
        stale: true,
        longName: false,
      ),
      (
        suffix: '17_تحليل_المتجر_الذكي__empty',
        status: StoreAnalyticsStatus.empty,
        stale: false,
        longName: false,
      ),
      (
        suffix: '18_تحليل_المتجر_الذكي__error',
        status: StoreAnalyticsStatus.error,
        stale: false,
        longName: false,
      ),
      (
        suffix: '19_تحليل_المتجر_الذكي__long_name_missing_logo',
        status: StoreAnalyticsStatus.loaded,
        stale: false,
        longName: true,
      ),
    ]) {
      testWidgets(
        'capture Store Analytics ${evidence.suffix} $localeCode',
        (tester) async {
          await _captureStoreAnalyticsEvidence(
            tester,
            locale: locale,
            path: '01_Mobile/B2B_Customer/${evidence.suffix}__$localeCode.png',
            status: evidence.status,
            stale: evidence.stale,
            longName: evidence.longName,
          );
        },
      );
    }

    testWidgets('capture My Addresses edit with location $localeCode', (
      tester,
    ) async {
      tester.view.physicalSize = const Size(430, 932);
      tester.view.devicePixelRatio = 1;
      addTearDown(() {
        tester.view.resetPhysicalSize();
        tester.view.resetDevicePixelRatio();
      });

      final boundaryKey = GlobalKey();
      await tester.pumpWidget(
        RepaintBoundary(
          key: boundaryKey,
          child: FoodexCustomerApp(
            theme: FoodexTheme.light(fontFamily: _evidenceFontFamily),
            session: _b2c,
            initialRoute: '/profile/addresses?channel=retail&store_id=7',
            b2bApi: const _EvidenceB2bApi(),
            b2cCatalogApi: const _EvidenceCatalogApi(),
            b2cAccountApi: const _EvidenceAccountApi(),
            actionApi: const _EvidenceActionApi(),
            storefrontApi: const _EvidenceStorefrontApi(),
            wholesaleCommerceApi: const _EvidenceWholesaleCommerceApi(),
            customerOrdersApi: const _EvidenceOrdersApi(),
            locale: locale,
          ),
        ),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('customer-address-menu-8')));
      await tester.pumpAndSettle();
      await tester.tap(find.byType(PopupMenuItem<String>).first);
      await tester.pumpAndSettle();
      expect(
        find.byKey(const ValueKey('customer-address-coordinates')),
        findsOneWidget,
      );

      await tester.runAsync(() async {
        final boundary = boundaryKey.currentContext!.findRenderObject()!
            as RenderRepaintBoundary;
        final image = await boundary.toImage(pixelRatio: 1);
        final data = await image.toByteData(format: ui.ImageByteFormat.png);
        final file = File(
          '../../ScreenShots/01_Mobile/B2C_Customer/14_تعديل_العنوان_والموقع__populated__$localeCode.png',
        );
        file.parent.createSync(recursive: true);
        file.writeAsBytesSync(data!.buffer.asUint8List(), flush: true);
        expect(file.lengthSync(), greaterThan(1000));
      });
    });
  }
}

Future<void> _captureStoreAnalyticsEvidence(
  WidgetTester tester, {
  required Locale locale,
  required String path,
  required StoreAnalyticsStatus status,
  required bool stale,
  required bool longName,
}) async {
  tester.view.physicalSize = const Size(430, 932);
  tester.view.devicePixelRatio = 1;
  addTearDown(() {
    tester.view.resetPhysicalSize();
    tester.view.resetDevicePixelRatio();
  });

  final localeCode = locale.languageCode;
  final arabic = localeCode == 'ar';
  final boundaryKey = GlobalKey();
  final data = status == StoreAnalyticsStatus.loaded
      ? _storeAnalyticsEvidenceData(
          arabic: arabic,
          stale: stale,
          longName: longName,
        )
      : null;

  await tester.pumpWidget(
    RepaintBoundary(
      key: boundaryKey,
      child: MaterialApp(
        locale: locale,
        theme: FoodexTheme.light(fontFamily: _evidenceFontFamily),
        home: Directionality(
          textDirection: arabic ? TextDirection.rtl : TextDirection.ltr,
          child: StoreAnalyticsScreen(
            copy: _storeAnalyticsEvidenceCopy(arabic),
            status: status,
            data: data,
            storeOptions: status == StoreAnalyticsStatus.loaded
                ? const [
                    StoreAnalyticsStoreOption(
                      id: 7,
                      name: 'FOODEX Fresh Market',
                    ),
                    StoreAnalyticsStoreOption(id: 8, name: 'FOODEX Downtown'),
                  ]
                : const [],
            selectedStoreId: data?.storeId,
            onRetry: () {},
            onRefresh: () async {},
            onStoreChanged: (_) {},
            onDrillDown: (_) {},
          ),
        ),
      ),
    ),
  );
  await tester.pump();
  await tester.pump(const Duration(milliseconds: 150));
  expect(tester.takeException(), isNull);

  await tester.runAsync(() async {
    final boundary = boundaryKey.currentContext!.findRenderObject()!
        as RenderRepaintBoundary;
    final image = await boundary.toImage(pixelRatio: 1);
    final bytes = await image.toByteData(format: ui.ImageByteFormat.png);
    final file = File('../../ScreenShots/$path');
    file.parent.createSync(recursive: true);
    file.writeAsBytesSync(bytes!.buffer.asUint8List(), flush: true);
    expect(file.lengthSync(), greaterThan(1000));
  });
}

StoreAnalyticsData _storeAnalyticsEvidenceData({
  required bool arabic,
  required bool stale,
  required bool longName,
}) =>
    StoreAnalyticsData(
      storeId: 7,
      storeName: longName
          ? (arabic
              ? 'سوق فودكس المركزي للخضروات والفواكه والمواد الغذائية الطازجة'
              : 'FOODEX Central Fresh Grocery, Produce and Daily Essentials Market')
          : (arabic ? 'فودكس ماركت سموحة' : 'FOODEX Fresh Market'),
      logoUrl: null,
      currency: 'EGP',
      periodLabel: arabic ? '6 - 12 أكتوبر 2026' : '6 - 12 Oct 2026',
      freshnessLabel: stale
          ? (arabic ? 'آخر تحديث منذ 26 ساعة' : 'Updated 26 hours ago')
          : (arabic ? 'آخر تحديث منذ 5 دقائق' : 'Updated 5 minutes ago'),
      revenue: 18425.75,
      orders: 53,
      averageOrderValue: 347.66,
      revenueDeltaPercent: -8.4,
      isStale: stale,
      dailySeries: const [
        StoreAnalyticsDailyPoint(dateLabel: '06', sales: 1900, orders: 6),
        StoreAnalyticsDailyPoint(dateLabel: '07', sales: 2700, orders: 8),
        StoreAnalyticsDailyPoint(dateLabel: '08', sales: 2310, orders: 7),
        StoreAnalyticsDailyPoint(dateLabel: '09', sales: 3125, orders: 9),
        StoreAnalyticsDailyPoint(dateLabel: '10', sales: 2880, orders: 8),
        StoreAnalyticsDailyPoint(dateLabel: '11', sales: 2510, orders: 7),
        StoreAnalyticsDailyPoint(dateLabel: '12', sales: 3000.75, orders: 8),
      ],
      insights: [
        StoreAnalyticsInsight(
          id: 'cancelled_orders',
          title: arabic
              ? 'طلبات ملغاة تحتاج مراجعة'
              : 'Cancelled orders need review',
          body: arabic
              ? 'تم إلغاء 3 طلبات خلال الفترة المحددة.'
              : '3 orders were cancelled in the selected period.',
          tone: StoreAnalyticsInsightTone.warning,
          actionLabel: arabic ? 'عرض الطلبات' : 'View orders',
          targetRoute: '/orders?channel=retail&store_id=7',
        ),
        StoreAnalyticsInsight(
          id: 'stock_attention',
          title: arabic ? 'تنبيه مخزون' : 'Stock attention',
          body: arabic
              ? 'هناك منتجان يحتاجان متابعة المخزون.'
              : 'Two products need stock attention.',
          tone: StoreAnalyticsInsightTone.warning,
        ),
        StoreAnalyticsInsight(
          id: 'revenue_change',
          title: arabic ? 'تغير المبيعات' : 'Sales changed',
          body: arabic
              ? 'انخفضت المبيعات مقارنة بالفترة السابقة.'
              : 'Sales decreased compared with the previous period.',
        ),
        StoreAnalyticsInsight(
          id: 'top_product',
          title: arabic ? 'المنتج الأقوى' : 'Top product',
          body: arabic
              ? 'الحليب الطازج حقق أعلى مساهمة في المبيعات.'
              : 'Fresh Milk made the highest sales contribution.',
          tone: StoreAnalyticsInsightTone.positive,
        ),
      ],
    );

StoreAnalyticsCopy _storeAnalyticsEvidenceCopy(bool arabic) => arabic
    ? const StoreAnalyticsCopy(
        title: 'تحليل المتجر الذكي',
        revenue: 'المبيعات',
        orders: 'الطلبات',
        averageOrderValue: 'متوسط قيمة الطلب',
        comparison: 'مقارنة بالفترة السابقة',
        vsPrevious: 'مقابل الفترة السابقة',
        noComparison: '—',
        salesTrend: 'اتجاه المبيعات والطلبات',
        ordersTrend: 'الطلبات',
        trendSummary: 'المبيعات والطلبات حسب اليوم',
        insights: 'أهم التحليلات',
        currencyEgp: 'ج.م',
        stale: 'نعرض آخر بيانات مؤكدة. يمكنك المحاولة مرة أخرى.',
        storeSelector: 'المتجر',
        loadingLabel: 'جارٍ تحميل تحليل المتجر',
        emptyTitle: 'لا توجد بيانات تحليلية للفترة المحددة.',
        emptyMessage: 'لا توجد حركة يومية لهذه الفترة.',
        errorTitle: 'تعذر تحميل تحليل المتجر.',
        errorMessage: 'تحقق من الاتصال ثم أعد المحاولة.',
        noEntitlementTitle: 'تحليل المتجر غير متاح لهذا الحساب.',
        noEntitlementMessage: 'استخدم حساب أعمال مخولًا بإدارة متجر التجزئة.',
        retry: 'إعادة المحاولة',
        refresh: 'تحديث التحليل',
        storeLogo: 'المتجر',
        ordersUnit: 'طلب',
      )
    : const StoreAnalyticsCopy(
        title: 'Smart Store Analytics',
        revenue: 'Sales',
        orders: 'Orders',
        averageOrderValue: 'Average order value',
        comparison: 'Compared with previous period',
        vsPrevious: 'vs previous period',
        noComparison: '—',
        salesTrend: 'Sales and orders trend',
        ordersTrend: 'Orders',
        trendSummary: 'Sales and orders by day',
        insights: 'Priority insights',
        currencyEgp: 'EGP',
        stale: 'Showing the last confirmed data. You can try again.',
        storeSelector: 'Store',
        loadingLabel: 'Loading store analytics',
        emptyTitle: 'No analytics data for the selected period.',
        emptyMessage: 'No daily activity for this period.',
        errorTitle: 'Unable to load store analytics.',
        errorMessage: 'Check the connection and try again.',
        noEntitlementTitle:
            'Store Analytics is not available for this account.',
        noEntitlementMessage:
            'Use a Business account authorized to manage the Retail store.',
        retry: 'Try again',
        refresh: 'Refresh analytics',
        storeLogo: 'Store',
        ordersUnit: 'orders',
      );

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
      'FLUTTER_ROOT is required for readable Material Icons evidence.',
    );
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

class _CaptureCase {
  const _CaptureCase(this.path, this.route, {this.session});
  final String path;
  final String route;
  final CustomerSession? session;
}

class _EvidenceB2bApi implements B2bApi {
  const _EvidenceB2bApi();

  @override
  Future<Object?> get(String path) async {
    if (path.contains('/products/top')) {
      return {
        'data': [
          {
            'rank': 1,
            'product_id': 42,
            'store_id': 7,
            'sku': 'TOP-1',
            'name': 'FOODEX Bulk Rice',
            'quantity': 24,
            'total': 174,
            'currency': 'KWD',
            'last_purchased_at': '2026-09-28',
            'pack_label': 'Case 12',
            'account_price': 7.25,
            'current_price_currency': 'KWD',
            'available_quantity': 240,
            'availability_state': 'AVAILABLE',
            'can_repurchase': true,
            'unavailable_reason': null,
          },
          {
            'rank': 2,
            'product_id': 43,
            'store_id': 7,
            'sku': 'TOP-2',
            'name': 'FOODEX Olive Oil',
            'quantity': 18,
            'total': 333,
            'currency': 'KWD',
            'last_purchased_at': '2026-09-23',
            'pack_label': 'Carton 6',
            'account_price': 18.5,
            'current_price_currency': 'KWD',
            'available_quantity': 0,
            'availability_state': 'OUT_OF_STOCK',
            'can_repurchase': false,
            'unavailable_reason': 'OUT_OF_STOCK',
          },
        ],
        'period': {'from': null, 'to': null},
        'sort': 'quantity',
        'meta': {'page': 1, 'per_page': 20, 'total': 2, 'has_more': false},
      };
    }
    if (path.contains('/products/42')) {
      return {
        'id': 42,
        'sku': 'B2B-P-42',
        'name': 'FOODEX Wholesale Tomato Box',
        'store_id': 7,
        'account_price': 7.25,
        'minimum_order_quantity': 5,
        'price_tier': 'GOLD',
        'category_name': 'Fresh produce',
        'brand_name': 'FOODEX',
        'available_quantity': 240,
        'is_available': true,
        'currency': 'KWD',
      };
    }
    if (path.endsWith('/products') || path.contains('/products?')) {
      return {
        'data': [
          {
            'id': 42,
            'sku': 'B2B-P-42',
            'name': 'FOODEX Wholesale Tomato Box',
            'account_price': 7.25,
            'currency': 'KWD',
            'minimum_order_quantity': 5,
            'ordering_increment': 1,
            'pack_size': 12,
            'available_quantity': 240,
            'is_available': true,
            'category_id': 3,
            'category_name_ar': 'خضار وفواكه',
            'category_name_en': 'Fruit & vegetables',
          },
          {
            'id': 43,
            'sku': 'B2B-P-43',
            'name': 'FOODEX Premium Olive Oil',
            'account_price': 18.5,
            'currency': 'KWD',
            'minimum_order_quantity': 5,
            'ordering_increment': 1,
            'pack_size': 6,
            'available_quantity': 80,
            'is_available': true,
            'category_id': 4,
            'category_name_ar': 'معلبات',
            'category_name_en': 'Pantry',
          },
        ],
      };
    }
    if (path.endsWith('/dashboard')) {
      return {
        'customer': {
          'id': 9,
          'name': 'FOODEX Business Buyer',
          'email': 'buyer@foodex.test',
        },
        'account': {
          'id': 3,
          'company_name': 'FOODEX Business Demo',
          'status': 'active',
        },
        'finance': {
          'currency': 'KWD',
          'balance': 88.5,
          'balance_direction': 'customer_owes_company',
          'credit_limit': 500.0,
          'available_credit_line': 411.5,
          'open_amount': 146.25,
          'overdue_amount': 24.0,
        },
        'operations': {
          'purchases_this_month': 321.75,
          'payments_this_month': 233.25,
          'invoice_count': 4,
          'order_count': 6,
          'active_orders': 2,
        },
        'freshness': {
          'generated_at': DateTime.now().toUtc().toIso8601String(),
          'stale': false,
        },
        'currency': 'KWD',
      };
    }
    if (path.contains('/reports/purchases')) {
      return {
        'currency': 'KWD',
        'period': {'from': '2026-09-01', 'to': '2026-09-30'},
        'summary': {
          'total_purchases': 159.0,
          'order_count': 2,
          'invoice_count': 2,
          'average_order_value': 79.5,
        },
        'comparison': {
          'from': '2026-08-01',
          'to': '2026-08-31',
          'total_purchases': 120.0,
          'order_count': 2,
          'change_amount': 39.0,
          'change_percent': 32.5,
        },
        'data': [
          {'period': '2026-09-12', 'orders_count': 1, 'purchase_total': 48.0},
          {'period': '2026-09-24', 'orders_count': 1, 'purchase_total': 111.0},
        ],
        'categories': [
          {
            'category_id': 1,
            'category_name': 'Staples',
            'purchase_total': 48.0,
            'percentage': 30.19,
          },
          {
            'category_id': 2,
            'category_name': 'Pantry',
            'purchase_total': 111.0,
            'percentage': 69.81,
          },
        ],
        'orders': [
          {
            'id': 77,
            'order_number': 'B2B-77',
            'store_id': 7,
            'status': 'confirmed',
            'currency': 'KWD',
            'grand_total': 48.0,
            'created_at': '2026-09-12T10:00:00Z',
          },
          {
            'id': 78,
            'order_number': 'B2B-78',
            'store_id': 7,
            'status': 'delivered',
            'currency': 'KWD',
            'grand_total': 111.0,
            'created_at': '2026-09-24T11:30:00Z',
          },
        ],
        'meta': {'page': 1, 'per_page': 5, 'total': 2, 'has_more': false},
        'generated_at': '2026-09-30T12:00:00Z',
      };
    }
    if (path.endsWith('/invoices')) {
      return {
        'data': [
          {
            'id': 31,
            'invoice_number': 'INV-31',
            'payment_status': 'paid',
            'total': 55.25,
            'currency': 'KWD',
          },
          {
            'id': 32,
            'invoice_number': 'INV-32',
            'payment_status': 'pending',
            'total': 91.0,
            'currency': 'KWD',
          },
        ],
      };
    }
    if (path.contains('/invoices/31')) {
      return {
        'data': {
          'id': 31,
          'invoice_number': 'INV-31',
          'display_status': 'partially_paid',
          'status': 'partially_paid',
          'currency': 'KWD',
          'subtotal': 20.0,
          'discount_total': 1.0,
          'delivery_total': 2.0,
          'tax_total': 1.0,
          'total': 22.0,
          'paid_amount': 5.0,
          'outstanding_amount': 17.0,
          'credit_amount': 0.0,
          'store_id': 7,
          'order_id': 77,
          'issued_at': '2026-10-01T10:00:00Z',
          'seller': {'store_id': 7, 'name': 'FOODEX Wholesale'},
          'customer': {
            'name': 'FOODEX Business Demo',
            'email': 'buyer@foodex.test',
            'phone': '+96555500000',
          },
          'pdf_path': '/api/v1/invoices/31/download?channel=b2b&store_id=7',
          'items': [
            {
              'id': 501,
              'sku': 'WHO-501',
              'description': 'FOODEX Wholesale Tomato Box',
              'quantity': 2.0,
              'unit_price': 10.0,
              'discount_total': 1.0,
              'tax_total': 1.0,
              'line_total': 20.0,
              'currency': 'KWD',
            },
          ],
          'payments': [
            {
              'id': 9,
              'method': 'account',
              'reference': 'PAY-31',
              'amount': 5.0,
              'currency': 'KWD',
              'paid_at': '2026-10-02T10:00:00Z',
            },
          ],
          'ledger_entries': [
            {
              'id': 81,
              'type': 'credit_note',
              'reference': 'CN-31',
              'description': 'Credit adjustment',
              'debit': 0.0,
              'credit': 1.0,
              'currency': 'KWD',
              'occurred_at': '2026-10-03T10:00:00Z',
            },
          ],
        },
        'account': {
          'currency': 'KWD',
          'balance': 17.0,
          'balance_direction': 'customer_owes_company',
        },
        'generated_at': '2026-10-04T10:00:00Z',
      };
    }
    if (path.endsWith('/account-statement')) {
      return {
        'balance': 19.75,
        'currency': 'KWD',
        'data': [
          {'reference': 'INV-31', 'type': 'invoice', 'amount': 55.25},
          {'reference': 'PAY-31', 'type': 'payment', 'amount': -35.5},
        ],
      };
    }
    if (path.endsWith('/orders')) {
      return {
        'data': [
          {
            'id': 77,
            'order_number': 'FDX-20261005-JV3MRNRDF',
            'status': 'pending',
            'store_name': 'FOODEX wholesale',
            'created_at': '2024-10-05T14:30:00',
            'grand_total': 1000,
            'currency': 'EGP',
          },
          {
            'id': 78,
            'order_number': 'FDX-20261005-MOY92072Y6',
            'status': 'preparing',
            'store_name': 'FOODEX wholesale',
            'created_at': '2024-10-06T10:15:00',
            'grand_total': 480,
            'currency': 'EGP',
          },
          {
            'id': 79,
            'order_number': 'FDX-B2B-20261005-2HMBJCBC',
            'status': 'out_for_delivery',
            'store_name': 'FOODEX wholesale',
            'created_at': '2024-10-07T09:20:00',
            'grand_total': 3500,
            'currency': 'EGP',
          },
          {
            'id': 80,
            'order_number': 'FDX-20261004-NCLU2ESSTP',
            'status': 'completed',
            'store_name': 'FOODEX wholesale',
            'created_at': '2024-10-04T16:45:00',
            'grand_total': 800,
            'currency': 'EGP',
          },
          {
            'id': 81,
            'order_number': 'FDX-20261004-UOQUDNVYFV',
            'status': 'preparing',
            'store_name': 'FOODEX wholesale',
            'created_at': '2024-10-04T11:10:00',
            'grand_total': 400,
            'currency': 'EGP',
          },
          {
            'id': 82,
            'order_number': 'FDX-B2B-20261004-XDE2DOZZ',
            'status': 'cancelled',
            'store_name': 'FOODEX wholesale',
            'created_at': '2024-10-03T13:25:00',
            'grand_total': 1300,
            'currency': 'EGP',
          },
          {
            'id': 83,
            'order_number': 'FDX-20261003-OFUSGL3W',
            'status': 'delivered',
            'store_name': 'FOODEX wholesale',
            'created_at': '2024-10-02T09:50:00',
            'grand_total': 500,
            'currency': 'EGP',
          },
          {
            'id': 84,
            'order_number': 'FDX-20261003-MY63BXHSBF',
            'status': 'completed',
            'store_name': 'FOODEX wholesale',
            'created_at': '2024-10-01T17:40:00',
            'grand_total': 800,
            'currency': 'EGP',
          },
        ],
      };
    }
    if (path.contains('/orders/77')) {
      return {
        'id': 77,
        'order_number': 'B2B-77',
        'status': 'out_for_delivery',
        'driver': 'FOODEX Driver 1',
        'grand_total': 101.0,
      };
    }
    if (path.startsWith('/api/v1/cart')) {
      return {
        'store_id': 7,
        'subtotal': 36.25,
        'items': [
          {
            'product_id': 42,
            'name': 'FOODEX Wholesale Tomato Box',
            'quantity': 5,
            'unit_price': 7.25,
          },
        ],
      };
    }
    if (path.endsWith('/profile?channel=retail&store_id=7')) {
      return {
        'company_name': 'FOODEX Business Demo',
        'email': 'buyer@foodex.test',
        'tax_number': 'TX-900',
      };
    }
    return {'status': 'ok'};
  }
}

class _EvidenceCatalogApi implements B2cCatalogApi {
  const _EvidenceCatalogApi([this.localeCode = 'ar']);

  final String localeCode;

  bool get _isEn => localeCode == 'en';

  @override
  Future<List<B2cStore>> stores() async => [
        B2cStore(
          id: 7,
          name: _isEn ? 'FOODEX Premium Demo Store' : 'متجر FOODEX التجريبي',
          code: 'FOODEX-DEMO-B2C',
        ),
      ];

  @override
  Future<List<B2cCategory>> categories(int storeId) async => [
        B2cCategory(id: 3, name: _isEn ? 'Vegetables' : 'خضروات'),
        B2cCategory(id: 4, name: _isEn ? 'Pantry' : 'بقالة'),
      ];

  @override
  Future<List<B2cOffer>> offers(int storeId) async => [
        B2cOffer(
          id: 9,
          name: _isEn ? 'Weekend offer' : 'عرض نهاية الأسبوع',
          type: 'percentage',
          value: 10,
        ),
        B2cOffer(
          id: 10,
          name: _isEn ? 'FOODEX offer' : 'عرض FOODEX',
          type: 'fixed',
          value: 2,
        ),
      ];

  @override
  Future<List<B2cBanner>> banners(int storeId) async => [
        B2cBanner(
          id: 11,
          title: _isEn ? 'FOODEX offer today' : 'عرض فودكس اليوم',
          targetUrl: '/offers',
        ),
      ];

  @override
  Future<List<B2cProduct>> products(
    int storeId, {
    String? query,
    int? categoryId,
    String? sort,
    String? direction,
  }) async =>
      [
        B2cProduct(
          id: 42,
          name: _isEn ? 'Fresh tomato box' : 'صندوق طماطم طازج',
          sku: 'TOM-42',
          price: 3.25,
        ),
        B2cProduct(
          id: 43,
          name: _isEn ? 'Organic olive oil' : 'زيت زيتون عضوي',
          sku: 'OLV-43',
          price: 18.5,
        ),
      ];

  @override
  Future<B2cProduct> product(int productId, {required int storeId}) async =>
      B2cProduct(
        id: 42,
        name: _isEn ? 'Fresh tomato box' : 'صندوق طماطم طازج',
        sku: 'TOM-42',
        price: 3.25,
        description: _isEn
            ? 'A safe FOODEX demo product for runtime evidence.'
            : 'منتج FOODEX تجريبي آمن لصور المنتج.',
      );
}

class _EvidenceAccountApi implements B2cAccountApi, B2cRetailFavoritesApi {
  const _EvidenceAccountApi([this.localeCode = 'ar']);

  final String localeCode;

  bool get _isEn => localeCode == 'en';

  @override
  Future<Object?> cart({int? storeId}) async => {
        'id': 1,
        'store_id': storeId ?? 7,
        'currency': 'KWD',
        'items': [
          {
            'id': 5,
            'product': {
              'id': 42,
              'name': _isEn ? 'Fresh tomato box' : 'صندوق طماطم طازج',
            },
            'quantity': 2,
            'unit_price': 3.25,
            'line_total': 6.5,
          },
        ],
        'subtotal': 6.5,
      };

  @override
  Future<Object?> updateCartItem(int itemId, double quantity) async => {
        'id': itemId,
        'quantity': quantity,
      };

  @override
  Future<void> removeCartItem(int itemId) async {}

  @override
  Future<Object?> order(int orderId) async => {
        'id': orderId,
        'order_number': 'FOODEX-$orderId',
        'status': 'out_for_delivery',
        'currency': 'KWD',
        'grand_total': 18.5,
        'driver_name': _isEn ? 'Nasser · FOODEX driver' : 'ناصر · سائق FOODEX',
      };

  @override
  Future<Object?> orders() async => {
        'data': [
          {
            'id': 101,
            'order_number': 'FOODEX-101',
            'status': 'out_for_delivery',
            'grand_total': 18.5,
            'currency': 'KWD',
          },
        ],
      };

  @override
  Future<Object?> profile() async => {
        'name': _isEn ? 'FOODEX Demo Customer' : 'عميل FOODEX التجريبي',
        'email': 'customer@foodex.test',
        'phone': '+96555500000',
      };

  @override
  Future<Object?> updateProfile(Map<String, dynamic> values) async => values;

  @override
  Future<Object?> addresses() async => {
        'data': [
          {
            'id': 8,
            'label': _isEn ? 'Home' : 'المنزل',
            'line1': _isEn ? 'Block 1, Demo Street' : 'قطعة 1 شارع تجريبي',
            'area': _isEn ? 'Bayan' : 'بيان',
            'city': _isEn ? 'Kuwait City' : 'مدينة الكويت',
            'country_code': 'KW',
            'latitude': 29.3031,
            'longitude': 48.0489,
            'location_accuracy_meters': 6.0,
            'location_source': 'map_pin',
            'landmark': _isEn ? 'Near the co-op' : 'قرب الجمعية',
            'is_default': true,
          },
        ],
      };

  @override
  Future<Object?> createAddress(Map<String, dynamic> values) async => {
        'id': 9,
        ...values,
      };

  @override
  Future<Object?> updateAddress(
    int addressId,
    Map<String, dynamic> values,
  ) async =>
      {'id': addressId, ...values};

  @override
  Future<Object?> setDefaultAddress(int addressId) async => {
        'id': addressId,
        'is_default': true,
      };

  @override
  Future<void> removeAddress(int addressId) async {}

  @override
  Future<Object?> favorites() async => {
        'data': [
          {
            'id': 42,
            'name': _isEn ? 'Fresh tomato box' : 'صندوق طماطم طازج',
            'sku': 'TOM-42',
          },
        ],
      };

  @override
  Future<void> addFavorite(int productId) async {}

  @override
  Future<void> removeFavorite(int productId) async {}

  @override
  Future<Object?> favoritesForStore(int storeId) async => {
        'data': [
          {
            'id': 42,
            'name': _isEn ? 'Fresh tomato box' : 'صندوق طماطم طازج',
            'sku': 'TOM-42',
            'store_id': storeId,
            'price': 3.25,
            'currency': 'KWD',
          },
        ],
      };

  @override
  Future<void> addFavoriteForStore(int storeId, int productId) async {}

  @override
  Future<void> removeFavoriteForStore(int storeId, int productId) async {}

  @override
  Future<Object?> accountDeletionStatus() async => {
        'data': {'status': 'NONE'},
      };

  @override
  Future<Object?> requestAccountDeletion(String password) async => {
        'data': {'status': 'COMPLETED'},
      };

  @override
  Future<Object?> notifications({String locale = 'ar'}) async => {
        'data': [
          {
            'id': 4,
            'title': locale == 'en' ? 'Order update' : 'تحديث الطلب',
            'body': locale == 'en' ? 'On the way' : 'في الطريق',
            'read_at': null,
          },
        ],
      };

  @override
  Future<void> markNotificationRead(int notificationId) async {}
}

class _EvidenceOrdersApi implements CustomerOrdersApi {
  const _EvidenceOrdersApi([this.localeCode = 'ar']);

  final String localeCode;

  bool get _isEn => localeCode == 'en';

  CustomerOrderSummary _summary(int orderId, {String channel = 'b2c'}) =>
      CustomerOrderSummary(
        id: orderId,
        orderNumber: 'FOODEX-$orderId',
        storeId: channel == 'b2b' ? 70 : 7,
        storeName: channel == 'b2b'
            ? (_isEn ? 'FOODEX Wholesale' : 'فوديكس جملة')
            : (_isEn ? 'FOODEX Fresh Market' : 'سوق فوديكس الطازج'),
        storeLogoUrl: null,
        channel: channel,
        status: 'out_for_delivery',
        currency: 'KWD',
        grandTotal: 18.5,
        createdAt: DateTime.utc(2026, 10, 1, 10, 30),
      );

  @override
  Future<CustomerOrderPage> orders({
    int page = 1,
    int perPage = 20,
    String? status,
    String? channel,
    CustomerOrderContext? context,
  }) async =>
      CustomerOrderPage(
        orders: [
          _summary(101,
              channel: channel ?? context?.normalizedChannel ?? 'b2c'),
        ],
        currentPage: page,
        perPage: perPage,
        total: 1,
        scope: channel ??
            (context == null
                ? 'all'
                : '${context.normalizedChannel}:${context.storeId}'),
      );

  @override
  Future<CustomerOrderDetails> order({
    required int orderId,
    CustomerOrderContext? context,
  }) async =>
      CustomerOrderDetails(
        summary: _summary(orderId),
        subtotal: 16.5,
        discountTotal: 0,
        deliveryTotal: 2,
        paymentMethod: 'cash_on_delivery',
        deliveryAddress: {
          'label': _isEn ? 'Home' : 'المنزل',
          'line1': _isEn ? 'Block 1, Demo Street' : 'قطعة 1 شارع تجريبي',
          'area': _isEn ? 'Bayan' : 'بيان',
          'city': _isEn ? 'Kuwait City' : 'مدينة الكويت',
          'country_code': 'KW',
        },
        items: [
          CustomerOrderItem(
            id: 1,
            productId: 42,
            sku: 'TOM-42',
            name: _isEn ? 'Fresh tomato box' : 'صندوق طماطم طازج',
            quantity: 2,
            unitPrice: 8.25,
            lineTotal: 16.5,
          ),
        ],
        history: [
          CustomerOrderHistoryEntry(
            id: 1,
            fromStatus: 'confirmed',
            toStatus: 'preparing',
            note: null,
            createdAt: DateTime.utc(2026, 10, 1, 10, 35),
          ),
          CustomerOrderHistoryEntry(
            id: 2,
            fromStatus: 'preparing',
            toStatus: 'out_for_delivery',
            note: _isEn ? 'FOODEX driver assigned' : 'تم تعيين سائق FOODEX',
            createdAt: DateTime.utc(2026, 10, 1, 11),
          ),
        ],
        payment: const CustomerOrderPayment(
          id: 1,
          provider: 'cash',
          status: 'pending',
          amount: 18.5,
          currency: 'KWD',
        ),
        requestedDeliveryDate: '2026-10-01',
      );
}

class _EvidenceActionApi implements CustomerActionApi {
  const _EvidenceActionApi();

  @override
  Future<CustomerLoginResult> login({required String username}) async =>
      const CustomerLoginResult(token: 'evidence-token');

  @override
  Future<void> logout() async {}

  @override
  Future<Object?> addCartItem({
    required int storeId,
    required int productId,
    required double quantity,
  }) async =>
      {
        'store_id': storeId,
        'product_id': productId,
        'quantity': quantity,
      };

  @override
  Future<Object?> checkout({
    required int addressId,
    int? storeId,
    String? paymentMethod,
    String? couponCode,
    required String idempotencyKey,
  }) async =>
      {
        'id': 101,
        'order_number': 'FDX-EVIDENCE-101',
        'display_order_number': '#101',
        'store_id': storeId ?? 7,
        'status': 'confirmed',
        'currency': 'KWD',
        'subtotal': 6.5,
        'discount_total': 0.5,
        'delivery_total': 0.75,
        'tax_total': 0.25,
        'grand_total': 7.0,
        'payment_method': paymentMethod ?? 'cash_on_delivery',
        'payment': {'status': 'pending'},
        'items': [
          {'id': 5},
        ],
      };
}

class _EvidenceRetailCommerceApi implements RetailCommerceApi {
  const _EvidenceRetailCommerceApi([this.localeCode = 'ar']);

  final String localeCode;

  bool get _isEn => localeCode == 'en';

  @override
  Future<RetailCartSnapshot> loadCart({required int storeId}) async =>
      RetailCartSnapshot(
        storeId: storeId,
        currency: 'KWD',
        items: [
          RetailCartItem(
            id: 5,
            productId: 42,
            name: _isEn ? 'Fresh tomato box' : 'صندوق طماطم طازج',
            quantity: 2,
            lineTotal: 6.5,
            isAvailable: true,
          ),
        ],
        subtotal: 6.5,
        discountTotal: .5,
        deliveryTotal: .75,
        taxTotal: .25,
        grandTotal: 7,
        hasUnavailableItems: false,
      );

  @override
  Future<RetailCheckoutOptions> checkoutOptions({required int storeId}) async =>
      RetailCheckoutOptions(
        storeId: storeId,
        addresses: [
          RetailCheckoutAddress(
            id: 81,
            label: _isEn ? 'Home' : 'المنزل',
            line1: _isEn ? 'FOODEX Street 1' : 'شارع FOODEX 1',
            city: _isEn ? 'Kuwait City' : 'مدينة الكويت',
            isDefault: true,
          ),
        ],
        paymentMethods: const ['cash_on_delivery', 'knet'],
      );

  @override
  Future<RetailCreatedOrder> submitCheckout({
    required int storeId,
    required int addressId,
    required String paymentMethod,
    String? couponCode,
    required String idempotencyKey,
  }) async =>
      RetailCreatedOrder(
        id: 101,
        storeId: storeId,
        orderNumber: 'FDX-EVIDENCE-101',
        displayOrderNumber: '#101',
        currency: 'KWD',
        subtotal: 6.5,
        discountTotal: .5,
        deliveryTotal: .75,
        taxTotal: .25,
        grandTotal: 7,
        paymentMethod: paymentMethod,
        paymentStatus: 'pending',
        itemCount: 1,
      );

  @override
  Future<RetailCartSnapshot> mergeGuestCartAfterAuthentication({
    required int storeId,
  }) =>
      loadCart(storeId: storeId);

  @override
  Future<RetailCartSnapshot> removeItem({
    required int storeId,
    required int itemId,
  }) =>
      loadCart(storeId: storeId);

  @override
  Future<RetailCartSnapshot> updateQuantity({
    required int storeId,
    required int itemId,
    required double quantity,
  }) =>
      loadCart(storeId: storeId);
}

class _EvidenceStorefrontApi implements StorefrontApi {
  const _EvidenceStorefrontApi();

  @override
  Future<Map<String, dynamic>> selection({
    String? countryCode,
    String? city,
    String? area,
    bool support = false,
  }) async =>
      {
        'retail_stores': [
          {
            'id': 7,
            'code': 'GROCERY-7',
            'name': 'FOODEX Fresh Market',
            'theme_code': 'retail_grocery',
            'address': 'سموحة · توصيل سريع',
          },
          {
            'id': 8,
            'code': 'PHARMACY-8',
            'name': 'FOODEX Pharmacy',
            'theme_code': 'retail_pharmacy',
            'address': 'صيدلية · خدمة 24 ساعة',
          },
        ],
        'wholesale_stores': [
          {
            'id': 70,
            'code': 'WHOLESALE-70',
            'name': 'FOODEX Wholesale',
            'theme_code': 'wholesale_b2b',
            'retail_context_ids': [7],
            'is_platform_principal': true,
          },
        ],
        'entitlements': {
          'retail_context_ids': [7],
          'direct_b2b': true,
          'principal_wholesale_store_id': 70,
        },
      };

  @override
  Future<Map<String, dynamic>> retailHome(int storeId) async {
    final pharmacy = storeId == 8;
    return {
      'store': {
        'id': storeId,
        'name': pharmacy ? 'FOODEX Pharmacy' : 'FOODEX Fresh Market',
      },
      'theme': {
        'code': pharmacy ? 'retail_pharmacy' : 'retail_grocery',
        'primary': pharmacy ? '#0A8DDA' : '#078A43',
        'primary_dark': pharmacy ? '#0668A9' : '#006736',
      },
      'branding': {
        'address': pharmacy ? 'صيدلية · خدمة 24 ساعة' : 'سموحة · توصيل سريع',
      },
      'hero': {'title': pharmacy ? 'صحتك أولويتنا' : 'طازج كل يوم'},
      'sections': [
        {'key': 'hero', 'type': 'hero', 'sort_order': 10},
        {
          'key': 'categories',
          'type': 'categories',
          'title_ar': 'التصنيفات',
          'sort_order': 20,
        },
        {
          'key': 'products',
          'type': 'products',
          'title_ar': pharmacy ? 'العناية والصحة' : 'وصل حديثًا',
          'sort_order': 30,
        },
      ],
    };
  }

  @override
  Future<Map<String, dynamic>> wholesaleHome(int storeId) async => {
        'store': {
          'id': storeId,
          'code': 'WHOLESALE-$storeId',
          'name': 'FOODEX Wholesale',
          'theme_code': 'wholesale_b2b',
        },
        'theme': {
          'code': 'wholesale_b2b',
          'primary': '#5D2A91',
          'primary_dark': '#35195E',
          'accent': '#B983F0',
          'background': '#FBFAFD',
        },
        'branding': {
          'address': 'تغطية توريد الجملة',
          'custom': {
            'brand_title_ar': 'FOODEX جملة',
            'brand_subtitle_ar': 'أفضل الأسعار لمتاجر التجزئة',
            'hero_cta_ar': 'تصفح الكتالوج',
          },
        },
        'hero': {'title': 'عرض الجملة', 'image_url': null},
        'sections': [
          {'key': 'hero', 'type': 'hero', 'sort_order': 10},
          {
            'key': 'categories',
            'type': 'categories',
            'title_ar': 'التصنيفات',
            'sort_order': 20,
          },
          {
            'key': 'offers',
            'type': 'offers',
            'title_ar': 'عروض الجملة',
            'sort_order': 30,
          },
        ],
      };

  @override
  Future<Map<String, dynamic>> b2bCheckoutOptions(int storeId) async => {
        'store_id': storeId,
        'addresses': [
          {
            'id': 81,
            'label': 'المتجر',
            'line1': 'سموحة - شارع FOODEX',
            'area': 'سموحة',
            'city': 'الإسكندرية',
            'country_code': 'EG',
            'is_default': true,
          },
        ],
        'delivery_dates': ['2026-09-29', '2026-09-30', '2026-10-01'],
        'payment_methods': ['cash_on_delivery', 'account_credit'],
        'credit_limit': 5000,
        'currency': 'EGP',
      };
}

class _EvidenceWholesaleCommerceApi implements WholesaleCommerceApi {
  const _EvidenceWholesaleCommerceApi();

  @override
  Future<Object?> cart(int storeId) async => {
        'store_id': storeId,
        'subtotal': 725.0,
        'currency': 'EGP',
        'items': [
          {
            'id': 501,
            'product_id': 42,
            'name': 'كرتونة طماطم FOODEX',
            'quantity': 10,
            'unit_price': 72.5,
            'line_total': 725.0,
            'minimum_order_quantity': 5,
            'ordering_increment': 5,
            'pack_size': 12,
            'pack_label': 'كرتونة 12',
          },
        ],
      };

  @override
  Future<Object?> addItem(int storeId, int productId, double quantity) async =>
      cart(storeId);

  @override
  Future<Object?> updateItem(int itemId, double quantity) async => {
        'id': itemId,
        'quantity': quantity,
      };

  @override
  Future<void> removeItem(int itemId) async {}

  @override
  Future<Object?> checkout({
    required int storeId,
    required int addressId,
    required String paymentMethod,
    String? requestedDeliveryDate,
    String? note,
    String? couponCode,
    required String idempotencyKey,
  }) async =>
      {
        'id': 990,
        'order_number': 'B2B-990',
        'store_id': storeId,
        'status': 'pending',
        'currency': 'EGP',
        'grand_total': 725.0,
      };
}
