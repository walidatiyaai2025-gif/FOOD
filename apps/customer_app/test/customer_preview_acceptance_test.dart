import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/app.dart';
import 'package:foodex_customer_app/core/api/b2c_account_api.dart';
import 'package:foodex_customer_app/core/api/b2c_catalog_api.dart';
import 'package:foodex_customer_app/core/api/storefront_api.dart';
import 'package:foodex_customer_app/core/auth/customer_session.dart';
import 'package:foodex_customer_app/core/preview/customer_preview_context.dart';
import 'package:foodex_customer_app/core/preview/customer_preview_transport.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  const widths = <double>[360, 390, 430];
  const locales = <Locale>[Locale('ar'), Locale('en')];

  for (final width in widths) {
    for (final locale in locales) {
      testWidgets(
        'Customer preview renders shared Retail runtime at '
        '${width.toInt()}px in ${locale.languageCode}',
        (tester) async {
          await tester.binding.setSurfaceSize(Size(width, 844));
          addTearDown(() => tester.binding.setSurfaceSize(null));

          final context = CustomerPreviewContext.guest(
            channel: CustomerChannel.b2c,
            storeId: 7,
            targetLocale: locale.languageCode,
          );

          await tester.pumpWidget(
            _previewApp(
              context: context,
              locale: locale,
            ),
          );
          await tester.pumpAndSettle();

          expect(tester.takeException(), isNull);
          expect(find.byKey(const ValueKey('b2c-home-search')), findsOneWidget);

          final direction = Directionality.of(
            tester.element(find.byKey(const ValueKey('b2c-home-search'))),
          );
          expect(
            direction,
            locale.languageCode == 'ar'
                ? TextDirection.rtl
                : TextDirection.ltr,
          );
        },
      );
    }
  }

  testWidgets(
    'Guest and authenticated Retail preview use the same shared home widgets',
    (tester) async {
      await tester.binding.setSurfaceSize(const Size(390, 844));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      final guest = CustomerPreviewContext.guest(
        channel: CustomerChannel.b2c,
        storeId: 7,
        targetLocale: 'en',
      );
      final authenticated = CustomerPreviewContext.fromResolvedSession({
        'session_id': 'preview-session-1',
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

      final guestApp = _previewApp(
        context: guest,
        locale: const Locale('en'),
      );
      expect(guestApp.session.isAuthenticated, isFalse);
      expect(guestApp.previewContext, same(guest));

      await tester.pumpWidget(guestApp);
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('b2c-home-search')), findsOneWidget);
      expect(tester.takeException(), isNull);

      final authenticatedApp = _previewApp(
        context: authenticated,
        locale: const Locale('en'),
      );
      expect(authenticatedApp.session.isAuthenticated, isTrue);
      expect(authenticatedApp.session.accessToken, isNull);
      expect(authenticatedApp.previewContext, same(authenticated));

      await tester.pumpWidget(authenticatedApp);
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('b2c-home-search')), findsOneWidget);
      expect(tester.takeException(), isNull);
    },
  );

  test(
    'authenticated B2B preview preserves personalized price and tier state',
    () async {
      const credential = 'opaque-preview-secret';
      late http.Request seen;

      final context = CustomerPreviewContext.fromResolvedSession({
        'session_id': 'preview-b2b-session',
        'target_type': 'customer',
        'channel': 'b2b',
        'store_id': 1,
        'read_only': true,
        'target': {
          'user_id': 91,
          'name': 'Wholesale Customer',
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
              'id': 42,
              'name': 'Wholesale Product',
              'account_price': 72.5,
              'base_wholesale_price': 75,
              'pricing_tier': 'gold',
              'minimum_order_quantity': 5,
            }),
            200,
            headers: const {'content-type': 'application/json'},
          );
        }),
      );

      final response = await bundle.b2b!.get(
        '/api/v1/b2b/products/42?store_id=1',
      );
      final product = Map<String, dynamic>.from(response as Map);

      expect(product['account_price'], 72.5);
      expect(product['base_wholesale_price'], 75);
      expect(product['pricing_tier'], 'gold');
      expect(
        seen.url.path,
        '/api/v1/b2b/app-preview/customer/products/42',
      );
      expect(seen.url.queryParameters['store_id'], '1');
      expect(seen.headers['X-Foodex-Preview-Token'], credential);
      expect(seen.headers.containsKey('Authorization'), isFalse);
      expect(context.runtimeIdentity.accessToken, isNull);

      bundle.close();
    },
  );
}

