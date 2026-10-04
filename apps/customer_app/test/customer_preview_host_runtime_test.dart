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
    String configuration = 'published',
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
          'configuration': configuration,
          'locale': 'en',
          'device': {'profile': 'phone_standard', 'width': 390},
          'safe_mode': 'read_only',
        },
      };

  test('creates the real Customer app for B2C guest preview', () async {
    final bootstrap = CustomerPreviewBootstrap.parse(
      bootstrapMessage(),
      origin: origin,
      expectedOrigin: origin,
    );
    final runtime = await CustomerPreviewRuntime.create(
      baseUrl: 'https://foodex.example',
      dashboardBaseUrl: 'https://dashboard.example',
      bootstrap: bootstrap,
      client: MockClient((request) async {
        expect(
          request.url.path,
          '/admin/app-preview/storefront-configuration',
        );
        expect(request.url.queryParameters['channel'], 'b2c');
        expect(request.url.queryParameters['store_id'], '7');
        expect(request.url.queryParameters['mode'], 'published');
        return http.Response(
          jsonEncode(
            _configurationResponse(
              channel: 'b2c',
              storeId: 7,
              mode: 'published',
              color: '#176B3A',
            ),
          ),
          200,
          headers: const {'content-type': 'application/json'},
        );
      }),
    );

    expect(runtime.app, isA<FoodexCustomerApp>());
    final app = runtime.app as FoodexCustomerApp;
    expect(app.previewContext, same(bootstrap.context));
    expect(app.session.isAuthenticated, isFalse);
    expect(app.initialRoute, '/retail/7/home');
    expect(runtime.configuration.revisionId, 'revision-published-1');
    expect(runtime.configuration.mode, 'published');
    expect(runtime.safeStatusMetadata['loaded_at'], isA<String>());
    expect(runtime.safeStatusMetadata['updated_at'], isA<String>());
    expect(app.notificationCampaignPopupService, isNotNull);

    runtime.close();
  });

  test('authenticated B2B resolves revision before real Customer reads', () async {
    const credential = 'opaque-preview-secret';
    final seen = <http.Request>[];

    final bootstrap = CustomerPreviewBootstrap.parse(
      bootstrapMessage(
        channel: 'b2b',
        storeId: 1,
        authenticated: true,
        credential: credential,
        configuration: 'draft',
      ),
      origin: origin,
      expectedOrigin: origin,
    );

    final runtime = await CustomerPreviewRuntime.create(
      baseUrl: 'https://foodex.example',
      bootstrap: bootstrap,
      client: MockClient((request) async {
        seen.add(request);
        if (request.url.path ==
            '/api/v1/app-preview/storefront-configuration') {
          return http.Response(
            jsonEncode(
              _configurationResponse(
                channel: 'b2b',
                storeId: 1,
                mode: 'draft',
                color: '#445566',
              ),
            ),
            200,
            headers: const {'content-type': 'application/json'},
          );
        }

        return http.Response(
          jsonEncode({'data': const []}),
          200,
          headers: const {'content-type': 'application/json'},
        );
      }),
    );

    expect(seen, hasLength(1));
    expect(
      seen.single.url.path,
      '/api/v1/app-preview/storefront-configuration',
    );
    expect(seen.single.url.queryParameters['mode'], 'draft');
    expect(seen.single.headers['X-Foodex-Preview-Token'], credential);
    expect(seen.single.headers.containsKey('Authorization'), isFalse);

    final app = runtime.app as FoodexCustomerApp;
    expect(app.previewContext?.runtimeIdentity.accessToken, isNull);
    expect(app.session.accessToken, isNull);
    expect(runtime.configuration.revisionId, 'revision-draft-1');
    expect(
      runtime.safeStatusMetadata['configuration_revision'],
      'revision-draft-1',
    );
    expect(runtime.safeStatusMetadata['configuration_checksum'], List.filled(64, 'a').join());
    expect(runtime.safeStatusMetadata.toString(), isNot(contains(credential)));
    expect(runtime.safeStatusMetadata.toString(), isNot(contains('Authorization')));
    expect(runtime.safeStatusMetadata.toString(), isNot(contains('preview_token')));

    await runtime.authenticatedBundle!.b2b!.get('/api/v1/b2b/products');
    expect(seen, hasLength(2));
    expect(seen.last.headers['X-Foodex-Preview-Token'], credential);
    expect(seen.last.headers.containsKey('Authorization'), isFalse);

    runtime.close();
  });

  test('authenticated Retail storefront renders resolved Draft payload', () async {
    const credential = 'retail-preview-secret';
    final bootstrap = CustomerPreviewBootstrap.parse(
      bootstrapMessage(
        storeId: 7,
        authenticated: true,
        credential: credential,
        configuration: 'draft',
      ),
      origin: origin,
      expectedOrigin: origin,
    );

    final runtime = await CustomerPreviewRuntime.create(
      baseUrl: 'https://foodex.example',
      bootstrap: bootstrap,
      client: MockClient((request) async {
        if (request.url.path ==
            '/api/v1/app-preview/storefront-configuration') {
          return http.Response(
            jsonEncode(
              _configurationResponse(
                channel: 'b2c',
                storeId: 7,
                mode: 'draft',
                color: '#445566',
              ),
            ),
            200,
            headers: const {'content-type': 'application/json'},
          );
        }

        return http.Response('{}', 200);
      }),
    );

    final app = runtime.app as FoodexCustomerApp;
    final storefront = await app.storefrontApi!.retailHome(7);

    expect(storefront['theme']['primary'], '#445566');
    expect(storefront['hero']['title'], 'Draft banner');
    expect(storefront['sections'], hasLength(1));
    expect(storefront['sections'][0]['title_en'], 'Draft section');
    expect(runtime.safeStatusMetadata['configuration'], 'draft');

    runtime.close();
  });

  test('B2B guest renders the authoritative dashboard revision', () async {
    late Uri seen;
    final bootstrap = CustomerPreviewBootstrap.parse(
      bootstrapMessage(channel: 'b2b', storeId: 3),
      origin: origin,
      expectedOrigin: origin,
    );

    final runtime = await CustomerPreviewRuntime.create(
      baseUrl: 'https://foodex.example',
      dashboardBaseUrl: 'https://dashboard.example',
      bootstrap: bootstrap,
      client: MockClient((request) async {
        seen = request.url;
        return http.Response(
          jsonEncode(
            _configurationResponse(
              channel: 'b2b',
              storeId: 3,
              mode: 'published',
              color: '#5D2A91',
            ),
          ),
          200,
          headers: const {'content-type': 'application/json'},
        );
      }),
    );

    final app = runtime.app as FoodexCustomerApp;
    final result = await app.storefrontApi!.wholesaleHome(3);

    expect(seen.path, '/admin/app-preview/storefront-configuration');
    expect(seen.queryParameters['channel'], 'b2b');
    expect(seen.queryParameters['store_id'], '3');
    expect(seen.queryParameters['mode'], 'published');
    expect(result['store']['id'], 3);
    expect(result['theme']['primary'], '#5D2A91');
    expect(app.session.isAuthenticated, isFalse);

    runtime.close();
  });
}

