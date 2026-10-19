import 'package:flutter/material.dart';
import 'package:foodex_formatting/foodex_formatting.dart';
import 'package:foodex_visualization/foodex_visualization.dart';
import '../storefront/storefront_design_system.dart';

import '../../core/api/store_analytics_api.dart' as analytics_api;
import '../../core/localization/app_translations.dart';
import '../../shared/customer_ui_v3/customer_ui_v3.dart';

enum StoreAnalyticsStatus { loading, loaded, empty, error, noEntitlement }

StoreAnalyticsStatus storeAnalyticsStatusFromConsumerState(
  analytics_api.StoreAnalyticsConsumerState state,
) {
  return switch (state.status) {
    analytics_api.StoreAnalyticsConsumerStatus.loading =>
      StoreAnalyticsStatus.loading,
    analytics_api.StoreAnalyticsConsumerStatus.ready ||
    analytics_api.StoreAnalyticsConsumerStatus.stale =>
      StoreAnalyticsStatus.loaded,
    analytics_api.StoreAnalyticsConsumerStatus.empty =>
      StoreAnalyticsStatus.empty,
    analytics_api.StoreAnalyticsConsumerStatus.noEntitlement =>
      StoreAnalyticsStatus.noEntitlement,
    analytics_api.StoreAnalyticsConsumerStatus.error =>
      StoreAnalyticsStatus.error,
  };
}

enum StoreAnalyticsInsightTone { positive, warning, neutral }

class StoreAnalyticsCopy {
  const StoreAnalyticsCopy({
    required this.title,
    required this.revenue,
    required this.orders,
    required this.averageOrderValue,
    required this.comparison,
    required this.vsPrevious,
    required this.noComparison,
    required this.salesTrend,
    required this.ordersTrend,
    required this.trendSummary,
    required this.insights,
    required this.currencyEgp,
    required this.stale,
    required this.storeSelector,
    required this.loadingLabel,
    required this.emptyTitle,
    required this.emptyMessage,
    required this.errorTitle,
    required this.errorMessage,
    required this.noEntitlementTitle,
    required this.noEntitlementMessage,
    required this.retry,
    required this.refresh,
    required this.storeLogo,
    required this.ordersUnit,
    this.lastUpdated = '',
    this.cancelledOrders = '',
    this.stockAttention = '',
    this.topProduct = '',
    this.viewOrders = '',
    this.viewProducts = '',
  });

  final String title;
  final String revenue;
  final String orders;
  final String averageOrderValue;
  final String comparison;
  final String vsPrevious;
  final String noComparison;
  final String salesTrend;
  final String ordersTrend;
  final String trendSummary;
  final String insights;
  final String currencyEgp;
  final String stale;
  final String storeSelector;
  final String loadingLabel;
  final String emptyTitle;
  final String emptyMessage;
  final String errorTitle;
  final String errorMessage;
  final String noEntitlementTitle;
  final String noEntitlementMessage;
  final String retry;
  final String refresh;
  final String storeLogo;
  final String ordersUnit;
  final String lastUpdated;
  final String cancelledOrders;
  final String stockAttention;
  final String topProduct;
  final String viewOrders;
  final String viewProducts;

  factory StoreAnalyticsCopy.fromContext(
    BuildContext context,
  ) =>
      StoreAnalyticsCopy(
        title: context.tr('b2b.store_analytics.title'),
        revenue: context.tr('b2b.store_analytics.kpi.sales'),
        orders: context.tr('b2b.store_analytics.kpi.orders'),
        averageOrderValue: context.tr('b2b.store_analytics.kpi.aov'),
        comparison: context.tr('b2b.store_analytics.kpi.change'),
        vsPrevious: context.tr('b2b.store_analytics.period.previous'),
        noComparison: '—',
        salesTrend: context.tr('b2b.store_analytics.trend.title'),
        ordersTrend: context.tr('b2b.store_analytics.trend.orders'),
        trendSummary: context.tr('b2b.store_analytics.trend.title'),
        insights: context.tr('b2b.store_analytics.insights.title'),
        currencyEgp: context.tr('b2b.store_analytics.currency.egp'),
        stale:
            '${context.tr('b2b.store_analytics.state.stale')} ${context.tr('b2b.store_analytics.state.stale_hint')}',
        storeSelector: context.tr('b2b.store_analytics.store'),
        loadingLabel: context.tr('b2b.store_analytics.state.loading'),
        emptyTitle: context.tr('b2b.store_analytics.state.empty'),
        emptyMessage: context.tr('b2b.store_analytics.trend.empty'),
        errorTitle: context.tr('b2b.store_analytics.state.error'),
        errorMessage: context.tr('customer.error.offline'),
        noEntitlementTitle:
            context.tr('b2b.store_analytics.state.no_entitlement'),
        noEntitlementMessage: context.tr(
          'b2b.store_analytics.state.no_entitlement_hint',
        ),
        retry: context.tr('b2b.store_analytics.state.retry'),
        refresh: context.tr('b2b.store_analytics.action.refresh'),
        storeLogo: context.tr('b2b.store_analytics.store'),
        ordersUnit: context.tr('b2b.store_analytics.kpi.orders'),
        lastUpdated: context.tr('b2b.store_analytics.state.last_updated'),
        cancelledOrders: context.tr('b2b.store_analytics.kpi.cancelled'),
        stockAttention:
            context.tr('b2b.store_analytics.insights.stock_attention'),
        topProduct: context.tr('b2b.store_analytics.insights.top_product'),
        viewOrders: context.tr('b2b.store_analytics.action.view_orders'),
        viewProducts: context.tr('b2b.store_analytics.action.view_products'),
      );
}

