import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_driver_app/features/wallet/driver_wallet_contract.dart';
import 'package:foodex_driver_app/features/wallet/driver_wallet_page.dart';

class _FakeWallet implements DriverWalletRepository {
  int walletCalls = 0;
  bool failWallet = false;

  @override
  Future<List<DriverWalletAccount>> wallet() async {
    walletCalls += 1;
    if (failWallet) {
      throw StateError('offline');
    }
    return const [
      DriverWalletAccount(
        id: 5,
        currency: 'KWD',
        status: 'active',
        custodyBalance: 10,
        availableToRemit: 4,
      ),
    ];
  }

  @override
  Future<DriverCollectionResult> collect(
    int assignmentId, {
    required double amount,
    required String idempotencyKey,
  }) {
    throw UnimplementedError();
  }

  @override
  Future<DriverRemittance> submitRemittance({
    required int collectionAccountId,
    required double amount,
    required String method,
    String? reference,
    String? note,
    required String idempotencyKey,
  }) {
    throw UnimplementedError();
  }
}

class _CollectedWallet extends _FakeWallet {
  @override
  Future<List<DriverWalletAccount>> wallet() async => const [
        DriverWalletAccount(
          id: 6,
          currency: 'KWD',
          status: 'active',
          custodyBalance: 0,
          availableToRemit: 0,
          totalCollected: 15,
          collectedOrders: [
            DriverCollectedOrder(
              orderId: 123,
              orderNumber: 'FO-123',
              currency: 'KWD',
              orderTotal: 30,
              collectedAmount: 15,
              remainingAmount: 15,
              paymentState: 'partially_settled',
            ),
          ],
        ),
      ];
}

void main() {
  testWidgets(
      'wallet polls in foreground and preserves stale confirmed balances',
      (tester) async {
    final repository = _FakeWallet();

    await tester.pumpWidget(
      MaterialApp(
        locale: const Locale('en'),
        home: DriverWalletPage(repository: repository),
      ),
    );
    await tester.pumpAndSettle();

    expect(repository.walletCalls, 1);
    expect(find.text('10.000 KWD'), findsOneWidget);
    expect(find.byKey(const Key('driver-wallet-stale')), findsNothing);

    repository.failWallet = true;
    await tester.pump(const Duration(seconds: 15));
    await tester.pumpAndSettle();

    expect(repository.walletCalls, 2);
    expect(find.text('10.000 KWD'), findsOneWidget);
    expect(find.byKey(const Key('driver-wallet-stale')), findsOneWidget);
    expect(find.textContaining('last confirmed data'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('wallet refreshes when Driver app resumes', (tester) async {
    final repository = _FakeWallet();

    await tester.pumpWidget(
      MaterialApp(
        locale: const Locale('en'),
        home: DriverWalletPage(repository: repository),
      ),
    );
    await tester.pumpAndSettle();

    expect(repository.walletCalls, 1);
    expect(find.text('10.000 KWD'), findsOneWidget);

    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();

    expect(repository.walletCalls, 2);
    expect(find.text('10.000 KWD'), findsOneWidget);
  });
  testWidgets('collected card opens scoped order amounts and remaining balance',
      (tester) async {
    await tester.pumpWidget(MaterialApp(
      locale: const Locale('en'),
      home: DriverWalletPage(repository: _CollectedWallet()),
    ));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('driver-collected-summary-6')), findsOneWidget);
    expect(find.text('15.000 KWD'), findsOneWidget);
    await tester.tap(find.byKey(const Key('driver-collected-summary-6')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('driver-collected-order-123')), findsOneWidget);
    expect(find.text('FO-123'), findsOneWidget);
    expect(find.textContaining('Partially paid'), findsOneWidget);
    expect(find.textContaining('Remaining'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

}
