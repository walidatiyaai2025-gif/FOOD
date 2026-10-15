import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_van_app/core/theme/foodex_van_theme.dart';
import 'package:foodex_van_app/shared/van_action_button.dart';

void main() {
  test('Van theme exposes contrast-safe semantic action colors', () {
    final theme = FoodexVanTheme.light();

    expect(FoodexVanTokens.green, const Color(0xFF158A3A));
    expect(FoodexVanTokens.actionGreen, const Color(0xFF137A35));
    expect(FoodexVanTokens.dangerText, const Color(0xFFB42318));
    expect(theme.colorScheme.primary, FoodexVanTokens.actionGreen);
    expect(theme.colorScheme.onPrimary, Colors.white);
    expect(theme.colorScheme.error, FoodexVanTokens.dangerText);
    expect(theme.colorScheme.onError, Colors.white);
  });

  testWidgets('Van primary and secondary shared actions use accessible green', (
    tester,
  ) async {
    await tester.pumpWidget(
      const MaterialApp(
        home: Scaffold(
          body: Column(
            children: [
              VanActionButton(onPressed: null, child: Text('Primary')),
              VanActionButton.secondary(
                onPressed: null,
                child: Text('Secondary'),
              ),
            ],
          ),
        ),
      ),
    );

    final buttons =
        tester.widgetList<FilledButton>(find.byType(FilledButton)).toList();
    expect(buttons, hasLength(2));

    final primaryStyle = buttons[0].style;
    final secondaryStyle = buttons[1].style;

    expect(
      primaryStyle?.backgroundColor?.resolve(<WidgetState>{}),
      FoodexVanTokens.actionGreen,
    );
    expect(
      primaryStyle?.foregroundColor?.resolve(<WidgetState>{}),
      Colors.white,
    );
    expect(
      secondaryStyle?.foregroundColor?.resolve(<WidgetState>{}),
      FoodexVanTokens.actionGreen,
    );
  });
}