class StoreAnalyticsStoreOption {
  const StoreAnalyticsStoreOption({required this.id, required this.name});

  final int id;
  final String name;
}

class StoreAnalyticsDailyPoint {
  const StoreAnalyticsDailyPoint({
    required this.dateLabel,
    required this.sales,
    required this.orders,
  });

  final String dateLabel;
  final double sales;
  final int orders;
}

class StoreAnalyticsInsight {
  const StoreAnalyticsInsight({
    required this.id,
    required this.title,
    required this.body,
    this.tone = StoreAnalyticsInsightTone.neutral,
    this.actionLabel,
    this.targetRoute,
  });

  final String id;
  final String title;
  final String body;
  final StoreAnalyticsInsightTone tone;
  final String? actionLabel;
  final String? targetRoute;
}

class StoreAnalyticsData {
  const StoreAnalyticsData({
    required this.storeId,
    required this.storeName,
    required this.currency,
    required this.periodLabel,
    required this.freshnessLabel,
    required this.revenue,
    required this.orders,
    required this.averageOrderValue,
    required this.dailySeries,
    required this.insights,
    this.logoUrl,
    this.revenueDeltaPercent,
    this.isStale = false,
  });

  final int storeId;
  final String storeName;
  final String? logoUrl;
  final String currency;
  final String periodLabel;
  final String freshnessLabel;
  final double revenue;
  final int orders;
  final double averageOrderValue;
  final double? revenueDeltaPercent;
  final List<StoreAnalyticsDailyPoint> dailySeries;
  final List<StoreAnalyticsInsight> insights;
  final bool isStale;
}

StoreAnalyticsData storeAnalyticsDataFromReadModel(
  analytics_api.StoreAnalyticsReadModel model, {
  required StoreAnalyticsCopy copy,
}) {
  if (model.store.currency != 'EGP') {
    throw StateError(
      'Store Analytics requires the authoritative EGP currency contract.',
    );
  }

  final sourceUpdatedAt =
      model.freshness.sourceUpdatedAt ?? model.freshness.computedAt;
  final freshnessLabel = copy.lastUpdated.isEmpty
      ? sourceUpdatedAt
      : '${copy.lastUpdated}: $sourceUpdatedAt';

  final insights = model.insights
      .map(
        (insight) => _storeAnalyticsInsightFromReadModel(
          insight,
          retailStoreId: model.store.id,
          copy: copy,
        ),
      )
      .whereType<StoreAnalyticsInsight>()
      .toList(growable: false);

  return StoreAnalyticsData(
    storeId: model.store.id,
    storeName: model.store.name,
    logoUrl: model.store.logoUrl,
    currency: model.store.currency,
    periodLabel: '${model.period.from} – ${model.period.to}',
    freshnessLabel: freshnessLabel,
    revenue: model.metrics.revenue.value,
    orders: model.metrics.orders.value.toInt(),
    averageOrderValue: model.metrics.averageOrderValue.value,
    revenueDeltaPercent: model.metrics.revenue.deltaPercent,
    dailySeries: model.dailySeries
        .map(
          (point) => StoreAnalyticsDailyPoint(
            dateLabel: point.date,
            sales: point.sales,
            orders: point.orders,
          ),
        )
        .toList(growable: false),
    insights: insights,
    isStale: model.isStale,
  );
}

