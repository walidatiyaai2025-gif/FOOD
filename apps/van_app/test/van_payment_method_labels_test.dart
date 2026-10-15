import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_van_app/features/orders/van_payment_method_labels.dart';

void main() {
  group('vanPaymentMethodLabel', () {
    test('matches shared Customer payment terminology in English', () {
      expect(
        vanPaymentMethodLabel('cash_on_delivery', arabic: false),
        'Cash on delivery',
      );
      expect(vanPaymentMethodLabel('cod', arabic: false), 'Cash on delivery');
      expect(vanPaymentMethodLabel('card', arabic: false), 'Card');
      expect(vanPaymentMethodLabel('credit_card', arabic: false), 'Card');
      expect(vanPaymentMethodLabel('knet', arabic: false), 'KNET');
      expect(vanPaymentMethodLabel('wallet', arabic: false), 'Wallet');
      expect(
        vanPaymentMethodLabel('account_credit', arabic: false),
        'Account credit',
      );
      expect(
        vanPaymentMethodLabel('bank_transfer', arabic: false),
        'Bank transfer',
      );
    });

    test('provides equivalent Arabic business labels', () {
      expect(
        vanPaymentMethodLabel('cash_on_delivery', arabic: true),
        'الدفع عند الاستلام',
      );
      expect(vanPaymentMethodLabel('card_online', arabic: true), 'بطاقة');
      expect(vanPaymentMethodLabel('knet', arabic: true), 'KNET');
      expect(vanPaymentMethodLabel('wallet', arabic: true), 'المحفظة');
      expect(
        vanPaymentMethodLabel('account_credit', arabic: true),
        'الرصيد الائتماني',
      );
      expect(
        vanPaymentMethodLabel('bank_transfer', arabic: true),
        'تحويل بنكي',
      );
    });

    test('does not expose unknown backend codes to operators', () {
      const rawCode = 'provider_internal_v2';

      expect(
        vanPaymentMethodLabel(rawCode, arabic: false),
        'Other payment method',
      );
      expect(
        vanPaymentMethodLabel(rawCode, arabic: true),
        'طريقة دفع أخرى',
      );
      expect(
        vanPaymentMethodLabel(rawCode, arabic: false),
        isNot(contains(rawCode)),
      );
    });
  });
}