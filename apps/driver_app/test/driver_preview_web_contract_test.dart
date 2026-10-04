import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_driver_app/core/auth/driver_session.dart';
import 'package:foodex_driver_app/core/preview/driver_preview_bootstrap.dart';
import 'package:foodex_driver_app/core/preview/driver_preview_transport.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

Map<String, dynamic> _message({
  String version = 'shared-flutter-v1',
  String targetType = 'driver',
  String channel = 'b2c',
  int storeId = 41,
  String mode = 'read_only',
  bool readOnly = true,
  String locale = 'en',
  String configuration = 'published',
  String credential = 'preview-secret',
}) =>
    {
      'type': 'foodex.preview.bootstrap',
      'version': version,
      'payload': {
        'context': {
          'session_id': 'preview-session',
          'target_type': targetType,
          'channel': channel,
          'store_id': storeId,
          'mode': mode,
          'read_only': readOnly,
          'target': {
            'user_id': 17,
            'driver_id': 23,
            'name': 'Preview Driver',
            'locale': locale,
          },
        },
        'credential': credential,
        'configuration': configuration,
        'locale': locale,
        'device': {'profile': 'phone_standard', 'width': 390},
        'safe_mode': 'read_only',
      },
    };

void main() {
  const origin = 'https://foodex.example';

  test('Driver bootstrap preserves exact scope without bearer identity', () {
    final bootstrap = DriverPreviewBootstrap.parse(
      _message(),
      origin: origin,
      expectedOrigin: origin,
    );

    expect(bootstrap.context.channel, DriverChannel.b2c);
    expect(bootstrap.context.storeId, 41);
    expect(bootstrap.context.driverId, 23);
    expect(bootstrap.context.runtimeIdentity.token, isEmpty);
    expect(bootstrap.credential, 'preview-secret');
    expect(bootstrap.safeStatusMetadata['auth_mode'], 'preview-driver');
    expect(
      bootstrap.safeStatusMetadata.values,
      isNot(contains('preview-secret')),
    );
  });

  test('message gate requires exact parent source and origin', () {
    expect(
      DriverPreviewHostContract.allowsMessage(
        origin: origin,
        expectedOrigin: origin,
        fromParent: true,
      ),
      isTrue,
    );
    expect(
      DriverPreviewHostContract.allowsMessage(
        origin: origin,
        expectedOrigin: origin,
        fromParent: false,
      ),
      isFalse,
    );
    expect(
      DriverPreviewHostContract.allowsMessage(
        origin: 'https://evil.example',
        expectedOrigin: origin,
        fromParent: true,
      ),
      isFalse,
    );
  });

  test('bootstrap rejects wrong contract target mode and credential', () {
    expect(
      () => DriverPreviewBootstrap.parse(
        _message(version: 'driver-only-v1'),
        origin: origin,
        expectedOrigin: origin,
      ),
      throwsA(isA<DriverPreviewBootstrapException>()),
    );
    expect(
      () => DriverPreviewBootstrap.parse(
        _message(targetType: 'customer'),
        origin: origin,
        expectedOrigin: origin,
      ),
      throwsA(isA<DriverPreviewBootstrapException>()),
    );
    expect(
      () => DriverPreviewBootstrap.parse(
        _message(mode: 'interactive'),
        origin: origin,
        expectedOrigin: origin,
      ),
      throwsA(isA<DriverPreviewBootstrapException>()),
    );
    expect(
      () => DriverPreviewBootstrap.parse(
        _message(credential: ''),
        origin: origin,
        expectedOrigin: origin,
      ),
      throwsA(isA<DriverPreviewBootstrapException>()),
    );
  });

  test('preview transport rewrites only Driver reads and strips bearer', () async {
    http.Request? captured;
    final delegate = MockClient((request) async {
      captured = request;
      return http.Response('{"data":[],"meta":{"scope":"all","total":0}}', 200);
    });
    final client = DriverPreviewReadHttpClient(
      delegate,
      credential: 'opaque-preview',
    );

    final response = await client.get(
      Uri.parse('https://api.example/api/v1/driver/assignments?scope=all'),
      headers: const {
        'Authorization': 'Bearer production-sentinel',
        'Cookie': 'secret-cookie',
      },
    );

    expect(response.statusCode, 200);
    expect(
      captured?.url.path,
      '/api/v1/app-preview/driver/assignments',
    );
    expect(captured?.url.queryParameters['scope'], 'all');
    expect(captured?.headers['X-Foodex-Preview-Token'], 'opaque-preview');
    expect(captured?.headers.containsKey('Authorization'), isFalse);
    expect(captured?.headers.containsKey('Cookie'), isFalse);
  });

  test('preview transport exposes authoritative failure reasons and read health',
      () async {
    http.Request? captured;
    final states = <Map<String, Object?>>[];
    final client = DriverPreviewReadHttpClient(
      MockClient((request) async {
        captured = request;
        return http.Response(
          '{"type":"failed-delivery-reasons","data":[]}',
          200,
          headers: const {'content-type': 'application/json'},
        );
      }),
      credential: 'opaque-preview',
      onReadState: (state, endpoint, statusCode, updatedAt) {
        states.add({
          'state': state,
          'endpoint': endpoint,
          'status': statusCode,
          'updated_at': updatedAt,
        });
      },
    );

    final response = await client.get(
      Uri.parse(
        'https://api.example/api/v1/lookups/failed-delivery-reasons',
      ),
    );

    expect(response.statusCode, 200);
    expect(
      captured?.url.path,
      '/api/v1/app-preview/driver/lookups/failed-delivery-reasons',
    );
    expect(states, hasLength(1));
    expect(states.single['state'], 'ready');
    expect(states.single['status'], 200);
    expect(states.single['updated_at'], isA<String>());
  });

  test('preview transport reports disconnected instead of stale-looking live data',
      () async {
    final states = <String>[];
    final client = DriverPreviewReadHttpClient(
      MockClient((_) async => throw http.ClientException('offline')),
      credential: 'opaque-preview',
      onReadState: (state, _, __, ___) => states.add(state),
    );

    await expectLater(
      client.get(
        Uri.parse('https://api.example/api/v1/driver/assignments?scope=all'),
      ),
      throwsA(isA<http.ClientException>()),
    );
    expect(states, ['disconnected']);
  });

  test('preview transport rejects mutation and unrelated paths', () async {
    final client = DriverPreviewReadHttpClient(
      MockClient((_) async => http.Response('{}', 200)),
      credential: 'opaque-preview',
    );

    await expectLater(
      client.post(
        Uri.parse('https://api.example/api/v1/driver/assignments/1/status'),
      ),
      throwsA(isA<DriverPreviewMutationBlocked>()),
    );
    await expectLater(
      client.get(Uri.parse('https://api.example/api/v1/auth/logout')),
      throwsA(isA<DriverPreviewMutationBlocked>()),
    );
  });
}