FoodexCustomerApp _previewApp({
  required CustomerPreviewContext context,
  required Locale locale,
}) =>
    FoodexCustomerApp.preview(
      previewContext: context,
      b2cCatalogApi: const _PreviewCatalogApi(),
      b2cAccountApi: const _PreviewAccountApi(),
      storefrontApi: const _PreviewStorefrontApi(),
      marketplaceClient: MockClient(
        (_) async => http.Response('{"data":[]}', 200),
      ),
      initialRoute: '/retail/7/home',
      locale: locale,
    );

class _PreviewCatalogApi implements B2cCatalogApi {
  const _PreviewCatalogApi();

  @override
  Future<List<B2cStore>> stores() async => const [
        B2cStore(id: 7, name: 'Preview Store', code: 'PREVIEW'),
      ];

  @override
  Future<List<B2cCategory>> categories(int storeId) async => const [
        B2cCategory(id: 1, name: 'Category'),
      ];

  @override
  Future<List<B2cProduct>> products(
    int storeId, {
    String? query,
    int? categoryId,
    String? sort,
    String? direction,
  }) async =>
      const [
        B2cProduct(
          id: 42,
          name: 'Preview Product',
          sku: 'PREVIEW-42',
          price: 12.5,
        ),
      ];

  @override
  Future<List<B2cOffer>> offers(int storeId) async => const [];

  @override
  Future<List<B2cBanner>> banners(int storeId) async => const [];

  @override
  Future<B2cProduct> product(
    int productId, {
    required int storeId,
  }) async =>
      const B2cProduct(
        id: 42,
        name: 'Preview Product',
        sku: 'PREVIEW-42',
        price: 12.5,
      );
}

class _PreviewAccountApi implements B2cAccountApi {
  const _PreviewAccountApi();

  @override
  dynamic noSuchMethod(Invocation invocation) =>
      Future<Object?>.value(null);
}

class _PreviewStorefrontApi implements StorefrontApi {
  const _PreviewStorefrontApi();

  @override
  Future<Map<String, dynamic>> selection({
    String? countryCode,
    String? city,
    String? area,
    bool support = false,
  }) async =>
      {
        'retail_stores': [
          {
            'id': 7,
            'code': 'PREVIEW',
            'name': 'Preview Store',
            'theme_code': 'retail_grocery',
          },
        ],
        'wholesale_stores': const <Object>[],
      };

  @override
  Future<Map<String, dynamic>> retailHome(int storeId) async => {
        'store': {
          'id': storeId,
          'name': 'Preview Store',
        },
        'theme': {
          'code': 'retail_grocery',
          'primary': '#176B3A',
          'primary_dark': '#0F4B29',
          'accent': '#E8B931',
          'background': '#FFFFFF',
        },
        'branding': {
          'address': 'Kuwait',
          'custom': const <String, Object?>{},
        },
        'hero': null,
        'sections': [
          {'type': 'categories', 'title_ar': 'التصنيفات', 'title_en': 'Categories'},
          {'type': 'products', 'title_ar': 'المنتجات', 'title_en': 'Products'},
        ],
      };

  @override
  Future<Map<String, dynamic>> wholesaleHome(int storeId) async =>
      const <String, dynamic>{};

  @override
  Future<Map<String, dynamic>> b2bCheckoutOptions(int storeId) async =>
      const <String, dynamic>{};
}
