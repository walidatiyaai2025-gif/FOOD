import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/api/customer_action_api.dart';
import 'package:foodex_customer_app/core/localization/app_translations.dart';
import 'package:foodex_customer_app/core/theme/foodex_theme.dart';
import 'package:foodex_customer_app/features/customer_orders/customer_order_models.dart';
import 'package:foodex_customer_app/features/customer_orders/customer_order_screens.dart';
import 'package:foodex_customer_app/features/customer_orders/customer_orders_api.dart';
import 'package:foodex_customer_app/shared/customer_ui_v3/customer_ui_v3.dart';

void main() {
  testWidgets('orders loading keeps independent V3 skeleton state per channel',
      (tester) async {
    final wholesale = Completer<CustomerOrderPage>();
    final retail = Completer<CustomerOrderPage>();
    final api = _FakeOrdersApi(
      responder: (channel, page) =>
          channel == 'b2b' ? wholesale.future : retail.future,
    );

    await tester.pumpWidget(
      _TestApp(
        child: CustomerOrdersScreen(api: api),
      ),
    );
    await tester.pump();

    expect(find.byType(CustomerSkeletonBox), findsWidgets);
    expect(find.byType(CircularProgressIndicator), findsNothing);
    expect(api.calls, containsAll(<String>['b2b:1', 'b2c:1']));

    wholesale.complete(_page(channel: 'b2b', orderId: 91));
    retail.complete(_page(channel: 'b2c', orderId: 92));
    await tester.pump();
    await tester.pump();
  });

  testWidgets('orders keep compact reference hierarchy under RTL and larger text',
      (tester) async {
    final api = _FakeOrdersApi(
      responder: (channel, page) => Future.value(
        _page(
          channel: channel,
          orderId: channel == 'b2b' ? 91 : 92,
        ),
      ),
    );

    await tester.pumpWidget(
      _TestApp(
        textDirection: TextDirection.rtl,
        textScale: 1.35,
        child: CustomerOrdersScreen(api: api),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('طلباتي'), findsOneWidget);
    expect(find.text('WH-91'), findsOneWidget);
    expect(find.text('RT-92'), findsNothing);
    expect(find.byIcon(Icons.receipt_long_outlined), findsOneWidget);
    expect(tester.takeException(), isNull);

    await tester.drag(find.byType(TabBarView), const Offset(-320, 0));
    await tester.pumpAndSettle();

    expect(find.text('RT-92'), findsOneWidget);
    expect(find.text('WH-91'), findsNothing);
    expect(find.byIcon(Icons.receipt_long_outlined), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets(
      'pagination refresh and order open stay isolated by authoritative channel',
      (tester) async {
    final api = _FakeOrdersApi(
      responder: (channel, page) async {
        if (channel == 'b2b' && page == 1) {
          return _page(
            channel: 'b2b',
            orderId: 101,
            storeId: 11,
            total: 2,
          );
        }
        if (channel == 'b2b' && page == 2) {
          return _page(
            channel: 'b2b',
            orderId: 102,
            storeId: 12,
            currentPage: 2,
            total: 2,
          );
        }
        return _page(
          channel: 'b2c',
          orderId: 201,
          storeId: 21,
        );
      },
    );
    CustomerOrderSummary? opened;

    await tester.pumpWidget(
      _TestApp(
        child: CustomerOrdersScreen(
          api: api,
          onOpenOrder: (order) => opened = order,
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('WH-101'), findsOneWidget);
    expect(find.text('RT-201'), findsNothing);
    expect(
      find.byKey(const ValueKey('customer-orders-load-more-b2b')),
      findsOneWidget,
    );

    await tester.tap(
      find.byKey(const ValueKey('customer-orders-load-more-b2b')),
    );
    await tester.pumpAndSettle();

    expect(find.text('WH-101'), findsOneWidget);
    expect(find.text('WH-102'), findsOneWidget);
    expect(find.text('RT-201'), findsNothing);
    expect(api.calls, contains('b2b:2'));

    await tester.drag(find.byType(TabBarView), const Offset(-320, 0));
    await tester.pumpAndSettle();

    expect(find.text('RT-201'), findsOneWidget);
    expect(find.text('WH-101'), findsNothing);

    await tester.tap(find.byKey(const ValueKey('customer-order-201')));
    await tester.pump();

    expect(opened?.context.storeId, 21);
    expect(opened?.context.normalizedChannel, 'b2c');

    await tester.tap(find.byKey(const ValueKey('customer-orders-refresh')));
    await tester.pumpAndSettle();

    expect(
      api.calls.where((call) => call == 'b2c:1').length,
      greaterThanOrEqualTo(2),
    );
  });

  testWidgets(
      'authoritative status counts filter orders and safe reorder revalidates cart',
      (tester) async {
    final api = _FakeOrdersApi(
      responder: (channel, page) async => _page(
        channel: channel,
        orderId: channel == 'b2b' ? 301 : 401,
        status: 'delivered',
        allTotal: 3,
        statusCodes: const <String>['pending', 'delivered'],
        statusCounts: const <String, int>{'pending': 2, 'delivered': 1},
        reorderItems: channel == 'b2b'
            ? const <CustomerOrderItem>[
                CustomerOrderItem(
                  id: 1,
                  productId: 77,
                  sku: 'SKU-77',
                  name: 'Current product',
                  quantity: 4,
                  unitPrice: 2,
                  lineTotal: 8,
                ),
              ]
            : const <CustomerOrderItem>[],
      ),
    );
    final actionApi = _FakeActionApi();

    await tester.pumpWidget(
      _TestApp(
        child: CustomerOrdersScreen(
          api: api,
          actionApi: actionApi,
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('All'), findsOneWidget);
    expect(find.text('Delivered'), findsOneWidget);
    expect(find.text('Items: 1'), findsNothing);
    expect(
      find.byKey(const ValueKey('customer-order-reorder-301')),
      findsNothing,
    );

    await tester.tap(
      find.byKey(const ValueKey('customer-orders-status-b2b-delivered')),
    );
    await tester.pumpAndSettle();

    expect(api.statusCalls, contains('b2b:delivered'));

    await tester.longPress(find.byKey(const ValueKey('customer-order-301')));
    await tester.pumpAndSettle();
    expect(
      find.byKey(const ValueKey('customer-order-reorder-301')),
      findsOneWidget,
    );

    await tester.tap(
      find.byKey(const ValueKey('customer-order-reorder-301')),
    );
    await tester.pumpAndSettle();

    expect(actionApi.addCalls, <String>['7:77:4.0']);
    expect(find.textContaining('current prices and stock'), findsOneWidget);
  });

  testWidgets(
      'B2B cards separate approval from authoritative account-debt settlement',
      (tester) async {
    final api = _FakeOrdersApi(
      responder: (channel, page) async => _page(
        channel: channel,
        orderId: channel == 'b2b' ? 501 : 601,
        status: 'pending',
        approvalStatus: 'pending',
        invoiceOutstandingAmount: 70,
        appliedCustomerCreditAmount: 30,
        remainderMethod: 'account_debt',
        fullySettled: false,
      ),
    );

    await tester.pumpWidget(
      _TestApp(
        child: CustomerOrdersScreen(api: api),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('Order received'), findsOneWidget);
    expect(find.textContaining('Pending customer-service approval'), findsNothing);

    await tester.longPress(find.byKey(const ValueKey('customer-order-501')));
    await tester.pumpAndSettle();

    expect(find.textContaining('Pending customer-service approval'), findsOneWidget);
    expect(find.textContaining('Account debt 70.000 KWD'), findsOneWidget);
    expect(find.textContaining('Balance applied: 30.000 KWD'), findsOneWidget);
    expect(
      find.byKey(const ValueKey('customer-order-approval-501')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('customer-order-financial-501')),
      findsOneWidget,
    );
  });

  testWidgets(
      'approved B2B card keeps COD due separate and renders fully settled only explicitly',
      (tester) async {
    var settled = false;
    final api = _FakeOrdersApi(
      responder: (channel, page) async => _page(
        channel: channel,
        orderId: channel == 'b2b' ? 502 : 602,
        status: 'confirmed',
        approvalStatus: 'approved',
        invoiceOutstandingAmount: settled ? 0 : 15.5,
        remainderMethod: 'cash_on_delivery',
        fullySettled: settled,
      ),
    );

    await tester.pumpWidget(
      _TestApp(
        child: CustomerOrdersScreen(api: api),
      ),
    );
    await tester.pumpAndSettle();

    await tester.longPress(find.byKey(const ValueKey('customer-order-502')));
    await tester.pumpAndSettle();
    expect(find.textContaining('Approval: Approved'), findsOneWidget);
    expect(find.textContaining('Due on delivery 15.500 KWD'), findsOneWidget);

    await tester.tapAt(const Offset(4, 4));
    await tester.pumpAndSettle();

    settled = true;
    await tester.tap(find.byKey(const ValueKey('customer-orders-refresh')));
    await tester.pumpAndSettle();

    await tester.longPress(find.byKey(const ValueKey('customer-order-502')));
    await tester.pumpAndSettle();
    expect(find.textContaining('Fully settled'), findsOneWidget);
    expect(find.textContaining('Due on delivery 15.500 KWD'), findsNothing);
  });

  test('order summary consumes nested authoritative settlement without deriving debt',
      () {
    final order = CustomerOrderSummary.fromJson(
      <String, dynamic>{
        'id': 503,
        'order_number': 'WH-503',
        'store_id': 7,
        'channel': 'b2b',
        'status': 'confirmed',
        'currency': 'KWD',
        'grand_total': 100,
        'settlement': <String, dynamic>{
          'approval_status': 'approved',
          'outstanding_amount': 70,
          'balance_applied': 30,
          'remainder_method': 'account_debt',
          'fully_settled': false,
        },
      },
    );

    expect(order.approvalStatus, 'approved');
    expect(order.invoiceOutstandingAmount, 70);
    expect(order.appliedCustomerCreditAmount, 30);
    expect(order.remainderMethod, 'account_debt');
    expect(order.fullySettled, isFalse);

    final noSettlement = CustomerOrderSummary.fromJson(
      <String, dynamic>{
        'id': 504,
        'order_number': 'WH-504',
        'store_id': 7,
        'channel': 'b2b',
        'status': 'confirmed',
        'currency': 'KWD',
        'grand_total': 100,
      },
    );
    expect(noSettlement.invoiceOutstandingAmount, isNull);
    expect(noSettlement.fullySettled, isNull);
  });

  testWidgets('cross-channel API leakage is never rendered in the wrong tab',
      (tester) async {
    final api = _FakeOrdersApi(
      responder: (channel, page) async => channel == 'b2b'
          ? _page(channel: 'b2c', orderId: 999)
          : _page(channel: 'b2c', orderId: 201),
    );

    await tester.pumpWidget(
      _TestApp(
        child: CustomerOrdersScreen(api: api),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('RT-999'), findsNothing);
    expect(find.byType(CustomerStateView), findsOneWidget);
  });
}

class _TestApp extends StatelessWidget {
  const _TestApp({
    required this.child,
    this.textDirection = TextDirection.ltr,
    this.textScale = 1,
  });

  final Widget child;
  final TextDirection textDirection;
  final double textScale;

  @override
  Widget build(BuildContext context) => AppTranslations(
        locale: textDirection == TextDirection.rtl
            ? const Locale('ar')
            : const Locale('en'),
        overrides: const {},
        child: MaterialApp(
          theme: FoodexTheme.light(fontFamily: 'sans'),
          home: MediaQuery(
            data: MediaQueryData(
              size: const Size(360, 800),
              textScaler: TextScaler.linear(textScale),
            ),
            child: Directionality(
              textDirection: textDirection,
              child: child,
            ),
          ),
        ),
      );
}

CustomerOrderPage _page({
  required String channel,
  required int orderId,
  int storeId = 7,
  int currentPage = 1,
  int total = 1,
  int? allTotal,
  String status = 'out_for_delivery',
  List<String> statusCodes = const <String>[],
  Map<String, int> statusCounts = const <String, int>{},
  List<CustomerOrderItem> reorderItems = const <CustomerOrderItem>[],
  String? approvalStatus,
  double? invoiceOutstandingAmount,
  double? appliedCustomerCreditAmount,
  String? remainderMethod,
  bool? fullySettled,
}) =>
    CustomerOrderPage(
      orders: [
        CustomerOrderSummary(
          id: orderId,
          orderNumber: channel == 'b2b' ? 'WH-$orderId' : 'RT-$orderId',
          storeId: storeId,
          storeName: channel == 'b2b' ? 'Wholesale A' : 'Retail A',
          storeLogoUrl: null,
          channel: channel,
          status: status,
          currency: 'KWD',
          grandTotal: 12.5,
          createdAt: DateTime.utc(2026, 10, 1, 10),
          itemCount: reorderItems.length,
          reorderItems: reorderItems,
          approvalStatus: approvalStatus,
          invoiceOutstandingAmount: invoiceOutstandingAmount,
          appliedCustomerCreditAmount: appliedCustomerCreditAmount,
          remainderMethod: remainderMethod,
          fullySettled: fullySettled,
        ),
      ],
      currentPage: currentPage,
      perPage: 20,
      total: total,
      allTotal: allTotal ?? total,
      statusCodes: statusCodes,
      statusCounts: statusCounts,
      scope: channel,
    );

class _FakeOrdersApi implements CustomerOrdersApi {
  _FakeOrdersApi({required this.responder});

  final Future<CustomerOrderPage> Function(String channel, int page) responder;
  final List<String> calls = <String>[];
  final List<String> statusCalls = <String>[];

  @override
  Future<CustomerOrderPage> orders({
    int page = 1,
    int perPage = 20,
    String? status,
    String? channel,
    CustomerOrderContext? context,
  }) {
    final resolvedChannel = channel ?? context?.normalizedChannel ?? 'b2c';
    calls.add('$resolvedChannel:$page');
    statusCalls.add('$resolvedChannel:${status ?? 'all'}');
    return responder(resolvedChannel, page);
  }

  @override
  Future<CustomerOrderDetails> order({
    required int orderId,
    CustomerOrderContext? context,
  }) {
    throw UnimplementedError();
  }
}


class _FakeActionApi implements CustomerActionApi {
  final List<String> addCalls = <String>[];

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
    addCalls.add('$storeId:$productId:$quantity');
    return const <String, dynamic>{'ok': true};
  }

  @override
  Future<Object?> checkout({
    required int addressId,
    int? storeId,
    String? paymentMethod,
    String? couponCode,
    required String idempotencyKey,
  }) async =>
      const <String, dynamic>{'ok': true};
}
