import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:foodex_customer_app/core/auth/customer_session.dart';
import 'package:foodex_customer_app/core/localization/app_translations.dart';
import 'package:foodex_customer_app/features/storefront/platform_marketplace_screen.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  testWidgets('marketplace loads guest platform storefront and opens exact retail store',
      (tester) async {
    final requests = <Uri>[];
    final client = MockClient((request) async {
      requests.add(request.url);
      if (request.url.path == '/api/v1/platform/products/42') {
        return http.Response(
          jsonEncode({
            'id': 42,
            'name': 'Wholesale Rice',
            'sku': 'W-RICE-42',
            'barcode': '123456789',
            'unit_price': 12.5,
            'currency': 'EGP',
            'description': 'Bulk rice for wholesale customers',
          }),
          200,
        );
      }
      return http.Response(
        jsonEncode({
          'store': {
            'id': 70,
            'code': 'MAIN-B2B',
            'name': 'FOODEX Main Wholesale',
            'channel': 'b2b',
          },
          'hero': null,
          'offers': [
            {
              'id': 3,
              'name': 'Wholesale Launch Offer',
              'type': 'fixed',
              'value': 2.5,
            },
          ],
          'products': {
            'data': [
              {
                'id': 42,
                'name': 'Wholesale Rice',
                'unit_price': 12.5,
                'currency': 'EGP',
              }
            ],
          },
          'retail_banners': [
            {
              'id': 7,
              'code': 'RETAIL-07',
              'name': 'Retail Seven',
              'title': 'Retail Seven Offer',
              'banner_url': null,
              'logo_url': null,
              'channel': 'b2c',
            },
            {
              'id': 8,
              'code': 'RETAIL-08',
              'name': 'Retail Eight',
              'title': 'Retail Eight Offer',
              'banner_url': null,
              'logo_url': null,
              'channel': 'b2c',
            },
          ],
        }),
        200,
        headers: const {'content-type': 'application/json'},
      );
    });

    await tester.pumpWidget(
      AppTranslations(
        locale: const Locale('en'),
        overrides: const {},
        child: MaterialApp(
          locale: const Locale('en'),
          home: PlatformMarketplaceScreen(
            session: const CustomerSession.guest(),
            onPlatformRegistered: (_) {},
            client: client,
          ),
          onGenerateRoute: (settings) => MaterialPageRoute<void>(
            settings: settings,
            builder: (_) => Scaffold(
              body: Text(settings.name ?? '', key: const ValueKey('route-name')),
            ),
          ),
        ),
      ),
    );

    await tester.pumpAndSettle();

    expect(requests, hasLength(1));
    expect(requests.single.path, '/api/v1/platform/storefront');
    expect(find.text('FOODEX'), findsOneWidget);
    expect(find.text('Wholesale Launch Offer'), findsOneWidget);
    expect(
      find.byKey(const ValueKey('marketplace-wholesale-offers')),
      findsOneWidget,
    );
    expect(find.text('Retail Seven Offer'), findsOneWidget);
    expect(
      find.byKey(const ValueKey('marketplace-retail-carousel')),
      findsOneWidget,
    );

    await tester.tap(find.text('Retail Seven Offer'));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('route-name')), findsOneWidget);
    expect(find.text('/retail/7/home'), findsOneWidget);
  });

  testWidgets('marketplace retail carousel supports manual swipe', (tester) async {
    final client = MockClient((request) async => http.Response(
          jsonEncode({
            'store': {'id': 70, 'name': 'Wholesale', 'channel': 'b2b'},
            'hero': null,
            'products': {'data': []},
            'retail_banners': [
              {
                'id': 7,
                'name': 'Retail Seven',
                'title': 'Retail Seven Offer',
                'banner_url': null,
                'logo_url': null,
              },
              {
                'id': 8,
                'name': 'Retail Eight',
                'title': 'Retail Eight Offer',
                'banner_url': null,
                'logo_url': null,
              },
            ],
          }),
          200,
        ));

    await tester.pumpWidget(
      AppTranslations(
        locale: const Locale('en'),
        overrides: const {},
        child: MaterialApp(
          home: PlatformMarketplaceScreen(
            session: const CustomerSession.guest(),
            onPlatformRegistered: (_) {},
            client: client,
          ),
        ),
      ),
    );

    await tester.pumpAndSettle();
    final carousel = find.byKey(const ValueKey('marketplace-retail-carousel'));
    expect(carousel, findsOneWidget);

    await tester.drag(carousel, const Offset(-350, 0));
    await tester.pumpAndSettle();

    expect(find.text('Retail Eight Offer'), findsOneWidget);
  });

  testWidgets('guest can search wholesale catalog and inspect product before login',
      (tester) async {
    final requests = <Uri>[];
    final client = MockClient((request) async {
      requests.add(request.url);
      if (request.url.path == '/api/v1/platform/products/42') {
        return http.Response(
          jsonEncode({
            'id': 42,
            'name': 'Wholesale Rice',
            'sku': 'W-RICE-42',
            'barcode': '123456789',
            'unit_price': 12.5,
            'currency': 'EGP',
            'description': 'Bulk rice for wholesale customers',
          }),
          200,
        );
      }

      return http.Response(
        jsonEncode({
          'store': {'id': 70, 'name': 'Wholesale', 'channel': 'b2b'},
          'hero': null,
          'products': {
            'data': [
              {
                'id': 42,
                'name': 'Wholesale Rice',
                'unit_price': 12.5,
                'currency': 'EGP',
              }
            ],
          },
          'retail_banners': const [],
        }),
        200,
      );
    });

    await tester.pumpWidget(
      AppTranslations(
        locale: const Locale('en'),
        overrides: const {},
        child: MaterialApp(
          home: PlatformMarketplaceScreen(
            session: const CustomerSession.guest(),
            onPlatformRegistered: (_) {},
            client: client,
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    await tester.enterText(
      find.byKey(const ValueKey('marketplace-search')),
      'W-RICE-42',
    );
    await tester.testTextInput.receiveAction(TextInputAction.search);
    await tester.pumpAndSettle();

    expect(
      requests.where((uri) => uri.path == '/api/v1/platform/storefront').last
          .queryParameters['q'],
      'W-RICE-42',
    );

    await tester.tap(find.text('Wholesale Rice'));
    await tester.pumpAndSettle();

    expect(
      find.byKey(const ValueKey('marketplace-wholesale-product-title')),
      findsOneWidget,
    );
    expect(find.textContaining('SKU: W-RICE-42'), findsOneWidget);
    expect(find.textContaining('Barcode: 123456789'), findsOneWidget);
    expect(
      requests.any((uri) => uri.path == '/api/v1/platform/products/42'),
      isTrue,
    );
    expect(
      find.byKey(const ValueKey('marketplace-wholesale-buy')),
      findsOneWidget,
    );
  });


  testWidgets('guest wholesale buy keeps product and store context through login',
      (tester) async {
    final client = MockClient((request) async {
      if (request.url.path == '/api/v1/platform/products/42') {
        return http.Response(
          jsonEncode({
            'id': 42,
            'name': 'Wholesale Rice',
            'sku': 'W-RICE-42',
            'unit_price': 12.5,
            'currency': 'EGP',
          }),
          200,
        );
      }

      return http.Response(
        jsonEncode({
          'store': {'id': 70, 'name': 'Wholesale', 'channel': 'b2b'},
          'hero': null,
          'products': {
            'data': [
              {
                'id': 42,
                'name': 'Wholesale Rice',
                'unit_price': 12.5,
                'currency': 'EGP',
              }
            ],
          },
          'retail_banners': const [],
        }),
        200,
      );
    });

    await tester.pumpWidget(
      AppTranslations(
        locale: const Locale('en'),
        overrides: const {},
        child: MaterialApp(
          home: PlatformMarketplaceScreen(
            session: const CustomerSession.guest(),
            onPlatformRegistered: (_) {},
            client: client,
          ),
          onGenerateRoute: (settings) => MaterialPageRoute<void>(
            settings: settings,
            builder: (_) => Scaffold(
              body: Text(settings.name ?? '', key: const ValueKey('route-name')),
            ),
          ),
        ),
      ),
    );

    await tester.pumpAndSettle();
    await tester.tap(find.text('Wholesale Rice'));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('marketplace-wholesale-buy')));
    await tester.pumpAndSettle();

    expect(find.text('Sign in'), findsWidgets);
    await tester.tap(find.widgetWithText(OutlinedButton, 'Sign in'));
    await tester.pumpAndSettle();

    final routeText = tester.widget<Text>(
      find.byKey(const ValueKey('route-name')),
    );
    final uri = Uri.parse(routeText.data!);
    expect(uri.path, '/auth/checkout');
    expect(
      uri.queryParameters['next'],
      '/b2b/products/42?store_id=70',
    );
  });


  testWidgets('marketplace category chip filters wholesale API query', (tester) async {
    final requests = <Uri>[];
    final client = MockClient((request) async {
      requests.add(request.url);
      return http.Response(
        jsonEncode({
          'store': {'id': 70, 'name': 'Wholesale', 'channel': 'b2b'},
          'hero': null,
          'categories': [
            {'id': 9, 'name': 'Beverages', 'slug': 'beverages'},
          ],
          'products': {'data': []},
          'retail_banners': const [],
        }),
        200,
      );
    });

    await tester.pumpWidget(
      AppTranslations(
        locale: const Locale('en'),
        overrides: const {},
        child: MaterialApp(
          home: PlatformMarketplaceScreen(
            session: const CustomerSession.guest(),
            onPlatformRegistered: (_) {},
            client: client,
          ),
        ),
      ),
    );

    await tester.pumpAndSettle();
    expect(
      find.byKey(const ValueKey('marketplace-category-9')),
      findsOneWidget,
    );

    await tester.tap(find.byKey(const ValueKey('marketplace-category-9')));
    await tester.pumpAndSettle();

    expect(
      requests.where((uri) => uri.path == '/api/v1/platform/storefront').last
          .queryParameters['category_id'],
      '9',
    );
  });


  testWidgets(
      'marketplace header stays responsive with scan and locale controls at 390px',
      (tester) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(() {
      tester.view.resetPhysicalSize();
      tester.view.resetDevicePixelRatio();
    });

    Locale? requestedLocale;
    final client = MockClient((request) async => http.Response(
          jsonEncode({
            'store': {'id': 70, 'name': 'Wholesale', 'channel': 'b2b'},
            'hero': null,
            'categories': const [],
            'products': {'data': const []},
            'retail_banners': const [],
          }),
          200,
        ));

    await tester.pumpWidget(
      AppTranslations(
        locale: const Locale('ar'),
        overrides: const {},
        child: MaterialApp(
          locale: const Locale('ar'),
          supportedLocales: const [Locale('ar'), Locale('en')],
          localizationsDelegates: const [
            GlobalMaterialLocalizations.delegate,
            GlobalWidgetsLocalizations.delegate,
            GlobalCupertinoLocalizations.delegate,
          ],
          home: PlatformMarketplaceScreen(
            session: const CustomerSession.guest(),
            onPlatformRegistered: (_) {},
            onLocaleChanged: (locale) => requestedLocale = locale,
            client: client,
          ),
        ),
      ),
    );

    await tester.pumpAndSettle();

    expect(tester.takeException(), isNull);
    expect(
      Directionality.of(
        tester.element(find.byType(PlatformMarketplaceScreen)),
      ),
      TextDirection.rtl,
    );
    expect(find.byKey(const ValueKey('marketplace-scan')), findsOneWidget);
    expect(find.byKey(const ValueKey('marketplace-language')), findsOneWidget);
    expect(find.text('AR'), findsOneWidget);
    expect(find.byKey(const ValueKey('marketplace-cart')), findsOneWidget);
    expect(
      find.byKey(const ValueKey('marketplace-notifications')),
      findsOneWidget,
    );
    expect(find.byKey(const ValueKey('marketplace-auth-menu')), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('marketplace-scan')));
    await tester.pump();
    tester.testTextInput.enterText('123456789');
    await tester.pump();
    final search = tester.widget<TextField>(
      find.byKey(const ValueKey('marketplace-search')),
    );
    expect(search.controller?.text, '123456789');

    await tester.tap(find.byKey(const ValueKey('marketplace-language')));
    await tester.pump();
    expect(requestedLocale, const Locale('en'));
  });

}
