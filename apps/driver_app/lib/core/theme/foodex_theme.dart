import 'package:flutter/material.dart';

abstract final class FoodexBrand {
  static const green = Color(0xFF158A3A);
  static const greenDark = Color(0xFF165D2D);
  static const greenBright = Color(0xFF27B658);
  static const greenSoft = Color(0xFFEAF7EF);
  static const orange = Color(0xFFEE731C);
  static const orangeBright = Color(0xFFFC8F33);
  static const orangeSoft = Color(0xFFFFF1E6);
  static const blue = Color(0xFF4B8CF5);
  static const red = Color(0xFFEF5350);
  static const ink = Color(0xFF172033);
  static const muted = Color(0xFF667085);
  static const surface = Color(0xFFFFFFFF);
  static const background = Color(0xFFF7F9FC);
  static const border = Color(0xFFE6EAF0);
}

abstract final class FoodexTheme {
  static ThemeData light() {
    const scheme = ColorScheme.light(
      primary: FoodexBrand.green,
      onPrimary: Colors.white,
      secondary: FoodexBrand.orange,
      onSecondary: Colors.white,
      surface: FoodexBrand.surface,
      onSurface: FoodexBrand.ink,
      error: FoodexBrand.red,
      onError: Colors.white,
    );

    return ThemeData(
      useMaterial3: true,
      colorScheme: scheme,
      scaffoldBackgroundColor: FoodexBrand.background,
      appBarTheme: const AppBarTheme(
        backgroundColor: FoodexBrand.surface,
        foregroundColor: FoodexBrand.ink,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
      ),
      progressIndicatorTheme: const ProgressIndicatorThemeData(
        color: FoodexBrand.green,
      ),
      floatingActionButtonTheme: const FloatingActionButtonThemeData(
        backgroundColor: FoodexBrand.orange,
        foregroundColor: Colors.white,
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: FoodexBrand.surface,
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: const BorderSide(color: FoodexBrand.border),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: const BorderSide(color: FoodexBrand.green, width: 1.5),
        ),
      ),
    );
  }
}
