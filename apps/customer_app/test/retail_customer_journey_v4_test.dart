import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/auth/customer_session.dart';
import 'package:foodex_customer_app/core/routing/customer_commerce_context.dart';
import 'package:foodex_customer_app/core/routing/customer_routes.dart';

void main() {
  group('Customer Commerce V4 Lane G Retail journey', () {
    const retailA = CustomerCommerceContext(
      channel: CustomerCommerceChannel.retail,
      storeId: 7,
      source: CustomerCommerceSource.retailBanner,
      entryPlacementId: 701,
    );

    const retailB = CustomerCommerceContext(
      channel: CustomerCommerceChannel.retail,
      storeId: 8,
      source: CustomerCommerceSource.retailBanner,
      entryPlacementId: 801,
    );

    test(
      'Dashboard banner provenance and exact Retail store survive the supported route chain',
      () {
        final home = CustomerRouteLocations.retailHome(retailA);
        final product = CustomerRouteLocations.retailProduct(retailA, 42);
        final cart = CustomerRouteLocations.retailCart(retailA);
        final checkout = CustomerRouteLocations.retailCheckout(retailA);
        final auth = CustomerRouteLocations.authHandoff(
          context: retailA,
          next: checkout,
        );

        for (final location in <String>[home, product, cart, checkout, auth]) {
          final parsed = CustomerCommerceContext.tryParseLocation(location);
          expect(parsed, isNotNull, reason: location);
          expect(parsed!.sameEntry(retailA), isTrue, reason: location);
          expect(parsed.storeId, 7, reason: location);
          expect(parsed.entryPlacementId, 701, reason: location);
        }

        final authUri = Uri.parse(auth);
        expect(authUri.path, CustomerRoutePaths.checkoutAuth);
        expect(authUri.queryParameters['next'], checkout);
        expect(
          <String>[home, product, cart, checkout, auth],
          isNot(contains(CustomerRoutePaths.storeSelector)),
        );
      },
    );

    test('signed-out auth return cannot switch Retail Store A into Store B', () {
      final retailACheckout = CustomerRouteLocations.retailCheckout(retailA);
      final retailBCheckout = CustomerRouteLocations.retailCheckout(retailB);

      expect(
        safeCustomerContextReturnLocation(
          retailACheckout,
          context: retailA,
        ),
        retailACheckout,
      );
      expect(
        safeCustomerContextReturnLocation(
          retailBCheckout,
          context: retailA,
        ),
        isNull,
      );
    });

    test('conflicting deep-link store identity is rejected before journey build', () {
      expect(
        CustomerCommerceContext.tryParseLocation(
          '/retail/7/products/42?channel=retail&store_id=8'
          '&source=retail_banner&placement_id=701',
        ),
        isNull,
      );
    });

    test('one platform Customer session remains valid for Retail and Wholesale', () {
      const session = CustomerSession.platformCustomer(
        accessToken: 'platform-customer-token',
      );

      expect(session.isAuthenticated, isTrue);
      expect(session.allowsChannel(CustomerChannel.b2c), isTrue);
      expect(session.allowsChannel(CustomerChannel.b2b), isTrue);
    });
  });
}
