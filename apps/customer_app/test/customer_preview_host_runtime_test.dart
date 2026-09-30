import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/app.dart';
import 'package:foodex_customer_app/core/preview/customer_preview_bootstrap.dart';
import 'package:foodex_customer_app/core/preview/customer_preview_runtime.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  const origin = 'https://foodex.50sols.com';

  Map<String, dynamic> bootstrapMessage({
    String channel = 'b2c',
    int storeId = 7,
    bool authenticated = false,
    String? credential,
  }) =>
      {
        'type': 'foodex.preview.bootstrap',
        'version': CustomerPreviewHostContract.version,
        'payload': {
          'context': {
            'session_id': authenticated ? 'session-1' : null,
            'target_type': 'customer',
            'channel': channel,
            'store_id': storeId,
            'mode': 'read_only',
            'read_only': true,
            'support_access': false,
            'target': authenticated
                ? {
                    'user_id': 44,
                    'name': 'Preview Customer',
                    'locale': 'en',
                  }
                : null,
          },
          'credential': credential,
          'configuration': 'published',
          'locale': 'en',
          'device': {'profile': 'phone_standard', 'width': 390},
          'safe_mode': 'read_only',
        },
      };

  test('creates the real Customer app for B2C guest preview', () {
    final bootstrap = CustomerPreviewBootstrap.parse(
      bootstrapMessage(),
      origin: origin,
      expectedOrigin: origin,
    );
    final runtime = CustomerPreviewRuntime.create(
      baseUrl: 'https://foodex.example',
      bootstrap: bootstrap,
      client: MockClient((request) async => http.Response('{}', 200)),
    );

    expect(runtime.app, isA<FoodexCustomerApp>());
    final app = runtime.app as FoodexCustomerApp;
    expect(app.previewContext, same(bootstrap.context));
    expect(app.session.isAuthenticated, isFalse);
    expect(app.initialRoute, '/retail/7/home');

    runtime.close();
  });

  test('creates authenticated B2B app without normal bearer credential', () async {
    const credential = 'opaque-preview-secret';
    late http.Request seen;

    final bootstrap = CustomerPreviewBootstrap.parse(
      bootstrapMessage(
        channel: 'b2b',
        storeId: 1,
        authenticated: true,
        credential: credential,
      ),
      origin: origin,
      expectedOrigin: origin,
    );

    final runtime = CustomerPreviewRuntime.create(
      baseUrl: 'https://foodex.example',
      bootstrap: bootstrap,
      client: MockClient((request) async {
        seen = request;
        return http.Response(
          jsonEncode({'data': const []}),
          200,
          headers: const {'content-type': 'application/json'},
        );
      }),
    );

    final app = runtime.app as FoodexCustomerApp;
    expect(app.previewContext?.runtimeIdentity.accessToken, isNull);
    expect(app.session.accessToken, isNull);

    await runtime.authenticatedBundle!.b2b!.get('/api/v1/b2b/products');
    expect(seen.headers['X-Foodex-Preview-Token'], credential);
    expect(seen.headers.containsKey('Authorization'), isFalse);

    runtime.close();
  });

  test('B2B guest uses public wholesale storefront contract', () async {
    late Uri seen;
    final bootstrap = CustomerPreviewBootstrap.parse(
      bootstrapMessage(channel: 'b2b', storeId: 3),
      origin: origin,
      expectedOrigin: origin,
    );

    final runtime = CustomerPreviewRuntime.create(
      baseUrl: 'https://foodex.example',
      bootstrap: bootstrap,
      client: MockClient((request) async {
        seen = request.url;
        return http.Response(
          jsonEncode({
            'store': {'id': 3, 'channel': 'b2b'},
            'sections': const [],
          }),
          200,
          headers: const {'content-type': 'application/json'},
        );
      }),
    );

    final app = runtime.app as FoodexCustomerApp;
    final result = await app.storefrontApi!.wholesaleHome(3);

    expect(seen.path, '/api/v1/wholesale/stores/3/storefront');
    expect(result['store']['id'], 3);
    expect(app.session.isAuthenticated, isFalse);

    runtime.close();
  });
}
