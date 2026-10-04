import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/api/b2b_api.dart';
import 'package:foodex_customer_app/features/b2b/business_account_profile.dart';

void main() {
  testWidgets(
      'C13 Screen 13 renders Arabic account/settings composition and switch hook',
      (tester) async {
    var switchCount = 0;

    await tester.pumpWidget(
      MaterialApp(
        locale: const Locale('ar'),
        supportedLocales: const [Locale('ar'), Locale('en')],
        routes: {
          '/b2b/addresses': (_) => const Scaffold(body: Text('addresses')),
        },
        home: Scaffold(
          body: SingleChildScrollView(
            child: B2bBusinessAccountProfile(
              api: const _ProfileApi(),
              endpoint: '/api/v1/profile',
              addressesRoute: '/b2b/addresses',
              onSwitchStore: () => switchCount += 1,
            ),
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    final hero = find.byKey(const ValueKey('b2b-profile-hero'));
    expect(hero, findsOneWidget);
    expect(
      Directionality.of(tester.element(hero)),
      TextDirection.rtl,
    );
    expect(find.text('حسابي'), findsOneWidget);
    expect(find.text('شركة فودكس'), findsWidgets);
    expect(find.text('حساب نشط'), findsOneWidget);
    expect(find.byKey(const ValueKey('b2b-profile-actions')), findsOneWidget);
    expect(
      find.byKey(const ValueKey('b2b-profile-addresses-action')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('b2b-profile-settings-action')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('b2b-profile-switch-store')),
      findsOneWidget,
    );
    expect(find.text('تبديل المتجر'), findsOneWidget);
    expect(find.text('الجملة والتجزئة'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('b2b-profile-switch-store')));
    await tester.pump();
    expect(switchCount, 1);

    await tester.tap(find.byKey(const ValueKey('b2b-profile-settings-action')));
    await tester.pumpAndSettle();
    expect(find.text('إعدادات الحساب'), findsOneWidget);
    expect(find.text('AR'), findsWidgets);

    expect(find.text('company_name'), findsNothing);
    expect(find.text('retail_store_ids'), findsNothing);
  });

  testWidgets('C13 Screen 13 keeps English LTR visual contract',
      (tester) async {
    await tester.pumpWidget(
      const MaterialApp(
        locale: Locale('en'),
        supportedLocales: [Locale('ar'), Locale('en')],
        home: Scaffold(
          body: SingleChildScrollView(
            child: B2bBusinessAccountProfile(
              api: _ProfileApi(),
              endpoint: '/api/v1/profile',
              addressesRoute: '/b2b/addresses',
            ),
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    final hero = find.byKey(const ValueKey('b2b-profile-hero'));
    expect(hero, findsOneWidget);
    expect(
      Directionality.of(tester.element(hero)),
      TextDirection.ltr,
    );
    expect(find.text('My account'), findsOneWidget);
    expect(find.text('Account details'), findsOneWidget);
    expect(find.text('Switch store'), findsOneWidget);
    expect(find.textContaining('from More'), findsOneWidget);
    expect(find.text('Active'), findsOneWidget);
  });
}

class _ProfileApi implements B2bApi {
  const _ProfileApi();

  @override
  Future<Object?> get(String path) async => {
        'name': 'Buyer One',
        'email': 'buyer@example.test',
        'locale': path.contains('profile') ? 'ar' : 'en',
        'retail_merchant': true,
        'retail_store_ids': [22],
        'owned_retail_store_ids': [22],
        'managed_retail_store_ids': [22],
        'b2b_customer_ids': [19],
        'retail_wholesale_accounts': [
          {
            'retail_store_id': 22,
            'b2b_customer_id': 19,
          },
        ],
        'customer': {
          'id': 19,
          'store_id': 7,
          'type': 'b2b',
          'name': 'Buyer One',
          'phone': '55512345',
          'email': 'buyer@example.test',
        },
        'business_account': {
          'id': 27,
          'company_name': 'شركة فودكس',
          'status': 'active',
          'tax_number': 'TAX-872',
        },
        'addresses': [
          {'id': 1},
        ],
        'favorites': [
          {'id': 2},
        ],
        'roles': ['B2B_CUSTOMER'],
      };
}
