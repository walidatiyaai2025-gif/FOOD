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
    'driver.home.status_summary': 'حالة طلباتي',
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
    'driver.login.username': 'اسم المستخدم',
    'driver.version': 'الإصدار',
    'driver.login.welcome': 'مرحباً بك في فودكس',
    'driver.login.subtitle': 'في المرحلة التجريبية أدخل اسم المستخدم فقط لعرض التوصيلات المسندة إليك',
    'driver.login.show_password': 'إظهار كلمة المرور',
    'driver.login.hide_password': 'إخفاء كلمة المرور',
    'driver.login.secure': 'دخول تجريبي مؤقت باسم المستخدم فقط',
    'driver.login.submit': 'تسجيل الدخول',
    'driver.login.required': 'أدخل اسم المستخدم',
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
    'driver.action.confirm_title': 'تأكيد تحديث حالة التوصيل',
    'driver.action.note_optional': 'ملاحظة اختيارية',
    'driver.action.confirm': 'تأكيد',
    'driver.proof.camera': 'الكاميرا',
    'driver.proof.gallery': 'المعرض',
    'driver.proof.attached': 'تم إرفاق صورة الإثبات',
    'driver.invoice.title': 'الفاتورة / الإيصال',
    'driver.invoice.open': 'عرض الفاتورة',
    'driver.invoice.status': 'حالة الفاتورة',
    'driver.invoice.revision': 'المراجعة',
    'driver.invoice.issued_at': 'تاريخ الإصدار',
    'driver.invoice.subtotal': 'الإجمالي الفرعي',
    'driver.invoice.discount': 'الخصم',
    'driver.invoice.delivery': 'التوصيل',
    'driver.invoice.tax': 'الضريبة',
    'driver.invoice.total': 'الإجمالي النهائي',
    'driver.invoice.payment': 'الدفع',
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
    'driver.payment_method.cash_on_delivery': 'الدفع عند الاستلام',
    'driver.payment_method.card': 'بطاقة',
    'driver.payment_method.account_credit': 'رصيد الحساب',
    'driver.payment_method.wallet': 'المحفظة',
    'driver.payment_status.pending': 'بانتظار الدفع',
    'driver.payment_status.paid': 'مدفوع',
    'driver.payment_status.failed': 'فشل الدفع',
    'driver.payment_status.refunded': 'مسترد',
    'driver.invoice_status.issued': 'صادرة',
    'driver.invoice_status.paid': 'مدفوعة',
    'driver.invoice_status.cancelled': 'ملغاة',
  };

  static const Map<String, String> _en = {
    'driver.app.title': 'FOODEX Driver',
    'driver.home.title': 'Driver Home',
    'driver.home.subtitle': 'Start your shift and review your assigned deliveries',
    'driver.home.channel': 'Work channel',
    'driver.home.ready': 'Ready to deliver',
    'driver.home.open_deliveries': 'Open current deliveries',
    'driver.home.status_summary': 'My order status',
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
    'driver.login.username': 'Username',
    'driver.version': 'Version',
    'driver.login.welcome': 'Welcome to FOODEX',
    'driver.login.subtitle': 'During the pilot, enter your username only to view assigned deliveries',
    'driver.login.show_password': 'Show password',
    'driver.login.hide_password': 'Hide password',
    'driver.login.secure': 'Temporary pilot access using username only',
    'driver.login.submit': 'Sign in',
    'driver.login.required': 'Enter your username',
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
    'driver.failure.note_optional': 'Optional additional note',
    'driver.failure.reason.customer_no_answer': 'Customer did not answer',
    'driver.failure.reason.wrong_address': 'Wrong address',
    'driver.failure.reason.customer_refused': 'Customer refused delivery',
    'driver.failure.reason.customer_absent': 'Customer not available',
    'driver.failure.reason.payment_issue': 'Payment issue',
    'driver.failure.reason.order_issue': 'Order issue',
    'driver.failure.reason.other': 'Other reason',
    'driver.failure.submit': 'Record failure',
    'driver.action.confirm_title': 'Confirm delivery status update',
    'driver.action.note_optional': 'Optional note',
    'driver.action.confirm': 'Confirm',
    'driver.proof.camera': 'Camera',
    'driver.proof.gallery': 'Gallery',
    'driver.proof.attached': 'Proof image attached',
    'driver.invoice.title': 'Invoice / receipt',
    'driver.invoice.open': 'View invoice',
    'driver.invoice.status': 'Invoice status',
    'driver.invoice.revision': 'Revision',
    'driver.invoice.issued_at': 'Issued at',
    'driver.invoice.subtotal': 'Subtotal',
    'driver.invoice.discount': 'Discount',
    'driver.invoice.delivery': 'Delivery',
    'driver.invoice.tax': 'Tax',
    'driver.invoice.total': 'Grand total',
    'driver.invoice.payment': 'Payment',
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
    'driver.payment_method.cash_on_delivery': 'Cash on delivery',
    'driver.payment_method.card': 'Card',
    'driver.payment_method.account_credit': 'Account credit',
    'driver.payment_method.wallet': 'Wallet',
    'driver.payment_status.pending': 'Payment pending',
    'driver.payment_status.paid': 'Paid',
    'driver.payment_status.failed': 'Payment failed',
    'driver.payment_status.refunded': 'Refunded',
    'driver.invoice_status.issued': 'Issued',
    'driver.invoice_status.paid': 'Paid',
    'driver.invoice_status.cancelled': 'Cancelled',
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
