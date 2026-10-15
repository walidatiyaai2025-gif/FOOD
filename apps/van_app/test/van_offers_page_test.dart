import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
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
    expect(find.textContaining('Sparkling Water'), findsOneWidget);
    expect(find.textContaining('Carton'), findsOneWidget);
    expect(find.textContaining('Product #99'), findsNothing);
    expect(find.textContaining('CARTON'), findsNothing);
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
      find.text('Online validation required.'),
      findsOneWidget,
    );
    expect(find.byType(Dialog), findsNothing);
  });

  testWidgets('Van offers localize blocked commercial reasons in English',
      (tester) async {
    await tester.pumpWidget(
      MaterialApp(
        locale: const Locale('en'),
        localizationsDelegates: GlobalMaterialLocalizations.delegates,
        supportedLocales: const [Locale('en'), Locale('ar')],
        home: Scaffold(
          body: VanOffersPage(
            session: const VanSession(
              token: 'token',
              name: 'Van Operator',
              email: 'van@example.test',
              locale: 'en',
              permissions: {'van.login'},
            ),
            commercialRepository: const _BlockedCommercialRepository([
              'PRODUCT_CLOSED',
              'MAX_PER_DAY_EXCEEDED',
              'FLASH_SOLD_OUT',
              'FUTURE_INTERNAL_CODE',
            ]),
            customerRepository: const _CustomerRepository(),
            onSessionExpired: _noop,
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('van-flash-buy-50')));
    await tester.pump();

    expect(
      find.byKey(const Key('van-commercial-policy-blocked')),
      findsOneWidget,
    );
    expect(find.textContaining('Product is closed for sale'), findsOneWidget);
    expect(
        find.textContaining('Daily quantity limit exceeded'), findsOneWidget);
    expect(find.textContaining('Flash offer is sold out'), findsOneWidget);
    expect(
        find.textContaining('Commercial policy restriction'), findsOneWidget);
    expect(find.textContaining('PRODUCT_CLOSED'), findsNothing);
    expect(find.textContaining('FUTURE_INTERNAL_CODE'), findsNothing);
  });

  testWidgets('Van offers localize blocked commercial reasons in Arabic',
      (tester) async {
    await tester.pumpWidget(
      MaterialApp(
        locale: const Locale('ar'),
        localizationsDelegates: GlobalMaterialLocalizations.delegates,
        supportedLocales: const [Locale('en'), Locale('ar')],
        home: Scaffold(
          body: VanOffersPage(
            session: const VanSession(
              token: 'token',
              name: 'مشغل الفان',
              email: 'van@example.test',
              locale: 'ar',
              permissions: {'van.login'},
            ),
            commercialRepository: const _BlockedCommercialRepository([
              'CHANNEL_BLOCKED',
              'FLASH_RESERVATION_EXPIRED',
              'OVERRIDE_REQUIRED',
            ]),
            customerRepository: const _CustomerRepository(),
            onSessionExpired: _noop,
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('van-flash-buy-50')));
    await tester.pump();

    expect(find.textContaining('قناة البيع غير متاحة'), findsOneWidget);
    expect(find.textContaining('انتهت صلاحية حجز Flash'), findsOneWidget);
    expect(
      find.textContaining('يلزم تجاوز بموافقة مشرف أو مدير'),
      findsOneWidget,
    );
    expect(find.textContaining('CHANNEL_BLOCKED'), findsNothing);
    expect(find.textContaining('OVERRIDE_REQUIRED'), findsNothing);
  });
  testWidgets(
      'Van offers use safe labels when legacy display names are missing',
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
            commercialRepository:
                const _CommercialRepository(includeBusinessLabels: false),
            customerRepository: const _CustomerRepository(),
            onSessionExpired: _noop,
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.textContaining('Product ·'), findsOneWidget);
    expect(find.textContaining('· Unit'), findsOneWidget);
    expect(find.textContaining('Product #99'), findsNothing);
    expect(find.textContaining('CARTON'), findsNothing);
  });

}

Future<void> _noop() async {}

class _CommercialRepository implements VanCommercialRepository {
  const _CommercialRepository({this.includeBusinessLabels = true});

  final bool includeBusinessLabels;

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
      offers: [
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
              productName: includeBusinessLabels ? 'Sparkling Water' : null,
              sellingUnitName: includeBusinessLabels ? 'Carton' : null,
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

class _BlockedCommercialRepository extends _CommercialRepository {
  const _BlockedCommercialRepository(this.reasons);

  final List<String> reasons;

  @override
  Future<VanCommercialQuote> quoteForCustomer({
    required VanCustomerScope customer,
    required int productId,
    required String sellingUnitCode,
    required double quantity,
    String? overrideReason,
  }) async {
    return VanCommercialQuote(
      allowed: false,
      status: 'BLOCKED',
      reasonCodes: reasons,
      sellingUnit: const VanSellingUnit(
        code: 'CARTON',
        name: 'Carton',
        conversionFactor: 10,
      ),
      sellingUnits: const [
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
  }) {
    throw StateError('Blocked quotes must not reserve stock.');
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