StoreAnalyticsInsight? _storeAnalyticsInsightFromReadModel(
  analytics_api.StoreAnalyticsInsight insight, {
  required int retailStoreId,
  required StoreAnalyticsCopy copy,
}) {
  final facts = insight.facts;
  final tone = switch (insight.severity) {
    'positive' => StoreAnalyticsInsightTone.positive,
    'warning' => StoreAnalyticsInsightTone.warning,
    _ => StoreAnalyticsInsightTone.neutral,
  };

  switch (insight.code) {
    case 'cancelled_orders_attention':
      final cancelled = facts.cancelledOrders ?? 0;
      final total = facts.orderCount;
      return StoreAnalyticsInsight(
        id: insight.id,
        title:
            copy.cancelledOrders.isEmpty ? copy.orders : copy.cancelledOrders,
        body: total == null
            ? '$cancelled ${copy.ordersUnit}'
            : '$cancelled / $total ${copy.ordersUnit}',
        tone: tone,
        actionLabel: copy.viewOrders.isEmpty ? null : copy.viewOrders,
        targetRoute: '/orders?channel=retail&store_id=$retailStoreId',
      );
    case 'stock_attention_required':
      final lowStock = facts.lowStockCount ?? 0;
      final reorderNow = facts.reorderNowCount ?? 0;
      return StoreAnalyticsInsight(
        id: insight.id,
        title:
            copy.stockAttention.isEmpty ? copy.insights : copy.stockAttention,
        body: '$lowStock · $reorderNow',
        tone: tone,
        actionLabel: copy.viewProducts.isEmpty ? null : copy.viewProducts,
        targetRoute: '/products?channel=retail&store_id=$retailStoreId',
      );
    case 'revenue_increased':
    case 'revenue_decreased':
    case 'revenue_flat':
      return StoreAnalyticsInsight(
        id: insight.id,
        title: copy.comparison,
        body: '${_displayPercent(facts.deltaPercent)} ${copy.vsPrevious}',
        tone: tone,
      );
    case 'top_product_contribution':
      final productId = facts.productId;
      final name = facts.productName ?? copy.topProduct;
      return StoreAnalyticsInsight(
        id: insight.id,
        title: copy.topProduct.isEmpty ? copy.insights : copy.topProduct,
        body: '$name · ${_displayPercent(facts.contributionPercent)}',
        tone: tone,
        actionLabel: productId == null || copy.viewProducts.isEmpty
            ? null
            : copy.viewProducts,
        targetRoute: productId == null
            ? null
            : '/products/$productId?channel=retail&store_id=$retailStoreId',
      );
    default:
      return null;
  }
}

String _displayPercent(double? value) {
  if (value == null) {
    return '—';
  }
  final whole = value == value.roundToDouble();
  return '${value.toStringAsFixed(whole ? 0 : 1)}%';
}

class StoreAnalyticsScreen extends StatelessWidget {
  const StoreAnalyticsScreen({
    required this.copy,
    required this.status,
    this.data,
    this.storeOptions = const <StoreAnalyticsStoreOption>[],
    this.selectedStoreId,
    this.onStoreChanged,
    this.onBack,
    this.onRetry,
    this.onRefresh,
    this.onDrillDown,
    super.key,
  }) : assert(
          status != StoreAnalyticsStatus.loaded || data != null,
          'Loaded Store Analytics requires data.',
        );

  final StoreAnalyticsCopy copy;
  final StoreAnalyticsStatus status;
  final StoreAnalyticsData? data;
  final List<StoreAnalyticsStoreOption> storeOptions;
  final int? selectedStoreId;
  final ValueChanged<int>? onStoreChanged;
  final VoidCallback? onBack;
  final VoidCallback? onRetry;
  final Future<void> Function()? onRefresh;
  final ValueChanged<String>? onDrillDown;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      key: const ValueKey('store-analytics-screen'),
      backgroundColor: CustomerUiColors.mint,
      body: FoodexUnifiedHeaderSurface(
        title: copy.title,
        searchHint: '',
        trailingActions: [
          IconButton(
            key: const ValueKey('store-analytics-refresh'),
            icon: const Icon(Icons.refresh_rounded, color: Colors.white),
            tooltip: copy.refresh,
            onPressed: onRefresh == null ? null : () => onRefresh!(),
          ),
        ],
        child: _body(context),
      ),
    );
  }

  Widget _body(BuildContext context) {
    return switch (status) {
      StoreAnalyticsStatus.loading => _LoadingState(copy: copy),
      StoreAnalyticsStatus.empty => _StateList(
          child: CustomerStateView(
            kind: CustomerStateKind.empty,
            title: copy.emptyTitle,
            message: copy.emptyMessage,
            actionLabel: onRefresh == null ? null : copy.refresh,
            onAction: onRefresh == null
                ? null
                : () {
                    onRefresh!();
                  },
          ),
        ),
      StoreAnalyticsStatus.error => _StateList(
          child: CustomerStateView(
            kind: CustomerStateKind.error,
            title: copy.errorTitle,
            message: copy.errorMessage,
            actionLabel: onRetry == null ? null : copy.retry,
            onAction: onRetry,
          ),
        ),
      StoreAnalyticsStatus.noEntitlement => _StateList(
          child: CustomerStateView(
            kind: CustomerStateKind.empty,
            title: copy.noEntitlementTitle,
            message: copy.noEntitlementMessage,
            icon: Icons.lock_outline_rounded,
          ),
        ),
      StoreAnalyticsStatus.loaded => _LoadedState(
          copy: copy,
          data: data!,
          storeOptions: storeOptions,
          selectedStoreId: selectedStoreId ?? data!.storeId,
          onStoreChanged: onStoreChanged,
          onRefresh: onRefresh,
          onDrillDown: onDrillDown,
        ),
    };
  }
}

