import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/routing/customer_commerce_context.dart';
import 'package:foodex_customer_app/core/routing/customer_routes.dart';
import 'package:foodex_customer_app/core/theme/customer_ui_v3_tokens.dart';
import 'package:foodex_customer_app/core/theme/foodex_theme.dart';
import 'package:foodex_customer_app/features/retail/customer_ui_v3/customer_retail_shell.dart';

/// Final #725 Customer UI V3 convergence contract.
///
/// All feature lanes #719-#724 are merged before this contract is executed.
/// It freezes the full-journey route, locale, viewport, state and motion
/// requirements while the final CI lane runs the executable widget, platform
/// and runtime screenshot evidence on the same candidate.
void main() {
  group('Customer UI V3 full-journey visual acceptance inventory', () {
    test('covers every mandatory journey milestone with routable locations', () {
      final ids = _journeyCases.map((item) => item.id).toList();
      expect(ids.toSet(), hasLength(ids.length));

      final coveredMilestones =
          _journeyCases.map((item) => item.milestone).toSet();
      expect(
        coveredMilestones,
        containsAll(_requiredMilestones),
      );

      for (final item in _journeyCases) {
        final matches = customerRouteDefinitions
            .where((definition) => definition.matches(item.location))
            .toList();

        expect(
          matches,
          isNotEmpty,
          reason:
              '${item.id} must resolve through the authoritative Customer router',
        );
      }
    });

    test('keeps feature-lane ownership explicit for convergence', () {
      expect(
        _journeyCases
            .where((item) => item.ownerLane == _VisualOwnerLane.shellHome)
            .map((item) => item.milestone),
        containsAll(<_VisualMilestone>{
          _VisualMilestone.marketplaceEntry,
          _VisualMilestone.retailHome,
        }),
      );
      expect(
        _journeyCases
            .where((item) => item.ownerLane == _VisualOwnerLane.browse)
            .map((item) => item.milestone),
        containsAll(<_VisualMilestone>{
          _VisualMilestone.search,
          _VisualMilestone.categories,
          _VisualMilestone.offers,
          _VisualMilestone.productList,
          _VisualMilestone.productDetails,
          _VisualMilestone.favorites,
        }),
      );
      expect(
        _journeyCases
            .where((item) => item.ownerLane == _VisualOwnerLane.commerce)
            .map((item) => item.milestone),
        containsAll(<_VisualMilestone>{
          _VisualMilestone.cart,
          _VisualMilestone.authHandoff,
          _VisualMilestone.checkout,
          _VisualMilestone.confirmation,
        }),
      );
      expect(
        _journeyCases
            .where((item) => item.ownerLane == _VisualOwnerLane.orders)
            .map((item) => item.milestone),
        containsAll(<_VisualMilestone>{
          _VisualMilestone.orders,
          _VisualMilestone.orderTracking,
        }),
      );
      expect(
        _journeyCases
            .where((item) => item.ownerLane == _VisualOwnerLane.account)
            .map((item) => item.milestone),
        containsAll(<_VisualMilestone>{
          _VisualMilestone.notifications,
          _VisualMilestone.profile,
          _VisualMilestone.addresses,
          _VisualMilestone.settingsHelp,
        }),
      );
    });

    test('AppBar titles keep the bilingual V3 font instead of platform fallback', () {
      final theme = FoodexTheme.light();

      expect(theme.appBarTheme.titleTextStyle?.fontFamily, isNotNull);
      expect(
        theme.appBarTheme.titleTextStyle?.fontFamily,
        theme.primaryTextTheme.titleLarge?.fontFamily,
      );
    });

    test('locks AR/EN, phone widths, text scale, state and motion coverage', () {
      expect(_visualContract.locales, <String>{'ar', 'en'});
      expect(_visualContract.viewportWidths, <int>{320, 360, 390, 430});
      expect(_visualContract.textScales, containsAll(<double>{1.0, 1.3}));
      expect(
        _visualContract.states,
        <_VisualState>{
          _VisualState.loading,
          _VisualState.empty,
          _VisualState.error,
          _VisualState.success,
        },
      );
      expect(_visualContract.reducedMotionModes, <bool>{false, true});
    });

    test('requires representative state coverage across the journey', () {
      final allStates = _journeyCases
          .expand((item) => item.requiredStates)
          .toSet();

      expect(
        allStates,
        containsAll(_visualContract.states),
      );

      expect(
        _journeyCases.where(
          (item) =>
              item.requiredStates.contains(_VisualState.loading) &&
              item.requiredStates.contains(_VisualState.error),
        ),
        isNotEmpty,
      );

      expect(
        _journeyCases.where(
          (item) => item.requiredStates.contains(_VisualState.empty),
        ),
        isNotEmpty,
      );
    });

    test('keeps categories and offers as distinct V3 production surfaces', () {
      final source = File(
        'lib/features/retail/retail_customer_journey_screen.dart',
      ).readAsStringSync();

      expect(source, contains('RetailCatalogCategoriesScreen('));
      expect(source, contains('RetailCatalogOffersScreen('));
      expect(
        source,
        isNot(contains(
          'case CustomerRoutePaths.categories:\n'
          '      case CustomerRoutePaths.offers:',
        )),
      );
    });

    test('binds convergence to the AR/EN runtime screenshot harness', () {
      final source =
          File('test/screenshot_evidence_test.dart').readAsStringSync();

      expect(
        source,
        contains("for (final locale in const [Locale('ar'), Locale('en')])"),
      );
      expect(source, contains('Size(430, 932)'));

      for (final routeFragment in _requiredRuntimeScreenshotRoutes) {
        expect(
          source,
          contains(routeFragment),
          reason:
              'runtime screenshot harness must retain $routeFragment for #725',
        );
      }
    });

    testWidgets(
      'floating navigation honors reduced motion without changing geometry',
      (tester) async {
        const commerceContext = CustomerCommerceContext(
          channel: CustomerCommerceChannel.retail,
          storeId: 7,
        );

        Future<void> pumpShell({required bool disableAnimations}) async {
          await tester.pumpWidget(
            MaterialApp(
              home: MediaQuery(
                data: MediaQueryData(
                  size: const Size(390, 844),
                  disableAnimations: disableAnimations,
                ),
                child: const CustomerRetailShell(
                  commerceContext: commerceContext,
                  activeDestination: CustomerRetailDestination.home,
                  isAuthenticated: true,
                  child: Scaffold(body: SizedBox.expand()),
                ),
              ),
            ),
          );
          await tester.pump();
        }

        await pumpShell(disableAnimations: false);
        final normal = tester
            .widgetList<AnimatedContainer>(find.byType(AnimatedContainer))
            .toList();
        expect(normal, hasLength(5));
        expect(
          normal.map((widget) => widget.duration).toSet(),
          <Duration>{CustomerUiMotion.standard},
        );
        final normalSizes = normal
            .map((widget) => <double?>[
                  widget.constraints?.maxWidth,
                  widget.constraints?.maxHeight,
                ])
            .toList();

        await pumpShell(disableAnimations: true);
        final reduced = tester
            .widgetList<AnimatedContainer>(find.byType(AnimatedContainer))
            .toList();
        expect(reduced, hasLength(5));
        expect(
          reduced.map((widget) => widget.duration).toSet(),
          <Duration>{Duration.zero},
        );
        final reducedSizes = reduced
            .map((widget) => <double?>[
                  widget.constraints?.maxWidth,
                  widget.constraints?.maxHeight,
                ])
            .toList();
        expect(reducedSizes, normalSizes);
        expect(tester.takeException(), isNull);
      },
    );
  });
}

