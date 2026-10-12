import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

/// Central Customer UI V3 palette.
///
/// Feature screens must consume these tokens (directly or through [FoodexTheme])
/// instead of introducing per-screen brand colors.
abstract final class CustomerUiColors {
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
}

abstract final class CustomerUiSpacing {
  static const double xxs = 4;
  static const double xs = 8;
  static const double sm = 12;
  static const double md = 16;
  static const double lg = 20;
  static const double xl = 24;
  static const double xxl = 32;
  static const double section = 36;
  static const double page = 16;
}

abstract final class CustomerUiRadii {
  static const double sm = 12;
  static const double md = 16;
  static const double lg = 22;
  static const double xl = 30;
  static const double curvedHeader = 38;
  static const double pill = 999;
}

abstract final class CustomerUiStroke {
  static const double hairline = 1;
  static const double emphasis = 1.5;
}

abstract final class CustomerUiElevation {
  static const double flat = 0;
  static const double card = 1;
  static const double floating = 8;

  static const cardShadow = <BoxShadow>[
    BoxShadow(
      color: CustomerUiColors.shadow,
      blurRadius: 18,
      offset: Offset(0, 7),
    ),
  ];
}

abstract final class CustomerUiMotion {
  static const Duration instant = Duration.zero;
  static const Duration fast = Duration(milliseconds: 120);
  static const Duration standard = Duration(milliseconds: 180);
  static const Duration emphasis = Duration(milliseconds: 240);

  static const Curve standardCurve = Curves.easeOutCubic;
  static const Curve emphasisCurve = Curves.easeInOutCubic;

  static bool isReduced(BuildContext context) {
    final media = MediaQuery.maybeOf(context);
    return media?.disableAnimations == true ||
        media?.accessibleNavigation == true;
  }

  static Duration resolve(BuildContext context, Duration preferred) =>
      isReduced(context) ? Duration.zero : preferred;
}

abstract final class CustomerUiTypography {
  static TextTheme build(
    TextTheme base, {
    String? fontFamily,
  }) {
    final themed = fontFamily == null
        ? GoogleFonts.alexandriaTextTheme(base)
        : base.apply(fontFamily: fontFamily);

    return themed.copyWith(
      headlineLarge: themed.headlineLarge?.copyWith(fontSize: 26, height: 1.18, fontWeight: FontWeight.w800),
      headlineMedium: themed.headlineMedium?.copyWith(fontSize: 22, height: 1.22, fontWeight: FontWeight.w800),
      headlineSmall: themed.headlineSmall?.copyWith(fontSize: 20, height: 1.24, fontWeight: FontWeight.w800),
      titleLarge: themed.titleLarge?.copyWith(fontSize: 18, height: 1.28, fontWeight: FontWeight.w800),
      titleMedium: themed.titleMedium?.copyWith(fontSize: 15, height: 1.32, fontWeight: FontWeight.w700),
      titleSmall: themed.titleSmall?.copyWith(fontSize: 14, height: 1.30, fontWeight: FontWeight.w700),
      bodyLarge: themed.bodyLarge?.copyWith(fontSize: 15, height: 1.48, fontWeight: FontWeight.w500),
      bodyMedium: themed.bodyMedium?.copyWith(fontSize: 14, height: 1.45, fontWeight: FontWeight.w500),
      bodySmall: themed.bodySmall?.copyWith(fontSize: 12, height: 1.38, fontWeight: FontWeight.w500),
      labelLarge: themed.labelLarge?.copyWith(fontSize: 13, height: 1.22, fontWeight: FontWeight.w700),
      labelMedium: themed.labelMedium?.copyWith(fontSize: 12, height: 1.20, fontWeight: FontWeight.w700),
    ).apply(
      bodyColor: CustomerUiColors.ink,
      displayColor: CustomerUiColors.ink,
    );
  }
}
