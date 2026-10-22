import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

abstract final class FoodexColors {
  static const deepGreen = Color(0xFF00452F);
  static const deepGreenStrong = Color(0xFF003C2A);
  static const deepGreenSoft = Color(0xFF0A5B40);
  static const lime = Color(0xFF9BE252);
  static const limeSoft = Color(0xFFE7F8D7);
  static const mint = Color(0xFFEDF7F1);
  static const mintStrong = Color(0xFFC5E0CC);
  static const white = Color(0xFFFFFFFF);
  static const ink = Color(0xFF17231D);
  static const inkSoft = Color(0xFF34453C);
  static const muted = Color(0xFF68766E);
  static const border = Color(0xFFDDE8E1);
  static const success = Color(0xFF1F8A4C);
  static const warning = Color(0xFFF59E0B);
  static const info = Color(0xFF3B82F6);
  static const destructive = Color(0xFFE5484D);
  static const shadow = Color(0x1F163629);
  static const canvas = Color(0xFFF8FBF9);
}

abstract final class FoodexTheme {
  static ThemeData light() {
    final base = ThemeData(
      useMaterial3: true,
      scaffoldBackgroundColor: FoodexColors.canvas,
      colorScheme: const ColorScheme.light(
        primary: FoodexColors.deepGreen,
        onPrimary: FoodexColors.white,
        secondary: FoodexColors.lime,
        onSecondary: FoodexColors.deepGreenStrong,
        surface: FoodexColors.white,
        onSurface: FoodexColors.ink,
        error: FoodexColors.destructive,
      ),
    );

    final textTheme = GoogleFonts.alexandriaTextTheme(base.textTheme).copyWith(
      headlineMedium: GoogleFonts.alexandria(fontSize: 24, fontWeight: FontWeight.w800, color: FoodexColors.ink),
      titleLarge: GoogleFonts.alexandria(fontSize: 18, fontWeight: FontWeight.w800, color: FoodexColors.ink),
      titleMedium: GoogleFonts.alexandria(fontSize: 15, fontWeight: FontWeight.w700, color: FoodexColors.ink),
      bodyLarge: GoogleFonts.alexandria(fontSize: 15, fontWeight: FontWeight.w500, height: 1.45, color: FoodexColors.ink),
      bodyMedium: GoogleFonts.alexandria(fontSize: 13.5, fontWeight: FontWeight.w500, height: 1.4, color: FoodexColors.ink),
      bodySmall: GoogleFonts.alexandria(fontSize: 11.5, fontWeight: FontWeight.w500, color: FoodexColors.muted),
      labelLarge: GoogleFonts.alexandria(fontSize: 13, fontWeight: FontWeight.w700),
    );

    return base.copyWith(
      textTheme: textTheme,
      appBarTheme: AppBarTheme(
        elevation: 0,
        backgroundColor: FoodexColors.deepGreen,
        foregroundColor: FoodexColors.white,
        centerTitle: false,
        titleTextStyle: GoogleFonts.alexandria(
          fontSize: 18,
          fontWeight: FontWeight.w800,
          color: FoodexColors.white,
        ),
      ),
      cardTheme: CardThemeData(
        color: FoodexColors.white,
        elevation: 0,
        margin: EdgeInsets.zero,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(18),
          side: const BorderSide(color: FoodexColors.border),
        ),
      ),
      navigationBarTheme: NavigationBarThemeData(
        height: 70,
        backgroundColor: FoodexColors.white,
        indicatorColor: FoodexColors.limeSoft,
        labelTextStyle: WidgetStatePropertyAll(
          GoogleFonts.alexandria(fontSize: 10.5, fontWeight: FontWeight.w700),
        ),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          backgroundColor: FoodexColors.deepGreen,
          foregroundColor: FoodexColors.white,
          minimumSize: const Size.fromHeight(52),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
          textStyle: GoogleFonts.alexandria(fontWeight: FontWeight.w800),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          foregroundColor: FoodexColors.deepGreenStrong,
          side: const BorderSide(color: FoodexColors.border),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
          textStyle: GoogleFonts.alexandria(fontWeight: FontWeight.w700),
        ),
      ),
      chipTheme: ChipThemeData(
        backgroundColor: FoodexColors.white,
        selectedColor: FoodexColors.limeSoft,
        side: const BorderSide(color: FoodexColors.border),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(999)),
        labelStyle: GoogleFonts.alexandria(fontSize: 11.5, fontWeight: FontWeight.w700),
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: FoodexColors.white,
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(999),
          borderSide: const BorderSide(color: FoodexColors.border),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(999),
          borderSide: const BorderSide(color: FoodexColors.border),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(999),
          borderSide: const BorderSide(color: FoodexColors.lime, width: 1.5),
        ),
        hintStyle: GoogleFonts.alexandria(fontSize: 12, color: FoodexColors.muted),
      ),
    );
  }
}
