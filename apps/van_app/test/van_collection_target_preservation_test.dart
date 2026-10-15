import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_van_app/features/wallet/van_collection_page.dart';
import 'package:foodex_van_app/features/wallet/van_wallet_contract.dart';
import 'package:foodex_van_app/shared/van_action_button.dart';

void main() {
  testWidgets('missing deep-linked customer never falls back to another target',
      (tester) async {
    final repository = _TargetRepository();

    await tester.pumpWidget(
      _host(
        repository,
        initialCustomer: const VanCustomerScope(
          type: 'b2b',
          id: 999,
          name: 'Removed Customer',
          storeId: 7,
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(repository.contextCalls, 0);
    expect(
      find.textContaining('intended collection customer'),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('van-collection-target-review')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('van-collection-invoice')),
      findsNothing,
    );

    await tester.tap(
      find.descendant(
        of: find.byKey(const ValueKey('van-collection-customer')),
        matching: find.byType(DropdownButtonFormField<VanCustomerScope>),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Customer A').last);
    await tester.pumpAndSettle();

    expect(repository.contextCalls, 1);
    expect(
      find.textContaining('customer changed', findRichText: true),
      findsOneWidget,
    );
    expect(
      find.textContaining('Invoice: INV-91'),
      findsOneWidget,
    );
    expect(
      tester
          .widget<TextField>(
            find.byKey(const ValueKey('van-collection-amount')),
          )
          .enabled,
      isTrue,
    );
    expect(
      tester
          .widget<VanActionButton>(
            find.byKey(const ValueKey('van-collection-submit')),
          )
          .onPressed,
      isNotNull,
    );
  });

  testWidgets('missing intended invoice requires deliberate invoice selection',
      (tester) async {
    final repository = _TargetRepository();

    await tester.pumpWidget(
      _host(
        repository,
        initialCustomer: _TargetRepository.customerA,
        initialInvoiceId: 999,
      ),
    );
    await tester.pumpAndSettle();

    expect(
      find.textContaining('intended invoice'),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('van-collection-invoice-identity')),
      findsNothing,
    );
    expect(
      tester
          .widget<TextField>(
            find.byKey(const ValueKey('van-collection-amount')),
          )
          .enabled,
      isFalse,
    );

    await _selectInvoice(tester, 'INV-91');
    expect(
      find.byKey(const ValueKey('van-collection-target-review')),
      findsNothing,
    );

    await tester.enterText(
      find.byKey(const ValueKey('van-collection-amount')),
      '10.500',
    );
    await tester.tap(
      find.byKey(const ValueKey('van-collection-submit')),
    );
    await tester.pumpAndSettle();

    expect(repository.collectCalls, 1);
    expect(repository.lastInvoiceId, 91);
    expect(repository.lastAmount, 10.5);
  });

  testWidgets('refresh invalidates a removed invoice and entered amount',
      (tester) async {
    final repository = _TargetRepository();

    await tester.pumpWidget(
      _host(
        repository,
        initialCustomer: _TargetRepository.customerA,
        initialInvoiceId: 91,
      ),
    );
    await tester.pumpAndSettle();

    expect(find.textContaining('Invoice: INV-91'), findsOneWidget);
    await tester.enterText(
      find.byKey(const ValueKey('van-collection-amount')),
      '12.500',
    );

    repository.invoices = const [_TargetRepository.invoice92];
    await _simulateAppResume(tester);

    final amountField = tester.widget<TextField>(
      find.byKey(const ValueKey('van-collection-amount')),
    );
    expect(amountField.controller!.text, isEmpty);
    expect(amountField.enabled, isFalse);
    expect(find.textContaining('no longer collectible'), findsOneWidget);
    expect(
      find.byKey(const ValueKey('van-collection-invoice-identity')),
      findsNothing,
    );
    expect(
      tester
          .widget<VanActionButton>(
            find.byKey(const ValueKey('van-collection-submit')),
          )
          .onPressed,
      isNull,
    );

    await _selectInvoice(tester, 'INV-92');
    await tester.enterText(
      find.byKey(const ValueKey('van-collection-amount')),
      '5',
    );
    await tester.tap(
      find.byKey(const ValueKey('van-collection-submit')),
    );
    await tester.pumpAndSettle();

    expect(repository.collectCalls, 1);
    expect(repository.lastInvoiceId, 92);
    expect(repository.lastAmount, 5);
  });

  testWidgets('target-unavailable review is equivalent in Arabic',
      (tester) async {
    final repository = _TargetRepository();

    await tester.pumpWidget(
      _host(
        repository,
        locale: const Locale('ar'),
        initialCustomer: _TargetRepository.customerA,
        initialInvoiceId: 999,
      ),
    );
    await tester.pumpAndSettle();

    expect(find.textContaining('الفاتورة المقصودة'), findsOneWidget);
    expect(
      find.byKey(const ValueKey('van-collection-target-review')),
      findsOneWidget,
    );
  });
}

Widget _host(
  _TargetRepository repository, {
  Locale locale = const Locale('en'),
  VanCustomerScope? initialCustomer,
  int? initialInvoiceId,
}) {
  return MaterialApp(
    locale: locale,
    localizationsDelegates: GlobalMaterialLocalizations.delegates,
    supportedLocales: const [Locale('en'), Locale('ar')],
    home: Scaffold(
      body: VanCollectionPage(
        repository: repository,
        onSessionExpired: () async {},
        initialCustomer: initialCustomer,
        initialInvoiceId: initialInvoiceId,
      ),
    ),
  );
}

Future<void> _simulateAppResume(WidgetTester tester) async {
  final lifecycleState = tester.binding.lifecycleState;
  if (lifecycleState == AppLifecycleState.paused) {
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.hidden);
    await tester.pump();
  } else if (lifecycleState == AppLifecycleState.detached) {
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pump();
  }
  if (tester.binding.lifecycleState != AppLifecycleState.inactive) {
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    await tester.pump();
  }
  tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
  await tester.pumpAndSettle();
}

Future<void> _selectInvoice(
  WidgetTester tester,
  String invoiceNumber,
) async {
  await tester.tap(
    find.descendant(
      of: find.byKey(const ValueKey('van-collection-invoice')),
      matching: find.byType(DropdownButtonFormField<VanInvoiceBalance>),
    ),
  );
  await tester.pumpAndSettle();
  await tester.tap(find.textContaining(invoiceNumber).last);
  await tester.pumpAndSettle();
}

class _TargetRepository implements VanWalletRepository {
  static const customerA = VanCustomerScope(
    type: 'b2b',
    id: 44,
    name: 'Customer A',
    storeId: 7,
  );

  static const customerB = VanCustomerScope(
    type: 'b2b',
    id: 45,
    name: 'Customer B',
    storeId: 7,
  );

  static const invoice91 = VanInvoiceBalance(
    id: 91,
    number: 'INV-91',
    currency: 'EGP',
    total: 100,
    outstandingAmount: 25,
  );

  static const invoice92 = VanInvoiceBalance(
    id: 92,
    number: 'INV-92',
    currency: 'EGP',
    total: 80,
    outstandingAmount: 20,
  );

  List<VanInvoiceBalance> invoices = const [invoice91, invoice92];
  int contextCalls = 0;
  int collectCalls = 0;
  int? lastInvoiceId;
  double? lastAmount;

  @override
  Future<List<VanCustomerScope>> customers() async => const [
        customerA,
        customerB,
      ];

  @override
  Future<VanCollectionContext> collectionContext(
    VanCustomerScope customer,
  ) async {
    contextCalls += 1;
    return VanCollectionContext(
      storeId: customer.storeId,
      invoices: invoices,
    );
  }

  @override
  Future<VanCollectionResult> collect({
    required VanCustomerScope customer,
    required int invoiceId,
    required double amount,
    required String idempotencyKey,
  }) async {
    collectCalls += 1;
    lastInvoiceId = invoiceId;
    lastAmount = amount;
    return const VanCollectionResult(
      receipt: VanReceipt(
        id: 501,
        amount: 5,
        currency: 'EGP',
        status: 'posted',
        createdAt: '2026-10-15T16:00:00Z',
      ),
      remainingOutstanding: 15,
      wallet: VanWalletAccount(
        id: 12,
        storeId: 7,
        currency: 'EGP',
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
        id: 12,
        storeId: 7,
        currency: 'EGP',
        status: 'active',
        custodyBalance: 0,
        availableToRemit: 0,
        receipts: [],
        remittances: [],
      );
}
