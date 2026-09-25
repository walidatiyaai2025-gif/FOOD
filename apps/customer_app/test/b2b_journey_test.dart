import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/app.dart';
import 'package:foodex_customer_app/core/api/b2b_api.dart';
import 'package:foodex_customer_app/core/auth/customer_session.dart';

void main() {
  const b2b = CustomerSession.authenticated(CustomerChannel.b2b);

  testWidgets('B2B unauthenticated protected route redirects to login without registration', (tester) async {
    await tester.pumpWidget(const FoodexCustomerApp(initialRoute: '/b2b/dashboard'));
    await tester.pumpAndSettle();
    expect(find.text('دخول عميل الأعمال'), findsOneWidget);
    expect(find.textContaining('تُنشأ وتُعتمد'), findsOneWidget);
  });

  testWidgets('B2B approved customer dashboard is RTL and exposes finance areas', (tester) async {
    await tester.pumpWidget(const FoodexCustomerApp(session: b2b, initialRoute: '/b2b/dashboard'));
    await tester.pumpAndSettle();
    expect(Directionality.of(tester.element(find.text('لوحة الأعمال'))), TextDirection.rtl);
    expect(find.text('المشتريات'), findsOneWidget);
    expect(find.text('الفواتير'), findsOneWidget);
    expect(find.text('كشف الحساب'), findsOneWidget);
  });

  testWidgets('B2B products and cart expose authoritative pricing constraints', (tester) async {
    await tester.pumpWidget(const FoodexCustomerApp(session: b2b, initialRoute: '/b2b/products/42'));
    await tester.pumpAndSettle();
    expect(find.textContaining('الحد الأدنى'), findsOneWidget);
    expect(find.text('إضافة إلى السلة'), findsOneWidget);

    await tester.pumpWidget(const FoodexCustomerApp(session: b2b, initialRoute: '/b2b/cart'));
    await tester.pumpAndSettle();
    expect(find.text('السلة فارغة'), findsOneWidget);
    expect(find.text('إتمام الطلب'), findsOneWidget);
  });

  testWidgets('B2C session cannot enter B2B protected journey', (tester) async {
    await tester.pumpWidget(const FoodexCustomerApp(session: CustomerSession.authenticated(CustomerChannel.b2c), initialRoute: '/b2b/invoices'));
    await tester.pumpAndSettle();
    expect(find.text('دخول عميل الأعمال'), findsOneWidget);
  });

  testWidgets('B2B remote journey renders loading and empty states', (tester) async {
    final api = _FakeB2bApi(const {'data': []});
    await tester.pumpWidget(FoodexCustomerApp(session: b2b, initialRoute: '/b2b/invoices', b2bApi: api));
    expect(find.byKey(const ValueKey('b2b-loading')), findsOneWidget);
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('b2b-empty')), findsOneWidget);
    expect(api.lastPath, '/api/v1/b2b/invoices');
  });

  testWidgets('B2B remote journey renders safe error state', (tester) async {
    await tester.pumpWidget(FoodexCustomerApp(session: b2b, initialRoute: '/b2b/account-statement', b2bApi: _FailingB2bApi()));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('b2b-error')), findsOneWidget);
  });
}

class _FakeB2bApi implements B2bApi {
  _FakeB2bApi(this.value);
  final Object? value;
  String? lastPath;
  @override
  Future<Object?> get(String path) async { lastPath = path; return value; }
}

class _FailingB2bApi implements B2bApi {
  @override
  Future<Object?> get(String path) async => throw const B2bApiException('test');
}
