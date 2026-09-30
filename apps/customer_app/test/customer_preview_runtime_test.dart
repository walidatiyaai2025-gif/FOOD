import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/app.dart';
import 'package:foodex_customer_app/core/api/b2c_account_api.dart';
import 'package:foodex_customer_app/core/api/b2c_catalog_api.dart';
import 'package:foodex_customer_app/core/api/storefront_api.dart';
import 'package:foodex_customer_app/core/auth/customer_session.dart';
import 'package:foodex_customer_app/core/preview/customer_preview_context.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  test('resolved customer preview identity never becomes a bearer token', () {
    final context = CustomerPreviewContext.fromResolvedSession({
      'session_id': 'preview-session',
      'target_type': 'customer',
      'channel': 'b2c',
      'store_id': 7,
      'read_only': true,
      'audit_correlation_id': 'audit-1',
      'expires_at': '2026-09-30T09:00:00Z',
      'target': {
        'user_id': 44,
        'name': 'Preview Customer',
        'locale': 'en',
      },
    });

    expect(context.authenticated, isTrue);
    expect(context.channel, CustomerChannel.b2c);
    expect(context.storeId, 7);
    expect(context.mutationsAllowed, isFalse);
    expect(context.runtimeIdentity.isAuthenticated, isTrue);
    expect(context.runtimeIdentity.channel, CustomerChannel.b2c);
    expect(context.runtimeIdentity.accessToken, isNull);
    expect(context.runtimeIdentity.platformWide, isFalse);
  });

  test('customer preview rejects driver and writable session contexts', () {
    expect(
      () => CustomerPreviewContext.fromResolvedSession({
        'target_type': 'driver',
        'channel': 'b2c',
        'store_id': 7,
        'read_only': true,
        'target': {'user_id': 1},
      }),
      throwsFormatException,
    );

    expect(
      () => CustomerPreviewContext.fromResolvedSession({
        'target_type': 'customer',
        'channel': 'b2c',
        'store_id': 7,
        'read_only': false,
        'target': {'user_id': 1},
      }),
      throwsFormatException,
    );
  });

  test('retail preview catalog cannot cross the selected store', () async {
    final context = CustomerPreviewContext.guest(
      channel: CustomerChannel.b2c,
      storeId: 7,
    );
    final api = PreviewB2cCatalogApi(_CatalogFake(), context);

    final stores = await api.stores();
    expect(stores.map((store) => store.id), [7]);

    expect(
      () => api.categories(8),
      throwsA(isA<CustomerPreviewScopeException>()),
    );
  });

  test('safe preview blocks commercial mutations before any delegate call',
      () async {
    const actionApi = PreviewCustomerActionApi();

    await expectLater(
      actionApi.addCartItem(storeId: 7, productId: 10, quantity: 1),
      throwsA(isA<CustomerPreviewMutationBlocked>()),
    );
    await expectLater(
      actionApi.checkout(
        addressId: 2,
        storeId: 7,
        idempotencyKey: 'preview-test',
      ),
      throwsA(isA<CustomerPreviewMutationBlocked>()),
    );
  });

  test('Marketplace preview bridge allows reads and blocks writes', () async {
    final seen = <String>[];
    final client = PreviewReadOnlyHttpClient(
      MockClient((request) async {
        seen.add('${request.method} ${request.url.path}');
        return http.Response('{"data":[]}', 200);
      }),
    );

    final response = await client.get(
      Uri.parse('https://ignored.example/api/v1/platform/storefront'),
    );
    expect(response.statusCode, 200);
    expect(seen, ['GET /api/v1/platform/storefront']);

    await expectLater(
      client.post(
        Uri.parse('https://ignored.example/api/v1/auth/register'),
        body: '{}',
      ),
      throwsA(isA<CustomerPreviewMutationBlocked>()),
    );
    expect(seen, ['GET /api/v1/platform/storefront']);
  });

  test('guest Wholesale preview does not require authenticated B2B adapters', () {
    final context = CustomerPreviewContext.guest(
      channel: CustomerChannel.b2b,
      storeId: 1,
      targetLocale: 'ar',
    );

    final app = FoodexCustomerApp.preview(
      previewContext: context,
      b2cCatalogApi: _CatalogFake(),
      b2cAccountApi: _AccountFake(),
      storefrontApi: _StorefrontFake(),
      marketplaceClient: MockClient(
        (request) async => http.Response('{"data":[]}', 200),
      ),
    );

    expect(app.session.isAuthenticated, isFalse);
    expect(app.session.accessToken, isNull);
    expect(app.b2bApi, isNull);
    expect(app.wholesaleCommerceApi, isNull);
  });

  test('preview factory keeps native and production fallbacks disabled', () {
    final context = CustomerPreviewContext.guest(
      channel: CustomerChannel.b2c,
      storeId: 7,
      targetLocale: 'en',
    );

    final app = FoodexCustomerApp.preview(
      previewContext: context,
      b2cCatalogApi: _CatalogFake(),
      b2cAccountApi: _AccountFake(),
      storefrontApi: _StorefrontFake(),
      marketplaceClient: MockClient(
        (request) async => http.Response('{"data":[]}', 200),
      ),
    );

    expect(app.session.accessToken, isNull);
    expect(app.previewContext, same(context));
    expect(app.pushService, isNull);
    expect(app.actionApi, isA<PreviewCustomerActionApi>());
    expect(app.b2cCatalogApi, isA<PreviewB2cCatalogApi>());
    expect(app.b2cAccountApi, isA<PreviewB2cAccountApi>());
    expect(app.storefrontApi, isA<PreviewStorefrontApi>());
    expect(app.locationService, isA<PreviewCustomerLocationService>());
    expect(app.mapPinPicker, same(previewCustomerMapPinPicker));
    expect(app.marketplaceClient, isA<PreviewReadOnlyHttpClient>());
    expect(app.marketplaceBarcodeScanner, isNotNull);
  });
}

class _CatalogFake implements B2cCatalogApi {
  @override
  Future<List<B2cStore>> stores() async => const [
        B2cStore(id: 7, name: 'Allowed', code: 'ALLOWED'),
        B2cStore(id: 8, name: 'Other', code: 'OTHER'),
      ];

  @override
  Future<List<B2cCategory>> categories(int storeId) async => const [];

  @override
  Future<List<B2cProduct>> products(
    int storeId, {
    String? query,
    int? categoryId,
    String? sort,
    String? direction,
  }) async =>
      const [];

  @override
  Future<List<B2cOffer>> offers(int storeId) async => const [];

  @override
  Future<List<B2cBanner>> banners(int storeId) async => const [];

  @override
  Future<B2cProduct> product(int productId, {required int storeId}) async =>
      const B2cProduct(id: 1, name: 'Product', sku: 'SKU');
}

class _AccountFake implements B2cAccountApi {
  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

class _StorefrontFake implements StorefrontApi {
  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}
