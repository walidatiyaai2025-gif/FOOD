import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/api/b2c_account_api.dart';
import 'package:foodex_customer_app/core/api/customer_action_api.dart';
import 'package:foodex_customer_app/core/routing/customer_routes.dart';
import 'package:foodex_customer_app/features/b2b/b2b_journey_screen.dart';
import 'package:foodex_customer_app/features/customer_account/customer_address_book_screen.dart';
import 'package:foodex_customer_app/features/customer_account/customer_notification_center_screen.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  for (final route in [
    CustomerRoutePaths.b2bAddresses,
    CustomerRoutePaths.b2bNotifications,
  ]) {
    testWidgets('wholesale account API loads $route in the B2B domain',
        (tester) async {
      final requests = <http.Request>[];
      final client = MockClient((request) async {
        requests.add(request);
        return http.Response('{"data":[]}', 200,
            headers: {'content-type': 'application/json'});
      });
      addTearDown(client.close);
      final api = HttpB2cAccountApi(
        baseUrl: 'https://example.test',
        token: 'wholesale-test-token',
        guestSession: CustomerGuestSession(),
        customerDomain: 'b2b',
        client: client,
      );
      await tester.pumpWidget(MaterialApp(
        home: B2bJourneyScreen(
          definition: customerRouteDefinitions.firstWhere(
            (definition) => definition.pattern == route,
          ),
          location: route,
          accountApi: api,
          actionApi: HttpCustomerActionApi(
            baseUrl: 'https://example.test',
            client: client,
          ),
        ),
      ));
      await tester.pumpAndSettle();

      expect(
        find.byType(route == CustomerRoutePaths.b2bAddresses
            ? CustomerAddressBookScreen
            : CustomerNotificationCenterScreen),
        findsOneWidget,
      );
      expect(requests, hasLength(1));
      expect(
          requests.single.url.path,
          route == CustomerRoutePaths.b2bAddresses
              ? '/api/v1/profile/addresses'
              : '/api/v1/notifications');
      expect(requests.single.headers['X-FOODEX-Customer-Domain'], 'b2b');
      expect(tester.takeException(), isNull);
    });
  }
}
