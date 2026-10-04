import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/api/b2c_catalog_api.dart';
import 'package:foodex_customer_app/core/api/storefront_api.dart';
import 'package:foodex_customer_app/core/auth/customer_session.dart';
import 'package:foodex_customer_app/core/preview/customer_preview_context.dart';
import 'package:foodex_customer_app/core/preview/customer_preview_transport.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  test('B2C authenticated preview rewrites read and sends credential header only',
      () async {
    const credential = 'opaque-preview-secret';
    late http.Request seen;

    final context = CustomerPreviewContext.fromResolvedSession({
      'session_id': 'session-1',
      'target_type': 'customer',
      'channel': 'b2c',
      'store_id': 7,
      'read_only': true,
      'target': {
        'user_id': 44,
        'name': 'Preview Customer',
        'locale': 'en',
      },
    });

    final bundle = CustomerPreviewApiBundle(
      baseUrl: 'https://foodex.example',
      context: context,
      credential: credential,
      client: MockClient((request) async {
        seen = request;
        return http.Response(
          jsonEncode({
            'user': {'id': 44, 'name': 'Preview Customer'},
            'customer': {'id': 11, 'store_id': 7},
            'channel': 'b2c',
          }),
          200,
          headers: const {'content-type': 'application/json'},
        );
      }),
    );

    final response = await bundle.account.profile();

    expect(response, isA<Map>());
    expect(seen.method, 'GET');
    expect(seen.url.path, '/api/v1/app-preview/customer/profile');
    expect(seen.headers['x-foodex-preview-token'], credential);
    expect(seen.headers.containsKey('authorization'), isFalse);
    expect(seen.url.queryParameters.containsKey('preview_token'), isFalse);
    expect(seen.url.queryParameters.containsKey('credential'), isFalse);

    bundle.close();
  });

  test('authenticated campaign eligibility uses the scoped preview bridge',
      () async {
    const credential = 'opaque-preview-secret';
    late http.Request seen;
    final client = CustomerPreviewReadHttpClient(
      MockClient((request) async {
        seen = request;
        return http.Response(
          jsonEncode({'data': const <Object>[]}),
          200,
          headers: const {'content-type': 'application/json'},
        );
      }),
      credential: credential,
      channel: CustomerChannel.b2c,
    );

    final response = await client.get(
      Uri.parse(
        'https://foodex.example/api/v1/notification-campaign-popups'
        '?channel=b2c&store_id=7&locale=en&install_id=preview-install',
      ),
    );

    expect(response.statusCode, 200);
    expect(
      seen.url.path,
      '/api/v1/app-preview/customer/notification-campaign-popups',
    );
    expect(seen.url.queryParameters['channel'], 'b2c');
    expect(seen.url.queryParameters['store_id'], '7');
    expect(seen.headers['x-foodex-preview-token'], credential);
    expect(seen.headers.containsKey('authorization'), isFalse);
  });

  test('preview event feed keeps credential header-only and preserves cursor',
      () async {
    const credential = 'opaque-preview-secret';
    late http.Request seen;
    final client = CustomerPreviewReadHttpClient(
      MockClient((request) async {
        seen = request;
        return http.Response(
          'retry: 3000\\n\\n: heartbeat test\\n\\n',
          200,
          headers: const {'content-type': 'text/event-stream'},
        );
      }),
      credential: credential,
      channel: CustomerChannel.b2c,
    );

    final response = await client.get(
      Uri.parse('https://foodex.example/api/v1/app-preview/events'),
      headers: const {
        'Accept': 'text/event-stream',
        'Last-Event-ID': '123',
      },
    );

    expect(response.statusCode, 200);
    expect(seen.url.path, '/api/v1/app-preview/events');
    expect(seen.url.query, isEmpty);
    expect(seen.headers['x-foodex-preview-token'], credential);
    expect(seen.headers['Last-Event-ID'], '123');
    expect(seen.headers.containsKey('authorization'), isFalse);
    expect(seen.url.queryParameters.containsKey('preview_token'), isFalse);
    expect(seen.url.queryParameters.containsKey('credential'), isFalse);
  });

  test('public catalog reads never receive preview credential or auth sentinel',
      () async {
    const credential = 'opaque-preview-secret';
    late http.Request seen;

    final client = CustomerPreviewReadHttpClient(
      MockClient((request) async {
        seen = request;
        return http.Response(
          jsonEncode({'data': const <Object>[]}),
          200,
          headers: const {'content-type': 'application/json'},
        );
      }),
      credential: credential,
      channel: CustomerChannel.b2c,
    );

    final request = http.Request(
      'GET',
      Uri.parse('https://foodex.example/api/v1/stores/7/products'),
    )
      ..headers['Authorization'] = 'Bearer must-not-leave-preview-host'
      ..headers['Cookie'] = 'secret=cookie';

    final response = await client.send(request);
    await response.stream.drain<void>();

    expect(seen.url.path, '/api/v1/stores/7/products');
    expect(seen.headers.containsKey('x-foodex-preview-token'), isFalse);
    expect(seen.headers.containsKey('authorization'), isFalse);
    expect(seen.headers.containsKey('cookie'), isFalse);
  });

  test('retail preview consumes production out-of-stock payload unchanged',
      () async {
    late http.Request seen;
    final transport = CustomerPreviewReadHttpClient(
      MockClient((request) async {
        seen = request;
        return http.Response(
          jsonEncode({
            'data': [
              {
                'id': 99,
                'name': 'Zero Stock',
                'sku': 'OOS-99',
                'price': 2.5,
                'currency': 'KWD',
                'available_quantity': 0,
                'is_available': false,
                'availability_state': 'OUT_OF_STOCK',
              },
            ],
          }),
          200,
          headers: const {'content-type': 'application/json'},
        );
      }),
      credential: 'opaque-preview-secret',
      channel: CustomerChannel.b2c,
    );
    final api = HttpB2cCatalogApi(
      baseUrl: 'https://foodex.example',
      client: transport,
    );

    final products = await api.products(7);

    expect(seen.url.path, '/api/v1/stores/7/products');
    expect(seen.headers.containsKey('x-foodex-preview-token'), isFalse);
    expect(products, hasLength(1));
    expect(products.single.availableQuantity, 0);
    expect(products.single.isAvailable, isFalse);
    expect(products.single.availabilityState, 'OUT_OF_STOCK');
    expect(products.single.isOutOfStock, isTrue);
  });

  test('preview transport blocks mutations and unmapped authenticated paths',
      () async {
    var sends = 0;
    final client = CustomerPreviewReadHttpClient(
      MockClient((request) async {
        sends++;
        return http.Response('{}', 200);
      }),
      credential: 'opaque-preview-secret',
      channel: CustomerChannel.b2c,
    );

    await expectLater(
      client.post(
        Uri.parse('https://foodex.example/api/v1/profile'),
        body: '{}',
      ),
      throwsA(isA<CustomerPreviewMutationBlocked>()),
    );

    await expectLater(
      client.get(
        Uri.parse('https://foodex.example/api/v1/admin/security/users'),
      ),
      throwsA(isA<CustomerPreviewScopeException>()),
    );

    await expectLater(
      client.get(
        Uri.parse('https://foodex.example/api/v1/b2b/products?store_id=7'),
      ),
      throwsA(isA<CustomerPreviewScopeException>()),
    );

    expect(sends, 0);
  });

  test('B2B preview maps real Customer journey reads to B2B preview prefix',
      () async {
    const credential = 'b2b-preview-secret';
    final paths = <String>[];
    final headers = <Map<String, String>>[];

    final client = CustomerPreviewReadHttpClient(
      MockClient((request) async {
        paths.add(request.url.path);
        headers.add({
          for (final entry in request.headers.entries)
            entry.key.toLowerCase(): entry.value,
        });
        return http.Response(
          jsonEncode({'data': const <Object>[]}),
          200,
          headers: const {'content-type': 'application/json'},
        );
      }),
      credential: credential,
      channel: CustomerChannel.b2b,
    );

    for (final path in const [
      '/api/v1/b2b/dashboard',
      '/api/v1/b2b/reports/purchases',
      '/api/v1/b2b/products/top',
      '/api/v1/b2b/products',
      '/api/v1/b2b/invoices',
      '/api/v1/b2b/account-statement',
      '/api/v1/b2b/orders',
      '/api/v1/profile',
      '/api/v1/cart',
    ]) {
      final response =
          await client.get(Uri.parse('https://foodex.example$path'));
      expect(response.statusCode, 200);
    }

    expect(
      paths,
      [
        '/api/v1/b2b/app-preview/customer/dashboard',
        '/api/v1/b2b/app-preview/customer/reports/purchases',
        '/api/v1/b2b/app-preview/customer/products/top',
        '/api/v1/b2b/app-preview/customer/products',
        '/api/v1/b2b/app-preview/customer/invoices',
        '/api/v1/b2b/app-preview/customer/account-statement',
        '/api/v1/b2b/app-preview/customer/orders',
        '/api/v1/b2b/app-preview/customer/profile',
        '/api/v1/b2b/app-preview/customer/cart',
      ],
    );
    expect(
      headers.every(
        (row) =>
            row['x-foodex-preview-token'] == credential &&
            !row.containsKey('authorization'),
      ),
      isTrue,
    );
  });

  test('Retail preview selector cannot expose another store or Wholesale',
      () async {
    final context = CustomerPreviewContext.guest(
      channel: CustomerChannel.b2c,
      storeId: 7,
    );
    final api = PreviewStorefrontApi(_StorefrontFake(), context);

    final selection = await api.selection();

    final retail = (selection['retail_stores'] as List)
        .whereType<Map>()
        .toList(growable: false);
    expect(retail, hasLength(1));
    expect(retail.single['id'], 7);
    expect(selection['wholesale_stores'], isEmpty);
    expect(
      (selection['entitlements'] as Map)['retail_context_ids'],
      [7],
    );
  });

  test('guest preview never creates authenticated preview transport bundle', () {
    final context = CustomerPreviewContext.guest(
      channel: CustomerChannel.b2c,
      storeId: 7,
    );

    expect(
      () => CustomerPreviewApiBundle(
        baseUrl: 'https://foodex.example',
        context: context,
        credential: 'must-not-be-used-for-guest',
        client: MockClient((_) async => http.Response('{}', 200)),
      ),
      throwsArgumentError,
    );
  });

}

class _StorefrontFake implements StorefrontApi {
  @override
  Future<Map<String, dynamic>> selection({
    String? countryCode,
    String? city,
    String? area,
    bool support = false,
  }) async =>
      {
        'retail_stores': [
          {'id': 7, 'name': 'Allowed'},
          {'id': 8, 'name': 'Other'},
        ],
        'wholesale_stores': [
          {'id': 1, 'name': 'Wholesale'},
        ],
        'entitlements': {
          'direct_b2b': true,
          'retail_context_ids': [7, 8],
          'support_access': true,
        },
      };

  @override
  Future<Map<String, dynamic>> retailHome(int storeId) async => {};

  @override
  Future<Map<String, dynamic>> wholesaleHome(int storeId) async => {};

  @override
  Future<Map<String, dynamic>> b2bCheckoutOptions(int storeId) async => {};
}
