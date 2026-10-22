import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/api/b2c_catalog_api.dart';
import 'package:foodex_customer_app/core/api/storefront_api.dart';
import 'package:foodex_customer_app/core/routing/customer_commerce_context.dart';
import 'package:foodex_customer_app/features/retail/catalog/retail_catalog_screens.dart';
import 'package:foodex_customer_app/features/retail/customer_ui_v3/customer_retail_shell.dart';
import 'package:foodex_customer_app/features/retail/customer_ui_v3/retail_home_v3_screen.dart';
import 'package:foodex_customer_app/features/storefront/storefront_design_system.dart';

void main() {
  testWidgets(
    'Customer UI V3 home keeps store-scoped actions usable on narrow RTL layout',
    (tester) async {
      tester.view.physicalSize = const Size(360, 800);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);

      int? searchStore;
      String? searchQuery;
      int? capturedCategoryId;
      int? capturedProductId;
      int? addedProductId;

      final navigation = RetailCatalogNavigation(
        openProducts: (
          BuildContext context, {
          required int storeId,
          String? query,
          int? categoryId,
        }) {
          searchStore = storeId;
          searchQuery = query;
          capturedCategoryId = categoryId;
        },
        openProduct: (
          BuildContext context, {
          required int storeId,
          required int productId,
        }) {
          searchStore = storeId;
          capturedProductId = productId;
        },
      );

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
              child: CustomerRetailShell(
                commerceContext: const CustomerCommerceContext(
                  channel: CustomerCommerceChannel.retail,
                  storeId: 7,
                ),
                activeDestination: CustomerRetailDestination.home,
                isAuthenticated: false,
                child: RetailHomeV3Screen(
                  storeId: 7,
                  catalogApi: _FakeCatalogApi(),
                  storefrontApi: const _FakeStorefrontApi(),
                  isAuthenticated: false,
                  navigation: navigation,
                  onAddToCart: ({
                    required int storeId,
                    required int productId,
                    required double quantity,
                  }) async {
                    searchStore = storeId;
                    addedProductId = productId;
                  },
                ),
              ),
            ),
          ),
        ),
      );
      await _pumpRetailHomeReady(tester);

      expect(find.byKey(const ValueKey('retail-catalog-home')), findsOneWidget);
      final sharedSurface = tester.widget<FoodexUnifiedHeaderSurface>(
        find.byType(FoodexUnifiedHeaderSurface),
      );
      expect(sharedSurface.palette.primary, FoodexPalette.wholesale.primary);
      expect(
        sharedSurface.palette.primaryDark,
        FoodexPalette.wholesale.primaryDark,
      );
      expect(sharedSurface.title, 'Retail Seven');
      expect(sharedSurface.address, 'Retail Area');
      expect(
          find.byKey(const ValueKey('retail-catalog-search')), findsOneWidget);
      expect(find.byKey(const ValueKey('retail-category-3')), findsOneWidget);
      expect(find.text('Weekend Offer'), findsNothing);
      expect(
        find.byKey(const ValueKey('retail-shell-nav-home')),
        findsOneWidget,
      );
      expect(
        find.byKey(const ValueKey('retail-shell-nav-orders')),
        findsOneWidget,
      );
      expect(
        find.byKey(const ValueKey('retail-shell-nav-account')),
        findsOneWidget,
      );
      expect(tester.takeException(), isNull);

      await tester.enterText(
        find.byKey(const ValueKey('retail-catalog-search')),
        ' tomato ',
      );
      await tester.testTextInput.receiveAction(TextInputAction.search);
      await tester.pump();
      expect(searchStore, 7);
      expect(searchQuery, 'tomato');

      final category = find.byKey(const ValueKey('retail-category-3'));
      await tester.ensureVisible(category);
      await tester.tap(category);
      await tester.pump();
      expect(capturedCategoryId, 3);

      final mainScroll = _homeVerticalScroll();
      final tickerHeader =
          find.byKey(const ValueKey('retail-best-seller-ticker-header'));
      await _dragUntilFound(
        tester,
        scrollable: mainScroll,
        target: tickerHeader,
      );

      const tickerProductIds = <int>[44, 43, 42];
      final visibleTickerProductIds = tickerProductIds
          .where(
            (productId) => find
                .byKey(
                  ValueKey('retail-best-seller-ticker-product-$productId'),
                )
                .evaluate()
                .isNotEmpty,
          )
          .toList(growable: false);
      expect(visibleTickerProductIds, hasLength(1));

      final initialTickerProductId = visibleTickerProductIds.single;
      final initialTickerIndex =
          tickerProductIds.indexOf(initialTickerProductId);
      final nextTickerProductId =
          tickerProductIds[(initialTickerIndex + 1) % tickerProductIds.length];

      await tester.pump(const Duration(seconds: 10));
      await tester.pump(const Duration(seconds: 5));
      final nextTickerProduct = find.byKey(
        ValueKey('retail-best-seller-ticker-product-$nextTickerProductId'),
      );
      expect(nextTickerProduct, findsOneWidget);
      await tester.tap(nextTickerProduct);
      await tester.pump();
      expect(capturedProductId, nextTickerProductId);

      final bestGridFinder =
          find.byKey(const ValueKey('retail-best-sellers-grid'));
      await _dragUntilFound(
        tester,
        scrollable: mainScroll,
        target: bestGridFinder,
      );
      final bestGrid = tester.widget<GridView>(bestGridFinder);
      final bestDelegate =
          bestGrid.gridDelegate as SliverGridDelegateWithFixedCrossAxisCount;
      expect(bestDelegate.crossAxisCount, 3);
      expect(
          find.byKey(const ValueKey('retail-best-seller-44')), findsOneWidget);
      expect(
          find.byKey(const ValueKey('retail-best-seller-43')), findsOneWidget);
      expect(
          find.byKey(const ValueKey('retail-best-seller-42')), findsOneWidget);

      final product = find.byKey(const ValueKey('retail-product-42'));
      await _dragUntilFound(
        tester,
        scrollable: mainScroll,
        target: product,
      );
      await tester.tap(product);
      await tester.pump();
      expect(capturedProductId, 42);

      final add = find.descendant(
        of: product,
        matching: find.byIcon(Icons.add_rounded),
      );
      await tester.tap(add);
      await tester.pump();
      expect(addedProductId, 42);

      final viewMore = find.byKey(const ValueKey('retail-home-view-more'));
      await _dragUntilFound(
        tester,
        scrollable: mainScroll,
        target: viewMore,
      );
      await tester.tap(viewMore);
      await tester.pump();
      expect(searchStore, 7);
      expect(capturedCategoryId, isNull);
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets(
    'Customer VNext Home keeps three-column geometry on standard LTR phone',
    (tester) async {
      tester.view.physicalSize = const Size(430, 932);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);

      await tester.pumpWidget(
        MaterialApp(
          locale: const Locale('en'),
          home: CustomerRetailShell(
            commerceContext: const CustomerCommerceContext(
              channel: CustomerCommerceChannel.retail,
              storeId: 7,
            ),
            activeDestination: CustomerRetailDestination.home,
            isAuthenticated: true,
            child: RetailHomeV3Screen(
              storeId: 7,
              catalogApi: _FakeCatalogApi(),
              storefrontApi: const _FakeStorefrontApi(),
              isAuthenticated: true,
              navigation: const RetailCatalogNavigation(
                openProducts: _noopOpenProducts,
              ),
            ),
          ),
        ),
      );
      await _pumpRetailHomeReady(tester);

      final mainScroll = _homeVerticalScroll();
      final bestGrid = find.byKey(const ValueKey('retail-best-sellers-grid'));
      await _dragUntilFound(
        tester,
        scrollable: mainScroll,
        target: bestGrid,
      );

      final allGridFinder =
          find.byKey(const ValueKey('retail-all-products-grid'));
      await _dragUntilFound(
        tester,
        scrollable: mainScroll,
        target: allGridFinder,
      );
      final allGrid = tester.widget<GridView>(allGridFinder);
      final allDelegate =
          allGrid.gridDelegate as SliverGridDelegateWithFixedCrossAxisCount;
      expect(allDelegate.crossAxisCount, 3);

      final viewMore = find.byKey(const ValueKey('retail-home-view-more'));
      await _dragUntilFound(
        tester,
        scrollable: mainScroll,
        target: viewMore,
      );
      expect(find.text('View more'), findsOneWidget);
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets('Customer VNext Home exposes an explicit retryable offline state',
      (tester) async {
    await tester.pumpWidget(
      MaterialApp(
        locale: const Locale('en'),
        home: RetailHomeV3Screen(
          storeId: 7,
          catalogApi: _OfflineCatalogApi(),
          isAuthenticated: false,
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(
      find.byKey(const ValueKey('retail-home-offline')),
      findsOneWidget,
    );
    expect(find.byIcon(Icons.wifi_off_rounded), findsOneWidget);
    expect(find.text('Retry'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets(
      'fixed footer reserves business-action space and Home returns to platform store switcher',
      (tester) async {
    String? pushedRoute;

    await tester.pumpWidget(
      MaterialApp(
        onGenerateRoute: (settings) {
          pushedRoute = settings.name;
          return MaterialPageRoute<void>(
            settings: settings,
            builder: (_) => const Scaffold(body: Text('destination')),
          );
        },
        home: CustomerRetailShell(
          commerceContext: const CustomerCommerceContext(
            channel: CustomerCommerceChannel.retail,
            storeId: 17,
          ),
          activeDestination: CustomerRetailDestination.cart,
          isAuthenticated: true,
          child: const Scaffold(
            body: SizedBox.expand(),
            bottomNavigationBar: SizedBox(
              key: ValueKey('business-fixed-action'),
              height: 56,
            ),
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    final actionBottom = tester
        .getBottomLeft(
          find.byKey(const ValueKey('business-fixed-action')),
        )
        .dy;
    final footerTop = tester
        .getTopLeft(
          find.byKey(const ValueKey('customer-persistent-footer')),
        )
        .dy;
    expect(actionBottom, lessThanOrEqualTo(footerTop));

    await tester.tap(
      find.byKey(const ValueKey('retail-shell-nav-home')),
    );
    await tester.pumpAndSettle();
    expect(pushedRoute, isNotNull);
    final homeUri = Uri.parse(pushedRoute!);
    expect(homeUri.path, '/b2b/home');
  });

  testWidgets(
      'floating navigation preserves authoritative Retail store context',
      (tester) async {
    String? pushedRoute;

    await tester.pumpWidget(
      MaterialApp(
        onGenerateRoute: (settings) {
          pushedRoute = settings.name;
          return MaterialPageRoute<void>(
            settings: settings,
            builder: (_) => const Scaffold(body: Text('destination')),
          );
        },
        home: CustomerRetailShell(
          commerceContext: const CustomerCommerceContext(
            channel: CustomerCommerceChannel.retail,
            storeId: 17,
          ),
          activeDestination: CustomerRetailDestination.home,
          isAuthenticated: true,
          child: const Scaffold(body: SizedBox.expand()),
        ),
      ),
    );
    await tester.pump();

    expect(
      find.byKey(const ValueKey('retail-shell-nav-orders')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('retail-shell-nav-account')),
      findsOneWidget,
    );

    await tester.tap(
      find.byKey(const ValueKey('retail-shell-nav-products')),
    );
    await tester.pumpAndSettle();

    expect(pushedRoute, isNotNull);
    final uri = Uri.parse(pushedRoute!);
    expect(uri.path, '/products');
    expect(uri.queryParameters['store_id'], '17');
    expect(uri.queryParameters['channel'], 'retail');
  });
}

Finder _homeVerticalScroll() {
  final homeList = find.byKey(const ValueKey('retail-home-scroll'));
  return find
      .descendant(
        of: homeList,
        matching: find.byType(Scrollable),
      )
      .first;
}

Future<void> _dragUntilFound(
  WidgetTester tester, {
  required Finder scrollable,
  required Finder target,
}) async {
  for (var attempt = 0; attempt < 30 && target.evaluate().isEmpty; attempt++) {
    await tester.drag(scrollable, const Offset(0, -220));
    await tester.pump();
  }
  expect(
    target,
    findsOneWidget,
    reason:
        'Expected lazy Home content to become reachable by vertical scroll.',
  );
  await tester.ensureVisible(target);
  await tester.pump(const Duration(milliseconds: 300));
}

Future<void> _pumpRetailHomeReady(WidgetTester tester) async {
  for (var attempt = 0; attempt < 12; attempt++) {
    await tester.pump(const Duration(milliseconds: 50));
    if (find
        .byKey(const ValueKey('retail-catalog-home'))
        .evaluate()
        .isNotEmpty) {
      return;
    }
  }
  expect(
    find.byKey(const ValueKey('retail-catalog-home')),
    findsOneWidget,
    reason: 'Expected Retail Home to finish its initial async load.',
  );
}

void _noopOpenProducts(
  BuildContext context, {
  required int storeId,
  String? query,
  int? categoryId,
}) {}

class _FakeCatalogApi implements B2cCatalogApi {
  @override
  Future<List<B2cStore>> stores() async => const <B2cStore>[];

  @override
  Future<List<B2cCategory>> categories(int storeId) async => const [
        B2cCategory(id: 3, name: 'Vegetables'),
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
          name: 'Tomato Box',
          sku: 'TOM-42',
          price: 3.25,
          currency: 'KWD',
          categoryId: 3,
        ),
        B2cProduct(
          id: 43,
          name: 'Mango Pack',
          sku: 'MAN-43',
          price: 4.10,
          currency: 'KWD',
          categoryId: 3,
        ),
        B2cProduct(
          id: 44,
          name: 'Okra Pack',
          sku: 'OKR-44',
          price: 2.95,
          currency: 'KWD',
          categoryId: 3,
        ),
        B2cProduct(
          id: 45,
          name: 'French Fries',
          sku: 'FRY-45',
          price: 1.80,
          currency: 'KWD',
          categoryId: 3,
        ),
        B2cProduct(
          id: 46,
          name: 'Mozzarella',
          sku: 'MOZ-46',
          price: 5.30,
          currency: 'KWD',
          categoryId: 3,
        ),
        B2cProduct(
          id: 47,
          name: 'Chicken Strips',
          sku: 'CHK-47',
          price: 6.40,
          currency: 'KWD',
          categoryId: 3,
        ),
        B2cProduct(
          id: 48,
          name: 'Tomato Sauce',
          sku: 'SAU-48',
          price: 2.20,
          currency: 'KWD',
          categoryId: 3,
        ),
      ];

  @override
  Future<List<B2cOffer>> offers(int storeId) async => const [
        B2cOffer(
          id: 9,
          name: 'Weekend Offer',
          type: 'percentage',
          value: 10,
        ),
      ];

  @override
  Future<List<B2cBanner>> banners(int storeId) async => const [
        B2cBanner(id: 11, title: 'Fresh every day'),
      ];

  @override
  Future<B2cProduct> product(int productId, {required int storeId}) async =>
      B2cProduct(
        id: productId,
        name: 'Tomato Box',
        sku: 'TOM-42',
        price: 3.25,
      );
}

class _FakeStorefrontApi implements StorefrontApi {
  const _FakeStorefrontApi();

  @override
  Future<Map<String, dynamic>> retailHome(int storeId) async => {
        'store': {
          'id': storeId,
          'name': 'Retail Seven',
          'logo_url': null,
        },
        'branding': {
          'logo_url': null,
          'address': 'Retail Area',
        },
        'sections': [
          {
            'type': 'best_sellers',
            'config': {
              'product_ids': [44, 43, 42],
              'limit': 3,
            },
          },
        ],
      };

  @override
  Future<Map<String, dynamic>> selection({
    String? countryCode,
    String? city,
    String? area,
    bool support = false,
  }) async =>
      const <String, dynamic>{};

  @override
  Future<Map<String, dynamic>> wholesaleHome(int storeId) async =>
      const <String, dynamic>{};

  @override
  Future<Map<String, dynamic>> b2bCheckoutOptions(int storeId) async =>
      const <String, dynamic>{};
}

class _OfflineCatalogApi extends _FakeCatalogApi {
  @override
  Future<List<B2cCategory>> categories(int storeId) async {
    throw const B2cCatalogException(B2cCatalogException.networkUnavailable);
  }
}
