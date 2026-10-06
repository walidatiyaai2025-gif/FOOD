import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/api/b2c_catalog_api.dart';
import 'package:foodex_customer_app/features/retail/catalog/retail_catalog_screens.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  testWidgets('NEW Retail home keeps explicit store context in every intent',
      (tester) async {
    final api = _FakeCatalogApi();
    int? navigatedStore;
    int? navigatedCategory;
    int? navigatedProduct;
    String? navigatedQuery;

    final navigation = RetailCatalogNavigation(
      openProducts: (
        BuildContext context, {
        required int storeId,
        String? query,
        int? categoryId,
      }) {
        navigatedStore = storeId;
        navigatedQuery = query;
        navigatedCategory = categoryId;
      },
      openProduct: (
        BuildContext context, {
        required int storeId,
        required int productId,
      }) {
        navigatedStore = storeId;
        navigatedProduct = productId;
      },
      openCart: (
        BuildContext context, {
        required int storeId,
      }) {
        navigatedStore = storeId;
      },
      openNotifications: (
        BuildContext context, {
        required int storeId,
      }) {
        navigatedStore = storeId;
      },
    );

    await tester.pumpWidget(
      MaterialApp(
        locale: const Locale('en'),
        home: RetailCatalogHomeScreen(
          storeId: 7,
          catalogApi: api,
          navigation: navigation,
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(api.seenStoreIds, everyElement(7));
    expect(find.text('Categories'), findsOneWidget);
    expect(find.text('All products'), findsOneWidget);

    await tester.enterText(
      find.byKey(const ValueKey('retail-catalog-search')),
      ' tomato ',
    );
    await tester.testTextInput.receiveAction(TextInputAction.search);
    await tester.pump();
    expect(navigatedStore, 7);
    expect(navigatedQuery, 'tomato');

    final category = find.byKey(const ValueKey('retail-category-3'));
    await tester.ensureVisible(category);
    await tester.tap(category);
    await tester.pump();
    expect(navigatedStore, 7);
    expect(navigatedCategory, 3);

    final product = find.byKey(const ValueKey('retail-product-42'));
    await tester.ensureVisible(product);
    await tester.tap(product);
    await tester.pump();
    expect(navigatedStore, 7);
    expect(navigatedProduct, 42);

    final cart = find.byKey(const ValueKey('retail-catalog-cart'));
    await tester.ensureVisible(cart);
    await tester.tap(cart);
    await tester.pump();
    expect(navigatedStore, 7);
  });

  testWidgets('NEW Retail catalog refreshes authoritative data on resume',
      (tester) async {
    final api = _FakeCatalogApi();

    await tester.pumpWidget(
      MaterialApp(
        locale: const Locale('en'),
        home: RetailCatalogHomeScreen(storeId: 7, catalogApi: api),
      ),
    );
    await tester.pumpAndSettle();

    final beforeResume = api.seenStoreIds.length;
    expect(beforeResume, 4);

    await tester.binding.handleAppLifecycleStateChanged(
      AppLifecycleState.paused,
    );
    await tester.pump();
    await tester.binding.handleAppLifecycleStateChanged(
      AppLifecycleState.resumed,
    );
    await tester.pumpAndSettle();

    expect(api.seenStoreIds.length, beforeResume + 4);
    expect(api.seenStoreIds, everyElement(7));
    expect(tester.takeException(), isNull);
  });

  testWidgets('NEW Retail home hides unwired controls instead of silent buttons',
      (tester) async {
    await tester.pumpWidget(
      MaterialApp(
        locale: const Locale('en'),
        home: RetailCatalogHomeScreen(
          storeId: 7,
          catalogApi: _FakeCatalogApi(),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('retail-catalog-search')), findsNothing);
    expect(find.byKey(const ValueKey('retail-catalog-cart')), findsNothing);
    expect(
      find.byKey(const ValueKey('retail-catalog-notifications')),
      findsNothing,
    );
    expect(find.byKey(const ValueKey('retail-product-add-42')), findsNothing);
  });

  testWidgets('NEW product add-to-cart entry carries exact store and product',
      (tester) async {
    int? capturedStoreId;
    int? capturedProductId;
    double? capturedQuantity;

    await tester.pumpWidget(
      MaterialApp(
        locale: const Locale('en'),
        home: RetailCatalogProductScreen(
          storeId: 7,
          productId: 42,
          catalogApi: _FakeCatalogApi(),
          onAddToCart: ({
            required int storeId,
            required int productId,
            required double quantity,
          }) async {
            capturedStoreId = storeId;
            capturedProductId = productId;
            capturedQuantity = quantity;
          },
        ),
      ),
    );
    await tester.pumpAndSettle();

    final plus = find.byKey(const ValueKey('retail-product-plus'));
    await tester.ensureVisible(plus);
    await tester.tap(plus);
    await tester.pump();
    final add = find.byKey(const ValueKey('retail-product-add-cart'));
    await tester.ensureVisible(add);
    await tester.tap(add);
    await tester.pumpAndSettle();

    expect(capturedStoreId, 7);
    expect(capturedProductId, 42);
    expect(capturedQuantity, 2);
  });

  test('HTTP catalog API sends authoritative Retail store header', () async {
    final requests = <http.Request>[];
    final api = HttpB2cCatalogApi(
      baseUrl: 'https://foodex.example',
      client: MockClient((request) async {
        requests.add(request);
        if (request.url.path == '/api/v1/products/42') {
          return http.Response(
            jsonEncode({
              'id': 42,
              'name': 'Tomato Box',
              'sku': 'TOM-42',
              'price': 3.25,
            }),
            200,
          );
        }
        return http.Response(jsonEncode({'data': <Object>[]}), 200);
      }),
    );

    await api.categories(7);
    await api.products(7, query: 'tomato', categoryId: 3);
    await api.offers(7);
    await api.banners(7);
    await api.product(42, storeId: 7);

    expect(requests, hasLength(5));
    for (final request in requests) {
      expect(request.headers['X-FOODEX-Store-ID'], '7');
    }
    expect(requests[1].url.queryParameters['q'], 'tomato');
    expect(requests[1].url.queryParameters['category'], '3');
    expect(requests.last.url.queryParameters['store'], '7');
  });

  test('HTTP catalog API rejects missing/invalid store context', () async {
    final api = HttpB2cCatalogApi(
      baseUrl: 'https://foodex.example',
      client: MockClient((request) async {
        fail('No network request should be made for an invalid store context.');
      }),
    );

    await expectLater(api.products(0), throwsArgumentError);
    await expectLater(api.product(42, storeId: -1), throwsArgumentError);
  });
}

class _FakeCatalogApi implements B2cCatalogApi {
  final List<int> seenStoreIds = <int>[];

  void _remember(int storeId) => seenStoreIds.add(storeId);

  @override
  Future<List<B2cStore>> stores() async => const <B2cStore>[];

  @override
  Future<List<B2cCategory>> categories(int storeId) async {
    _remember(storeId);
    return const <B2cCategory>[
      B2cCategory(id: 3, name: 'Vegetables'),
    ];
  }

  @override
  Future<List<B2cProduct>> products(
    int storeId, {
    String? query,
    int? categoryId,
    String? sort,
    String? direction,
  }) async {
    _remember(storeId);
    return const <B2cProduct>[
      B2cProduct(
        id: 42,
        name: 'Tomato Box',
        sku: 'TOM-42',
        price: 3.25,
      ),
    ];
  }

  @override
  Future<List<B2cOffer>> offers(int storeId) async {
    _remember(storeId);
    return const <B2cOffer>[
      B2cOffer(id: 9, name: 'Weekend Offer', type: 'percentage', value: 10),
    ];
  }

  @override
  Future<List<B2cBanner>> banners(int storeId) async {
    _remember(storeId);
    return const <B2cBanner>[
      B2cBanner(id: 11, title: 'Fresh every day'),
    ];
  }

  @override
  Future<B2cProduct> product(int productId, {required int storeId}) async {
    _remember(storeId);
    return B2cProduct(
      id: productId,
      name: 'Tomato Box',
      sku: 'TOM-42',
      price: 3.25,
      description: 'Fresh product',
    );
  }
}
