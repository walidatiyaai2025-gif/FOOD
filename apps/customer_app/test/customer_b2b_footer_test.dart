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

  testWidgets(
    'persistent footer survives 320px width and enlarged text in LTR and RTL',
    (tester) async {
      tester.view.physicalSize = const Size(320, 720);
      tester.view.devicePixelRatio = 1;
      addTearDown(() {
        tester.view.resetPhysicalSize();
        tester.view.resetDevicePixelRatio();
      });

      const commerceContext = CustomerCommerceContext(
        channel: CustomerCommerceChannel.wholesale,
        storeId: 70,
      );

      Future<void> pumpDirection({
        required Locale locale,
        required TextDirection direction,
      }) async {
        await tester.pumpWidget(
          AppTranslations(
            locale: locale,
            overrides: const {},
            child: MaterialApp(
              home: MediaQuery(
                data: MediaQueryData(
                  size: const Size(320, 720),
                  textScaler: const TextScaler.linear(1.3),
                  viewPadding: const EdgeInsets.only(bottom: 24),
                ),
                child: Directionality(
                  textDirection: direction,
                  child: const Scaffold(
                    body: CustomerPersistentFooterDock(
                      commerceContext: commerceContext,
                      activeDestination: CustomerFooterDestination.account,
                    ),
                  ),
                ),
              ),
            ),
          ),
        );
        await tester.pumpAndSettle();

        expect(
          find.byKey(const ValueKey('customer-persistent-footer')),
          findsOneWidget,
        );
        for (final destination
            in ['home', 'products', 'orders', 'invoices', 'account']) {
          expect(
            find.byKey(ValueKey('customer-footer-$destination')),
            findsOneWidget,
          );
        }
        expect(
          tester.getSize(
            find.byKey(const ValueKey('customer-persistent-footer')),
          ).height,
          greaterThanOrEqualTo(82),
        );
        expect(tester.takeException(), isNull);
      }

      await pumpDirection(
        locale: const Locale('en'),
        direction: TextDirection.ltr,
      );
      await pumpDirection(
        locale: const Locale('ar'),
        direction: TextDirection.rtl,
      );
    },
  );
}
