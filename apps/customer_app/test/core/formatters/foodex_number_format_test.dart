import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_formatting/foodex_formatting.dart';

void main() {
  test('EGP display drops redundant zeroes', () {
    expect(foodexMoneyAmount(100, 'EGP'), '100');
    expect(foodexMoneyAmount(100.5, 'EGP'), '100.5');
    expect(foodexMoneyAmount(100.125, 'EGP'), '100.125');
  });

  test('other currencies keep fixed precision', () {
    expect(foodexMoneyAmount(100, 'KWD'), '100.000');
    expect(foodexMoneyAmount(100.5, 'USD'), '100.500');
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
