import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_driver_app/features/wallet/driver_wallet_contract.dart';
import 'package:foodex_driver_app/features/wallet/driver_wallet_page.dart';

class _FakeWallet implements DriverWalletRepository {
  int walletCalls = 0;

  @override
  Future<List<DriverWalletAccount>> wallet() async {
    walletCalls += 1;
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

void main() {
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

    await tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
    await tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();

    expect(repository.walletCalls, 2);
    expect(find.text('10.000 KWD'), findsOneWidget);
  });
}
