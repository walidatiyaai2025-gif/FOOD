import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/localization/app_translations.dart';
import 'package:foodex_customer_app/features/customer_orders/customer_order_models.dart';
import 'package:foodex_customer_app/features/customer_orders/customer_order_screens.dart';
import 'package:foodex_customer_app/features/customer_orders/customer_orders_api.dart';

void main() {
  testWidgets(
    'lifecycle notification refetches authoritative order instead of trusting payload',
    (tester) async {
      final notifications = StreamController<Map<String, dynamic>>();
      final api = _FakeOrdersApi([
        _details('pending'),
        _details('preparing'),
      ]);

      await tester.pumpWidget(
        AppTranslations(
          locale: const Locale('en'),
          overrides: const {},
          child: MaterialApp(
            home: CustomerOrderTrackingScreen(
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
      await tester.pumpAndSettle();

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

      await tester.pumpWidget(const SizedBox.shrink());
      await notifications.close();
    },
  );
}

CustomerOrderDetails _details(String status) => CustomerOrderDetails(
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
    CustomerOrderContext? context,
  }) {
    throw UnimplementedError();
  }
}