enum _VisualOwnerLane {
  shellHome,
  browse,
  commerce,
  orders,
  account,
}

enum _VisualMilestone {
  marketplaceEntry,
  retailHome,
  search,
  categories,
  offers,
  productList,
  productDetails,
  favorites,
  cart,
  authHandoff,
  checkout,
  confirmation,
  orders,
  orderTracking,
  notifications,
  profile,
  addresses,
  settingsHelp,
}

enum _VisualState {
  loading,
  empty,
  error,
  success,
}

class _VisualCase {
  const _VisualCase({
    required this.id,
    required this.milestone,
    required this.location,
    required this.ownerLane,
    this.requiredStates = const <_VisualState>{_VisualState.success},
  });

  final String id;
  final _VisualMilestone milestone;
  final String location;
  final _VisualOwnerLane ownerLane;
  final Set<_VisualState> requiredStates;
}

class _VisualContract {
  const _VisualContract({
    required this.locales,
    required this.viewportWidths,
    required this.textScales,
    required this.states,
    required this.reducedMotionModes,
  });

  final Set<String> locales;
  final Set<int> viewportWidths;
  final Set<double> textScales;
  final Set<_VisualState> states;
  final Set<bool> reducedMotionModes;
}

final _visualContract = _VisualContract(
  locales: <String>{'ar', 'en'},
  viewportWidths: <int>{320, 360, 390, 430},
  textScales: <double>{1.0, 1.3},
  states: <_VisualState>{
    _VisualState.loading,
    _VisualState.empty,
    _VisualState.error,
    _VisualState.success,
  },
  reducedMotionModes: <bool>{false, true},
);

