import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/theme/foodex_theme.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  test('FOODEX customer theme uses canonical brand tokens', () {
    final theme = FoodexTheme.light();

    expect(theme.colorScheme.primary, FoodexBrand.green);
    expect(theme.colorScheme.secondary, FoodexBrand.orange);
    expect(theme.scaffoldBackgroundColor, FoodexBrand.background);
    expect(theme.navigationBarTheme.indicatorColor, FoodexBrand.greenSoft);
    expect(theme.textTheme.bodyMedium?.fontFamily, startsWith('Tajawal'));
    expect(theme.textTheme.titleLarge?.fontFamily, startsWith('Tajawal'));
    expect(FoodexBrand.statusColor('delivered'), FoodexBrand.green);
    expect(FoodexBrand.statusColor('out_for_delivery'), FoodexBrand.blue);
    expect(FoodexBrand.statusColor('processing'), FoodexBrand.orange);
    expect(FoodexBrand.statusColor('cancelled'), FoodexBrand.red);
  });
}