class _StateList extends StatelessWidget {
  const _StateList({required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) => ListView(
        key: const ValueKey('store-analytics-state-list'),
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.all(CustomerUiSpacing.md),
        children: [
          SizedBox(
              height: MediaQuery.sizeOf(context).height * .62, child: child),
        ],
      );
}

class _LoadingState extends StatelessWidget {
  const _LoadingState({required this.copy});

  final StoreAnalyticsCopy copy;

  @override
  Widget build(BuildContext context) => Semantics(
        label: copy.loadingLabel,
        liveRegion: true,
        child: ListView(
          key: const ValueKey('store-analytics-loading'),
          padding: const EdgeInsets.all(CustomerUiSpacing.sm),
          children: const [
            Row(
              children: [
                CustomerSkeletonBox(height: 52, width: 52, radius: 16),
                SizedBox(width: CustomerUiSpacing.sm),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      CustomerSkeletonBox(height: 16, radius: 8),
                      SizedBox(height: CustomerUiSpacing.xs),
                      CustomerSkeletonBox(height: 12, width: 150, radius: 6),
                    ],
                  ),
                ),
              ],
            ),
            SizedBox(height: CustomerUiSpacing.sm),
            Row(
              children: [
                Expanded(child: CustomerSkeletonBox(height: 78)),
                SizedBox(width: CustomerUiSpacing.xs),
                Expanded(child: CustomerSkeletonBox(height: 78)),
              ],
            ),
            SizedBox(height: CustomerUiSpacing.xs),
            Row(
              children: [
                Expanded(child: CustomerSkeletonBox(height: 78)),
                SizedBox(width: CustomerUiSpacing.xs),
                Expanded(child: CustomerSkeletonBox(height: 78)),
              ],
            ),
            SizedBox(height: CustomerUiSpacing.sm),
            CustomerSkeletonBox(height: 222),
            SizedBox(height: CustomerUiSpacing.sm),
            CustomerSkeletonBox(height: 92),
          ],
        ),
      );
}

class _LoadedState extends StatelessWidget {
  const _LoadedState({
    required this.copy,
    required this.data,
    required this.storeOptions,
    required this.selectedStoreId,
    required this.onStoreChanged,
    required this.onRefresh,
    required this.onDrillDown,
  });

  final StoreAnalyticsCopy copy;
  final StoreAnalyticsData data;
  final List<StoreAnalyticsStoreOption> storeOptions;
  final int selectedStoreId;
  final ValueChanged<int>? onStoreChanged;
  final Future<void> Function()? onRefresh;
  final ValueChanged<String>? onDrillDown;

  @override
  Widget build(BuildContext context) {
    final content = ListView(
      key: const ValueKey('store-analytics-content'),
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.fromLTRB(
        CustomerUiSpacing.sm,
        CustomerUiSpacing.sm,
        CustomerUiSpacing.sm,
        CustomerUiSpacing.xl,
      ),
      children: [
        _StoreHeaderCard(
          copy: copy,
          data: data,
          storeOptions: storeOptions,
          selectedStoreId: selectedStoreId,
          onStoreChanged: onStoreChanged,
        ),
        if (data.isStale) ...[
          const SizedBox(height: CustomerUiSpacing.xs),
          _StaleBanner(copy: copy),
        ],
        const SizedBox(height: CustomerUiSpacing.sm),
        _KpiGrid(copy: copy, data: data),
        const SizedBox(height: CustomerUiSpacing.sm),
        _TrendCard(copy: copy, data: data),
        if (data.insights.isNotEmpty) ...[
          const SizedBox(height: CustomerUiSpacing.sm),
          Text(
            copy.insights,
            key: const ValueKey('store-analytics-insights-title'),
            style: Theme.of(context).textTheme.titleMedium?.copyWith(
                  color: CustomerUiColors.ink,
                  fontWeight: FontWeight.w800,
                ),
          ),
          const SizedBox(height: CustomerUiSpacing.xs),
          for (final insight in data.insights.take(4))
            Padding(
              padding: const EdgeInsets.only(bottom: CustomerUiSpacing.xs),
              child: _InsightCard(insight: insight, onDrillDown: onDrillDown),
            ),
        ],
      ],
    );

    if (onRefresh == null) return content;
    return RefreshIndicator(onRefresh: onRefresh!, child: content);
  }
}

