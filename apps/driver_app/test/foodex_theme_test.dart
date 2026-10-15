import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_driver_app/core/theme/foodex_theme.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  test('FOODEX driver theme uses canonical brand tokens', () {
    final theme = FoodexTheme.light();

    expect(FoodexBrand.green, const Color(0xFF158A3A));
    expect(FoodexBrand.actionGreen, const Color(0xFF137A35));
    expect(FoodexBrand.dangerText, const Color(0xFFB42318));
    expect(theme.colorScheme.primary, FoodexBrand.actionGreen);
    expect(theme.colorScheme.onPrimary, Colors.white);
    expect(theme.colorScheme.error, FoodexBrand.dangerText);
    expect(theme.colorScheme.onError, Colors.white);
    expect(theme.colorScheme.secondary, FoodexBrand.orange);
    expect(theme.scaffoldBackgroundColor, FoodexBrand.background);
    expect(theme.navigationBarTheme.indicatorColor, FoodexBrand.greenSoft);
    expect(theme.textTheme.bodyMedium?.fontFamily, startsWith('Alexandria'));
    expect(theme.textTheme.titleLarge?.fontFamily, startsWith('Alexandria'));
    expect(FoodexBrand.statusColor('delivered'), FoodexBrand.green);
    expect(FoodexBrand.statusColor('out_for_delivery'), FoodexBrand.blue);
    expect(FoodexBrand.statusColor('processing'), FoodexBrand.orange);
    expect(FoodexBrand.statusColor('cancelled'), FoodexBrand.dangerText);
  });
}
