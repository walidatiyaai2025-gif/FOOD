import 'dart:convert';

import 'package:flutter/widgets.dart';
import 'package:http/http.dart' as http;

typedef DriverTranslationFetcher = Future<Map<String, String>> Function(String locale);

class DriverTranslations extends InheritedWidget {
  const DriverTranslations({
    required this.locale,
    required this.overrides,
    required super.child,
    super.key,
  });

  final Locale locale;
  final Map<String, String> overrides;

  static const Map<String, String> _ar = {
    'driver.app.title': 'فودكس للسائق',
    'driver.home.title': 'الرئيسية',
    'driver.deliveries.title': 'التوصيلات',
    'driver.b2c.title': 'توصيلات التجزئة',
    'driver.b2b.title': 'توصيلات الجملة',
    'driver.empty': 'لا توجد توصيلات مسندة',
    'driver.error': 'تعذر تحميل التوصيلات',
    'driver.offline': 'لا يوجد اتصال. أعد المحاولة عند عودة الشبكة.',
    'driver.retry': 'إعادة المحاولة',
    'driver.route.denied': 'هذا المسار غير متاح لدور السائق الحالي',
    'driver.route.not_found': 'المسار غير موجود',
  };

  static const Map<String, String> _en = {
    'driver.app.title': 'FOODEX Driver',
    'driver.home.title': 'Driver Home',
    'driver.deliveries.title': 'Deliveries',
    'driver.b2c.title': 'Retail deliveries',
    'driver.b2b.title': 'Wholesale deliveries',
    'driver.empty': 'No assigned deliveries',
    'driver.error': 'Unable to load deliveries',
    'driver.offline': 'No connection. Try again when the network is back.',
    'driver.retry': 'Retry',
    'driver.route.denied': 'This route is not available for this driver role',
    'driver.route.not_found': 'Route not found',
  };

  String text(String key) {
    final defaults = locale.languageCode == 'en' ? _en : _ar;
    return overrides[key] ?? defaults[key] ?? key;
  }

  static DriverTranslations? maybeOf(BuildContext context) {
    return context.dependOnInheritedWidgetOfExactType<DriverTranslations>();
  }

  static String fallback(BuildContext context, String key) {
    final locale = Localizations.maybeLocaleOf(context) ?? const Locale('ar');
    final defaults = locale.languageCode == 'en' ? _en : _ar;
    return defaults[key] ?? key;
  }

  @override
  bool updateShouldNotify(DriverTranslations oldWidget) {
    return oldWidget.locale != locale || oldWidget.overrides != overrides;
  }
}

extension DriverTranslationContext on BuildContext {
  String tr(String key) => DriverTranslations.maybeOf(this)?.text(key) ?? DriverTranslations.fallback(this, key);
}

Future<Map<String, String>> fetchDriverTranslationBundle(String baseUrl, String locale) async {
  final response = await http.get(
    Uri.parse('$baseUrl/api/v1/translations/$locale'),
    headers: const {'Accept': 'application/json'},
  );

  if (response.statusCode < 200 || response.statusCode >= 300) {
    return const {};
  }

  final decoded = jsonDecode(response.body);
  if (decoded is! Map<String, dynamic> || decoded['translations'] is! Map) {
    return const {};
  }

  return (decoded['translations'] as Map).map(
    (key, value) => MapEntry(key.toString(), value.toString()),
  );
}
