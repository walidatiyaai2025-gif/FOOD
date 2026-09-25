import 'dart:convert';

import 'package:flutter/widgets.dart';
import 'package:http/http.dart' as http;

typedef TranslationFetcher = Future<Map<String, String>> Function(String locale);

class AppTranslations extends InheritedWidget {
  const AppTranslations({
    required this.locale,
    required this.overrides,
    required super.child,
    super.key,
  });

  final Locale locale;
  final Map<String, String> overrides;

  static const Map<String, String> _ar = {
    'customer.app.title': 'فودكس',
    'customer.nav.home': 'الرئيسية',
    'customer.nav.products': 'المنتجات',
    'customer.nav.cart': 'السلة',
    'customer.nav.profile': 'حسابي',
    'customer.splash.title': 'فودكس',
    'customer.splash.subtitle': 'كل احتياجاتك في مكان واحد',
    'customer.entry.title': 'مرحباً بك',
    'customer.entry.subtitle': 'تصفح كضيف أو سجل الدخول لإتمام الطلب',
    'customer.action.guest': 'تصفح كضيف',
    'customer.action.login': 'تسجيل الدخول',
    'customer.action.add_cart': 'إضافة إلى السلة',
    'customer.action.checkout': 'متابعة الشراء',
    'customer.action.confirm_order': 'تأكيد الطلب',
    'customer.store.title': 'اختر المتجر',
    'customer.store.subtitle': 'المتاجر المتاحة حسب موقع الخدمة',
    'customer.store.empty': 'اختر متجراً للمتابعة',
    'customer.home.title': 'الرئيسية',
    'customer.home.subtitle': 'العروض والأقسام والمنتجات المتاحة',
    'customer.home.offers': 'العروض',
    'customer.home.categories': 'الأقسام',
    'customer.home.popular': 'الأكثر طلباً',
    'customer.offers.title': 'العروض',
    'customer.offers.subtitle': 'العروض الفعالة في المتجر المحدد',
    'customer.offers.empty': 'لا توجد عروض حالياً',
    'customer.products.title': 'المنتجات',
    'customer.products.subtitle': 'بحث وتصفية المنتجات',
    'customer.products.search': 'ابحث عن منتج',
    'customer.products.empty': 'اختر قسماً أو ابدأ البحث',
    'customer.product.title': 'تفاصيل المنتج',
    'customer.product.subtitle': 'السعر والتوفر والكمية من الخادم',
    'customer.cart.title': 'السلة',
    'customer.cart.subtitle': 'الأسعار والتوفر يعاد التحقق منهما قبل الدفع',
    'customer.cart.empty': 'السلة فارغة',
    'customer.checkout_login.title': 'تسجيل الدخول',
    'customer.checkout_login.subtitle': 'يلزم تسجيل الدخول لإتمام الطلب',
    'customer.checkout.title': 'العنوان والدفع',
    'customer.checkout.subtitle': 'اختر عنوان التوصيل وطريقة الدفع',
    'customer.checkout.address': 'عنوان التوصيل',
    'customer.checkout.payment': 'طريقة الدفع',
    'customer.tracking.title': 'تتبع الطلب',
    'customer.tracking.subtitle': 'حالة الطلب وتحديثات التوصيل',
    'customer.tracking.received': 'تم استلام الطلب',
    'customer.tracking.preparing': 'قيد التجهيز',
    'customer.tracking.on_way': 'في الطريق',
    'customer.profile.title': 'حسابي',
    'customer.profile.subtitle': 'الملف الشخصي والعناوين والمفضلة والطلبات',
    'customer.profile.addresses': 'العناوين',
    'customer.profile.favorites': 'المفضلة',
    'customer.profile.orders': 'طلباتي',
    'customer.empty': 'لا توجد بيانات',
    'b2b.app.title': 'فودكس للأعمال',
    'b2b.login.title': 'دخول عميل الأعمال',
    'b2b.login.subtitle': 'الحسابات تُنشأ وتُعتمد من لوحة الإدارة فقط',
    'b2b.dashboard.title': 'لوحة الأعمال',
    'b2b.dashboard.subtitle': 'ملخص المشتريات والفواتير والرصيد',
    'b2b.purchase_reports.title': 'تقارير المشتريات',
    'b2b.purchase_reports.subtitle': 'تقارير حساب الأعمال من الخادم',
    'b2b.top_products.title': 'الأكثر شراءً',
    'b2b.top_products.subtitle': 'المنتجات الأعلى شراءً للحساب',
    'b2b.products.title': 'منتجات الجملة',
    'b2b.products.subtitle': 'الأسعار والكميات الدنيا حسب الحساب المعتمد',
    'b2b.product.title': 'تفاصيل منتج الجملة',
    'b2b.product.subtitle': 'السعر والحد الأدنى للطلب محسوبان من الخادم',
    'b2b.minimum_order': 'الحد الأدنى للطلب',
    'b2b.invoices.title': 'الفواتير',
    'b2b.invoices.subtitle': 'فواتير حساب الأعمال',
    'b2b.invoice.title': 'تفاصيل الفاتورة',
    'b2b.invoice.subtitle': 'تفاصيل الفاتورة وحالة السداد',
    'b2b.invoice.items': 'بنود الفاتورة',
    'b2b.invoice.payment_status': 'حالة السداد',
    'b2b.statement.title': 'كشف الحساب',
    'b2b.statement.subtitle': 'الرصيد والحركات المالية للحساب',
    'b2b.orders.title': 'طلبات الأعمال',
    'b2b.orders.subtitle': 'طلبات الجملة وحالاتها',
    'b2b.order.title': 'تفاصيل الطلب',
    'b2b.order.subtitle': 'تفاصيل الطلب والتتبع',
    'b2b.order.status': 'حالة الطلب',
    'b2b.order.tracking': 'التتبع',
    'b2b.cart.title': 'سلة الجملة',
    'b2b.cart.subtitle': 'الأسعار والحد الأدنى والتوفر يعاد التحقق منها من الخادم',
    'b2b.cart.empty': 'السلة فارغة',
    'b2b.action.checkout': 'إتمام الطلب',
    'b2b.profile.title': 'حساب الأعمال',
    'b2b.profile.subtitle': 'بيانات الشركة والإعدادات',
    'b2b.profile.company': 'بيانات الشركة',
    'b2b.profile.settings': 'الإعدادات',
    'b2b.finance.purchases': 'المشتريات',
    'b2b.finance.invoices': 'الفواتير',
    'b2b.finance.statement': 'كشف الحساب',
    'b2b.empty.period': 'لا توجد بيانات للفترة المحددة',
    'b2b.empty.purchases': 'لا توجد مشتريات بعد',
    'b2b.empty.products': 'لا توجد منتجات متاحة',
    'b2b.empty.invoices': 'لا توجد فواتير',
    'b2b.empty.statement': 'لا توجد حركات مالية',
    'b2b.empty.orders': 'لا توجد طلبات',
    'b2b.remote.error': 'تعذر تحميل البيانات. حاول مرة أخرى.',
    'b2b.remote.empty': 'لا توجد بيانات',
    'b2b.remote.loaded': 'تم تحميل البيانات من فودكس',
  };

