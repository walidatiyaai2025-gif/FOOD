import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/app.dart';
import 'package:foodex_customer_app/core/api/b2b_api.dart';
import 'package:foodex_customer_app/core/api/customer_action_api.dart';
import 'package:foodex_customer_app/core/api/storefront_api.dart';
import 'package:foodex_customer_app/core/api/wholesale_commerce_api.dart';
import 'package:foodex_customer_app/core/auth/customer_session.dart';
import 'package:foodex_customer_app/core/diagnostics/customer_diagnostics.dart';
import 'package:foodex_customer_app/core/routing/customer_pending_action.dart';

Future<void> _scrollUntilBuilt(
  WidgetTester tester,
  Finder scrollable,
  Finder target,
) async {
  for (var attempt = 0;
      attempt < 12 && target.evaluate().isEmpty;
      attempt++) {
    await tester.drag(scrollable, const Offset(0, -350));
    await tester.pumpAndSettle();
  }
}

void main() {
  const b2b = CustomerSession.authenticated(CustomerChannel.b2b);

  testWidgets('B2B unauthenticated protected route hides internal redirect details',
      (tester) async {
    await tester.pumpWidget(
      const FoodexCustomerApp(initialRoute: '/b2b/dashboard'),
    );
    await tester.pumpAndSettle();

    expect(find.text('تسجيل دخول العميل'), findsWidgets);
    expect(find.text('دخول عميل الأعمال'), findsNothing);
    expect(
      find.byKey(const ValueKey('unified-auth-submit')),
      findsOneWidget,
    );
    expect(find.textContaining('/b2b/login'), findsNothing);
    expect(find.textContaining('/auth/checkout'), findsNothing);
    expect(find.textContaining('next='), findsNothing);
    expect(
      find.byKey(const ValueKey('customer-route-location')),
      findsNothing,
    );
  });

  testWidgets('B2B approved customer dashboard is RTL and exposes finance areas', (tester) async {
    await tester.pumpWidget(const FoodexCustomerApp(session: b2b, initialRoute: '/b2b/dashboard'));
    await tester.pumpAndSettle();
    expect(Directionality.of(tester.element(find.text('لوحة الأعمال'))), TextDirection.rtl);
    expect(find.text('المشتريات'), findsOneWidget);
    expect(find.text('الفواتير'), findsWidgets);
    expect(find.text('كشف الحساب'), findsOneWidget);
  });

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
      'customer': {
        'id': 9,
        'name': 'Buyer',
        'email': 'buyer@example.test',
      },
      'account': {
        'id': 3,
        'company_name': 'Buyer Co',
        'status': 'active',
      },
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
    await tester.pumpAndSettle();

    expect(api.lastPath, '/api/v1/b2b/dashboard?store_id=7');
    expect(api.calls, 1);
    expect(find.byKey(const ValueKey('b2b-dashboard-data')), findsOneWidget);
    expect(
      find.byKey(const ValueKey('b2b-dashboard-hero')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('b2b-dashboard-balance-hero')),
      findsNothing,
    );
    expect(
      find.byKey(const ValueKey('b2b-dashboard-refresh')),
      findsNothing,
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
    expect(find.text('حد الائتمان'), findsOneWidget);
    expect(find.text('الائتمان المتاح'), findsOneWidget);
    expect(find.text('الفواتير المفتوحة'), findsOneWidget);
    expect(find.text('المبلغ المتأخر'), findsOneWidget);
    expect(find.text('مشتريات هذا الشهر'), findsOneWidget);
    expect(find.text('مدفوعات هذا الشهر'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('b2b-dashboard-hero')));
    await tester.pumpAndSettle();
    expect(api.calls, 2);
  });

  testWidgets('B2B orders refresh automatically when app resumes',
      (tester) async {
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
      FoodexCustomerApp(
        session: b2b,
        initialRoute: '/b2b/orders',
        b2bApi: api,
      ),
    );
    await tester.pumpAndSettle();

    expect(api.calls, 1);
    expect(find.byKey(const ValueKey('b2b-orders-data')), findsOneWidget);

    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();

    expect(api.calls, 2);
    expect(find.byKey(const ValueKey('b2b-orders-data')), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets(
      'B2B dashboard Store icon resolves principal Wholesale and opens canonical home',
      (tester) async {
    final storefront = _FakeWholesaleStorefrontApi();
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
        storefrontApi: storefront,
        actionApi: _FakeCustomerActionApi(),
      ),
    );
    await tester.pumpAndSettle();

    final shopping =
        find.byKey(const ValueKey('b2b-dashboard-shopping'));
    expect(shopping, findsOneWidget);

    await tester.tap(shopping);
    await tester.pumpAndSettle();

    expect(storefront.selectionCalls, 1);
    expect(storefront.lastStoreId, 70);
    expect(api.calls, 1);
  });

  testWidgets('B2B dashboard shows credit direction in English LTR',
      (tester) async {
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
    await tester.pumpAndSettle();

    expect(
      Directionality.of(tester.element(find.text('FOODEX Business'))),
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
    expect(find.text('Available credit'), findsOneWidget);
    expect(find.text('Active orders'), findsOneWidget);
  });

  testWidgets('B2B profile is the complete visible account and finance hub',
      (tester) async {
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
        initialRoute:
            '/b2b/profile?channel=wholesale&store_id=7',
        b2bApi: api,
      ),
    );
    await tester.pumpAndSettle();

    expect(
      find.byKey(const ValueKey('b2b-profile-friendly-data')),
      findsOneWidget,
    );
    expect(find.text('Acme Foods'), findsWidgets);
    expect(find.text('TAX-872'), findsOneWidget);
    expect(find.text('نشط'), findsOneWidget);
    expect(find.byKey(const ValueKey('b2b-account-hub')), findsOneWidget);
    expect(find.text('عليك'), findsOneWidget);
    expect(find.text('125.000 KWD'), findsOneWidget);
    expect(find.text('1000.000 KWD'), findsOneWidget);
    expect(find.text('875.000 KWD'), findsWidgets);
    expect(find.text('طلباتي'), findsWidgets);
    expect(find.text('الفواتير'), findsWidgets);
    expect(find.text('كشف الحساب'), findsWidgets);
    expect(find.text('تقرير المشتريات'), findsOneWidget);
    expect(find.text('الإشعارات'), findsOneWidget);
    expect(find.text('العناوين'), findsWidgets);
    expect(find.text('العربية'), findsOneWidget);
    expect(find.text('English'), findsOneWidget);
    expect(find.text('المساعدة والدعم'), findsOneWidget);
    expect(
      find.byKey(const ValueKey('b2b-profile-security-action')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('b2b-profile-logout')),
      findsOneWidget,
    );
    expect(find.text('company_name'), findsNothing);
  });

  testWidgets('B2B storefront keeps server branding and enforces green theme on mobile', (tester) async {
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
    await tester.pumpAndSettle();

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
  });

  testWidgets(
      'Wholesale header shows Business dashboard shortcut only for signed-in B2B users',
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
    await tester.pumpAndSettle();

    final shortcut = find.byKey(
      const ValueKey('wholesale-business-dashboard-button'),
    );
    expect(shortcut, findsOneWidget);

    await tester.tap(shortcut);
    await tester.pumpAndSettle();

    expect(
      find.byKey(const ValueKey('b2b-dashboard-notifications')),
      findsOneWidget,
    );
  });

  testWidgets('Wholesale header hides Business dashboard shortcut for guests',
      (tester) async {
    await tester.pumpWidget(
      FoodexCustomerApp(
        initialRoute: '/b2b/home?channel=wholesale&store_id=70',
        storefrontApi: _FakeWholesaleStorefrontApi(),
        actionApi: _FakeCustomerActionApi(),
      ),
    );
    await tester.pumpAndSettle();

    expect(
      find.byKey(const ValueKey('wholesale-business-dashboard-button')),
      findsNothing,
    );
  });

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
    await tester.pumpAndSettle();

    final openCatalog = find.byKey(
      const ValueKey('wholesale-home-open-catalog'),
    );
    expect(openCatalog, findsOneWidget);
    await tester.ensureVisible(openCatalog);
    await tester.tap(openCatalog);
    await tester.pumpAndSettle();

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
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('wholesale-catalog-list-view')),
      findsOneWidget,
    );

    await tester.tap(
      find.byKey(const ValueKey('wholesale-catalog-list-view')),
    );
    await tester.pump();
    var grid = tester.widget<GridView>(
      find.byKey(const ValueKey('wholesale-product-grid')),
    );
    var delegate =
        grid.gridDelegate as SliverGridDelegateWithFixedCrossAxisCount;
    expect(delegate.crossAxisCount, 1);

    await tester.tap(
      find.byKey(const ValueKey('wholesale-catalog-grid-view')),
    );
    await tester.pump();
    grid = tester.widget<GridView>(
      find.byKey(const ValueKey('wholesale-product-grid')),
    );
    delegate =
        grid.gridDelegate as SliverGridDelegateWithFixedCrossAxisCount;
    expect(delegate.crossAxisCount, 2);

    expect(api.lastPath, '/api/v1/b2b/products?store_id=70');
    expect(storefront.lastStoreId, 70);
  });

  testWidgets('guest can browse Wholesale storefront and product without login',
      (tester) async {
    final storefront = _FakeWholesaleStorefrontApi();

    await tester.pumpWidget(
      FoodexCustomerApp(
        initialRoute: '/b2b/home?channel=wholesale&store_id=70',
        storefrontApi: storefront,
        actionApi: _FakeCustomerActionApi(),
      ),
    );
    await tester.pumpAndSettle();

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
    await tester.pumpAndSettle();
    expect(find.text('Public Bulk Water'), findsWidgets);

    await tester.tap(find.text('Public Bulk Water').first);
    await tester.pumpAndSettle();

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
  });

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
        initialRoute:
            '/b2b/products/42?channel=wholesale&store_id=7',
        b2bApi: b2bApi,
        storefrontApi: _FakeWholesaleStorefrontApi(),
        actionApi: actionApi,
        pendingActionStore: pending,
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('Wholesale Product'), findsOneWidget);
    expect(actionApi.addCalls, 0);

    await tester.tap(find.byKey(const ValueKey('customer-add-cart')));
    await tester.pumpAndSettle();

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
    await tester.pumpAndSettle();

    expect(pending.takeCount, 1);
    expect(actionApi.addCalls, 1);
    expect(actionApi.lastStoreId, 7);
    expect(actionApi.lastProductId, 42);
    expect(actionApi.lastQuantity, 5);
    expect(find.text('سلة الجملة'), findsOneWidget);

    await tester.pumpAndSettle();
    expect(actionApi.addCalls, 1);
  });

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
    await tester.pumpAndSettle();

    expect(api.lastPath, '/api/v1/b2b/products?store_id=7');
    expect(find.text('Filter'), findsOneWidget);
    expect(find.text('Default order'), findsOneWidget);
    expect(find.text('Wholesale Water'), findsOneWidget);
    expect(find.text('Beverages'), findsOneWidget);
    expect(find.textContaining('72.50 EGP'), findsOneWidget);
    expect(find.textContaining('Case 12'), findsOneWidget);
    expect(find.textContaining('Step 5'), findsOneWidget);
    expect(find.textContaining('Available quantity: 30'), findsOneWidget);

    final availableAdd = find.byKey(
      const ValueKey('wholesale-product-add-42'),
    );
    final unavailableAdd = find.byKey(
      const ValueKey('wholesale-product-add-43'),
    );
    expect(availableAdd, findsOneWidget);
    expect(unavailableAdd, findsOneWidget);
    expect(tester.widget<FilledButton>(unavailableAdd).onPressed, isNull);

    await tester.tap(availableAdd);
    await tester.pumpAndSettle();

    expect(actionApi.addCalls, 1);
    expect(actionApi.lastStoreId, 7);
    expect(actionApi.lastProductId, 42);
    expect(actionApi.lastQuantity, 5);
    expect(find.text('Product added to cart'), findsOneWidget);
  });

  testWidgets('B2B product details render authoritative account pricing and inventory', (tester) async {
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
    await tester.pumpAndSettle();

    expect(api.lastPath, '/api/v1/b2b/products/42?store_id=7');
    expect(find.byKey(const ValueKey('b2b-product-detail-data')), findsOneWidget);
    expect(find.byKey(const ValueKey('b2b-product-gallery')), findsOneWidget);
    expect(find.byKey(const ValueKey('b2b-product-detail-header')), findsOneWidget);
    expect(find.byKey(const ValueKey('b2b-product-detail-hero')), findsOneWidget);
    expect(find.byKey(const ValueKey('b2b-product-price')), findsOneWidget);
    expect(find.byKey(const ValueKey('b2b-product-commerce-strip')), findsOneWidget);
    expect(find.byKey(const ValueKey('b2b-product-meta')), findsOneWidget);
    expect(
      find.byKey(const ValueKey('b2b-product-description-section')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('b2b-product-additional-info')),
      findsOneWidget,
    );
    expect(find.byType(PageView), findsOneWidget);
    expect(find.text('Wholesale Product'), findsOneWidget);
    expect(find.textContaining('7.25 KWD'), findsOneWidget);
    expect(find.textContaining('5'), findsWidgets);
    expect(find.textContaining('24'), findsWidgets);
    expect(find.text('إضافة إلى السلة'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('customer-add-cart')));
    await tester.pumpAndSettle();

    expect(actionApi.lastStoreId, 7);
    expect(actionApi.lastProductId, 42);
    expect(find.text('سلة الجملة'), findsOneWidget);
    expect(api.lastPath, '/api/v1/cart?store=7');
  });

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
    await tester.pumpAndSettle();

    expect(find.text('Brand: FOODEX'), findsOneWidget);
    expect(find.text('Category: Beverages'), findsOneWidget);
    expect(find.textContaining('7.25 EGP'), findsOneWidget);

    final descriptionDisclosure = find.text('Description');
    expect(descriptionDisclosure, findsOneWidget);
    await tester.ensureVisible(descriptionDisclosure);
    await tester.tap(descriptionDisclosure);
    await tester.pumpAndSettle();
    expect(find.text('Fresh authoritative description'), findsOneWidget);

    await tester.ensureVisible(
      find.byKey(const ValueKey('customer-add-cart')),
    );
    await tester.tap(find.byKey(const ValueKey('customer-add-cart')));
    await tester.pumpAndSettle();

    expect(api.calls, 2);
    expect(actionApi.addCalls, 0);
    expect(
      find.byKey(const ValueKey('b2b-product-terms-updated')),
      findsOneWidget,
    );
    expect(
      find.textContaining('Price or stock changed'),
      findsOneWidget,
    );
    expect(find.textContaining('8.00 EGP'), findsOneWidget);
    expect(find.textContaining('Minimum order 10'), findsOneWidget);
  });

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
    await tester.pumpAndSettle();

    expect(find.text('Out of stock'), findsWidgets);
    expect(
      find.byKey(const ValueKey('b2b-product-stock-auto-refresh')),
      findsOneWidget,
    );
    expect(api.calls, 1);

    await tester.pump(const Duration(seconds: 15));
    await tester.pumpAndSettle();

    expect(api.calls, 2);
    expect(
      find.byKey(const ValueKey('b2b-product-stock-auto-refresh')),
      findsNothing,
    );
    expect(
      find.textContaining('Available quantity 6'),
      findsOneWidget,
    );

    final quantityCta = find.byKey(const ValueKey('customer-add-cart'));
    final plus = find.descendant(
      of: quantityCta,
      matching: find.byIcon(Icons.add_rounded),
    );
    expect(plus, findsOneWidget);
    await tester.tap(plus);
    await tester.pump();

    expect(find.textContaining('Maximum available 6'), findsOneWidget);
    expect(find.text('5'), findsWidgets);
  });

  testWidgets('B2B cart exposes authoritative checkout action', (tester) async {
    await tester.pumpWidget(const FoodexCustomerApp(session: b2b, initialRoute: '/b2b/cart', b2bApi: _StaticB2bApi()));
    await tester.pumpAndSettle();
    expect(find.text('سلة الجملة'), findsOneWidget);
    expect(find.text('إتمام الطلب'), findsOneWidget);
  });

  testWidgets(
      'B2B checkout with order id navigates to B2B details and uses B2B API',
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
        wholesaleCommerceApi: const _FakeWholesaleCommerceApi({'id': 4}),
      ),
    );
    await tester.pumpAndSettle();

    await tester.scrollUntilVisible(
      find.text('تأكيد الطلب'),
      300,
      scrollable: find.byType(Scrollable).first,
    );
    await tester.tap(find.text('تأكيد الطلب'));
    await tester.pumpAndSettle();

    expect(api.lastPath, '/api/v1/b2b/orders/4');
    expect(find.byKey(const ValueKey('b2b-order-detail')), findsOneWidget);
    expect(find.text('FDX-B2B-4'), findsOneWidget);
    expect(find.text('Wholesale Store'), findsOneWidget);
    await _scrollUntilBuilt(
      tester,
      find.byKey(const ValueKey('b2b-order-detail')),
      find.text('الدفع عند الاستلام'),
    );
    expect(find.text('الدفع عند الاستلام'), findsOneWidget);
    expect(find.text('/orders/4/track'), findsNothing);
  });

  testWidgets('B2B checkout without usable order id falls back to B2B orders',
      (tester) async {
    final api = _FakeB2bApi(const {'data': <Object>[]});

    await tester.pumpWidget(
      FoodexCustomerApp(
        session: b2b,
        initialRoute: '/b2b/checkout?store=7',
        b2bApi: api,
        storefrontApi: const _CheckoutStorefrontApi(),
        wholesaleCommerceApi:
            const _FakeWholesaleCommerceApi(<String, Object?>{}),
      ),
    );
    await tester.pumpAndSettle();

    await tester.scrollUntilVisible(
      find.text('تأكيد الطلب'),
      300,
      scrollable: find.byType(Scrollable).first,
    );
    await tester.tap(find.text('تأكيد الطلب'));
    await tester.pumpAndSettle();

    expect(api.lastPath, '/api/v1/b2b/orders');
    expect(find.byKey(const ValueKey('b2b-empty')), findsOneWidget);
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
    await tester.pumpAndSettle();

    expect(find.text('Wholesale cart'), findsOneWidget);
    expect(find.text('Bulk Water'), findsOneWidget);
    expect(find.textContaining('Unit price'), findsWidgets);
    expect(find.textContaining('Line total'), findsWidgets);
    expect(find.textContaining('Available: 8'), findsOneWidget);
    expect(find.text('Discounts'), findsOneWidget);
    expect(find.text('Delivery'), findsOneWidget);
    expect(find.text('Tax'), findsOneWidget);
    expect(find.byKey(const ValueKey('b2b-cart-clear')), findsOneWidget);
    expect(
      find.byKey(const ValueKey('b2b-cart-remove-Bulk Water')),
      findsOneWidget,
    );

    await tester.tap(
      find.byKey(const ValueKey('b2b-cart-remove-Bulk Water')),
    );
    await tester.pumpAndSettle();
    expect(commerce.removeCalls, 1);
    expect(find.text('Bulk Water'), findsNothing);
    expect(find.text('Bulk Juice'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('b2b-cart-clear')));
    await tester.pumpAndSettle();
    expect(commerce.removeCalls, 2);
    expect(find.text('The cart is empty'), findsOneWidget);
  });

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
        {
          'id': 1,
          'label': 'Warehouse',
          'line1': 'Street 1',
          'city': 'Cairo',
        },
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
    await tester.pumpAndSettle();

    expect(find.text('Current financial position'), findsOneWidget);
    expect(find.text('Available purchasing power'), findsOneWidget);
    expect(find.text('Final review'), findsOneWidget);
    expect(find.text('Acme Buyer'), findsOneWidget);
    expect(find.text('Wholesale Store'), findsOneWidget);
    expect(
      find.byKey(const ValueKey('b2b-checkout-insufficient-credit')),
      findsOneWidget,
    );
    final submit = tester.widget<FilledButton>(
      find.byKey(const ValueKey('b2b-checkout-submit')),
    );
    expect(submit.onPressed, isNull);
  });

  testWidgets('C13 checkout prevents double submit with one stable attempt key',
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
    await tester.pumpAndSettle();

    final submitFinder = find.byKey(const ValueKey('b2b-checkout-submit'));
    await tester.ensureVisible(submitFinder);
    await tester.tap(submitFinder);
    await tester.pump();

    expect(commerce.checkoutCalls, 1);
    expect(commerce.checkoutKeys, hasLength(1));
    expect(commerce.checkoutKeys.single.length, greaterThanOrEqualTo(16));
    expect(
      tester.widget<FilledButton>(submitFinder).onPressed,
      isNull,
    );

    // A second physical tap cannot create a second checkout while the first
    // authoritative request is still unresolved.
    await tester.tap(submitFinder, warnIfMissed: false);
    await tester.pump();
    expect(commerce.checkoutCalls, 1);

    completer.complete(<String, Object?>{});
    await tester.pumpAndSettle();
  });

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
    await tester.pumpAndSettle();

    expect(api.lastPath, '/api/v1/b2b/orders/77');
    expect(find.byKey(const ValueKey('b2b-order-detail')), findsOneWidget);
    expect(find.text('B2B-77'), findsOneWidget);
  });


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
          {
            'stage': 'placed',
            'occurred_at': '2026-10-01T20:00:00+00:00',
          },
          {
            'stage': 'ready',
            'occurred_at': '2026-10-01T20:10:00+00:00',
          },
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
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('b2b-orders-data')), findsOneWidget);
    expect(find.byKey(const ValueKey('b2b-order-row-91')), findsOneWidget);
    expect(find.text('B2B-91'), findsOneWidget);
    expect(find.text('FOODEX Wholesale'), findsOneWidget);
    expect(find.text('جاهز'), findsOneWidget);
    expect(find.text('42.5 KWD'), findsOneWidget);

    await tester.tap(find.text('B2B-91'));
    await tester.pumpAndSettle();

    expect(api.lastPath, '/api/v1/b2b/orders/91');
    expect(find.byKey(const ValueKey('b2b-order-detail')), findsOneWidget);
    expect(find.byKey(const ValueKey('b2b-timeline-0-placed')), findsOneWidget);
    expect(find.byKey(const ValueKey('b2b-timeline-1-ready')), findsOneWidget);
  });

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
          {
            'stage': 'placed',
            'occurred_at': '2026-10-01T20:00:00+00:00',
          },
          {
            'stage': 'ready',
            'occurred_at': '2026-10-01T20:10:00+00:00',
          },
          {
            'stage': 'driver_assigned',
            'occurred_at': '2026-10-01T20:12:00+00:00',
            'driver_name': 'Ahmed Driver',
          },
          {
            'stage': 'out_for_delivery',
            'occurred_at': '2026-10-01T20:20:00+00:00',
            'driver_name': 'Ahmed Driver',
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
          {
            'stage': 'placed',
            'occurred_at': '2026-10-01T20:00:00+00:00',
          },
          {
            'stage': 'delivered',
            'occurred_at': '2026-10-01T20:30:00+00:00',
            'driver_name': 'Ahmed Driver',
          },
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
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('b2b-timeline-0-placed')), findsOneWidget);
    expect(find.byKey(const ValueKey('b2b-timeline-1-ready')), findsOneWidget);
    expect(
      find.byKey(const ValueKey('b2b-timeline-2-driver_assigned')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('b2b-timeline-3-out_for_delivery')),
      findsOneWidget,
    );
    expect(find.text('Ahmed Driver'), findsWidgets);
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
    await tester.pumpAndSettle();

    expect(api.calls, 2);
    expect(find.byKey(const ValueKey('b2b-timeline-1-delivered')), findsOneWidget);
    expect(find.text('تم التسليم'), findsWidgets);
    expect(find.byKey(const ValueKey('b2b-timeline-1-ready')), findsNothing);
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
      'payment': {
        'status': 'paid',
        'amount': 100.0,
        'currency': 'KWD',
      },
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
        'driver_name': 'Driver One',
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
        {
          'stage': 'placed',
          'occurred_at': '2026-10-04T10:00:00+00:00',
        },
        {
          'stage': 'failed',
          'occurred_at': '2026-10-04T10:20:00+00:00',
          'driver_name': 'Driver One',
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
    await tester.pumpAndSettle();

    expect(api.lastPath, '/api/v1/b2b/orders/78');
    expect(find.byKey(const ValueKey('b2b-order-detail')), findsOneWidget);
    expect(find.text('Order details'), findsOneWidget);
    expect(find.text('B2B-78'), findsOneWidget);
    expect(find.text('Wholesale Store'), findsOneWidget);
    expect(find.byKey(const ValueKey('b2b-order-terminal')), findsOneWidget);
    expect(
      Directionality.of(
        tester.element(find.byKey(const ValueKey('b2b-order-detail'))),
      ),
      TextDirection.ltr,
    );
    expect(find.text('تفاصيل الطلب'), findsNothing);

    final scrollable =
        find.byKey(const ValueKey('b2b-order-detail'));

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
    expect(find.text('Driver One'), findsWidgets);

    await _scrollUntilBuilt(
      tester,
      scrollable,
      find.text('Bulk Water'),
    );
    expect(find.text('Bulk Water'), findsOneWidget);
    expect(find.text('SKU-42'), findsOneWidget);

    await _scrollUntilBuilt(
      tester,
      scrollable,
      find.text('Price summary'),
    );
    expect(find.text('Price summary'), findsOneWidget);

    await _scrollUntilBuilt(
      tester,
      scrollable,
      find.text('Account credit'),
    );
    expect(find.text('Account credit'), findsOneWidget);
    expect(find.text('Paid'), findsOneWidget);

    await _scrollUntilBuilt(
      tester,
      scrollable,
      find.byKey(const ValueKey('b2b-order-open-invoice')),
    );
    expect(
      find.byKey(const ValueKey('b2b-order-open-invoice')),
      findsOneWidget,
    );

    await _scrollUntilBuilt(
      tester,
      scrollable,
      find.text('Leave at gate'),
    );
    expect(find.text('Leave at gate'), findsOneWidget);
  });

  testWidgets('legacy channel-scoped session cannot enter B2B protected journey', (tester) async {
    await tester.pumpWidget(const FoodexCustomerApp(session: CustomerSession.authenticated(CustomerChannel.b2c), initialRoute: '/b2b/invoices'));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('unified-customer-auth-screen')), findsNothing);
    expect(find.text('دخول عميل الأعمال'), findsNothing);
    expect(find.text('الفواتير'), findsNothing);
  });

  testWidgets('B2B top products expose current catalog state, sort and product navigation', (tester) async {
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
      'meta': {
        'page': 1,
        'per_page': 20,
        'total': 1,
        'has_more': false,
      },
    });
    await tester.pumpWidget(
      FoodexCustomerApp(
        session: b2b,
        initialRoute: '/b2b/products/top?store_id=7&from=2026-09-01&to=2026-09-30',
        b2bApi: api,
      ),
    );
    await tester.pumpAndSettle();

    expect(
      api.lastPath,
      '/api/v1/b2b/products/top?store_id=7&from=2026-09-01&to=2026-09-30',
    );
    expect(find.byKey(const ValueKey('b2b-top-products-filters')), findsOneWidget);
    expect(find.byKey(const ValueKey('b2b-top-products-data')), findsOneWidget);
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
    await tester.pumpAndSettle();
    await tester.tap(find.text('قيمة المشتريات').last);
    await tester.pumpAndSettle();

    final sortedUri = Uri.parse(api.lastPath!);
    expect(sortedUri.path, '/api/v1/b2b/products/top');
    expect(sortedUri.queryParameters['store_id'], '7');
    expect(sortedUri.queryParameters['from'], '2026-09-01');
    expect(sortedUri.queryParameters['to'], '2026-09-30');
    expect(sortedUri.queryParameters['sort'], 'value');
    expect(sortedUri.queryParameters['page'], '1');
    expect(sortedUri.queryParameters['per_page'], '20');

    final openProduct =
        find.byKey(const ValueKey('b2b-top-product-open-1'));
    await tester.ensureVisible(openProduct);
    await tester.pumpAndSettle();
    await tester.tap(openProduct);
    await tester.pumpAndSettle();

    expect(api.lastPath, '/api/v1/b2b/products/42?store_id=7');
  });

  testWidgets('C13 Screen 4 period presets match purchases report semantics', (tester) async {
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
      'meta': {
        'page': 1,
        'per_page': 20,
        'total': 1,
        'has_more': false,
      },
    });

    await tester.pumpWidget(
      FoodexCustomerApp(
        session: b2b,
        initialRoute: '/b2b/products/top?store_id=7',
        b2bApi: api,
      ),
    );
    await tester.pumpAndSettle();

    expect(
      find.byKey(const ValueKey('b2b-top-products-current-period')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('b2b-top-products-previous-period')),
      findsOneWidget,
    );
    expect(find.byKey(const ValueKey('b2b-top-product-rank-1')), findsOneWidget);

    await tester.tap(
      find.byKey(const ValueKey('b2b-top-products-current-period')),
    );
    await tester.pumpAndSettle();

    var uri = Uri.parse(api.lastPath!);
    expect(uri.queryParameters['store_id'], '7');
    expect(uri.queryParameters['from'], isNotNull);
    expect(uri.queryParameters['to'], isNotNull);
    expect(uri.queryParameters['page'], '1');

    await tester.tap(
      find.byKey(const ValueKey('b2b-top-products-previous-period')),
    );
    await tester.pumpAndSettle();

    uri = Uri.parse(api.lastPath!);
    expect(uri.queryParameters['from'], isNotNull);
    expect(uri.queryParameters['to'], isNotNull);
    expect(
      DateTime.parse(uri.queryParameters['from']!).day,
      1,
    );

    await tester.tap(
      find.byKey(const ValueKey('b2b-top-products-all-time')),
    );
    await tester.pumpAndSettle();

    uri = Uri.parse(api.lastPath!);
    expect(uri.queryParameters.containsKey('from'), isFalse);
    expect(uri.queryParameters.containsKey('to'), isFalse);
    expect(uri.queryParameters['store_id'], '7');
  });

  testWidgets('B2B top products visibly disable repurchase when authoritative stock is empty', (tester) async {
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
      'meta': {
        'page': 1,
        'per_page': 20,
        'total': 1,
        'has_more': false,
      },
    });

    await tester.pumpWidget(
      FoodexCustomerApp(
        session: b2b,
        initialRoute: '/b2b/products/top?store_id=7',
        b2bApi: api,
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('غير متاح حالياً — نفد المخزون'), findsOneWidget);
    expect(find.textContaining('7.25 EGP'), findsOneWidget);
    expect(find.byKey(const ValueKey('b2b-top-product-open-1')), findsOneWidget);
  });

  testWidgets('C13 Screen 3 renders period summary trend categories and drill-through', (tester) async {
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
        {
          'period': '2026-09-01',
          'orders_count': 1,
          'purchase_total': 50.0,
        },
        {
          'period': '2026-09-02',
          'orders_count': 1,
          'purchase_total': 100.0,
        },
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
      'meta': {
        'page': 1,
        'per_page': 5,
        'total': 1,
        'has_more': false,
      },
      'generated_at': '2026-09-02T10:00:00Z',
    });

    await tester.pumpWidget(
      FoodexCustomerApp(
        session: b2b,
        initialRoute: '/b2b/reports/purchases?store_id=7',
        b2bApi: api,
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('b2b-purchases-filters')), findsOneWidget);
    expect(
      find.byKey(const ValueKey('b2b-purchases-date-actions-row')),
      findsOneWidget,
    );
    final fromRect =
        tester.getRect(find.byKey(const ValueKey('b2b-purchases-from')));
    final toRect =
        tester.getRect(find.byKey(const ValueKey('b2b-purchases-to')));
    final refreshRect =
        tester.getRect(find.byKey(const ValueKey('b2b-purchases-refresh')));
    expect((fromRect.center.dy - toRect.center.dy).abs(), lessThan(1));
    expect((toRect.center.dy - refreshRect.center.dy).abs(), lessThan(1));
    expect(find.byKey(const ValueKey('b2b-purchases-summary')), findsOneWidget);
    expect(find.byKey(const ValueKey('b2b-purchases-trend')), findsOneWidget);
    expect(find.byKey(const ValueKey('b2b-purchases-categories')), findsOneWidget);
    expect(
      find.byKey(const ValueKey('b2b-purchases-category-donut')),
      findsOneWidget,
    );
    expect(find.text('Rice'), findsOneWidget);
    expect(find.text('Oil'), findsOneWidget);
    expect(find.text('B2B-77'), findsOneWidget);
    expect(api.lastPath, contains('store_id=7'));

    await tester.tap(
      find.byKey(const ValueKey('b2b-purchases-current-period')),
    );
    await tester.pumpAndSettle();

    final currentUri = Uri.parse(api.lastPath!);
    expect(currentUri.queryParameters['store_id'], '7');
    expect(currentUri.queryParameters['from'], isNotNull);
    expect(currentUri.queryParameters['to'], isNotNull);
    expect(currentUri.queryParameters['page'], '1');
    expect(currentUri.queryParameters['per_page'], '5');
  });

  testWidgets('B2B remote routes visibly render authoritative payload fields', (tester) async {
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
            {
              'period': '2026-09-01',
              'orders_count': 1,
              'purchase_total': 48.0,
            },
          ],
          'categories': [
            {
              'category_name': 'Bulk Rice',
              'purchase_total': 48.0,
              'percentage': 100.0,
            },
          ],
          'orders': <Object?>[],
          'meta': {
            'page': 1,
            'per_page': 5,
            'total': 0,
            'has_more': false,
          },
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
        payload: {
          'balance': 19.75,
          'currency': 'KWD',
          'data': <Object?>[],
        },
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
        expected: 'B2B-77',
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
      (
        route: '/b2b/profile',
        payload: {
          'company_name': 'Acme Foods',
          'email': 'buyer@example.test',
          'tax_number': 'TX-900',
        },
        expected: 'Acme Foods',
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
      await tester.pumpAndSettle();

      expect(
        find.byKey(const ValueKey('b2b-loaded')),
        findsNothing,
        reason: item.route,
      );
      expect(find.textContaining(item.expected), findsWidgets, reason: item.route);
      expect(
        Directionality.of(tester.element(find.textContaining(item.expected).first)),
        TextDirection.rtl,
        reason: item.route,
      );
    }
  });

  testWidgets('C13 Screen 6 reconciles statement totals, direction and references',
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
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('b2b-statement-data')), findsOneWidget);
    expect(find.byKey(const ValueKey('b2b-statement-summary-opening')), findsOneWidget);
    expect(find.byKey(const ValueKey('b2b-statement-summary-closing')), findsOneWidget);
    expect(find.byKey(const ValueKey('b2b-statement-direction-chip')), findsOneWidget);
    expect(find.byKey(const ValueKey('b2b-statement-current-direction')), findsOneWidget);
    expect(find.textContaining('50.000 EGP'), findsWidgets);
    expect(find.textContaining('عليك'), findsWidgets);
    expect(find.byKey(const ValueKey('b2b-statement-export-pdf')), findsOneWidget);
    expect(find.byKey(const ValueKey('b2b-statement-export-xlsx')), findsOneWidget);
    expect(
      find.byKey(const ValueKey('b2b-statement-running-invoice:31')),
      findsOneWidget,
    );
    expect(api.lastPath, contains('/api/v1/b2b/account-statement'));
    expect(api.lastPath, contains('store_id=7'));

    final invoiceReference = find.byKey(
      const ValueKey('b2b-statement-reference-invoice:31'),
    );
    await tester.ensureVisible(invoiceReference);
    await tester.pumpAndSettle();
    await tester.tap(invoiceReference);
    await tester.pumpAndSettle();
    expect(api.lastPath, '/api/v1/b2b/invoices/31');
  });

  testWidgets('C13 Screen 6 quick period preset reloads authoritative query',
      (tester) async {
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
    await tester.pumpAndSettle();
    await tester.tap(
      find.byKey(const ValueKey('b2b-statement-preset-30')),
    );
    await tester.pumpAndSettle();

    expect(api.lastPath, contains('from='));
    expect(api.lastPath, contains('to='));
    expect(api.lastPath, contains('page=1'));
    expect(api.lastPath, contains('per_page=30'));
  });

  testWidgets('B2B collections expose approved detail navigation', (tester) async {
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
          'due_at': '2026-10-20T10:00:00Z',
          'pdf_path':
              '/api/v1/invoices/31/download?channel=b2b&store_id=7',
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
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('b2b-invoices-search')), findsOneWidget);
    expect(find.byKey(const ValueKey('b2b-invoices-status')), findsOneWidget);
    expect(find.byKey(const ValueKey('b2b-invoices-from')), findsOneWidget);
    expect(find.byKey(const ValueKey('b2b-invoices-to')), findsOneWidget);
    expect(find.byKey(const ValueKey('b2b-invoice-pdf-31')), findsOneWidget);
    expect(find.textContaining('20.000 KWD'), findsWidgets);
    expect(find.textContaining('5.000 KWD'), findsWidgets);
    expect(find.textContaining('15.000 KWD'), findsWidgets);

    await tester.tap(find.text('INV-31'));
    await tester.pumpAndSettle();

    expect(find.text('/b2b/invoices/31'), findsNothing);
    expect(
      find.byKey(const ValueKey('customer-route-location')),
      findsNothing,
    );
    expect(api.lastPath, '/api/v1/b2b/invoices/31?store_id=7');
  });


  testWidgets('C13 Screen 9 renders reconciled invoice detail and visible actions',
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
        'due_at': '2026-10-20T10:00:00Z',
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
    await tester.pumpAndSettle();

    expect(api.lastPath, '/api/v1/b2b/invoices/31?store_id=7');
    expect(
      find.byKey(const ValueKey('b2b-invoice-detail-data')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('b2b-invoice-hero')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('b2b-invoice-brand')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('b2b-invoice-status-chip')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('b2b-invoice-settlement-strip')),
      findsOneWidget,
    );
    expect(find.text('INV-31'), findsOneWidget);
    expect(find.textContaining('FOODEX Wholesale'), findsOneWidget);
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
    expect(find.text('Wholesale item'), findsOneWidget);
    expect(find.textContaining('WHO-501'), findsOneWidget);
    expect(
      find.byKey(const ValueKey('b2b-invoice-payments')),
      findsOneWidget,
    );
    expect(find.textContaining('PAY-31'), findsOneWidget);
    expect(
      find.byKey(const ValueKey('b2b-invoice-ledger')),
      findsOneWidget,
    );
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
      findsOneWidget,
    );
    expect(find.textContaining('/api/v1/invoices/31/download'), findsNothing);
  });

  testWidgets('B2B remote journey renders loading and empty states', (tester) async {
    final api = _FakeB2bApi(const {'data': []});
    await tester.pumpWidget(FoodexCustomerApp(session: b2b, initialRoute: '/b2b/invoices', b2bApi: api));
    expect(find.byKey(const ValueKey('b2b-loading')), findsOneWidget);
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('b2b-empty')), findsOneWidget);
    expect(api.lastPath, '/api/v1/b2b/invoices');
  });

  testWidgets('B2B remote journey classifies network/client failure safely',
      (tester) async {
    await CustomerDiagnostics.instance.clear();
    await tester.pumpWidget(
      FoodexCustomerApp(
        session: b2b,
        initialRoute: '/b2b/account-statement',
        b2bApi: _FailingB2bApi(),
      ),
    );
    await tester.pumpAndSettle();

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
    await tester.pumpAndSettle();

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
    await tester.pumpAndSettle();

    expect(api.calls, 2);
    expect(
      find.byKey(const ValueKey('b2b-product-detail-data')),
      findsOneWidget,
    );
    expect(find.text('Recovered Product'), findsOneWidget);
  });

  testWidgets('B2B runtime diagnostics classify 401 403 404 and 5xx',
      (tester) async {
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
          b2bApi: _StatusFailingB2bApi(
            status: item.status,
            code: item.code,
          ),
          actionApi: _FakeCustomerActionApi(),
        ),
      );
      await tester.pumpAndSettle();

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
  _C13WholesaleCommerceApi({
    required this.cartValue,
    this.checkoutCompleter,
  });

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
          .where(
            (raw) =>
                raw is! Map ||
                !removedItemIds.contains(raw['id']),
          )
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
      'entitlements': {
        'direct_b2b': true,
        'principal_wholesale_store_id': 70,
      },
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
  int calls = 0;

  @override
  Future<Object?> get(String path) async {
    calls++;
    lastPath = path;
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
  const _StatusFailingB2bApi({
    required this.status,
    required this.code,
  });

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
    return {
      'store_id': storeId,
      'product_id': productId,
      'quantity': quantity,
    };
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
  Future<CustomerLoginResult> login({
    required String username,
  }) async =>
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
