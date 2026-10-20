import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/api/b2c_catalog_api.dart';
import 'package:foodex_customer_app/features/retail/catalog/retail_catalog_screens.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  testWidgets('NEW Retail home keeps explicit store context in every intent', (
    tester,
  ) async {
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
      openCart: (BuildContext context, {required int storeId}) {
        navigatedStore = storeId;
      },
      openNotifications: (BuildContext context, {required int storeId}) {
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

  testWidgets('NEW Retail catalog refreshes authoritative data on resume', (
    tester,
  ) async {
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

    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    await tester.pump();
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();

    expect(api.seenStoreIds.length, beforeResume + 4);
    expect(api.seenStoreIds, everyElement(7));
    expect(tester.takeException(), isNull);
  });

  testWidgets(
    'NEW Retail home keeps shared search and hides unwired action keys',
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

      expect(
        find.byKey(const ValueKey('retail-catalog-search')),
        findsOneWidget,
      );
      expect(find.byKey(const ValueKey('retail-catalog-cart')), findsNothing);
      expect(
        find.byKey(const ValueKey('retail-catalog-notifications')),
        findsNothing,
      );
      expect(find.byKey(const ValueKey('retail-product-add-42')), findsNothing);
    },
  );

  testWidgets('NEW product add-to-cart entry carries exact store and product', (
    tester,
  ) async {
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

    await tester.drag(
      find.byKey(const ValueKey('retail-product-scroll')),
      const Offset(0, -360),
    );
    await tester.pumpAndSettle();
    final plus = find.byKey(const ValueKey('retail-product-plus'));
    await tester.ensureVisible(plus);
    await tester.pumpAndSettle();
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

  testWidgets(
    'full category and offer browse can reach records after the first page',
    (tester) async {
      tester.view.physicalSize = const Size(390, 844);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);

      final api = _PagedCollectionCatalogApi();
      await tester.pumpWidget(
        MaterialApp(
          locale: const Locale('en'),
          home: RetailCatalogCategoriesScreen(storeId: 7, catalogApi: api),
        ),
      );
      await tester.pumpAndSettle();

      expect(
        find.byKey(const ValueKey('retail-category-grid-1')),
        findsOneWidget,
      );
      await tester.tap(
        find.byKey(const ValueKey('retail-categories-load-more')),
      );
      await tester.pumpAndSettle();
      expect(
        find.byKey(const ValueKey('retail-category-grid-21')),
        findsOneWidget,
      );
      expect(
        find.byKey(const ValueKey('retail-categories-end')),
        findsOneWidget,
      );

      await tester.pumpWidget(
        MaterialApp(
          locale: const Locale('ar'),
          home: Directionality(
            textDirection: TextDirection.rtl,
            child: RetailCatalogOffersScreen(storeId: 7, catalogApi: api),
          ),
        ),
      );
      await tester.pumpAndSettle();

      expect(
        find.byKey(const ValueKey('retail-offers-load-more')),
        findsOneWidget,
      );
      await tester.tap(find.byKey(const ValueKey('retail-offers-load-more')));
      await tester.pumpAndSettle();

      expect(find.text('Second page offer'), findsOneWidget);
      expect(find.byKey(const ValueKey('retail-offers-end')), findsOneWidget);
      expect(api.categoryPages, <int>[1, 2]);
      expect(api.offerPages, <int>[1, 2]);
      expect(tester.takeException(), isNull);
    },
  );

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
              'currency': 'KWD',
              'selling_units': [
                {
                  'id': 1,
                  'code': 'PC',
                  'name': 'Piece',
                  'conversion_factor': 1,
                  'is_base': true,
                },
                {
                  'id': 2,
                  'code': 'CTN12',
                  'name': 'Carton',
                  'conversion_factor': 12,
                  'price': 35.0,
                  'is_base': false,
                },
              ],
            }),
            200,
          );
        }
        return http.Response(
          jsonEncode({
            'data': <Object>[],
            'meta': {'current_page': 1, 'per_page': 20, 'total': 0},
          }),
          200,
        );
      }),
    );

    await api.categories(7);
    await api.products(7, query: 'tomato', categoryId: 3);
    await api.offers(7);
    await api.banners(7);
    final product = await api.product(42, storeId: 7);

    expect(product.sellingUnits, hasLength(2));
    expect(product.sellingUnits.last.name, 'Carton');
    expect(product.sellingUnits.last.conversionFactor, 12);
    expect(product.sellingUnits.last.price, 35);

    expect(requests, hasLength(5));
    for (final request in requests) {
      expect(request.headers['X-FOODEX-Store-ID'], '7');
    }
    expect(requests[1].url.queryParameters['q'], 'tomato');
    expect(requests[1].url.queryParameters['category'], '3');
    expect(requests.last.url.queryParameters['store'], '7');
  });

  test(
    'HTTP catalog API preserves paginator metadata and request scope',
    () async {
      final requests = <http.Request>[];
      final api = HttpB2cCatalogApi(
        baseUrl: 'https://foodex.example',
        client: MockClient((request) async {
          requests.add(request);
          final page = int.parse(request.url.queryParameters['page'] ?? '1');
          final total = request.url.path.endsWith('/products') ? 45 : 21;
          final data = request.url.path.endsWith('/products')
              ? <Object>[
                  {
                    'id': page * 100,
                    'name': 'Paged product',
                    'sku': 'PAGE-$page',
                    'price': 2.5,
                  },
                ]
              : <Object>[];
          return http.Response(
            jsonEncode({
              'data': data,
              'meta': {'current_page': page, 'per_page': 20, 'total': total},
            }),
            200,
          );
        }),
      );

      final products = await api.productPage(
        7,
        query: ' coffee ',
        categoryId: 3,
        sort: 'price',
        direction: 'desc',
        page: 2,
      );
      final categories = await api.categoryPage(7, page: 2);
      final offers = await api.offerPage(7, page: 2);

      expect(products.items.single.id, 200);
      expect(products.currentPage, 2);
      expect(products.perPage, 20);
      expect(products.total, 45);
      expect(products.lastPage, 3);
      expect(products.hasNextPage, isTrue);
      expect(categories.currentPage, 2);
      expect(categories.lastPage, 2);
      expect(categories.hasNextPage, isFalse);
      expect(offers.currentPage, 2);
      expect(offers.lastPage, 2);
      expect(offers.hasNextPage, isFalse);

      expect(requests, hasLength(3));
      expect(requests[0].url.queryParameters, containsPair('q', 'coffee'));
      expect(requests[0].url.queryParameters, containsPair('category', '3'));
      expect(requests[0].url.queryParameters, containsPair('sort', 'price'));
      expect(
        requests[0].url.queryParameters,
        containsPair('direction', 'desc'),
      );
      for (final request in requests) {
        expect(request.url.queryParameters['page'], '2');
        expect(request.url.queryParameters['per_page'], '20');
        expect(request.headers['X-FOODEX-Store-ID'], '7');
      }
    },
  );

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

  test(
    'HTTP catalog API normalizes transport failures as offline state',
    () async {
      final api = HttpB2cCatalogApi(
        baseUrl: 'https://foodex.example',
        client: MockClient((request) async {
          throw http.ClientException('offline', request.url);
        }),
      );

      try {
        await api.products(7);
        fail('Expected an offline catalog exception.');
      } on B2cCatalogException catch (error) {
        expect(error.isNetworkUnavailable, isTrue);
      }
    },
  );
}

