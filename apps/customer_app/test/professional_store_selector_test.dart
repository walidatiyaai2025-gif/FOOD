import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/app.dart';
import 'package:foodex_customer_app/core/api/b2c_account_api.dart';
import 'package:foodex_customer_app/core/api/b2c_catalog_api.dart';
import 'package:foodex_customer_app/core/api/customer_action_api.dart';
import 'package:foodex_customer_app/core/api/storefront_api.dart';
import 'package:foodex_customer_app/core/auth/customer_session.dart';

void main() {
  testWidgets('selector partitions wholesale and retail and disables closed stores', (tester) async {
    await tester.binding.setSurfaceSize(const Size(390, 844));
    addTearDown(() => tester.binding.setSurfaceSize(null));

    await tester.pumpWidget(FoodexCustomerApp(
      initialRoute: '/customer/store-selector',
      session: const CustomerSession.authenticated(CustomerChannel.b2c, accessToken: 'token', b2bRetailStoreId: 7),
      storefrontApi: const _SelectorApi(),
      b2cCatalogApi: const _CatalogApi(),
      b2cAccountApi: const _AccountApi(),
      actionApi: const _ActionApi(),
    ));
    await tester.pumpAndSettle();

    expect(find.text('جملة'), findsOneWidget);
    expect(find.text('التجزئة'), findsOneWidget);
    expect(find.text('مخزن الجملة'), findsOneWidget);
    expect(find.text('سوبر ماركت - 1'), findsNothing);

    await tester.tap(find.text('التجزئة'));
    await tester.pumpAndSettle();
    expect(find.text('مخزن الجملة'), findsNothing);
    expect(find.text('سوبر ماركت - 1'), findsOneWidget);
    expect(find.text('صيدلية العزيزي'), findsOneWidget);
    expect(find.text('مغلق الآن'), findsWidgets);
    expect(tester.takeException(), isNull);
  });
}

class _SelectorApi implements StorefrontApi {
  const _SelectorApi();
  @override
  Future<Map<String, dynamic>> selection({String? countryCode, String? city, String? area, bool support = false}) async => {
    'wholesale_stores': [
      {'id': 70, 'name': 'مخزن الجملة', 'channel': 'b2b', 'is_active': true, 'retail_context_ids': [7]},
    ],
    'retail_stores': [
      {'id': 7, 'name': 'سوبر ماركت - 1', 'channel': 'b2c', 'theme_code': 'retail_grocery', 'is_active': true, 'address': 'الكويت'},
      {'id': 8, 'name': 'صيدلية العزيزي', 'channel': 'b2c', 'theme_code': 'retail_pharmacy', 'is_active': false, 'address': 'الكويت'},
    ],
  };
  @override
  Future<Map<String, dynamic>> retailHome(int storeId) async => {};
  @override
  Future<Map<String, dynamic>> wholesaleHome(int storeId) async => {};
  @override
  Future<Map<String, dynamic>> b2bCheckoutOptions(int storeId) async => {};
}

class _CatalogApi implements B2cCatalogApi {
  const _CatalogApi();
  @override
  Future<List<B2cStore>> stores() async => const [];
  @override
  dynamic noSuchMethod(Invocation invocation) => Future.value([]);
}
class _AccountApi implements B2cAccountApi {
  const _AccountApi();
  @override
  dynamic noSuchMethod(Invocation invocation) => Future.value([]);
}
class _ActionApi implements CustomerActionApi {
  const _ActionApi();
  @override
  dynamic noSuchMethod(Invocation invocation) => Future.value({});
}
