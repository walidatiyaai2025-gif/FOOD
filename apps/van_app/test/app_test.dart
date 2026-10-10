import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_visualization/foodex_visualization.dart';
import 'package:foodex_van_app/app.dart';
import 'package:foodex_van_app/core/auth/van_session.dart';
import 'package:foodex_van_app/features/foundation/van_screen_inventory.dart';
import 'package:foodex_van_app/features/wallet/van_wallet_contract.dart';
import 'package:foodex_van_app/features/visits/van_visit_contract.dart';
import 'package:foodex_van_app/features/notifications/van_notification_contract.dart';
import 'package:foodex_van_app/features/notifications/van_notifications_page.dart';
import 'package:foodex_van_app/features/orders/van_order_contract.dart';

Future<void> _scrollUntilBuilt(
  WidgetTester tester,
  Finder scrollable,
  Finder target,
) async {
  for (var attempt = 0;
      attempt < 12 && target.evaluate().isEmpty;
      attempt++) {
    await tester.drag(scrollable, const Offset(0, -180));
    await tester.pumpAndSettle();
  }
}

void main() {
  test('approved Van production inventory stays locked to 19 surfaces', () {
    expect(vanProductionScreenInventory.length, 19);
    expect(vanProductionScreenInventory.first, VanScreenId.login);
    expect(vanProductionScreenInventory.last, VanScreenId.profile);
    expect(
      vanProductionScreenInventory.toSet().length,
      vanProductionScreenInventory.length,
    );
  });

  testWidgets('authenticated Van shell exposes production navigation instead of legacy tabs', (tester) async {
    await tester.pumpWidget(
      const FoodexVanApp(
        locale: Locale('en'),
        walletRepository: _EmptyWalletRepository(),
        initialSession: VanSession(
          token: 'test-token',
          name: 'Van Operator',
          email: 'van@example.test',
          locale: 'en',
          permissions: {'van.login'},
        ),
      ),
    );

    expect(find.text('Van Operator'), findsOneWidget);
    expect(find.text('Home Dashboard'), findsWidgets);
    expect(find.byType(NavigationBar), findsOneWidget);
    expect(find.byType(TabBar), findsNothing);

    final scaffold = tester.state<ScaffoldState>(find.byType(Scaffold).last);
    scaffold.openDrawer();
    await tester.pumpAndSettle();

    expect(find.text('FOODEX Van'), findsOneWidget);
    expect(
      find.byKey(const ValueKey('van-production-screen-menu')),
      findsOneWidget,
    );
    expect(find.text('Routes'), findsWidgets);
    expect(find.text('Customers'), findsWidgets);
    expect(find.text('Product Catalog'), findsOneWidget);
    expect(find.text('Order Builder'), findsOneWidget);
    expect(vanProductionScreenInventory.contains(VanScreenId.orders), isTrue);
    expect(vanProductionScreenInventory.contains(VanScreenId.notifications), isTrue);
    expect(vanProductionScreenInventory.contains(VanScreenId.profile), isTrue);

    final navigation = tester.widget<NavigationBar>(find.byType(NavigationBar));
    navigation.onDestinationSelected?.call(2);
    await tester.pumpAndSettle();
    expect(find.text('No assigned customers'), findsOneWidget);
  });

  testWidgets(
      'Arabic Van menu opens from physical left as overlay and closes after selection',
      (tester) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    await tester.pumpWidget(
      const FoodexVanApp(
        locale: Locale('ar'),
        walletRepository: _EmptyWalletRepository(),
        visitRepository: _RoutesVisitRepository(),
        initialSession: VanSession(
          token: 'test-token',
          name: 'مشغل الفان',
          email: 'van@example.test',
          locale: 'ar',
          permissions: {'van.login'},
        ),
      ),
    );
    await tester.pumpAndSettle();

    final scaffoldFinder = find.byType(Scaffold).last;
    final scaffold = tester.state<ScaffoldState>(scaffoldFinder);
    final menuToggle = find.byKey(const Key('van-menu-toggle'));

    expect(menuToggle, findsOneWidget);
    expect(tester.getCenter(menuToggle).dx, lessThan(390 / 2));
    expect(scaffold.isDrawerOpen, isFalse);
    expect(scaffold.isEndDrawerOpen, isFalse);

    await tester.tap(menuToggle);
    await tester.pumpAndSettle();

    expect(scaffold.isDrawerOpen, isFalse);
    expect(scaffold.isEndDrawerOpen, isTrue);

    final drawer = find.byKey(const Key('van-navigation-drawer'));
    expect(drawer, findsOneWidget);
    expect(tester.getTopLeft(drawer).dx, closeTo(0, 0.1));

    await tester.tap(find.byKey(const ValueKey('van-screen-routes')));
    await tester.pumpAndSettle();

    expect(scaffold.isEndDrawerOpen, isFalse);
    expect(find.text('المسارات'), findsWidgets);
  });

  testWidgets('Van Customer 360 renders authoritative customer financial context',
      (tester) async {
    await tester.pumpWidget(
      const FoodexVanApp(
        locale: Locale('en'),
        walletRepository: _CustomerWalletRepository(),
        initialSession: VanSession(
          token: 'test-token',
          name: 'Van Operator',
          email: 'van@example.test',
          locale: 'en',
          permissions: {'van.login'},
        ),
      ),
    );
    await tester.pumpAndSettle();

    final scaffold = tester.state<ScaffoldState>(find.byType(Scaffold).last);
    scaffold.openDrawer();
    await tester.pumpAndSettle();

    final target =
        find.byKey(const ValueKey('van-screen-customer360'));
    await tester.scrollUntilVisible(
      target,
      180,
      scrollable: find.descendant(
        of: find.byKey(const ValueKey('van-production-screen-menu')),
        matching: find.byType(Scrollable),
      ).first,
    );
    await tester.tap(target);
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('van-customer-360')), findsOneWidget);
    expect(find.text('Acme Grocery'), findsWidgets);
    expect(find.text('INV-42'), findsOneWidget);
    expect(find.text('12.500 KWD'), findsOneWidget);
  });


  testWidgets('Van Collection posts through authoritative repository contract',
      (tester) async {
    await tester.pumpWidget(
      const FoodexVanApp(
        locale: Locale('en'),
        walletRepository: _CollectionWalletRepository(),
        initialSession: VanSession(
          token: 'test-token',
          name: 'Van Operator',
          email: 'van@example.test',
          locale: 'en',
          permissions: {'van.login'},
        ),
      ),
    );
    await tester.pumpAndSettle();

    final scaffold = tester.state<ScaffoldState>(find.byType(Scaffold).last);
    scaffold.openDrawer();
    await tester.pumpAndSettle();

    final target = find.byKey(const ValueKey('van-screen-collection'));
    await tester.scrollUntilVisible(
      target,
      180,
      scrollable: find.descendant(
        of: find.byKey(const ValueKey('van-production-screen-menu')),
        matching: find.byType(Scrollable),
      ).first,
    );
    await tester.tap(target);
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('van-collection-page')), findsOneWidget);
    expect(find.textContaining('INV-42'), findsWidgets);

    await tester.enterText(
      find.byKey(const ValueKey('van-collection-amount')),
      '5.000',
    );
    await tester.tap(find.byKey(const ValueKey('van-collection-submit')));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('van-collection-receipt')), findsOneWidget);
    expect(find.textContaining('#77'), findsOneWidget);
    expect(find.textContaining('7.500 KWD'), findsOneWidget);
  });


  testWidgets('Van Receipt renders custody-ledger receipt history',
      (tester) async {
    await tester.pumpWidget(
      const FoodexVanApp(
        locale: Locale('en'),
        walletRepository: _ReceiptsWalletRepository(),
        initialSession: VanSession(
          token: 'test-token',
          name: 'Van Operator',
          email: 'van@example.test',
          locale: 'en',
          permissions: {'van.login'},
        ),
      ),
    );
    await tester.pumpAndSettle();

    final scaffold = tester.state<ScaffoldState>(find.byType(Scaffold).last);
    scaffold.openDrawer();
    await tester.pumpAndSettle();

    final target = find.byKey(const ValueKey('van-screen-receipt'));
    await tester.scrollUntilVisible(
      target,
      180,
      scrollable: find.descendant(
        of: find.byKey(const ValueKey('van-production-screen-menu')),
        matching: find.byType(Scrollable),
      ).first,
    );
    await tester.tap(target);
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('van-receipts-page')), findsOneWidget);
    expect(find.byKey(const ValueKey('van-receipt-77')), findsOneWidget);
    expect(find.text('#77'), findsOneWidget);
    expect(find.text('5.000 KWD'), findsOneWidget);
  });


  testWidgets('Van Remittance submits through authoritative repository contract',
      (tester) async {
    await tester.pumpWidget(
      const FoodexVanApp(
        locale: Locale('en'),
        walletRepository: _RemittanceWalletRepository(),
        initialSession: VanSession(
          token: 'test-token',
          name: 'Van Operator',
          email: 'van@example.test',
          locale: 'en',
          permissions: {'van.login'},
        ),
      ),
    );
    await tester.pumpAndSettle();

    final scaffold = tester.state<ScaffoldState>(find.byType(Scaffold).last);
    scaffold.openDrawer();
    await tester.pumpAndSettle();

    final target = find.byKey(const ValueKey('van-screen-remittance'));
    await tester.scrollUntilVisible(
      target,
      180,
      scrollable: find.descendant(
        of: find.byKey(const ValueKey('van-production-screen-menu')),
        matching: find.byType(Scrollable),
      ).first,
    );
    await tester.tap(target);
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('van-remittance-page')), findsOneWidget);
    expect(find.text('10.000 KWD'), findsOneWidget);

    await tester.enterText(
      find.byKey(const ValueKey('van-remittance-amount')),
      '5.000',
    );
    final remittanceSubmit = find.byKey(const ValueKey('van-remittance-submit'));
    await tester.ensureVisible(remittanceSubmit);
    await tester.pumpAndSettle();
    await tester.tap(remittanceSubmit);
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('van-remittance-result')), findsOneWidget);
    expect(find.textContaining('5.000 KWD'), findsWidgets);
  });


  testWidgets('Van Profile renders authenticated session identity and access scope',
      (tester) async {
    await tester.pumpWidget(
      const FoodexVanApp(
        locale: Locale('en'),
        walletRepository: _EmptyWalletRepository(),
        initialSession: VanSession(
          token: 'test-token',
          name: 'Van Operator',
          email: 'van@example.test',
          locale: 'en',
          permissions: {'van.login', 'finance.view'},
        ),
      ),
    );
    await tester.pumpAndSettle();

    final scaffold = tester.state<ScaffoldState>(find.byType(Scaffold).last);
    scaffold.openDrawer();
    await tester.pumpAndSettle();

    final target = find.byKey(const ValueKey('van-screen-profile'));
    await tester.scrollUntilVisible(
      target,
      180,
      scrollable: find.descendant(
        of: find.byKey(const ValueKey('van-production-screen-menu')),
        matching: find.byType(Scrollable),
      ).first,
    );
    await tester.ensureVisible(target);
    await tester.pumpAndSettle();
    await tester.tap(target);
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('van-profile-page')), findsOneWidget);
    expect(find.text('Van Operator'), findsWidgets);
    expect(find.text('van@example.test'), findsOneWidget);
    expect(find.text('EN'), findsOneWidget);
    expect(find.text('2'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('van-profile-permissions')));
    await tester.pumpAndSettle();
    expect(find.text('finance.view'), findsOneWidget);
    expect(find.text('van.login'), findsOneWidget);
  });


  testWidgets('Van Dashboard renders authoritative operational totals',
      (tester) async {
    await tester.pumpWidget(
      const FoodexVanApp(
        locale: Locale('en'),
        walletRepository: _DashboardWalletRepository(),
        initialSession: VanSession(
          token: 'test-token',
          name: 'Van Operator',
          email: 'van@example.test',
          locale: 'en',
          permissions: {'van.login'},
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('van-dashboard-page')), findsOneWidget);
    await tester.scrollUntilVisible(
      find.byKey(const ValueKey('van-dashboard-cash-flow-chart')),
      180,
      scrollable: find.descendant(
        of: find.byKey(const ValueKey('van-dashboard-page')),
        matching: find.byType(Scrollable),
      ).first,
    );
    expect(
      find.byKey(const ValueKey('van-dashboard-cash-flow-chart')),
      findsOneWidget,
    );
    expect(find.byType(FoodexBarChart), findsOneWidget);
    expect(find.text('2'), findsOneWidget);
    expect(find.text('15.000 KWD'), findsOneWidget);
    expect(find.text('9.000 KWD'), findsOneWidget);
    expect(find.text('1'), findsWidgets);
  });


  testWidgets('Van Visit Workspace uses canonical visit lifecycle transitions',
      (tester) async {
    await tester.pumpWidget(
      FoodexVanApp(
        locale: Locale('en'),
        walletRepository: _CustomerWalletRepository(),
        visitRepository: _VisitRepository(),
        orderRepository: _OrderRepository(),
        initialSession: VanSession(
          token: 'test-token',
          name: 'Van Operator',
          email: 'van@example.test',
          locale: 'en',
          permissions: {'van.login'},
        ),
      ),
    );
    await tester.pumpAndSettle();

    final scaffold = tester.state<ScaffoldState>(find.byType(Scaffold).last);
    scaffold.openDrawer();
    await tester.pumpAndSettle();

    final target = find.byKey(const ValueKey('van-screen-visit'));
    await tester.scrollUntilVisible(
      target,
      180,
      scrollable: find.descendant(
        of: find.byKey(const ValueKey('van-production-screen-menu')),
        matching: find.byType(Scrollable),
      ).first,
    );
    await tester.tap(target);
    await tester.pumpAndSettle();

    expect(
      find.byKey(const ValueKey('van-visit-workspace-page')),
      findsOneWidget,
    );
    expect(find.text('Acme Grocery'), findsOneWidget);
    expect(find.text('Planned'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('van-visit-start-501')));
    await tester.pumpAndSettle();

    expect(find.text('Started'), findsOneWidget);
    expect(find.text('Planned'), findsNothing);
  });


  testWidgets('Van Routes renders canonical route context from visit feed',
      (tester) async {
    await tester.pumpWidget(
      const FoodexVanApp(
        locale: Locale('en'),
        walletRepository: _EmptyWalletRepository(),
        visitRepository: _RoutesVisitRepository(),
        initialSession: VanSession(
          token: 'test-token',
          name: 'Van Operator',
          email: 'van@example.test',
          locale: 'en',
          permissions: {'van.login'},
        ),
      ),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.text('Routes').last);
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('van-routes-page')), findsOneWidget);
    expect(find.byKey(const ValueKey('van-routes-status-chart')), findsOneWidget);
    expect(find.byType(FoodexDonutChart), findsOneWidget);
    expect(find.text('ROUTE-A'), findsOneWidget);
    expect(find.text('2 assigned visits'), findsOneWidget);
  });


  testWidgets('Van Route Detail renders route visit KPIs from visit repository',
      (tester) async {
    await tester.pumpWidget(
      const FoodexVanApp(
        locale: Locale('en'),
        walletRepository: _EmptyWalletRepository(),
        visitRepository: _RoutesVisitRepository(),
        initialSession: VanSession(
          token: 'test-token',
          name: 'Van Operator',
          email: 'van@example.test',
          locale: 'en',
          permissions: {'van.login'},
        ),
      ),
    );
    await tester.pumpAndSettle();

    final scaffold = tester.state<ScaffoldState>(find.byType(Scaffold).last);
    scaffold.openDrawer();
    await tester.pumpAndSettle();

    final target = find.byKey(const ValueKey('van-screen-routeDetail'));
    await tester.scrollUntilVisible(
      target,
      180,
      scrollable: find.descendant(
        of: find.byKey(const ValueKey('van-production-screen-menu')),
        matching: find.byType(Scrollable),
      ).first,
    );
    await tester.tap(target);
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('van-route-detail-page')), findsOneWidget);
    expect(find.text('ROUTE-A'), findsWidgets);
    expect(find.text('Visits · 2'), findsOneWidget);
    expect(find.text('Planned · 1'), findsOneWidget);
    expect(find.text('Started · 1'), findsOneWidget);
  });


  testWidgets('Van Notifications renders canonical feed and marks read',
      (tester) async {
    final notifications = _NotificationRepository();
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: VanNotificationsPage(
            repository: notifications,
            onSessionExpired: () async {},
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('van-notifications-page')), findsOneWidget);
    expect(find.text('Route updated'), findsOneWidget);
    expect(find.text('Stop sequence changed.'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('van-notification-91')));
    await tester.pumpAndSettle();
    expect(notifications.markedRead, 91);
  });


  testWidgets('Van Route Map plots only authoritative visit coordinates',
      (tester) async {
    await tester.pumpWidget(
      const FoodexVanApp(
        locale: Locale('en'),
        walletRepository: _RouteCustomersRepository(),
        visitRepository: _RoutesVisitRepository(),
        initialSession: VanSession(
          token: 'test-token',
          name: 'Van Operator',
          email: 'van@example.test',
          locale: 'en',
          permissions: {'van.login'},
        ),
      ),
    );
    await tester.pumpAndSettle();

    final scaffold = tester.state<ScaffoldState>(find.byType(Scaffold).last);
    scaffold.openDrawer();
    await tester.pumpAndSettle();
    final target = find.byKey(const ValueKey('van-screen-routeMap'));
    await tester.scrollUntilVisible(
      target,
      180,
      scrollable: find.descendant(
        of: find.byKey(const ValueKey('van-production-screen-menu')),
        matching: find.byType(Scrollable),
      ).first,
    );
    await tester.tap(target);
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('van-route-map-page')), findsOneWidget);
    expect(find.byKey(const ValueKey('van-route-map-canvas')), findsOneWidget);
    expect(find.text('ROUTE-A'), findsOneWidget);
    expect(find.text('0/2'), findsOneWidget);
    expect(find.textContaining('Next: Acme Grocery'), findsOneWidget);
    expect(find.text('Block 3 · Street 17'), findsOneWidget);
  });


  testWidgets('Van sales flow reaches Catalog Builder Review and Orders',
      (tester) async {
    final orders = _OrderRepository();
    await tester.pumpWidget(
      FoodexVanApp(
        locale: const Locale('en'),
        walletRepository: const _CustomerWalletRepository(),
        orderRepository: orders,
        initialSession: const VanSession(
          token: 'test-token',
          name: 'Van Operator',
          email: 'van@example.test',
          locale: 'en',
          permissions: {'van.login'},
        ),
      ),
    );
    await tester.pumpAndSettle();

    var scaffold = tester.state<ScaffoldState>(find.byType(Scaffold).last);
    scaffold.openDrawer();
    await tester.pumpAndSettle();
    final catalogTarget = find.byKey(const ValueKey('van-screen-catalog'));
    await tester.scrollUntilVisible(
      catalogTarget,
      180,
      scrollable: find.descendant(
        of: find.byKey(const ValueKey('van-production-screen-menu')),
        matching: find.byType(Scrollable),
      ).first,
    );
    await tester.tap(catalogTarget);
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('van-catalog-page')), findsOneWidget);
    expect(find.byKey(const ValueKey('van-catalog-stock-chart')), findsOneWidget);
    expect(find.byType(FoodexDonutChart), findsOneWidget);
    expect(find.text('Water Case'), findsOneWidget);
    final catalogScrollable = find.descendant(
      of: find.byKey(const ValueKey('van-catalog-page')),
      matching: find.byType(Scrollable),
    ).first;
    final addProduct =
        find.byKey(const ValueKey('van-catalog-add-301'));
    await tester.scrollUntilVisible(
      addProduct,
      180,
      scrollable: catalogScrollable,
    );
    await tester.drag(catalogScrollable, const Offset(0, -140));
    await tester.pumpAndSettle();
    expect(tester.widget<IconButton>(addProduct).onPressed, isNotNull);
    await tester.tap(addProduct);
    await tester.pumpAndSettle();

    final openCart =
        find.byKey(const ValueKey('van-catalog-open-cart'));
    await _scrollUntilBuilt(tester, catalogScrollable, openCart);
    expect(openCart, findsOneWidget);
    await tester.ensureVisible(openCart);
    await tester.pumpAndSettle();
    await tester.tap(openCart);
    await tester.pumpAndSettle();

    expect(
      find.byKey(const ValueKey('van-order-builder-page')),
      findsOneWidget,
    );
    await tester.tap(find.byKey(const ValueKey('van-order-builder-review')));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('van-order-review-page')), findsOneWidget);
    expect(find.text('12.000 KWD'), findsWidgets);
    final orderSubmit = find.byKey(const ValueKey('van-order-submit'));
    await tester.scrollUntilVisible(
      orderSubmit,
      180,
      scrollable: find.descendant(
        of: find.byKey(const ValueKey('van-order-review-page')),
        matching: find.byType(Scrollable),
      ).first,
    );
    await tester.ensureVisible(orderSubmit);
    await tester.pumpAndSettle();
    await tester.tap(orderSubmit);
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('van-orders-page')), findsOneWidget);
    expect(find.byKey(const ValueKey('van-orders-status-chart')), findsOneWidget);
    expect(find.byType(FoodexDonutChart), findsOneWidget);
    expect(find.text('FDX-B2B-TEST-001'), findsOneWidget);
    expect(find.text('12.000 KWD'), findsWidgets);
    expect(orders.createdCount, 1);
  });


  testWidgets('Van Visit Workspace completes with authoritative order lookup',
      (tester) async {
    final visits = _StartedVisitRepository();
    final orders = _OrderRepository()..createdCount = 1;
    await tester.pumpWidget(
      FoodexVanApp(
        locale: const Locale('en'),
        walletRepository: const _CustomerWalletRepository(),
        visitRepository: visits,
        orderRepository: orders,
        initialSession: const VanSession(
          token: 'test-token',
          name: 'Van Operator',
          email: 'van@example.test',
          locale: 'en',
          permissions: {'van.login'},
        ),
      ),
    );
    await tester.pumpAndSettle();

    final scaffold = tester.state<ScaffoldState>(find.byType(Scaffold).last);
    scaffold.openDrawer();
    await tester.pumpAndSettle();
    final target = find.byKey(const ValueKey('van-screen-visit'));
    await tester.scrollUntilVisible(
      target,
      180,
      scrollable: find.descendant(
        of: find.byKey(const ValueKey('van-production-screen-menu')),
        matching: find.byType(Scrollable),
      ).first,
    );
    await tester.tap(target);
    await tester.pumpAndSettle();

    final visitOrder = find.byKey(const ValueKey('van-visit-order-801'));
    await tester.ensureVisible(visitOrder);
    await tester.pumpAndSettle();
    await tester.tap(visitOrder);
    await tester.pumpAndSettle();
    final orderOption = find.text('FDX-B2B-TEST-001 · 12.000 KWD').last;
    expect(orderOption, findsOneWidget);
    await tester.tap(orderOption);
    await tester.pumpAndSettle();
    final completeOrder = find.byKey(const ValueKey('van-visit-complete-order-801'));
    await tester.ensureVisible(completeOrder);
    await tester.pumpAndSettle();
    await tester.tap(completeOrder);
    await tester.pumpAndSettle();

    expect(visits.lastStatus, 'completed_with_order');
    expect(visits.lastOrderId, 7001);
    expect(find.text('Completed with order'), findsOneWidget);
  });

}


