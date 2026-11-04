import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/theme/customer_ui_v3_tokens.dart';
import 'package:foodex_customer_app/features/customer_orders/customer_order_payment_badge.dart';

void main() {
  Future<void> pumpBadge(
    WidgetTester tester, {
    required String state,
    required Locale locale,
    double? paid,
    double? remaining,
  }) async {
    await tester.pumpWidget(
      MaterialApp(
        locale: locale,
        home: Scaffold(
          body: Center(
            child: CustomerOrderPaymentBadge(
              orderId: 79,
              currency: 'EGP',
              paymentState: state,
              paidAmount: paid,
              remainingAmount: remaining,
            ),
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
  }

  testWidgets('fully paid shows green amount with no red remaining', (tester) async {
    await pumpBadge(
      tester,
      state: 'fully_settled',
      locale: const Locale('ar'),
      paid: 1500,
      remaining: 0,
    );

    expect(find.text('مدفوع بالكامل'), findsOneWidget);
    expect(find.byKey(const ValueKey('customer-order-paid-79')), findsOneWidget);
    expect(find.byKey(const ValueKey('customer-order-remaining-79')), findsNothing);
    expect(
      tester.widget<Text>(find.byKey(const ValueKey('customer-order-paid-79')))
          .style!.color,
      CustomerUiColors.success,
    );
  });

  testWidgets('partial payment shows paid green and remaining red', (tester) async {
    await pumpBadge(
      tester,
      state: 'partially_settled',
      locale: const Locale('ar'),
      paid: 500,
      remaining: 1000,
    );

    expect(find.text('مدفوع جزئياً'), findsOneWidget);
    expect(
      tester.widget<Text>(find.byKey(const ValueKey('customer-order-paid-79')))
          .style!.color,
      CustomerUiColors.success,
    );
    expect(
      tester.widget<Text>(find.byKey(const ValueKey('customer-order-remaining-79')))
          .style!.color,
      CustomerUiColors.destructive,
    );
  });

  testWidgets('English labels work and missing financial data never appears paid', (tester) async {
    await pumpBadge(
      tester,
      state: 'unpaid',
      locale: const Locale('en'),
      paid: 0,
      remaining: 90,
    );
    expect(find.text('Unpaid'), findsOneWidget);
    expect(find.byKey(const ValueKey('customer-order-remaining-79')), findsOneWidget);

    await pumpBadge(
      tester,
      state: '',
      locale: const Locale('en'),
    );
    expect(find.byKey(const ValueKey('customer-order-payment-badge-79')), findsNothing);
    expect(find.text('Fully paid'), findsNothing);
  });
}
