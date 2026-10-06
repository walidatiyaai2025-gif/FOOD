import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/localization/app_translations.dart';
import 'package:foodex_customer_app/features/customer_orders/customer_order_models.dart';
import 'package:foodex_customer_app/features/customer_orders/customer_order_screens.dart';
import 'package:foodex_customer_app/features/customer_orders/customer_orders_api.dart';

void main() {
  testWidgets(
    'order detail refetches authoritative collection state after lifecycle notification',
    (tester) async {
      final notifications = StreamController<Map<String, dynamic>>();
      final api = _FakeOrdersApi([
        _details('pending'),
        _details(
          'preparing',
          outstanding: 7.5,
          collectionReceipts: [
            CustomerCollectionReceipt(
              id: 44,
              paymentId: 81,
              status: 'posted',
              amount: 5,
              currency: 'KWD',
              source: 'driver_delivery',
              collectedAt: DateTime.utc(2026, 10, 1, 10, 15),
            ),
          ],
        ),
      ]);

      await tester.pumpWidget(
        AppTranslations(
          locale: const Locale('en'),
          overrides: const {},
          child: MaterialApp(
            home: CustomerOrderDetailsScreen(
              api: api,
              orderId: 91,
              orderContext: const CustomerOrderContext(
                storeId: 7,
                channel: 'b2c',
              ),
              lifecycleNotifications: notifications.stream,
            ),
          ),
        ),
      );
      // The tracking screen deliberately keeps an open-order polling timer.
      // Pump only the bounded async load instead of waiting for the scheduler
      // to settle, which cannot happen while polling remains enabled.
      await tester.pump();
      await tester.pump();

      expect(api.detailCalls, 1);
      expect(find.text('Order received'), findsWidgets);

      notifications.add({
        'order_id': 91,
        'store_id': 7,
        'channel': 'b2c',
        'status': 'delivered',
      });
      await tester.pump();
      await tester.pump();

      expect(api.detailCalls, 2);
      expect(find.text('Preparing'), findsWidgets);
      expect(find.text('Delivered'), findsNothing);
      expect(find.text('Collection receipts'), findsOneWidget);
      expect(find.text('Collection receipt #44'), findsOneWidget);
      expect(find.text('Remaining balance'), findsOneWidget);
      expect(find.text('7.500 KWD'), findsOneWidget);
      expect(find.text('5.000 KWD'), findsOneWidget);

      // Close the notification stream while the widget is still subscribed and
      // advance fake async once so the done event is delivered. Awaiting
      // StreamController.close() after widget disposal can deadlock the widget
      // test because subscription cancellation is itself scheduled in fake time.
      unawaited(notifications.close());
      await tester.pump();
      await tester.pumpWidget(const SizedBox.shrink());
    },
  );
}

CustomerOrderDetails _details(
  String status, {
  double? outstanding,
  List<CustomerCollectionReceipt> collectionReceipts =
      const <CustomerCollectionReceipt>[],
}) =>
    CustomerOrderDetails(
      summary: CustomerOrderSummary(
        id: 91,
        orderNumber: 'FO-91',
        storeId: 7,
        storeName: 'Retail A',
        storeLogoUrl: null,
        channel: 'b2c',
        status: status,
        currency: 'KWD',
        grandTotal: 12.5,
        createdAt: DateTime.utc(2026, 10, 1, 10),
        invoiceOutstandingAmount: outstanding,
      ),
      subtotal: 10,
      discountTotal: 0,
      deliveryTotal: 2.5,
      paymentMethod: 'cash',
      deliveryAddress: const {'area': 'Bayan'},
      items: const [],
      history: const [],
      payment: null,
      requestedDeliveryDate: null,
      collectionReceipts: collectionReceipts,
    );

class _FakeOrdersApi implements CustomerOrdersApi {
  _FakeOrdersApi(this.details);

  final List<CustomerOrderDetails> details;
  int detailCalls = 0;

  @override
  Future<CustomerOrderDetails> order({
    required int orderId,
    CustomerOrderContext? context,
  }) async {
    final index = detailCalls < details.length
        ? detailCalls
        : details.length - 1;
    detailCalls += 1;
    return details[index];
  }

  @override
  Future<CustomerOrderPage> orders({
    int page = 1,
    int perPage = 20,
    String? status,
    String? channel,
    CustomerOrderContext? context,
  }) {
    throw UnimplementedError();
  }
}