class _StoreHeaderCard extends StatelessWidget {
  const _StoreHeaderCard({
    required this.copy,
    required this.data,
    required this.storeOptions,
    required this.selectedStoreId,
    required this.onStoreChanged,
  });

  final StoreAnalyticsCopy copy;
  final StoreAnalyticsData data;
  final List<StoreAnalyticsStoreOption> storeOptions;
  final int selectedStoreId;
  final ValueChanged<int>? onStoreChanged;

  @override
  Widget build(BuildContext context) => DecoratedBox(
        key: const ValueKey('store-analytics-store-header'),
        decoration: BoxDecoration(
          color: CustomerUiColors.white,
          borderRadius: BorderRadius.circular(CustomerUiRadii.lg),
          border: Border.all(color: CustomerUiColors.border),
          boxShadow: CustomerUiElevation.cardShadow,
        ),
        child: Padding(
          padding: const EdgeInsets.all(CustomerUiSpacing.sm),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              _StoreLogo(copy: copy, data: data),
              const SizedBox(width: CustomerUiSpacing.sm),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      data.storeName,
                      key: const ValueKey('store-analytics-store-name'),
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: Theme.of(context).textTheme.titleMedium?.copyWith(
                            color: CustomerUiColors.ink,
                            fontWeight: FontWeight.w800,
                          ),
                    ),
                    const SizedBox(height: CustomerUiSpacing.xxs),
                    Text(
                      data.periodLabel,
                      key: const ValueKey('store-analytics-period'),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: Theme.of(context).textTheme.bodySmall?.copyWith(
                            color: CustomerUiColors.inkSoft,
                            fontWeight: FontWeight.w700,
                          ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      data.freshnessLabel,
                      key: const ValueKey('store-analytics-freshness'),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: Theme.of(context).textTheme.bodySmall?.copyWith(
                            color: data.isStale
                                ? CustomerUiColors.warning
                                : CustomerUiColors.muted,
                          ),
                    ),
                    if (storeOptions.length > 1) ...[
                      const SizedBox(height: CustomerUiSpacing.xs),
                      _StoreSelector(
                        copy: copy,
                        options: storeOptions,
                        selectedStoreId: selectedStoreId,
                        onChanged: onStoreChanged,
                      ),
                    ],
                  ],
                ),
              ),
            ],
          ),
        ),
      );
}

class _StoreLogo extends StatelessWidget {
  const _StoreLogo({required this.copy, required this.data});

  final StoreAnalyticsCopy copy;
  final StoreAnalyticsData data;

  @override
  Widget build(BuildContext context) {
    Widget fallback() => Container(
          key: const ValueKey('store-analytics-logo-fallback'),
          alignment: Alignment.center,
          color: CustomerUiColors.limeSoft,
          child: const Icon(
            Icons.storefront_rounded,
            color: CustomerUiColors.deepGreenStrong,
            size: 26,
          ),
        );

    final logo = data.logoUrl?.trim();
    return Semantics(
      image: true,
      label:
          '${copy.storeLogo}: ${data.storeName}', // localization-gate: allow — localized label plus business store name.
      child: ExcludeSemantics(
        child: ClipRRect(
          borderRadius: BorderRadius.circular(CustomerUiRadii.md),
          child: SizedBox.square(
            dimension: 54,
            child: logo == null || logo.isEmpty
                ? fallback()
                : Image.network(
                    logo,
                    key: const ValueKey('store-analytics-logo'),
                    fit: BoxFit.cover,
                    loadingBuilder: (context, child, progress) =>
                        progress == null
                            ? child
                            : const ColoredBox(
                                color: CustomerUiColors.limeSoft,
                                child: Center(
                                  child: SizedBox.square(
                                    dimension: 18,
                                    child: CircularProgressIndicator(
                                      strokeWidth: 2,
                                    ),
                                  ),
                                ),
                              ),
                    errorBuilder: (context, error, stackTrace) => fallback(),
                  ),
          ),
        ),
      ),
    );
  }
}

class _StoreSelector extends StatelessWidget {
  const _StoreSelector({
    required this.copy,
    required this.options,
    required this.selectedStoreId,
    required this.onChanged,
  });

  final StoreAnalyticsCopy copy;
  final List<StoreAnalyticsStoreOption> options;
  final int selectedStoreId;
  final ValueChanged<int>? onChanged;

