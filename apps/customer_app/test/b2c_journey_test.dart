import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/app.dart';
import 'package:foodex_customer_app/core/auth/customer_session.dart';

void main() {
  testWidgets('B2C guest product journey is RTL and exposes cart action', (tester) async {
    await tester.pumpWidget(const FoodexCustomerApp(initialRoute: '/products/42'));
    await tester.pumpAndSettle();
    expect(Directionality.of(tester.element(find.text('تفاصيل المنتج'))), TextDirection.rtl);
    expect(find.text('إضافة إلى السلة'), findsOneWidget);
  });

  testWidgets('B2C authenticated checkout exposes address payment and submit states', (tester) async {
    await tester.pumpWidget(const FoodexCustomerApp(session: CustomerSession.authenticated(CustomerChannel.b2c), initialRoute: '/checkout/address-payment'));
    await tester.pumpAndSettle();
    expect(find.text('العنوان والدفع'), findsOneWidget);
    expect(find.text('عنوان التوصيل'), findsOneWidget);
    expect(find.text('طريقة الدفع'), findsOneWidget);
    expect(find.text('تأكيد الطلب'), findsOneWidget);
  });

  testWidgets('B2C cart has explicit empty state', (tester) async {
    await tester.pumpWidget(const FoodexCustomerApp(initialRoute: '/cart'));
    await tester.pumpAndSettle();
    expect(find.text('السلة فارغة'), findsOneWidget);
  });
}
