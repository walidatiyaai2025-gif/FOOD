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
  static const background = Color(0xFFF6F8F6);
  static const border = Color(0xFFE3E8E4);
  static const surfaceMuted = Color(0xFFF0F5F1);
  static const inkSoft = Color(0xFF344054);

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
  static ThemeData light({String? fontFamily}) {
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
    final textTheme = (fontFamily == null
            ? GoogleFonts.alexandriaTextTheme(base.textTheme)
            : base.textTheme.apply(fontFamily: fontFamily))
        .apply(
      bodyColor: FoodexBrand.ink,
      displayColor: FoodexBrand.ink,
    );
    final primaryTextTheme = fontFamily == null
        ? GoogleFonts.alexandriaTextTheme(base.primaryTextTheme)
        : base.primaryTextTheme.apply(fontFamily: fontFamily);

    return base.copyWith(
      textTheme: textTheme,
      primaryTextTheme: primaryTextTheme,
      scaffoldBackgroundColor: FoodexBrand.background,
      cardColor: FoodexBrand.surface,
      dividerColor: FoodexBrand.border,
      appBarTheme: const AppBarTheme(
        backgroundColor: FoodexBrand.surface,
        foregroundColor: FoodexBrand.ink,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        scrolledUnderElevation: 0,
        centerTitle: false,
        toolbarHeight: 64,
        titleTextStyle: TextStyle(
          color: FoodexBrand.ink,
          fontSize: 19,
          fontWeight: FontWeight.w800,
        ),
      ),
      navigationBarTheme: const NavigationBarThemeData(
        height: 74,
        backgroundColor: FoodexBrand.surface,
        indicatorColor: FoodexBrand.greenSoft,
        surfaceTintColor: Colors.transparent,
        elevation: 8,
        shadowColor: Color(0x1A172033),
        labelBehavior: NavigationDestinationLabelBehavior.alwaysShow,
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          backgroundColor: FoodexBrand.green,
          foregroundColor: Colors.white,
          minimumSize: const Size(0, 52),
          padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 12),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(16),
          ),
        ),
      ),
      textButtonTheme: TextButtonThemeData(
        style: TextButton.styleFrom(
          foregroundColor: FoodexBrand.greenDark,
          minimumSize: const Size(44, 44),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(14),
          ),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          foregroundColor: FoodexBrand.greenDark,
          minimumSize: const Size(0, 50),
          side: const BorderSide(color: FoodexBrand.border),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(16),
          ),
        ),
      ),
      listTileTheme: const ListTileThemeData(
        minVerticalPadding: 12,
        iconColor: FoodexBrand.greenDark,
        textColor: FoodexBrand.ink,
      ),
      snackBarTheme: SnackBarThemeData(
        backgroundColor: FoodexBrand.ink,
        contentTextStyle: textTheme.bodyMedium?.copyWith(color: Colors.white),
        behavior: SnackBarBehavior.floating,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(16),
        ),
      ),
      chipTheme: ChipThemeData(
        backgroundColor: FoodexBrand.surface,
        selectedColor: FoodexBrand.greenSoft,
        side: const BorderSide(color: FoodexBrand.border),
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(999),
        ),
        labelStyle: textTheme.labelLarge,
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 8),
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
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 15),
        hintStyle: textTheme.bodyMedium?.copyWith(color: FoodexBrand.muted),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(16),
          borderSide: const BorderSide(color: FoodexBrand.border),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(16),
          borderSide: const BorderSide(color: FoodexBrand.green, width: 1.5),
        ),
        errorBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(16),
          borderSide: const BorderSide(color: FoodexBrand.red),
        ),
        focusedErrorBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(16),
          borderSide: const BorderSide(color: FoodexBrand.red, width: 1.5),
        ),
      ),
    );
  }
}
