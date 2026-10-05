import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/api/storefront_api.dart';
import 'package:foodex_customer_app/core/auth/customer_session.dart';
import 'package:foodex_customer_app/core/preview/customer_preview_configuration.dart';
import 'package:foodex_customer_app/core/preview/customer_preview_context.dart';
import 'package:foodex_customer_app/core/preview/customer_preview_transport.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  test('Draft resolver is header-only and returns scoped revision metadata',
      () async {
    const credential = 'opaque-preview-secret';
    late http.Request seen;
    final context = _context(CustomerChannel.b2c, 7);
    final transport = CustomerPreviewReadHttpClient(
      MockClient((request) async {
        seen = request;
        return http.Response(
          jsonEncode(_response(channel: 'b2c', storeId: 7, mode: 'draft')),
          200,
          headers: const {'content-type': 'application/json'},
        );
      }),
      credential: credential,
      channel: CustomerChannel.b2c,
    );

    final resolved = await CustomerPreviewResolvedConfiguration.resolve(
      baseUrl: 'https://foodex.example',
      client: transport,
      context: context,
      mode: 'draft',
    );

    expect(
      seen.url.path,
      '/api/v1/app-preview/storefront-configuration',
    );
    expect(seen.url.queryParameters, {'mode': 'draft'});
    expect(seen.headers['X-Foodex-Preview-Token'], credential);
    expect(seen.headers.containsKey('Authorization'), isFalse);
    expect(seen.url.queryParameters.containsKey('preview_token'), isFalse);
    expect(resolved.revisionId, 'revision-draft-7');
    expect(resolved.mode, 'draft');
    expect(resolved.storeId, 7);
  });

  test('Guest resolver uses Dashboard session scope without preview credential',
      () async {
    late http.Request seen;
    final context = CustomerPreviewContext.guest(
      channel: CustomerChannel.b2c,
      storeId: 7,
      supportAccess: true,
    );
    final client = MockClient((request) async {
      seen = request;
      return http.Response(
        jsonEncode(_response(channel: 'b2c', storeId: 7, mode: 'draft')),
        200,
        headers: const {'content-type': 'application/json'},
      );
    });

    final resolved = await CustomerPreviewResolvedConfiguration.resolveGuest(
      dashboardBaseUrl: 'https://dashboard.example/',
      client: client,
      context: context,
      mode: 'draft',
    );

    expect(
      seen.url.path,
      '/admin/app-preview/storefront-configuration',
    );
    expect(seen.url.queryParameters, {
      'channel': 'b2c',
      'store_id': '7',
      'mode': 'draft',
      'support_access': '1',
    });
    expect(seen.headers.containsKey('Authorization'), isFalse);
    expect(
      seen.headers.keys
          .map((key) => key.toLowerCase())
          .contains('x-foodex-preview-token'),
      isFalse,
    );
    expect(seen.url.queryParameters.containsKey('preview_token'), isFalse);
    expect(resolved.revisionId, 'revision-draft-7');
    expect(resolved.mode, 'draft');
  });

  test('Draft and Published resolve deterministic different storefront payloads',
      () async {
    final context = _context(CustomerChannel.b2c, 7);
    final transport = CustomerPreviewReadHttpClient(
      MockClient((request) async {
        final mode = request.url.queryParameters['mode'] ?? 'published';
        return http.Response(
          jsonEncode(
            _response(
              channel: 'b2c',
              storeId: 7,
              mode: mode,
              primaryColor: mode == 'draft' ? '#DDAA11' : '#1122AA',
            ),
          ),
          200,
          headers: const {'content-type': 'application/json'},
        );
      }),
      credential: 'opaque-preview-secret',
      channel: CustomerChannel.b2c,
    );

    final draft = await CustomerPreviewResolvedConfiguration.resolve(
      baseUrl: 'https://foodex.example',
      client: transport,
      context: context,
      mode: 'draft',
    );
    final published = await CustomerPreviewResolvedConfiguration.resolve(
      baseUrl: 'https://foodex.example',
      client: transport,
      context: context,
      mode: 'published',
    );

    final draftHome = await PreviewRevisionStorefrontApi(
      delegate: _StorefrontFake(),
      context: context,
      configuration: draft,
      baseUrl: 'https://foodex.example',
    ).retailHome(7);
    final publishedHome = await PreviewRevisionStorefrontApi(
      delegate: _StorefrontFake(),
      context: context,
      configuration: published,
      baseUrl: 'https://foodex.example',
    ).retailHome(7);

    expect(draft.mode, 'draft');
    expect(published.mode, 'published');
    expect(draft.revisionId, 'revision-draft-7');
    expect(published.revisionId, 'revision-published-7');
    expect(draftHome['theme']['primary'], '#DDAA11');
    expect(publishedHome['theme']['primary'], '#1122AA');
  });

  test('Retail Store A rejects a Store B revision even with a valid response',
      () async {
    final context = _context(CustomerChannel.b2c, 7);
    final transport = CustomerPreviewReadHttpClient(
      MockClient(
        (_) async => http.Response(
          jsonEncode(_response(channel: 'b2c', storeId: 8, mode: 'draft')),
          200,
          headers: const {'content-type': 'application/json'},
        ),
      ),
      credential: 'opaque-preview-secret',
      channel: CustomerChannel.b2c,
    );

    await expectLater(
      CustomerPreviewResolvedConfiguration.resolve(
        baseUrl: 'https://foodex.example',
        client: transport,
        context: context,
        mode: 'draft',
      ),
      throwsA(
        isA<CustomerPreviewConfigurationException>().having(
          (error) => error.code,
          'code',
          'preview_configuration_scope_mismatch',
        ),
      ),
    );
  });

  test('missing Draft is a controlled unavailable state and never falls back', () async {
    final context = _context(CustomerChannel.b2c, 7);
    final transport = CustomerPreviewReadHttpClient(
      MockClient(
        (_) async => http.Response(
          jsonEncode({
            'data': null,
            'state': 'draft_unavailable',
            'mode': 'draft',
            'read_only': true,
          }),
          200,
          headers: const {'content-type': 'application/json'},
        ),
      ),
      credential: 'opaque-preview-secret',
      channel: CustomerChannel.b2c,
    );

    await expectLater(
      CustomerPreviewResolvedConfiguration.resolve(
        baseUrl: 'https://foodex.example',
        client: transport,
        context: context,
        mode: 'draft',
      ),
      throwsA(
        isA<CustomerPreviewConfigurationException>()
            .having(
              (error) => error.code,
              'code',
              'preview_draft_unavailable',
            )
            .having((error) => error.statusCode, 'statusCode', 200)
            .having((error) => error.runtimeState, 'runtimeState', 'unavailable'),
      ),
    );
  });

  test('expired forbidden and incompatible configuration have explicit states',
      () async {
    final context = _context(CustomerChannel.b2c, 7);

    Future<CustomerPreviewConfigurationException> failure(int status) async {
      final transport = CustomerPreviewReadHttpClient(
        MockClient((_) async => http.Response('{}', status)),
        credential: 'opaque-preview-secret',
        channel: CustomerChannel.b2c,
      );

      try {
        await CustomerPreviewResolvedConfiguration.resolve(
          baseUrl: 'https://foodex.example',
          client: transport,
          context: context,
          mode: 'published',
        );
      } on CustomerPreviewConfigurationException catch (error) {
        return error;
      }
      throw StateError('Expected preview configuration failure.');
    }

    final expired = await failure(401);
    expect(expired.code, 'preview_session_expired');
    expect(expired.runtimeState, 'expired');

    final forbidden = await failure(403);
    expect(forbidden.code, 'preview_configuration_forbidden');
    expect(forbidden.runtimeState, 'forbidden');

    final incompatible = await failure(422);
    expect(incompatible.code, 'preview_configuration_schema_incompatible');
    expect(incompatible.runtimeState, 'error');
  });

  test('revision storefront adapter uses config but keeps real delegate actions',
      () async {
    final context = _context(CustomerChannel.b2b, 1);
    final configuration = await CustomerPreviewResolvedConfiguration.resolve(
      baseUrl: 'https://foodex.example',
      client: CustomerPreviewReadHttpClient(
        MockClient(
          (_) async => http.Response(
            jsonEncode(
              _response(
                channel: 'b2b',
                storeId: 1,
                mode: 'published',
                primaryColor: '#123456',
              ),
            ),
            200,
            headers: const {'content-type': 'application/json'},
          ),
        ),
        credential: 'opaque-preview-secret',
        channel: CustomerChannel.b2b,
      ),
      context: context,
      mode: 'published',
    );

    final delegate = _StorefrontFake();
    final api = PreviewRevisionStorefrontApi(
      delegate: delegate,
      context: context,
      configuration: configuration,
      baseUrl: 'https://foodex.example',
    );

    final home = await api.wholesaleHome(1);
    expect(home['theme']['primary'], '#123456');
    expect(
      home['hero']['image_url'],
      'https://foodex.example/storage/banner.webp',
    );
    expect(home['store']['channel'], 'b2b');

    final options = await api.b2bCheckoutOptions(1);
    expect(options['source'], 'real-business-api');
    expect(delegate.checkoutCalls, 1);

    await expectLater(
      api.wholesaleHome(2),
      throwsA(isA<CustomerPreviewScopeException>()),
    );
  });
}

