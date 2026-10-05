import 'package:flutter/material.dart';

import 'customer_ui_v3_tokens.dart';

/// Backward-compatible brand aliases.
///
/// Existing Customer Journey V2 screens can keep using [FoodexBrand] while the
/// V3 feature lanes migrate screen composition to the shared primitives.
abstract final class FoodexBrand {
  static const green = CustomerUiColors.deepGreen;
  static const greenDark = CustomerUiColors.deepGreenStrong;
  static const greenBright = CustomerUiColors.deepGreenSoft;
  static const greenSoft = CustomerUiColors.mint;
  static const accent = CustomerUiColors.lime;
  static const accentSoft = CustomerUiColors.limeSoft;
  static const strongMint = CustomerUiColors.mintStrong;
  static const orange = CustomerUiColors.warning;
  static const orangeBright = Color(0xFFFBBF24);
  static const orangeSoft = Color(0xFFFFF6DD);
  static const blue = CustomerUiColors.info;
  static const red = CustomerUiColors.destructive;
  static const ink = CustomerUiColors.ink;
  static const muted = CustomerUiColors.muted;
  static const surface = CustomerUiColors.white;
  static const background = CustomerUiColors.mint;
  static const border = CustomerUiColors.border;
  static const surfaceMuted = CustomerUiColors.mint;
  static const inkSoft = CustomerUiColors.inkSoft;

  static Color statusColor(String status) {
    switch (status.toLowerCase()) {
      case 'delivered':
      case 'completed':
      case 'success':
        return CustomerUiColors.success;
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
        return const Color(0xFFE8F7EE);
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
      primary: CustomerUiColors.deepGreen,
      onPrimary: CustomerUiColors.white,
      secondary: CustomerUiColors.lime,
      onSecondary: CustomerUiColors.deepGreenStrong,
      surface: CustomerUiColors.white,
      onSurface: CustomerUiColors.ink,
      error: CustomerUiColors.destructive,
      onError: CustomerUiColors.white,
    );

    final base = ThemeData(
      useMaterial3: true,
      colorScheme: scheme,
    );
    final textTheme = CustomerUiTypography.build(
      base.textTheme,
      fontFamily: fontFamily,
    );
    final primaryTextTheme = CustomerUiTypography.build(
      base.primaryTextTheme,
      fontFamily: fontFamily,
    ).apply(
      bodyColor: CustomerUiColors.white,
      displayColor: CustomerUiColors.white,
    );

    return base.copyWith(
      textTheme: textTheme,
      primaryTextTheme: primaryTextTheme,
      scaffoldBackgroundColor: CustomerUiColors.mint,
      cardColor: CustomerUiColors.white,
      dividerColor: CustomerUiColors.border,
      appBarTheme: AppBarTheme(
        backgroundColor: CustomerUiColors.deepGreen,
        foregroundColor: CustomerUiColors.white,
        surfaceTintColor: Colors.transparent,
        elevation: CustomerUiElevation.flat,
        scrolledUnderElevation: CustomerUiElevation.flat,
        centerTitle: false,
        toolbarHeight: 58,
        titleSpacing: CustomerUiSpacing.md,
        titleTextStyle: primaryTextTheme.titleLarge?.copyWith(
          color: CustomerUiColors.white,
          fontSize: 18,
          fontWeight: FontWeight.w800,
        ),
        iconTheme: const IconThemeData(color: CustomerUiColors.white),
        actionsIconTheme: const IconThemeData(color: CustomerUiColors.white),
      ),
      navigationBarTheme: const NavigationBarThemeData(
        height: 74,
        backgroundColor: CustomerUiColors.white,
        indicatorColor: CustomerUiColors.limeSoft,
        surfaceTintColor: Colors.transparent,
        elevation: CustomerUiElevation.floating,
        shadowColor: CustomerUiColors.shadow,
        labelBehavior: NavigationDestinationLabelBehavior.alwaysShow,
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          backgroundColor: CustomerUiColors.deepGreen,
          foregroundColor: CustomerUiColors.white,
          minimumSize: const Size(0, 52),
          padding: const EdgeInsets.symmetric(
            horizontal: CustomerUiSpacing.lg,
            vertical: CustomerUiSpacing.sm,
          ),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(CustomerUiRadii.md),
          ),
        ),
      ),
      textButtonTheme: TextButtonThemeData(
        style: TextButton.styleFrom(
          foregroundColor: CustomerUiColors.deepGreenStrong,
          minimumSize: const Size(44, 44),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(CustomerUiRadii.md),
          ),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          foregroundColor: CustomerUiColors.deepGreenStrong,
          minimumSize: const Size(0, 50),
          side: const BorderSide(color: CustomerUiColors.border),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(CustomerUiRadii.md),
          ),
        ),
      ),
      listTileTheme: const ListTileThemeData(
        minVerticalPadding: CustomerUiSpacing.sm,
        iconColor: CustomerUiColors.deepGreenStrong,
        textColor: CustomerUiColors.ink,
      ),
      snackBarTheme: SnackBarThemeData(
        backgroundColor: CustomerUiColors.ink,
        contentTextStyle: textTheme.bodyMedium?.copyWith(
          color: CustomerUiColors.white,
        ),
        behavior: SnackBarBehavior.floating,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(CustomerUiRadii.md),
        ),
      ),
      chipTheme: ChipThemeData(
        backgroundColor: CustomerUiColors.white,
        selectedColor: CustomerUiColors.limeSoft,
        side: const BorderSide(color: CustomerUiColors.border),
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(CustomerUiRadii.pill),
        ),
        labelStyle: textTheme.labelLarge,
        padding: const EdgeInsets.symmetric(
          horizontal: CustomerUiSpacing.xs,
          vertical: CustomerUiSpacing.xxs,
        ),
      ),
      progressIndicatorTheme: const ProgressIndicatorThemeData(
        color: CustomerUiColors.deepGreen,
      ),
      floatingActionButtonTheme: const FloatingActionButtonThemeData(
        backgroundColor: CustomerUiColors.lime,
        foregroundColor: CustomerUiColors.deepGreenStrong,
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: CustomerUiColors.white,
        contentPadding: const EdgeInsets.symmetric(
          horizontal: CustomerUiSpacing.md,
          vertical: 15,
        ),
        hintStyle: textTheme.bodyMedium?.copyWith(
          color: CustomerUiColors.muted,
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(CustomerUiRadii.pill),
          borderSide: const BorderSide(color: CustomerUiColors.border),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(CustomerUiRadii.pill),
          borderSide: const BorderSide(
            color: CustomerUiColors.lime,
            width: CustomerUiStroke.emphasis,
          ),
        ),
        errorBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(CustomerUiRadii.pill),
          borderSide: const BorderSide(color: CustomerUiColors.destructive),
        ),
        focusedErrorBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(CustomerUiRadii.pill),
          borderSide: const BorderSide(
            color: CustomerUiColors.destructive,
            width: CustomerUiStroke.emphasis,
          ),
        ),
      ),
    );
  }
}
