import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/auth/customer_session.dart';
import 'package:foodex_customer_app/core/preview/customer_preview_bridge_contract.dart';

Map<String, dynamic> _message({
  String version = 'shared-flutter-v1',
  String channel = 'b2c',
  int storeId = 7,
  String locale = 'en',
  String configuration = 'published',
  Object? target = const {'user_id': 44, 'name': 'Preview Customer', 'locale': 'en'},
  Object? credential = 'preview-secret',
}) =>
    {
      'type': 'foodex.preview.bootstrap',
      'version': version,
      'payload': {
        'context': {
          'session_id': target == null ? null : 'preview-session',
          'target_type': 'customer',
          'channel': channel,
          'store_id': storeId,
          'mode': 'read_only',
          'read_only': true,
          'target': target,
        },
        'credential': credential,
        'configuration': configuration,
        'locale': locale,
        'device': {'profile': 'phone_standard', 'width': 390},
        'safe_mode': 'read_only',
      },
    };

void main() {
  test('authenticated bootstrap never turns preview credential into bearer identity', () {
    final bootstrap = CustomerPreviewBootstrap.fromMessage(
      _message(),
      expectedVersion: 'shared-flutter-v1',
    );

    expect(bootstrap.authenticated, isTrue);
    expect(bootstrap.context.channel, CustomerChannel.b2c);
    expect(bootstrap.context.storeId, 7);
    expect(bootstrap.context.runtimeIdentity.accessToken, isNull);
    expect(bootstrap.credential, 'preview-secret');
    expect(bootstrap.configuration, 'published');
  });

  test('guest bootstrap carries no credential or impersonation session', () {
    final bootstrap = CustomerPreviewBootstrap.fromMessage(
      _message(target: null, credential: null, channel: 'b2b'),
      expectedVersion: 'shared-flutter-v1',
    );

    expect(bootstrap.authenticated, isFalse);
    expect(bootstrap.context.channel, CustomerChannel.b2b);
    expect(bootstrap.context.runtimeIdentity.isAuthenticated, isFalse);
    expect(bootstrap.credential, isNull);
  });

  test('bridge rejects wrong version, write mode, channel and missing store', () {
    expect(
      () => CustomerPreviewBootstrap.fromMessage(
        _message(version: 'wrong'),
        expectedVersion: 'shared-flutter-v1',
      ),
      throwsFormatException,
    );

    final writable = _message();
    (writable['payload'] as Map<String, dynamic>)['safe_mode'] = 'interactive';
    expect(
      () => CustomerPreviewBootstrap.fromMessage(
        writable,
        expectedVersion: 'shared-flutter-v1',
      ),
      throwsFormatException,
    );

    expect(
      () => CustomerPreviewBootstrap.fromMessage(
        _message(channel: 'other'),
        expectedVersion: 'shared-flutter-v1',
      ),
      throwsFormatException,
    );
    expect(
      () => CustomerPreviewBootstrap.fromMessage(
        _message(storeId: 0),
        expectedVersion: 'shared-flutter-v1',
      ),
      throwsFormatException,
    );
  });

  test('bridge enforces credential/persona pairing and AR EN locale', () {
    expect(
      () => CustomerPreviewBootstrap.fromMessage(
        _message(credential: null),
        expectedVersion: 'shared-flutter-v1',
      ),
      throwsFormatException,
    );
    expect(
      () => CustomerPreviewBootstrap.fromMessage(
        _message(target: null, credential: 'forbidden'),
        expectedVersion: 'shared-flutter-v1',
      ),
      throwsFormatException,
    );
    expect(
      () => CustomerPreviewBootstrap.fromMessage(
        _message(locale: 'fr'),
        expectedVersion: 'shared-flutter-v1',
      ),
      throwsFormatException,
    );
  });

  test('draft and locale metadata survive bootstrap', () {
    final bootstrap = CustomerPreviewBootstrap.fromMessage(
      _message(locale: 'ar', configuration: 'draft'),
      expectedVersion: 'shared-flutter-v1',
    );

    expect(bootstrap.locale, 'ar');
    expect(bootstrap.context.targetLocale, 'en');
    expect(bootstrap.context.configurationRevision, 'draft');
    expect(bootstrap.deviceProfile, 'phone_standard');
    expect(bootstrap.deviceWidth, 390);
  });
}
