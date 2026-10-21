import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/theme/customer_display_preferences.dart';
import 'package:foodex_customer_app/core/theme/foodex_theme.dart';

void main() {
  test('wholesale card size cycles 3, 2, 1 and back to 3 columns', () {
    expect(WholesaleProductCardSize.compact.columns, 3);
    expect(WholesaleProductCardSize.compact.next,
        WholesaleProductCardSize.comfortable);
    expect(WholesaleProductCardSize.comfortable.columns, 2);
    expect(WholesaleProductCardSize.comfortable.next,
        WholesaleProductCardSize.expanded);
    expect(WholesaleProductCardSize.expanded.columns, 1);
    expect(
      WholesaleProductCardSize.expanded.next,
      WholesaleProductCardSize.compact,
    );
  });

  test('FOODEX dark theme exposes a real dark Material theme', () {
    final theme = FoodexTheme.dark();

    expect(theme.brightness, Brightness.dark);
    expect(theme.colorScheme.brightness, Brightness.dark);
    expect(theme.scaffoldBackgroundColor.computeLuminance(), lessThan(.1));
    expect(theme.cardColor.computeLuminance(), lessThan(.1));
  });
}
