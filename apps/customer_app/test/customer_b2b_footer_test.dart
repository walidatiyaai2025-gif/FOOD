import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/localization/app_translations.dart';
import 'package:foodex_customer_app/core/routing/customer_commerce_context.dart';
import 'package:foodex_customer_app/shared/customer_persistent_footer.dart';

void main() {
  testWidgets(
    'Wholesale footer has five destinations and Shopping opens products',
    (tester) async {
      const commerceContext = CustomerCommerceContext(
        channel: CustomerCommerceChannel.wholesale,
        storeId: 70,
      );

      await tester.pumpWidget(
        AppTranslations(
          locale: const Locale('en'),
          overrides: const {},
          child: MaterialApp(
            home: const Scaffold(
              body: Column(
                children: [
                  Spacer(),
                  CustomerPersistentFooter(
                    commerceContext: commerceContext,
                    activeDestination: CustomerFooterDestination.products,
                  ),
                ],
              ),
            ),
            onGenerateRoute: (settings) => MaterialPageRoute<void>(
              settings: settings,
              builder: (_) => Scaffold(
                body: Text(
                  settings.name ?? '',
                  key: const ValueKey('route-name'),
                ),
              ),
            ),
          ),
        ),
      );

      for (final destination in ['home', 'products', 'orders', 'invoices', 'account']) {
        expect(
          find.byKey(ValueKey('customer-footer-$destination')),
          findsOneWidget,
        );
      }
      expect(find.byKey(const ValueKey('customer-footer-home')), findsOneWidget);
      expect(find.byKey(const ValueKey('customer-footer-cart')), findsNothing);
      expect(find.text('Shopping'), findsOneWidget);
      expect(find.text('My orders'), findsOneWidget);
      expect(find.text('Invoices'), findsOneWidget);
      expect(find.text('More'), findsOneWidget);

      await tester.tap(
        find.byKey(const ValueKey('customer-footer-products')),
      );
      await tester.pumpAndSettle();

      final route = Uri.parse(
        tester.widget<Text>(find.byKey(const ValueKey('route-name'))).data!,
      );
      expect(route.path, '/b2b/products');
      expect(route.queryParameters['store_id'], '70');
      expect(route.queryParameters['channel'], 'wholesale');
    },
  );
}
