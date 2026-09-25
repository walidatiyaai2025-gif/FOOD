import 'package:flutter/material.dart';

import '../../core/api/b2b_api.dart';
import '../../core/routing/customer_routes.dart';

class B2bJourneyScreen extends StatelessWidget {
  const B2bJourneyScreen({required this.definition, required this.location, this.api, super.key});

  final CustomerRouteDefinition definition;
  final String location;
  final B2bApi? api;

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
            if (api != null && _endpoint() != null) _RemoteState(api: api!, endpoint: _endpoint()!),
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

  String? _endpoint() {
    final uri = Uri.parse(location);
    final segments = uri.pathSegments;
    switch (definition.pattern) {
      case CustomerRoutePaths.b2bDashboard: return '/api/v1/b2b/dashboard';
      case CustomerRoutePaths.b2bPurchaseReports: return '/api/v1/b2b/reports/purchases';
      case CustomerRoutePaths.b2bTopProducts:
      case CustomerRoutePaths.b2bProducts: return '/api/v1/b2b/products${uri.hasQuery ? '?${uri.query}' : ''}';
      case CustomerRoutePaths.b2bInvoices: return '/api/v1/b2b/invoices';
      case CustomerRoutePaths.b2bInvoiceDetails: return '/api/v1/b2b/invoices/${segments.last}';
      case CustomerRoutePaths.b2bAccountStatement: return '/api/v1/b2b/account-statement';
      case CustomerRoutePaths.b2bOrders: return '/api/v1/b2b/orders';
      case CustomerRoutePaths.b2bOrderDetails: return '/api/v1/b2b/orders/${segments.last}';
      case CustomerRoutePaths.b2bCart: return '/api/v1/cart${uri.hasQuery ? '?${uri.query}' : ''}';
      case CustomerRoutePaths.b2bProfile: return '/api/v1/profile';
      default: return null;
    }
  }

  Widget _button(String label) => Padding(padding: const EdgeInsets.only(bottom: 12), child: FilledButton(onPressed: () {}, child: Text(label)));
  Widget _section(String label) => Card(child: ListTile(title: Text(label), trailing: const Icon(Icons.chevron_right)));
  Widget _empty(String label) => Card(child: Padding(padding: const EdgeInsets.all(24), child: Center(child: Text(label))));
}


class _RemoteState extends StatelessWidget {
  const _RemoteState({required this.api, required this.endpoint});
  final B2bApi api;
  final String endpoint;

  @override
  Widget build(BuildContext context) => FutureBuilder<Object?>(
    future: api.get(endpoint),
    builder: (context, snapshot) {
      if (snapshot.connectionState != ConnectionState.done) return const Center(key: ValueKey('b2b-loading'), child: CircularProgressIndicator());
      if (snapshot.hasError) return const Card(key: ValueKey('b2b-error'), child: Padding(padding: EdgeInsets.all(16), child: Text('تعذر تحميل البيانات. حاول مرة أخرى.')));
      final value = snapshot.data;
      final empty = value == null || (value is List && value.isEmpty) || (value is Map && value['data'] is List && (value['data'] as List).isEmpty);
      if (empty) return const Card(key: ValueKey('b2b-empty'), child: Padding(padding: EdgeInsets.all(16), child: Text('لا توجد بيانات')));
      return const Card(key: ValueKey('b2b-loaded'), child: Padding(padding: EdgeInsets.all(16), child: Text('تم تحميل البيانات من فودكس')));
    },
  );
}
