import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';

enum WholesaleProductCardSize {
  compact(3),
  comfortable(2),
  expanded(1);

  const WholesaleProductCardSize(this.columns);

  final int columns;

  WholesaleProductCardSize get next =>
      WholesaleProductCardSize.values[
        (index + 1) % WholesaleProductCardSize.values.length
      ];
}

class CustomerDisplayPreferences {
  CustomerDisplayPreferences._();

  static final CustomerDisplayPreferences instance =
      CustomerDisplayPreferences._();

  static const _darkModeKey = 'customer_display_dark_mode';
  static const _wholesaleCardSizeKey =
      'customer_wholesale_product_card_size';

  final ValueNotifier<ThemeMode> themeMode =
      ValueNotifier<ThemeMode>(ThemeMode.light);
  final ValueNotifier<WholesaleProductCardSize> wholesaleProductCardSize =
      ValueNotifier<WholesaleProductCardSize>(
        WholesaleProductCardSize.compact,
      );

  bool _initialized = false;

  bool get isDarkMode => themeMode.value == ThemeMode.dark;

  Future<void> initialize() async {
    if (_initialized) return;
    _initialized = true;

    try {
      final preferences = await SharedPreferences.getInstance();
      themeMode.value = (preferences.getBool(_darkModeKey) ?? false)
          ? ThemeMode.dark
          : ThemeMode.light;

      final savedSize = preferences.getString(_wholesaleCardSizeKey);
      wholesaleProductCardSize.value =
          WholesaleProductCardSize.values.firstWhere(
        (value) => value.name == savedSize,
        orElse: () => WholesaleProductCardSize.compact,
      );
    } catch (_) {
      // Keep safe in-memory defaults when platform preferences are unavailable.
    }
  }

  Future<void> setDarkMode(bool enabled) async {
    themeMode.value = enabled ? ThemeMode.dark : ThemeMode.light;
    try {
      final preferences = await SharedPreferences.getInstance();
      await preferences.setBool(_darkModeKey, enabled);
    } catch (_) {
      // The live preference still applies for this session.
    }
  }

  Future<void> setWholesaleProductCardSize(
    WholesaleProductCardSize size,
  ) async {
    wholesaleProductCardSize.value = size;
    try {
      final preferences = await SharedPreferences.getInstance();
      await preferences.setString(_wholesaleCardSizeKey, size.name);
    } catch (_) {
      // The live preference still applies for this session.
    }
  }

  Future<void> cycleWholesaleProductCardSize() =>
      setWholesaleProductCardSize(wholesaleProductCardSize.value.next);
}
