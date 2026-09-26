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
    'driver.login.email': 'البريد الإلكتروني',
    'driver.login.password': 'كلمة المرور',
    'driver.login.submit': 'تسجيل الدخول',
    'driver.login.required': 'أدخل البريد الإلكتروني وكلمة المرور',
    'driver.login.invalid': 'بيانات الدخول غير صحيحة',
    'driver.login.role_denied': 'هذا الحساب ليس حساب سائق فودكس مصرحًا له',
    'driver.config.missing': 'عنوان خادم فودكس غير مضبوط لهذا الإصدار',
    'driver.logout': 'تسجيل الخروج',
    'driver.dismiss': 'إغلاق',
    'driver.detail.title': 'تفاصيل التوصيل',
    'driver.detail.order': 'الطلب',
    'driver.detail.status': 'الحالة',
    'driver.action.none': 'لا يوجد إجراء متاح حاليًا',
    'driver.status.assigned': 'مسند',
    'driver.status.accepted': 'مقبول',
    'driver.status.picked_up': 'تم الاستلام',
    'driver.status.out_for_delivery': 'في الطريق للتسليم',
    'driver.status.delivered': 'تم التسليم',
    'driver.status.failed': 'تعذر التسليم',
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
    'driver.login.email': 'Email',
    'driver.login.password': 'Password',
    'driver.login.submit': 'Sign in',
    'driver.login.required': 'Enter email and password',
    'driver.login.invalid': 'Invalid sign-in details',
    'driver.login.role_denied': 'This account is not an authorized FOODEX driver',
    'driver.config.missing': 'The FOODEX server URL is not configured for this build',
    'driver.logout': 'Sign out',
    'driver.dismiss': 'Dismiss',
    'driver.detail.title': 'Delivery details',
    'driver.detail.order': 'Order',
    'driver.detail.status': 'Status',
    'driver.action.none': 'No action is currently available',
    'driver.status.assigned': 'Assigned',
    'driver.status.accepted': 'Accepted',
    'driver.status.picked_up': 'Picked up',
    'driver.status.out_for_delivery': 'Out for delivery',
    'driver.status.delivered': 'Delivered',
    'driver.status.failed': 'Delivery failed',
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