class _PagedCollectionCatalogApi extends _FakeCatalogApi
    implements B2cPagedCatalogApi {
  final List<int> categoryPages = <int>[];
  final List<int> offerPages = <int>[];

  @override
  Future<B2cCatalogPage<B2cCategory>> categoryPage(
    int storeId, {
    int page = 1,
    int perPage = 20,
  }) async {
    categoryPages.add(page);
    return B2cCatalogPage<B2cCategory>(
      items: <B2cCategory>[
        B2cCategory(
          id: page == 1 ? 1 : 21,
          name: page == 1 ? 'First page category' : 'Second page category',
        ),
      ],
      currentPage: page,
      perPage: 20,
      total: 21,
    );
  }

  @override
  Future<B2cCatalogPage<B2cOffer>> offerPage(
    int storeId, {
    int page = 1,
    int perPage = 20,
  }) async {
    offerPages.add(page);
    return B2cCatalogPage<B2cOffer>(
      items: <B2cOffer>[
        B2cOffer(
          id: page == 1 ? 1 : 21,
          name: page == 1 ? 'First page offer' : 'Second page offer',
          type: 'percentage',
          value: 10,
        ),
      ],
      currentPage: page,
      perPage: 20,
      total: 21,
    );
  }

  @override
  Future<B2cCatalogPage<B2cProduct>> productPage(
    int storeId, {
    String? query,
    int? categoryId,
    String? sort,
    String? direction,
    int page = 1,
    int perPage = 20,
  }) async =>
      B2cCatalogPage<B2cProduct>.single(
        await products(
          storeId,
          query: query,
          categoryId: categoryId,
          sort: sort,
          direction: direction,
        ),
      );
}

class _FakeCatalogApi implements B2cCatalogApi {
  final List<int> seenStoreIds = <int>[];

  void _remember(int storeId) => seenStoreIds.add(storeId);

  @override
  Future<List<B2cStore>> stores() async => const <B2cStore>[];

  @override
  Future<List<B2cCategory>> categories(int storeId) async {
    _remember(storeId);
    return const <B2cCategory>[B2cCategory(id: 3, name: 'Vegetables')];
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
      B2cProduct(id: 42, name: 'Tomato Box', sku: 'TOM-42', price: 3.25),
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
    return const <B2cBanner>[B2cBanner(id: 11, title: 'Fresh every day')];
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
