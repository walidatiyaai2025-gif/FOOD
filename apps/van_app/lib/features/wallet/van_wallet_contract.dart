class VanCustomerScope {
  const VanCustomerScope({
    required this.type,
    required this.id,
    required this.name,
    required this.storeId,
  });

  final String type;
  final int id;
  final String name;
  final int storeId;
}

class VanInvoiceBalance {
  const VanInvoiceBalance({
    required this.id,
    required this.number,
    required this.currency,
    required this.total,
    required this.outstandingAmount,
  });

  final int id;
  final String number;
  final String currency;
  final double total;
  final double outstandingAmount;
}

class VanCollectionContext {
  const VanCollectionContext({
    required this.storeId,
    required this.invoices,
  });

  final int storeId;
  final List<VanInvoiceBalance> invoices;
}

class VanReceipt {
  const VanReceipt({
    required this.id,
    required this.amount,
    required this.currency,
    required this.status,
    required this.createdAt,
  });

  final int id;
  final double amount;
  final String currency;
  final String status;
  final String createdAt;
}

class VanRemittance {
  const VanRemittance({
    required this.id,
    required this.amount,
    required this.currency,
    required this.method,
    required this.status,
    required this.createdAt,
  });

  final int id;
  final double amount;
  final String currency;
  final String method;
  final String status;
  final String createdAt;
}

class VanWalletAccount {
  const VanWalletAccount({
    required this.id,
    required this.storeId,
    required this.currency,
    required this.status,
    required this.custodyBalance,
    required this.availableToRemit,
    required this.receipts,
    required this.remittances,
  });

  final int id;
  final int? storeId;
  final String currency;
  final String status;
  final double custodyBalance;
  final double availableToRemit;
  final List<VanReceipt> receipts;
  final List<VanRemittance> remittances;
}

class VanCollectionResult {
  const VanCollectionResult({
    required this.receipt,
    required this.remainingOutstanding,
    required this.wallet,
  });

  final VanReceipt receipt;
  final double remainingOutstanding;
  final VanWalletAccount wallet;
}

abstract interface class VanWalletRepository {
  Future<List<VanWalletAccount>> wallet();

  Future<List<VanCustomerScope>> customers();

  Future<VanCollectionContext> collectionContext(VanCustomerScope customer);

  Future<VanCollectionResult> collect({
    required VanCustomerScope customer,
    required int invoiceId,
    required double amount,
    required String idempotencyKey,
  });

  Future<VanWalletAccount> remit({
    required int collectionAccountId,
    required double amount,
    required String method,
    required String idempotencyKey,
    String? reference,
    String? note,
  });
}
