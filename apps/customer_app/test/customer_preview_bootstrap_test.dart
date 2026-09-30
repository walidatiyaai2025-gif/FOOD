import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/auth/customer_session.dart';
import 'package:foodex_customer_app/core/preview/customer_preview_bootstrap.dart';

void main() {
  const origin = 'https://foodex.50sols.com';

  Map<String, dynamic> message({
    String channel = 'b2c',
    int storeId = 7,
    bool authenticated = false,
    String locale = 'ar',
    String configuration = 'published',
    String? credential,
  }) =>
      {
        'type': 'foodex.preview.bootstrap',
        'version': CustomerPreviewHostContract.version,
        'payload': {
          'context': {
            'session_id': authenticated ? 'session-1' : null,
            'target_type': 'customer',
            'channel': channel,
            'store_id': storeId,
            'mode': 'read_only',
            'read_only': true,
            'support_access': false,
            'target': authenticated
                ? {
                    'user_id': 44,
                    'name': 'Preview Customer',
                    'locale': locale,
                  }
                : null,
          },
          'credential': credential,
          'configuration': configuration,
          'locale': locale,
          'device': {
            'profile': 'phone_standard',
            'width': 390,
          },
          'safe_mode': 'read_only',
        },
      };

  test('accepts exact-store B2C guest bootstrap without credential', () {
    final bootstrap = CustomerPreviewBootstrap.parse(
      message(),
      origin: origin,
      expectedOrigin: origin,
    );

    expect(bootstrap.context.channel, CustomerChannel.b2c);
    expect(bootstrap.context.storeId, 7);
    expect(bootstrap.context.authenticated, isFalse);
    expect(bootstrap.context.runtimeIdentity.isAuthenticated, isFalse);
    expect(bootstrap.credential, isNull);
    expect(bootstrap.safeStatusMetadata.containsKey('credential'), isFalse);
  });

  test('accepts authenticated B2B session and keeps bearer identity empty', () {
    final bootstrap = CustomerPreviewBootstrap.parse(
      message(
        channel: 'b2b',
        storeId: 1,
        authenticated: true,
        locale: 'en',
        credential: 'opaque-preview-secret',
      ),
      origin: origin,
      expectedOrigin: origin,
    );

    expect(bootstrap.context.channel, CustomerChannel.b2b);
    expect(bootstrap.context.authenticated, isTrue);
    expect(bootstrap.credential, 'opaque-preview-secret');
    expect(bootstrap.context.runtimeIdentity.isAuthenticated, isTrue);
    expect(bootstrap.context.runtimeIdentity.accessToken, isNull);
    expect(
      bootstrap.safeStatusMetadata.values,
      isNot(contains('opaque-preview-secret')),
    );
  });

  test('authenticated bootstrap requires opaque preview credential', () {
    expect(
      () => CustomerPreviewBootstrap.parse(
        message(authenticated: true),
        origin: origin,
        expectedOrigin: origin,
      ),
      throwsA(
        isA<CustomerPreviewBootstrapException>().having(
          (error) => error.code,
          'code',
          'preview_credential_required',
        ),
      ),
    );
  });

  test('guest bootstrap rejects a credential', () {
    expect(
      () => CustomerPreviewBootstrap.parse(
        message(credential: 'must-not-exist'),
        origin: origin,
        expectedOrigin: origin,
      ),
      throwsA(
        isA<CustomerPreviewBootstrapException>().having(
          (error) => error.code,
          'code',
          'preview_guest_credential_forbidden',
        ),
      ),
    );
  });

  test('message gate rejects same-origin non-parent senders', () {
    expect(
      CustomerPreviewHostContract.allowsMessage(
        origin: origin,
        expectedOrigin: origin,
        fromParent: true,
      ),
      isTrue,
    );
    expect(
      CustomerPreviewHostContract.allowsMessage(
        origin: origin,
        expectedOrigin: origin,
        fromParent: false,
      ),
      isFalse,
    );
    expect(
      CustomerPreviewHostContract.allowsMessage(
        origin: 'https://evil.example',
        expectedOrigin: origin,
        fromParent: true,
      ),
      isFalse,
    );
  });

  test('rejects wrong origin, contract, target and unsafe mode', () {
    expect(
      () => CustomerPreviewBootstrap.parse(
        message(),
        origin: 'https://evil.example',
        expectedOrigin: origin,
      ),
      throwsA(isA<CustomerPreviewBootstrapException>()),
    );

    final wrongVersion = message()..['version'] = 'old-contract';
    expect(
      () => CustomerPreviewBootstrap.parse(
        wrongVersion,
        origin: origin,
        expectedOrigin: origin,
      ),
      throwsA(
        isA<CustomerPreviewBootstrapException>().having(
          (error) => error.code,
          'code',
          'preview_version_mismatch',
        ),
      ),
    );

    final wrongTarget = message();
    (wrongTarget['payload']['context'] as Map<String, dynamic>)['target_type'] =
        'driver';
    expect(
      () => CustomerPreviewBootstrap.parse(
        wrongTarget,
        origin: origin,
        expectedOrigin: origin,
      ),
      throwsA(
        isA<CustomerPreviewBootstrapException>().having(
          (error) => error.code,
          'code',
          'preview_customer_required',
        ),
      ),
    );

    final unsafe = message();
    (unsafe['payload'] as Map<String, dynamic>)['safe_mode'] = 'write';
    expect(
      () => CustomerPreviewBootstrap.parse(
        unsafe,
        origin: origin,
        expectedOrigin: origin,
      ),
      throwsA(
        isA<CustomerPreviewBootstrapException>().having(
          (error) => error.code,
          'code',
          'preview_safe_mode_required',
        ),
      ),
    );
  });

  test('rejects invalid locale, configuration and device width', () {
    expect(
      () => CustomerPreviewBootstrap.parse(
        message(locale: 'fr'),
        origin: origin,
        expectedOrigin: origin,
      ),
      throwsA(isA<CustomerPreviewBootstrapException>()),
    );

    expect(
      () => CustomerPreviewBootstrap.parse(
        message(configuration: 'local-copy'),
        origin: origin,
        expectedOrigin: origin,
      ),
      throwsA(isA<CustomerPreviewBootstrapException>()),
    );

    final invalidDevice = message();
    (invalidDevice['payload']['device'] as Map<String, dynamic>)['width'] = 2000;
    expect(
      () => CustomerPreviewBootstrap.parse(
        invalidDevice,
        origin: origin,
        expectedOrigin: origin,
      ),
      throwsA(
        isA<CustomerPreviewBootstrapException>().having(
          (error) => error.code,
          'code',
          'preview_device_invalid',
        ),
      ),
    );
  });
}
