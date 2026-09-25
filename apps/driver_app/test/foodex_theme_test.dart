import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_driver_app/core/theme/foodex_theme.dart';

void main() {
  test('FOODEX driver theme uses canonical brand tokens', () {
    final theme = FoodexTheme.light();

    expect(theme.colorScheme.primary, FoodexBrand.green);
    expect(theme.colorScheme.secondary, FoodexBrand.orange);
    expect(theme.scaffoldBackgroundColor, FoodexBrand.background);
  });
}
