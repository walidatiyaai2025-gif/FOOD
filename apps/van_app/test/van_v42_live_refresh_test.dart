import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_van_app/core/auth/van_session.dart';
import 'package:foodex_van_app/features/commercial/van_commercial_contract.dart';
import 'package:foodex_van_app/features/commercial/van_offers_page.dart';
import 'package:foodex_van_app/features/wallet/van_wallet_contract.dart';
import 'package:foodex_van_app/features/wallet/van_wallet_page.dart';

void main() {
  testWidgets('Van offers refresh on resume and preserve stale server data', (
    tester,
  ) async {
    final commercial = _CommercialRepository();

    await tester.pumpWidget(
      MaterialApp(
        locale: const Locale('en'),
        home: Scaffold(
          body: VanOffersPage(
            session: _session,
            commercialRepository: commercial,
            customerRepository: _WalletRepository(),
            onSessionExpired: () async {},
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(commercial.calls, 1);
    expect(find.text('Flash Offer'), findsOneWidget);

    commercial.failNext = true;
    tester.binding.handleAppLifecycleStateChanged(
      AppLifecycleState.paused,
    );
    tester.binding.handleAppLifecycleStateChanged(
      AppLifecycleState.resumed,
    );
    await tester.pumpAndSettle();

    expect(commercial.calls, 2);
    expect(find.byKey(const Key('van-offers-stale-banner')), findsOneWidget);
    expect(find.text('Flash Offer'), findsOneWidget);
  });

  testWidgets('Van wallet refresh on resume and preserves last balances', (
    tester,
  ) async {
    final wallet = _WalletRepository();

    await tester.pumpWidget(
      MaterialApp(
        locale: const Locale('en'),
        home: Scaffold(
          body: VanWalletPage(
            repository: wallet,
            onSessionExpired: () async {},
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(wallet.walletCalls, 1);
    expect(find.text('10.000 EGP'), findsNWidgets(2));

    wallet.failNext = true;
    tester.binding.handleAppLifecycleStateChanged(
      AppLifecycleState.paused,
    );
    tester.binding.handleAppLifecycleStateChanged(
      AppLifecycleState.resumed,
    );
    await tester.pumpAndSettle();

    expect(wallet.walletCalls, 2);
    expect(find.byKey(const Key('van-wallet-stale-banner')), findsOneWidget);
    expect(find.text('10.000 EGP'), findsNWidgets(2));
  });
}

const _session = VanSession(
  token: 'token',
  name: 'Van Operator',
  email: 'van@example.test',
  locale: 'en',
  permissions: {'van.login'},
);

class _CommercialRepository implements VanCommercialRepository {
  int calls = 0;
  bool failNext = false;

  @override
  Future<VanCommercialOfferFeed> offersFor(VanCustomerScope customer) async {
    calls += 1;
    if (failNext) {
      failNext = false;
      throw const VanOfflineException();
    }
    return VanCommercialOfferFeed(
      serverTime: DateTime.utc(2026, 10, 6, 20),
      normalOffers: const [],
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
          products: [],
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
  }) {
    throw UnimplementedError();
  }

  @override
  Future<void> reserveFlashForCustomer({
    required VanCustomerScope customer,
    required int offerProductId,
    required double quantity,
    required String idempotencyKey,
    String? overrideReason,
  }) {
    throw UnimplementedError();
  }
}

class _WalletRepository implements VanWalletRepository {
  int walletCalls = 0;
  bool failNext = false;

  @override
  Future<List<VanWalletAccount>> wallet() async {
    walletCalls += 1;
    if (failNext) {
      failNext = false;
      throw const VanOfflineException();
    }
    return const [
      VanWalletAccount(
        id: 12,
        storeId: 7,
        currency: 'EGP',
        status: 'active',
        custodyBalance: 10,
        availableToRemit: 10,
        receipts: [],
        remittances: [],
      ),
    ];
  }

  @override
  Future<List<VanCustomerScope>> customers() async => const [
        VanCustomerScope(
          type: 'b2b',
          id: 44,
          name: 'Scoped Customer',
          storeId: 7,
        ),
      ];

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