  @override
  Widget build(BuildContext context) => Semantics(
        label: copy.storeSelector,
        child: DropdownButtonFormField<int>(
          key: const ValueKey('store-analytics-store-selector'),
          initialValue: options.any((option) => option.id == selectedStoreId)
              ? selectedStoreId
              : null,
          isExpanded: true,
          decoration: InputDecoration(
            isDense: true,
            labelText: copy.storeSelector,
            contentPadding: const EdgeInsetsDirectional.fromSTEB(12, 9, 8, 9),
          ),
          items: [
            for (final option in options)
              DropdownMenuItem<int>(
                value: option.id,
                child: Text(
                  option.name,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
          ],
          onChanged: onChanged == null
              ? null
              : (value) {
                  if (value != null) onChanged!(value);
                },
        ),
      );
}

class _StaleBanner extends StatelessWidget {
  const _StaleBanner({required this.copy});

  final StoreAnalyticsCopy copy;

  @override
  Widget build(BuildContext context) => Semantics(
        liveRegion: true,
        child: DecoratedBox(
          key: const ValueKey('store-analytics-stale'),
          decoration: BoxDecoration(
            color: CustomerUiColors.warning.withValues(alpha: .10),
            borderRadius: BorderRadius.circular(CustomerUiRadii.md),
            border: Border.all(
              color: CustomerUiColors.warning.withValues(alpha: .45),
            ),
          ),
          child: Padding(
            padding: const EdgeInsets.symmetric(
              horizontal: CustomerUiSpacing.sm,
              vertical: CustomerUiSpacing.xs,
            ),
            child: Row(
              children: [
                const Icon(
                  Icons.schedule_rounded,
                  size: 18,
                  color: CustomerUiColors.warning,
                ),
                const SizedBox(width: CustomerUiSpacing.xs),
                Expanded(
                  child: Text(
                    copy.stale,
                    style: Theme.of(context).textTheme.bodySmall?.copyWith(
                          color: CustomerUiColors.inkSoft,
                          fontWeight: FontWeight.w700,
                        ),
                  ),
                ),
              ],
            ),
          ),
        ),
      );
}

class _KpiGrid extends StatelessWidget {
  const _KpiGrid({required this.copy, required this.data});

  final StoreAnalyticsCopy copy;
  final StoreAnalyticsData data;

  @override
  Widget build(BuildContext context) {
    final delta = data.revenueDeltaPercent;
    final deltaText = delta == null
        ? copy.noComparison
        : '${delta > 0 ? '+' : ''}${foodexCompactDecimal(delta, maxFractionDigits: 1)}% ${copy.vsPrevious}';
    final deltaTone = delta == null
        ? CustomerUiColors.muted
        : delta > 0
            ? CustomerUiColors.success
            : delta < 0
                ? CustomerUiColors.warning
                : CustomerUiColors.muted;

    return LayoutBuilder(
      builder: (context, constraints) {
        final gap = CustomerUiSpacing.xs;
        final width = (constraints.maxWidth - gap) / 2;
        return Wrap(
          key: const ValueKey('store-analytics-kpis'),
          spacing: gap,
          runSpacing: gap,
          children: [
            SizedBox(
              width: width,
              child: _KpiCard(
                key: const ValueKey('store-analytics-kpi-revenue'),
                label: copy.revenue,
                value: _money(data.revenue, data.currency, copy),
                icon: Icons.payments_outlined,
              ),
            ),
            SizedBox(
              width: width,
              child: _KpiCard(
                key: const ValueKey('store-analytics-kpi-orders'),
                label: copy.orders,
                value: foodexQuantity(data.orders, fractionDigits: 0),
                icon: Icons.receipt_long_outlined,
              ),
            ),
            SizedBox(
              width: width,
              child: _KpiCard(
                key: const ValueKey('store-analytics-kpi-aov'),
                label: copy.averageOrderValue,
                value: _money(data.averageOrderValue, data.currency, copy),
                icon: Icons.shopping_bag_outlined,
              ),
            ),
            SizedBox(
              width: width,
              child: _KpiCard(
                key: const ValueKey('store-analytics-kpi-comparison'),
                label: copy.comparison,
                value: deltaText,
                icon: delta != null && delta < 0
                    ? Icons.trending_down_rounded
                    : Icons.trending_up_rounded,
                valueColor: deltaTone,
              ),
            ),
          ],
        );
      },
    );
  }
}

class _KpiCard extends StatelessWidget {
  const _KpiCard({
    required this.label,
    required this.value,
    required this.icon,
    this.valueColor,
    super.key,
  });

  final String label;
  final String value;
  final IconData icon;
  final Color? valueColor;

  @override
  Widget build(BuildContext context) => ConstrainedBox(
        constraints: const BoxConstraints(minHeight: 82),
        child: DecoratedBox(
          decoration: BoxDecoration(
            color: CustomerUiColors.white,
            borderRadius: BorderRadius.circular(CustomerUiRadii.md),
            border: Border.all(color: CustomerUiColors.border),
          ),
          child: Padding(
            padding: const EdgeInsets.all(CustomerUiSpacing.sm),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Row(
                  children: [
                    Icon(icon, size: 16, color: CustomerUiColors.deepGreenSoft),
                    const SizedBox(width: CustomerUiSpacing.xxs),
                    Expanded(
                      child: Text(
                        label,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: Theme.of(context)
                            .textTheme
                            .labelMedium
                            ?.copyWith(color: CustomerUiColors.muted),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: CustomerUiSpacing.xxs),
                Text(
                  value,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: Theme.of(context).textTheme.titleMedium?.copyWith(
                        color: valueColor ?? CustomerUiColors.ink,
                        fontWeight: FontWeight.w900,
                      ),
                ),
              ],
            ),
          ),
        ),
      );
}

class _TrendCard extends StatelessWidget {
  const _TrendCard({required this.copy, required this.data});

  final StoreAnalyticsCopy copy;
  final StoreAnalyticsData data;

  @override
  Widget build(BuildContext context) {
    final summary = _trendSummary(data, copy);

    return DecoratedBox(
      key: const ValueKey('store-analytics-trend-card'),
      decoration: BoxDecoration(
        color: CustomerUiColors.white,
        borderRadius: BorderRadius.circular(CustomerUiRadii.lg),
        border: Border.all(color: CustomerUiColors.border),
      ),
      child: Padding(
        padding: const EdgeInsets.all(CustomerUiSpacing.sm),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              copy.salesTrend,
              style: Theme.of(context).textTheme.titleMedium?.copyWith(
                    color: CustomerUiColors.ink,
                    fontWeight: FontWeight.w800,
                  ),
            ),
            const SizedBox(height: CustomerUiSpacing.xxs),
            Text(
              copy.trendSummary,
              key: const ValueKey('store-analytics-trend-summary'),
              style: Theme.of(context)
                  .textTheme
                  .bodySmall
                  ?.copyWith(color: CustomerUiColors.muted),
            ),
            const SizedBox(height: CustomerUiSpacing.xs),
            FoodexTrendChart(
              values: data.dailySeries
                  .map((point) => point.sales)
                  .toList(growable: false),
              semanticLabel: summary,
              height: 108,
            ),
            const SizedBox(height: CustomerUiSpacing.xs),
            Row(
              children: [
                const Icon(
                  Icons.bar_chart_rounded,
                  size: 16,
                  color: CustomerUiColors.deepGreenSoft,
                ),
                const SizedBox(width: CustomerUiSpacing.xxs),
                Text(
                  copy.ordersTrend,
                  style: Theme.of(context)
                      .textTheme
                      .labelMedium
                      ?.copyWith(color: CustomerUiColors.inkSoft),
                ),
              ],
            ),
            const SizedBox(height: CustomerUiSpacing.xxs),
            FoodexBarChart(
              groups: data.dailySeries
                  .map((point) => <double>[point.orders.toDouble()])
                  .toList(growable: false),
              semanticLabel: summary,
              height: 58,
              gap: 5,
            ),
          ],
        ),
      ),
    );
  }
}

