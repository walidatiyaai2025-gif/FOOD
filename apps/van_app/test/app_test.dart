import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_van_app/app.dart';
import 'package:foodex_van_app/core/auth/van_session.dart';
import 'package:foodex_van_app/features/wallet/van_wallet_contract.dart';

void main() {
  testWidgets('authenticated Van shell exposes tabs-first foundation', (tester) async {
    await tester.pumpWidget(
      const FoodexVanApp(
        locale: Locale('en'),
        walletRepository: _EmptyWalletRepository(),
        initialSession: VanSession(
          token: 'test-token',
          name: 'Van Operator',
          email: 'van@example.test',
          locale: 'en',
          permissions: {'van.login'},
        ),
      ),
    );

    expect(find.text('FOODEX Van'), findsOneWidget);
    expect(find.text('Van Operator'), findsOneWidget);
    expect(find.text('Overview'), findsOneWidget);
    expect(find.text('Routes'), findsOneWidget);
    expect(find.text('Visits'), findsOneWidget);
    expect(find.text('Wallet'), findsOneWidget);
  });
}


class _EmptyWalletRepository implements VanWalletRepository {
  const _EmptyWalletRepository();

  @override
  Future<List<VanWalletAccount>> wallet() async => const [];

  @override
  Future<List<VanCustomerScope>> customers() async => const [];

  @override
  Future<VanCollectionContext> collectionContext(
    VanCustomerScope customer,
  ) async =>
      const VanCollectionContext(storeId: 1, invoices: []);

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
