import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_visualization/foodex_visualization.dart';
import 'package:foodex_customer_app/app.dart';
import 'package:foodex_customer_app/core/api/b2b_api.dart';
import 'package:foodex_customer_app/core/api/b2c_account_api.dart';
import 'package:foodex_customer_app/core/api/customer_action_api.dart';
import 'package:foodex_customer_app/core/api/storefront_api.dart';
import 'package:foodex_customer_app/core/api/wholesale_commerce_api.dart';
import 'package:foodex_customer_app/core/auth/customer_session.dart';
import 'package:foodex_customer_app/core/diagnostics/customer_diagnostics.dart';
import 'package:foodex_customer_app/core/routing/customer_pending_action.dart';
import 'package:foodex_customer_app/core/theme/customer_ui_v3_tokens.dart';
import 'package:foodex_customer_app/features/storefront/wholesale_multistore_screens.dart';

Future<void> _scrollUntilBuilt(
  WidgetTester tester,
  Finder scrollable,
  Finder target,
) async {
  for (var attempt = 0; attempt < 12 && target.evaluate().isEmpty; attempt++) {
    await tester.drag(scrollable, const Offset(0, -350));
    await _pumpCustomerFrames(tester);
  }
}

Future<void> _pumpCustomerFrames(WidgetTester tester) async {
  for (var frame = 0; frame < 30; frame++) {
    await tester.pump(const Duration(milliseconds: 50));
  }
}

