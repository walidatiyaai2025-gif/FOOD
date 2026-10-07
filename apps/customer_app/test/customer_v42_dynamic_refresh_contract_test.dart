import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

void main() {
  test('all canonical wholesale dynamic screens refresh on app resume', () {
    final source = File(
      'lib/features/storefront/wholesale_multistore_screens.dart',
    ).readAsStringSync();

    for (final stateName in const <String>[
      '_WholesaleHomeDesignScreenState',
      '_WholesaleCatalogDesignScreenState',
      '_WholesaleProductDetailsDesignScreenState',
      '_WholesaleCartDesignScreenState',
      '_WholesaleCheckoutDesignScreenState',
      '_WholesaleOrdersDesignScreenState',
      '_WholesaleOrderDetailsDesignScreenState',
    ]) {
      final start = source.indexOf('class $stateName');
      expect(start, greaterThanOrEqualTo(0), reason: '$stateName is missing');

      final nextClass = source.indexOf('\nclass ', start + 10);
      final block = source.substring(
        start,
        nextClass < 0 ? source.length : nextClass,
      );

      expect(
        block,
        contains('WidgetsBindingObserver'),
        reason: '$stateName must observe lifecycle changes',
      );
      expect(
        block,
        contains('didChangeAppLifecycleState'),
        reason: '$stateName must define resume refresh behavior',
      );
      expect(
        block,
        contains('AppLifecycleState.resumed'),
        reason: '$stateName must refresh when the app resumes',
      );
    }
  });

  test('customer orders keep both polling and truthful stale evidence', () {
    final source = File(
      'lib/features/customer_orders/customer_order_screens.dart',
    ).readAsStringSync();

    expect(source, contains('Timer.periodic'));
    expect(source, contains('CustomerOrderRefreshPolicy.openOrderPollInterval'));
    expect(source, contains('lastSuccessfulAt'));
    expect(source, contains('_OrdersStaleBanner'));
  });
}
