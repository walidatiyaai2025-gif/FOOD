import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/features/storefront/storefront_design_system.dart' as storefront;
import 'package:foodex_formatting/foodex_formatting.dart';

void main() {
  test('EGP display always uses exactly two decimals', () {
    expect(foodexMoneyAmount(100, 'EGP'), '100.00');
    expect(foodexMoneyAmount(100.5, 'EGP'), '100.50');
    expect(foodexMoneyAmount(100.50, 'egp'), '100.50');
    expect(foodexMoneyAmount(100.567, ' EGP '), '100.57');
  });

  test('other currencies keep fixed precision', () {
    expect(foodexMoneyAmount(100, 'KWD'), '100.000');
    expect(foodexMoneyAmount(100.5, 'USD'), '100.500');
  });

  test('storefront helper preserves its existing two-decimal non-EGP contract', () {
    expect(storefront.money(42.5, currency: 'KWD'), '42.50 KWD');
    expect(storefront.money(42.5, currency: 'EGP'), '42.50 EGP');
  });

  test('unit counts compact without losing fractional values', () {
    expect(foodexQuantity(12), '12');
    expect(foodexQuantity(12.5), '12.5');
    expect(foodexQuantity(12.125), '12.125');
  });

  test('measurement fields can preserve fixed precision', () {
    expect(foodexQuantity(12.5, preserveFixedPrecision: true), '12.500');
  });
}
