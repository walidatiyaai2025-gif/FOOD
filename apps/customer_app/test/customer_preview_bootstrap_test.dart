import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/auth/customer_session.dart';
import 'package:foodex_customer_app/core/preview/customer_preview_bootstrap.dart';

void main() {
  const origin = 'https://foodex.example';
  const version = 'shared-flutter-v1';

  test('authenticated bootstrap accepts exact origin and opaque credential', () {
    final bootstrap = CustomerPreviewBootstrap.parse(
      eventOrigin: origin,
      allowedParentOrigin: origin,
      expectedContractVersion: version,
      message: {
        'type': CustomerPreviewBootstrap.messageType,
        'version': version,
        'payload': {
          'credential': 'opaque-preview-token',
          'configuration': 'draft',
          'locale': 'en',
          'safe_mode': 'read_only',
          'device': {'profile': 'phone_standard', 'width': 390},
          'context': {
            'session_id': 'session-1',
            'target_type': 'customer',
            'channel': 'b2c',
            'store_id': 7,
            'read_only': true,
            'target': {
              'user_id': 44,
              'name': 'Preview Customer',
              'locale': 'en',
            },
          },
        },
      },
    );

    expect(bootstrap.authenticated, isTrue);
    expect(bootstrap.credential, 'opaque-preview-token');
    expect(bootstrap.context.channel, CustomerChannel.b2c);
    expect(bootstrap.context.storeId, 7);
    expect(bootstrap.context.runtimeIdentity.accessToken, isNull);
    expect(bootstrap.configuration, 'draft');
    expect(bootstrap.locale, 'en');
    expect(bootstrap.deviceWidth, 390);
  });

  test('guest bootstrap forbids credential and creates guest identity', () {
    final bootstrap = CustomerPreviewBootstrap.parse(
      eventOrigin: origin,
      allowedParentOrigin: origin,
      expectedContractVersion: version,
      message: {
        'type': CustomerPreviewBootstrap.messageType,
        'version': version,
        'payload': {
          'credential': null,
          'configuration': 'published',
          'locale': 'ar',
          'safe_mode': 'read_only',
          'device': {'profile': 'phone_compact', 'width': 360},
          'context': {
            'session_id': null,
            'target_type': 'customer',
            'channel': 'b2b',
            'store_id': 1,
            'read_only': true,
            'target': null,
          },
        },
      },
    );

    expect(bootstrap.authenticated, isFalse);
    expect(bootstrap.credential, isNull);
    expect(bootstrap.context.channel, CustomerChannel.b2b);
    expect(bootstrap.context.runtimeIdentity.isAuthenticated, isFalse);
  });

  test('wrong origin and contract version are rejected', () {
    expect(
      () => CustomerPreviewBootstrap.parse(
        eventOrigin: 'https://evil.example',
        allowedParentOrigin: origin,
        expectedContractVersion: version,
        message: _guestMessage(version),
      ),
      throwsA(
        isA<CustomerPreviewBootstrapException>()
            .having((e) => e.code, 'code', 'origin_rejected'),
      ),
    );

    expect(
      () => CustomerPreviewBootstrap.parse(
        eventOrigin: origin,
        allowedParentOrigin: origin,
        expectedContractVersion: version,
        message: _guestMessage('wrong-version'),
      ),
      throwsA(
        isA<CustomerPreviewBootstrapException>()
            .having((e) => e.code, 'code', 'contract_version_mismatch'),
      ),
    );
  });

  test('unsafe mode invalid width and guest credential are rejected', () {
    final unsafe = _guestMessage(version);
    (unsafe['payload'] as Map<String, dynamic>)['safe_mode'] = 'interactive';

    expect(
      () => CustomerPreviewBootstrap.parse(
        eventOrigin: origin,
        allowedParentOrigin: origin,
        expectedContractVersion: version,
        message: unsafe,
      ),
      throwsA(
        isA<CustomerPreviewBootstrapException>()
            .having((e) => e.code, 'code', 'unsafe_preview_mode'),
      ),
    );

    final badWidth = _guestMessage(version);
    ((badWidth['payload'] as Map<String, dynamic>)['device']
        as Map<String, dynamic>)['width'] = 200;

    expect(
      () => CustomerPreviewBootstrap.parse(
        eventOrigin: origin,
        allowedParentOrigin: origin,
        expectedContractVersion: version,
        message: badWidth,
      ),
      throwsA(
        isA<CustomerPreviewBootstrapException>()
            .having((e) => e.code, 'code', 'invalid_device_width'),
      ),
    );

    final guestWithCredential = _guestMessage(version);
    (guestWithCredential['payload'] as Map<String, dynamic>)['credential'] =
        'must-not-exist';

    expect(
      () => CustomerPreviewBootstrap.parse(
        eventOrigin: origin,
        allowedParentOrigin: origin,
        expectedContractVersion: version,
        message: guestWithCredential,
      ),
      throwsA(
        isA<CustomerPreviewBootstrapException>()
            .having((e) => e.code, 'code', 'guest_credential_forbidden'),
      ),
    );
  });

  test('status metadata contains no preview credential', () {
    final bootstrap = CustomerPreviewBootstrap.parse(
      eventOrigin: origin,
      allowedParentOrigin: origin,
      expectedContractVersion: version,
      message: _guestMessage(version),
    );

    final status = bootstrap.statusMessage(
      'ready',
      runtimeVersion: version,
      appVersion: '1.0.38',
      configurationRevision: 'rev-1',
    );

    expect(status.toString(), isNot(contains('credential')));
    expect(status.toString(), isNot(contains('preview-token')));
    expect((status['metadata'] as Map)['configuration_revision'], 'rev-1');
  });
}

Map<String, dynamic> _guestMessage(String version) => {
      'type': CustomerPreviewBootstrap.messageType,
      'version': version,
      'payload': {
        'credential': null,
        'configuration': 'published',
        'locale': 'ar',
        'safe_mode': 'read_only',
        'device': {'profile': 'phone_standard', 'width': 390},
        'context': {
          'session_id': null,
          'target_type': 'customer',
          'channel': 'b2c',
          'store_id': 7,
          'read_only': true,
          'target': null,
        },
      },
    };
