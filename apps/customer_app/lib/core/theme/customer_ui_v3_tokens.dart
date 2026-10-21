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

  // Wholesale storefront illustration and commerce-state tokens.
  // Keep feature screens free of local Color(0x...) literals.
  static const wholesaleVehicleShadow = Color(0x33000000);
  static const wholesaleVehicleGreenLight = Color(0xFF0C9B55);
  static const wholesaleVehicleGreen = Color(0xFF006736);
  static const wholesaleVehicleTop = Color(0xFF44C784);
  static const wholesaleVehicleFront = Color(0xFF045C34);
  static const wholesaleVehicleTrim = Color(0xFFF4D06F);
  static const wholesaleVehicleCabTop = Color(0xFF168A53);
  static const wholesaleVehicleGlassLight = Color(0xFFD9F3FF);
  static const wholesaleVehicleGlassDark = Color(0xFF5B8798);
  static const wholesaleVehicleSideGlass = Color(0xFFB8D9E6);
  static const wholesaleVehicleChassis = Color(0xFF063D28);
  static const wholesaleVehicleBumper = Color(0xFFC7D0CE);
  static const wholesaleWheelTire = Color(0xFF1C2529);
  static const wholesaleWheelRim = Color(0xFF66737A);
  static const wholesaleWheelHub = Color(0xFFD7E0DE);
  static const wholesaleWheelSpoke = Color(0xFFCBD4D2);
  static const wholesaleBrandToneGreen = Color(0xFF087347);
  static const wholesaleBrandToneBlue = Color(0xFF2E5FA8);
  static const wholesaleBrandToneOrange = Color(0xFFB54708);
  static const wholesaleBrandTonePurple = Color(0xFF7A4EA3);
  static const wholesaleBrandToneAmber = Color(0xFF8B5A00);
  static const wholesaleCategoryBadgeBackground = Color(0xFFFFD76A);
  static const wholesaleCategoryBadgeForeground = Color(0xFF6B4900);
  static const wholesalePriceBadgeBackground = Color(0xFFE9F7EE);
  static const wholesaleNegativeValue = Color(0xFFD62828);
  static const wholesalePromotionBadgeBackground = Color(0xFFFFF2CC);
  static const wholesalePromotionBadgeForeground = Color(0xFF7A4B00);
  static const wholesaleOutOfStockBackground = Color(0xFF4B5563);
  static const wholesaleCommerceInk = Color(0xFF102033);
  static const wholesaleBadgeBorder = Color(0xFFFFC44D);
  static const wholesaleSoftShadow = Color(0x12000000);
  static const wholesaleDisabled = Color(0xFFB8C4BE);
  static const wholesaleWaveAccent = Color(0xFFB9F227);
  static const wholesalePositiveValue = Color(0xFF078A43);
  static const wholesaleFinalValue = Color(0xFF1565C0);
  static const wholesaleSelectionIdle = Color(0xFFE4E0E7);
  static const wholesaleSecondaryText = Color(0xFF6B7785);
  static const marketplaceImageShadow = Color(0x3D72E89A);
  static const marketplaceInk = Color(0xFF17212F);
  static const retailMutedStrong = Color(0xFF5F6C67);
  static const retailSuccessWash = Color(0xFFE8F7EE);
  static const storefrontBorderSoft = Color(0xFFE3E9E5);
  static const storefrontShadowMedium = Color(0x26000000);
  static const storefrontMintWash = Color(0xFFF1F8F4);
  static const storefrontLimeWash = Color(0xFFD8FFD0);
  static const storefrontLimeStrong = Color(0xFFB5F23E);
  static const storefrontGreenGlow = Color(0xFF63D36E);
  static const storefrontGreenHighlight = Color(0xFF8DEB75);
  static const storefrontGoldDeep = Color(0xFF8A5A00);
  static const storefrontGoldMid = Color(0xFFD99A12);
  static const storefrontGoldLight = Color(0xFFFFE08A);
  static const storefrontGoldBright = Color(0xFFFFC238);
  static const storefrontGoldDark = Color(0xFFB87500);
  static const storefrontGoldShadow = Color(0x338A5A00);
  static const storefrontOxbloodDeep = Color(0xFF3D0710);
  static const storefrontOxblood = Color(0xFF65101A);
  static const storefrontOxbloodMid = Color(0xFF8B1E2D);
  static const storefrontOxbloodLight = Color(0xFFC04A58);
  static const storefrontOxbloodDark = Color(0xFF6F0F1D);
  static const storefrontOxbloodShadow = Color(0x443D0710);
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
