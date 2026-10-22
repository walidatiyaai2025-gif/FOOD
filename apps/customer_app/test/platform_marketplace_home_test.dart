import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:foodex_customer_app/core/auth/customer_session.dart';
import 'package:foodex_customer_app/core/routing/customer_commerce_context.dart';
import 'package:foodex_customer_app/core/localization/app_translations.dart';
import 'package:foodex_customer_app/features/storefront/platform_marketplace_screen.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  testWidgets(
      'marketplace loads guest platform storefront and opens exact retail store',
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
              'store_id': 7,
              'banner_id': 7001,
              'placement_id': 701,
              'placement_scope': 'platform_retail_store',
              'target_type': 'retail_store',
              'target_id': 7,
              'code': 'RETAIL-07',
              'name': 'Retail Seven',
              'title': 'Retail Seven Offer',
              'banner_url': null,
              'logo_url': null,
              'channel': 'b2c',
            },
            {
              'id': 8,
              'store_id': 8,
              'placement_scope': 'platform_retail_store',
              'target_type': 'retail_store',
              'target_id': 8,
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
              body:
                  Text(settings.name ?? '', key: const ValueKey('route-name')),
            ),
          ),
        ),
      ),
    );

    await tester.pumpAndSettle();

    expect(requests, hasLength(1));
    expect(requests.single.path, '/api/v1/platform/storefront');
    expect(requests.any((uri) => uri.path == '/api/v1/stores'), isFalse);
    expect(
      find.byKey(const ValueKey('marketplace-brand-title')),
      findsOneWidget,
    );
    expect(find.text('FOODEX Wholesale'), findsOneWidget);
    expect(
      find.byKey(const ValueKey('marketplace-wholesale-entry')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('marketplace-primary-banner')),
      findsOneWidget,
    );
    expect(
      find.byKey(
        const ValueKey('marketplace-retail-store-banner-section'),
      ),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('marketplace-retail-store-carousel')),
      findsOneWidget,
    );
    expect(find.text('FOODEX Wholesale').hitTestable(), findsOneWidget);
    expect(find.text('Retail Seven Offer').hitTestable(), findsOneWidget);

    await tester.tap(find.text('Retail Seven Offer').hitTestable());
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('route-name')), findsOneWidget);
    final routeText = tester.widget<Text>(
      find.byKey(const ValueKey('route-name')),
    );
    final route = Uri.parse(routeText.data!);
    expect(route.path, '/retail/7/home');
    expect(route.queryParameters['channel'], 'retail');
    expect(route.queryParameters['store_id'], '7');
    expect(route.queryParameters['source'], 'retail_banner');
    expect(route.queryParameters['placement_id'], '701');
  });

  testWidgets(
      'guest keeps Wholesale primary hero and rotates active Retail stores in the separate compact section',
      (tester) async {
    final client = MockClient((request) async {
      return http.Response(
          jsonEncode({
            'data': [
              {'id': 8, 'code': 'RETAIL-08', 'name': 'Primary Retail'},
              {'id': 11, 'code': 'RETAIL-11', 'name': 'Retail Eleven'},
              {'id': 12, 'code': 'RETAIL-12', 'name': 'Retail Twelve'},
            ],
          }),
          200,
        );
      }

      return http.Response(
        jsonEncode({
          'store': {'id': 70, 'name': 'Wholesale', 'channel': 'b2b'},
          'hero': {'title': 'FOODEX Wholesale', 'image_url': null},
          'products': {'data': []},
          'retail_banners': [
            {
              'id': 8,
              'store_id': 8,
              'placement_id': 801,
              'name': 'Primary Retail',
              'title': 'Primary Retail',
              'sort_order': 1,
            },
            {
              'id': 8002,
              'store_id': 8,
              'placement_id': 802,
              'name': 'Primary Retail',
              'title': 'Duplicate placement must not duplicate store',
              'sort_order': 2,
            },
            {
              'id': 11,
              'store_id': 11,
              'placement_id': 1101,
              'name': 'Retail Eleven',
              'title': 'Retail Eleven',
              'sort_order': 3,
            },
            {
              'id': 12,
              'store_id': 12,
              'placement_id': null,
              'name': 'Retail Twelve',
              'title': 'Retail Twelve',
              'placement_scope': 'active_retail_store_fallback',
              'sort_order': null,
            },
          ],
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
      find.byKey(const ValueKey('marketplace-primary-banner')),
      findsOneWidget,
    );
    expect(
      find.byKey(
        const ValueKey('marketplace-retail-store-banner-section'),
      ),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('marketplace-retail-store-carousel')),
      findsOneWidget,
    );
    expect(find.text('FOODEX Wholesale').hitTestable(), findsOneWidget);
    expect(find.text('Primary Retail').hitTestable(), findsOneWidget);
    expect(
      find.text('Duplicate placement must not duplicate store'),
      findsNothing,
    );

    await tester.pump(const Duration(seconds: 5));
    await tester.pump(const Duration(milliseconds: 450));
    expect(find.text('Retail Eleven').hitTestable(), findsOneWidget);

    await tester.pump(const Duration(seconds: 5));
    await tester.pump(const Duration(milliseconds: 450));
    expect(find.text('Retail Twelve').hitTestable(), findsOneWidget);

    await tester.pump(const Duration(seconds: 5));
    await tester.pump(const Duration(milliseconds: 450));
    expect(find.text('Primary Retail').hitTestable(), findsOneWidget);
  });

  testWidgets(
      'guest renders authoritative active Retail fallback rows without calling paginated stores endpoint',
      (tester) async {
    final requests = <Uri>[];
    final client = MockClient((request) async {
      requests.add(request.url);
      return http.Response(
        jsonEncode({
          'store': {
            'id': 70,
            'code': 'MAIN-B2B',
            'name': 'FOODEX Main Wholesale',
            'channel': 'b2b',
          },
          'hero': {
            'title': 'FOODEX Wholesale',
            'image_url': null,
          },
          'products': {'data': []},
          'retail_banners': [
            {
              'id': 11,
              'store_id': 11,
              'code': 'RETAIL-11',
              'name': 'Retail Eleven',
              'title': 'Retail Eleven',
              'theme_code': 'retail_grocery',
              'address': 'Guest Retail Area',
              'logo_url': null,
              'banner_url': null,
              'channel': 'b2c',
              'placement_scope': 'active_retail_store_fallback',
              'target_type': 'retail_store',
              'target_id': 11,
              'target_url': '/retail/11/home',
            },
          ],
        }),
        200,
        headers: const {'content-type': 'application/json'},
      );
    });

    await tester.pumpWidget(
      AppTranslations(
        locale: const Locale('ar'),
        overrides: const {},
        child: MaterialApp(
          locale: const Locale('ar'),
          home: PlatformMarketplaceScreen(
            session: const CustomerSession.guest(),
            onPlatformRegistered: (_) {},
            client: client,
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

    await tester.pumpAndSettle();

    expect(
      requests.where((uri) => uri.path == '/api/v1/platform/storefront'),
      hasLength(1),
    );
    expect(
      requests.where((uri) => uri.path == '/api/v1/stores'),
      isEmpty,
    );
    expect(
      find.byKey(const ValueKey('marketplace-wholesale-entry')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('marketplace-primary-banner')),
      findsOneWidget,
    );
    expect(
      find.byKey(
        const ValueKey('marketplace-retail-store-banner-section'),
      ),
      findsOneWidget,
    );
    expect(find.text('FOODEX Wholesale').hitTestable(), findsOneWidget);
    expect(find.text('Retail Eleven').hitTestable(), findsOneWidget);

    final primary = find.byKey(const ValueKey('marketplace-primary-banner'));
    final retailCarousel =
        find.byKey(const ValueKey('marketplace-retail-store-carousel'));
    final retailBanner =
        find.byKey(const ValueKey('marketplace-retail-banner-11'));
    final primaryBottom = tester.getBottomLeft(primary).dy;
    final retailTop = tester
        .getTopLeft(
          find.byKey(
            const ValueKey('marketplace-retail-store-banner-section'),
          ),
        )
        .dy;
    expect(retailTop, greaterThanOrEqualTo(primaryBottom));
    expect(
      tester.getSize(retailCarousel).height,
      closeTo(tester.getSize(primary).height / 2, 0.1),
    );
    expect(
      tester.getSize(retailBanner).height,
      closeTo(tester.getSize(primary).height / 2, 0.1),
    );

    await tester.tap(find.text('Retail Eleven').hitTestable());
    await tester.pumpAndSettle();

    final routeText = tester.widget<Text>(
      find.byKey(const ValueKey('route-name')),
    );
    final route = Uri.parse(routeText.data!);
    expect(route.path, '/retail/11/home');
    expect(route.queryParameters['channel'], 'retail');
    expect(route.queryParameters['store_id'], '11');
  });

  testWidgets(
      'Retail banner section auto-rotates active stores every five seconds while Wholesale stays fixed',
      (tester) async {
    final client = MockClient((request) async => http.Response(
          jsonEncode({
            'store': {'id': 70, 'name': 'Wholesale', 'channel': 'b2b'},
            'hero': null,
            'products': {'data': []},
            'retail_banners': [
              {
                'id': 7,
                'store_id': 7,
                'banner_id': 701,
                'name': 'Retail Seven',
                'title': 'Retail Seven Offer',
                'banner_url': null,
                'logo_url': null,
              },
              {
                'id': 8,
                'store_id': 8,
                'banner_id': 801,
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
    final carousel =
        find.byKey(const ValueKey('marketplace-retail-store-carousel'));
    expect(carousel, findsOneWidget);
    expect(find.byKey(const ValueKey('marketplace-wholesale-entry')),
        findsOneWidget);
    expect(find.text('FOODEX Wholesale').hitTestable(), findsOneWidget);
    expect(find.text('Retail Seven Offer').hitTestable(), findsOneWidget);
    expect(find.text('Retail Eight Offer').hitTestable(), findsNothing);

    await tester.pump(const Duration(seconds: 5));
    await tester.pump(const Duration(milliseconds: 450));
    expect(find.text('Retail Eight Offer').hitTestable(), findsOneWidget);
    expect(find.text('FOODEX Wholesale').hitTestable(), findsOneWidget);

    await tester.pump(const Duration(seconds: 5));
    await tester.pump(const Duration(milliseconds: 450));
    expect(find.text('Retail Seven Offer').hitTestable(), findsOneWidget);
    expect(find.text('FOODEX Wholesale').hitTestable(), findsOneWidget);
  });

  testWidgets(
      'guest wholesale product opens the full scoped product-details route',
      (tester) async {
    final requests = <Uri>[];
    final client = MockClient((request) async {
      requests.add(request.url);
      if (request.url.path == '/api/v1/stores') {
        return http.Response(jsonEncode({'data': const []}), 200);
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
              body: Text(
                settings.name ?? '',
                key: const ValueKey('route-name'),
              ),
            ),
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(
      find.byKey(
        const ValueKey('marketplace-retail-store-banner-section'),
      ),
      findsNothing,
    );

    await tester.enterText(
      find.byKey(const ValueKey('marketplace-search')),
      'W-RICE-42',
    );
    await tester.testTextInput.receiveAction(TextInputAction.search);
    await tester.pumpAndSettle();

    expect(
      requests
          .where((uri) => uri.path == '/api/v1/platform/storefront')
          .last
          .queryParameters['q'],
      'W-RICE-42',
    );

    await tester.drag(
      find.byType(CustomScrollView),
      const Offset(0, -170),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Wholesale Rice'));
    await tester.pumpAndSettle();

    final route = Uri.parse(
      tester.widget<Text>(find.byKey(const ValueKey('route-name'))).data!,
    );
    expect(route.path, '/b2b/products/42');
    expect(route.queryParameters['store_id'], '70');
    expect(route.queryParameters['channel'], 'wholesale');
    expect(
      requests.any((uri) => uri.path == '/api/v1/platform/products/42'),
      isFalse,
    );
  });

  testWidgets('marketplace category chip opens scoped Wholesale catalog',
      (tester) async {
    final client = MockClient((request) async {
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

    await tester.pumpAndSettle();
    expect(
      find.byKey(const ValueKey('marketplace-category-9')),
      findsOneWidget,
    );

    await tester.tap(find.byKey(const ValueKey('marketplace-category-9')));
    await tester.pumpAndSettle();

    final route = Uri.parse(
      tester.widget<Text>(find.byKey(const ValueKey('route-name'))).data!,
    );
    expect(route.path, '/b2b/products');
    expect(route.queryParameters['store_id'], '70');
    expect(route.queryParameters['channel'], 'wholesale');
    expect(route.queryParameters['source'], 'marketplace');
    expect(route.queryParameters['category_id'], '9');
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
    final requests = <Uri>[];
    final client = MockClient((request) async {
      requests.add(request.url);
      return http.Response(
        jsonEncode({
          'store': {'id': 70, 'name': 'Wholesale', 'channel': 'b2b'},
          'hero': null,
          'categories': const [],
          'products': {'data': const []},
          'retail_banners': const [],
        }),
        200,
      );
    });

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
            barcodeScanner: (_) async => '123456789',
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
    expect(find.byKey(const ValueKey('marketplace-compact-header')),
        findsOneWidget);
    expect(find.byKey(const ValueKey('marketplace-search')), findsOneWidget);
    expect(
        find.byKey(const ValueKey('marketplace-brand-title')), findsOneWidget);
    expect(find.byKey(const ValueKey('marketplace-cart')), findsOneWidget);
    expect(find.byKey(const ValueKey('marketplace-orders')), findsOneWidget);
    expect(
      find.byKey(const ValueKey('customer-persistent-footer')),
      findsOneWidget,
    );
    for (final destination in [
      'home',
      'products',
      'orders',
      'invoices',
      'account',
    ]) {
      expect(
        find.byKey(ValueKey('customer-footer-$destination')),
        findsOneWidget,
      );
    }
    expect(find.byKey(const ValueKey('customer-footer-home')), findsOneWidget);
    expect(find.byKey(const ValueKey('customer-footer-cart')), findsNothing);
    expect(
      find.byKey(const ValueKey('marketplace-notifications')),
      findsOneWidget,
    );
    expect(find.byKey(const ValueKey('marketplace-auth-menu')), findsOneWidget);
    expect(find.byKey(const ValueKey('marketplace-scan')), findsNothing);
    expect(find.byKey(const ValueKey('marketplace-language')), findsNothing);

    await tester.tap(find.byKey(const ValueKey('marketplace-auth-menu')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('marketplace-scan')), findsOneWidget);
    expect(find.byKey(const ValueKey('marketplace-language')), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('marketplace-scan')));
    await tester.pumpAndSettle();
    final search = tester.widget<TextField>(
      find.byKey(const ValueKey('marketplace-search')),
    );
    expect(search.controller?.text, '123456789');
    expect(requests.last.queryParameters['q'], '123456789');

    await tester.tap(find.byKey(const ValueKey('marketplace-auth-menu')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('marketplace-language')));
    await tester.pump();
    expect(requestedLocale, const Locale('en'));
  });

  testWidgets(
      'marketplace header routes every navigation icon in the main Wholesale context',
      (tester) async {
    final client = MockClient((request) async => http.Response(
          jsonEncode({
            'store': {
              'id': 70,
              'name': 'FOODEX Wholesale',
              'channel': 'b2b',
            },
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
          home: PlatformMarketplaceScreen(
            session: const CustomerSession.platformCustomer(
              accessToken: 'signed-in-token',
            ),
            onPlatformRegistered: (_) {},
            client: client,
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
    await tester.pumpAndSettle();

    Future<void> expectHeaderRoute(
      Finder action,
      String expectedPath,
    ) async {
      await tester.tap(action);
      await tester.pumpAndSettle();

      final routeText = tester.widget<Text>(
        find.byKey(const ValueKey('route-name')),
      );
      final route = Uri.parse(routeText.data!);
      expect(route.path, expectedPath);
      expect(route.queryParameters['channel'], 'wholesale');
      expect(route.queryParameters['store_id'], '70');
      expect(route.queryParameters['source'], 'marketplace');

      Navigator.of(
        tester.element(find.byKey(const ValueKey('route-name'))),
      ).pop();
      await tester.pumpAndSettle();
    }

    await expectHeaderRoute(
      find.byKey(const ValueKey('marketplace-store')),
      '/b2b/home',
    );
    await expectHeaderRoute(
      find.byKey(const ValueKey('marketplace-notifications')),
      '/b2b/notifications',
    );
    await expectHeaderRoute(
      find.byKey(const ValueKey('marketplace-orders')),
      '/b2b/orders',
    );
    await expectHeaderRoute(
      find.byKey(const ValueKey('marketplace-cart')),
      '/b2b/cart',
    );

    await tester.tap(find.byKey(const ValueKey('marketplace-auth-menu')));
    await tester.pumpAndSettle();
    await tester.tap(
      find.byKey(const ValueKey('marketplace-profile-action')),
    );
    await tester.pumpAndSettle();

    final profileText = tester.widget<Text>(
      find.byKey(const ValueKey('route-name')),
    );
    final profileRoute = Uri.parse(profileText.data!);
    expect(profileRoute.path, '/b2b/profile');
    expect(profileRoute.queryParameters['channel'], 'wholesale');
    expect(profileRoute.queryParameters['store_id'], '70');
    expect(profileRoute.queryParameters['source'], 'marketplace');
  });

  testWidgets(
      'signed-in marketplace keeps store switcher open and management routes follow origin store',
      (tester) async {
    const retailContext = CustomerCommerceContext(
      channel: CustomerCommerceChannel.retail,
      storeId: 22,
      source: CustomerCommerceSource.retailBanner,
    );
    final client = MockClient((request) async {
      if (request.url.path == '/api/v1/stores') {
        return http.Response(
          jsonEncode({
            'data': [
              {
                'id': 22,
                'name': 'Retail 22',
                'logo_url': null,
              },
            ],
          }),
          200,
        );
      }
      return http.Response(
        jsonEncode({
          'store': {
            'id': 70,
            'name': 'FOODEX Wholesale',
            'channel': 'b2b',
          },
          'hero': {'title': 'Wholesale'},
          'categories': const [],
          'products': {'data': const []},
          'retail_banners': [
            {
              'id': 22,
              'store_id': 22,
              'name': 'Retail 22',
              'title': 'Retail 22',
              'logo_url': null,
              'channel': 'b2c',
              'placement_scope': 'active_retail_store_fallback',
              'target_type': 'retail_store',
              'target_id': 22,
            },
          ],
        }),
        200,
      );
    });

    await tester.pumpWidget(
      AppTranslations(
        locale: const Locale('ar'),
        overrides: const {},
        child: MaterialApp(
          locale: const Locale('ar'),
          home: PlatformMarketplaceScreen(
            session: const CustomerSession.platformCustomer(
              accessToken: 'signed-in-token',
            ),
            commerceContextProvider: () => retailContext,
            onPlatformRegistered: (_) {},
            client: client,
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
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 200));

    expect(
      find.byKey(const ValueKey('marketplace-retail-store-carousel')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('marketplace-retail-banner-title-22')),
      findsOneWidget,
    );

    await tester.tap(find.byKey(const ValueKey('marketplace-cart')));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 400));
    var route = Uri.parse(
      tester
          .widget<Text>(
            find.byKey(const ValueKey('route-name')),
          )
          .data!,
    );
    expect(route.path, '/cart');
    expect(route.queryParameters['channel'], 'retail');
    expect(route.queryParameters['store_id'], '22');

    Navigator.of(
      tester.element(find.byKey(const ValueKey('route-name'))),
    ).pop();
    await tester.pump();
    await tester.pump(const Duration(seconds: 1));

    expect(
      find.byKey(const ValueKey('marketplace-retail-store-carousel')),
      findsOneWidget,
    );

    await tester.tap(
      find.byKey(const ValueKey('marketplace-wholesale-banner-action')),
    );
    await tester.pump();
    await tester.pump(const Duration(seconds: 1));
    route = Uri.parse(
      tester
          .widget<Text>(
            find.byKey(const ValueKey('route-name')),
          )
          .data!,
    );
    expect(route.path, '/b2b/home');
    expect(route.queryParameters['channel'], 'wholesale');
    expect(route.queryParameters['store_id'], '70');
  });

  testWidgets(
      'marketplace auth menu reads the live app session after login without a stale guest route',
      (tester) async {
    var liveSession = const CustomerSession.guest();
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
        locale: const Locale('en'),
        overrides: const {},
        child: MaterialApp(
          home: PlatformMarketplaceScreen(
            session: const CustomerSession.guest(),
            sessionProvider: () => liveSession,
            onPlatformRegistered: (_) {},
            client: client,
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('marketplace-auth-menu')));
    await tester.pumpAndSettle();
    expect(
      find.byKey(const ValueKey('marketplace-login-action')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('marketplace-register-action')),
      findsOneWidget,
    );
    await tester.tapAt(const Offset(5, 5));
    await tester.pumpAndSettle();

    liveSession = const CustomerSession.platformCustomer(
      accessToken: 'signed-in-token',
    );

    await tester.tap(find.byKey(const ValueKey('marketplace-auth-menu')));
    await tester.pumpAndSettle();
    expect(
      find.byKey(const ValueKey('marketplace-profile-action')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('marketplace-login-action')),
      findsNothing,
    );
    expect(
      find.byKey(const ValueKey('marketplace-register-action')),
      findsNothing,
    );
  });

  testWidgets(
      'marketplace reference home binds authoritative API content at narrow phone width',
      (tester) async {
    tester.view.physicalSize = const Size(320, 780);
    tester.view.devicePixelRatio = 1;
    addTearDown(() {
      tester.view.resetPhysicalSize();
      tester.view.resetDevicePixelRatio();
    });

    final client = MockClient((request) async => http.Response(
          jsonEncode({
            'store': {
              'id': 70,
              'name': 'FOODEX Wholesale',
              'channel': 'b2b',
            },
            'hero': {
              'title': 'October Wholesale',
              'image_url': null,
            },
            'categories': [
              {'id': 9, 'name': 'Beverages'},
              {'id': 10, 'name': 'Pantry'},
            ],
            'offers': const [],
            'products': {
              'data': [
                {
                  'id': 42,
                  'name': 'API Water',
                  'sku': 'API-WATER-42',
                  'unit_price': 12.5,
                  'currency': 'KWD',
                  'image_url': null,
                },
                {
                  'id': 43,
                  'name': 'API Rice',
                  'sku': 'API-RICE-43',
                  'account_price': 8.75,
                  'currency': 'KWD',
                  'image_url': null,
                },
              ],
            },
            'retail_banners': [
              {
                'id': 7,
                'store_id': 7,
                'banner_id': 701,
                'code': 'RTL-7',
                'name': 'Retail Seven',
                'title': 'Dashboard Retail Banner',
                'banner_url': null,
                'logo_url': null,
                'theme_code': 'retail_grocery',
                'address': 'Retail Area',
                'channel': 'b2c',
                'target_type': 'store',
                'target_id': 7,
                'target_url': null,
                'sort_order': 1,
              },
            ],
          }),
          200,
          headers: const {'content-type': 'application/json'},
        ));

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
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(tester.takeException(), isNull);
    expect(
        find.byKey(const ValueKey('marketplace-categories')), findsOneWidget);
    expect(
      find.byKey(const ValueKey('marketplace-primary-banner')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('marketplace-retail-banner-indicators')),
      findsNothing,
    );
    expect(find.text('October Wholesale'), findsOneWidget);
    expect(
      find.byKey(const ValueKey('marketplace-wholesale-entry')),
      findsOneWidget,
    );

    final categoriesTop = tester
        .getTopLeft(find.byKey(const ValueKey('marketplace-categories')))
        .dy;
    final wholesaleTop = tester
        .getTopLeft(find.byKey(const ValueKey('marketplace-wholesale-entry')))
        .dy;
    final bannerTop = tester
        .getTopLeft(find.byKey(const ValueKey('marketplace-primary-banner')))
        .dy;
    final retailBottom = tester
        .getBottomLeft(
          find.byKey(
            const ValueKey('marketplace-retail-store-banner-section'),
          ),
        )
        .dy;
    expect(wholesaleTop, bannerTop);
    expect(bannerTop, lessThan(retailBottom));
    expect(retailBottom, lessThanOrEqualTo(categoriesTop));
    expect(find.byIcon(Icons.local_cafe_outlined), findsWidgets);

    expect(
      find.byKey(const ValueKey('marketplace-retail-banner-title-7')),
      findsOneWidget,
    );
    expect(find.text('Dashboard Retail Banner').hitTestable(), findsOneWidget);
    expect(find.text('Retail Area'), findsNothing);
    expect(tester.takeException(), isNull);

    await tester.drag(
      find.byType(CustomScrollView),
      const Offset(0, -650),
    );
    await tester.pumpAndSettle();

    expect(
      find.byKey(const ValueKey('marketplace-product-card-42')),
      findsOneWidget,
    );
    final productNameBox =
        find.byKey(const ValueKey('marketplace-product-name-42'));
    final productName = tester.widget<Text>(
      find.descendant(of: productNameBox, matching: find.byType(Text)).first,
    );
    expect(productName.maxLines, 1);
    expect(productName.softWrap, isFalse);
    expect(find.text('API Water'), findsOneWidget);
    expect(find.text('12.5 KWD'), findsOneWidget);
    expect(find.byIcon(Icons.water_drop_rounded), findsWidgets);
    expect(tester.takeException(), isNull);
  });

  testWidgets(
      'stale owned Retail context is rejected by marketplace header and footer',
      (tester) async {
    const staleOwnedContext = CustomerCommerceContext(
      channel: CustomerCommerceChannel.retail,
      storeId: 99,
      source: CustomerCommerceSource.retailBanner,
    );
    final client = MockClient((request) async {
      if (request.url.path == '/api/v1/stores') {
        return http.Response(
          jsonEncode({
            'data': [
              {'id': 22, 'name': 'Allowed Retail', 'logo_url': null},
            ],
          }),
          200,
        );
      }
      return http.Response(
        jsonEncode({
          'store': {
            'id': 70,
            'name': 'FOODEX Wholesale',
            'channel': 'b2b',
          },
          'hero': null,
          'categories': const [],
          'products': {'data': const []},
          'retail_banners': [
            {
              'id': 22,
              'store_id': 22,
              'name': 'Allowed Retail',
              'title': 'Allowed Retail',
              'channel': 'b2c',
            },
          ],
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
            session: const CustomerSession.platformCustomer(
              accessToken: 'signed-in-token',
            ),
            commerceContextProvider: () => staleOwnedContext,
            onPlatformRegistered: (_) {},
            client: client,
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
    await tester.pumpAndSettle();

    expect(
      find.byKey(const ValueKey('footer-disabled-products')),
      findsOneWidget,
    );
    await tester.tap(
      find.byKey(const ValueKey('customer-footer-products')),
    );
    await tester.pump();
    expect(find.byKey(const ValueKey('route-name')), findsNothing);

    await tester.tap(find.byKey(const ValueKey('marketplace-store')));
    await tester.pumpAndSettle();
    final route = Uri.parse(
      tester.widget<Text>(find.byKey(const ValueKey('route-name'))).data!,
    );
    expect(route.path, '/b2b/home');
    expect(route.queryParameters['store_id'], '70');
    expect(route.queryParameters['channel'], 'wholesale');

    Navigator.of(tester.element(find.byKey(const ValueKey('route-name'))))
        .pop();
    await tester.pumpAndSettle();

    for (final destination in [
      'home',
      'products',
      'orders',
      'invoices',
      'account'
    ]) {
      expect(
        find.byKey(ValueKey('customer-footer-$destination')),
        findsOneWidget,
      );
    }
    expect(find.byKey(const ValueKey('customer-footer-home')), findsOneWidget);
    expect(find.byKey(const ValueKey('customer-footer-cart')), findsNothing);
    expect(
      find.byKey(const ValueKey('footer-disabled-products')),
      findsOneWidget,
    );
  });
}
