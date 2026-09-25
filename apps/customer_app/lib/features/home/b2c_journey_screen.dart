import 'package:flutter/material.dart';

import '../../core/routing/customer_routes.dart';

class B2cJourneyScreen extends StatelessWidget {
  const B2cJourneyScreen({required this.definition, required this.location, super.key});
  final CustomerRouteDefinition definition;
  final String location;

  @override
  Widget build(BuildContext context) {
    final rtl = Directionality.of(context) == TextDirection.rtl;
    final content = _contentFor(definition.pattern);
    return Scaffold(
      appBar: AppBar(title: Text(rtl ? 'فودكس' : 'FOODEX')),
      bottomNavigationBar: definition.pattern == CustomerRoutePaths.home || definition.pattern == CustomerRoutePaths.products || definition.pattern == CustomerRoutePaths.cart || definition.pattern == CustomerRoutePaths.profile
          ? NavigationBar(destinations: const [NavigationDestination(icon: Icon(Icons.home_outlined), label: 'الرئيسية'), NavigationDestination(icon: Icon(Icons.grid_view_outlined), label: 'المنتجات'), NavigationDestination(icon: Icon(Icons.shopping_cart_outlined), label: 'السلة'), NavigationDestination(icon: Icon(Icons.person_outline), label: 'حسابي')]) : null,
      body: SafeArea(child: ListView(padding: const EdgeInsets.all(20), children: [Text(content.$1, key: const ValueKey('customer-route-label'), style: Theme.of(context).textTheme.headlineSmall), const SizedBox(height: 8), Text(content.$2), const SizedBox(height: 20), ...content.$3, Text(location, key: const ValueKey('customer-route-location'), style: Theme.of(context).textTheme.labelSmall)])),
    );
  }

  (String, String, List<Widget>) _contentFor(String pattern) {
    switch (pattern) {
      case CustomerRoutePaths.splash: return ('فودكس', 'كل احتياجاتك في مكان واحد', [const LinearProgressIndicator()]);
      case CustomerRoutePaths.entry: return ('مرحباً بك', 'تصفح كضيف أو سجل الدخول لإتمام الطلب', [_button('تصفح كضيف'), _button('تسجيل الدخول')]);
      case CustomerRoutePaths.stores: return ('اختر المتجر', 'المتاجر المتاحة حسب موقع الخدمة', [_empty('اختر متجراً للمتابعة')]);
      case CustomerRoutePaths.home: return ('الرئيسية', 'العروض والأقسام والمنتجات المتاحة', [_section('العروض'), _section('الأقسام'), _section('الأكثر طلباً')]);
      case CustomerRoutePaths.offers: return ('العروض', 'العروض الفعالة في المتجر المحدد', [_empty('لا توجد عروض حالياً')]);
      case CustomerRoutePaths.products: return ('المنتجات', 'بحث وتصفية المنتجات', [const SearchBar(hintText: 'ابحث عن منتج'), _empty('اختر قسماً أو ابدأ البحث')]);
      case CustomerRoutePaths.productDetails: return ('تفاصيل المنتج', 'السعر والتوفر والكمية من الخادم', [_button('إضافة إلى السلة')]);
      case CustomerRoutePaths.cart: return ('السلة', 'الأسعار والتوفر يعاد التحقق منهما قبل الدفع', [_empty('السلة فارغة'), _button('متابعة الشراء')]);
      case CustomerRoutePaths.checkoutAuth: return ('تسجيل الدخول', 'يلزم تسجيل الدخول لإتمام الطلب', [_button('تسجيل الدخول')]);
      case CustomerRoutePaths.checkoutAddressPayment: return ('العنوان والدفع', 'اختر عنوان التوصيل وطريقة الدفع', [_section('عنوان التوصيل'), _section('طريقة الدفع'), _button('تأكيد الطلب')]);
      case CustomerRoutePaths.orderTracking: return ('تتبع الطلب', 'حالة الطلب وتحديثات التوصيل', [_section('تم استلام الطلب'), _section('قيد التجهيز'), _section('في الطريق')]);
      case CustomerRoutePaths.profile: return ('حسابي', 'الملف الشخصي والعناوين والمفضلة والطلبات', [_section('العناوين'), _section('المفضلة'), _section('طلباتي')]);
      default: return (definition.label, 'FOODEX Customer', [_empty('لا توجد بيانات')]);
    }
  }

  Widget _button(String label) => Padding(padding: const EdgeInsets.only(bottom: 12), child: FilledButton(onPressed: () {}, child: Text(label)));
  Widget _section(String label) => Card(child: ListTile(title: Text(label), trailing: const Icon(Icons.chevron_right)));
  Widget _empty(String label) => Card(child: Padding(padding: const EdgeInsets.all(24), child: Center(child: Text(label))));
}