  static const Map<String, String> _en = {
    'customer.app.title': 'FOODEX',
    'customer.nav.home': 'Home',
    'customer.nav.products': 'Products',
    'customer.nav.cart': 'Cart',
    'customer.nav.profile': 'My account',
    'customer.splash.title': 'FOODEX',
    'customer.splash.subtitle': 'Everything you need in one place',
    'customer.entry.title': 'Welcome',
    'customer.entry.subtitle': 'Browse as a guest or sign in to complete checkout',
    'customer.action.guest': 'Browse as guest',
    'customer.action.login': 'Sign in',
    'customer.action.add_cart': 'Add to cart',
    'customer.action.checkout': 'Continue to checkout',
    'customer.action.confirm_order': 'Confirm order',
    'customer.store.title': 'Choose a store',
    'customer.store.subtitle': 'Stores available for your service area',
    'customer.store.empty': 'Choose a store to continue',
    'customer.home.title': 'Home',
    'customer.home.subtitle': 'Available offers, categories and products',
    'customer.home.offers': 'Offers',
    'customer.home.categories': 'Categories',
    'customer.home.popular': 'Most ordered',
    'customer.offers.title': 'Offers',
    'customer.offers.subtitle': 'Active offers in the selected store',
    'customer.offers.empty': 'No offers are available right now',
    'customer.products.title': 'Products',
    'customer.products.subtitle': 'Search and filter products',
    'customer.products.search': 'Search products',
    'customer.products.empty': 'Choose a category or start searching',
    'customer.product.title': 'Product details',
    'customer.product.subtitle': 'Price, availability and quantity come from the server',
    'customer.cart.title': 'Cart',
    'customer.cart.subtitle': 'Price and availability are rechecked before payment',
    'customer.cart.empty': 'Your cart is empty',
    'customer.checkout_login.title': 'Sign in',
    'customer.checkout_login.subtitle': 'Sign in is required to complete checkout',
    'customer.checkout.title': 'Address and payment',
    'customer.checkout.subtitle': 'Choose a delivery address and payment method',
    'customer.checkout.address': 'Delivery address',
    'customer.checkout.payment': 'Payment method',
    'customer.tracking.title': 'Order tracking',
    'customer.tracking.subtitle': 'Order status and delivery updates',
    'customer.tracking.received': 'Order received',
    'customer.tracking.preparing': 'Preparing',
    'customer.tracking.on_way': 'On the way',
    'customer.profile.title': 'My account',
    'customer.profile.subtitle': 'Profile, addresses, favorites and orders',
    'customer.profile.addresses': 'Addresses',
    'customer.profile.favorites': 'Favorites',
    'customer.profile.orders': 'My orders',
    'customer.empty': 'No data available',
    'b2b.app.title': 'FOODEX Business',
    'b2b.login.title': 'Business customer sign in',
    'b2b.login.subtitle': 'Accounts are created and approved from the management dashboard only',
    'b2b.dashboard.title': 'Business dashboard',
    'b2b.dashboard.subtitle': 'Purchases, invoices and balance summary',
    'b2b.purchase_reports.title': 'Purchase reports',
    'b2b.purchase_reports.subtitle': 'Business account reports from the server',
    'b2b.top_products.title': 'Top purchased products',
    'b2b.top_products.subtitle': 'Most purchased products for the account',
    'b2b.products.title': 'Wholesale products',
    'b2b.products.subtitle': 'Pricing and minimum quantities for the approved account',
    'b2b.product.title': 'Wholesale product details',
    'b2b.product.subtitle': 'Price and minimum order are calculated by the server',
    'b2b.minimum_order': 'Minimum order',
    'b2b.invoices.title': 'Invoices',
    'b2b.invoices.subtitle': 'Business account invoices',
    'b2b.invoice.title': 'Invoice details',
    'b2b.invoice.subtitle': 'Invoice details and payment status',
    'b2b.invoice.items': 'Invoice items',
    'b2b.invoice.payment_status': 'Payment status',
    'b2b.statement.title': 'Account statement',
    'b2b.statement.subtitle': 'Account balance and financial transactions',
    'b2b.orders.title': 'Business orders',
    'b2b.orders.subtitle': 'Wholesale orders and their statuses',
    'b2b.order.title': 'Order details',
    'b2b.order.subtitle': 'Order details and tracking',
    'b2b.order.status': 'Order status',
    'b2b.order.tracking': 'Tracking',
    'b2b.cart.title': 'Wholesale cart',
    'b2b.cart.subtitle': 'Prices, minimums and availability are rechecked by the server',
    'b2b.cart.empty': 'The cart is empty',
    'b2b.action.checkout': 'Complete order',
    'b2b.profile.title': 'Business account',
    'b2b.profile.subtitle': 'Company details and settings',
    'b2b.profile.company': 'Company details',
    'b2b.profile.settings': 'Settings',
    'b2b.finance.purchases': 'Purchases',
    'b2b.finance.invoices': 'Invoices',
    'b2b.finance.statement': 'Account statement',
    'b2b.empty.period': 'No data for the selected period',
    'b2b.empty.purchases': 'No purchases yet',
    'b2b.empty.products': 'No products available',
    'b2b.empty.invoices': 'No invoices',
    'b2b.empty.statement': 'No financial transactions',
    'b2b.empty.orders': 'No orders',
    'b2b.remote.error': 'Unable to load data. Please try again.',
    'b2b.remote.empty': 'No data available',
    'b2b.remote.loaded': 'Data loaded from FOODEX',
  };

