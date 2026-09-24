import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/version_policy.dart';

void main() {
  test('forced and unsupported policies block the app', () {
    for (final status in ['forced', 'unsupported']) {
      final policy = AppVersionPolicy.fromJson({
        'latest_version': '2.0.0',
        'minimum_supported_version': '1.5.0',
        'force_update': true,
        'update_required': true,
        'status': status,
        'store_url': 'https://example.com/store',
        'release_notes': null,
      });
      expect(policy.blocksApp, isTrue);
    }
  });

  test('optional policy uses official store URL without sideload data', () {
    final policy = AppVersionPolicy.fromJson({
      'latest_version': '2.0.0',
      'minimum_supported_version': '1.5.0',
      'force_update': false,
      'update_required': true,
      'status': 'optional',
      'store_url': 'https://example.com/store',
      'release_notes': 'Update available',
    });
    expect(policy.blocksApp, isFalse);
    expect(policy.storeUrl.scheme, 'https');
  });
}