Map<String, dynamic> _configurationResponse({
  required String channel,
  required int storeId,
  required String mode,
  required String color,
}) =>
    {
      'data': {
        'id': 1,
        'revision_id': 'revision-$mode-1',
        'store_id': storeId,
        'channel': channel,
        'status': mode,
        'mode': mode,
        'schema_version': 1,
        'checksum': List.filled(64, 'a').join(),
        'read_only': true,
        'preview_session_id': 'session-1',
        'payload': {
          'schema_version': 1,
          'channel': channel,
          'store': {
            'id': storeId,
            'code': 'STORE-$storeId',
            'name': 'Preview Store',
            'logo_path': 'storage/preview/logo.webp',
            'is_active': true,
          },
          'settings': {
            'theme_code':
                channel == 'b2b' ? 'wholesale_b2b' : 'retail_grocery',
            'primary_color': color,
            'primary_dark_color': '#112233',
            'accent_color': '#778899',
            'background_color': '#FFFFFF',
            'header_address': 'Kuwait',
            'branding': {'brand_title_en': 'Preview'},
          },
          'sections': [
            {
              'key': 'hero',
              'type': 'hero',
              'title_ar': 'مسودة',
              'title_en': 'Draft section',
              'sort_order': 10,
              'config': <String, Object?>{},
              'is_active': true,
            },
          ],
          'banners': [
            {
              'title': 'Draft banner',
              'image_path': 'storage/preview/banner.webp',
              'target_type': null,
              'target_id': null,
              'target_url': null,
              'sort_order': 10,
              'is_active': true,
            },
          ],
          'service_zones': const <Object>[],
        },
      },
    };
