import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/app.dart';
import 'package:foodex_customer_app/core/api/b2c_catalog_api.dart';
import 'package:foodex_customer_app/core/auth/customer_session.dart';

void main() {
  testWidgets('B2C guest product detail renders authoritative API data in RTL', (tester) async {
    await tester.pumpWidget(
      FoodexCustomerApp(
        initialRoute: '/products/42?store=7',
        b2cCatalogApi: _FakeCatalogApi(),
      ),
    );
    await tester.pumpAndSettle();

    expect(Directionality.of(tester.element(find.text('تفاصيل المنتج'))), TextDirection.rtl);
    expect(find.text('Tomato Box'), findsOneWidget);
    expect(find.textContaining('3.250 KWD'), findsOneWidget);
    expect(find.text('إضافة إلى السلة'), findsOneWidget);
  });

  testWidgets('B2C store selection propagates store context to home', (tester) async {
    await tester.pumpWidget(
      FoodexCustomerApp(
        initialRoute: '/stores',
        b2cCatalogApi: _FakeCatalogApi(),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('Salmiya Store'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('b2c-store-7')));
    await tester.pumpAndSettle();

    expect(find.text('/home?store=7'), findsOneWidget);
    expect(find.text('Vegetables'), findsOneWidget);
    expect(find.text('Weekend Offer'), findsOneWidget);
    expect(find.text('Tomato Box'), findsOneWidget);
  });

  testWidgets('B2C products render remote data and preserve store in detail navigation', (tester) async {
    await tester.pumpWidget(
      FoodexCustomerApp(
        initialRoute: '/products?store=7',
        b2cCatalogApi: _FakeCatalogApi(),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('Tomato Box'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('b2c-product-42')));
    await tester.pumpAndSettle();

    expect(find.text('/products/42?store=7'), findsOneWidget);
    expect(find.byKey(const ValueKey('b2c-product-detail')), findsOneWidget);
  });

  testWidgets('B2C authenticated checkout exposes address payment and submit states', (tester) async {
    await tester.pumpWidget(
      FoodexCustomerApp(
        session: const CustomerSession.authenticated(CustomerChannel.b2c),
        initialRoute: '/checkout/address-payment',
        b2cCatalogApi: _FakeCatalogApi(),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('العنوان والدفع'), findsOneWidget);
    expect(find.text('عنوان التوصيل'), findsOneWidget);
    expect(find.text('طريقة الدفع'), findsOneWidget);
    expect(find.text('تأكيد الطلب'), findsOneWidget);
  });

  testWidgets('B2C cart has explicit empty state', (tester) async {
    await tester.pumpWidget(
      FoodexCustomerApp(
        initialRoute: '/cart',
        b2cCatalogApi: _FakeCatalogApi(),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('السلة فارغة'), findsOneWidget);
  });
}

class _FakeCatalogApi implements B2cCatalogApi {
  @override
  Future<List<B2cStore>> stores() async =>
      const [B2cStore(id: 7, name: 'Salmiya Store', code: 'SLM')];

  @override
  Future<List<B2cCategory>> categories(int storeId) async =>
      const [B2cCategory(id: 3, name: 'Vegetables')];

  @override
  Future<List<B2cOffer>> offers(int storeId) async =>
      const [B2cOffer(id: 9, name: 'Weekend Offer', type: 'percentage', value: 10)];

  @override
  Future<List<B2cProduct>> products(
    int storeId, {
    String? query,
    int? categoryId,
  }) async =>
      const [
        B2cProduct(
          id: 42,
          name: 'Tomato Box',
          sku: 'TOM-42',
          price: 3.25,
        ),
      ];

  @override
  Future<B2cProduct> product(int productId, {required int storeId}) async =>
      const B2cProduct(
        id: 42,
        name: 'Tomato Box',
        sku: 'TOM-42',
        price: 3.25,
        description: 'Fresh product',
      );
}
