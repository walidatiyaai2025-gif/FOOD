import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_van_app/features/wallet/van_wallet_contract.dart';
import 'package:foodex_van_app/features/wallet/van_wallet_page.dart';

void main() {
  testWidgets('Wallet exposes scoped collection and posts authoritative receipt', (
    tester,
  ) async {
    final repository = _WalletRepository();

    await tester.pumpWidget(
      MaterialApp(
        locale: const Locale('en'),
        home: Scaffold(
          body: VanWalletPage(
            repository: repository,
            onSessionExpired: () async {},
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('van-wallet-page')), findsOneWidget);
    expect(find.text('10.000 EGP'), findsOneWidget);
    expect(find.text('Scoped Customer'), findsOneWidget);

    final collect = find.byKey(
      const Key('van-collect-customer-b2b-44-7'),
    );
    await tester.ensureVisible(collect);
    await tester.tap(collect);
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('van-collection-invoice')), findsOneWidget);
    expect(find.textContaining('INV-91'), findsWidgets);

    await tester.tap(find.byKey(const Key('van-collection-submit')));
    await tester.pumpAndSettle();

    expect(repository.collectionCalls, 1);
    expect(repository.lastIdempotencyKey, startsWith('van-'));
    expect(find.textContaining('Receipt #501 posted'), findsOneWidget);
  });
}

class _WalletRepository implements VanWalletRepository {
  int collectionCalls = 0;
  String? lastIdempotencyKey;

  @override
  Future<List<VanWalletAccount>> wallet() async => const [
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
      const VanCollectionContext(
        storeId: 7,
        invoices: [
          VanInvoiceBalance(
            id: 91,
            number: 'INV-91',
            currency: 'EGP',
            total: 100,
            outstandingAmount: 25,
          ),
        ],
      );

  @override
  Future<VanCollectionResult> collect({
    required VanCustomerScope customer,
    required int invoiceId,
    required double amount,
    required String idempotencyKey,
  }) async {
    collectionCalls += 1;
    lastIdempotencyKey = idempotencyKey;
    return const VanCollectionResult(
      receipt: VanReceipt(
        id: 501,
        amount: 25,
        currency: 'EGP',
        status: 'posted',
        createdAt: '2026-10-06T03:00:00Z',
      ),
      remainingOutstanding: 0,
      wallet: VanWalletAccount(
        id: 12,
        storeId: 7,
        currency: 'EGP',
        status: 'active',
        custodyBalance: 35,
        availableToRemit: 35,
        receipts: [],
        remittances: [],
      ),
    );
  }

  @override
  Future<VanWalletAccount> remit({
    required int collectionAccountId,
    required double amount,
    required String method,
    required String idempotencyKey,
    String? reference,
    String? note,
  }) async =>
      const VanWalletAccount(
        id: 12,
        storeId: 7,
        currency: 'EGP',
        status: 'active',
        custodyBalance: 10,
        availableToRemit: 0,
        receipts: [],
        remittances: [],
      );
}
