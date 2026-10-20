import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/app.dart';
import 'package:foodex_customer_app/core/api/b2b_api.dart';
import 'package:foodex_customer_app/core/api/customer_action_api.dart';
import 'package:foodex_customer_app/core/auth/customer_session.dart';
import 'package:foodex_customer_app/features/customer_account/customer_account_v3_widgets.dart';
import 'package:foodex_customer_app/features/retail/offers/customer_flash_offers.dart';
import 'package:foodex_customer_app/shared/customer_ui_v3/customer_ui_v3.dart';

void main() {
  const session = CustomerSession.authenticated(CustomerChannel.b2b);

  for (final locale in const [Locale('ar'), Locale('en')]) {
    testWidgets(
      'B2B profile consumes Customer VNext shared account chrome on 320px '
      '${locale.languageCode}',
      (tester) async {
        tester.view.physicalSize = const Size(320, 760);
        tester.view.devicePixelRatio = 1;
        addTearDown(() {
          tester.view.resetPhysicalSize();
          tester.view.resetDevicePixelRatio();
        });

        await tester.pumpWidget(
          FoodexCustomerApp(
            session: session,
            initialRoute: '/b2b/profile?channel=wholesale&store_id=7',
            locale: locale,
            b2bApi: const _AccountB2bApi(),
            actionApi: const _NoopCustomerActionApi(),
            showPersistentFooter: false,
          ),
        );
        await tester.pumpAndSettle();

        expect(
          find.byKey(const ValueKey('foodex-wholesale-shared-header')),
          findsOneWidget,
        );
        expect(find.byType(CustomerAccountHeader), findsNothing);
        expect(find.byType(CustomerOutlineIconButton), findsNothing);

        final title = locale.languageCode == 'ar'
            ? find.text('الملف التجاري')
            : find.text('Business profile');
        if (title.evaluate().isNotEmpty) {
          expect(
            Directionality.of(tester.element(title.first)),
            locale.languageCode == 'ar' ? TextDirection.rtl : TextDirection.ltr,
          );
        }

        expect(tester.takeException(), isNull);
      },
    );
  }
  for (final locale in const [Locale('ar'), Locale('en')]) {
    testWidgets(
      'owned B2B secondary routes share VNext chrome on 320px '
      '${locale.languageCode}',
      (tester) async {
        tester.view.physicalSize = const Size(320, 760);
        tester.view.devicePixelRatio = 1;
        addTearDown(() {
          tester.view.resetPhysicalSize();
          tester.view.resetDevicePixelRatio();
        });

        for (final route in const [
          '/b2b/dashboard?store_id=7',
          '/b2b/reports/purchases?store_id=7',
          '/b2b/invoices?store_id=7',
          '/b2b/invoices/31?store_id=7',
          '/b2b/account-statement?store_id=7',
        ]) {
          await tester.pumpWidget(
            FoodexCustomerApp(
              session: session,
              initialRoute: route,
              locale: locale,
              b2bApi: const _AccountB2bApi(),
              actionApi: const _NoopCustomerActionApi(),
              showPersistentFooter: false,
            ),
          );
          await tester.pumpAndSettle();

          expect(
            find.byKey(const ValueKey('foodex-wholesale-shared-header')),
            findsOneWidget,
            reason: route,
          );
          expect(tester.takeException(), isNull, reason: route);
        }
      },
    );

    testWidgets(
      'Flash Offers and Flash Checkout consume VNext chrome '
      '${locale.languageCode}',
      (tester) async {
        tester.view.physicalSize = const Size(320, 760);
        tester.view.devicePixelRatio = 1;
        addTearDown(() {
          tester.view.resetPhysicalSize();
          tester.view.resetDevicePixelRatio();
        });

        await tester.pumpWidget(
          MaterialApp(
            locale: locale,
            home: CustomerFlashOffersScreen(
              storeId: 7,
              api: const _EmptyFlashApi(),
              isAuthenticated: true,
              onAuthenticationRequired: _noop,
              onOpenNormalOffers: _noop,
            ),
          ),
        );
        await tester.pumpAndSettle();

        expect(find.byKey(const ValueKey('foodex-wholesale-shared-header')),
            findsOneWidget);
        expect(find.byType(CustomerAccountHeader), findsNothing);
        expect(find.byType(AppBar), findsNothing);
        expect(tester.takeException(), isNull);

        await tester.pumpWidget(
          MaterialApp(
            locale: locale,
            home: CustomerFlashCheckoutScreen(
              storeId: 7,
              reservation: _flashReservation,
              api: const _EmptyFlashApi(),
            ),
          ),
        );
        await tester.pumpAndSettle();

        expect(find.byKey(const ValueKey('foodex-wholesale-shared-header')),
            findsOneWidget);
        expect(find.byType(CustomerAccountHeader), findsNothing);
        expect(find.byType(AppBar), findsNothing);
        expect(tester.takeException(), isNull);
      },
    );
  }
}

void _noop() {}

final _flashReservation = CustomerFlashReservation(
  id: 5,
  offerId: 9,
  expiresAt: DateTime.utc(2099, 1, 1, 12),
  serverTime: DateTime.utc(2099, 1, 1, 11),
  status: 'ACTIVE',
);

class _EmptyFlashApi implements CustomerFlashOffersApi {
  const _EmptyFlashApi();

  @override
  Future<List<CustomerFlashOffer>> activeOffers({required int storeId}) async =>
      const <CustomerFlashOffer>[];

  @override
  Future<CustomerFlashReservation?> activeReservation({
    required int storeId,
  }) async =>
      null;

  @override
  dynamic noSuchMethod(Invocation invocation) =>
      throw UnimplementedError(invocation.memberName.toString());
}

class _AccountB2bApi implements B2bApi {
  const _AccountB2bApi();

  @override
  Future<Object?> get(String path) async {
    if (path.startsWith('/api/v1/b2b/account-summary')) {
      return const {
        'data': {
          'balance': 12.5,
          'balance_direction': 'customer_owes_company',
          'credit_limit': 500,
          'available_credit_line': 487.5,
          'purchasing_power': 487.5,
          'currency': 'KWD',
        },
      };
    }

    if (path == '/api/v1/profile') {
      return const {
        'name': 'Buyer',
        'locale': 'en',
        'customer': {
          'id': 9,
          'name': 'Buyer',
          'email': 'buyer@example.test',
          'type': 'b2b',
        },
        'business_account': {
          'company_name': 'Buyer Co',
          'status': 'active',
          'tax_number': 'TEST-001',
        },
        'addresses': [],
        'favorites': [],
        'roles': ['customer'],
      };
    }

    return const {'data': []};
  }
}

class _NoopCustomerActionApi implements CustomerActionApi {
  const _NoopCustomerActionApi();

  @override
  dynamic noSuchMethod(Invocation invocation) =>
      throw UnimplementedError(invocation.memberName.toString());
}
