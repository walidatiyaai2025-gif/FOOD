import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_van_app/core/theme/foodex_van_theme.dart';

void main() {
  test('FOODEX Van theme uses contrast-safe shared action colors', () {
    final theme = FoodexVanTheme.light();

    expect(FoodexVanTokens.green, const Color(0xFF148638));
    expect(FoodexVanTokens.danger, const Color(0xFFB42318));
    expect(theme.colorScheme.primary, FoodexVanTokens.green);
    expect(theme.colorScheme.onPrimary, Colors.white);
    expect(theme.colorScheme.error, FoodexVanTokens.danger);
  });
}
