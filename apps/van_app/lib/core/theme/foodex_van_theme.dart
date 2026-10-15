import 'package:flutter/material.dart';

abstract final class FoodexVanTokens {
  static const green = Color(0xFF158A3A);
  static const greenDark = Color(0xFF165D2D);
  static const greenSoft = Color(0xFFEAF7EF);
  static const mint = greenSoft;
  static const surface = Color(0xFFFFFFFF);
  static const background = Color(0xFFF6F8F6);
  static const ink = Color(0xFF172033);
  static const muted = Color(0xFF667085);
  static const border = Color(0xFFE3E8E4);
  static const warning = Color(0xFFEE731C);
  static const danger = Color(0xFFEF5350);

  static const double touchTarget = 48;
  static const double cardRadius = 16;
}

abstract final class FoodexVanTheme {
  static ThemeData light({String? fontFamily}) {
    const scheme = ColorScheme.light(
      primary: FoodexVanTokens.green,
      onPrimary: Colors.white,
      secondary: FoodexVanTokens.warning,
      surface: FoodexVanTokens.surface,
      onSurface: FoodexVanTokens.ink,
      error: FoodexVanTokens.danger,
    );

    return ThemeData(
      useMaterial3: true,
      colorScheme: scheme,
      scaffoldBackgroundColor: FoodexVanTokens.background,
      dividerColor: FoodexVanTokens.border,
      fontFamily: fontFamily,
      appBarTheme: const AppBarTheme(
        backgroundColor: FoodexVanTokens.surface,
        foregroundColor: FoodexVanTokens.ink,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          minimumSize: const Size(0, FoodexVanTokens.touchTarget),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(FoodexVanTokens.cardRadius),
          ),
        ),
      ),
    );
  }
}
