import 'package:flutter/material.dart';

import '../../core/routing/customer_routes.dart';

class B2bJourneyScreen extends StatelessWidget {
  const B2bJourneyScreen({required this.definition, required this.location, super.key});

  final CustomerRouteDefinition definition;
  final String location;

  @override
  Widget build(BuildContext context) {
    final rtl = Directionality.of(context) == TextDirection.rtl;
    final content = _contentFor(definition.pattern);
    return Scaffold(
      appBar: AppBar(title: Text(rtl ? 'فودكس للأعمال' : 'FOODEX Business')),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.all(20),
          children: [
            Text(content.$1, key: const ValueKey('customer-route-label'), style: Theme.of(context).textTheme.headlineSmall),
            const SizedBox(height: 8),
            Text(content.$2),
            const SizedBox(height: 20),
            ...content.$3,
            Text(location, key: const ValueKey('customer-route-location'), style: Theme.of(context).textTheme.labelSmall),
          ],
        ),
      ),
    );
  }

  (String, String, List<Widget>) _contentFor(String pattern) {
    switch (pattern) {
      case CustomerRoutePaths.b2bLogin: return ('دخول عميل الأعمال', 'الحسابات تُنشأ وتُعتمد من لوحة الإدارة فقط', [_button('تسجيل الدخول')]);
      case CustomerRoutePaths.b2bDashboard: return ('لوحة الأعمال', 'ملخص المشتريات والفواتير والرصيد', [_section('المشتريات'), _section('الفواتير'), _section('كشف الحساب')]);
      case CustomerRoutePaths.b2bPurchaseReports: return ('تقارير المشتريات', 'تقارير حساب الأعمال من الخادم', [_empty('لا توجد بيانات للفترة المحددة')]);
      case CustomerRoutePaths.b2bTopProducts: return ('الأكثر شراءً', 'المنتجات الأعلى شراءً للحساب', [_empty('لا توجد مشتريات بعد')]);
      case CustomerRoutePaths.b2bProducts: return ('منتجات الجملة', 'الأسعار والكميات الدنيا حسب الحساب المعتمد', [const SearchBar(hintText: 'ابحث عن منتج'), _empty('لا توجد منتجات متاحة')]);
      case CustomerRoutePaths.b2bProductDetails: return ('تفاصيل منتج الجملة', 'السعر والحد الأدنى للطلب محسوبان من الخادم', [_button('إضافة إلى السلة')]);
      case CustomerRoutePaths.b2bInvoices: return ('الفواتير', 'فواتير حساب الأعمال', [_empty('لا توجد فواتير')]);
      case CustomerRoutePaths.b2bInvoiceDetails: return ('تفاصيل الفاتورة', 'تفاصيل الفاتورة وحالة السداد', [_section('بنود الفاتورة'), _section('حالة السداد')]);
      case CustomerRoutePaths.b2bAccountStatement: return ('كشف الحساب', 'الرصيد والحركات المالية للحساب', [_empty('لا توجد حركات مالية')]);
      case CustomerRoutePaths.b2bOrders: return ('طلبات الأعمال', 'طلبات الجملة وحالاتها', [_empty('لا توجد طلبات')]);
      case CustomerRoutePaths.b2bOrderDetails: return ('تفاصيل الطلب', 'تفاصيل الطلب والتتبع', [_section('حالة الطلب'), _section('التتبع')]);
      case CustomerRoutePaths.b2bCart: return ('سلة الجملة', 'الأسعار والحد الأدنى والتوفر يعاد التحقق منها من الخادم', [_empty('السلة فارغة'), _button('إتمام الطلب')]);
      case CustomerRoutePaths.b2bProfile: return ('حساب الأعمال', 'بيانات الشركة والإعدادات', [_section('بيانات الشركة'), _section('الإعدادات')]);
      default: return (definition.label, 'FOODEX Business', [_empty('لا توجد بيانات')]);
    }
  }

  Widget _button(String label) => Padding(padding: const EdgeInsets.only(bottom: 12), child: FilledButton(onPressed: () {}, child: Text(label)));
  Widget _section(String label) => Card(child: ListTile(title: Text(label), trailing: const Icon(Icons.chevron_right)));
  Widget _empty(String label) => Card(child: Padding(padding: const EdgeInsets.all(24), child: Center(child: Text(label))));
}