const _requiredMilestones = <_VisualMilestone>{
  _VisualMilestone.marketplaceEntry,
  _VisualMilestone.retailHome,
  _VisualMilestone.search,
  _VisualMilestone.categories,
  _VisualMilestone.offers,
  _VisualMilestone.productList,
  _VisualMilestone.productDetails,
  _VisualMilestone.favorites,
  _VisualMilestone.cart,
  _VisualMilestone.authHandoff,
  _VisualMilestone.checkout,
  _VisualMilestone.confirmation,
  _VisualMilestone.orders,
  _VisualMilestone.orderTracking,
  _VisualMilestone.notifications,
  _VisualMilestone.profile,
  _VisualMilestone.addresses,
  _VisualMilestone.settingsHelp,
};

const _journeyCases = <_VisualCase>[
  _VisualCase(
    id: 'entry.marketplace',
    milestone: _VisualMilestone.marketplaceEntry,
    location: CustomerRoutePaths.marketplace,
    ownerLane: _VisualOwnerLane.shellHome,
    requiredStates: <_VisualState>{
      _VisualState.loading,
      _VisualState.empty,
      _VisualState.error,
      _VisualState.success,
    },
  ),
  _VisualCase(
    id: 'home.retail',
    milestone: _VisualMilestone.retailHome,
    location: '/retail/7/home',
    ownerLane: _VisualOwnerLane.shellHome,
    requiredStates: <_VisualState>{
      _VisualState.loading,
      _VisualState.empty,
      _VisualState.error,
      _VisualState.success,
    },
  ),
  _VisualCase(
    id: 'browse.search',
    milestone: _VisualMilestone.search,
    location: '/products?channel=retail&store_id=7&q=milk',
    ownerLane: _VisualOwnerLane.browse,
    requiredStates: <_VisualState>{
      _VisualState.loading,
      _VisualState.empty,
      _VisualState.error,
      _VisualState.success,
    },
  ),
  _VisualCase(
    id: 'browse.categories',
    milestone: _VisualMilestone.categories,
    location: '/categories?channel=retail&store_id=7',
    ownerLane: _VisualOwnerLane.browse,
    requiredStates: <_VisualState>{
      _VisualState.loading,
      _VisualState.empty,
      _VisualState.success,
    },
  ),
  _VisualCase(
    id: 'browse.offers',
    milestone: _VisualMilestone.offers,
    location: '/offers?channel=retail&store_id=7',
    ownerLane: _VisualOwnerLane.browse,
    requiredStates: <_VisualState>{
      _VisualState.loading,
      _VisualState.empty,
      _VisualState.success,
    },
  ),
  _VisualCase(
    id: 'browse.products',
    milestone: _VisualMilestone.productList,
    location: '/products?channel=retail&store_id=7',
    ownerLane: _VisualOwnerLane.browse,
    requiredStates: <_VisualState>{
      _VisualState.loading,
      _VisualState.empty,
      _VisualState.error,
      _VisualState.success,
    },
  ),
  _VisualCase(
    id: 'browse.product-details',
    milestone: _VisualMilestone.productDetails,
    location: '/retail/7/products/42',
    ownerLane: _VisualOwnerLane.browse,
    requiredStates: <_VisualState>{
      _VisualState.loading,
      _VisualState.error,
      _VisualState.success,
    },
  ),
  _VisualCase(
    id: 'browse.favorites',
    milestone: _VisualMilestone.favorites,
    location: '/favorites?channel=retail&store_id=7',
    ownerLane: _VisualOwnerLane.browse,
    requiredStates: <_VisualState>{
      _VisualState.loading,
      _VisualState.empty,
      _VisualState.error,
      _VisualState.success,
    },
  ),
  _VisualCase(
    id: 'commerce.cart',
    milestone: _VisualMilestone.cart,
    location: '/cart?channel=retail&store_id=7',
    ownerLane: _VisualOwnerLane.commerce,
    requiredStates: <_VisualState>{
      _VisualState.loading,
      _VisualState.empty,
      _VisualState.error,
      _VisualState.success,
    },
  ),
  _VisualCase(
    id: 'commerce.auth-handoff',
    milestone: _VisualMilestone.authHandoff,
    location:
        '/auth/checkout?channel=retail&store_id=7&next=%2Fcheckout%2Faddress-payment%3Fchannel%3Dretail%26store_id%3D7',
    ownerLane: _VisualOwnerLane.commerce,
  ),
  _VisualCase(
    id: 'commerce.checkout',
    milestone: _VisualMilestone.checkout,
    location: '/checkout/address-payment?channel=retail&store_id=7',
    ownerLane: _VisualOwnerLane.commerce,
    requiredStates: <_VisualState>{
      _VisualState.loading,
      _VisualState.error,
      _VisualState.success,
    },
  ),
  _VisualCase(
    id: 'commerce.confirmation',
    milestone: _VisualMilestone.confirmation,
    location: '/orders/101/track?channel=retail&store_id=7',
    ownerLane: _VisualOwnerLane.commerce,
  ),
  _VisualCase(
    id: 'orders.list',
    milestone: _VisualMilestone.orders,
    location: '/orders?channel=retail&store_id=7',
    ownerLane: _VisualOwnerLane.orders,
    requiredStates: <_VisualState>{
      _VisualState.loading,
      _VisualState.empty,
      _VisualState.error,
      _VisualState.success,
    },
  ),
  _VisualCase(
    id: 'orders.details-tracking',
    milestone: _VisualMilestone.orderTracking,
    location: '/orders/101/track?channel=retail&store_id=7',
    ownerLane: _VisualOwnerLane.orders,
    requiredStates: <_VisualState>{
      _VisualState.loading,
      _VisualState.error,
      _VisualState.success,
    },
  ),
  _VisualCase(
    id: 'account.notifications',
    milestone: _VisualMilestone.notifications,
    location: '/notifications?channel=retail&store_id=7',
    ownerLane: _VisualOwnerLane.account,
    requiredStates: <_VisualState>{
      _VisualState.loading,
      _VisualState.empty,
      _VisualState.error,
      _VisualState.success,
    },
  ),
  _VisualCase(
    id: 'account.profile',
    milestone: _VisualMilestone.profile,
    location: '/profile?channel=retail&store_id=7',
    ownerLane: _VisualOwnerLane.account,
    requiredStates: <_VisualState>{
      _VisualState.loading,
      _VisualState.error,
      _VisualState.success,
    },
  ),
  _VisualCase(
    id: 'account.addresses',
    milestone: _VisualMilestone.addresses,
    location: '/profile/addresses?channel=retail&store_id=7',
    ownerLane: _VisualOwnerLane.account,
    requiredStates: <_VisualState>{
      _VisualState.loading,
      _VisualState.empty,
      _VisualState.error,
      _VisualState.success,
    },
  ),
  _VisualCase(
    id: 'account.settings-help-about-contact',
    milestone: _VisualMilestone.settingsHelp,
    location: '/profile/settings?channel=retail&store_id=7',
    ownerLane: _VisualOwnerLane.account,
  ),
];


const _requiredRuntimeScreenshotRoutes = <String>{
  '/retail/7/home',
  '/categories?channel=retail&store_id=7',
  '/offers?channel=retail&store_id=7',
  '/products?channel=retail&store_id=7',
  '/retail/7/products/42',
  '/favorites?channel=retail&store_id=7',
  '/cart?channel=retail&store_id=7',
  '/auth/checkout?channel=retail&store_id=7',
  '/checkout/address-payment?channel=retail&store_id=7',
  '/orders?channel=retail&store_id=7',
  '/orders/101/track?channel=retail&store_id=7',
  '/notifications?channel=retail&store_id=7',
  '/profile?channel=retail&store_id=7',
  '/profile/addresses?channel=retail&store_id=7',
  '/profile/settings?channel=retail&store_id=7',
};
