import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_van_app/core/auth/van_session.dart';
import 'package:foodex_van_app/features/commercial/van_commercial_contract.dart';
import 'package:foodex_van_app/features/commercial/van_offers_page.dart';
import 'package:foodex_van_app/features/wallet/van_wallet_contract.dart';

void main() {
  testWidgets('Van offers stay in a normal tab surface with no Flash popup',
      (tester) async {
    await tester.pumpWidget(
      MaterialApp(
        locale: const Locale('en'),
        home: Scaffold(
          body: VanOffersPage(
            session: const VanSession(
              token: 'token',
              name: 'Van Operator',
              email: 'van@example.test',
              locale: 'en',
              permissions: {'van.login'},
            ),
            commercialRepository: const _CommercialRepository(),
            customerRepository: const _CustomerRepository(),
            onSessionExpired: _noop,
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('van-offers-page')), findsOneWidget);
    expect(find.byKey(const Key('van-offer-10')), findsOneWidget);
    expect(find.text('Flash Offer'), findsOneWidget);
    expect(find.text('Normal offer'), findsOneWidget);
    expect(find.byKey(const Key('van-commercial-override-50')), findsNothing);
    expect(find.byType(Dialog), findsNothing);
    expect(find.byType(AlertDialog), findsNothing);

    await tester.tap(find.byKey(const Key('van-flash-buy-50')));
    await tester.pump();

    expect(
      find.byKey(const Key('van-flash-online-validation-required')),
      findsOneWidget,
    );
    expect(
      find.text(
        'Online validation for the selected customer is required before a Flash sale.',
      ),
      findsOneWidget,
    );
    expect(find.byType(Dialog), findsNothing);
  });
}

Future<void> _noop() async {}

class _CommercialRepository implements VanCommercialRepository {
  const _CommercialRepository();

  @override
  Future<VanCommercialOfferFeed> offersFor(VanCustomerScope customer) async {
    return VanCommercialOfferFeed(
      serverTime: DateTime.utc(2026, 10, 6, 5, 30),
      normalOffers: const [
        VanNormalOffer(
          id: 1,
          name: 'Normal offer',
          type: 'percentage',
          value: 10,
        ),
      ],
      offers: const [
        VanCommercialOffer(
          id: 10,
          storeId: 7,
          titleAr: 'عرض سريع',
          titleEn: 'Flash Offer',
          status: 'active',
          startsAt: null,
          endsAt: null,
          priority: 1,
          reservationSeconds: 300,
          products: [
            VanCommercialOfferProduct(
              id: 50,
              productId: 99,
              sellingUnitCode: 'CARTON',
              conversionFactor: 10,
              flashPrice: 7,
            ),
          ],
        ),
      ],
    );
  }

  @override
  Future<VanCommercialQuote> quoteForCustomer({
    required VanCustomerScope customer,
    required int productId,
    required String sellingUnitCode,
    required double quantity,
    String? overrideReason,
  }) async {
    return const VanCommercialQuote(
      allowed: true,
      status: 'OPEN',
      reasonCodes: [],
      sellingUnit: VanSellingUnit(
        code: 'CARTON',
        name: 'Carton',
        conversionFactor: 10,
      ),
      sellingUnits: [
        VanSellingUnit(
          code: 'CARTON',
          name: 'Carton',
          conversionFactor: 10,
        ),
      ],
      overrideApplied: false,
    );
  }

  @override
  Future<void> reserveFlashForCustomer({
    required VanCustomerScope customer,
    required int offerProductId,
    required double quantity,
    required String idempotencyKey,
    String? overrideReason,
  }) async {
    throw const VanCommercialContractPendingException();
  }
}

class _CustomerRepository implements VanWalletRepository {
  const _CustomerRepository();

  @override
  Future<List<VanCustomerScope>> customers() async => const [
        VanCustomerScope(
          type: 'b2c',
          id: 44,
          name: 'Scoped Customer',
          storeId: 7,
        ),
      ];

  @override
  Future<List<VanWalletAccount>> wallet() async => const [];

  @override
  Future<VanCollectionContext> collectionContext(
    VanCustomerScope customer,
  ) async =>
      const VanCollectionContext(storeId: 7, invoices: []);

  @override
  Future<VanCollectionResult> collect({
    required VanCustomerScope customer,
    required int invoiceId,
    required double amount,
    required String idempotencyKey,
  }) {
    throw UnimplementedError();
  }

  @override
  Future<VanWalletAccount> remit({
    required int collectionAccountId,
    required double amount,
    required String method,
    required String idempotencyKey,
    String? reference,
    String? note,
  }) {
    throw UnimplementedError();
  }
}