class _InsightCard extends StatelessWidget {
  const _InsightCard({required this.insight, required this.onDrillDown});

  final StoreAnalyticsInsight insight;
  final ValueChanged<String>? onDrillDown;

  @override
  Widget build(BuildContext context) {
    final tone = switch (insight.tone) {
      StoreAnalyticsInsightTone.positive => CustomerUiColors.success,
      StoreAnalyticsInsightTone.warning => CustomerUiColors.warning,
      StoreAnalyticsInsightTone.neutral => CustomerUiColors.deepGreenSoft,
    };
    final canOpen = insight.targetRoute?.trim().isNotEmpty == true &&
        insight.actionLabel?.trim().isNotEmpty == true &&
        onDrillDown != null;

    return Semantics(
      container: true,
      label:
          '${insight.title}. ${insight.body}', // localization-gate: allow — localized/authoritative insight copy.
      child: DecoratedBox(
        key: ValueKey('store-analytics-insight-${insight.id}'),
        decoration: BoxDecoration(
          color: CustomerUiColors.white,
          borderRadius: BorderRadius.circular(CustomerUiRadii.md),
          border: Border.all(color: CustomerUiColors.border),
        ),
        child: Padding(
          padding: const EdgeInsets.all(CustomerUiSpacing.sm),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              DecoratedBox(
                decoration: BoxDecoration(
                  color: tone.withValues(alpha: .11),
                  shape: BoxShape.circle,
                ),
                child: SizedBox.square(
                  dimension: 34,
                  child: Icon(
                    insight.tone == StoreAnalyticsInsightTone.warning
                        ? Icons.priority_high_rounded
                        : insight.tone == StoreAnalyticsInsightTone.positive
                            ? Icons.trending_up_rounded
                            : Icons.lightbulb_outline_rounded,
                    size: 18,
                    color: tone,
                  ),
                ),
              ),
              const SizedBox(width: CustomerUiSpacing.xs),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      insight.title,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: Theme.of(context).textTheme.titleSmall?.copyWith(
                            color: CustomerUiColors.ink,
                            fontWeight: FontWeight.w800,
                          ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      insight.body,
                      style: Theme.of(context)
                          .textTheme
                          .bodySmall
                          ?.copyWith(color: CustomerUiColors.muted),
                    ),
                    if (canOpen) ...[
                      const SizedBox(height: CustomerUiSpacing.xs),
                      TextButton.icon(
                        key: ValueKey(
                          'store-analytics-insight-action-${insight.id}',
                        ),
                        onPressed: () => onDrillDown!(insight.targetRoute!),
                        icon: const Icon(Icons.open_in_new_rounded, size: 16),
                        label: Text(insight.actionLabel!),
                      ),
                    ],
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

String _money(num value, String currency, StoreAnalyticsCopy copy) {
  final amount = foodexMoneyAmount(value, currency, fractionDigits: 2);
  final unit = currency.trim().toUpperCase() == 'EGP'
      ? copy.currencyEgp
      : currency.trim().toUpperCase();
  return unit.isEmpty ? amount : '$amount $unit';
}

String _trendSummary(StoreAnalyticsData data, StoreAnalyticsCopy copy) {
  if (data.dailySeries.isEmpty) return copy.trendSummary;

  final points = data.dailySeries.map((point) {
    final sales = _money(point.sales, data.currency, copy);
    final orders = foodexQuantity(point.orders, fractionDigits: 0);
    return '${point.dateLabel}: $sales, $orders ${copy.ordersUnit}';
  }).join(' • ');

  return '${copy.trendSummary}. $points';
}


class StoreAnalyticsRouteScreen extends StatefulWidget {
  const StoreAnalyticsRouteScreen({
    required this.api,
    this.retailStoreId,
    super.key,
  });

  final analytics_api.StoreAnalyticsApi api;
  final int? retailStoreId;

  @override
  State<StoreAnalyticsRouteScreen> createState() =>
      _StoreAnalyticsRouteScreenState();
}

class _StoreAnalyticsRouteScreenState extends State<StoreAnalyticsRouteScreen> {
  analytics_api.StoreAnalyticsConsumerState _state =
      const analytics_api.StoreAnalyticsConsumerState.loading();

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    if (mounted) {
      setState(() {
        _state = const analytics_api.StoreAnalyticsConsumerState.loading();
      });
    }

    try {
      final data = await widget.api.read(
        retailStoreId: widget.retailStoreId,
      );
      if (!mounted) return;
      setState(() {
        _state = analytics_api.StoreAnalyticsConsumerState.fromData(data);
      });
    } on analytics_api.StoreAnalyticsApiException catch (error) {
      debugPrint(
        'StoreAnalytics load failed: code=${error.code} '
        'status=${error.statusCode ?? '-'} '
        'retailStoreId=${widget.retailStoreId ?? '-'}',
      );
      if (!mounted) return;
      setState(() {
        _state = error.isNoEntitlement
            ? const analytics_api.StoreAnalyticsConsumerState.noEntitlement()
            : analytics_api.StoreAnalyticsConsumerState.error(error.code);
      });
    } catch (error) {
      debugPrint(
        'StoreAnalytics load failed: error=${error.runtimeType} '
        'retailStoreId=${widget.retailStoreId ?? '-'}',
      );
      if (!mounted) return;
      setState(() {
        _state =
            const analytics_api.StoreAnalyticsConsumerState.error('unknown');
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final copy = StoreAnalyticsCopy.fromContext(context);
    final model = _state.data;
    final data = model == null
        ? null
        : storeAnalyticsDataFromReadModel(
            model,
            copy: copy,
          );

    return StoreAnalyticsScreen(
      copy: copy,
      status: storeAnalyticsStatusFromConsumerState(_state),
      data: data,
      selectedStoreId: data?.storeId ?? widget.retailStoreId,
      onRetry: _load,
      onRefresh: _load,
      onDrillDown: (location) => Navigator.of(context).pushNamed(location),
    );
  }
}
