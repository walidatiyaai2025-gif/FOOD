import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/engagement/notification_campaign_popup_service.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';

void main() {
  setUp(() {
    SharedPreferences.setMockInitialValues(<String, Object>{});
  });

  test('campaign popup parses localized action contract', () {
    final popup = CustomerNotificationCampaignPopup.fromJson({
      'id': 833,
      'title': 'Launch offer',
      'body': 'Current campaign',
      'frequency': 'once_per_user',
      'image_url': 'https://foodex.test/campaign.webp',
      'cta_label': 'View offers',
      'cta_target': '/offers',
    });

    expect(popup.id, 833);
    expect(popup.title, 'Launch offer');
    expect(popup.frequency, 'once_per_user');
    expect(popup.ctaLabel, 'View offers');
    expect(popup.ctaTarget, '/offers');
  });

  testWidgets('launch popup records impression and deterministic dismiss event',
      (tester) async {
    final requests = <http.Request>[];
    final client = MockClient((request) async {
      requests.add(request);
      if (request.method == 'GET') {
        return http.Response(
          jsonEncode({
            'data': [
              {
                'id': 833,
                'title': 'Launch offer',
                'body': 'Campaign body',
                'frequency': 'once_per_session',
                'cta_label': 'View offers',
                'cta_target': '/offers',
              },
            ],
          }),
          200,
          headers: const {'content-type': 'application/json'},
        );
      }

      return http.Response('', 204);
    });
    final service = CustomerNotificationCampaignPopupService(
      client: client,
      baseUrl: 'https://foodex.test',
    );

    late BuildContext popupContext;
    await tester.pumpWidget(
      MaterialApp(
        locale: const Locale('en'),
        home: Builder(
          builder: (context) {
            popupContext = context;
            return const Scaffold(body: Text('Home'));
          },
        ),
      ),
    );

    final popupFuture = service.showForContext(
      popupContext,
      channel: 'b2c',
      storeId: 7,
      accessToken: 'customer-token',
    );
    await tester.pumpAndSettle();

    expect(find.text('Launch offer'), findsOneWidget);
    expect(find.text('Campaign body'), findsOneWidget);
    expect(find.text('Close'), findsOneWidget);

    await tester.tap(find.text('Close'));
    await tester.pumpAndSettle();
    await popupFuture;

    expect(requests, hasLength(3));
    expect(requests.first.method, 'GET');
    expect(requests.first.url.path, '/api/v1/notification-campaign-popups');
    expect(requests.first.url.queryParameters['channel'], 'b2c');
    expect(requests.first.url.queryParameters['store_id'], '7');
    expect(requests.first.headers['authorization'], 'Bearer customer-token');

    final events = requests
        .where((request) => request.method == 'POST')
        .map((request) => jsonDecode(request.body) as Map<String, dynamic>)
        .map((payload) => payload['event'])
        .toList(growable: false);
    expect(events, ['impression', 'dismiss']);
  });
}
