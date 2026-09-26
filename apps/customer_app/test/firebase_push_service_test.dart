import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:foodex_customer_app/core/push/firebase_push_service.dart';

void main() {
  test('Firebase configuration stays disabled without external values', () {
    const config = FoodexFirebaseConfig(apiKey: '', appId: '', messagingSenderId: '', projectId: '');
    expect(config.isConfigured, isFalse);
  });

  test('notification routes are constrained to authorized customer destinations', () {
    expect(CustomerFirebasePushService.routeForData({'order_id': '17'}), '/orders/17/track');
    expect(CustomerFirebasePushService.routeForData({'order_id': '18', 'channel': 'b2b'}), '/b2b/orders/18');
    expect(CustomerFirebasePushService.routeForData({'route': '/profile'}), '/profile');
    expect(CustomerFirebasePushService.routeForData({'route': '/admin/security'}), '/notifications');
  });

  test('device registry uses the authenticated FOODEX push contract', () async {
    late http.Request captured;
    final client = MockClient((request) async {
      captured = request;
      return http.Response(jsonEncode({'data': {'id': 42}}), 201, headers: {'content-type': 'application/json'});
    });
    final registry = CustomerPushDeviceRegistry(baseUrl: 'https://foodex.50sols.com', client: client);
    final id = await registry.register(accessToken: 'access-token', firebaseToken: 'fcm-token');
    expect(id, 42);
    expect(captured.url.toString(), 'https://foodex.50sols.com/api/v1/push/devices');
    expect(captured.headers['Authorization'], 'Bearer access-token');
    final body = jsonDecode(captured.body) as Map<String, dynamic>;
    expect(body['app'], 'customer');
    expect(body['environment'], 'production');
    expect(body['token'], 'fcm-token');
  });
}
