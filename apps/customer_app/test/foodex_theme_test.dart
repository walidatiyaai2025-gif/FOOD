import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/theme/customer_ui_v3_tokens.dart';
import 'package:foodex_customer_app/core/theme/foodex_theme.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  test('FOODEX customer theme uses Customer UI V3 canonical tokens', () {
    final theme = FoodexTheme.light();

    expect(theme.colorScheme.primary, CustomerUiColors.deepGreen);
    expect(theme.colorScheme.secondary, CustomerUiColors.lime);
    expect(theme.scaffoldBackgroundColor, CustomerUiColors.mint);
    expect(
      theme.navigationBarTheme.indicatorColor,
      CustomerUiColors.limeSoft,
    );
    expect(
      theme.appBarTheme.backgroundColor,
      CustomerUiColors.deepGreen,
    );
    expect(theme.textTheme.bodyMedium?.fontFamily, startsWith('Alexandria'));
    expect(theme.textTheme.titleLarge?.fontFamily, startsWith('Alexandria'));
    expect(FoodexBrand.statusColor('delivered'), CustomerUiColors.success);
    expect(FoodexBrand.statusColor('out_for_delivery'), CustomerUiColors.info);
    expect(FoodexBrand.statusColor('processing'), CustomerUiColors.warning);
    expect(FoodexBrand.statusColor('cancelled'), CustomerUiColors.destructive);
  });
}