void main() {
  const b2b = CustomerSession.authenticated(CustomerChannel.b2b);

  testWidgets(
    'B2B unauthenticated protected route hides internal redirect details',
    (tester) async {
      await tester.pumpWidget(
        const FoodexCustomerApp(initialRoute: '/b2b/dashboard'),
      );
      await _pumpCustomerFrames(tester);

      expect(find.text('تسجيل دخول العميل'), findsWidgets);
      expect(find.text('دخول عميل الأعمال'), findsNothing);
      expect(find.byKey(const ValueKey('unified-auth-submit')), findsOneWidget);
      expect(find.textContaining('/b2b/login'), findsNothing);
      expect(find.textContaining('/auth/checkout'), findsNothing);
      expect(find.textContaining('next='), findsNothing);
      expect(
        find.byKey(const ValueKey('customer-route-location')),
        findsNothing,
      );
    },
  );

  testWidgets(
    'B2B approved customer dashboard is RTL and exposes finance areas',
    (tester) async {
      await tester.pumpWidget(
        const FoodexCustomerApp(session: b2b, initialRoute: '/b2b/dashboard'),
      );
      await _pumpCustomerFrames(tester);
      expect(
        Directionality.of(tester.element(find.text('لوحة الأعمال'))),
        TextDirection.rtl,
      );
      expect(find.text('المشتريات'), findsOneWidget);
      expect(find.text('الفواتير'), findsWidgets);
      expect(find.text('كشف الحساب'), findsOneWidget);
    },
  );

  testWidgets(
    'B2B account statement keeps compact period controls and correct credit direction',
    (tester) async {
      tester.view.physicalSize = const Size(390, 844);
      tester.view.devicePixelRatio = 1;
      addTearDown(() {
        tester.view.resetPhysicalSize();
        tester.view.resetDevicePixelRatio();
      });

      final api = _FakeB2bApi({
        'currency': 'EGP',
        'opening_balance': 25,
        'period_debits': 20,
        'period_credits': 120,
        'closing_balance': 125,
        'balance': 80,
        'transactions': <Object>[],
        'pagination': {
          'current_page': 1,
          'last_page': 1,
        },
      });

      await tester.pumpWidget(
        FoodexCustomerApp(
          session: b2b,
          initialRoute:
              '/b2b/account-statement?store_id=7&from=2026-10-01&to=2026-10-15',
          b2bApi: api,
        ),
      );
      await _pumpCustomerFrames(tester);

      expect(find.text('كل التواريخ'), findsNothing);
      expect(find.text('ليك'), findsWidgets);
      expect(find.text('عليك'), findsNothing);

      final periodY = tester.getTopLeft(find.text('الفترة')).dy;
      final fromY = tester
          .getTopLeft(find.byKey(const ValueKey('b2b-statement-from')))
          .dy;
      final toY =
          tester.getTopLeft(find.byKey(const ValueKey('b2b-statement-to'))).dy;
      expect((periodY - fromY).abs(), lessThan(20));
      expect((fromY - toY).abs(), lessThan(1));

      String periodControlLabel(String key) => tester
          .widgetList<Text>(
            find.descendant(
              of: find.byKey(ValueKey(key)),
              matching: find.byType(Text),
            ),
          )
          .map((widget) => widget.data)
          .whereType<String>()
          .single;

      expect(periodControlLabel('b2b-statement-from'), startsWith('من · '));
      expect(periodControlLabel('b2b-statement-to'), startsWith('إلى · '));

      await tester.tap(
        find.byKey(const ValueKey('b2b-statement-preset-all')),
      );
      await _pumpCustomerFrames(tester);
      expect(periodControlLabel('b2b-statement-from'), 'من');
      expect(periodControlLabel('b2b-statement-to'), 'إلى');

      await tester.tap(
        find.byKey(const ValueKey('b2b-statement-preset-month')),
      );
      await _pumpCustomerFrames(tester);
      expect(periodControlLabel('b2b-statement-from'), startsWith('من · '));
      expect(periodControlLabel('b2b-statement-to'), startsWith('إلى · '));

      final presetKeys = <String>['month', 'previous', '30', 'all'];
      final presetYs = presetKeys
          .map(
            (key) => tester
                .getTopLeft(
                  find.byKey(ValueKey('b2b-statement-preset-$key')),
                )
                .dy,
          )
          .toList(growable: false);
      expect(
        presetYs.every((value) => (value - presetYs.first).abs() < 1),
        isTrue,
      );

      final closingText = tester.widget<Text>(
        find.byKey(const ValueKey('b2b-statement-closing-value')),
      );
      expect(closingText.style?.color, CustomerUiColors.white);
    },
  );

  testWidgets(
    'B2B dashboard renders authoritative account metrics and refreshes in place',
    (tester) async {
      tester.view.physicalSize = const Size(900, 1800);
      tester.view.devicePixelRatio = 1;
      addTearDown(() {
        tester.view.resetPhysicalSize();
        tester.view.resetDevicePixelRatio();
      });

      final api = _FakeB2bApi({
        'customer': {'id': 9, 'name': 'Buyer', 'email': 'buyer@example.test'},
        'account': {'id': 3, 'company_name': 'Buyer Co', 'status': 'active'},
        'finance': {
          'currency': 'KWD',
          'balance': 10,
          'balance_direction': 'customer_owes_company',
          'credit_limit': 500,
          'available_credit_line': 490,
          'customer_credit_balance': 35,
          'open_amount': 10,
          'overdue_amount': 2,
        },
        'operations': {
          'purchases_this_month': 125.5,
          'payments_this_month': 75,
          'invoice_count': 4,
          'order_count': 6,
          'active_orders': 2,
        },
        'freshness': {
          'generated_at': DateTime.now().toUtc().toIso8601String(),
          'stale': false,
        },
        'currency': 'KWD',
        'purchase_total': 640.25,
      });

      await tester.pumpWidget(
        FoodexCustomerApp(
          session: b2b,
          initialRoute: '/b2b/dashboard?store_id=7',
          b2bApi: api,
        ),
      );
      await _pumpCustomerFrames(tester);

      expect(api.lastPath, '/api/v1/b2b/dashboard?store_id=7');
      expect(api.calls, 1);
      expect(find.byKey(const ValueKey('b2b-dashboard-data')), findsOneWidget);
      expect(find.byKey(const ValueKey('b2b-dashboard-hero')), findsOneWidget);
      expect(
        find.byKey(const ValueKey('b2b-dashboard-balance-hero')),
        findsNothing,
      );
      expect(find.byKey(const ValueKey('b2b-dashboard-refresh')), findsNothing);
      expect(
        find.byKey(const ValueKey('b2b-dashboard-back')),
        findsOneWidget,
      );
      expect(
        find.byKey(const ValueKey('b2b-dashboard-finance-grid')),
        findsOneWidget,
      );
      expect(
        find.byKey(const ValueKey('b2b-dashboard-operations-grid')),
        findsOneWidget,
      );
      expect(
        find.byKey(const ValueKey('b2b-dashboard-offers')),
        findsOneWidget,
      );
      expect(find.text('Buyer Co'), findsOneWidget);
      expect(find.text('Buyer'), findsOneWidget);
      expect(find.text('الرصيد الدائن'), findsOneWidget);
      expect(find.text('قيمة مشترياتك'), findsOneWidget);
      expect(
        find.descendant(
          of: find.byKey(const ValueKey('b2b-dashboard-credit-balance')),
          matching: find.text('35.000 KWD'),
        ),
        findsOneWidget,
      );
      expect(
        find.descendant(
          of: find.byKey(const ValueKey('b2b-dashboard-purchase-value')),
          matching: find.text('640.250 KWD'),
        ),
        findsOneWidget,
      );
      expect(find.text('حد الائتمان'), findsNothing);
      expect(find.text('الائتمان المتاح'), findsNothing);
      expect(find.text('الفواتير المفتوحة'), findsOneWidget);
      expect(find.text('المبلغ المتأخر'), findsOneWidget);
      expect(find.text('مشتريات هذا الشهر'), findsOneWidget);
      expect(find.text('مدفوعات هذا الشهر'), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey('b2b-dashboard-hero')));
      await _pumpCustomerFrames(tester);
      expect(api.calls, 2);
    },
  );

  testWidgets('B2B orders refresh automatically when app resumes', (
    tester,
  ) async {
    final api = _FakeB2bApi({
      'data': [
        {
          'id': 77,
          'order_number': 'B2B-77',
          'status': 'processing',
          'grand_total': 48.0,
          'currency': 'KWD',
        },
      ],
    });

    await tester.pumpWidget(
      FoodexCustomerApp(session: b2b, initialRoute: '/b2b/orders', b2bApi: api),
    );
    await _pumpCustomerFrames(tester);

    expect(api.calls, 1);
    expect(find.byKey(const ValueKey('b2b-orders-data')), findsOneWidget);
    expect(
      find.byKey(const ValueKey('customer-persistent-footer')),
      findsOneWidget,
    );
    for (final destination in [
      'home',
      'products',
      'orders',
      'invoices',
      'account',
    ]) {
      expect(
        find.byKey(ValueKey('customer-footer-$destination')),
        findsOneWidget,
      );
    }
    expect(
      find.byKey(const ValueKey('customer-footer-home')),
      findsOneWidget,
    );
    expect(find.byKey(const ValueKey('b2b-orders-back')), findsOneWidget);

    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.hidden);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.hidden);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await _pumpCustomerFrames(tester);

    expect(api.calls, 2);
    expect(find.byKey(const ValueKey('b2b-orders-data')), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('b2b-orders-back')));
    await _pumpCustomerFrames(tester);

    expect(find.byKey(const ValueKey('b2b-orders-screen')), findsNothing);
    expect(
      find.byKey(const ValueKey('foodex-wholesale-shared-header')),
      findsOneWidget,
    );
    expect(tester.takeException(), isNull);
  });

  testWidgets(
    'Wholesale reorder prompts before merge, applies current rules and opens cart',
    (tester) async {
      tester.view.physicalSize = const Size(900, 1800);
      tester.view.devicePixelRatio = 1;
      addTearDown(() {
        tester.view.resetPhysicalSize();
        tester.view.resetDevicePixelRatio();
      });

      final api = _PathB2bApi({
        '/api/v1/b2b/orders': {
          'data': [
            {
              'id': 77,
              'order_number': 'B2B-77',
              'store_id': 7,
              'status': 'delivered',
              'grand_total': 48.0,
              'currency': 'KWD',
            },
          ],
        },
        '/api/v1/b2b/orders/77': {
          'id': 77,
          'order_number': 'B2B-77',
          'store_id': 7,
          'status': 'delivered',
          'items': [
            {
              'id': 701,
              'product_id': 101,
              'name': 'Current Water',
              'quantity': 3.0,
            },
            {
              'id': 702,
              'product_id': 102,
              'name': 'Unavailable Juice',
              'quantity': 2.0,
            },
          ],
        },
        '/api/v1/b2b/products/101?store_id=7': {
          'id': 101,
          'minimum_order_quantity': 5.0,
          'ordering_increment': 5.0,
          'available_quantity': 20.0,
          'is_available': true,
          'availability_state': 'AVAILABLE',
        },
        '/api/v1/b2b/products/102?store_id=7': {
          'id': 102,
          'minimum_order_quantity': 1.0,
          'ordering_increment': 1.0,
          'available_quantity': 0.0,
          'is_available': false,
          'availability_state': 'OUT_OF_STOCK',
        },
      });
      final commerce = _ReorderWholesaleCommerceApi();

      await tester.pumpWidget(
        FoodexCustomerApp(
          session: b2b,
          locale: const Locale('en'),
          initialRoute: '/b2b/orders?store_id=7',
          b2bApi: api,
          wholesaleCommerceApi: commerce,
        ),
      );
      await _pumpCustomerFrames(tester);

      final reorder = find.byKey(const ValueKey('b2b-order-reorder-77'));
      expect(reorder, findsOneWidget);
      expect(find.text('Reorder'), findsOneWidget);
      await tester.tap(reorder);
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 300));

      expect(
        find.byKey(const ValueKey('b2b-reorder-merge-confirm')),
        findsOneWidget,
      );
      expect(commerce.addCalls, isEmpty);

      await tester.tap(find.byKey(const ValueKey('b2b-reorder-merge-confirm')));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 300));

      expect(commerce.addCalls, <String>['7:101:5.0']);
      expect(find.text('Cart rebuilt with current rules'), findsOneWidget);
      expect(find.textContaining('Current Water: 3 → 5'), findsOneWidget);
      expect(find.textContaining('Unavailable Juice'), findsOneWidget);

      await tester.tap(find.text('Review cart'));
      await _pumpCustomerFrames(tester);

      expect(find.byKey(const ValueKey('b2b-cart-data')), findsOneWidget);
      expect(commerce.cartCalls, greaterThanOrEqualTo(2));
    },
  );

  testWidgets(
    'B2B dashboard hides the Store shortcut from the hero',
    (tester) async {
      final api = _FakeB2bApi({
        'customer': {'name': 'Buyer'},
        'account': {'company_name': 'Buyer Co', 'status': 'active'},
        'finance': {
          'currency': 'KWD',
          'balance': 0,
          'balance_direction': 'settled',
          'credit_limit': 500,
          'available_credit_line': 500,
          'customer_credit_balance': 0,
          'open_amount': 0,
          'overdue_amount': 0,
        },
        'operations': {
          'purchases_this_month': 0,
          'payments_this_month': 0,
          'invoice_count': 0,
          'order_count': 0,
          'active_orders': 0,
        },
        'freshness': {
          'generated_at': DateTime.now().toUtc().toIso8601String(),
          'stale': false,
        },
      });

      await tester.pumpWidget(
        FoodexCustomerApp(
          session: b2b,
          initialRoute: '/b2b/dashboard',
          b2bApi: api,
          actionApi: _FakeCustomerActionApi(),
        ),
      );
      await _pumpCustomerFrames(tester);

      expect(
        find.byKey(const ValueKey('b2b-dashboard-shopping')),
        findsNothing,
      );
      expect(
        api.paths
            .where((path) => path.startsWith('/api/v1/b2b/dashboard'))
            .length,
        1,
      );
    },
  );

  testWidgets('B2B dashboard shows credit direction in English LTR', (
    tester,
  ) async {
    final api = _FakeB2bApi({
      'customer': {'name': 'Buyer'},
      'account': {'company_name': 'Buyer Co', 'status': 'active'},
      'finance': {
        'currency': 'KWD',
        'balance': -20,
        'balance_direction': 'company_owes_customer',
        'credit_limit': 500,
        'available_credit_line': 500,
        'customer_credit_balance': 20,
        'open_amount': 0,
        'overdue_amount': 0,
      },
      'operations': {
        'purchases_this_month': 0,
        'payments_this_month': 20,
        'invoice_count': 1,
        'order_count': 1,
        'active_orders': 0,
      },
      'freshness': {
        'generated_at': DateTime.now().toUtc().toIso8601String(),
        'stale': false,
      },
      'purchase_total': 88,
    });

    await tester.pumpWidget(
      FoodexCustomerApp(
        session: b2b,
        locale: const Locale('en'),
        initialRoute: '/b2b/dashboard',
        b2bApi: api,
      ),
    );
    await _pumpCustomerFrames(tester);

    expect(
      Directionality.of(
        tester.element(
          find.byKey(const ValueKey('foodex-wholesale-shared-header')),
        ),
      ),
      TextDirection.ltr,
    );
    expect(find.text('Credit balance'), findsOneWidget);
    expect(find.text('Your purchase value'), findsOneWidget);
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('b2b-dashboard-credit-balance')),
        matching: find.text('20.000 KWD'),
      ),
      findsOneWidget,
    );
    expect(find.text('Credit limit'), findsNothing);
    expect(find.text('Available credit'), findsNothing);
    expect(find.text('Active orders'), findsOneWidget);
  });

  testWidgets('B2B More keeps the compact hub without inline account sections',
      (
    tester,
  ) async {
    final api = _PathB2bApi({
      '/api/v1/profile': {
        'name': 'Buyer One',
        'email': 'buyer@example.test',
        'locale': 'ar',
        'customer': {
          'id': 19,
          'type': 'b2b',
          'name': 'Buyer One',
          'phone': '55512345',
          'email': 'buyer@example.test',
        },
        'business_account': {
          'id': 27,
          'company_name': 'Acme Foods',
          'status': 'active',
          'tax_number': 'TAX-872',
        },
        'addresses': const <Object>[],
        'favorites': const <Object>[],
      },
      '/api/v1/b2b/account-summary?store_id=7': {
        'data': {
          'currency': 'KWD',
          'balance': 125.0,
          'balance_direction': 'customer_owes_company',
          'total_debits': 420.0,
          'total_purchases': 400.0,
          'outstanding_receivable': 125.0,
          'credit_limit': 1000.0,
          'available_credit_line': 875.0,
          'purchasing_power': 875.0,
        },
      },
    });

    await tester.pumpWidget(
      FoodexCustomerApp(
        session: const CustomerSession.authenticated(
          CustomerChannel.b2b,
          accessToken: 'token',
          platformWide: true,
        ),
        initialRoute: '/b2b/profile?channel=wholesale&store_id=7',
        b2bApi: api,
      ),
    );
    await _pumpCustomerFrames(tester);

    expect(
      find.byKey(const ValueKey('b2b-profile-friendly-data')),
      findsNothing,
    );
    expect(find.text('Acme Foods'), findsNothing);
    expect(find.text('TAX-872'), findsNothing);
    expect(find.text('نشط'), findsNothing);
    expect(find.byKey(const ValueKey('b2b-account-hub')), findsOneWidget);
    expect(find.text('إجمالي مشترياتك'), findsOneWidget);
    expect(find.text('400.000 KWD'), findsOneWidget);
    expect(find.text('إجمالي مديونيتك'), findsOneWidget);
    expect(find.text('125.000 KWD'), findsOneWidget);
    expect(find.text('الحساب المالي'), findsNothing);
    expect(find.text('1000.000 KWD'), findsNothing);
    expect(find.text('875.000 KWD'), findsNothing);
    expect(find.text('كشف الحساب'), findsNothing);
    expect(find.text('تقرير المشتريات'), findsOneWidget);
    expect(find.text('العناوين'), findsOneWidget);
    expect(find.text('الإشعارات'), findsNothing);
    expect(find.text('المساعدة والدعم'), findsNothing);
    expect(
      find.byKey(const ValueKey('b2b-more-bottom-settings')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('b2b-more-language-action')),
      findsOneWidget,
    );
    expect(find.text('العربية'), findsOneWidget);
    expect(find.text('English'), findsNothing);
    expect(
      find.byKey(const ValueKey('b2b-profile-security-action')),
      findsOneWidget,
    );
    expect(find.byKey(const ValueKey('b2b-profile-logout')), findsOneWidget);
    expect(find.byKey(const ValueKey('b2b-more-app-version')), findsOneWidget);
    expect(find.text('الإصدار 1.0.95'), findsOneWidget);
    expect(find.text('company_name'), findsNothing);
  });

  testWidgets(
    'B2B storefront keeps server branding and enforces green theme on mobile',
    (tester) async {
      final storefront = _FakeWholesaleStorefrontApi();
      await tester.pumpWidget(
        FoodexCustomerApp(
          session: b2b,
          initialRoute: '/b2b/home?store_id=70',
          b2bApi: _FakeB2bApi({
            'data': [
              {
                'id': 42,
                'name': 'Bulk Water',
                'sku': 'WATER-42',
                'account_price': 75,
                'minimum_order_quantity': 5,
                'category_id': 3,
              },
            ],
          }),
          storefrontApi: storefront,
          actionApi: _FakeCustomerActionApi(),
        ),
      );
      await _pumpCustomerFrames(tester);

      expect(storefront.lastStoreId, 70);
      expect(find.text('فودكس جملة المخصص'), findsOneWidget);
      expect(find.text('أسعار مخصصة من لوحة التحكم'), findsOneWidget);
      expect(find.text('ابدأ طلب الجملة'), findsOneWidget);
      expect(find.text('تصنيفات الجملة'), findsOneWidget);
      await tester.scrollUntilVisible(
        find.text('عروض الحساب'),
        280,
        scrollable: find.byType(Scrollable).first,
      );
      expect(find.text('عروض الحساب'), findsOneWidget);

      final scaffold = tester.widget<Scaffold>(find.byType(Scaffold).first);
      expect(scaffold.backgroundColor, const Color(0xFFF8FBF9));
    },
  );

  testWidgets(
    'Wholesale shopping resolves principal store on first open without store id',
    (tester) async {
      final storefront = _FakeWholesaleStorefrontApi();
      final api = _FakeB2bApi({
        'data': [
          {
            'id': 42,
            'name': 'Resolved Bulk Water',
            'sku': 'RESOLVED-42',
            'account_price': 55,
            'minimum_order_quantity': 1,
            'is_available': true,
          },
        ],
      });

      await tester.pumpWidget(
        FoodexCustomerApp(
          session: b2b,
          initialRoute: '/b2b/products',
          b2bApi: api,
          storefrontApi: storefront,
          actionApi: _FakeCustomerActionApi(),
        ),
      );
      await _pumpCustomerFrames(tester);

      expect(
        find.byKey(const ValueKey('wholesale-catalog-screen')),
        findsOneWidget,
      );
      expect(
        find.byKey(const ValueKey('b2b-catalog-store-required')),
        findsNothing,
      );
      expect(storefront.selectionCalls, greaterThanOrEqualTo(1));
      expect(storefront.lastStoreId, 70);
      expect(api.lastPath, '/api/v1/b2b/products?store_id=70');
      expect(find.text('Resolved Bulk Water'), findsWidgets);
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets(
    'Wholesale favorites first open resolves principal store and renders shared cards',
    (tester) async {
      final storefront = _FakeWholesaleStorefrontApi();
      final favorites = _WholesaleFavoritesApi({
        'data': [
          {
            'id': 900,
            'product': {
              'id': 42,
              'name': 'Favorite Bulk Water',
              'sku': 'FAV-42',
              'account_price': 55,
              'price': 55,
              'currency': 'EGP',
              'is_available': true,
            },
          },
        ],
      });

      await tester.pumpWidget(
        MaterialApp(
          locale: const Locale('ar'),
          home: WholesaleFavoritesDesignScreen(
            location: '/b2b/favorites',
            session: b2b,
            favoritesApi: favorites,
            storefrontApi: storefront,
            actionApi: _FakeCustomerActionApi(),
          ),
        ),
      );
      await _pumpCustomerFrames(tester);

      expect(favorites.lastStoreId, 70);
      expect(storefront.lastStoreId, 70);
      expect(
        find.byKey(const ValueKey('wholesale-favorites-screen')),
        findsOneWidget,
      );
      expect(
        find.byKey(const ValueKey('foodex-wholesale-shared-header')),
        findsOneWidget,
      );
      expect(
        find.byKey(const ValueKey('wholesale-product-card-42')),
        findsOneWidget,
      );
      expect(find.text('Favorite Bulk Water'), findsOneWidget);
      expect(
        find.byKey(const ValueKey('wholesale-favorites-loading')),
        findsNothing,
      );
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets(
    'Wholesale header removes Business shortcut and keeps search inside header',
    (tester) async {
      await tester.pumpWidget(
        FoodexCustomerApp(
          session: b2b,
          initialRoute: '/b2b/home?channel=wholesale&store_id=70',
          b2bApi: _FakeB2bApi(const <String, Object?>{}),
          storefrontApi: _FakeWholesaleStorefrontApi(),
          actionApi: _FakeCustomerActionApi(),
        ),
      );
      await _pumpCustomerFrames(tester);

      expect(
        find.byKey(const ValueKey('wholesale-business-dashboard-button')),
        findsNothing,
      );
      expect(
        find.byKey(const ValueKey('wholesale-home-search')),
        findsOneWidget,
      );
      expect(find.text('B2B'), findsNothing);
    },
  );

  testWidgets('Wholesale header hides Business dashboard shortcut for guests', (
    tester,
  ) async {
    await tester.pumpWidget(
      FoodexCustomerApp(
        initialRoute: '/b2b/home?channel=wholesale&store_id=70',
        storefrontApi: _FakeWholesaleStorefrontApi(),
        actionApi: _FakeCustomerActionApi(),
      ),
    );
    await _pumpCustomerFrames(tester);

    expect(
      find.byKey(const ValueKey('wholesale-business-dashboard-button')),
      findsNothing,
    );
  });

  testWidgets(
    'Wholesale category browse route shows image-only categories with fixed search/cart header',
    (tester) async {
      final storefront = _FakeWholesaleStorefrontApi();
      final api = _FakeB2bApi({
        'data': [
          {
            'id': 42,
            'name': 'Bulk Water',
            'sku': 'WATER-42',
            'account_price': 75,
            'category_id': 3,
            'category_name': 'Beverages',
            'category_image_url': 'https://example.invalid/beverages.jpg',
          },
          {
            'id': 43,
            'name': 'Bulk Rice',
            'sku': 'RICE-43',
            'account_price': 40,
            'category_id': 4,
            'category_name': 'Dry food',
            'category_image_url': 'https://example.invalid/dry-food.jpg',
          },
        ],
      });

      await tester.pumpWidget(
        FoodexCustomerApp(
          session: b2b,
          initialRoute:
              '/b2b/products?channel=wholesale&store_id=70&browse=categories',
          b2bApi: api,
          storefrontApi: storefront,
          actionApi: _FakeCustomerActionApi(),
        ),
      );
      await _pumpCustomerFrames(tester);

      expect(
        find.byKey(const ValueKey('wholesale-category-browser-screen')),
        findsOneWidget,
      );
      expect(
        find.byKey(const ValueKey('wholesale-category-browser-grid')),
        findsOneWidget,
      );
      expect(
        find.byKey(const ValueKey('wholesale-category-browser-search')),
        findsOneWidget,
      );
      expect(find.text('كل التصنيفات'), findsOneWidget);
      expect(find.text('Beverages'), findsOneWidget);
      expect(find.text('Dry food'), findsOneWidget);
      expect(find.text('Bulk Water'), findsNothing);
      expect(
        find.byKey(const ValueKey('wholesale-product-grid')),
        findsNothing,
      );
    },
  );

  testWidgets(
    'Wholesale brands View All opens a dedicated brand grid and brand products',
    (tester) async {
      final storefront = _FakeWholesaleStorefrontApi();
      final api = _FakeB2bApi({
        'data': [
          {
            'id': 51,
            'name': 'Brand One Water',
            'sku': 'BRAND-ONE-51',
            'account_price': 75,
            'brand_id': 11,
            'brand_name': 'Brand One',
            'brand_image_url': 'https://example.invalid/brand-one.png',
          },
          {
            'id': 52,
            'name': 'Brand Two Rice',
            'sku': 'BRAND-TWO-52',
            'account_price': 40,
            'brand_id': 12,
            'brand_name': 'Brand Two',
            'brand_image_url': 'https://example.invalid/brand-two.png',
          },
        ],
      });

      await tester.pumpWidget(
        FoodexCustomerApp(
          session: b2b,
          initialRoute:
              '/b2b/products?channel=wholesale&store_id=70&browse=brands',
          b2bApi: api,
          storefrontApi: storefront,
          actionApi: _FakeCustomerActionApi(),
        ),
      );
      await _pumpCustomerFrames(tester);

      expect(
        find.byKey(const ValueKey('wholesale-brand-browser-screen')),
        findsOneWidget,
      );
      expect(
        find.byKey(const ValueKey('wholesale-brand-browser-grid')),
        findsOneWidget,
      );
      expect(
        find.byKey(const ValueKey('wholesale-brand-browser-search')),
        findsOneWidget,
      );
      expect(find.text('كل العلامات التجارية'), findsOneWidget);
      expect(find.text('Brand One'), findsOneWidget);
      expect(find.text('Brand Two'), findsOneWidget);
      expect(find.text('Brand One Water'), findsNothing);
      expect(find.text('Brand Two Rice'), findsNothing);

      await tester.tap(
        find.byKey(const ValueKey('wholesale-brand-browser-11')),
      );
      await _pumpCustomerFrames(tester);

      expect(
        find.byKey(const ValueKey('wholesale-catalog-screen')),
        findsOneWidget,
      );
      expect(find.text('Brand One Water'), findsWidgets);
      expect(find.text('Brand Two Rice'), findsNothing);
      expect(
        find.byKey(const ValueKey('wholesale-brand-filter-11')),
        findsOneWidget,
      );
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets(
    'Wholesale storefront CTA opens canonical Screen 10 with store context',
    (tester) async {
      final storefront = _FakeWholesaleStorefrontApi();
      final api = _FakeB2bApi({
        'data': [
          {
            'id': 42,
            'name': 'Bulk Water',
            'sku': 'WATER-42',
            'account_price': 75,
            'minimum_order_quantity': 5,
            'ordering_increment': 1,
            'available_quantity': 20,
            'is_available': true,
            'category_id': 3,
            'category_name': 'Beverages',
          },
        ],
      });

      await tester.pumpWidget(
        FoodexCustomerApp(
          session: b2b,
          initialRoute: '/b2b/home?channel=wholesale&store_id=70',
          b2bApi: api,
          storefrontApi: storefront,
          actionApi: _FakeCustomerActionApi(),
        ),
      );
      await _pumpCustomerFrames(tester);

      final openCatalog = find.byKey(
        const ValueKey('wholesale-home-open-catalog'),
      );
      expect(openCatalog, findsOneWidget);
      await tester.ensureVisible(openCatalog);
      await tester.tap(openCatalog);
      await _pumpCustomerFrames(tester);

      expect(
        find.byKey(const ValueKey('wholesale-catalog-screen')),
        findsOneWidget,
      );
      expect(
        find.byKey(const ValueKey('wholesale-catalog-search')),
        findsOneWidget,
      );
      expect(
        find.byKey(const ValueKey('wholesale-category-all')),
        findsOneWidget,
      );
      expect(
        find.byKey(const ValueKey('wholesale-category-filter-3')),
        findsOneWidget,
      );
      expect(
        find.byKey(const ValueKey('wholesale-catalog-count')),
        findsOneWidget,
      );
      expect(
        find.byKey(const ValueKey('wholesale-catalog-grid-view')),
        findsNothing,
      );
      expect(
        find.byKey(const ValueKey('wholesale-catalog-list-view')),
        findsNothing,
      );

      final grid = tester.widget<GridView>(
        find.byKey(const ValueKey('wholesale-product-grid')),
      );
      final delegate =
          grid.gridDelegate as SliverGridDelegateWithFixedCrossAxisCount;
      expect(delegate.crossAxisCount, 3);

      expect(api.lastPath, '/api/v1/b2b/products?store_id=70');
      expect(storefront.lastStoreId, 70);
    },
  );

  testWidgets(
    'Wholesale View All keeps fallback section products visible',
    (tester) async {
      final storefront = _FakeWholesaleStorefrontApi();
      final api = _FakeB2bApi({
        'data': [
          {
            'id': 42,
            'name': 'Bulk Water',
            'sku': 'WATER-42',
            'account_price': 75,
            'minimum_order_quantity': 5,
            'ordering_increment': 1,
            'available_quantity': 20,
            'is_available': true,
            'category_id': 3,
            'category_name': 'Beverages',
          },
        ],
      });

      await tester.pumpWidget(
        FoodexCustomerApp(
          session: b2b,
          initialRoute: '/b2b/home?channel=wholesale&store_id=70',
          b2bApi: api,
          storefrontApi: storefront,
          actionApi: _FakeCustomerActionApi(),
        ),
      );
      await _pumpCustomerFrames(tester);

      final viewAll = find.byKey(
        const ValueKey('section-action-عروض الحساب'),
      );
      await tester.scrollUntilVisible(
        viewAll,
        260,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.tap(viewAll);
      await _pumpCustomerFrames(tester);

      expect(
        find.byKey(const ValueKey('wholesale-catalog-screen')),
        findsOneWidget,
      );
      expect(find.text('Bulk Water'), findsWidgets);
      expect(
        find.byKey(const ValueKey('wholesale-product-grid')),
        findsOneWidget,
      );
    },
  );

  testWidgets(
    'guest can browse Wholesale storefront and product without login',
    (tester) async {
      final storefront = _FakeWholesaleStorefrontApi();

      await tester.pumpWidget(
        FoodexCustomerApp(
          initialRoute: '/b2b/home?channel=wholesale&store_id=70',
          storefrontApi: storefront,
          actionApi: _FakeCustomerActionApi(),
        ),
      );
      await _pumpCustomerFrames(tester);

      expect(storefront.lastStoreId, 70);
      expect(
        find.byKey(const ValueKey('unified-customer-auth-screen')),
        findsNothing,
      );
      await tester.scrollUntilVisible(
        find.text('Public Bulk Water'),
        260,
        scrollable: find.byType(Scrollable).first,
      );
      await _pumpCustomerFrames(tester);
      expect(find.text('Public Bulk Water'), findsWidgets);

      await tester.tap(find.text('Public Bulk Water').first);
      await _pumpCustomerFrames(tester);

      expect(
        find.byKey(const ValueKey('b2b-product-detail-data')),
        findsOneWidget,
      );
      expect(find.text('Public Bulk Water'), findsOneWidget);
      expect(find.textContaining('55'), findsWidgets);
      expect(
        find.byKey(const ValueKey('unified-customer-auth-screen')),
        findsNothing,
      );
    },
  );

  testWidgets(
    'signed-out Wholesale add uses unified auth and resumes add exactly once',
    (tester) async {
      tester.view.physicalSize = const Size(800, 1400);
      tester.view.devicePixelRatio = 1;
      addTearDown(() {
        tester.view.resetPhysicalSize();
        tester.view.resetDevicePixelRatio();
      });

      final actionApi = _CountingCustomerActionApi();
      final pending = _MemoryPendingActionStore();
      final b2bApi = _FakeB2bApi({
        'id': 42,
        'sku': 'B2B-P-42',
        'name': 'Wholesale Product',
        'store_id': 7,
        'account_price': 7.25,
        'minimum_order_quantity': 5,
        'ordering_increment': 1,
        'available_quantity': 24,
        'currency': 'KWD',
      });

      await tester.pumpWidget(
        FoodexCustomerApp(
          initialRoute: '/b2b/products/42?channel=wholesale&store_id=7',
          b2bApi: b2bApi,
          storefrontApi: _FakeWholesaleStorefrontApi(),
          actionApi: actionApi,
          pendingActionStore: pending,
        ),
      );
      await _pumpCustomerFrames(tester);

      expect(find.text('Wholesale Product'), findsOneWidget);
      expect(actionApi.addCalls, 0);

      await tester.tap(find.byKey(const ValueKey('customer-add-cart')));
      await _pumpCustomerFrames(tester);

      expect(
        find.byKey(const ValueKey('unified-customer-auth-screen')),
        findsOneWidget,
      );
      expect(pending.writeCount, 1);
      expect(pending.value?.kind, CustomerPendingActionKind.addToCart);
      expect(pending.value?.productId, 42);
      expect(pending.value?.quantity, 5);

      await tester.enterText(
        find.byKey(const ValueKey('unified-auth-email')),
        'buyer@example.test',
      );
      await tester.enterText(
        find.byKey(const ValueKey('unified-auth-password')),
        'secret-pass',
      );
      await tester.tap(find.byKey(const ValueKey('unified-auth-submit')));
      await _pumpCustomerFrames(tester);

      expect(pending.takeCount, 1);
      expect(actionApi.addCalls, 1);
      expect(actionApi.lastStoreId, 7);
      expect(actionApi.lastProductId, 42);
      expect(actionApi.lastQuantity, 5);
      expect(find.text('سلة الجملة'), findsOneWidget);

      await _pumpCustomerFrames(tester);
      expect(actionApi.addCalls, 1);
    },
  );

  testWidgets(
    'B2B catalog renders authoritative commerce constraints and actionable stock states in English',
    (tester) async {
      tester.view.physicalSize = const Size(900, 1600);
      tester.view.devicePixelRatio = 1;
      addTearDown(() {
        tester.view.resetPhysicalSize();
        tester.view.resetDevicePixelRatio();
      });

      final api = _FakeB2bApi({
        'currency': 'EGP',
        'data': [
          {
            'id': 42,
            'sku': 'B2B-P-42',
            'name': 'Wholesale Water',
            'store_id': 7,
            'category_id': 3,
            'category_name': 'Beverages',
            'brand_name': 'FOODEX',
            'account_price': 72.5,
            'minimum_order_quantity': 5,
            'ordering_increment': 5,
            'pack_size': 12,
            'pack_label': 'Case 12',
            'available_quantity': 30,
            'is_available': true,
            'availability_state': 'AVAILABLE',
          },
          {
            'id': 43,
            'sku': 'B2B-P-43',
            'name': 'Unavailable Rice',
            'store_id': 7,
            'category_id': 4,
            'category_name': 'Grocery',
            'account_price': 150,
            'minimum_order_quantity': 2,
            'ordering_increment': 1,
            'pack_size': 10,
            'available_quantity': 0,
            'is_available': false,
            'availability_state': 'OUT_OF_STOCK',
          },
        ],
      });
      final actionApi = _CountingCustomerActionApi();

      await tester.pumpWidget(
        FoodexCustomerApp(
          session: b2b,
          locale: const Locale('en'),
          initialRoute: '/b2b/products?store_id=7',
          b2bApi: api,
          storefrontApi: _FakeWholesaleStorefrontApi(),
          actionApi: actionApi,
        ),
      );
      await _pumpCustomerFrames(tester);

      expect(api.lastPath, '/api/v1/b2b/products?store_id=7');
      expect(find.text('Filter'), findsOneWidget);
      expect(find.text('Default order'), findsOneWidget);
      expect(find.text('Wholesale Water'), findsOneWidget);
      expect(find.text('Beverages'), findsWidgets);
      expect(find.textContaining('72.50 EGP'), findsOneWidget);
      expect(find.textContaining('Case 12'), findsNothing);
      expect(find.textContaining('Step 5'), findsNothing);
      expect(find.textContaining('Available quantity: 30'), findsNothing);

      final availableAdd = find.byKey(
        const ValueKey('wholesale-product-add-42'),
      );
      final unavailableAdd = find.byKey(
        const ValueKey('wholesale-product-add-43'),
      );
      expect(availableAdd, findsOneWidget);
      expect(unavailableAdd, findsNothing);
      expect(
        find.byKey(const ValueKey('wholesale-product-out-of-stock-43')),
        findsOneWidget,
      );

      final addIcon = tester.widget<Icon>(
        find.descendant(
          of: availableAdd,
          matching: find.byIcon(Icons.add_rounded),
        ),
      );
      expect(addIcon.size, 13);

      final productNameBox =
          find.byKey(const ValueKey('wholesale-product-name-42'));
      final productName = tester.widget<Text>(
        find.descendant(of: productNameBox, matching: find.byType(Text)).first,
      );
      expect(productName.maxLines, 1);
      expect(productName.softWrap, isFalse);

      final priceCenter = tester.getCenter(
        find.byKey(const ValueKey('wholesale-product-price-42')),
      );
      final addCenter = tester.getCenter(availableAdd);
      expect((priceCenter.dy - addCenter.dy).abs(), lessThan(10));

      await tester.tap(availableAdd);
      await _pumpCustomerFrames(tester);

      expect(actionApi.addCalls, 1);
      expect(actionApi.lastStoreId, 7);
      expect(actionApi.lastProductId, 42);
      expect(actionApi.lastQuantity, 5);
      expect(find.text('Product added to cart'), findsOneWidget);
    },
  );

  testWidgets(
    'B2B product details render authoritative account pricing and inventory',
    (tester) async {
      tester.view.physicalSize = const Size(800, 1400);
      tester.view.devicePixelRatio = 1;
      addTearDown(() {
        tester.view.resetPhysicalSize();
        tester.view.resetDevicePixelRatio();
      });

      final api = _FakeB2bApi({
        'id': 42,
        'sku': 'B2B-P-1',
        'name': 'Wholesale Product',
        'store_id': 7,
        'account_price': 7.25,
        'minimum_order_quantity': 5,
        'price_tier': 'GOLD',
        'available_quantity': 24,
        'is_available': true,
        'currency': 'KWD',
        'image_url': 'https://example.invalid/b2b-primary.jpg',
        'images': [
          'https://example.invalid/b2b-primary.jpg',
          'https://example.invalid/b2b-second.jpg',
        ],
      });
      final actionApi = _FakeCustomerActionApi();
      await tester.pumpWidget(
        FoodexCustomerApp(
          session: b2b,
          initialRoute: '/b2b/products/42?store_id=7',
          b2bApi: api,
          actionApi: actionApi,
        ),
      );
      await _pumpCustomerFrames(tester);

      expect(api.lastPath, '/api/v1/b2b/products/42?store_id=7');
      expect(
        find.byKey(const ValueKey('b2b-product-detail-data')),
        findsOneWidget,
      );
      expect(find.byKey(const ValueKey('b2b-product-gallery')), findsOneWidget);
      expect(
        find.byKey(const ValueKey('foodex-wholesale-shared-header')),
        findsOneWidget,
      );
      expect(
        find.byKey(const ValueKey('b2b-product-detail-hero')),
        findsOneWidget,
      );
      expect(find.byKey(const ValueKey('b2b-product-price')), findsOneWidget);
      expect(
        find.byKey(const ValueKey('b2b-product-commerce-strip')),
        findsOneWidget,
      );
      expect(find.byKey(const ValueKey('b2b-product-meta')), findsOneWidget);
      expect(
        find.byKey(const ValueKey('b2b-product-description-section')),
        findsOneWidget,
      );
      expect(
        find.byKey(const ValueKey('b2b-product-additional-info')),
        findsNothing,
      );
      expect(find.byType(PageView), findsOneWidget);
      expect(find.text('Wholesale Product'), findsOneWidget);
      expect(find.textContaining('7.25 KWD'), findsOneWidget);
      expect(find.textContaining('5'), findsWidgets);
      expect(find.text('إضافة إلى السلة'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('customer-add-cart')));
      await _pumpCustomerFrames(tester);

      expect(actionApi.lastStoreId, 7);
      expect(actionApi.lastProductId, 42);
      expect(
        find.byKey(const ValueKey('wholesale-cart-confirmation-42')),
        findsOneWidget,
      );
      expect(
        find.byKey(const ValueKey('wholesale-cart-confirmation-close-42')),
        findsOneWidget,
      );
      expect(find.text('سلة الجملة'), findsNothing);
      expect(api.lastPath, '/api/v1/b2b/products/42?store_id=7');

      await tester.tap(
        find.byKey(const ValueKey('wholesale-cart-confirmation-close-42')),
      );
      await _pumpCustomerFrames(tester);
      expect(
        find.byKey(const ValueKey('b2b-product-detail-data')),
        findsOneWidget,
      );
    },
  );

  testWidgets(
    'B2B product detail hides base wholesale/pack box and shows same-category products',
    (tester) async {
      tester.view.physicalSize = const Size(800, 1600);
      tester.view.devicePixelRatio = 1;
      addTearDown(() {
        tester.view.resetPhysicalSize();
        tester.view.resetDevicePixelRatio();
      });

      final api = _PathB2bApi({
        '/api/v1/b2b/products/42?store_id=7': {
          'id': 42,
          'sku': 'MAIN-42',
          'name': 'Main Product',
          'store_id': 7,
          'category_id': 3,
          'category_name': 'Beverages',
          'account_price': 7.25,
          'base_wholesale_price': 8.0,
          'retail_reference_price': 9.5,
          'minimum_order_quantity': 1,
          'ordering_increment': 1,
          'pack_size': 12,
          'pack_label': 'Case 12',
          'available_quantity': 20,
          'is_available': true,
          'currency': 'EGP',
        },
        '/api/v1/b2b/products?store_id=7&category_id=3': {
          'data': [
            {
              'id': 42,
              'name': 'Main Product',
              'category_id': 3,
              'account_price': 7.25,
              'currency': 'EGP',
            },
            {
              'id': 43,
              'name': 'Similar Drink',
              'category_id': 3,
              'account_price': 6.5,
              'minimum_order_quantity': 1,
              'available_quantity': 10,
              'is_available': true,
              'currency': 'EGP',
            },
          ],
        },
      });

      await tester.pumpWidget(
        FoodexCustomerApp(
          session: b2b,
          locale: const Locale('ar'),
          initialRoute: '/b2b/products/42?store_id=7',
          b2bApi: api,
          actionApi: _FakeCustomerActionApi(),
        ),
      );
      await _pumpCustomerFrames(tester);

      expect(
        find.byKey(const ValueKey('b2b-product-additional-info')),
        findsNothing,
      );
      expect(find.text('سعر الجملة الأساسي'), findsNothing);
      expect(
        find.byKey(const ValueKey('b2b-product-similar-title')),
        findsOneWidget,
      );
      await tester.scrollUntilVisible(
        find.text('Similar Drink'),
        250,
        scrollable: find.byType(Scrollable).first,
      );
      expect(find.text('Similar Drink'), findsOneWidget);
    },
  );

  testWidgets(
    'B2B product detail revalidates changed commercial terms before add',
    (tester) async {
      tester.view.physicalSize = const Size(800, 1500);
      tester.view.devicePixelRatio = 1;
      addTearDown(() {
        tester.view.resetPhysicalSize();
        tester.view.resetDevicePixelRatio();
      });

      final api = _SequenceB2bApi([
        {
          'id': 42,
          'sku': 'LIVE-42',
          'name': 'Live Wholesale Product',
          'store_id': 7,
          'brand_name': 'FOODEX',
          'category_name': 'Beverages',
          'description': 'Fresh authoritative description',
          'account_price': 7.25,
          'base_wholesale_price': 8.0,
          'retail_reference_price': 9.5,
          'minimum_order_quantity': 5,
          'ordering_increment': 5,
          'pack_size': 12,
          'case_size': 24,
          'pack_label': 'Case 12',
          'available_quantity': 30,
          'is_available': true,
          'availability_state': 'AVAILABLE',
          'currency': 'EGP',
        },
        {
          'id': 42,
          'sku': 'LIVE-42',
          'name': 'Live Wholesale Product',
          'store_id': 7,
          'brand_name': 'FOODEX',
          'category_name': 'Beverages',
          'description': 'Fresh authoritative description',
          'account_price': 8.0,
          'base_wholesale_price': 8.5,
          'retail_reference_price': 9.5,
          'minimum_order_quantity': 10,
          'ordering_increment': 5,
          'pack_size': 12,
          'case_size': 24,
          'pack_label': 'Case 12',
          'available_quantity': 20,
          'is_available': true,
          'availability_state': 'AVAILABLE',
          'currency': 'EGP',
        },
      ]);
      final actionApi = _CountingCustomerActionApi();

      await tester.pumpWidget(
        FoodexCustomerApp(
          session: b2b,
          locale: const Locale('en'),
          initialRoute: '/b2b/products/42?store_id=7',
          b2bApi: api,
          actionApi: actionApi,
        ),
      );
      await _pumpCustomerFrames(tester);

      expect(find.text('Brand: FOODEX'), findsOneWidget);
      expect(find.text('Category: Beverages'), findsOneWidget);
      expect(find.textContaining('7.25 EGP'), findsOneWidget);

      final descriptionDisclosure = find.text('Description');
      expect(descriptionDisclosure, findsOneWidget);
      await tester.ensureVisible(descriptionDisclosure);
      await tester.tap(descriptionDisclosure);
      await _pumpCustomerFrames(tester);
      expect(find.text('Fresh authoritative description'), findsOneWidget);

      await tester.ensureVisible(
        find.byKey(const ValueKey('customer-add-cart')),
      );
      await tester.tap(find.byKey(const ValueKey('customer-add-cart')));
      await _pumpCustomerFrames(tester);

      expect(api.calls, 2);
      expect(actionApi.addCalls, 0);
      expect(
        find.byKey(const ValueKey('b2b-product-terms-updated')),
        findsOneWidget,
      );
      expect(find.textContaining('Price or stock changed'), findsOneWidget);
      expect(find.textContaining('8.00 EGP'), findsOneWidget);
      expect(find.textContaining('Minimum order 10'), findsOneWidget);
    },
  );

  testWidgets(
    'B2B product detail enforces max stock and auto-recovers from out of stock',
    (tester) async {
      tester.view.physicalSize = const Size(800, 1500);
      tester.view.devicePixelRatio = 1;
      addTearDown(() {
        tester.view.resetPhysicalSize();
        tester.view.resetDevicePixelRatio();
      });

      final api = _SequenceB2bApi([
        {
          'id': 42,
          'sku': 'STOCK-42',
          'name': 'Stock Product',
          'store_id': 7,
          'account_price': 10,
          'minimum_order_quantity': 5,
          'ordering_increment': 5,
          'pack_size': 1,
          'available_quantity': 0,
          'is_available': false,
          'availability_state': 'OUT_OF_STOCK',
          'currency': 'EGP',
        },
        {
          'id': 42,
          'sku': 'STOCK-42',
          'name': 'Stock Product',
          'store_id': 7,
          'account_price': 10,
          'minimum_order_quantity': 5,
          'ordering_increment': 5,
          'pack_size': 1,
          'available_quantity': 6,
          'is_available': true,
          'availability_state': 'AVAILABLE',
          'currency': 'EGP',
        },
      ]);

      await tester.pumpWidget(
        FoodexCustomerApp(
          session: b2b,
          locale: const Locale('en'),
          initialRoute: '/b2b/products/42?store_id=7',
          b2bApi: api,
          actionApi: _CountingCustomerActionApi(),
        ),
      );
      await _pumpCustomerFrames(tester);

      expect(
        find.byKey(const ValueKey('b2b-product-commerce-strip')),
        findsOneWidget,
      );
      expect(api.calls, greaterThanOrEqualTo(1));
      expect(
        find.byKey(const ValueKey('b2b-product-stock-auto-refresh')),
        findsOneWidget,
      );
    },
  );

  testWidgets(
    'B2B add-to-cart checkout success journey completes in AR and EN',
    (tester) async {
      addTearDown(() {
        tester.view.resetPhysicalSize();
        tester.view.resetDevicePixelRatio();
      });
      tester.view.physicalSize = const Size(430, 932);
      tester.view.devicePixelRatio = 1;

      for (final locale in const [Locale('ar'), Locale('en')]) {
        final actionApi = _CountingCustomerActionApi();
        final b2bApi = _FakeB2bApi({
          'id': 42,
          'sku': 'B2B-E2E-42',
          'name': 'FOODEX Wholesale Water',
          'store_id': 7,
          'brand_name': 'FOODEX',
          'category_name': 'Beverages',
          'description': 'Authoritative seeded journey product',
          'account_price': 7.25,
          'minimum_order_quantity': 1,
          'ordering_increment': 1,
          'available_quantity': 24,
          'is_available': true,
          'availability_state': 'AVAILABLE',
          'currency': 'KWD',
        });
        final storefront = _C13CheckoutStorefrontApi({
          'store_id': 7,
          'store_name': 'FOODEX Wholesale',
          'customer_name': 'Launch Buyer',
          'addresses': [
            {
              'id': 81,
              'label': 'Warehouse',
              'line1': 'FOODEX Street 1',
              'city': 'Kuwait City',
            },
          ],
          'delivery_dates': ['2026-10-13'],
          'payment_methods': ['cash_on_delivery'],
          'purchasing_power': 500,
          'currency': 'KWD',
        });
        final commerce = _CheckoutJourneyWholesaleCommerceApi();

        await tester.pumpWidget(
          FoodexCustomerApp(
            session: b2b,
            locale: locale,
            initialRoute: '/b2b/products/42?store_id=7',
            b2bApi: b2bApi,
            actionApi: actionApi,
            storefrontApi: storefront,
            wholesaleCommerceApi: commerce,
          ),
        );
        await _pumpCustomerFrames(tester);

        await tester.ensureVisible(
          find.byKey(const ValueKey('customer-add-cart')),
        );
        await tester.tap(find.byKey(const ValueKey('customer-add-cart')));
        await _pumpCustomerFrames(tester);

        expect(actionApi.addCalls, 1);
        expect(actionApi.lastProductId, 42);
        expect(find.byType(SnackBarAction), findsOneWidget);

        await tester.tap(find.byType(SnackBarAction));
        await _pumpCustomerFrames(tester);

        final checkout = find.byKey(const ValueKey('b2b-cart-checkout'));
        expect(checkout, findsOneWidget);
        await tester.ensureVisible(checkout);
        await tester.tap(checkout);
        await _pumpCustomerFrames(tester);

        final submit = find.byKey(const ValueKey('b2b-checkout-submit'));
        await tester.scrollUntilVisible(
          submit,
          300,
          scrollable: find
              .descendant(
                of: find.byKey(const ValueKey('b2b-checkout-data')),
                matching: find.byType(Scrollable),
              )
              .first,
        );
        expect(submit, findsOneWidget);
        await tester.tap(submit);
        await _pumpCustomerFrames(tester);

        expect(commerce.checkoutCalls, 1);
        expect(
          find.byKey(const ValueKey('customer-order-success')),
          findsOneWidget,
        );
        expect(find.text('FDX-E2E-707'), findsOneWidget);
        expect(tester.takeException(), isNull);

        await tester.pumpWidget(const SizedBox.shrink());
        await tester.pump();
      }
    },
  );

  testWidgets('B2B cart exposes authoritative checkout action', (tester) async {
    await tester.pumpWidget(
      const FoodexCustomerApp(
        session: b2b,
        initialRoute: '/b2b/cart',
        b2bApi: _StaticB2bApi(),
      ),
    );
    await _pumpCustomerFrames(tester);
    expect(find.text('سلة الجملة'), findsOneWidget);
    expect(find.byKey(const ValueKey('foodex-wholesale-shared-header')),
        findsOneWidget);
    expect(find.text('إتمام الطلب'), findsOneWidget);
  });

  testWidgets(
    'B2B checkout confirms order before explicit tracking navigation',
    (tester) async {
      final api = _FakeB2bApi({
        'id': 4,
        'order_number': 'FDX-B2B-4',
        'status': 'pending',
        'store_name': 'Wholesale Store',
        'grand_total': 25.5,
        'currency': 'KWD',
        'payment_method': 'cash_on_delivery',
        'items': [
          {
            'name': 'Bulk Water',
            'quantity': 2,
            'unit_price': 12.75,
            'line_total': 25.5,
          },
        ],
      });

      await tester.pumpWidget(
        FoodexCustomerApp(
          session: b2b,
          initialRoute: '/b2b/checkout?store=7',
          b2bApi: api,
          storefrontApi: const _CheckoutStorefrontApi(),
          wholesaleCommerceApi: const _FakeWholesaleCommerceApi({
            'id': 4,
            'order_number': 'FDX-B2B-4',
            'currency': 'KWD',
            'subtotal': 25.5,
            'discount_total': 0.0,
            'delivery_total': 0.0,
            'tax_total': 0.0,
            'grand_total': 25.5,
          }),
        ),
      );
      await _pumpCustomerFrames(tester);

      await tester.scrollUntilVisible(
        find.text('تأكيد الطلب'),
        300,
        scrollable: find
            .descendant(
              of: find.byKey(const ValueKey('b2b-checkout-data')),
              matching: find.byType(Scrollable),
            )
            .first,
      );
      await tester.tap(find.text('تأكيد الطلب'));
      await _pumpCustomerFrames(tester);

      expect(
        find.byKey(const ValueKey('customer-order-success')),
        findsOneWidget,
      );
      expect(find.text('FDX-B2B-4'), findsOneWidget);
      expect(api.lastPath, isNot('/api/v1/b2b/orders/4'));

      await tester.ensureVisible(
        find.byKey(const ValueKey('customer-order-success-track')),
      );
      await tester.tap(
        find.byKey(const ValueKey('customer-order-success-track')),
      );
      await _pumpCustomerFrames(tester);

      expect(api.lastPath, '/api/v1/b2b/orders/4');
      expect(find.byKey(const ValueKey('b2b-order-detail')), findsOneWidget);
      expect(find.text('#4'), findsOneWidget);
      await _scrollUntilBuilt(
        tester,
        find.byKey(const ValueKey('b2b-order-detail')),
        find.text('الدفع عند الاستلام'),
      );
      expect(find.text('الدفع عند الاستلام'), findsOneWidget);
      expect(find.text('/orders/4/track'), findsNothing);
    },
  );

  testWidgets('B2B checkout rejects missing authoritative order reference', (
    tester,
  ) async {
    final api = _FakeB2bApi(const {'data': <Object>[]});

    await tester.pumpWidget(
      FoodexCustomerApp(
        session: b2b,
        initialRoute: '/b2b/checkout?store=7',
        b2bApi: api,
        storefrontApi: const _CheckoutStorefrontApi(),
        wholesaleCommerceApi: const _FakeWholesaleCommerceApi(
          <String, Object?>{},
        ),
      ),
    );
    await _pumpCustomerFrames(tester);

    await tester.scrollUntilVisible(
      find.text('تأكيد الطلب'),
      300,
      scrollable: find
          .descendant(
            of: find.byKey(const ValueKey('b2b-checkout-data')),
            matching: find.byType(Scrollable),
          )
          .first,
    );
    await tester.tap(find.text('تأكيد الطلب'));
    await _pumpCustomerFrames(tester);

    expect(
      find.byKey(const ValueKey('customer-order-success')),
      findsNothing,
    );
    expect(
      find.byKey(const ValueKey('b2b-checkout-data')),
      findsOneWidget,
    );
    expect(find.byKey(const ValueKey('b2b-empty')), findsNothing);
  });

  testWidgets(
    'C13 cart shows authoritative line state totals and visible remove/clear actions',
    (tester) async {
      tester.view.physicalSize = const Size(900, 1800);
      tester.view.devicePixelRatio = 1;
      addTearDown(() {
        tester.view.resetPhysicalSize();
        tester.view.resetDevicePixelRatio();
      });

      final commerce = _C13WholesaleCommerceApi(
        cartValue: {
          'store_id': 7,
          'currency': 'EGP',
          'subtotal': 30.0,
          'has_unavailable_items': false,
          'quote': {
            'discount_total': 2.0,
            'delivery_total': 3.0,
            'tax_total': 1.0,
            'grand_total': 32.0,
          },
          'items': [
            {
              'id': 11,
              'product': {
                'id': 101,
                'name': 'Bulk Water',
                'sku': 'W-101',
                'image_url': null,
              },
              'quantity': 2.0,
              'unit_price_snapshot': 10.0,
              'line_total': 20.0,
              'is_available': true,
              'available_quantity': 8.0,
              'minimum_order_quantity': 1.0,
              'ordering_increment': 1.0,
              'pack_label': 'Case 12',
            },
            {
              'id': 12,
              'product': {
                'id': 102,
                'name': 'Bulk Juice',
                'sku': 'J-102',
                'image_url': null,
              },
              'quantity': 1.0,
              'unit_price_snapshot': 10.0,
              'line_total': 10.0,
              'is_available': true,
              'available_quantity': 5.0,
              'minimum_order_quantity': 1.0,
              'ordering_increment': 1.0,
            },
          ],
        },
      );

      await tester.pumpWidget(
        FoodexCustomerApp(
          session: b2b,
          locale: const Locale('en'),
          initialRoute: '/b2b/cart?store_id=7',
          wholesaleCommerceApi: commerce,
        ),
      );
      await _pumpCustomerFrames(tester);

      expect(find.text('Wholesale cart'), findsOneWidget);
      expect(
        find.byKey(const ValueKey('foodex-wholesale-shared-header')),
        findsOneWidget,
      );
      expect(find.text('Bulk Water'), findsOneWidget);
      expect(find.text('W-101'), findsNothing);
      expect(find.text('Case 12'), findsOneWidget);
      expect(find.textContaining('Unit price'), findsWidgets);
      expect(find.textContaining('Line total'), findsWidgets);
      expect(find.textContaining('Available: 8'), findsNothing);

      final minusIcon = tester.widget<Icon>(
        find.descendant(
          of: find.byKey(const ValueKey('b2b-cart-minus-11')),
          matching: find.byIcon(Icons.remove_rounded),
        ),
      );
      final plusIcon = tester.widget<Icon>(
        find.descendant(
          of: find.byKey(const ValueKey('b2b-cart-plus-11')),
          matching: find.byIcon(Icons.add_rounded),
        ),
      );
      expect(minusIcon.size, 12);
      expect(plusIcon.size, 12);

      expect(find.text('Discounts'), findsOneWidget);
      expect(find.text('Delivery'), findsOneWidget);
      expect(find.text('Tax'), findsOneWidget);
      expect(find.byKey(const ValueKey('b2b-cart-clear')), findsOneWidget);
      final removeButton = find.byKey(
        const ValueKey('b2b-cart-remove-Bulk Water'),
      );
      expect(removeButton, findsOneWidget);
      final removeIcon = tester.widget<Icon>(
        find.descendant(
          of: removeButton,
          matching: find.byIcon(Icons.delete_outline_rounded),
        ),
      );
      expect(removeIcon.color, const Color(0xFFD92D20));

      await tester.tap(
        find.byKey(const ValueKey('b2b-cart-remove-Bulk Water')),
      );
      await _pumpCustomerFrames(tester);
      expect(commerce.removeCalls, 1);
      expect(find.text('Bulk Water'), findsNothing);
      expect(find.text('Bulk Juice'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('b2b-cart-clear')));
      await _pumpCustomerFrames(tester);
      expect(commerce.removeCalls, 2);
      expect(find.text('The cart is empty'), findsOneWidget);
    },
  );

  testWidgets(
    'B2B cart coalesces rapid plus taps while the first quantity update is slow',
    (tester) async {
      tester.view.physicalSize = const Size(900, 1800);
      tester.view.devicePixelRatio = 1;
      addTearDown(() {
        tester.view.resetPhysicalSize();
        tester.view.resetDevicePixelRatio();
      });

      final commerce = _DelayedQuantityWholesaleCommerceApi(
        cartValue: {
          'store_id': 7,
          'currency': 'EGP',
          'subtotal': 20.0,
          'has_unavailable_items': false,
          'quote': {'grand_total': 20.0},
          'items': [
            {
              'id': 11,
              'product': {
                'id': 101,
                'name': 'Slow Bulk Water',
                'sku': 'SLOW-101',
                'image_url': null,
              },
              'quantity': 2.0,
              'unit_price_snapshot': 10.0,
              'line_total': 20.0,
              'is_available': true,
              'available_quantity': 10.0,
              'minimum_order_quantity': 1.0,
              'ordering_increment': 1.0,
            },
          ],
        },
      );

      await tester.pumpWidget(
        FoodexCustomerApp(
          session: b2b,
          locale: const Locale('en'),
          initialRoute: '/b2b/cart?store_id=7',
          wholesaleCommerceApi: commerce,
        ),
      );
      await _pumpCustomerFrames(tester);

      final plus = find.byKey(const ValueKey('b2b-cart-plus-11'));
      expect(plus, findsOneWidget);
      expect(commerce.cartCalls, 1);

      await tester.tap(plus);
      await tester.pump();
      await tester.tap(plus);
      await tester.pump();
      await tester.tap(plus);
      await tester.pump();

      // The UI reaches the latest target immediately, but only the first PATCH
      // is in flight. Later taps are coalesced while that request is unresolved.
      expect(find.text('5'), findsOneWidget);
      expect(commerce.updateQuantities, <double>[3.0]);
      expect(commerce.cartCalls, 1);

      commerce.releaseFirstUpdate();
      await tester.pump();
      await _pumpCustomerFrames(tester);

      expect(commerce.updateQuantities, <double>[3.0, 5.0]);
      expect(commerce.cartCalls, 1);
      expect(find.text('5'), findsOneWidget);
    },
  );

  testWidgets(
    'C13 checkout exposes financial position and blocks insufficient account credit',
    (tester) async {
      tester.view.physicalSize = const Size(900, 1900);
      tester.view.devicePixelRatio = 1;
      addTearDown(() {
        tester.view.resetPhysicalSize();
        tester.view.resetDevicePixelRatio();
      });

      final commerce = _C13WholesaleCommerceApi(
        cartValue: {
          'store_id': 7,
          'currency': 'EGP',
          'subtotal': 25.0,
          'has_unavailable_items': false,
          'quote': {
            'discount_total': 0.0,
            'delivery_total': 0.0,
            'tax_total': 0.0,
            'grand_total': 25.0,
          },
          'items': [
            {
              'id': 21,
              'product': {'id': 201, 'name': 'Credit Item', 'sku': 'C-201'},
              'quantity': 1.0,
              'unit_price_snapshot': 25.0,
              'line_total': 25.0,
              'is_available': true,
              'minimum_order_quantity': 1.0,
              'ordering_increment': 1.0,
            },
          ],
        },
      );
      final storefront = _C13CheckoutStorefrontApi({
        'store_id': 7,
        'store_name': 'Wholesale Store',
        'customer_name': 'Acme Buyer',
        'addresses': [
          {'id': 1, 'label': 'Warehouse', 'line1': 'Street 1', 'city': 'Cairo'},
        ],
        'delivery_dates': ['2026-10-06'],
        'payment_methods': ['account_credit'],
        'customer_credit_balance': 1.0,
        'outstanding_receivable': 8.0,
        'credit_limit': 20.0,
        'available_credit_line': 12.0,
        'purchasing_power': 13.0,
        'currency': 'EGP',
      });

      await tester.pumpWidget(
        FoodexCustomerApp(
          session: b2b,
          locale: const Locale('en'),
          initialRoute: '/b2b/checkout?store_id=7',
          storefrontApi: storefront,
          wholesaleCommerceApi: commerce,
        ),
      );
      await _pumpCustomerFrames(tester);

      expect(find.text('Current financial position'), findsNothing);
      expect(find.text('Available purchasing power'), findsNothing);
      expect(find.text('Final review'), findsWidgets);
      expect(find.text('Acme Buyer'), findsOneWidget);
      expect(find.text('Wholesale Store'), findsOneWidget);
      expect(
        find.byKey(const ValueKey('b2b-checkout-insufficient-credit')),
        findsOneWidget,
      );
      final submit = tester.widget<InkWell>(
        find.descendant(
          of: find.byKey(const ValueKey('b2b-checkout-submit')),
          matching: find.byType(InkWell),
        ),
      );
      expect(submit.onTap, isNull);
    },
  );

  testWidgets(
    'C13 checkout prevents double submit with one stable attempt key',
    (tester) async {
      tester.view.physicalSize = const Size(900, 1900);
      tester.view.devicePixelRatio = 1;
      addTearDown(() {
        tester.view.resetPhysicalSize();
        tester.view.resetDevicePixelRatio();
      });

      final completer = Completer<Object?>();
      final commerce = _C13WholesaleCommerceApi(
        checkoutCompleter: completer,
        cartValue: {
          'store_id': 7,
          'currency': 'EGP',
          'subtotal': 10.0,
          'has_unavailable_items': false,
          'quote': {'grand_total': 10.0},
          'items': [
            {
              'id': 31,
              'product': {'id': 301, 'name': 'Submit Item', 'sku': 'S-301'},
              'quantity': 1.0,
              'unit_price_snapshot': 10.0,
              'line_total': 10.0,
              'is_available': true,
              'minimum_order_quantity': 1.0,
              'ordering_increment': 1.0,
            },
          ],
        },
      );
      final storefront = _C13CheckoutStorefrontApi({
        'store_id': 7,
        'store_name': 'Wholesale Store',
        'customer_name': 'Acme Buyer',
        'addresses': [
          {'id': 1, 'label': 'Warehouse', 'line1': 'Street 1'},
        ],
        'delivery_dates': ['2026-10-06'],
        'payment_methods': ['cash_on_delivery'],
        'purchasing_power': 0.0,
        'currency': 'EGP',
      });

      await tester.pumpWidget(
        FoodexCustomerApp(
          session: b2b,
          locale: const Locale('en'),
          initialRoute: '/b2b/checkout?store_id=7',
          storefrontApi: storefront,
          wholesaleCommerceApi: commerce,
          b2bApi: _StaticB2bApi(),
        ),
      );
      await _pumpCustomerFrames(tester);

      final submitFinder = find.byKey(const ValueKey('b2b-checkout-submit'));
      await tester.ensureVisible(submitFinder);
      await tester.tap(submitFinder);
      await tester.pump();

      expect(commerce.checkoutCalls, 1);
      expect(commerce.checkoutKeys, hasLength(1));
      expect(commerce.checkoutKeys.single.length, greaterThanOrEqualTo(16));
      expect(
        tester
            .widget<InkWell>(
              find.descendant(
                of: submitFinder,
                matching: find.byType(InkWell),
              ),
            )
            .onTap,
        isNull,
      );

      // A second physical tap cannot create a second checkout while the first
      // authoritative request is still unresolved.
      await tester.tap(submitFinder, warnIfMissed: false);
      await tester.pump();
      expect(commerce.checkoutCalls, 1);

      completer.complete(<String, Object?>{
        'id': 31,
        'order_number': 'FDX-C13-31',
        'currency': 'EGP',
        'subtotal': 10.0,
        'discount_total': 0.0,
        'delivery_total': 0.0,
        'tax_total': 0.0,
        'grand_total': 10.0,
      });
      await _pumpCustomerFrames(tester);

      expect(
        find.byKey(const ValueKey('customer-order-success')),
        findsOneWidget,
      );
      expect(find.text('FDX-C13-31'), findsOneWidget);
    },
  );

  testWidgets(
    'B2B order details refresh route stays on authoritative B2B endpoint',
    (tester) async {
      final api = _FakeB2bApi({
        'id': 77,
        'order_number': 'B2B-77',
        'status': 'out_for_delivery',
        'grand_total': 101.0,
        'currency': 'KWD',
        'payment_method': 'account_credit',
        'items': const <Object>[],
      });

      await tester.pumpWidget(
        FoodexCustomerApp(
          session: b2b,
          initialRoute: '/b2b/orders/77',
          b2bApi: api,
        ),
      );
      await _pumpCustomerFrames(tester);

      expect(api.lastPath, '/api/v1/b2b/orders/77');
      expect(find.byKey(const ValueKey('b2b-order-detail')), findsOneWidget);
      expect(find.text('#77'), findsOneWidget);
    },
  );

  testWidgets(
    'B2B wholesale orders list renders seller state and opens exact detail',
    (tester) async {
      final api = _PathB2bApi({
        '/api/v1/b2b/orders': {
          'data': [
            {
              'id': 91,
              'order_number': 'B2B-91',
              'status': 'ready',
              'grand_total': 42.5,
              'currency': 'KWD',
              'created_at': '2026-10-01T20:00:00+00:00',
              'store': {'id': 1, 'name': 'FOODEX Wholesale'},
            },
          ],
        },
        '/api/v1/b2b/orders/91': {
          'id': 91,
          'order_number': 'B2B-91',
          'status': 'ready',
          'grand_total': 42.5,
          'currency': 'KWD',
          'payment_method': 'account_credit',
          'store': {'id': 1, 'name': 'FOODEX Wholesale'},
          'timeline': [
            {'stage': 'placed', 'occurred_at': '2026-10-01T20:00:00+00:00'},
            {'stage': 'ready', 'occurred_at': '2026-10-01T20:10:00+00:00'},
          ],
          'items': const <Object>[],
        },
      });

      await tester.pumpWidget(
        FoodexCustomerApp(
          session: b2b,
          initialRoute: '/b2b/orders',
          b2bApi: api,
        ),
      );
      await _pumpCustomerFrames(tester);

      expect(find.byKey(const ValueKey('b2b-orders-data')), findsOneWidget);
      expect(find.byKey(const ValueKey('b2b-order-row-91')), findsOneWidget);
      expect(find.text('#91'), findsOneWidget);
      expect(find.text('FOODEX Wholesale'), findsNothing);
      expect(find.text('جاهز'), findsOneWidget);
      expect(find.text('KWD 42.5'), findsOneWidget);

      await tester.tap(find.text('#91'));
      await _pumpCustomerFrames(tester);

      expect(api.lastPath, '/api/v1/b2b/orders/91');
      expect(find.byKey(const ValueKey('b2b-order-detail')), findsOneWidget);
      expect(
        find.byKey(const ValueKey('b2b-timeline-0-placed')),
        findsOneWidget,
      );
      expect(
        find.byKey(const ValueKey('b2b-timeline-1-ready')),
        findsOneWidget,
      );
    },
  );

  testWidgets(
    'B2B order timeline renders only authoritative events and refreshes state',
    (tester) async {
      final api = _SequenceB2bApi([
        {
          'id': 77,
          'order_number': 'B2B-77',
          'status': 'out_for_delivery',
          'grand_total': 101.0,
          'currency': 'KWD',
          'payment_method': 'cash_on_delivery',
          'store': {'id': 1, 'name': 'FOODEX Wholesale'},
          'delivery_address': {
            'label': 'Warehouse',
            'line1': 'Street 1',
            'city': 'Kuwait City',
          },
          'timeline': [
            {'stage': 'placed', 'occurred_at': '2026-10-01T20:00:00+00:00'},
            {'stage': 'ready', 'occurred_at': '2026-10-01T20:10:00+00:00'},
            {
              'stage': 'van_assigned',
              'occurred_at': '2026-10-01T20:12:00+00:00',
              'van_code': 'VAN-07',
            },
            {
              'stage': 'out_for_delivery',
              'occurred_at': '2026-10-01T20:20:00+00:00',
            },
          ],
          'items': const <Object>[],
        },
        {
          'id': 77,
          'order_number': 'B2B-77',
          'status': 'delivered',
          'grand_total': 101.0,
          'currency': 'KWD',
          'payment_method': 'cash_on_delivery',
          'store': {'id': 1, 'name': 'FOODEX Wholesale'},
          'timeline': [
            {'stage': 'placed', 'occurred_at': '2026-10-01T20:00:00+00:00'},
            {'stage': 'delivered', 'occurred_at': '2026-10-01T20:30:00+00:00'},
          ],
          'items': const <Object>[],
        },
      ]);

      await tester.pumpWidget(
        FoodexCustomerApp(
          session: b2b,
          initialRoute: '/b2b/orders/77',
          b2bApi: api,
        ),
      );
      await _pumpCustomerFrames(tester);

      expect(
        find.byKey(const ValueKey('b2b-timeline-0-placed')),
        findsOneWidget,
      );
      expect(
        find.byKey(const ValueKey('b2b-timeline-1-ready')),
        findsOneWidget,
      );
      expect(
        find.byKey(const ValueKey('b2b-timeline-2-van_assigned')),
        findsOneWidget,
      );
      expect(
        find.byKey(const ValueKey('b2b-timeline-3-out_for_delivery')),
        findsOneWidget,
      );

      Icon timelineIcon(String stage) => tester.widget<Icon>(
            find
                .descendant(
                  of: find.byKey(ValueKey('b2b-timeline-icon-$stage')),
                  matching: find.byType(Icon),
                )
                .first,
          );

      final placedIcon = timelineIcon('placed');
      final readyIcon = timelineIcon('ready');
      final assignedIcon = timelineIcon('van_assigned');
      final deliveryIcon = timelineIcon('out_for_delivery');
      expect(placedIcon.icon, Icons.receipt_long_outlined);
      expect(readyIcon.icon, Icons.task_alt_rounded);
      expect(assignedIcon.icon, Icons.local_shipping_outlined);
      expect(deliveryIcon.icon, Icons.delivery_dining_rounded);
      expect(placedIcon.color, isNot(readyIcon.color));
      expect(readyIcon.color, isNot(deliveryIcon.color));

      expect(find.text('VAN-07'), findsOneWidget);
      expect(find.textContaining('Driver'), findsNothing);
      expect(find.text('تم التأكيد'), findsNothing);
      expect(find.text('جاري التجهيز'), findsNothing);
      expect(find.text('تم التسليم'), findsNothing);
      await _scrollUntilBuilt(
        tester,
        find.byKey(const ValueKey('b2b-order-detail')),
        find.text('الدفع عند الاستلام'),
      );
      expect(find.text('الدفع عند الاستلام'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('b2b-order-refresh')));
      await _pumpCustomerFrames(tester);

      expect(api.calls, 2);
      expect(
        find.byKey(const ValueKey('b2b-timeline-1-delivered')),
        findsOneWidget,
      );
      final deliveredIcon = tester.widget<Icon>(
        find
            .descendant(
              of: find.byKey(
                const ValueKey('b2b-timeline-icon-delivered'),
              ),
              matching: find.byType(Icon),
            )
            .first,
      );
      expect(deliveredIcon.icon, Icons.home_rounded);
      expect(deliveredIcon.color, isNot(deliveryIcon.color));
      expect(find.text('تم التسليم'), findsWidgets);
      expect(find.byKey(const ValueKey('b2b-timeline-1-ready')), findsNothing);
    },
  );

  testWidgets('B2B awaiting dispatch is explicit and never invents a Driver', (
    tester,
  ) async {
    final api = _FakeB2bApi({
      'id': 79,
      'order_number': 'B2B-79',
      'status': 'pending',
      'channel': 'b2b',
      'currency': 'KWD',
      'grand_total': 20.0,
      'store': {'id': 7, 'name': 'Wholesale Store'},
      'tracking': {
        'actor_type': 'van',
        'status': 'awaiting_dispatch',
        'van_code': null,
      },
      'timeline': [
        {'stage': 'placed', 'occurred_at': '2026-10-04T10:00:00+00:00'},
        {
          'stage': 'awaiting_dispatch',
          'occurred_at': '2026-10-04T10:01:00+00:00',
        },
      ],
      'items': const <Object>[],
    });

    await tester.pumpWidget(
      FoodexCustomerApp(
        session: b2b,
        initialRoute: '/b2b/orders/79',
        b2bApi: api,
        locale: const Locale('en'),
      ),
    );
    await _pumpCustomerFrames(tester);

    expect(
      find.byKey(const ValueKey('b2b-timeline-1-awaiting_dispatch')),
      findsOneWidget,
    );
    await _scrollUntilBuilt(
      tester,
      find.byKey(const ValueKey('b2b-order-detail')),
      find.text('Awaiting dispatch'),
    );
    expect(find.text('Awaiting dispatch'), findsWidgets);
    expect(find.text('Driver'), findsNothing);
  });

  testWidgets(
    'B2B order details expose complete authoritative surface in English LTR',
    (tester) async {
      final api = _FakeB2bApi({
        'id': 78,
        'order_number': 'B2B-78',
        'status': 'failed',
        'channel': 'b2b',
        'created_at': '2026-10-04T10:00:00+00:00',
        'requested_delivery_date': '2026-10-05',
        'store': {'id': 7, 'name': 'Wholesale Store'},
        'currency': 'KWD',
        'subtotal': 100.0,
        'discount_total': 5.0,
        'tax_total': 4.0,
        'delivery_total': 1.0,
        'grand_total': 100.0,
        'payment_method': 'account_credit',
        'payment': {'status': 'paid', 'amount': 100.0, 'currency': 'KWD'},
        'account_credit_impact': {
          'amount': 100.0,
          'currency': 'KWD',
          'status': 'paid',
        },
        'delivery_address': {
          'recipient_name': 'Buyer One',
          'delivery_phone': '55512345',
          'line1': 'Street 1',
          'city': 'Kuwait City',
          'latitude': 29.37,
          'longitude': 47.98,
          'has_coordinates': true,
        },
        'tracking': {
          'actor_type': 'van',
          'van_code': 'VAN-ONE',
          'status': 'failed',
          'assigned_at': '2026-10-04T10:10:00+00:00',
        },
        'allowed_actions': {
          'view_map': true,
          'view_invoice': true,
          'cancel': false,
          'reorder': false,
          'contact_support': false,
        },
        'invoice': {
          'id': 44,
          'invoice_number': 'INV-B2B-78',
          'status': 'issued',
        },
        'is_terminal': true,
        'customer_note': 'Leave at gate',
        'items': [
          {
            'id': 1,
            'product_id': 42,
            'sku': 'SKU-42',
            'name': 'Bulk Water',
            'quantity': 2,
            'pack_size': 12,
            'unit_price': 50.0,
            'line_total': 100.0,
          },
        ],
        'timeline': [
          {'stage': 'placed', 'occurred_at': '2026-10-04T10:00:00+00:00'},
          {
            'stage': 'failed',
            'occurred_at': '2026-10-04T10:20:00+00:00',
            'reason_code': 'customer_no_answer',
          },
        ],
      });

      await tester.pumpWidget(
        FoodexCustomerApp(
          session: b2b,
          initialRoute: '/b2b/orders/78',
          b2bApi: api,
          locale: const Locale('en'),
        ),
      );
      await _pumpCustomerFrames(tester);

      expect(api.lastPath, '/api/v1/b2b/orders/78');
      expect(find.byKey(const ValueKey('b2b-order-detail')), findsOneWidget);
      expect(find.text('Order details'), findsOneWidget);
      expect(find.text('#78'), findsOneWidget);
      expect(find.text('Wholesale Store'), findsNothing);
      expect(find.text('2026-10-05'), findsNothing);
      expect(find.text('Wholesale B2B'), findsNothing);
      expect(find.byKey(const ValueKey('b2b-order-terminal')), findsOneWidget);
      expect(
        Directionality.of(
          tester.element(find.byKey(const ValueKey('b2b-order-detail'))),
        ),
        TextDirection.ltr,
      );
      expect(find.text('تفاصيل الطلب'), findsNothing);
      expect(find.text('Paid'), findsOneWidget);

      final scrollable = find.byKey(const ValueKey('b2b-order-detail'));

      await _scrollUntilBuilt(
        tester,
        scrollable,
        find.byKey(const ValueKey('b2b-order-open-map')),
      );
      expect(find.byKey(const ValueKey('b2b-order-open-map')), findsOneWidget);

      await _scrollUntilBuilt(
        tester,
        scrollable,
        find.text('Customer did not answer'),
      );
      expect(find.text('Customer did not answer'), findsOneWidget);

      await _scrollUntilBuilt(tester, scrollable, find.text('VAN-ONE'));
      expect(find.text('VAN-ONE'), findsWidgets);
      expect(find.textContaining('Driver One'), findsNothing);

      await _scrollUntilBuilt(
        tester,
        scrollable,
        find.byKey(const ValueKey('b2b-order-line-1')),
      );
      expect(
        find.byKey(const ValueKey('b2b-order-line-1')),
        findsOneWidget,
      );
      expect(find.text('Bulk Water'), findsNothing);
      expect(find.text('SKU-42'), findsNothing);
      expect(find.text('Quantity: 2'), findsOneWidget);
      expect(
        find.descendant(
          of: find.byKey(const ValueKey('b2b-order-line-1')),
          matching: find.textContaining('100'),
        ),
        findsOneWidget,
      );

      await _scrollUntilBuilt(tester, scrollable, find.text('Price summary'));
      expect(find.text('Price summary'), findsOneWidget);

      await _scrollUntilBuilt(tester, scrollable, find.text('Account credit'));
      expect(find.text('Account credit'), findsOneWidget);

      await _scrollUntilBuilt(
        tester,
        scrollable,
        find.byKey(const ValueKey('b2b-order-open-invoice')),
      );
      expect(
        find.byKey(const ValueKey('b2b-order-open-invoice')),
        findsOneWidget,
      );

      await _scrollUntilBuilt(tester, scrollable, find.text('Leave at gate'));
      expect(find.text('Leave at gate'), findsOneWidget);
    },
  );

  testWidgets(
    'legacy channel-scoped session cannot enter B2B protected journey',
    (tester) async {
      await tester.pumpWidget(
        const FoodexCustomerApp(
          session: CustomerSession.authenticated(CustomerChannel.b2c),
          initialRoute: '/b2b/invoices',
        ),
      );
      await _pumpCustomerFrames(tester);

      expect(
        find.byKey(const ValueKey('unified-customer-auth-screen')),
        findsNothing,
      );
      expect(find.text('دخول عميل الأعمال'), findsNothing);
      expect(find.text('الفواتير'), findsNothing);
    },
  );

  testWidgets(
    'B2B top products expose current catalog state, sort and product navigation',
    (tester) async {
      final api = _FakeB2bApi({
        'data': [
          {
            'rank': 1,
            'product_id': 42,
            'store_id': 7,
            'sku': 'TOP-1',
            'name': 'Top Product',
            'quantity': 12,
            'total': 144.5,
            'currency': 'KWD',
            'last_purchased_at': '2026-09-30T11:30:00Z',
            'account_price': 7.25,
            'current_price_currency': 'EGP',
            'minimum_order_quantity': 5,
            'ordering_increment': 5,
            'pack_size': 12,
            'pack_label': 'Case 12',
            'available_quantity': 20,
            'availability_state': 'AVAILABLE',
            'can_repurchase': true,
            'unavailable_reason': null,
          },
        ],
        'period': {'from': '2026-09-01', 'to': '2026-09-30'},
        'sort': 'quantity',
        'meta': {'page': 1, 'per_page': 20, 'total': 1, 'has_more': false},
      });
      await tester.pumpWidget(
        FoodexCustomerApp(
          session: b2b,
          initialRoute:
              '/b2b/products/top?store_id=7&from=2026-09-01&to=2026-09-30',
          b2bApi: api,
        ),
      );
      await _pumpCustomerFrames(tester);

      expect(
        api.lastPath,
        '/api/v1/b2b/products/top?store_id=7&from=2026-09-01&to=2026-09-30',
      );
      expect(
        find.byKey(const ValueKey('b2b-top-products-filters')),
        findsOneWidget,
      );
      expect(
        find.byKey(const ValueKey('b2b-top-products-data')),
        findsOneWidget,
      );
      expect(find.textContaining('Top Product'), findsOneWidget);
      expect(find.textContaining('144.5 KWD'), findsOneWidget);
      expect(find.textContaining('7.25 EGP'), findsOneWidget);
      expect(find.text('فترة الترتيب'), findsNothing);
      expect(find.text('كل القنوات'), findsOneWidget);
      expect(
        find.byKey(const ValueKey('b2b-top-product-availability-1')),
        findsOneWidget,
      );
      final currentTab = tester.getRect(
        find.byKey(const ValueKey('b2b-top-products-current-period')),
      );
      final allTab = tester.getRect(
        find.byKey(const ValueKey('b2b-top-products-all-time')),
      );
      final previousTab = tester.getRect(
        find.byKey(const ValueKey('b2b-top-products-previous-period')),
      );
      expect((currentTab.center.dy - allTab.center.dy).abs(), lessThan(1));
      expect((allTab.center.dy - previousTab.center.dy).abs(), lessThan(1));

      await tester.tap(find.byKey(const ValueKey('b2b-top-products-sort')));
      await _pumpCustomerFrames(tester);
      await tester.tap(find.text('قيمة المشتريات').last);
      await _pumpCustomerFrames(tester);

      final sortedUri = Uri.parse(api.lastPath!);
      expect(sortedUri.path, '/api/v1/b2b/products/top');
      expect(sortedUri.queryParameters['store_id'], '7');
      expect(sortedUri.queryParameters['from'], '2026-09-01');
      expect(sortedUri.queryParameters['to'], '2026-09-30');
      expect(sortedUri.queryParameters['sort'], 'value');
      expect(sortedUri.queryParameters['page'], '1');
      expect(sortedUri.queryParameters['per_page'], '20');

      final openProduct = find.byKey(const ValueKey('b2b-top-product-open-1'));
      await tester.ensureVisible(openProduct);
      await _pumpCustomerFrames(tester);
      await tester.tap(openProduct);
      await _pumpCustomerFrames(tester);

      expect(api.lastPath, '/api/v1/b2b/products/42?store_id=7');
    },
  );

  testWidgets('C13 Screen 4 period presets match purchases report semantics', (
    tester,
  ) async {
    final api = _FakeB2bApi({
      'data': [
        {
          'rank': 1,
          'product_id': 42,
          'store_id': 7,
          'sku': 'TOP-1',
          'name': 'Top Product',
          'quantity': 12,
          'total': 144.5,
          'currency': 'KWD',
          'last_purchased_at': '2026-09-30T11:30:00Z',
          'account_price': 7.25,
          'current_price_currency': 'KWD',
          'pack_label': 'Case 12',
          'can_repurchase': true,
          'unavailable_reason': null,
        },
      ],
      'meta': {'page': 1, 'per_page': 20, 'total': 1, 'has_more': false},
    });

    await tester.pumpWidget(
      FoodexCustomerApp(
        session: b2b,
        initialRoute: '/b2b/products/top?store_id=7',
        b2bApi: api,
      ),
    );
    await _pumpCustomerFrames(tester);

    expect(
      find.byKey(const ValueKey('b2b-top-products-current-period')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('b2b-top-products-previous-period')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('b2b-top-product-rank-1')),
      findsOneWidget,
    );

    await tester.tap(
      find.byKey(const ValueKey('b2b-top-products-current-period')),
    );
    await _pumpCustomerFrames(tester);

    var uri = Uri.parse(api.lastPath!);
    expect(uri.queryParameters['store_id'], '7');
    expect(uri.queryParameters['from'], isNotNull);
    expect(uri.queryParameters['to'], isNotNull);
    expect(uri.queryParameters['page'], '1');

    await tester.tap(
      find.byKey(const ValueKey('b2b-top-products-previous-period')),
    );
    await _pumpCustomerFrames(tester);

    uri = Uri.parse(api.lastPath!);
    expect(uri.queryParameters['from'], isNotNull);
    expect(uri.queryParameters['to'], isNotNull);
    expect(DateTime.parse(uri.queryParameters['from']!).day, 1);

    await tester.tap(find.byKey(const ValueKey('b2b-top-products-all-time')));
    await _pumpCustomerFrames(tester);

    uri = Uri.parse(api.lastPath!);
    expect(uri.queryParameters.containsKey('from'), isFalse);
    expect(uri.queryParameters.containsKey('to'), isFalse);
    expect(uri.queryParameters['store_id'], '7');
  });

  testWidgets(
    'B2B top products visibly disable repurchase when authoritative stock is empty',
    (tester) async {
      final api = _FakeB2bApi({
        'data': [
          {
            'rank': 1,
            'product_id': 42,
            'store_id': 7,
            'sku': 'TOP-1',
            'name': 'Top Product',
            'quantity': 12,
            'total': 144.5,
            'currency': 'KWD',
            'account_price': 7.25,
            'current_price_currency': 'EGP',
            'available_quantity': 0,
            'availability_state': 'OUT_OF_STOCK',
            'can_repurchase': false,
            'unavailable_reason': 'OUT_OF_STOCK',
          },
        ],
        'meta': {'page': 1, 'per_page': 20, 'total': 1, 'has_more': false},
      });

      await tester.pumpWidget(
        FoodexCustomerApp(
          session: b2b,
          initialRoute: '/b2b/products/top?store_id=7',
          b2bApi: api,
        ),
      );
      await _pumpCustomerFrames(tester);

      expect(find.text('غير متاح حالياً — نفد المخزون'), findsOneWidget);
      expect(find.textContaining('7.25 EGP'), findsOneWidget);
      expect(
        find.byKey(const ValueKey('b2b-top-product-open-1')),
        findsOneWidget,
      );
    },
  );

  testWidgets(
    'C13 Screen 3 keeps compact report header then categories trend and orders',
    (tester) async {
      tester.view.physicalSize = const Size(320, 800);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);

      final api = _FakeB2bApi({
        'currency': 'KWD',
        'period': {'from': null, 'to': null},
        'summary': {
          'total_purchases': 150.0,
          'order_count': 2,
          'invoice_count': 2,
          'average_order_value': 75.0,
        },
        'comparison': {
          'from': '2026-08-01',
          'to': '2026-08-31',
          'total_purchases': 100.0,
          'order_count': 1,
          'change_amount': 50.0,
          'change_percent': 50.0,
        },
        'data': [
          {'period': '2026-09-01', 'orders_count': 1, 'purchase_total': 50.0},
          {'period': '2026-09-02', 'orders_count': 1, 'purchase_total': 100.0},
        ],
        'categories': [
          {
            'category_id': 1,
            'category_name': 'Rice',
            'purchase_total': 90.0,
            'percentage': 60.0,
          },
          {
            'category_id': 2,
            'category_name': 'Oil',
            'purchase_total': 60.0,
            'percentage': 40.0,
          },
        ],
        'orders': [
          {
            'id': 77,
            'order_number': 'B2B-77',
            'store_id': 7,
            'status': 'confirmed',
            'currency': 'KWD',
            'grand_total': 100.0,
            'created_at': '2026-09-02T10:00:00Z',
          },
        ],
        'meta': {'page': 1, 'per_page': 5, 'total': 1, 'has_more': false},
        'generated_at': '2026-09-02T10:00:00Z',
      });

      await tester.pumpWidget(
        FoodexCustomerApp(
          session: b2b,
          initialRoute: '/b2b/reports/purchases?store_id=7',
          b2bApi: api,
        ),
      );
      await _pumpCustomerFrames(tester);

      expect(
        find.byKey(const ValueKey('b2b-purchases-filters')),
        findsOneWidget,
      );
      expect(
        find.byKey(const ValueKey('b2b-purchases-date-actions-row')),
        findsOneWidget,
      );
      final fromRect = tester.getRect(
        find.byKey(const ValueKey('b2b-purchases-from')),
      );
      final toRect = tester.getRect(
        find.byKey(const ValueKey('b2b-purchases-to')),
      );
      final refreshRect = tester.getRect(
        find.byKey(const ValueKey('b2b-purchases-refresh')),
      );
      expect((fromRect.center.dy - toRect.center.dy).abs(), lessThan(1));
      expect((toRect.center.dy - refreshRect.center.dy).abs(), lessThan(1));
      expect(
        find.byKey(const ValueKey('b2b-purchases-summary')),
        findsOneWidget,
      );
      for (final metric in <String>['total', 'orders', 'invoices', 'average']) {
        expect(
          find.byKey(ValueKey('b2b-purchases-summary-$metric')),
          findsOneWidget,
        );
      }

      final metricTops = <String>['total', 'orders', 'invoices', 'average']
          .map(
            (metric) => tester
                .getTopLeft(
                  find.byKey(ValueKey('b2b-purchases-summary-$metric')),
                )
                .dy,
          )
          .toList(growable: false);
      expect(
        metricTops.every((value) => (value - metricTops.first).abs() < 1),
        isTrue,
      );

      expect(
        find.byKey(const ValueKey('b2b-purchases-categories')),
        findsOneWidget,
      );
      expect(find.byKey(const ValueKey('b2b-purchases-trend')), findsOneWidget);
      expect(
          find.byKey(const ValueKey('b2b-purchases-orders')), findsOneWidget);

      final categoriesTop = tester
          .getTopLeft(find.byKey(const ValueKey('b2b-purchases-categories')))
          .dy;
      final trendTop = tester
          .getTopLeft(find.byKey(const ValueKey('b2b-purchases-trend')))
          .dy;
      final ordersTop = tester
          .getTopLeft(find.byKey(const ValueKey('b2b-purchases-orders')))
          .dy;
      expect(categoriesTop, lessThan(trendTop));
      expect(trendTop, lessThan(ordersTop));
      expect(
        find.byKey(const ValueKey('b2b-purchases-category-bars')),
        findsOneWidget,
      );
      expect(find.byType(FoodexBarChart), findsNWidgets(2));
      expect(find.byType(FoodexDonutChart), findsNothing);
      expect(
        find.descendant(
          of: find.byType(FoodexChartLegend),
          matching: find.text('Rice'),
        ),
        findsOneWidget,
      );
      expect(
        find.descendant(
          of: find.byType(FoodexChartLegend),
          matching: find.text('Oil'),
        ),
        findsOneWidget,
      );
      expect(find.text('#77'), findsOneWidget);

      final distributionTitle = tester.widget<Text>(
        find.text('توزيع المشتريات حسب التصنيف'),
      );
      expect(distributionTitle.style?.fontSize, lessThanOrEqualTo(12));

      final reportOrderNumber = tester.widget<Text>(
        find.text('#77'),
      );
      expect(reportOrderNumber.style?.fontSize, lessThanOrEqualTo(11));

      expect(api.lastPath, contains('store_id=7'));

      await tester.tap(
        find.byKey(const ValueKey('b2b-purchases-current-period')),
      );
      await _pumpCustomerFrames(tester);

      final currentUri = Uri.parse(api.lastPath!);
      expect(currentUri.queryParameters['store_id'], '7');
      expect(currentUri.queryParameters['from'], isNotNull);
      expect(currentUri.queryParameters['to'], isNotNull);
      expect(currentUri.queryParameters['page'], '1');
      expect(currentUri.queryParameters['per_page'], '5');
    },
  );

  testWidgets('B2B remote routes visibly render authoritative payload fields', (
    tester,
  ) async {
    final cases = <({String route, Object? payload, String expected})>[
      (
        route: '/b2b/dashboard',
        payload: {
          'customer': {'name': 'Acme Buyer'},
          'account': {'company_name': 'Acme Foods'},
          'finance': {
            'currency': 'KWD',
            'balance': 88.5,
            'balance_direction': 'customer_owes_company',
            'credit_limit': 500.0,
            'available_credit_line': 411.5,
            'open_amount': 88.5,
            'overdue_amount': 0.0,
          },
          'operations': {
            'purchases_this_month': 321.75,
            'payments_this_month': 50.0,
            'invoice_count': 4,
            'order_count': 3,
            'active_orders': 1,
          },
          'freshness': {
            'generated_at': '2026-10-04T14:00:00+00:00',
            'stale': false,
          },
        },
        expected: '321.75',
      ),
      (
        route: '/b2b/reports/purchases',
        payload: {
          'currency': 'KWD',
          'period': {'from': '2026-09-01', 'to': '2026-09-30'},
          'summary': {
            'total_purchases': 48.0,
            'order_count': 1,
            'invoice_count': 1,
            'average_order_value': 48.0,
          },
          'comparison': null,
          'data': [
            {'period': '2026-09-01', 'orders_count': 1, 'purchase_total': 48.0},
          ],
          'categories': [
            {
              'category_name': 'Bulk Rice',
              'purchase_total': 48.0,
              'percentage': 100.0,
            },
          ],
          'orders': <Object?>[],
          'meta': {'page': 1, 'per_page': 5, 'total': 0, 'has_more': false},
        },
        expected: 'Bulk Rice',
      ),
      (
        route: '/b2b/invoices',
        payload: {
          'data': [
            {
              'id': 31,
              'invoice_number': 'INV-31',
              'payment_status': 'paid',
              'total': 55.25,
              'currency': 'KWD',
            },
          ],
        },
        expected: 'INV-31',
      ),
      (
        route: '/b2b/account-statement',
        payload: {'balance': 19.75, 'currency': 'KWD', 'data': <Object?>[]},
        expected: '19.75',
      ),
      (
        route: '/b2b/orders',
        payload: {
          'data': [
            {
              'id': 77,
              'order_number': 'B2B-77',
              'status': 'processing',
              'grand_total': 101.0,
            },
          ],
        },
        expected: '#77',
      ),
      (
        route: '/b2b/cart?store=7',
        payload: {
          'store_id': 7,
          'subtotal': 36.25,
          'items': [
            {
              'product_id': 42,
              'name': 'Wholesale Product',
              'quantity': 5,
              'unit_price': 7.25,
            },
          ],
        },
        expected: 'Wholesale Product',
      ),
    ];

    for (final item in cases) {
      final api = _FakeB2bApi(item.payload);
      await tester.pumpWidget(
        FoodexCustomerApp(
          key: ValueKey('b2b-route-${item.route}'),
          session: b2b,
          initialRoute: item.route,
          b2bApi: api,
        ),
      );
      await _pumpCustomerFrames(tester);

      expect(
        find.byKey(const ValueKey('b2b-loaded')),
        findsNothing,
        reason: item.route,
      );
      expect(
        find.textContaining(item.expected),
        findsWidgets,
        reason: item.route,
      );
      expect(
        Directionality.of(
          tester.element(find.textContaining(item.expected).first),
        ),
        TextDirection.rtl,
        reason: item.route,
      );
    }
  });

  testWidgets(
    'C13 Screen 6 reconciles statement totals, direction and references',
    (tester) async {
      final api = _FakeB2bApi({
        'data': {
          'currency': 'EGP',
          'opening_balance': 10.0,
          'period_debits': 60.0,
          'period_credits': 20.0,
          'closing_balance': 50.0,
          'balance': 50.0,
          'transactions': [
            {
              'id': 'invoice:31',
              'type': 'invoice',
              'reference': 'INV-31',
              'description': 'Invoice INV-31',
              'debit': 60.0,
              'credit': 0.0,
              'running_balance': 70.0,
              'currency': 'EGP',
              'invoice_id': 31,
              'order_id': 77,
              'occurred_at': '2026-10-01T10:00:00Z',
            },
            {
              'id': 'payment:9',
              'type': 'payment',
              'reference': 'PAY-9',
              'description': 'Payment',
              'debit': 0.0,
              'credit': 20.0,
              'running_balance': 50.0,
              'currency': 'EGP',
              'invoice_id': 31,
              'occurred_at': '2026-10-02T10:00:00Z',
            },
          ],
          'pagination': {
            'current_page': 1,
            'per_page': 30,
            'total': 2,
            'last_page': 1,
            'has_more': false,
          },
        },
      });

      await tester.pumpWidget(
        FoodexCustomerApp(
          session: b2b,
          initialRoute: '/b2b/account-statement?store_id=7',
          b2bApi: api,
        ),
      );
      await _pumpCustomerFrames(tester);

      expect(find.byKey(const ValueKey('b2b-statement-data')), findsOneWidget);
      expect(
        find.byKey(const ValueKey('b2b-statement-summary-opening')),
        findsNothing,
      );
      expect(
        find.byKey(const ValueKey('b2b-statement-summary-current')),
        findsNothing,
      );
      expect(
        find.byKey(const ValueKey('b2b-statement-summary-closing')),
        findsOneWidget,
      );
      expect(
        find.byKey(const ValueKey('b2b-statement-direction-chip')),
        findsOneWidget,
      );
      expect(
        find.byKey(const ValueKey('b2b-statement-current-direction')),
        findsOneWidget,
      );
      expect(find.textContaining('50.00 EGP'), findsWidgets);
      expect(find.textContaining('ليك'), findsWidgets);
      expect(
        find.byKey(const ValueKey('b2b-statement-export-pdf')),
        findsOneWidget,
      );
      expect(
        find.byKey(const ValueKey('b2b-statement-export-xlsx')),
        findsOneWidget,
      );
      expect(find.text('تحميل كشف الحساب'), findsOneWidget);
      final pdfTop = tester
          .getTopLeft(
            find.byKey(const ValueKey('b2b-statement-export-pdf')),
          )
          .dy;
      final excelTop = tester
          .getTopLeft(
            find.byKey(const ValueKey('b2b-statement-export-xlsx')),
          )
          .dy;
      expect((pdfTop - excelTop).abs(), lessThan(1));
      expect(
        find.byKey(const ValueKey('b2b-statement-running-invoice:31')),
        findsOneWidget,
      );

      final invoiceCard = find.byKey(
        const ValueKey('b2b-statement-row-invoice:31'),
      );
      final invoiceTitle = tester.widget<Text>(
        find.descendant(of: invoiceCard, matching: find.text('فاتورة')),
      );
      expect(invoiceTitle.style?.color, CustomerUiColors.warning);

      final referenceButton = find.byKey(
        const ValueKey('b2b-statement-reference-invoice:31'),
      );
      final referenceText = tester.widget<Text>(
        find.descendant(
          of: referenceButton,
          matching: find.textContaining('INV-31'),
        ),
      );
      expect(referenceText.style?.color, CustomerUiColors.deepGreenStrong);

      final runningText = tester.widget<Text>(
        find.byKey(const ValueKey('b2b-statement-running-invoice:31')),
      );
      expect(runningText.style?.color, CustomerUiColors.success);

      expect(api.lastPath, contains('/api/v1/b2b/account-statement'));
      expect(api.lastPath, contains('store_id=7'));

      final invoiceReference = find.byKey(
        const ValueKey('b2b-statement-reference-invoice:31'),
      );
      await tester.ensureVisible(invoiceReference);
      await _pumpCustomerFrames(tester);
      await tester.tap(invoiceReference);
      await _pumpCustomerFrames(tester);
      expect(api.lastPath, '/api/v1/b2b/invoices/31');
    },
  );

  testWidgets('C13 Screen 6 quick period preset reloads authoritative query', (
    tester,
  ) async {
    final api = _FakeB2bApi({
      'data': {
        'currency': 'EGP',
        'opening_balance': 0,
        'period_debits': 0,
        'period_credits': 0,
        'closing_balance': 0,
        'balance': 0,
        'transactions': <Object?>[],
        'pagination': {
          'current_page': 1,
          'per_page': 30,
          'total': 0,
          'last_page': 1,
          'has_more': false,
        },
      },
    });

    await tester.pumpWidget(
      FoodexCustomerApp(
        session: b2b,
        initialRoute: '/b2b/account-statement?store_id=7',
        b2bApi: api,
      ),
    );
    await _pumpCustomerFrames(tester);
    await tester.tap(find.byKey(const ValueKey('b2b-statement-preset-30')));
    await _pumpCustomerFrames(tester);

    expect(api.lastPath, contains('from='));
    expect(api.lastPath, contains('to='));
    expect(api.lastPath, contains('page=1'));
    expect(api.lastPath, contains('per_page=30'));
  });

  testWidgets(
    '#1072 invoices use one-line header and compact responsive filters',
    (tester) async {
      tester.view.physicalSize = const Size(390, 844);
      tester.view.devicePixelRatio = 1;
      addTearDown(() {
        tester.view.resetPhysicalSize();
        tester.view.resetDevicePixelRatio();
      });

      final api = _FakeB2bApi(const {
        'data': <Object?>[],
        'summary': {'totals': <Object?>[]},
        'meta': {'page': 1, 'per_page': 20, 'total': 0, 'has_more': false},
      });

      await tester.pumpWidget(
        FoodexCustomerApp(
          session: b2b,
          initialRoute: '/b2b/invoices',
          b2bApi: api,
        ),
      );
      await _pumpCustomerFrames(tester);

      expect(find.byKey(const ValueKey('b2b-invoices-screen')), findsOneWidget);
      expect(
        find.byKey(const ValueKey('foodex-wholesale-shared-header')),
        findsOneWidget,
      );
      expect(
        find.byKey(const ValueKey('b2b-invoices-header-row')),
        findsNothing,
      );
      expect(find.byKey(const ValueKey('customer-route-label')), findsNothing);

      final filtersRect = tester.getRect(
        find.byKey(const ValueKey('b2b-invoices-filters')),
      );
      expect(filtersRect.height, lessThanOrEqualTo(844 * .20));

      expect(find.byKey(const ValueKey('b2b-invoices-search')), findsOneWidget);
      expect(find.byKey(const ValueKey('b2b-invoices-status')), findsOneWidget);
      expect(find.byKey(const ValueKey('b2b-invoices-from')), findsOneWidget);
      expect(find.byKey(const ValueKey('b2b-invoices-to')), findsOneWidget);
      expect(
        find.byKey(const ValueKey('b2b-invoices-clear-filters')),
        findsOneWidget,
      );
      expect(
        find.byKey(const ValueKey('b2b-invoices-refresh')),
        findsOneWidget,
      );
      expect(
        find.byKey(const ValueKey('b2b-invoices-empty-icon')),
        findsOneWidget,
      );
      expect(
        find.text('لا توجد فواتير مطابقة للفلاتر الحالية.'),
        findsOneWidget,
      );

      final callsBeforeRefresh = api.calls;
      await tester.tap(find.byKey(const ValueKey('b2b-invoices-refresh')));
      await _pumpCustomerFrames(tester);
      expect(api.calls, callsBeforeRefresh + 1);
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets('B2B collections expose approved detail navigation', (
    tester,
  ) async {
    final api = _FakeB2bApi({
      'data': [
        {
          'id': 31,
          'invoice_number': 'INV-31',
          'display_status': 'partially_paid',
          'currency': 'KWD',
          'total': 20.0,
          'paid_amount': 5.0,
          'outstanding_amount': 15.0,
          'credit_amount': 0.0,
          'store_id': 7,
          'issued_at': '2026-10-01T10:00:00Z',
          'pdf_path': '/api/v1/invoices/31/download?channel=b2b&store_id=7',
        },
      ],
      'summary': {
        'invoice_count': 1,
        'totals': [
          {
            'currency': 'KWD',
            'total': 20.0,
            'paid': 5.0,
            'outstanding': 15.0,
            'overdue': 0.0,
            'credit': 0.0,
          },
        ],
      },
      'meta': {'page': 1, 'per_page': 20, 'total': 1, 'has_more': false},
    });
    await tester.pumpWidget(
      FoodexCustomerApp(
        session: b2b,
        initialRoute: '/b2b/invoices',
        b2bApi: api,
      ),
    );
    await _pumpCustomerFrames(tester);

    expect(find.byKey(const ValueKey('b2b-invoices-search')), findsOneWidget);
    expect(find.byKey(const ValueKey('b2b-invoices-status')), findsOneWidget);
    expect(find.byKey(const ValueKey('b2b-invoices-from')), findsOneWidget);
    expect(find.byKey(const ValueKey('b2b-invoices-to')), findsOneWidget);
    expect(find.byKey(const ValueKey('b2b-invoice-pdf-31')), findsOneWidget);
    expect(find.textContaining('20.000 KWD'), findsWidgets);
    expect(find.textContaining('5.000 KWD'), findsWidgets);
    expect(find.textContaining('15.000 KWD'), findsWidgets);

    final paidText = tester.widget<Text>(
      find.byKey(const ValueKey('b2b-invoice-paid-31')),
    );
    final paidSpans = (paidText.textSpan as TextSpan).children!;
    expect((paidSpans.last as TextSpan).style?.color, CustomerUiColors.success);

    final outstandingText = tester.widget<Text>(
      find.byKey(const ValueKey('b2b-invoice-outstanding-31')),
    );
    final outstandingSpans = (outstandingText.textSpan as TextSpan).children!;
    expect(
      (outstandingSpans.last as TextSpan).style?.color,
      CustomerUiColors.destructive,
    );

    expect(find.text('#31'), findsNothing);
    expect(find.text('رقم الفاتورة INV-31'), findsOneWidget);

    final statusRect = tester.getRect(
      find.byKey(const ValueKey('b2b-invoice-status-31')),
    );
    final pdfRect = tester.getRect(
      find.byKey(const ValueKey('b2b-invoice-pdf-31')),
    );
    final detailsRect = tester.getRect(
      find.byKey(const ValueKey('b2b-invoice-open-31')),
    );
    expect(pdfRect.top, greaterThan(statusRect.bottom - 1));
    expect((pdfRect.center.dy - detailsRect.center.dy).abs(), lessThan(1));

    final invoiceHeader = tester.widget<Text>(
      find.byKey(const ValueKey('b2b-invoice-number-31')),
    );
    expect(invoiceHeader.style?.fontSize, lessThanOrEqualTo(10));

    await tester.tap(find.text('رقم الفاتورة INV-31'));
    await _pumpCustomerFrames(tester);

    expect(find.text('/b2b/invoices/31'), findsNothing);
    expect(find.byKey(const ValueKey('customer-route-location')), findsNothing);
    expect(api.lastPath, '/api/v1/b2b/invoices/31?store_id=7');
  });

  testWidgets(
    'C13 Screen 9 renders reconciled invoice detail and visible actions',
    (tester) async {
      tester.view.physicalSize = const Size(900, 2400);
      tester.view.devicePixelRatio = 1;
      addTearDown(() {
        tester.view.resetPhysicalSize();
        tester.view.resetDevicePixelRatio();
      });

      final api = _FakeB2bApi({
        'data': {
          'id': 31,
          'invoice_number': 'INV-31',
          'display_status': 'partially_paid',
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
          'seller': {
            'store_id': 7,
            'name': 'FOODEX Wholesale',
            'logo_url': null,
          },
          'customer': {
            'name': 'Buyer Co',
            'email': 'buyer@example.test',
            'phone': '55510000',
          },
          'pdf_path': '/api/v1/invoices/31/download?channel=b2b&store_id=7',
          'items': [
            {
              'id': 501,
              'sku': 'WHO-501',
              'description': 'Wholesale item',
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
      });

      await tester.pumpWidget(
        FoodexCustomerApp(
          session: b2b,
          initialRoute: '/b2b/invoices/31?store_id=7',
          b2bApi: api,
        ),
      );
      await _pumpCustomerFrames(tester);

      expect(api.lastPath, '/api/v1/b2b/invoices/31?store_id=7');
      expect(
        find.byKey(const ValueKey('b2b-invoice-detail-data')),
        findsOneWidget,
      );
      expect(find.byKey(const ValueKey('b2b-invoice-hero')), findsOneWidget);
      expect(find.byKey(const ValueKey('b2b-invoice-brand')), findsOneWidget);
      expect(
        find.byKey(const ValueKey('b2b-invoice-status-chip')),
        findsOneWidget,
      );
      expect(
        find.byKey(const ValueKey('b2b-invoice-settlement-strip')),
        findsOneWidget,
      );
      expect(find.text('#31'), findsNothing);
      expect(find.text('تفاصيل الفاتورة'), findsNothing);
      expect(
        find.byKey(const ValueKey('b2b-invoice-detail-number')),
        findsOneWidget,
      );
      final invoiceNumber = tester.widget<Text>(
        find.byKey(const ValueKey('b2b-invoice-detail-number')),
      );
      expect(invoiceNumber.textSpan?.toPlainText(), 'رقم الفاتورة INV-31');
      expect(
        find.byKey(const ValueKey('b2b-invoice-seller')),
        findsNothing,
      );
      expect(find.textContaining('FOODEX Wholesale'), findsNothing);
      expect(find.textContaining('Buyer Co'), findsOneWidget);
      expect(
        find.byKey(const ValueKey('b2b-invoice-detail-totals')),
        findsOneWidget,
      );
      expect(find.textContaining('22.000 KWD'), findsWidgets);
      expect(find.textContaining('5.000 KWD'), findsWidgets);
      expect(find.textContaining('17.000 KWD'), findsWidgets);
      expect(
        find.byKey(const ValueKey('b2b-invoice-item-501')),
        findsOneWidget,
      );
      expect(find.text('Wholesale item'), findsNothing);
      expect(find.textContaining('WHO-501'), findsNothing);
      expect(find.textContaining('الكمية'), findsOneWidget);
      expect(find.text('الضريبة'), findsNothing);
      expect(find.text('المديونية'), findsWidgets);
      expect(
        find.byKey(const ValueKey('b2b-invoice-payments')),
        findsOneWidget,
      );
      expect(find.textContaining('PAY-31'), findsOneWidget);
      expect(find.byKey(const ValueKey('b2b-invoice-ledger')), findsOneWidget);
      expect(find.textContaining('CN-31'), findsOneWidget);
      expect(
        find.byKey(const ValueKey('b2b-invoice-detail-pdf')),
        findsOneWidget,
      );
      expect(
        find.descendant(
          of: find.byKey(const ValueKey('b2b-invoice-detail-pdf')),
          matching: find.textContaining('PDF'),
        ),
        findsOneWidget,
      );
      expect(
        find.byKey(const ValueKey('b2b-invoice-related-order')),
        findsNothing,
      );
      expect(find.text('الطلب المرتبط'), findsNothing);
      final pdfRect = tester.getRect(
        find.byKey(const ValueKey('b2b-invoice-detail-pdf')),
      );
      expect(pdfRect.width, greaterThan(0));
      expect(
        find.byKey(const ValueKey('b2b-invoice-detail-refresh')),
        findsNothing,
      );

      expect(find.textContaining('/api/v1/invoices/31/download'), findsNothing);
    },
  );

  testWidgets('B2B remote journey renders loading and empty states', (
    tester,
  ) async {
    final api = _FakeB2bApi(const {'data': []});
    await tester.pumpWidget(
      FoodexCustomerApp(
        session: b2b,
        initialRoute: '/b2b/invoices',
        b2bApi: api,
      ),
    );
    expect(find.byKey(const ValueKey('b2b-loading')), findsOneWidget);
    await _pumpCustomerFrames(tester);
    expect(find.byKey(const ValueKey('b2b-empty')), findsOneWidget);
    expect(api.lastPath, '/api/v1/b2b/invoices');
  });

  testWidgets('B2B remote journey classifies network/client failure safely', (
    tester,
  ) async {
    await CustomerDiagnostics.instance.clear();
    await tester.pumpWidget(
      FoodexCustomerApp(
        session: b2b,
        initialRoute: '/b2b/account-statement',
        b2bApi: _FailingB2bApi(),
      ),
    );
    await _pumpCustomerFrames(tester);

    expect(find.byKey(const ValueKey('b2b-error')), findsOneWidget);
    expect(find.byKey(const ValueKey('b2b-error-retry')), findsOneWidget);

    final failure = CustomerDiagnostics.instance.events.lastWhere(
      (event) => event['type'] == 'runtime_failure',
    );
    final details = Map<String, dynamic>.from(failure['details'] as Map);
    expect(details['category'], 'network_or_client_failure');
    expect(details.containsKey('status_code'), isFalse);
  });

  testWidgets(
    'B2B product transient failure exposes support reference and authoritative retry',
    (tester) async {
      await CustomerDiagnostics.instance.clear();
      final api = _RetryB2bApi();

      await tester.pumpWidget(
        FoodexCustomerApp(
          session: b2b,
          initialRoute: '/b2b/products/42?store_id=7',
          b2bApi: api,
          actionApi: _FakeCustomerActionApi(),
        ),
      );
      await _pumpCustomerFrames(tester);

      expect(find.byKey(const ValueKey('b2b-error')), findsOneWidget);
      expect(find.byKey(const ValueKey('b2b-error-retry')), findsOneWidget);
      expect(
        find.byKey(const ValueKey('b2b-error-back-products')),
        findsOneWidget,
      );
      expect(find.textContaining('req-product-503'), findsOneWidget);
      expect(find.textContaining('/b2b/products/42'), findsNothing);

      final failure = CustomerDiagnostics.instance.events.lastWhere(
        (event) => event['type'] == 'runtime_failure',
      );
      final details = Map<String, dynamic>.from(failure['details'] as Map);
      expect(details['category'], 'server_failure');
      expect(details['status_code'], 503);
      expect(details['support_reference'], 'req-product-503');

      await tester.tap(find.byKey(const ValueKey('b2b-error-retry')));
      await _pumpCustomerFrames(tester);

      expect(api.calls, 2);
      expect(
        find.byKey(const ValueKey('b2b-product-detail-data')),
        findsOneWidget,
      );
      expect(find.text('Recovered Product'), findsOneWidget);
    },
  );

  testWidgets('B2B More header stays usable at 320px with 1.3 text scaling', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(320, 760);
    tester.view.devicePixelRatio = 1;
    tester.platformDispatcher.textScaleFactorTestValue = 1.3;
    addTearDown(() {
      tester.view.resetPhysicalSize();
      tester.view.resetDevicePixelRatio();
      tester.platformDispatcher.clearTextScaleFactorTestValue();
    });

    final api = _FakeB2bApi(const {
      'company_name': 'FOODEX Wholesale Business Account With Long Name',
      'name': 'Business Buyer',
      'currency': 'KWD',
      'balance': 12.5,
      'open_amount': 12.5,
      'overdue_amount': 0,
      'credit_limit': 100,
      'available_credit_line': 87.5,
      'items_count': 2,
      'unread_count': 3,
      'data': <Object?>[],
      'items': <Object?>[],
    });

    await tester.pumpWidget(
      FoodexCustomerApp(
        session: b2b,
        initialRoute: '/b2b/profile?store_id=7',
        b2bApi: api,
      ),
    );
    await _pumpCustomerFrames(tester);

    final header = find.byKey(const ValueKey('foodex-wholesale-shared-header'));
    expect(header, findsOneWidget);
    expect(find.byKey(const ValueKey('b2b-more-back')), findsNothing);
    expect(find.text('حساب الأعمال'), findsNothing);
    expect(find.text('كل أدوات حسابك في مكان واحد'), findsNothing);
    expect(
      find.byKey(const ValueKey('b2b-more-business-dashboard')),
      findsOneWidget,
    );

    final headerRect = tester.getRect(header);
    expect(headerRect.left, greaterThanOrEqualTo(0));
    expect(headerRect.right, lessThanOrEqualTo(320));
    expect(tester.takeException(), isNull);
  });

  testWidgets('B2B runtime diagnostics classify 401 403 404 and 5xx', (
    tester,
  ) async {
    const cases = <({int status, String code, String category})>[
      (status: 401, code: 'not_authorized', category: 'unauthorized'),
      (status: 403, code: 'not_authorized', category: 'forbidden'),
      (status: 404, code: 'http_404', category: 'not_found'),
      (status: 500, code: 'http_500', category: 'server_failure'),
    ];

    for (final item in cases) {
      await CustomerDiagnostics.instance.clear();
      await tester.pumpWidget(
        FoodexCustomerApp(
          key: ValueKey('b2b-failure-${item.status}'),
          session: b2b,
          initialRoute: '/b2b/products/42?store_id=7',
          b2bApi: _StatusFailingB2bApi(status: item.status, code: item.code),
          actionApi: _FakeCustomerActionApi(),
        ),
      );
      await _pumpCustomerFrames(tester);

      final failure = CustomerDiagnostics.instance.events.lastWhere(
        (event) => event['type'] == 'runtime_failure',
      );
      final details = Map<String, dynamic>.from(failure['details'] as Map);
      expect(details['category'], item.category, reason: '${item.status}');
      expect(details['status_code'], item.status, reason: '${item.status}');
      expect(
        details['support_reference'],
        'req-${item.status}',
        reason: '${item.status}',
      );
      expect(find.byKey(const ValueKey('b2b-error-retry')), findsOneWidget);
    }
  });
}

class _DelayedQuantityWholesaleCommerceApi implements WholesaleCommerceApi {
  _DelayedQuantityWholesaleCommerceApi({required this.cartValue});

  final Map<String, dynamic> cartValue;
  final Completer<void> _firstUpdate = Completer<void>();
  final List<double> updateQuantities = <double>[];
  int cartCalls = 0;

  void releaseFirstUpdate() {
    if (!_firstUpdate.isCompleted) {
      _firstUpdate.complete();
    }
  }

  Map<String, dynamic> _snapshot() {
    final snapshot = Map<String, dynamic>.from(cartValue);
    final items = cartValue['items'];
    if (items is List) {
      snapshot['items'] = items
          .map((raw) => raw is Map ? Map<String, dynamic>.from(raw) : raw)
          .toList(growable: false);
    }
    return snapshot;
  }

  @override
  Future<Object?> cart(int storeId) async {
    cartCalls += 1;
    return _snapshot();
  }

  @override
  Future<Object?> addItem(int storeId, int productId, double quantity) async =>
      null;

  @override
  Future<Object?> updateItem(int itemId, double quantity) async {
    updateQuantities.add(quantity);
    if (updateQuantities.length == 1) {
      await _firstUpdate.future;
    }

    final items = cartValue['items'];
    if (items is List) {
      for (final raw in items) {
        if (raw is Map && raw['id'] == itemId) {
          raw['quantity'] = quantity;
          final unitPrice = raw['unit_price_snapshot'];
          if (unitPrice is num) {
            raw['line_total'] = unitPrice.toDouble() * quantity;
          }
        }
      }
    }

    return _snapshot();
  }

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
      <String, Object?>{};
}

class _CheckoutJourneyWholesaleCommerceApi implements WholesaleCommerceApi {
  int checkoutCalls = 0;

  @override
  Future<Object?> cart(int storeId) async => {
        'store_id': storeId,
        'currency': 'KWD',
        'subtotal': 7.25,
        'has_unavailable_items': false,
        'quote': {
          'discount_total': 0.0,
          'delivery_total': 0.75,
          'tax_total': 0.0,
          'grand_total': 8.0,
        },
        'items': [
          {
            'id': 707,
            'product': {
              'id': 42,
              'name': 'FOODEX Wholesale Water',
              'sku': 'B2B-E2E-42',
            },
            'quantity': 1.0,
            'unit_price_snapshot': 7.25,
            'line_total': 7.25,
            'is_available': true,
            'available_quantity': 24.0,
            'minimum_order_quantity': 1.0,
            'ordering_increment': 1.0,
          },
        ],
      };

  @override
  Future<Object?> addItem(int storeId, int productId, double quantity) async =>
      cart(storeId);

  @override
  Future<Object?> updateItem(int itemId, double quantity) async => null;

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
  }) async {
    checkoutCalls++;
    return {
      'id': 707,
      'order_number': 'FDX-E2E-707',
      'display_order_number': '#707',
      'store_id': storeId,
      'currency': 'KWD',
      'subtotal': 7.25,
      'discount_total': 0.0,
      'delivery_total': 0.75,
      'tax_total': 0.0,
      'grand_total': 8.0,
      'payment_method': paymentMethod,
      'status': 'pending',
    };
  }
}

class _C13CheckoutStorefrontApi implements StorefrontApi {
  const _C13CheckoutStorefrontApi(this.options);

  final Map<String, dynamic> options;

  @override
  Future<Map<String, dynamic>> selection({
    String? countryCode,
    String? city,
    String? area,
    bool support = false,
  }) async =>
      const {};

  @override
  Future<Map<String, dynamic>> retailHome(int storeId) async => const {};

  @override
  Future<Map<String, dynamic>> wholesaleHome(int storeId) async => const {};

  @override
  Future<Map<String, dynamic>> b2bCheckoutOptions(int storeId) async =>
      Map<String, dynamic>.from(options);
}

class _C13WholesaleCommerceApi implements WholesaleCommerceApi {
  _C13WholesaleCommerceApi({required this.cartValue, this.checkoutCompleter});

  final Map<String, dynamic> cartValue;
  final Completer<Object?>? checkoutCompleter;
  int removeCalls = 0;
  int updateCalls = 0;
  int checkoutCalls = 0;
  final Set<int> removedItemIds = <int>{};
  final List<String> checkoutKeys = <String>[];

  @override
  Future<Object?> cart(int storeId) async {
    final snapshot = Map<String, dynamic>.from(cartValue);
    final items = cartValue['items'];
    if (items is List) {
      snapshot['items'] = items
          .where((raw) => raw is! Map || !removedItemIds.contains(raw['id']))
          .toList(growable: false);
    }
    return snapshot;
  }

  @override
  Future<Object?> addItem(int storeId, int productId, double quantity) async =>
      null;

  @override
  Future<Object?> updateItem(int itemId, double quantity) async {
    updateCalls += 1;
    final items = (cartValue['items'] as List? ?? <Object>[]);
    for (final raw in items) {
      if (raw is Map && raw['id'] == itemId) {
        raw['quantity'] = quantity;
      }
    }
    return cartValue;
  }

  @override
  Future<void> removeItem(int itemId) async {
    removeCalls += 1;
    removedItemIds.add(itemId);
  }

  @override
  Future<Object?> checkout({
    required int storeId,
    required int addressId,
    required String paymentMethod,
    String? requestedDeliveryDate,
    String? note,
    String? couponCode,
    required String idempotencyKey,
  }) async {
    checkoutCalls += 1;
    checkoutKeys.add(idempotencyKey);
    if (checkoutCompleter != null) {
      return checkoutCompleter!.future;
    }
    return <String, Object?>{};
  }
}

class _CheckoutStorefrontApi implements StorefrontApi {
  const _CheckoutStorefrontApi();

  @override
  Future<Map<String, dynamic>> selection({
    String? countryCode,
    String? city,
    String? area,
    bool support = false,
  }) async =>
      const {};

  @override
  Future<Map<String, dynamic>> retailHome(int storeId) async => const {};

  @override
  Future<Map<String, dynamic>> wholesaleHome(int storeId) async => const {};

  @override
  Future<Map<String, dynamic>> b2bCheckoutOptions(int storeId) async => const {
        'addresses': [
          {'id': 1, 'label': 'المخزن', 'line1': 'شارع 1'},
        ],
        'delivery_dates': ['2026-09-30'],
        'payment_methods': ['cash_on_delivery'],
      };
}

class _ReorderWholesaleCommerceApi implements WholesaleCommerceApi {
  int cartCalls = 0;
  final List<String> addCalls = <String>[];

  @override
  Future<Object?> cart(int storeId) async {
    cartCalls++;
    return {
      'store_id': storeId,
      'currency': 'KWD',
      'subtotal': 10.0,
      'grand_total': 10.0,
      'items': [
        {
          'id': 900,
          'product': {'id': 900, 'name': 'Existing Cart Item', 'sku': 'EX-900'},
          'quantity': 1.0,
          'unit_price_snapshot': 10.0,
          'line_total': 10.0,
          'is_available': true,
          'available_quantity': 10.0,
          'minimum_order_quantity': 1.0,
          'ordering_increment': 1.0,
        },
      ],
    };
  }

  @override
  Future<Object?> addItem(int storeId, int productId, double quantity) async {
    addCalls.add('$storeId:$productId:$quantity');
    return null;
  }

  @override
  Future<Object?> updateItem(int itemId, double quantity) async => null;

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
      const <String, Object?>{};
}

class _FakeWholesaleCommerceApi implements WholesaleCommerceApi {
  const _FakeWholesaleCommerceApi(this.checkoutResult);

  final Object? checkoutResult;

  @override
  Future<Object?> cart(int storeId) async => const {
        'currency': 'KWD',
        'subtotal': 25.5,
        'grand_total': 25.5,
        'items': [
          {'name': 'Bulk Water', 'quantity': 2},
        ],
      };

  @override
  Future<Object?> addItem(int storeId, int productId, double quantity) async =>
      null;

  @override
  Future<Object?> updateItem(int itemId, double quantity) async => null;

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
      checkoutResult;
}

class _WholesaleFavoritesApi implements B2cRetailFavoritesApi {
  _WholesaleFavoritesApi(this.value);

  final Object? value;
  int? lastStoreId;
  final List<int> removed = <int>[];

  @override
  Future<Object?> favoritesForStore(int storeId) async {
    lastStoreId = storeId;
    return value;
  }

  @override
  Future<void> addFavoriteForStore(int storeId, int productId) async {
    lastStoreId = storeId;
  }

  @override
  Future<void> removeFavoriteForStore(int storeId, int productId) async {
    lastStoreId = storeId;
    removed.add(productId);
  }
}

class _FakeWholesaleStorefrontApi implements StorefrontApi {
  int? lastStoreId;
  int selectionCalls = 0;

  @override
  Future<Map<String, dynamic>> selection({
    String? countryCode,
    String? city,
    String? area,
    bool support = false,
  }) async {
    selectionCalls += 1;
    return const {
      'retail_stores': <Object>[],
      'wholesale_stores': [
        {
          'id': 70,
          'name': 'Principal Wholesale',
          'is_platform_principal': true,
        },
      ],
      'entitlements': {'direct_b2b': true, 'principal_wholesale_store_id': 70},
    };
  }

  @override
  Future<Map<String, dynamic>> retailHome(int storeId) async => const {};

  @override
  Future<Map<String, dynamic>> wholesaleHome(int storeId) async {
    lastStoreId = storeId;
    return {
      'store': {'id': storeId, 'name': 'Wholesale'},
      'theme': {
        'code': 'wholesale_b2b',
        'primary': '#712FA5',
        'primary_dark': '#35195E',
        'accent': '#D6A7FA',
        'background': '#F7F1FC',
      },
      'branding': {
        'address': 'القاهرة والإسكندرية',
        'custom': {
          'brand_title_ar': 'فودكس جملة المخصص',
          'brand_subtitle_ar': 'أسعار مخصصة من لوحة التحكم',
          'hero_cta_ar': 'ابدأ طلب الجملة',
        },
      },
      'products': [
        {
          'id': 42,
          'sku': 'PUBLIC-42',
          'name': 'Public Bulk Water',
          'price': 55,
          'currency': 'EGP',
          'category_id': 3,
        },
      ],
      'sections': [
        {'key': 'hero', 'type': 'hero', 'sort_order': 10},
        {
          'key': 'categories',
          'type': 'categories',
          'title_ar': 'تصنيفات الجملة',
          'sort_order': 20,
        },
        {
          'key': 'offers',
          'type': 'offers',
          'title_ar': 'عروض الحساب',
          'sort_order': 30,
        },
      ],
    };
  }

  @override
  Future<Map<String, dynamic>> b2bCheckoutOptions(int storeId) async =>
      const {};
}

class _FakeB2bApi implements B2bApi {
  _FakeB2bApi(this.value);
  final Object? value;
  String? lastPath;
  final List<String> paths = <String>[];
  int calls = 0;

  @override
  Future<Object?> get(String path) async {
    calls++;
    lastPath = path;
    paths.add(path);
    return value;
  }
}

class _PathB2bApi implements B2bApi {
  _PathB2bApi(this.responses);

  final Map<String, Object?> responses;
  String? lastPath;

  @override
  Future<Object?> get(String path) async {
    lastPath = path;
    return responses[path];
  }
}

class _SequenceB2bApi implements B2bApi {
  _SequenceB2bApi(this.values);

  final List<Object?> values;
  int calls = 0;

  @override
  Future<Object?> get(String path) async {
    final index = calls < values.length ? calls : values.length - 1;
    calls++;
    return values[index];
  }
}

class _FailingB2bApi implements B2bApi {
  @override
  Future<Object?> get(String path) async => throw const B2bApiException('test');
}

class _StaticB2bApi implements B2bApi {
  const _StaticB2bApi();
  @override
  Future<Object?> get(String path) async => null;
}

class _RetryB2bApi implements B2bApi {
  int calls = 0;

  @override
  Future<Object?> get(String path) async {
    calls++;
    if (calls == 1) {
      throw const B2bApiException(
        'http_503',
        statusCode: 503,
        supportReference: 'req-product-503',
      );
    }
    return const {
      'id': 42,
      'sku': 'REC-42',
      'name': 'Recovered Product',
      'store_id': 7,
      'account_price': 7.25,
      'minimum_order_quantity': 5,
      'available_quantity': 24,
      'currency': 'KWD',
    };
  }
}

class _StatusFailingB2bApi implements B2bApi {
  const _StatusFailingB2bApi({required this.status, required this.code});

  final int status;
  final String code;

  @override
  Future<Object?> get(String path) async {
    throw B2bApiException(
      code,
      statusCode: status,
      supportReference: 'req-$status',
    );
  }
}

class _CountingCustomerActionApi implements CustomerActionApi {
  int addCalls = 0;
  int? lastStoreId;
  int? lastProductId;
  double? lastQuantity;

  @override
  Future<CustomerLoginResult> login({required String username}) async =>
      const CustomerLoginResult(
        token: 'platform-token',
        platformCustomer: true,
      );

  @override
  Future<void> logout() async {}

  @override
  Future<Object?> addCartItem({
    required int storeId,
    required int productId,
    required double quantity,
  }) async {
    addCalls++;
    lastStoreId = storeId;
    lastProductId = productId;
    lastQuantity = quantity;
    return {'store_id': storeId, 'product_id': productId, 'quantity': quantity};
  }

  @override
  Future<Object?> checkout({
    required int addressId,
    int? storeId,
    String? paymentMethod,
    String? couponCode,
    required String idempotencyKey,
  }) async =>
      null;
}

class _MemoryPendingActionStore implements CustomerPendingActionStore {
  CustomerPendingAction? value;
  int writeCount = 0;
  int takeCount = 0;

  @override
  Future<void> clear() async {
    value = null;
  }

  @override
  Future<CustomerPendingAction?> read() async => value;

  @override
  Future<CustomerPendingAction?> take() async {
    takeCount++;
    final current = value;
    value = null;
    return current;
  }

  @override
  Future<void> write(CustomerPendingAction action) async {
    writeCount++;
    value = action;
  }
}

class _FakeCustomerActionApi implements CustomerActionApi {
  int? lastStoreId;
  int? lastProductId;

  @override
  Future<CustomerLoginResult> login({required String username}) async =>
      const CustomerLoginResult(token: 'test-token');

  @override
  Future<void> logout() async {}

  @override
  Future<Object?> addCartItem({
    required int storeId,
    required int productId,
    required double quantity,
  }) async {
    lastStoreId = storeId;
    lastProductId = productId;
    return {'store_id': storeId, 'product_id': productId, 'quantity': quantity};
  }

  @override
  Future<Object?> checkout({
    required int addressId,
    int? storeId,
    String? paymentMethod,
    String? couponCode,
    required String idempotencyKey,
  }) async =>
      {'id': 1};
}
