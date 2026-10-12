import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/app.dart';
import 'package:foodex_customer_app/core/auth/customer_session.dart';
import 'package:foodex_customer_app/core/routing/customer_commerce_context.dart';
import 'package:foodex_customer_app/core/routing/customer_route_authority.dart';
import 'package:foodex_customer_app/core/routing/customer_routes.dart';

void main() {
  CustomerRouteDefinition definition() => customerRouteDefinitions.firstWhere(
        (item) => item.pattern == CustomerRoutePaths.b2bStoreAnalytics,
      );

  group('Store Analytics route contract', () {
    test('uses explicit Retail merchant context without Wholesale store_id',
        () {
      expect(
        CustomerRouteLocations.storeAnalytics(),
        CustomerRoutePaths.b2bStoreAnalytics,
      );
      expect(
        CustomerRouteLocations.storeAnalytics(retailStoreId: 22),
        '/b2b/store-analytics?retail_store_id=22',
      );
      final scoped = Uri.parse(
        CustomerRouteLocations.storeAnalytics(retailStoreId: 22),
      );
      expect(scoped.queryParameters.containsKey('store_id'), isFalse);
      expect(scoped.queryParameters.containsKey('store'), isFalse);
      expect(
        () => CustomerRouteLocations.storeAnalytics(retailStoreId: 0),
        throwsArgumentError,
      );
    });

    test('parser fails closed on Wholesale or ambiguous context keys', () {
      expect(
        CustomerStoreAnalyticsRouteContext.tryParse(
          '/b2b/store-analytics?retail_store_id=22',
        )?.retailStoreId,
        22,
      );
      expect(
        CustomerStoreAnalyticsRouteContext.tryParse(
          '/b2b/store-analytics',
        )?.retailStoreId,
        isNull,
      );

      for (final invalid in <String>[
        '/b2b/store-analytics?store_id=22',
        '/b2b/store-analytics?store=22',
        '/b2b/store-analytics?channel=wholesale&retail_store_id=22',
        '/b2b/store-analytics?retail_store_id=0',
        '/b2b/store-analytics?retail_store_id=-1',
        '/b2b/store-analytics?retail_store_id=22&retail_store_id=23',
      ]) {
        expect(
          CustomerStoreAnalyticsRouteContext.tryParse(invalid),
          isNull,
          reason: invalid,
        );
      }
    });

    test('route is protected by Business auth and has one authority', () {
      final route = definition();
      expect(route.channel, CustomerChannel.b2b);
      expect(route.requiresAuth, isTrue);
      expect(
        customerRouteAuthorityFor(
          route,
          '/b2b/store-analytics?retail_store_id=22',
        ),
        CustomerRouteAuthority.storeAnalytics,
      );
    });

    test('safe return accepts Retail analytics selector but rejects store_id',
        () {
      const target = '/b2b/store-analytics?retail_store_id=22';
      expect(
        safeCustomerReturnLocation(
          target,
          channel: CustomerChannel.b2b,
        ),
        target,
      );
      expect(
        safeCustomerReturnLocation(
          '/b2b/store-analytics',
          channel: CustomerChannel.b2b,
        ),
        CustomerRoutePaths.b2bStoreAnalytics,
      );
      expect(
        safeCustomerReturnLocation(
          '/b2b/store-analytics?store_id=22',
          channel: CustomerChannel.b2b,
        ),
        isNull,
      );
      expect(
        safeCustomerReturnLocation(
          target,
          channel: CustomerChannel.b2c,
        ),
        isNull,
      );
    });

    test('Wholesale commerce context cannot claim Store Analytics return', () {
      const wholesale = CustomerCommerceContext(
        channel: CustomerCommerceChannel.wholesale,
        storeId: 22,
      );
      expect(
        safeCustomerContextReturnLocation(
          '/b2b/store-analytics?retail_store_id=22',
          context: wholesale,
        ),
        isNull,
      );
    });

    testWidgets('guest Store Analytics route hands off to unified auth',
        (tester) async {
      await tester.pumpWidget(
        const FoodexCustomerApp(
          initialRoute: '/b2b/store-analytics?retail_store_id=22',
        ),
      );
      await tester.pumpAndSettle();

      expect(
        find.byKey(const ValueKey('unified-customer-auth-screen')),
        findsOneWidget,
      );
      expect(
        find.byKey(const ValueKey('customer-route-shell')),
        findsNothing,
      );
    });

    testWidgets('authenticated Business context reaches analytics authority',
        (tester) async {
      await tester.pumpWidget(
        const FoodexCustomerApp(
          session: CustomerSession.authenticated(CustomerChannel.b2b),
          initialRoute: '/b2b/store-analytics?retail_store_id=22',
        ),
      );
      await tester.pumpAndSettle();

      expect(
        find.byKey(const ValueKey('unified-customer-auth-screen')),
        findsNothing,
      );
      expect(
        find.byKey(const ValueKey('customer-route-shell')),
        findsOneWidget,
      );
      expect(find.text('تحليل المتجر الذكي'), findsOneWidget);
    });
  });
}
