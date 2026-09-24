import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/app.dart';
import 'package:foodex_customer_app/core/auth/customer_session.dart';
import 'package:foodex_customer_app/core/routing/customer_routes.dart';

void main() {
  test('reference route patterns are represented in one route registry', () {
    final patterns = customerRouteDefinitions
        .map((definition) => definition.pattern)
        .toSet();

    expect(
      patterns,
      containsAll(<String>{
        '/splash',
        '/entry',
        '/stores',
        '/home',
        '/offers',
        '/products',
        '/products/:id',
        '/cart',
        '/auth/checkout',
        '/checkout/address-payment',
        '/orders/:id/track',
        '/profile',
        '/b2b/login',
        '/b2b/dashboard',
        '/b2b/reports/purchases',
        '/b2b/products/top',
        '/b2b/invoices',
        '/b2b/invoices/:id',
        '/b2b/account-statement',
        '/b2b/orders',
        '/b2b/orders/:id',
        '/b2b/products',
        '/b2b/products/:id',
        '/b2b/cart',
        '/b2b/profile',
      }),
    );
  });

  testWidgets('guest can resolve B2C catalog routes without authentication',
      (tester) async {
    await tester.pumpWidget(
      const FoodexCustomerApp(initialRoute: '/products/42'),
    );
    await tester.pumpAndSettle();

    expect(find.text('B2C product details'), findsOneWidget);
    expect(find.text('/products/42'), findsOneWidget);
  });

  testWidgets('guest B2C protected route redirects to checkout login',
      (tester) async {
    await tester.pumpWidget(
      const FoodexCustomerApp(initialRoute: '/checkout/address-payment'),
    );
    await tester.pumpAndSettle();

    expect(find.text('B2C checkout login'), findsOneWidget);
    expect(find.text('/auth/checkout'), findsOneWidget);
  });

  testWidgets('guest B2B protected route redirects to B2B login',
      (tester) async {
    await tester.pumpWidget(
      const FoodexCustomerApp(initialRoute: '/b2b/dashboard'),
    );
    await tester.pumpAndSettle();

    expect(find.text('B2B login'), findsOneWidget);
    expect(find.text('/b2b/login'), findsOneWidget);
  });

  testWidgets('authenticated B2C session reaches B2C protected routes',
      (tester) async {
    await tester.pumpWidget(
      const FoodexCustomerApp(
        session: CustomerSession.authenticated(CustomerChannel.b2c),
        initialRoute: '/profile',
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('B2C profile'), findsOneWidget);
    expect(find.text('/profile'), findsOneWidget);
  });

  testWidgets('authenticated B2B session reaches B2B protected routes',
      (tester) async {
    await tester.pumpWidget(
      const FoodexCustomerApp(
        session: CustomerSession.authenticated(CustomerChannel.b2b),
        initialRoute: '/b2b/orders/101',
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('B2B order details'), findsOneWidget);
    expect(find.text('/b2b/orders/101'), findsOneWidget);
  });

  testWidgets('authenticated B2C session cannot enter protected B2B partition',
      (tester) async {
    await tester.pumpWidget(
      const FoodexCustomerApp(
        session: CustomerSession.authenticated(CustomerChannel.b2c),
        initialRoute: '/b2b/dashboard',
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('B2B login'), findsOneWidget);
    expect(find.text('/b2b/login'), findsOneWidget);
  });
}