class _EmptyWalletRepository implements VanWalletRepository {
  const _EmptyWalletRepository();

  @override
  Future<List<VanWalletAccount>> wallet() async => const [];

  @override
  Future<List<VanCustomerScope>> customers() async => const [];

  @override
  Future<VanCollectionContext> collectionContext(
    VanCustomerScope customer,
  ) async =>
      const VanCollectionContext(storeId: 1, invoices: []);

  @override
  Future<VanCollectionResult> collect({
    required VanCustomerScope customer,
    required int invoiceId,
    required double amount,
    required String idempotencyKey,
  }) {
    throw UnimplementedError();
  }

  @override
  Future<VanWalletAccount> remit({
    required int collectionAccountId,
    required double amount,
    required String method,
    required String idempotencyKey,
    String? reference,
    String? note,
  }) {
    throw UnimplementedError();
  }
}


class _CustomerWalletRepository implements VanWalletRepository {
  const _CustomerWalletRepository();

  @override
  Future<List<VanWalletAccount>> wallet() async => const [];

  @override
  Future<List<VanCustomerScope>> customers() async => const [
        VanCustomerScope(
          type: 'b2b',
          id: 42,
          name: 'Acme Grocery',
          storeId: 7,
        ),
      ];

  @override
  Future<VanCollectionContext> collectionContext(
    VanCustomerScope customer,
  ) async =>
      const VanCollectionContext(
        storeId: 7,
        invoices: [
          VanInvoiceBalance(
            id: 42,
            number: 'INV-42',
            currency: 'KWD',
            total: 20,
            outstandingAmount: 12.5,
          ),
        ],
      );