  String text(String key) {
    final defaults = locale.languageCode == 'en' ? _en : _ar;
    return overrides[key] ?? defaults[key] ?? key;
  }

  static AppTranslations? maybeOf(BuildContext context) {
    return context.dependOnInheritedWidgetOfExactType<AppTranslations>();
  }

  static String fallback(BuildContext context, String key) {
    final locale = Localizations.maybeLocaleOf(context) ?? const Locale('ar');
    final defaults = locale.languageCode == 'en' ? _en : _ar;
    return defaults[key] ?? key;
  }

  @override
  bool updateShouldNotify(AppTranslations oldWidget) {
    return oldWidget.locale != locale || oldWidget.overrides != overrides;
  }
}

extension TranslationContext on BuildContext {
  String tr(String key) => AppTranslations.maybeOf(this)?.text(key) ?? AppTranslations.fallback(this, key);
}

Future<Map<String, String>> fetchTranslationBundle(String baseUrl, String locale) async {
  final response = await http.get(Uri.parse('$baseUrl/api/v1/translations/$locale'), headers: const {'Accept': 'application/json'});
  if (response.statusCode < 200 || response.statusCode >= 300) {
    return const {};
  }

  final decoded = jsonDecode(response.body);
  if (decoded is! Map<String, dynamic> || decoded['translations'] is! Map) {
    return const {};
  }

  return (decoded['translations'] as Map).map((key, value) => MapEntry(key.toString(), value.toString()));
}
