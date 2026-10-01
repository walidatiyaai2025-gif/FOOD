import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/routing/customer_commerce_context.dart';
import 'package:foodex_customer_app/core/routing/customer_route_location.dart';
import 'package:foodex_customer_app/core/routing/customer_routes.dart';

void main() {
  group('CustomerRouteLocation', () {
    test('builds and parses canonical Retail routes without hand-built ids', () {
      final route = CustomerRouteLocation.retailOrderTracking(7, 42);
      final parsed = CustomerRouteLocation.tryParse(route);

      expect(route, '/retail/7/orders/42/track');
      expect(parsed?.definition.pattern, CustomerRoutePaths.retailOrderTracking);
      expect(parsed?.pathParameters['store'], '7');
      expect(parsed?.pathParameters['order'], '42');
      expect(
        parsed?.commerceContext,
        const CustomerCommerceContext.retail(storeId: 7),
      );
    });

    test('login and register preserve exact store context and return route', () {
      const context = CustomerCommerceContext.retail(storeId: 7);
      final next = CustomerRouteLocation.retailCheckout(7);

      final login = Uri.parse(
        CustomerRouteLocation.authLogin(context: context, next: next),
      );
      final register = Uri.parse(
        CustomerRouteLocation.authRegister(context: context, next: next),
      );

      expect(login.path, CustomerRoutePaths.authLogin);
      expect(login.queryParameters['channel'], 'b2c');
      expect(login.queryParameters['store'], '7');
      expect(login.queryParameters['next'], next);
      expect(register.path, CustomerRoutePaths.authRegister);
      expect(register.queryParameters['store'], '7');
      expect(register.queryParameters['next'], next);
    });

    test('rejects cross-store return route', () {
      const expected = CustomerCommerceContext.retail(storeId: 7);

      expect(
        CustomerRouteLocation.safeReturnLocation(
          CustomerRouteLocation.retailCheckout(8),
          expectedContext: expected,
        ),
        isNull,
      );
    });

    test('rejects malicious external and auth-loop returns', () {
      const expected = CustomerCommerceContext.retail(storeId: 7);

      expect(
        CustomerRouteLocation.safeReturnLocation(
          'https://evil.example/retail/7/cart',
          expectedContext: expected,
        ),
        isNull,
      );
      expect(
        CustomerRouteLocation.safeReturnLocation(
          '//evil.example/retail/7/cart',
          expectedContext: expected,
        ),
        isNull,
      );
      expect(
        () => CustomerRouteLocation.authLogin(
          context: expected,
          next: '/auth/login?channel=b2c&store=7',
        ),
        throwsArgumentError,
      );
    });

    test('legacy ambiguous cart is not accepted as a scoped return', () {
      expect(
        CustomerRouteLocation.safeReturnLocation(
          CustomerRoutePaths.cart,
          expectedContext:
              const CustomerCommerceContext.retail(storeId: 7),
        ),
        isNull,
      );
    });
  });
}
