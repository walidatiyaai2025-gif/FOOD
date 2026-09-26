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

  test('device registry uses the authenticated FOODEX driver push contract', () async {
    late http.Request captured;
    final client = MockClient((request) async {
      captured = request;
      return http.Response(jsonEncode({'data': {'id': 77}}), 201, headers: {'content-type': 'application/json'});
    });
    final registry = DriverPushDeviceRegistry(baseUrl: 'https://foodex.50sols.com', client: client);
    final id = await registry.register(accessToken: 'driver-token', firebaseToken: 'driver-fcm-token');
    expect(id, 77);
    expect(captured.url.toString(), 'https://foodex.50sols.com/api/v1/push/devices');
    expect(captured.headers['Authorization'], 'Bearer driver-token');
    final body = jsonDecode(captured.body) as Map<String, dynamic>;
    expect(body['app'], 'driver');
    expect(body['environment'], 'production');
    expect(body['token'], 'driver-fcm-token');
  });
}
