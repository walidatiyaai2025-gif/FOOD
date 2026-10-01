import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/api/b2c_catalog_api.dart';
import 'package:foodex_customer_app/core/routing/customer_commerce_context.dart';
import 'package:foodex_customer_app/features/retail/catalog/retail_catalog_screens.dart';
import 'package:foodex_customer_app/features/retail/customer_ui_v3/customer_retail_shell.dart';
import 'package:foodex_customer_app/features/retail/customer_ui_v3/retail_home_v3_screen.dart';

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
      await tester.pumpAndSettle();

      expect(find.byKey(const ValueKey('retail-catalog-home')), findsOneWidget);
      expect(find.byKey(const ValueKey('retail-catalog-search')), findsOneWidget);
      expect(find.byKey(const ValueKey('retail-category-3')), findsOneWidget);
      expect(
        find.byKey(const ValueKey('retail-shell-nav-home')),
        findsOneWidget,
      );
      expect(
        find.byKey(const ValueKey('retail-shell-nav-orders')),
        findsNothing,
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

      final product = find.byKey(const ValueKey('retail-product-42'));
      final mainScroll = find.byType(Scrollable).first;
      await tester.scrollUntilVisible(
        product,
        240,
        scrollable: mainScroll,
      );
      await tester.drag(mainScroll, const Offset(0, -160));
      await tester.pumpAndSettle();
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
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets('floating navigation preserves authoritative Retail store context',
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
