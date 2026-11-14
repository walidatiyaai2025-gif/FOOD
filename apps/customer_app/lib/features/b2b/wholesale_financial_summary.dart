/// Display-only financial contract for the authenticated Wholesale customer.
/// The server owns balance, credit and purchasing power; malformed or
/// contradictory data is never replaced with a synthetic zero.
enum WholesaleBalanceDirection {
  customerOwesCompany,
  companyOwesCustomer,
  settled,
}

class WholesaleFinancialSummary {
  const WholesaleFinancialSummary._({
    required this.balance,
    required this.direction,
    required this.currency,
    required this.creditLimit,
    required this.availableCreditLine,
    required this.purchasingPower,
  });

  final double balance;
  final WholesaleBalanceDirection direction;
  final String currency;
  final double creditLimit;
  final double availableCreditLine;
  final double purchasingPower;

  static WholesaleFinancialSummary? tryParse(Map<Object?, Object?> data) {
    final balance = _amount(data['balance']);
    final creditLimit = _amount(data['credit_limit']);
    final availableCreditLine = _amount(data['available_credit_line']);
    final purchasingPower = _amount(data['purchasing_power']);
    final rawCurrency = data['currency'];
    final currency = rawCurrency is String ? rawCurrency.trim() : '';

    if (balance == null ||
        creditLimit == null ||
        availableCreditLine == null ||
        purchasingPower == null ||
        // Reject forged, overlong or bidi-controlled currency labels.
        !RegExp(r'^[A-Z]{3}$').hasMatch(currency) ||
        creditLimit < 0 ||
        availableCreditLine < 0 ||
        purchasingPower < 0) {
      return null;
    }

    final direction = switch (data['balance_direction']) {
      'customer_owes_company' => WholesaleBalanceDirection.customerOwesCompany,
      'company_owes_customer' => WholesaleBalanceDirection.companyOwesCustomer,
      'settled' => WholesaleBalanceDirection.settled,
      _ => null,
    };

    final expectedDirection = balance < 0
        ? WholesaleBalanceDirection.customerOwesCompany
        : balance > 0
            ? WholesaleBalanceDirection.companyOwesCustomer
            : WholesaleBalanceDirection.settled;

    if (direction != expectedDirection) {
      return null;
    }

    // Matches B2bAccountLedgerService::summary. Tolerance allows the three
    // decimal monetary rounding performed by the server, not policy drift.
    final expectedLine = (creditLimit + (balance < 0 ? balance : 0.0))
        .clamp(0.0, double.infinity);
    final expectedPower = availableCreditLine + (balance > 0 ? balance : 0.0);
    if ((expectedLine - availableCreditLine).abs() > 0.0015 ||
        (expectedPower - purchasingPower).abs() > 0.0015) {
      return null;
    }

    return WholesaleFinancialSummary._(
      balance: balance,
      direction: direction!,
      currency: currency,
      creditLimit: creditLimit,
      availableCreditLine: availableCreditLine,
      purchasingPower: purchasingPower,
    );
  }

  /// Parses a required, server-owned monetary total without inventing zero.
  /// The balance and purchasing-power contract remains authoritative server-side.
  static double? nonNegativeAmount(Object? value) {
    final amount = _amount(value);
    return amount != null && amount >= 0 ? amount : null;
  }

  static double? _amount(Object? value) {
    if (value is bool) return null;
    final parsed = value is num
        ? value.toDouble()
        : value is String
            ? double.tryParse(value.trim())
            : null;
    return parsed != null && parsed.isFinite ? parsed : null;
  }
}
