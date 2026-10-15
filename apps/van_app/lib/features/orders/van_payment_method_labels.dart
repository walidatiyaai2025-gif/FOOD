String vanPaymentMethodLabel(
  String method, {
  required bool arabic,
}) {
  final normalized = method.trim().toLowerCase();

  return switch (normalized) {
    'cash' ||
    'cod' ||
    'cash_on_delivery' =>
      arabic ? 'الدفع عند الاستلام' : 'Cash on delivery',
    'card' ||
    'card_online' ||
    'credit_card' ||
    'debit_card' =>
      arabic ? 'بطاقة' : 'Card',
    'knet' => 'KNET',
    'wallet' => arabic ? 'المحفظة' : 'Wallet',
    'credit' => arabic ? 'ائتمان' : 'Credit',
    'account_credit' ||
    'credit_account' =>
      arabic ? 'الرصيد الائتماني' : 'Account credit',
    'bank_transfer' => arabic ? 'تحويل بنكي' : 'Bank transfer',
    'apple_pay' => 'Apple Pay',
    'google_pay' => 'Google Pay',
    _ => arabic ? 'طريقة دفع أخرى' : 'Other payment method',
  };
}