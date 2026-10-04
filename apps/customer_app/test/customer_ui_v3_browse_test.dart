import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/api/b2c_catalog_api.dart';
import 'package:foodex_customer_app/features/retail/catalog/retail_catalog_screens.dart';
import 'package:foodex_customer_app/shared/customer_ui_v3/customer_ui_v3.dart';

void main() {
  testWidgets(
    'V3 product browse is RTL-safe at 360px and preserves store/query intents',
    (tester) async {
      final api = _BrowseFakeApi();
      int? openedStore;
      int? openedProduct;
      int? addedStore;
      int? addedProduct;

      await tester.pumpWidget(
        MaterialApp(
          locale: const Locale('ar'),
          home: MediaQuery(
            data: const MediaQueryData(
              size: Size(360, 800),
              textScaler: TextScaler.linear(1.35),
            ),
            child: Directionality(
              textDirection: TextDirection.rtl,
              child: RetailCatalogProductsScreen(
                storeId: 19,
                categoryId: 3,
                catalogApi: api,
                navigation: RetailCatalogNavigation(
                  openProduct: (
                    BuildContext context, {
                    required int storeId,
                    required int productId,
                  }) {
                    openedStore = storeId;
                    openedProduct = productId;
                  },
                ),
                onAddToCart: ({
                  required int storeId,
                  required int productId,
                  required double quantity,
                }) async {
                  addedStore = storeId;
                  addedProduct = productId;
                },
              ),
            ),
          ),
        ),
      );
      await tester.pumpAndSettle();

      expect(find.byType(CustomerSearchPill), findsOneWidget);
      expect(find.byType(CustomerProductCard), findsOneWidget);
      expect(find.byKey(const ValueKey('retail-products-grid')), findsOneWidget);
      expect(api.lastStoreId, 19);
      expect(api.lastCategoryId, 3);

      final searchField = find.descendant(
        of: find.byKey(const ValueKey('retail-products-search')),
        matching: find.byType(TextField),
      );
      await tester.enterText(searchField, ' coffee ');
      await tester.testTextInput.receiveAction(TextInputAction.search);
      await tester.pumpAndSettle();
      expect(api.lastQuery, 'coffee');
      expect(api.lastStoreId, 19);

      final product = find.byKey(const ValueKey('retail-product-8'));
      await tester.ensureVisible(product);
      await tester.tap(product);
      await tester.pump();
      expect(openedStore, 19);
      expect(openedProduct, 8);

      await tester.tap(find.byIcon(Icons.add_rounded));
      await tester.pumpAndSettle();
      expect(addedStore, 19);
      expect(addedProduct, 8);
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets('V3 browse and detail replace default loading spinners',
      (tester) async {
    final productsPending = Completer<List<B2cProduct>>();
    final detailPending = Completer<B2cProduct>();

    await tester.pumpWidget(
      MaterialApp(
        home: RetailCatalogProductsScreen(
          storeId: 19,
          catalogApi: _PendingBrowseApi(
            productsPending: productsPending,
            detailPending: detailPending,
          ),
        ),
      ),
    );
    await tester.pump();

    expect(
      find.byKey(const ValueKey('retail-products-loading')),
      findsOneWidget,
    );
    expect(find.byType(CustomerProductCardSkeleton), findsNWidgets(6));
    expect(find.byType(CircularProgressIndicator), findsNothing);

    await tester.pumpWidget(
      MaterialApp(
        home: RetailCatalogProductScreen(
          storeId: 19,
          productId: 8,
          catalogApi: _PendingBrowseApi(
            productsPending: productsPending,
            detailPending: detailPending,
          ),
        ),
      ),
    );
    await tester.pump();

    expect(
      find.byKey(const ValueKey('retail-product-loading')),
      findsOneWidget,
    );
    expect(find.byType(CustomerSkeletonBox), findsWidgets);
    expect(find.byType(CircularProgressIndicator), findsNothing);
  });

  testWidgets(
    'V3 product detail keeps quantity and add-to-cart semantics on narrow RTL',
    (tester) async {
      tester.view.physicalSize = const Size(360, 800);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);

      int? capturedStoreId;
      int? capturedProductId;
      double? capturedQuantity;

      await tester.pumpWidget(
        MaterialApp(
          locale: const Locale('ar'),
          home: MediaQuery(
            data: const MediaQueryData(
              size: Size(360, 800),
              textScaler: TextScaler.linear(1.35),
            ),
            child: Directionality(
              textDirection: TextDirection.rtl,
              child: RetailCatalogProductScreen(
                storeId: 19,
                productId: 8,
                catalogApi: _BrowseFakeApi(),
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
          ),
        ),
      );
      await tester.pumpAndSettle();

      expect(find.byType(CustomerProductImage), findsOneWidget);
      expect(find.text('Coffee'), findsOneWidget);

      final plus = find.byKey(const ValueKey('retail-product-plus'));
      await tester.ensureVisible(plus);
      await tester.tap(plus);
      await tester.pump();

      final add = find.byKey(const ValueKey('retail-product-add-cart'));
      await tester.ensureVisible(add);
      await tester.tap(add);
      await tester.pumpAndSettle();

      expect(capturedStoreId, 19);
      expect(capturedProductId, 8);
      expect(capturedQuantity, 2);
      expect(tester.takeException(), isNull);
    },
  );
  testWidgets(
    'out-of-stock product is dimmed, labeled and cannot be added or increased',
    (tester) async {
      var addCalls = 0;
      final api = _OutOfStockBrowseApi();

      await tester.pumpWidget(
        MaterialApp(
          locale: const Locale('ar'),
          home: Directionality(
            textDirection: TextDirection.rtl,
            child: RetailCatalogProductsScreen(
              storeId: 19,
              catalogApi: api,
              onAddToCart: ({
                required int storeId,
                required int productId,
                required double quantity,
              }) async {
                addCalls++;
              },
            ),
          ),
        ),
      );
      await tester.pumpAndSettle();

      expect(
        find.byKey(const ValueKey('customer-product-out-of-stock')),
        findsOneWidget,
      );
      expect(find.byIcon(Icons.add_rounded), findsNothing);
      expect(addCalls, 0);

      await tester.pumpWidget(
        MaterialApp(
          locale: const Locale('ar'),
          home: Directionality(
            textDirection: TextDirection.rtl,
            child: RetailCatalogProductScreen(
              storeId: 19,
              productId: 18,
              catalogApi: api,
              onAddToCart: ({
                required int storeId,
                required int productId,
                required double quantity,
              }) async {
                addCalls++;
              },
            ),
          ),
        ),
      );
      await tester.pumpAndSettle();

      expect(
        find.byKey(const ValueKey('retail-product-out-of-stock')),
        findsOneWidget,
      );
      final plus = tester.widget<IconButton>(
        find.byKey(const ValueKey('retail-product-plus')),
      );
      final add = tester.widget<FilledButton>(
        find.byKey(const ValueKey('retail-product-add-cart')),
      );
      expect(plus.onPressed, isNull);
      expect(add.onPressed, isNull);
      expect(addCalls, 0);
      expect(tester.takeException(), isNull);
    },
  );

}

class _BrowseFakeApi implements B2cCatalogApi {
  int? lastStoreId;
  int? lastCategoryId;
  String? lastQuery;

  @override
  Future<List<B2cStore>> stores() async => const <B2cStore>[];

  @override
  Future<List<B2cCategory>> categories(int storeId) async =>
      const <B2cCategory>[];

  @override
  Future<List<B2cOffer>> offers(int storeId) async => const <B2cOffer>[];

  @override
  Future<List<B2cBanner>> banners(int storeId) async => const <B2cBanner>[];

  @override
  Future<List<B2cProduct>> products(
    int storeId, {
    String? query,
    int? categoryId,
    String? sort,
    String? direction,
  }) async {
    lastStoreId = storeId;
    lastCategoryId = categoryId;
    lastQuery = query;
    return const <B2cProduct>[
      B2cProduct(
        id: 8,
        name: 'Coffee',
        sku: 'COF-8',
        price: 2.5,
        currency: 'KWD',
      ),
    ];
  }

  @override
  Future<B2cProduct> product(int productId, {required int storeId}) async =>
      B2cProduct(
        id: productId,
        name: 'Coffee',
        sku: 'COF-8',
        price: 2.5,
        currency: 'KWD',
        description: 'Fresh coffee',
      );
}

class _PendingBrowseApi implements B2cCatalogApi {
  const _PendingBrowseApi({
    required this.productsPending,
    required this.detailPending,
  });

  final Completer<List<B2cProduct>> productsPending;
  final Completer<B2cProduct> detailPending;

  @override
  Future<List<B2cProduct>> products(
    int storeId, {
    String? query,
    int? categoryId,
    String? sort,
    String? direction,
  }) =>
      productsPending.future;

  @override
  Future<B2cProduct> product(int productId, {required int storeId}) =>
      detailPending.future;

  @override
  Future<List<B2cStore>> stores() async => const <B2cStore>[];

  @override
  Future<List<B2cCategory>> categories(int storeId) async =>
      const <B2cCategory>[];

  @override
  Future<List<B2cOffer>> offers(int storeId) async => const <B2cOffer>[];

  @override
  Future<List<B2cBanner>> banners(int storeId) async => const <B2cBanner>[];
}

class _OutOfStockBrowseApi implements B2cCatalogApi {
  @override
  Future<List<B2cStore>> stores() async => const <B2cStore>[];

  @override
  Future<List<B2cCategory>> categories(int storeId) async =>
      const <B2cCategory>[];

  @override
  Future<List<B2cOffer>> offers(int storeId) async => const <B2cOffer>[];

  @override
  Future<List<B2cBanner>> banners(int storeId) async => const <B2cBanner>[];

  @override
  Future<List<B2cProduct>> products(
    int storeId, {
    String? query,
    int? categoryId,
    String? sort,
    String? direction,
  }) async => const <B2cProduct>[
        B2cProduct(
          id: 18,
          name: 'Sold Out Coffee',
          sku: 'OOS-18',
          price: 2.5,
          currency: 'KWD',
          availableQuantity: 0,
          isAvailable: false,
          availabilityState: 'OUT_OF_STOCK',
        ),
      ];

  @override
  Future<B2cProduct> product(int productId, {required int storeId}) async =>
      B2cProduct(
        id: productId,
        name: 'Sold Out Coffee',
        sku: 'OOS-18',
        price: 2.5,
        currency: 'KWD',
        availableQuantity: 0,
        isAvailable: false,
        availabilityState: 'OUT_OF_STOCK',
      );
}
