import 'package:flutter/widgets.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/localization/app_translations.dart';

void main() {
  const keys = <String>[
    'b2b.store_analytics.launcher.title',
    'b2b.store_analytics.launcher.hint',
    'b2b.store_analytics.launcher.new_insights',
    'b2b.store_analytics.title',
    'b2b.store_analytics.subtitle',
    'b2b.store_analytics.store',
    'b2b.store_analytics.period',
    'b2b.store_analytics.period.current',
    'b2b.store_analytics.period.previous',
    'b2b.store_analytics.period.today',
    'b2b.store_analytics.period.7_days',
    'b2b.store_analytics.period.30_days',
    'b2b.store_analytics.currency',
    'b2b.store_analytics.currency.egp',
    'b2b.store_analytics.kpi.sales',
    'b2b.store_analytics.kpi.orders',
    'b2b.store_analytics.kpi.aov',
    'b2b.store_analytics.kpi.completed',
    'b2b.store_analytics.kpi.cancelled',
    'b2b.store_analytics.kpi.late',
    'b2b.store_analytics.kpi.change',
    'b2b.store_analytics.trend.title',
    'b2b.store_analytics.trend.sales',
    'b2b.store_analytics.trend.orders',
    'b2b.store_analytics.trend.empty',
    'b2b.store_analytics.insights.title',
    'b2b.store_analytics.insights.opportunity',
    'b2b.store_analytics.insights.risk',
    'b2b.store_analytics.insights.top_product',
    'b2b.store_analytics.insights.stock_attention',
    'b2b.store_analytics.insights.empty',
    'b2b.store_analytics.top_products.title',
    'b2b.store_analytics.top_products.contribution',
    'b2b.store_analytics.top_products.empty',
    'b2b.store_analytics.inventory.low_stock',
    'b2b.store_analytics.inventory.attention',
    'b2b.store_analytics.inventory.empty',
    'b2b.store_analytics.action.view_orders',
    'b2b.store_analytics.action.view_products',
    'b2b.store_analytics.action.view_inventory',
    'b2b.store_analytics.action.refresh',
    'b2b.store_analytics.action.back_dashboard',
    'b2b.store_analytics.state.loading',
    'b2b.store_analytics.state.empty',
    'b2b.store_analytics.state.error',
    'b2b.store_analytics.state.retry',
    'b2b.store_analytics.state.no_entitlement',
    'b2b.store_analytics.state.no_entitlement_hint',
    'b2b.store_analytics.state.stale',
    'b2b.store_analytics.state.stale_hint',
    'b2b.store_analytics.state.last_updated',
    'b2b.store_analytics.state.logo_fallback',
    'b2b.store_analytics.badge.new',
    'b2b.store_analytics.accessibility.open',
    'b2b.store_analytics.accessibility.new_insights',
  ];

  test('Store Analytics copy contract has complete AR and EN parity', () {
    const child = SizedBox.shrink();
    const ar = AppTranslations(
      locale: Locale('ar'),
      overrides: <String, String>{},
      child: child,
    );
    const en = AppTranslations(
      locale: Locale('en'),
      overrides: <String, String>{},
      child: child,
    );

    for (final key in keys) {
      expect(ar.text(key), isNot(key), reason: 'Missing Arabic key: $key');
      expect(en.text(key), isNot(key), reason: 'Missing English key: $key');
      expect(ar.text(key).trim(), isNotEmpty, reason: 'Empty Arabic: $key');
      expect(en.text(key).trim(), isNotEmpty, reason: 'Empty English: $key');
    }

    expect(ar.text('b2b.store_analytics.title'), 'تحليل المتجر الذكي');
    expect(en.text('b2b.store_analytics.title'), 'Smart Store Analytics');
    expect(ar.text('b2b.store_analytics.currency.egp'), 'ج.م');
    expect(en.text('b2b.store_analytics.currency.egp'), 'EGP');
  });
}