CustomerPreviewContext _context(CustomerChannel channel, int storeId) =>
    CustomerPreviewContext.fromResolvedSession({
      'session_id': 'session-1',
      'target_type': 'customer',
      'channel': channel.name,
      'store_id': storeId,
      'read_only': true,
      'target': {
        'user_id': 44,
        'name': 'Preview Customer',
        'locale': 'en',
      },
    });

Map<String, dynamic> _response({
  required String channel,
  required int storeId,
  required String mode,
  String primaryColor = '#445566',
}) =>
    {
      'data': {
        'id': 1,
        'revision_id': 'revision-$mode-$storeId',
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
            'logo_path': 'storage/logo.webp',
            'is_active': true,
          },
          'settings': {
            'theme_code':
                channel == 'b2b' ? 'wholesale_b2b' : 'retail_grocery',
            'primary_color': primaryColor,
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
              'title_ar': 'الرئيسية',
              'title_en': 'Hero',
              'sort_order': 10,
              'config': <String, Object?>{},
              'is_active': true,
            },
          ],
          'banners': [
            {
              'title': 'Revision banner',
              'image_path': 'storage/banner.webp',
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

class _StorefrontFake implements StorefrontApi {
  int checkoutCalls = 0;

  @override
  Future<Map<String, dynamic>> selection({
    String? countryCode,
    String? city,
    String? area,
    bool support = false,
  }) async =>
      const <String, dynamic>{};

  @override
  Future<Map<String, dynamic>> retailHome(int storeId) async =>
      const <String, dynamic>{'source': 'live'};

  @override
  Future<Map<String, dynamic>> wholesaleHome(int storeId) async =>
      const <String, dynamic>{'source': 'live'};

  @override
  Future<Map<String, dynamic>> b2bCheckoutOptions(int storeId) async {
    checkoutCalls++;
    return const <String, dynamic>{'source': 'real-business-api'};
  }
}
