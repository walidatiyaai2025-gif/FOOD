import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/api/b2c_catalog_api.dart';
import 'package:foodex_customer_app/core/routing/customer_routes.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  test('B2C browsing routes stay public while account and checkout stay gated', () {
    CustomerRouteDefinition route(String pattern) =>
        customerRouteDefinitions.singleWhere(
          (definition) => definition.pattern == pattern,
        );

    for (final publicRoute in <String>[
      CustomerRoutePaths.marketplace,
      CustomerRoutePaths.stores,
      CustomerRoutePaths.home,
      CustomerRoutePaths.offers,
      CustomerRoutePaths.products,
      CustomerRoutePaths.productDetails,
      CustomerRoutePaths.cart,
    ]) {
      expect(
        route(publicRoute).requiresAuth,
        isFalse,
        reason: '$publicRoute must remain guest-accessible',
      );
    }

    for (final protectedRoute in <String>[
      CustomerRoutePaths.checkoutAddressPayment,
      CustomerRoutePaths.orderTracking,
      CustomerRoutePaths.profile,
    ]) {
      expect(
        route(protectedRoute).requiresAuth,
        isTrue,
        reason: '$protectedRoute must remain authentication-gated',
      );
    }
  });

  test('authenticated retail browse carries bearer token without changing guest routes', () async {
    http.Request? captured;
    final api = HttpB2cCatalogApi(
      baseUrl: 'https://foodex.example',
      token: 'merchant-token',
      client: MockClient((request) async {
        captured = request;
        return http.Response(
          '{"data":[]}',
          200,
          headers: {'content-type': 'application/json'},
        );
      }),
    );

    await api.products(19);

    expect(captured?.url.path, '/api/v1/stores/19/products');
    expect(captured?.headers['Authorization'], 'Bearer merchant-token');
    expect(captured?.headers['X-FOODEX-Store-ID'], '19');
  });
}
