import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_visualization/foodex_visualization.dart';
import 'package:foodex_van_app/app.dart';
import 'package:foodex_van_app/core/auth/van_session.dart';
import 'package:foodex_van_app/core/push/firebase_push_service.dart';
import 'package:foodex_van_app/features/foundation/van_screen_inventory.dart';
import 'package:foodex_van_app/features/wallet/van_wallet_contract.dart';
import 'package:foodex_van_app/features/visits/van_visit_contract.dart';
import 'package:foodex_van_app/features/notifications/van_notification_contract.dart';
import 'package:foodex_van_app/features/notifications/van_notifications_page.dart';
import 'package:foodex_van_app/features/orders/van_order_contract.dart';
import 'package:foodex_van_app/features/orders/van_order_detail_page.dart';
import 'package:foodex_van_app/features/orders/van_order_proof_picker.dart';
import 'package:foodex_van_app/shared/van_action_button.dart';

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


  testWidgets('Van Route Detail opens the exact linked B2B order',
      (tester) async {
    final orders = _OrderRepository()..createdCount = 1;
    await tester.pumpWidget(
      FoodexVanApp(
        locale: const Locale('en'),
        walletRepository: const _EmptyWalletRepository(),
        visitRepository: const _RoutesVisitRepository(),
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

    final linkedVisit = find.byKey(const ValueKey('van-route-detail-visit-601'));
    await tester.scrollUntilVisible(
      linkedVisit,
      180,
      scrollable: find.descendant(
        of: find.byKey(const ValueKey('van-route-detail-page')),
        matching: find.byType(Scrollable),
      ).first,
    );
    final tile = tester.widget<ListTile>(
      find.descendant(of: linkedVisit, matching: find.byType(ListTile)),
    );
    expect(tile.onTap, isNotNull);
    tile.onTap?.call();
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('van-order-detail-page')), findsOneWidget);
    expect(find.text('Acme Grocery'), findsOneWidget);
  });


  testWidgets('canonical Van Orders preserves collection settlement context',
      (tester) async {
    final orders = _OrderRepository()
      ..createdCount = 1
      ..executionStatus = 'out_for_delivery';
    final wallet = _OrderFlowWalletRepository(orders);

    await tester.pumpWidget(
      FoodexVanApp(
        locale: const Locale('en'),
        walletRepository: wallet,
        orderRepository: orders,
        initialSession: const VanSession(
          token: 'test-token',
          name: 'Van Operator',
          email: 'van@example.test',
          locale: 'en',
          permissions: {'van.login'},
          vanId: 7,
          assignmentId: 701,
        ),
      ),
    );
    await tester.pumpAndSettle();

    final scaffold = tester.state<ScaffoldState>(find.byType(Scaffold).last);
    scaffold.openDrawer();
    await tester.pumpAndSettle();
    final target = find.byKey(const ValueKey('van-screen-orders'));
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

    await tester.tap(find.byKey(const ValueKey('van-order-7001')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('van-order-detail-page')), findsOneWidget);

    final detailList = find.byKey(const ValueKey('van-order-detail-page'));
    final collectAction =
        find.byKey(const ValueKey('van-order-collect-action'));
    await _scrollUntilBuilt(tester, detailList, collectAction);
    await tester.ensureVisible(collectAction);
    expect(collectAction, findsOneWidget);
  });


  testWidgets('Van push tap opens finance-aware canonical B2B Order Detail',
      (tester) async {
    final alerts = StreamController<VanPushAlert>.broadcast();
    final orders = _OrderRepository()
      ..createdCount = 1
      ..executionStatus = 'out_for_delivery';
    final wallet = _OrderFlowWalletRepository(orders);
    addTearDown(alerts.close);

    await tester.pumpWidget(
      FoodexVanApp(
        locale: const Locale('en'),
        walletRepository: wallet,
        orderRepository: orders,
        pushAlerts: alerts.stream,
        initialSession: const VanSession(
          token: 'test-token',
          name: 'Van Operator',
          email: 'van@example.test',
          locale: 'en',
          permissions: {'van.login'},
          vanId: 7,
          assignmentId: 701,
        ),
      ),
    );
    await tester.pumpAndSettle();

    alerts.add(
      const VanPushAlert(
        title: 'New Van delivery',
        body: 'Open the order',
        openRequested: true,
        orderId: 7001,
        storeId: 7,
        channel: 'b2b',
        deepLink: '/van/orders/7001?channel=b2b&store_id=7',
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('van-order-detail-page')), findsOneWidget);
    expect(find.text('Acme Grocery'), findsOneWidget);

    final detailList = find.byKey(const ValueKey('van-order-detail-page'));
    final collectAction =
        find.byKey(const ValueKey('van-order-collect-action'));
    await _scrollUntilBuilt(tester, detailList, collectAction);
    await tester.ensureVisible(collectAction);
    expect(collectAction, findsOneWidget);
  });

  testWidgets('Van foreground push requires explicit Open action',
      (tester) async {
    final alerts = StreamController<VanPushAlert>.broadcast();
    final orders = _OrderRepository()..createdCount = 1;
    addTearDown(alerts.close);

    await tester.pumpWidget(
      FoodexVanApp(
        locale: const Locale('en'),
        walletRepository: const _EmptyWalletRepository(),
        orderRepository: orders,
        pushAlerts: alerts.stream,
        initialSession: const VanSession(
          token: 'test-token',
          name: 'Van Operator',
          email: 'van@example.test',
          locale: 'en',
          permissions: {'van.login'},
          vanId: 7,
          assignmentId: 701,
        ),
      ),
    );
    await tester.pumpAndSettle();

    alerts.add(
      const VanPushAlert(
        title: 'Order update',
        body: 'Tap Open',
        openRequested: false,
        orderId: 7001,
        storeId: 7,
        channel: 'b2b',
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('van-order-detail-page')), findsNothing);
    expect(find.text('Open'), findsOneWidget);
    await tester.tap(find.text('Open'));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('van-order-detail-page')), findsOneWidget);
  });

  testWidgets('Van push refuses non-B2B navigation intents', (tester) async {
    final alerts = StreamController<VanPushAlert>.broadcast();
    final orders = _OrderRepository()..createdCount = 1;
    addTearDown(alerts.close);

    await tester.pumpWidget(
      FoodexVanApp(
        locale: const Locale('en'),
        walletRepository: const _EmptyWalletRepository(),
        orderRepository: orders,
        pushAlerts: alerts.stream,
        initialSession: const VanSession(
          token: 'test-token',
          name: 'Van Operator',
          email: 'van@example.test',
          locale: 'en',
          permissions: {'van.login'},
          vanId: 7,
          assignmentId: 701,
        ),
      ),
    );
    await tester.pumpAndSettle();

    alerts.add(
      const VanPushAlert(
        title: 'Wrong channel',
        body: 'Must not open',
        openRequested: true,
        orderId: 7001,
        storeId: 7,
        channel: 'b2c',
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('van-order-detail-page')), findsNothing);
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


  testWidgets('Van Order Detail opens authoritative delivery coordinates',
      (tester) async {
    final orders = _OrderRepository()..createdCount = 1;
    double? openedLatitude;
    double? openedLongitude;

    await tester.pumpWidget(
      MaterialApp(
        home: VanOrderDetailPage(
          orderId: 7001,
          repository: orders,
          onSessionExpired: () async {},
          navigationLauncher: (latitude, longitude) async {
            openedLatitude = latitude;
            openedLongitude = longitude;
            return true;
          },
        ),
      ),
    );
    await tester.pumpAndSettle();

    final mapAction = find.byKey(const ValueKey('van-order-open-map'));
    await tester.scrollUntilVisible(
      mapAction,
      180,
      scrollable: find.descendant(
        of: find.byKey(const ValueKey('van-order-detail-page')),
        matching: find.byType(Scrollable),
      ).first,
    );
    final button = tester.widget<VanActionButton>(mapAction);
    expect(button.onPressed, isNotNull);
    button.onPressed?.call();
    await tester.pumpAndSettle();

    expect(openedLatitude, closeTo(29.3375, 0.000001));
    expect(openedLongitude, closeTo(47.6581, 0.000001));
  });


  testWidgets('Van Dashboard opens exact active B2B order', (tester) async {
    final orders = _OrderRepository()..createdCount = 1;
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

    final orderLink = find.byKey(const ValueKey('van-dashboard-order-7001'));
    await tester.scrollUntilVisible(
      orderLink,
      180,
      scrollable: find.descendant(
        of: find.byKey(const ValueKey('van-dashboard-page')),
        matching: find.byType(Scrollable),
      ).first,
    );
    expect(orderLink, findsOneWidget);
    final dashboardTile = tester.widget<ListTile>(orderLink);
    dashboardTile.onTap?.call();
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('van-order-detail-page')), findsOneWidget);
    expect(find.text('Acme Grocery'), findsOneWidget);
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
    expect(find.byKey(const ValueKey('van-orders-filter-new')), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('van-orders-filter-new')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('van-order-7001')), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('van-order-7001')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('van-order-detail-page')), findsOneWidget);
    expect(find.textContaining('Warehouse gate'), findsOneWidget);
    expect(find.byKey(const ValueKey('van-order-action-accepted')), findsOneWidget);

    final timeline = find.byKey(const ValueKey('van-order-detail-timeline'));
    await tester.scrollUntilVisible(
      timeline,
      220,
      scrollable: find.descendant(
        of: find.byKey(const ValueKey('van-order-detail-page')),
        matching: find.byType(Scrollable),
      ).first,
    );
    expect(timeline, findsOneWidget);

    await tester.scrollUntilVisible(
      find.byKey(const ValueKey('van-order-action-accepted')),
      -220,
      scrollable: find.descendant(
        of: find.byKey(const ValueKey('van-order-detail-page')),
        matching: find.byType(Scrollable),
      ).first,
    );

    final acceptedAction =
        find.byKey(const ValueKey('van-order-action-accepted'));
    final acceptedButton = tester.widget<VanActionButton>(acceptedAction);
    acceptedButton.onPressed?.call();
    await tester.pumpAndSettle();
    expect(orders.transitions, contains('accepted'));
    expect(find.byKey(const ValueKey('van-order-action-picked_up')), findsOneWidget);
    expect(find.byKey(const ValueKey('van-order-fail-action')), findsOneWidget);
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

    final exactOrder = find.byKey(const ValueKey('van-visit-open-order-801'));
    await tester.ensureVisible(exactOrder);
    await tester.tap(exactOrder);
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('van-order-detail-page')), findsOneWidget);
    Navigator.of(tester.element(find.byKey(const ValueKey('van-order-detail-page')))).pop();
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

  testWidgets('Van Order Detail completes proof failure and retry UX',
      (tester) async {
    final orders = _OrderRepository()
      ..createdCount = 1
      ..executionStatus = 'out_for_delivery';

    await tester.pumpWidget(
      MaterialApp(
        locale: const Locale('en'),
        home: VanOrderDetailPage(
          orderId: 7001,
          repository: orders,
          proofPicker: const _ProofPicker(),
          onSessionExpired: () async {},
        ),
      ),
    );
    await tester.pumpAndSettle();

    final proofAction = find.byKey(const ValueKey('van-order-proof-action'));
    expect(proofAction, findsOneWidget);
    await tester.tap(proofAction);
    await tester.pumpAndSettle();
    expect(
      find.byKey(const ValueKey('van-proof-evidence-sheet')),
      findsOneWidget,
    );

    await tester.tap(find.byKey(const ValueKey('van-proof-camera')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('van-proof-attached')), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('van-proof-submit')));
    await tester.pumpAndSettle();

    expect(orders.transitions, contains('proof_upload'));
    expect(find.byKey(const ValueKey('van-order-proof-ready')), findsOneWidget);
    expect(
      find.byKey(const ValueKey('van-order-action-delivered')),
      findsOneWidget,
    );

    await tester.tap(find.byKey(const ValueKey('van-order-fail-action')));
    await tester.pumpAndSettle();
    expect(
      find.byKey(const ValueKey('van-failure-evidence-sheet')),
      findsOneWidget,
    );

    await tester.tap(find.byKey(const ValueKey('van-failure-reason')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Other').last);
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('van-failure-submit')));
    await tester.pumpAndSettle();
    expect(
      find.byKey(const ValueKey('van-evidence-validation')),
      findsOneWidget,
    );

    await tester.enterText(
      find.byKey(const ValueKey('van-evidence-note')),
      'Entrance blocked',
    );
    await tester.tap(find.byKey(const ValueKey('van-failure-submit')));
    await tester.pumpAndSettle();

    expect(orders.executionStatus, 'failed');
    expect(orders.failureReasonCode, 'other');
    expect(
      find.byKey(const ValueKey('van-order-retry-action')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('van-order-failure-note')),
      findsOneWidget,
    );

    await tester.tap(find.byKey(const ValueKey('van-order-retry-action')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('van-retry-confirm')));
    await tester.pumpAndSettle();

    expect(orders.transitions, contains('retry'));
    expect(orders.executionStatus, 'out_for_delivery');
    expect(orders.deliveryProofReady, isFalse);
    expect(
      find.byKey(const ValueKey('van-order-proof-action')),
      findsOneWidget,
    );
  });

  testWidgets('Van Order Detail completes collection receipt return flow',
      (tester) async {
    final orders = _OrderRepository()
      ..createdCount = 1
      ..executionStatus = 'out_for_delivery'
      ..deliveryProofReady = true;
    final wallet = _OrderFlowWalletRepository(orders);

    await tester.pumpWidget(
      MaterialApp(
        locale: const Locale('en'),
        home: VanOrderDetailPage(
          orderId: 7001,
          repository: orders,
          walletRepository: wallet,
          onSessionExpired: () async {},
        ),
      ),
    );
    await tester.pumpAndSettle();

    final detailList = find.byKey(const ValueKey('van-order-detail-page'));
    final collectAction =
        find.byKey(const ValueKey('van-order-collect-action'));
    await _scrollUntilBuilt(tester, detailList, collectAction);
    expect(collectAction, findsOneWidget);
    expect(
      find.byKey(const ValueKey('van-order-action-delivered')),
      findsNothing,
    );

    await tester.tap(collectAction);
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('van-collection-page')), findsOneWidget);
    expect(find.textContaining('INV-7001'), findsWidgets);
    await tester.enterText(
      find.byKey(const ValueKey('van-collection-amount')),
      '12.000',
    );
    await tester.tap(find.byKey(const ValueKey('van-collection-submit')));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('van-collection-receipt')), findsOneWidget);
    expect(
      find.byKey(const ValueKey('van-collection-view-receipt')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('van-collection-return-order')),
      findsOneWidget,
    );

    await tester.tap(
      find.byKey(const ValueKey('van-collection-view-receipt')),
    );
    await tester.pumpAndSettle();
    expect(
      find.byKey(const ValueKey('van-receipt-focus-77')),
      findsOneWidget,
    );
    expect(find.byKey(const ValueKey('van-receipt-77')), findsOneWidget);

    await tester.pageBack();
    await tester.pumpAndSettle();
    await tester.tap(
      find.byKey(const ValueKey('van-collection-return-order')),
    );
    await tester.pumpAndSettle();

    expect(orders.invoiceOutstanding, 0);
    expect(find.byKey(const ValueKey('van-order-detail-page')), findsOneWidget);

    final lastReceipt = find.byKey(const ValueKey('van-order-last-receipt'));
    await _scrollUntilBuilt(tester, detailList, lastReceipt);
    expect(lastReceipt, findsOneWidget);
    expect(collectAction, findsNothing);

    await tester.drag(detailList, const Offset(0, 1600));
    await tester.pumpAndSettle();
    expect(
      find.byKey(const ValueKey('van-order-action-delivered')),
      findsOneWidget,
    );
  });

  testWidgets('Van Order Detail keeps account credit out of cash collection',
      (tester) async {
    final orders = _OrderRepository()
      ..createdCount = 1
      ..executionStatus = 'out_for_delivery'
      ..deliveryProofReady = true
      ..paymentMethod = 'account_credit';
    final wallet = _OrderFlowWalletRepository(orders);

    await tester.pumpWidget(
      MaterialApp(
        locale: const Locale('en'),
        home: VanOrderDetailPage(
          orderId: 7001,
          repository: orders,
          walletRepository: wallet,
          onSessionExpired: () async {},
        ),
      ),
    );
    await tester.pumpAndSettle();

    final detailList = find.byKey(const ValueKey('van-order-detail-page'));
    final creditNotice = find.byKey(
      const ValueKey('van-order-account-credit-settlement'),
    );
    await _scrollUntilBuilt(tester, detailList, creditNotice);
    expect(creditNotice, findsOneWidget);
    expect(
      find.byKey(const ValueKey('van-order-collect-action')),
      findsNothing,
    );

    await tester.drag(detailList, const Offset(0, 1600));
    await tester.pumpAndSettle();
    expect(
      find.byKey(const ValueKey('van-order-action-delivered')),
      findsOneWidget,
    );
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


class _OrderFlowWalletRepository implements VanWalletRepository {
  _OrderFlowWalletRepository(this.orders);

  final _OrderRepository orders;
  VanReceipt? _receipt;

  @override
  Future<List<VanWalletAccount>> wallet() async {
    final receipt = _receipt;
    return [
      VanWalletAccount(
        id: 5,
        storeId: 7,
        currency: 'KWD',
        status: 'active',
        custodyBalance: receipt?.amount ?? 0,
        availableToRemit: receipt?.amount ?? 0,
        receipts: receipt == null ? const [] : [receipt],
        remittances: const [],
      ),
    ];
  }

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
      VanCollectionContext(
        storeId: 7,
        invoices: orders.invoiceOutstanding <= 0.0001
            ? const []
            : [
                VanInvoiceBalance(
                  id: 7001,
                  number: 'INV-7001',
                  currency: 'KWD',
                  total: 12,
                  outstandingAmount: orders.invoiceOutstanding,
                ),
              ],
      );

  @override
  Future<VanCollectionResult> collect({
    required VanCustomerScope customer,
    required int invoiceId,
    required double amount,
    required String idempotencyKey,
  }) async {
    if (invoiceId != 7001 || amount > orders.invoiceOutstanding + 0.0001) {
      throw const VanApiException('Invalid order collection.');
    }

    final remaining = orders.invoiceOutstanding - amount;
    orders.invoiceOutstanding = remaining <= 0.0001 ? 0 : remaining;
    orders.collectionRecords.add(
      VanOrderCollectionRecord(
        status: 'posted',
        source: 'van_app',
        amount: amount,
        currency: 'KWD',
      ),
    );
    final receipt = VanReceipt(
      id: 77,
      amount: amount,
      currency: 'KWD',
      status: 'posted',
      createdAt: '2026-10-10T13:00:00+03:00',
    );
    _receipt = receipt;

    return VanCollectionResult(
      receipt: receipt,
      remainingOutstanding: orders.invoiceOutstanding,
      wallet: VanWalletAccount(
        id: 5,
        storeId: 7,
        currency: 'KWD',
        status: 'active',
        custodyBalance: amount,
        availableToRemit: amount,
        receipts: [receipt],
        remittances: const [],
      ),
    );
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
          orderId: 7001,
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
  String executionStatus = 'assigned';
  bool deliveryProofReady = false;
  String? failureReasonCode;
  String? failureNote;
  String paymentMethod = 'cash_on_delivery';
  double invoiceOutstanding = 12;
  final List<VanOrderCollectionRecord> collectionRecords = [];
  final List<String> transitions = [];

  VanOrderRecord _record() => VanOrderRecord(
        id: 7001,
        orderNumber: 'FDX-B2B-TEST-001',
        customerType: 'b2b',
        customerId: 42,
        storeId: 7,
        status: executionStatus == 'out_for_delivery'
            ? 'out_for_delivery'
            : executionStatus == 'delivered'
                ? 'delivered'
                : executionStatus == 'failed'
                    ? 'failed'
                    : 'pending',
        currency: 'KWD',
        grandTotal: 12,
        createdAt: '2026-10-07T10:00:00+03:00',
        vanExecutionStatus: executionStatus,
      );

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
    return _record();
  }

  @override
  Future<List<VanOrderRecord>> orders({
    VanCustomerScope? customer,
    String? status,
  }) async => createdCount == 0 ? const [] : [_record()];

  @override
  Future<VanOrderDetail> order(int orderId) async => VanOrderDetail(
        summary: _record(),
        paymentMethod: paymentMethod,
        customer: const VanOrderCustomer(
          name: 'Acme Grocery',
          phone: '+96550000077',
          email: 'acme@example.test',
        ),
        deliveryAddress: const VanOrderDeliveryAddress(
          formatted: 'Warehouse gate, Street 17, Shuwaikh, Kuwait City, KW',
          hasCoordinates: true,
          latitude: 29.3375,
          longitude: 47.6581,
        ),
        items: const [
          VanOrderItemRecord(
            name: 'Water Case',
            sku: 'WATER-CASE',
            quantity: 1,
            lineTotal: 12,
          ),
        ],
        invoice: VanOrderInvoiceRecord(
          id: 7001,
          number: 'INV-7001',
          status: 'issued',
          currency: 'KWD',
          total: 12,
          paidAmount: 12 - invoiceOutstanding,
          outstandingAmount: invoiceOutstanding,
        ),
        payments: const [],
        collections: List<VanOrderCollectionRecord>.unmodifiable(
          collectionRecords,
        ),
        timeline: const [
          VanOrderTimelineRecord(
            stage: 'assigned',
            status: 'assigned',
            source: 'van_assignment',
            occurredAt: '2026-10-07T10:00:00+03:00',
          ),
        ],
      );

  @override
  Future<VanOrderExecutionState> execution(int orderId) async {
    final allowed = switch (executionStatus) {
      'assigned' => const ['accepted'],
      'accepted' => const ['picked_up', 'failed'],
      'picked_up' => const ['out_for_delivery', 'failed'],
      'out_for_delivery' => const ['delivered', 'failed'],
      'failed' => const ['out_for_delivery'],
      _ => const <String>[],
    };
    return VanOrderExecutionState(
      orderId: orderId,
      orderStatus: _record().status,
      status: executionStatus,
      allowedActions: allowed,
      proofRequiredForDelivered: true,
      deliveryProofReady: deliveryProofReady,
      failureReasonCode: failureReasonCode,
      failureNote: failureNote,
      latestProof: deliveryProofReady
          ? const VanOrderProofRecord(
              id: 91,
              type: 'delivery_image',
              available: true,
              capturedAt: '2026-10-07T11:15:00+03:00',
            )
          : null,
    );
  }

  @override
  Future<VanOrderExecutionState> transitionOrder({
    required int orderId,
    required String status,
    required String idempotencyKey,
  }) async {
    transitions.add(status);
    executionStatus = status;
    if (status != 'out_for_delivery') deliveryProofReady = false;
    return execution(orderId);
  }

  @override
  Future<List<VanFailureReasonOption>> failedDeliveryReasons() async => const [
        VanFailureReasonOption(
          code: 'customer_no_answer',
          labelAr: 'العميل لا يجيب',
          labelEn: 'Customer did not answer',
        ),
        VanFailureReasonOption(
          code: 'other',
          labelAr: 'أخرى',
          labelEn: 'Other',
        ),
      ];

  @override
  Future<VanOrderExecutionState> uploadProof({
    required int orderId,
    required VanProofAttachment proof,
    required String idempotencyKey,
    String? note,
  }) async {
    transitions.add('proof_upload');
    deliveryProofReady = true;
    return execution(orderId);
  }

  @override
  Future<VanOrderExecutionState> failOrder({
    required int orderId,
    required String failureReason,
    required String idempotencyKey,
    String? note,
    VanProofAttachment? proof,
  }) async {
    transitions.add('failed');
    executionStatus = 'failed';
    failureReasonCode = failureReason;
    failureNote = note;
    deliveryProofReady = false;
    return execution(orderId);
  }

  @override
  Future<VanOrderExecutionState> retryOrder({
    required int orderId,
    required String idempotencyKey,
    String? note,
  }) async {
    transitions.add('retry');
    executionStatus = 'out_for_delivery';
    failureReasonCode = null;
    failureNote = null;
    deliveryProofReady = false;
    return execution(orderId);
  }
}

class _ProofPicker implements VanOrderProofPicker {
  const _ProofPicker();

  @override
  Future<VanProofAttachment?> pickCamera() async => const VanProofAttachment(
        path: '/tmp/van-proof.jpg',
        fileName: 'van-proof.jpg',
        byteLength: 1024,
        mimeType: 'image/jpeg',
      );

  @override
  Future<VanProofAttachment?> pickGallery() => pickCamera();
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
          orderId: 7001,
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