  @override
  Future<VanCollectionResult> collect({
    required VanCustomerScope customer,
    required int invoiceId,
    required double amount,
    required String idempotencyKey,
  }) {
    throw UnimplementedError();
  }

  @override
  Future<VanWalletAccount> remit({
    required int collectionAccountId,
    required double amount,
    required String method,
    required String idempotencyKey,
    String? reference,
    String? note,
  }) {
    throw UnimplementedError();
  }
}


class _CollectionWalletRepository implements VanWalletRepository {
  const _CollectionWalletRepository();

  @override
  Future<List<VanWalletAccount>> wallet() async => const [];

  @override
  Future<List<VanCustomerScope>> customers() async => const [
        VanCustomerScope(
          type: 'b2b',
          id: 42,
          name: 'Acme Grocery',
          storeId: 7,
        ),
      ];

  @override
  Future<VanCollectionContext> collectionContext(
    VanCustomerScope customer,
  ) async =>
      const VanCollectionContext(
        storeId: 7,
        invoices: [
          VanInvoiceBalance(
            id: 42,
            number: 'INV-42',
            currency: 'KWD',
            total: 20,
            outstandingAmount: 12.5,
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
      const VanCollectionResult(
        receipt: VanReceipt(
          id: 77,
          amount: 5,
          currency: 'KWD',
          status: 'posted',
          createdAt: '2026-10-07T08:30:00+03:00',
        ),
        remainingOutstanding: 7.5,
        wallet: VanWalletAccount(
          id: 5,
          storeId: 7,
          currency: 'KWD',
          status: 'active',
          custodyBalance: 5,
          availableToRemit: 5,
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
  }) {
    throw UnimplementedError();
  }
}


class _ReceiptsWalletRepository implements VanWalletRepository {
  const _ReceiptsWalletRepository();

  @override
  Future<List<VanWalletAccount>> wallet() async => const [
        VanWalletAccount(
          id: 5,
          storeId: 7,
          currency: 'KWD',
          status: 'active',
          custodyBalance: 5,
          availableToRemit: 5,
          receipts: [
            VanReceipt(
              id: 77,
              amount: 5,
              currency: 'KWD',
              status: 'posted',
              createdAt: '2026-10-07T08:30:00+03:00',
            ),
          ],
          remittances: [],
        ),
      ];

  @override
  Future<List<VanCustomerScope>> customers() async => const [];

  @override
  Future<VanCollectionContext> collectionContext(
    VanCustomerScope customer,
  ) async =>
      const VanCollectionContext(storeId: 7, invoices: []);

  @override
  Future<VanCollectionResult> collect({
    required VanCustomerScope customer,
    required int invoiceId,
    required double amount,
    required String idempotencyKey,
  }) {
    throw UnimplementedError();
  }

  @override
  Future<VanWalletAccount> remit({
    required int collectionAccountId,
    required double amount,
    required String method,
    required String idempotencyKey,
    String? reference,
    String? note,
  }) {
    throw UnimplementedError();
  }
}


class _RemittanceWalletRepository implements VanWalletRepository {
  const _RemittanceWalletRepository();

  @override
  Future<List<VanWalletAccount>> wallet() async => const [
        VanWalletAccount(
          id: 5,
          storeId: 7,
          currency: 'KWD',
          status: 'active',
          custodyBalance: 10,
          availableToRemit: 10,
          receipts: [],
          remittances: [],
        ),
      ];

  @override
  Future<List<VanCustomerScope>> customers() async => const [];

  @override
  Future<VanCollectionContext> collectionContext(
    VanCustomerScope customer,
  ) async =>
      const VanCollectionContext(storeId: 7, invoices: []);

  @override
  Future<VanCollectionResult> collect({
    required VanCustomerScope customer,
    required int invoiceId,
    required double amount,
    required String idempotencyKey,
  }) {
    throw UnimplementedError();
  }

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
        custodyBalance: 5,
        availableToRemit: 5,
        receipts: [],
        remittances: [
          VanRemittance(
            id: 88,
            amount: 5,
            currency: 'KWD',
            method: 'cash_deposit',
            status: 'submitted',
            createdAt: '2026-10-07T08:45:00+03:00',
          ),
        ],
      );
}


class _DashboardWalletRepository implements VanWalletRepository {
  const _DashboardWalletRepository();

  @override
  Future<List<VanWalletAccount>> wallet() async => const [
        VanWalletAccount(
          id: 5,
          storeId: 7,
          currency: 'KWD',
          status: 'active',
          custodyBalance: 15,
          availableToRemit: 9,
          receipts: [
            VanReceipt(
              id: 77,
              amount: 6,
              currency: 'KWD',
              status: 'posted',
              createdAt: '2026-10-07T08:30:00+03:00',
            ),
          ],
          remittances: [
            VanRemittance(
              id: 88,
              amount: 6,
              currency: 'KWD',
              method: 'cash_deposit',
              status: 'submitted',
              createdAt: '2026-10-07T08:45:00+03:00',
            ),
          ],
        ),
      ];

  @override
  Future<List<VanCustomerScope>> customers() async => const [
        VanCustomerScope(type: 'b2b', id: 42, name: 'Acme Grocery', storeId: 7),
        VanCustomerScope(type: 'b2c', id: 43, name: 'City Market', storeId: 8),
      ];

  @override
  Future<VanCollectionContext> collectionContext(
    VanCustomerScope customer,
  ) async =>
      const VanCollectionContext(storeId: 7, invoices: []);

  @override
  Future<VanCollectionResult> collect({
    required VanCustomerScope customer,
    required int invoiceId,
    required double amount,
    required String idempotencyKey,
  }) {
    throw UnimplementedError();
  }

  @override
  Future<VanWalletAccount> remit({
    required int collectionAccountId,
    required double amount,
    required String method,
    required String idempotencyKey,
    String? reference,
    String? note,
  }) {
    throw UnimplementedError();
  }
}


class _VisitRepository implements VanVisitRepository {
  const _VisitRepository();

  @override
  Future<List<VanVisitRecord>> visits({String? status}) async => const [
        VanVisitRecord(
          id: 501,
          customerType: 'b2b',
          customerId: 42,
          storeId: 7,
          status: 'planned',
          plannedAt: '2026-10-07T10:00:00+03:00',
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
  }) async {
    if (visitId != 501 || status != 'started') {
      throw StateError('Unexpected visit transition in test.');
    }
    return const VanVisitRecord(
      id: 501,
      customerType: 'b2b',
      customerId: 42,
      storeId: 7,
      status: 'started',
      plannedAt: '2026-10-07T10:00:00+03:00',
      startedAt: '2026-10-07T10:01:00+03:00',
      allowedTransitions: [
        'completed_with_order',
        'completed_no_order',
        'customer_unavailable',
      ],
    );
  }
}


class _RoutesVisitRepository implements VanVisitRepository {
  const _RoutesVisitRepository();

  @override
  Future<List<VanVisitRecord>> visits({String? status}) async => const [
        VanVisitRecord(
          id: 601,
          customerType: 'b2b',
          customerId: 42,
          storeId: 7,
          routeKey: 'ROUTE-A',
          latitude: 29.3375,
          longitude: 47.6581,
          address: 'Block 3 · Street 17',
          status: 'planned',
          plannedAt: '2026-10-07T10:00:00+03:00',
          allowedTransitions: ['started', 'customer_unavailable'],
        ),
        VanVisitRecord(
          id: 602,
          customerType: 'b2c',
          customerId: 43,
          storeId: 8,
          routeKey: 'ROUTE-A',
          latitude: 29.3412,
          longitude: 47.6654,
          address: 'Block 5 · Street 9',
          status: 'started',
          plannedAt: '2026-10-07T11:00:00+03:00',
          allowedTransitions: [
            'completed_with_order',
            'completed_no_order',
            'customer_unavailable',
          ],
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
  }) {
    throw UnimplementedError();
  }
}


class _NotificationRepository implements VanNotificationRepository {
  int? markedRead;

  @override
  Future<List<VanNotificationRecord>> notifications({
    required String locale,
  }) async =>
      const [
        VanNotificationRecord(
          id: 91,
          type: 'route_updated',
          title: 'Route updated',
          body: 'Stop sequence changed.',
          publishedAt: '2026-10-07T09:00:00+03:00',
          read: false,
        ),
      ];

  @override
  Future<void> markRead(int notificationId) async {
    markedRead = notificationId;
  }
}


class _RouteCustomersRepository implements VanWalletRepository {
  const _RouteCustomersRepository();

  @override
  Future<List<VanCustomerScope>> customers() async => const [
        VanCustomerScope(type: 'b2b', id: 42, name: 'Acme Grocery', storeId: 7),
        VanCustomerScope(type: 'b2c', id: 43, name: 'City Market', storeId: 8),
      ];

  @override
  Future<List<VanWalletAccount>> wallet() async => const [];

  @override
  Future<VanCollectionContext> collectionContext(
    VanCustomerScope customer,
  ) async =>
      VanCollectionContext(storeId: customer.storeId, invoices: const []);

  @override
  Future<VanCollectionResult> collect({
    required VanCustomerScope customer,
    required int invoiceId,
    required double amount,
    required String idempotencyKey,
  }) {
    throw UnimplementedError();
  }

  @override
  Future<VanWalletAccount> remit({
    required int collectionAccountId,
    required double amount,
    required String method,
    required String idempotencyKey,
    String? reference,
    String? note,
  }) {
    throw UnimplementedError();
  }
}


class _OrderRepository implements VanOrderRepository {
  int createdCount = 0;

  @override
  Future<List<VanCatalogProduct>> catalog(
    VanCustomerScope customer, {
    String search = '',
  }) async =>
      const [
        VanCatalogProduct(
          id: 301,
          name: 'Water Case',
          sku: 'WATER-CASE',
          unitPrice: 12,
          minimumQuantity: 1,
          orderingIncrement: 1,
          isAvailable: true,
          availableQuantity: 20,
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
  }) async {
    createdCount += 1;
    return const VanOrderRecord(
      id: 7001,
      orderNumber: 'FDX-B2B-TEST-001',
      customerType: 'b2b',
      customerId: 42,
      storeId: 7,
      status: 'pending',
      currency: 'KWD',
      grandTotal: 12,
      createdAt: '2026-10-07T10:00:00+03:00',
    );
  }

  @override
  Future<List<VanOrderRecord>> orders({
    VanCustomerScope? customer,
    String? status,
  }) async =>
      createdCount == 0
          ? const []
          : const [
              VanOrderRecord(
                id: 7001,
                orderNumber: 'FDX-B2B-TEST-001',
                customerType: 'b2b',
                customerId: 42,
                storeId: 7,
                status: 'pending',
                currency: 'KWD',
                grandTotal: 12,
                createdAt: '2026-10-07T10:00:00+03:00',
              ),
            ];

  @override
  Future<VanOrderDetail> order(int orderId) =>
      throw UnimplementedError('Order detail is not used by this fixture.');

  @override
  Future<VanOrderExecutionState> execution(int orderId) =>
      throw UnimplementedError('Order execution is not used by this fixture.');

  @override
  Future<VanOrderExecutionState> transitionOrder({
    required int orderId,
    required String status,
    required String idempotencyKey,
  }) =>
      throw UnimplementedError('Order transition is not used by this fixture.');

}


class _StartedVisitRepository implements VanVisitRepository {
  String? lastStatus;
  int? lastOrderId;

  @override
  Future<List<VanVisitRecord>> visits({String? status}) async => const [
        VanVisitRecord(
          id: 801,
          customerType: 'b2b',
          customerId: 42,
          storeId: 7,
          status: 'started',
          allowedTransitions: [
            'completed_with_order',
            'completed_no_order',
            'customer_unavailable',
          ],
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
  }) async {
    lastStatus = status;
    lastOrderId = orderId;
    return VanVisitRecord(
      id: visitId,
      customerType: 'b2b',
      customerId: 42,
      storeId: 7,
      status: status,
      orderId: orderId,
      noOrderReasonId: noOrderReasonId,
      allowedTransitions: const ['closed'],
    );
  }
}
