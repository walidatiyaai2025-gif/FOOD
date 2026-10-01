import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/localization/app_translations.dart';
import 'package:foodex_customer_app/core/theme/foodex_theme.dart';
import 'package:foodex_customer_app/features/customer_orders/customer_order_models.dart';
import 'package:foodex_customer_app/features/customer_orders/customer_order_screens.dart';
import 'package:foodex_customer_app/features/customer_orders/customer_orders_api.dart';
import 'package:foodex_customer_app/shared/customer_ui_v3/customer_ui_v3.dart';

void main() {
  testWidgets('orders loading uses V3 geometry skeletons instead of a spinner',
      (tester) async {
    final pending = Completer<CustomerOrderPage>();
    final api = _FakeOrdersApi(ordersFuture: pending.future);

    await tester.pumpWidget(
      _TestApp(
        child: CustomerOrdersScreen(api: api),
      ),
    );
    await tester.pump();

    expect(find.byType(CustomerSkeletonBox), findsWidgets);
    expect(find.byType(CircularProgressIndicator), findsNothing);

    pending.complete(_page());
    await tester.pump();
    await tester.pump();
  });

  testWidgets('orders cards keep V3 hierarchy under RTL and larger text',
      (tester) async {
    final api = _FakeOrdersApi(ordersFuture: Future.value(_page()));

    await tester.pumpWidget(
      _TestApp(
        textDirection: TextDirection.rtl,
        textScale: 1.35,
        child: CustomerOrdersScreen(api: api),
      ),
    );
    await tester.pump();
    await tester.pump();

    expect(find.text('FO-91'), findsOneWidget);
    expect(find.byType(CustomerBadge), findsNWidgets(2));
    expect(find.byIcon(Icons.storefront_outlined), findsOneWidget);
    expect(tester.takeException(), isNull);
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

CustomerOrderPage _page() => CustomerOrderPage(
      orders: [
        CustomerOrderSummary(
          id: 91,
          orderNumber: 'FO-91',
          storeId: 7,
          storeName: 'Retail A',
          storeLogoUrl: null,
          channel: 'b2c',
          status: 'out_for_delivery',
          currency: 'KWD',
          grandTotal: 12.5,
          createdAt: DateTime.utc(2026, 10, 1, 10),
        ),
      ],
      currentPage: 1,
      perPage: 20,
      total: 1,
      scope: 'all',
    );

class _FakeOrdersApi implements CustomerOrdersApi {
  _FakeOrdersApi({required this.ordersFuture});

  final Future<CustomerOrderPage> ordersFuture;

  @override
  Future<CustomerOrderPage> orders({
    int page = 1,
    int perPage = 20,
    String? status,
    CustomerOrderContext? context,
  }) =>
      ordersFuture;

  @override
  Future<CustomerOrderDetails> order({
    required int orderId,
    CustomerOrderContext? context,
  }) {
    throw UnimplementedError();
  }
}
