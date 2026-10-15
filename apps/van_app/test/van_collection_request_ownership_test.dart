import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_van_app/features/wallet/van_collection_page.dart';
import 'package:foodex_van_app/features/wallet/van_wallet_contract.dart';
import 'package:foodex_van_app/shared/van_action_button.dart';

void main() {
  testWidgets(
    'late customer context cannot replace a newer Van collection selection',
    (tester) async {
      final repository = _DeferredWalletRepository();

      await _pumpCollection(tester, repository);
      expect(repository.requests.map((request) => request.customer.id), [1]);

      await _selectCustomer(tester, 'Customer B');
      expect(repository.requests.map((request) => request.customer.id), [1, 2]);

      repository.requests[1].complete(_context('INV-B', 202));
      await tester.pump();
      await tester.pump();

      expect(find.textContaining('INV-B'), findsWidgets);
      expect(find.textContaining('INV-A'), findsNothing);
      expect(
        tester
            .widget<VanActionButton>(
              find.byKey(const ValueKey('van-collection-submit')),
            )
            .onPressed,
        isNotNull,
      );

      repository.requests[0].complete(_context('INV-A', 101));
      await tester.pump();
      await tester.pump();

      expect(find.textContaining('INV-B'), findsWidgets);
      expect(find.textContaining('INV-A'), findsNothing);

      await tester.enterText(
        find.byKey(const ValueKey('van-collection-amount')),
        '5',
      );
      await tester.tap(find.byKey(const ValueKey('van-collection-submit')));
      await tester.pump();
      await tester.pump();

      expect(repository.collectedCustomerIds, [2]);
      expect(repository.idempotencyKeys.single, startsWith('van-'));

      expect(
          repository.requests.map((request) => request.customer.id), [1, 2, 2]);
      repository.requests[2].complete(_context('INV-B', 202));
      await tester.pump();
      await tester.pump();
    },
  );

  testWidgets(
    'background refresh cannot overwrite a newer explicit customer scope',
    (tester) async {
      final repository = _DeferredWalletRepository();

      await _pumpCollection(tester, repository);
      repository.requests[0].complete(_context('INV-A', 101));
      await tester.pump();
      await tester.pump();

      expect(find.textContaining('INV-A'), findsWidgets);

      final lifecycleState = tester.binding.lifecycleState;
      if (lifecycleState == AppLifecycleState.paused) {
        tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.hidden);
        await tester.pump();
      } else if (lifecycleState == AppLifecycleState.detached) {
        tester.binding
            .handleAppLifecycleStateChanged(AppLifecycleState.resumed);
        await tester.pump();
      }
      if (tester.binding.lifecycleState != AppLifecycleState.inactive) {
        tester.binding
            .handleAppLifecycleStateChanged(AppLifecycleState.inactive);
        await tester.pump();
      }
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pump();

      expect(repository.requests.map((request) => request.customer.id), [1, 1]);

      await _selectCustomer(tester, 'Customer B');
      expect(
          repository.requests.map((request) => request.customer.id), [1, 1, 2]);

      repository.requests[2].complete(_context('INV-B', 202));
      await tester.pump();
      await tester.pump();

      repository.requests[1].completeError(StateError('stale A refresh'));
      await tester.pump();
      await tester.pump();

      expect(find.textContaining('INV-B'), findsWidgets);
      expect(find.textContaining('INV-A'), findsNothing);
      expect(find.text('Showing last confirmed collection context.'),
          findsNothing);
      expect(find.text('Unable to load outstanding invoices'), findsNothing);
    },
  );
}

Future<void> _pumpCollection(
  WidgetTester tester,
  _DeferredWalletRepository repository,
) async {
  await tester.pumpWidget(
    MaterialApp(
      locale: const Locale('en'),
      home: Scaffold(
        body: VanCollectionPage(
          repository: repository,
          onSessionExpired: () async {},
        ),
      ),
    ),
  );
  await tester.pump();
  await tester.pump();
}

Future<void> _selectCustomer(WidgetTester tester, String name) async {
  await tester.tap(find.byKey(const ValueKey('van-collection-customer')));
  await tester.pump();
  await tester.pump(const Duration(milliseconds: 300));
  await tester.tap(find.text(name));
  await tester.pump();
  await tester.pump(const Duration(milliseconds: 300));
}

VanCollectionContext _context(String number, int invoiceId) =>
    VanCollectionContext(
      storeId: 7,
      invoices: [
        VanInvoiceBalance(
          id: invoiceId,
          number: number,
          currency: 'KWD',
          total: 20,
          outstandingAmount: 20,
        ),
      ],
    );

class _ContextRequest {
  _ContextRequest(this.customer);

  final VanCustomerScope customer;
  final Completer<VanCollectionContext> _completer =
      Completer<VanCollectionContext>();

  Future<VanCollectionContext> get future => _completer.future;

  void complete(VanCollectionContext value) => _completer.complete(value);

  void completeError(Object error) => _completer.completeError(error);
}

class _DeferredWalletRepository implements VanWalletRepository {
  static const customerA = VanCustomerScope(
    type: 'b2b',
    id: 1,
    name: 'Customer A',
    storeId: 7,
  );
  static const customerB = VanCustomerScope(
    type: 'b2b',
    id: 2,
    name: 'Customer B',
    storeId: 7,
  );

  final List<_ContextRequest> requests = [];
  final List<int> collectedCustomerIds = [];
  final List<String> idempotencyKeys = [];

  @override
  Future<List<VanCustomerScope>> customers() async => const [
        customerA,
        customerB,
      ];

  @override
  Future<VanCollectionContext> collectionContext(
    VanCustomerScope customer,
  ) {
    final request = _ContextRequest(customer);
    requests.add(request);
    return request.future;
  }

  @override
  Future<VanCollectionResult> collect({
    required VanCustomerScope customer,
    required int invoiceId,
    required double amount,
    required String idempotencyKey,
  }) async {
    collectedCustomerIds.add(customer.id);
    idempotencyKeys.add(idempotencyKey);
    return VanCollectionResult(
      receipt: VanReceipt(
        id: 500 + customer.id,
        amount: amount,
        currency: 'KWD',
        status: 'posted',
        createdAt: '2026-10-15T14:00:00Z',
      ),
      remainingOutstanding: 15,
      wallet: const VanWalletAccount(
        id: 10,
        storeId: 7,
        currency: 'KWD',
        status: 'active',
        custodyBalance: 5,
        availableToRemit: 5,
        receipts: [],
        remittances: [],
      ),
    );
  }

  @override
  Future<List<VanWalletAccount>> wallet() async => const [];

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
        id: 10,
        storeId: 7,
        currency: 'KWD',
        status: 'active',
        custodyBalance: 0,
        availableToRemit: 0,
        receipts: [],
        remittances: [],
      );
}
