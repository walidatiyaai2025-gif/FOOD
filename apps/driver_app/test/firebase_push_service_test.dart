import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:foodex_driver_app/core/push/firebase_push_service.dart';

void main() {
  test('Firebase configuration stays disabled without external values', () {
    const config = DriverFirebaseConfig(apiKey: '', appId: '', messagingSenderId: '', projectId: '');
    expect(config.isConfigured, isFalse);
  });

  test('driver local notification payload restores safe assignment routing', () {
    final open = DriverFirebasePushService.openForPayload(jsonEncode({
      'assignment_id': '42',
      'order_id': '99',
      'access_revoked': '0',
    }));

    expect(open, isNotNull);
    expect(open!.assignmentId, 42);
    expect(open.orderId, 99);
    expect(open.accessRevoked, isFalse);
    expect(DriverFirebasePushService.openForPayload('{invalid'), isNull);
  });

  test('driver push data preserves assignment routing and revocation state', () {
    final open = DriverFirebasePushService.openForData({
      'assignment_id': '42',
      'order_id': '99',
      'access_revoked': '0',
    });

    expect(open.assignmentId, 42);
    expect(open.orderId, 99);
    expect(open.accessRevoked, isFalse);

    final revoked = DriverFirebasePushService.openForData({
      'assignment_id': '42',
      'access_revoked': '1',
    });
    expect(revoked.assignmentId, 42);
    expect(revoked.accessRevoked, isTrue);
  });

  test('foreground driver alert keeps the exact assignment target and stable dedupe key', () {
    const open = DriverPushOpen(assignmentId: 42, orderId: 99);
    const alert = DriverPushAlert(
      title: 'New order',
      body: 'Order 99 is ready',
      open: open,
    );

    expect(alert.open.assignmentId, 42);
    expect(alert.open.orderId, 99);
    expect(alert.eventKey, 'assignment:42');

    const orderOnly = DriverPushAlert(
      title: 'Order update',
      body: 'Updated',
      open: DriverPushOpen(orderId: 99),
    );
    expect(orderOnly.eventKey, 'order:99');
  });

  test('device registry revokes authenticated driver push device', () async {
    late http.Request captured;
    final client = MockClient((request) async {
      captured = request;
      return http.Response('', 204);
    });
    final registry = DriverPushDeviceRegistry(
      baseUrl: 'https://foodex.50sols.com',
      client: client,
    );

    await registry.revoke(
      accessToken: 'driver-token',
      deviceId: 77,
    );

    expect(
      captured.url.toString(),
      'https://foodex.50sols.com/api/v1/push/devices/77',
    );
    expect(captured.method, 'DELETE');
    expect(captured.headers['Authorization'], 'Bearer driver-token');
  });

  test('device registry uses the authenticated FOODEX driver push contract', () async {
    late http.Request captured;
    final client = MockClient((request) async {
      captured = request;
      return http.Response(jsonEncode({'data': {'id': 77}}), 201, headers: {'content-type': 'application/json'});
    });
    final registry = DriverPushDeviceRegistry(baseUrl: 'https://foodex.50sols.com', client: client);
    final id = await registry.register(
      accessToken: 'driver-token',
      firebaseToken: 'driver-fcm-token',
      installId: 'driver-install-1',
    );
    expect(id, 77);
    expect(captured.url.toString(), 'https://foodex.50sols.com/api/v1/push/devices');
    expect(captured.headers['Authorization'], 'Bearer driver-token');
    final body = jsonDecode(captured.body) as Map<String, dynamic>;
    expect(body['app'], 'driver');
    expect(body['environment'], 'production');
    expect(body['token'], 'driver-fcm-token');
    expect(body['install_id'], 'driver-install-1');
  });
}
