import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

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

  static Color statusColor(String status) {
    switch (status.toLowerCase()) {
      case 'delivered':
      case 'completed':
      case 'success':
        return green;
      case 'assigned':
      case 'picked_up':
      case 'out_for_delivery':
      case 'in_transit':
      case 'info':
        return blue;
      case 'cancelled':
      case 'refunded':
      case 'failed':
      case 'error':
        return red;
      case 'pending':
      case 'confirmed':
      case 'processing':
      case 'paid':
      case 'accepted':
      case 'warning':
        return orange;
      default:
        return muted;
    }
  }

  static Color statusSurface(String status) {
    switch (status.toLowerCase()) {
      case 'delivered':
      case 'completed':
      case 'success':
        return greenSoft;
      case 'assigned':
      case 'picked_up':
      case 'out_for_delivery':
      case 'in_transit':
      case 'info':
        return const Color(0xFFEDF4FF);
      case 'cancelled':
      case 'refunded':
      case 'failed':
      case 'error':
        return const Color(0xFFFFF0F0);
      case 'pending':
      case 'confirmed':
      case 'processing':
      case 'paid':
      case 'accepted':
      case 'warning':
        return orangeSoft;
      default:
        return const Color(0xFFF2F4F7);
    }
  }
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

    final base = ThemeData(
      useMaterial3: true,
      colorScheme: scheme,
    );
    final tajawalTextTheme = GoogleFonts.tajawalTextTheme(base.textTheme).apply(
      bodyColor: FoodexBrand.ink,
      displayColor: FoodexBrand.ink,
    );

    return base.copyWith(
      textTheme: tajawalTextTheme,
      primaryTextTheme: GoogleFonts.tajawalTextTheme(base.primaryTextTheme),
      scaffoldBackgroundColor: FoodexBrand.background,
      cardColor: FoodexBrand.surface,
      dividerColor: FoodexBrand.border,
      appBarTheme: const AppBarTheme(
        backgroundColor: FoodexBrand.surface,
        foregroundColor: FoodexBrand.ink,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
      ),
      navigationBarTheme: const NavigationBarThemeData(
        backgroundColor: FoodexBrand.surface,
        indicatorColor: FoodexBrand.greenSoft,
        surfaceTintColor: Colors.transparent,
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          backgroundColor: FoodexBrand.green,
          foregroundColor: Colors.white,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(12),
          ),
        ),
      ),
      textButtonTheme: TextButtonThemeData(
        style: TextButton.styleFrom(
          foregroundColor: FoodexBrand.greenDark,
        ),
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
