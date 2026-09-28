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
    'driver.home.subtitle': 'ابدأ يومك وراجع التوصيلات المسندة لك',
    'driver.home.channel': 'قناة العمل',
    'driver.home.ready': 'جاهز للتوصيل',
    'driver.home.open_deliveries': 'عرض التوصيلات الحالية',
    'driver.deliveries.title': 'التوصيلات',
    'driver.b2c.title': 'توصيلات التجزئة',
    'driver.b2b.title': 'توصيلات الجملة',
    'driver.empty': 'لا توجد توصيلات مسندة',
    'driver.error': 'تعذر تحميل التوصيلات',
    'driver.offline': 'لا يوجد اتصال. أعد المحاولة عند عودة الشبكة.',
    'driver.retry': 'إعادة المحاولة',
    'driver.refresh': 'تحديث الطلبات',
    'driver.route.denied': 'هذا المسار غير متاح لدور السائق الحالي',
    'driver.route.not_found': 'المسار غير موجود',
    'driver.login.email': 'البريد الإلكتروني',
    'driver.login.password': 'كلمة المرور',
    'driver.login.welcome': 'مرحباً بك في فودكس',
    'driver.login.subtitle': 'سجّل الدخول بحساب السائق لعرض التوصيلات المسندة إليك',
    'driver.login.show_password': 'إظهار كلمة المرور',
    'driver.login.hide_password': 'إخفاء كلمة المرور',
    'driver.login.secure': 'دخول آمن ومخصص لسائقي FOODEX',
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
    'driver.detail.order_status': 'حالة الطلب',
    'driver.detail.store': 'المتجر',
    'driver.detail.customer': 'العميل',
    'driver.detail.phone': 'الهاتف',
    'driver.detail.address': 'العنوان',
    'driver.detail.note': 'ملاحظة الطلب',
    'driver.detail.items': 'الأصناف',
    'driver.detail.no_items': 'لا توجد أصناف مسجلة',
    'driver.detail.total': 'الإجمالي',
    'driver.detail.payment': 'الدفع',
    'driver.detail.unknown': 'غير محدد',
    'driver.search': 'ابحث برقم الطلب أو العميل أو العنوان',
    'driver.filter.active': 'نشطة',
    'driver.filter.completed': 'مكتملة',
    'driver.filter.failed': 'متعذرة',
    'driver.filter.all': 'الكل',
    'driver.filter.empty': 'لا توجد طلبات مطابقة لهذا العرض',
    'driver.failure.title': 'تعذر التسليم',
    'driver.failure.reason': 'سبب تعذر التسليم',
    'driver.failure.submit': 'تسجيل التعذر',
    'driver.order_status.pending': 'قيد الانتظار',
    'driver.order_status.confirmed': 'مؤكد',
    'driver.order_status.preparing': 'قيد التجهيز',
    'driver.order_status.ready': 'جاهز للتسليم',
    'driver.order_status.out_for_delivery': 'خرج للتسليم',
    'driver.order_status.delivered': 'تم التسليم',
    'driver.order_status.failed': 'تعذر التسليم',
    'driver.order_status.cancelled': 'ملغي',
    'driver.action.none': 'لا يوجد إجراء متاح حاليًا',
    'driver.status.assigned': 'مسند',
    'driver.status.accepted': 'مقبول',
    'driver.status.picked_up': 'تم الاستلام',
    'driver.status.out_for_delivery': 'في الطريق للتسليم',
    'driver.status.delivered': 'تم التسليم',
    'driver.status.failed': 'تعذر التسليم',
    'driver.status.unassigned': 'تم سحب الطلب',
  };

  static const Map<String, String> _en = {
    'driver.app.title': 'FOODEX Driver',
    'driver.home.title': 'Driver Home',
    'driver.home.subtitle': 'Start your shift and review your assigned deliveries',
    'driver.home.channel': 'Work channel',
    'driver.home.ready': 'Ready to deliver',
    'driver.home.open_deliveries': 'Open current deliveries',
    'driver.deliveries.title': 'Deliveries',
    'driver.b2c.title': 'Retail deliveries',
    'driver.b2b.title': 'Wholesale deliveries',
    'driver.empty': 'No assigned deliveries',
    'driver.error': 'Unable to load deliveries',
    'driver.offline': 'No connection. Try again when the network is back.',
    'driver.retry': 'Retry',
    'driver.refresh': 'Refresh orders',
    'driver.route.denied': 'This route is not available for this driver role',
    'driver.route.not_found': 'Route not found',
    'driver.login.email': 'Email',
    'driver.login.password': 'Password',
    'driver.login.welcome': 'Welcome to FOODEX',
    'driver.login.subtitle': 'Sign in with your driver account to view assigned deliveries',
    'driver.login.show_password': 'Show password',
    'driver.login.hide_password': 'Hide password',
    'driver.login.secure': 'Secure access for FOODEX drivers',
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
    'driver.detail.order_status': 'Order status',
    'driver.detail.store': 'Store',
    'driver.detail.customer': 'Customer',
    'driver.detail.phone': 'Phone',
    'driver.detail.address': 'Address',
    'driver.detail.note': 'Order note',
    'driver.detail.items': 'Items',
    'driver.detail.no_items': 'No order items recorded',
    'driver.detail.total': 'Total',
    'driver.detail.payment': 'Payment',
    'driver.detail.unknown': 'Not specified',
    'driver.search': 'Search order, customer or address',
    'driver.filter.active': 'Active',
    'driver.filter.completed': 'Completed',
    'driver.filter.failed': 'Failed',
    'driver.filter.all': 'All',
    'driver.filter.empty': 'No orders match this view',
    'driver.failure.title': 'Delivery failed',
    'driver.failure.reason': 'Failure reason',
    'driver.failure.submit': 'Record failure',
    'driver.order_status.pending': 'Pending',
    'driver.order_status.confirmed': 'Confirmed',
    'driver.order_status.preparing': 'Preparing',
    'driver.order_status.ready': 'Ready',
    'driver.order_status.out_for_delivery': 'Out for delivery',
    'driver.order_status.delivered': 'Delivered',
    'driver.order_status.failed': 'Failed',
    'driver.order_status.cancelled': 'Cancelled',
    'driver.action.none': 'No action is currently available',
    'driver.status.assigned': 'Assigned',
    'driver.status.accepted': 'Accepted',
    'driver.status.picked_up': 'Picked up',
    'driver.status.out_for_delivery': 'Out for delivery',
    'driver.status.delivered': 'Delivered',
    'driver.status.failed': 'Delivery failed',
    'driver.status.unassigned': 'Unassigned',
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
