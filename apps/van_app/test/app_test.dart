import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_van_app/app.dart';
import 'package:foodex_van_app/core/auth/van_session.dart';
import 'package:foodex_van_app/features/foundation/van_screen_inventory.dart';
import 'package:foodex_van_app/features/wallet/van_wallet_contract.dart';

void main() {
  test('approved Van production inventory stays locked to 19 surfaces', () {
    expect(vanProductionScreenInventory.length, 19);
    expect(vanProductionScreenInventory.first, VanScreenId.login);
    expect(vanProductionScreenInventory.last, VanScreenId.profile);
    expect(
      vanProductionScreenInventory.toSet().length,
      vanProductionScreenInventory.length,
    );
  });

  testWidgets('authenticated Van shell exposes production navigation instead of legacy tabs', (tester) async {
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
    expect(find.text('Home Dashboard'), findsWidgets);
    expect(find.byType(NavigationBar), findsOneWidget);
    expect(find.byType(TabBar), findsNothing);

    final scaffold = tester.widget<Scaffold>(find.byType(Scaffold).last);
    scaffold.openDrawer();
    await tester.pumpAndSettle();

    expect(
      find.byKey(const ValueKey('van-production-screen-menu')),
      findsOneWidget,
    );
    expect(find.text('Routes'), findsWidgets);
    expect(find.text('Customers'), findsWidgets);
    expect(find.text('Product Catalog'), findsOneWidget);
    expect(find.text('Order Builder'), findsOneWidget);
    expect(find.text('Notifications'), findsOneWidget);
    expect(find.text('Profile & Settings'), findsOneWidget);

    await tester.tap(find.text('Customers').first);
    await tester.pumpAndSettle();
    expect(find.text('No assigned customers'), findsOneWidget);
  });

  testWidgets('Van Customer 360 renders authoritative customer financial context',
      (tester) async {
    await tester.pumpWidget(
      const FoodexVanApp(
        locale: Locale('en'),
        walletRepository: _CustomerWalletRepository(),
        initialSession: VanSession(
          token: 'test-token',
          name: 'Van Operator',
          email: 'van@example.test',
          locale: 'en',
          permissions: {'van.login'},
        ),
      ),
    );
    await tester.pumpAndSettle();

    final scaffold = tester.widget<Scaffold>(find.byType(Scaffold).last);
    scaffold.openDrawer();
    await tester.pumpAndSettle();

    final target =
        find.byKey(const ValueKey('van-screen-customer360'));
    await tester.scrollUntilVisible(
      target,
      180,
      scrollable:
          find.byKey(const ValueKey('van-production-screen-menu')),
    );
    await tester.tap(target);
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('van-customer-360')), findsOneWidget);
    expect(find.text('Acme Grocery'), findsWidgets);
    expect(find.text('INV-42'), findsOneWidget);
    expect(find.text('12.500 KWD'), findsOneWidget);
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


class _CustomerWalletRepository implements VanWalletRepository {
  const _CustomerWalletRepository();

  @override
  Future<List<VanWalletAccount>> wallet() async => const [];

  @override
  Future<List<VanCustomerScope>> customers() async => const [
        VanCustomerScope(
          type: 'b2b',
          id: 42,
          name: 'Acme Grocery',
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
            id: 42,
            number: 'INV-42',
            currency: 'KWD',
            total: 20,
            outstandingAmount: 12.5,
          ),
        ],
      );

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
