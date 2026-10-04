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
    expect(find.text('الفواتير'), findsOneWidget);
    expect(find.text('كشف الحساب'), findsOneWidget);
  });

  testWidgets('B2B profile exposes the unified customer address book',
      (tester) async {
    await tester.pumpWidget(
      FoodexCustomerApp(
        session: const CustomerSession.authenticated(
          CustomerChannel.b2b,
          accessToken: 'token',
          platformWide: true,
        ),
        initialRoute: '/b2b/profile',
        b2bApi: _FakeB2bApi({
          'company_name': 'Acme Foods',
          'email': 'buyer@example.test',
        }),
      ),
    );
    await tester.pumpAndSettle();

    expect(
      find.byKey(const ValueKey('b2b-profile-friendly-data')),
      findsOneWidget,
    );
    expect(find.text('ملخص الحساب'), findsOneWidget);
    expect(find.text('بيانات الشركة والتواصل'), findsOneWidget);
    expect(find.text('Acme Foods'), findsOneWidget);
    expect(find.text('buyer@example.test'), findsOneWidget);
    expect(find.text('إدارة العناوين'), findsOneWidget);
    expect(find.text('العناوين'), findsOneWidget);
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
    expect(find.text('الدفع عند الاستلام'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('b2b-order-refresh')));
    await tester.pumpAndSettle();

    expect(api.calls, 2);
    expect(find.byKey(const ValueKey('b2b-timeline-1-delivered')), findsOneWidget);
    expect(find.text('تم التسليم'), findsWidgets);
    expect(find.byKey(const ValueKey('b2b-timeline-1-ready')), findsNothing);
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
    expect(find.textContaining('Case 12'), findsOneWidget);
    expect(
      find.byKey(const ValueKey('b2b-top-product-availability-1')),
      findsOneWidget,
    );

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

  testWidgets('B2B remote routes visibly render authoritative payload fields', (tester) async {
    final cases = <({String route, Object? payload, String expected})>[
      (
        route: '/b2b/dashboard',
        payload: {
          'purchases_total': 321.75,
          'open_invoices': 4,
          'balance': 88.5,
          'currency': 'KWD',
        },
        expected: '321.75',
      ),
      (
        route: '/b2b/reports/purchases',
        payload: {
          'period': {'from': '2026-09-01', 'to': '2026-09-30'},
          'data': [
            {'product_name': 'Bulk Rice', 'quantity': 12, 'total': 48.0},
          ],
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

  testWidgets('B2B collections expose approved detail navigation', (tester) async {
    final api = _FakeB2bApi({
      'data': [
        {
          'id': 31,
          'invoice_number': 'INV-31',
          'payment_status': 'paid',
        },
      ],
    });
    await tester.pumpWidget(
      FoodexCustomerApp(
        session: b2b,
        initialRoute: '/b2b/invoices',
        b2bApi: api,
      ),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.text('INV-31'));
    await tester.pumpAndSettle();

    expect(find.text('/b2b/invoices/31'), findsNothing);
    expect(
      find.byKey(const ValueKey('customer-route-location')),
      findsNothing,
    );
    expect(api.lastPath, '/api/v1/b2b/invoices/31');
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
  @override
  Future<Object?> get(String path) async { lastPath = path; return value; }
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
