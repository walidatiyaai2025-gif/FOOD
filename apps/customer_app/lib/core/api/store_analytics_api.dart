import 'dart:async';
import 'dart:convert';

import 'package:http/http.dart' as http;

abstract interface class StoreAnalyticsApi {
  Future<StoreAnalyticsReadModel> read({
    int? retailStoreId,
    DateTime? from,
    DateTime? to,
    String? seenInsightVersion,
  });
}

class HttpStoreAnalyticsApi implements StoreAnalyticsApi {
  HttpStoreAnalyticsApi({
    required this.baseUrl,
    required this.token,
    this.requestTimeout = const Duration(seconds: 20),
    http.Client? client,
  }) : _client = client ?? http.Client();

  static const endpoint = '/api/v1/mobile/store-analytics';

  final String baseUrl;
  final String token;
  final Duration requestTimeout;
  final http.Client _client;

  @override
  Future<StoreAnalyticsReadModel> read({
    int? retailStoreId,
    DateTime? from,
    DateTime? to,
    String? seenInsightVersion,
  }) async {
    if (retailStoreId != null && retailStoreId <= 0) {
      throw ArgumentError.value(
        retailStoreId,
        'retailStoreId',
        'must be positive',
      );
    }
    if (from != null && to != null && _dateOnly(from).isAfter(_dateOnly(to))) {
      throw ArgumentError.value(from, 'from', 'must not be after to');
    }
    if (seenInsightVersion != null &&
        !RegExp(r'^[a-fA-F0-9]{64}$').hasMatch(seenInsightVersion)) {
      throw ArgumentError.value(
        seenInsightVersion,
        'seenInsightVersion',
        'must be a 64-character hex digest',
      );
    }

    final uri = Uri.parse('$baseUrl$endpoint').replace(
      queryParameters: <String, String>{
        if (retailStoreId != null) 'retail_store_id': '$retailStoreId',
        if (from != null) 'from': _formatDate(from),
        if (to != null) 'to': _formatDate(to),
        if (seenInsightVersion != null)
          'seen_insight_version': seenInsightVersion.toLowerCase(),
      },
    );

    late final http.Response response;
    try {
      response = await _client.get(
        uri,
        headers: <String, String>{
          'Accept': 'application/json',
          'Authorization': '***',
          'X-FOODEX-Customer-Domain': 'b2b',
        },
      ).timeout(requestTimeout);
    } on TimeoutException {
      throw const StoreAnalyticsApiException(
        StoreAnalyticsApiException.timeout,
      );
    } on http.ClientException {
      throw const StoreAnalyticsApiException(
        StoreAnalyticsApiException.networkUnavailable,
      );
    }

    Object? decoded;
    if (response.body.isNotEmpty) {
      try {
        decoded = jsonDecode(response.body);
      } on FormatException {
        decoded = null;
      }
    }

    if (response.statusCode == 401) {
      throw const StoreAnalyticsApiException(
        StoreAnalyticsApiException.notAuthenticated,
        statusCode: 401,
      );
    }
    if (response.statusCode == 403) {
      throw const StoreAnalyticsApiException(
        StoreAnalyticsApiException.noEntitlement,
        statusCode: 403,
      );
    }
    if (response.statusCode == 422 && _hasRetailStoreValidationError(decoded)) {
      throw const StoreAnalyticsApiException(
        StoreAnalyticsApiException.retailStoreSelectionRequired,
        statusCode: 422,
      );
    }
    if (response.statusCode < 200 || response.statusCode >= 300) {
      throw StoreAnalyticsApiException(
        _serverErrorCode(decoded) ?? 'http_${response.statusCode}',
        statusCode: response.statusCode,
      );
    }

    final envelope = _map(decoded);
    final payload =
        envelope == null ? null : _map(envelope['data']) ?? envelope;
    if (payload == null) {
      throw const StoreAnalyticsApiException(
        StoreAnalyticsApiException.invalidResponse,
      );
    }

    try {
      return StoreAnalyticsReadModel.fromJson(payload);
    } on FormatException {
      throw const StoreAnalyticsApiException(
        StoreAnalyticsApiException.invalidResponse,
      );
    }
  }
}

class StoreAnalyticsApiException implements Exception {
  const StoreAnalyticsApiException(this.code, {this.statusCode});

  static const noEntitlement = 'no_entitlement';
  static const notAuthenticated = 'not_authenticated';
  static const retailStoreSelectionRequired = 'retail_store_selection_required';
  static const networkUnavailable = 'network_unavailable';
  static const timeout = 'timeout';
  static const invalidResponse = 'invalid_response';

  final String code;
  final int? statusCode;

  bool get isNoEntitlement => code == noEntitlement;
}

enum StoreAnalyticsDataState {
  ready,
  noData,
  stale,
}

class StoreAnalyticsReadModel {
  const StoreAnalyticsReadModel({
    required this.store,
    required this.period,
    required this.previousPeriod,
    required this.metrics,
    required this.orderFacts,
    required this.dailySeries,
    required this.topProducts,
    required this.peakActivity,
    required this.attention,
    required this.insights,
    required this.freshness,
    required this.insightState,
    required this.dataState,
  });

  final StoreAnalyticsStore store;
  final StoreAnalyticsPeriod period;
  final StoreAnalyticsPeriod previousPeriod;
  final StoreAnalyticsMetrics metrics;
  final StoreAnalyticsOrderFacts orderFacts;
  final List<StoreAnalyticsSeriesPoint> dailySeries;
  final List<StoreAnalyticsTopProduct> topProducts;
  final StoreAnalyticsPeakActivity peakActivity;
  final StoreAnalyticsAttention attention;
  final List<StoreAnalyticsInsight> insights;
  final StoreAnalyticsFreshness freshness;
  final StoreAnalyticsInsightState insightState;
  final StoreAnalyticsDataState dataState;

  bool get isExplicitlyEmpty => dataState == StoreAnalyticsDataState.noData;
  bool get isStale =>
      dataState == StoreAnalyticsDataState.stale || freshness.isStale;
  bool get hasNewInsights => insightState.hasNewInsights;

  factory StoreAnalyticsReadModel.fromJson(Map<String, dynamic> json) {
    final store = _requiredMap(json, 'store');
    final period = _requiredMap(json, 'period');
    final previousPeriod = _requiredMap(json, 'previous_period');
    final metrics = _requiredMap(json, 'metrics');
    final orderFacts = _requiredMap(json, 'order_facts');
    final peakActivity = _requiredMap(json, 'peak_activity');
    final attention = _requiredMap(json, 'attention');
    final freshness = _requiredMap(json, 'freshness');
    final insightState = _requiredMap(json, 'insight_state');
    final rawDataState = _requiredString(json, 'data_state');

    return StoreAnalyticsReadModel(
      store: StoreAnalyticsStore.fromJson(store),
      period: StoreAnalyticsPeriod.fromJson(period),
      previousPeriod: StoreAnalyticsPeriod.fromJson(previousPeriod),
      metrics: StoreAnalyticsMetrics.fromJson(metrics),
      orderFacts: StoreAnalyticsOrderFacts.fromJson(orderFacts),
      dailySeries: _mapList(json['daily_series'], 'daily_series')
          .map(StoreAnalyticsSeriesPoint.fromJson)
          .toList(growable: false),
      topProducts: _mapList(json['top_products'], 'top_products')
          .map(StoreAnalyticsTopProduct.fromJson)
          .toList(growable: false),
      peakActivity: StoreAnalyticsPeakActivity.fromJson(peakActivity),
      attention: StoreAnalyticsAttention.fromJson(attention),
      insights: _mapList(json['insights'], 'insights')
          .map(StoreAnalyticsInsight.fromJson)
          .toList(growable: false),
      freshness: StoreAnalyticsFreshness.fromJson(freshness),
      insightState: StoreAnalyticsInsightState.fromJson(insightState),
      dataState: switch (rawDataState) {
        'ready' => StoreAnalyticsDataState.ready,
        'no_data' => StoreAnalyticsDataState.noData,
        'stale' => StoreAnalyticsDataState.stale,
        _ => throw const FormatException('invalid analytics data state'),
      },
    );
  }
}

class StoreAnalyticsStore {
  const StoreAnalyticsStore({
    required this.id,
    required this.name,
    required this.currency,
    this.logoUrl,
  });

  final int id;
  final String name;
  final String? logoUrl;
  final String currency;

  factory StoreAnalyticsStore.fromJson(Map<String, dynamic> json) {
    final id = _positiveInt(json['id']);
    final name = _nonBlankString(json['name']);
    final currency = _nonBlankString(json['currency']);
    if (id == null || name == null || currency == null || currency != 'EGP') {
      throw const FormatException('invalid store analytics identity');
    }

    return StoreAnalyticsStore(
      id: id,
      name: name,
      logoUrl: _nonBlankString(json['logo_url']),
      currency: currency,
    );
  }
}

class StoreAnalyticsPeriod {
  const StoreAnalyticsPeriod({
    required this.from,
    required this.to,
    required this.days,
  });

  final String from;
  final String to;
  final int days;

  factory StoreAnalyticsPeriod.fromJson(Map<String, dynamic> json) {
    final from = _nonBlankString(json['from']);
    final to = _nonBlankString(json['to']);
    final days = _positiveInt(json['days']);
    if (from == null || to == null || days == null) {
      throw const FormatException('invalid analytics period');
    }
    return StoreAnalyticsPeriod(from: from, to: to, days: days);
  }
}

class StoreAnalyticsMetric {
  const StoreAnalyticsMetric({
    required this.value,
    required this.previousValue,
    required this.deltaPercent,
  });

  final double value;
  final double previousValue;
  final double? deltaPercent;

  factory StoreAnalyticsMetric.fromJson(Map<String, dynamic> json) {
    final value = _number(json['value']);
    final previousValue = _number(json['previous_value']);
    if (value == null || previousValue == null) {
      throw const FormatException('invalid analytics metric');
    }
    return StoreAnalyticsMetric(
      value: value,
      previousValue: previousValue,
      deltaPercent: _nullableNumber(json, 'delta_percent'),
    );
  }
}

class StoreAnalyticsMetrics {
  const StoreAnalyticsMetrics({
    required this.revenue,
    required this.orders,
    required this.averageOrderValue,
  });

  final StoreAnalyticsMetric revenue;
  final StoreAnalyticsMetric orders;
  final StoreAnalyticsMetric averageOrderValue;

  factory StoreAnalyticsMetrics.fromJson(Map<String, dynamic> json) =>
      StoreAnalyticsMetrics(
        revenue: StoreAnalyticsMetric.fromJson(
          _requiredMap(json, 'revenue'),
        ),
        orders: StoreAnalyticsMetric.fromJson(
          _requiredMap(json, 'orders'),
        ),
        averageOrderValue: StoreAnalyticsMetric.fromJson(
          _requiredMap(json, 'average_order_value'),
        ),
      );
}

class StoreAnalyticsOrderFact {
  const StoreAnalyticsOrderFact({
    required this.supported,
    required this.value,
    required this.previousValue,
  });

  final bool supported;
  final int? value;
  final int? previousValue;

  factory StoreAnalyticsOrderFact.fromJson(
    Map<String, dynamic> json, {
    bool defaultSupported = true,
  }) {
    final supported = _bool(json['supported']) ?? defaultSupported;
    final value = _nullableWholeNumber(json, 'value');
    final previousValue = _nullableWholeNumber(json, 'previous_value');

    if (supported && (value == null || previousValue == null)) {
      throw const FormatException('supported order fact requires values');
    }

    return StoreAnalyticsOrderFact(
      supported: supported,
      value: value,
      previousValue: previousValue,
    );
  }
}

class StoreAnalyticsOrderFacts {
  const StoreAnalyticsOrderFacts({
    required this.completed,
    required this.cancelled,
    required this.late,
  });

  final StoreAnalyticsOrderFact completed;
  final StoreAnalyticsOrderFact cancelled;
  final StoreAnalyticsOrderFact late;

  factory StoreAnalyticsOrderFacts.fromJson(Map<String, dynamic> json) =>
      StoreAnalyticsOrderFacts(
        completed: StoreAnalyticsOrderFact.fromJson(
          _requiredMap(json, 'completed'),
        ),
        cancelled: StoreAnalyticsOrderFact.fromJson(
          _requiredMap(json, 'cancelled'),
        ),
        late: StoreAnalyticsOrderFact.fromJson(
          _requiredMap(json, 'late'),
          defaultSupported: false,
        ),
      );
}

class StoreAnalyticsSeriesPoint {
  const StoreAnalyticsSeriesPoint({
    required this.date,
    required this.sales,
    required this.orders,
  });

  final String date;
  final double sales;
  final int orders;

  factory StoreAnalyticsSeriesPoint.fromJson(Map<String, dynamic> json) {
    final date = _nonBlankString(json['date']);
    final sales = _number(json['sales']);
    final orders = _wholeNumber(json['orders']);
    if (date == null || sales == null || orders == null) {
      throw const FormatException('invalid analytics series point');
    }
    return StoreAnalyticsSeriesPoint(
      date: date,
      sales: sales,
      orders: orders,
    );
  }
}

class StoreAnalyticsTopProduct {
  const StoreAnalyticsTopProduct({
    required this.productId,
    required this.sku,
    required this.name,
    required this.units,
    required this.revenue,
    required this.contributionPercent,
    required this.currency,
  });

  final int productId;
  final String sku;
  final String name;
  final double units;
  final double revenue;
  final double contributionPercent;
  final String currency;

  factory StoreAnalyticsTopProduct.fromJson(Map<String, dynamic> json) {
    final productId = _positiveInt(json['product_id']);
    final sku = _nonBlankString(json['sku']);
    final name = _nonBlankString(json['name']);
    final units = _number(json['units']);
    final revenue = _number(json['revenue']);
    final contributionPercent = _number(json['contribution_percent']);
    final currency = _nonBlankString(json['currency']);

    if (productId == null ||
        sku == null ||
        name == null ||
        units == null ||
        revenue == null ||
        contributionPercent == null ||
        currency == null ||
        currency != 'EGP') {
      throw const FormatException('invalid top product');
    }

    return StoreAnalyticsTopProduct(
      productId: productId,
      sku: sku,
      name: name,
      units: units,
      revenue: revenue,
      contributionPercent: contributionPercent,
      currency: currency,
    );
  }
}

class StoreAnalyticsHourlyActivity {
  const StoreAnalyticsHourlyActivity({
    required this.hour,
    required this.orders,
    required this.sales,
  });

  final int hour;
  final int orders;
  final double sales;

  factory StoreAnalyticsHourlyActivity.fromJson(Map<String, dynamic> json) {
    final hour = _wholeNumber(json['hour']);
    final orders = _wholeNumber(json['orders']);
    final sales = _number(json['sales']);
    if (hour == null ||
        hour < 0 ||
        hour > 23 ||
        orders == null ||
        orders < 0 ||
        sales == null) {
      throw const FormatException('invalid hourly analytics bucket');
    }
    return StoreAnalyticsHourlyActivity(
      hour: hour,
      orders: orders,
      sales: sales,
    );
  }
}

class StoreAnalyticsPeakWindow {
  const StoreAnalyticsPeakWindow({
    required this.startHour,
    required this.endHour,
    required this.windowHours,
    required this.orders,
    required this.sales,
    required this.currency,
    required this.source,
  });

  final int startHour;
  final int endHour;
  final int windowHours;
  final int orders;
  final double sales;
  final String currency;
  final String source;

  factory StoreAnalyticsPeakWindow.fromJson(Map<String, dynamic> json) {
    final startHour = _wholeNumber(json['start_hour']);
    final endHour = _wholeNumber(json['end_hour']);
    final windowHours = _positiveInt(json['window_hours']);
    final orders = _wholeNumber(json['orders']);
    final sales = _number(json['sales']);
    final currency = _nonBlankString(json['currency']);
    final source = _nonBlankString(json['source']);
    if (startHour == null ||
        startHour < 0 ||
        startHour > 23 ||
        endHour == null ||
        endHour < 0 ||
        endHour > 23 ||
        windowHours == null ||
        orders == null ||
        orders < 0 ||
        sales == null ||
        currency == null ||
        currency != 'EGP' ||
        source == null) {
      throw const FormatException('invalid peak activity window');
    }
    return StoreAnalyticsPeakWindow(
      startHour: startHour,
      endHour: endHour,
      windowHours: windowHours,
      orders: orders,
      sales: sales,
      currency: currency,
      source: source,
    );
  }
}

class StoreAnalyticsPeakActivity {
  const StoreAnalyticsPeakActivity({
    required this.hourly,
    required this.peakWindow,
  });

  final List<StoreAnalyticsHourlyActivity> hourly;
  final StoreAnalyticsPeakWindow? peakWindow;

  factory StoreAnalyticsPeakActivity.fromJson(Map<String, dynamic> json) {
    final peakWindowMap = _map(json['peak_window']);
    return StoreAnalyticsPeakActivity(
      hourly: _mapList(json['hourly'], 'peak_activity.hourly')
          .map(StoreAnalyticsHourlyActivity.fromJson)
          .toList(growable: false),
      peakWindow: peakWindowMap == null
          ? null
          : StoreAnalyticsPeakWindow.fromJson(peakWindowMap),
    );
  }
}

class StoreAnalyticsLowStockItem {
  const StoreAnalyticsLowStockItem({
    required this.id,
    required this.name,
    required this.sku,
    required this.available,
    this.imagePath,
  });

  final int id;
  final String name;
  final String sku;
  final double available;
  final String? imagePath;

  factory StoreAnalyticsLowStockItem.fromJson(Map<String, dynamic> json) {
    final id = _positiveInt(json['id']);
    final name = _nonBlankString(json['name']);
    final sku = _nonBlankString(json['sku']);
    final available = _number(json['available']);
    if (id == null || name == null || sku == null || available == null) {
      throw const FormatException('invalid low-stock item');
    }
    return StoreAnalyticsLowStockItem(
      id: id,
      name: name,
      sku: sku,
      available: available,
      imagePath: _nonBlankString(json['image']),
    );
  }
}

class StoreAnalyticsMerchantSummary {
  const StoreAnalyticsMerchantSummary({
    this.reorderNow,
    this.reorderSoon,
    this.blocked,
    this.expectedReorderSpend,
  });

  final int? reorderNow;
  final int? reorderSoon;
  final int? blocked;
  final double? expectedReorderSpend;

  factory StoreAnalyticsMerchantSummary.fromJson(Map<String, dynamic> json) =>
      StoreAnalyticsMerchantSummary(
        reorderNow: _wholeNumber(json['reorder_now']),
        reorderSoon: _wholeNumber(json['reorder_soon']),
        blocked: _wholeNumber(json['blocked']),
        expectedReorderSpend: _number(json['expected_reorder_spend']),
      );
}

class StoreAnalyticsMerchantAttentionItem {
  const StoreAnalyticsMerchantAttentionItem({
    required this.productId,
    required this.sku,
    required this.name,
    required this.available,
    required this.stockRisk,
    required this.action,
    required this.state,
    required this.priorityScore,
    this.daysOfCover,
  });

  final int productId;
  final String sku;
  final String name;
  final double available;
  final double? daysOfCover;
  final String stockRisk;
  final String action;
  final String state;
  final double priorityScore;

  factory StoreAnalyticsMerchantAttentionItem.fromJson(
    Map<String, dynamic> json,
  ) {
    final productId = _positiveInt(json['product_id']);
    final sku = _nonBlankString(json['sku']);
    final name = _nonBlankString(json['name']);
    final available = _number(json['available']);
    final stockRisk = _nonBlankString(json['stock_risk']);
    final action = _nonBlankString(json['action']);
    final state = _nonBlankString(json['state']);
    final priorityScore = _number(json['priority_score']);
    if (productId == null ||
        sku == null ||
        name == null ||
        available == null ||
        stockRisk == null ||
        action == null ||
        state == null ||
        priorityScore == null) {
      throw const FormatException('invalid merchant attention item');
    }

    return StoreAnalyticsMerchantAttentionItem(
      productId: productId,
      sku: sku,
      name: name,
      available: available,
      daysOfCover: _number(json['days_of_cover']),
      stockRisk: stockRisk,
      action: action,
      state: state,
      priorityScore: priorityScore,
    );
  }
}

class StoreAnalyticsAttention {
  const StoreAnalyticsAttention({
    required this.lowStock,
    required this.merchantSummary,
    required this.merchantAttention,
  });

  final List<StoreAnalyticsLowStockItem> lowStock;
  final StoreAnalyticsMerchantSummary merchantSummary;
  final List<StoreAnalyticsMerchantAttentionItem> merchantAttention;

  factory StoreAnalyticsAttention.fromJson(Map<String, dynamic> json) =>
      StoreAnalyticsAttention(
        lowStock: _mapList(json['low_stock'], 'attention.low_stock')
            .map(StoreAnalyticsLowStockItem.fromJson)
            .toList(growable: false),
        merchantSummary: StoreAnalyticsMerchantSummary.fromJson(
          _requiredMap(json, 'merchant_summary'),
        ),
        merchantAttention: _mapList(
          json['merchant_attention'],
          'attention.merchant_attention',
        )
            .map(StoreAnalyticsMerchantAttentionItem.fromJson)
            .toList(growable: false),
      );
}

class StoreAnalyticsInsightFacts {
  const StoreAnalyticsInsightFacts({
    this.cancelledOrders,
    this.orderCount,
    this.cancelledPercent,
    this.lowStockCount,
    this.reorderNowCount,
    this.firstProductId,
    this.deltaPercent,
    this.currentRevenue,
    this.previousRevenue,
    this.productId,
    this.productName,
    this.revenue,
    this.contributionPercent,
    this.currency,
  });

  final int? cancelledOrders;
  final int? orderCount;
  final double? cancelledPercent;
  final int? lowStockCount;
  final int? reorderNowCount;
  final int? firstProductId;
  final double? deltaPercent;
  final double? currentRevenue;
  final double? previousRevenue;
  final int? productId;
  final String? productName;
  final double? revenue;
  final double? contributionPercent;
  final String? currency;

  factory StoreAnalyticsInsightFacts.fromJson(Map<String, dynamic> json) =>
      StoreAnalyticsInsightFacts(
        cancelledOrders: _wholeNumber(json['cancelled_orders']),
        orderCount: _wholeNumber(json['order_count']),
        cancelledPercent: _number(json['cancelled_percent']),
        lowStockCount: _wholeNumber(json['low_stock_count']),
        reorderNowCount: _wholeNumber(json['reorder_now_count']),
        firstProductId: _positiveInt(json['first_product_id']),
        deltaPercent: _number(json['delta_percent']),
        currentRevenue: _number(json['current_revenue']),
        previousRevenue: _number(json['previous_revenue']),
        productId: _positiveInt(json['product_id']),
        productName: _nonBlankString(json['name']),
        revenue: _number(json['revenue']),
        contributionPercent: _number(json['contribution_percent']),
        currency: _nonBlankString(json['currency']),
      );
}

class StoreAnalyticsInsight {
  const StoreAnalyticsInsight({
    required this.id,
    required this.code,
    required this.severity,
    required this.priority,
    required this.facts,
  });

  final String id;
  final String code;
  final String severity;
  final int priority;
  final StoreAnalyticsInsightFacts facts;

  factory StoreAnalyticsInsight.fromJson(Map<String, dynamic> json) {
    final id = _nonBlankString(json['id']);
    final code = _nonBlankString(json['code']);
    final severity = _nonBlankString(json['severity']);
    final priority = _wholeNumber(json['priority']);
    if (id == null || code == null || severity == null || priority == null) {
      throw const FormatException('invalid store insight');
    }
    return StoreAnalyticsInsight(
      id: id,
      code: code,
      severity: severity,
      priority: priority,
      facts: StoreAnalyticsInsightFacts.fromJson(
        _requiredMap(json, 'facts'),
      ),
    );
  }
}

class StoreAnalyticsFreshness {
  const StoreAnalyticsFreshness({
    required this.computedAt,
    required this.staleAfterSeconds,
    required this.isStale,
    required this.state,
    this.sourceUpdatedAt,
  });

  final String computedAt;
  final String? sourceUpdatedAt;
  final int staleAfterSeconds;
  final bool isStale;
  final String state;

  factory StoreAnalyticsFreshness.fromJson(Map<String, dynamic> json) {
    final computedAt = _nonBlankString(json['computed_at']);
    final staleAfterSeconds = _wholeNumber(json['stale_after_seconds']);
    final isStale = _bool(json['is_stale']);
    final state = _nonBlankString(json['state']);
    if (computedAt == null ||
        staleAfterSeconds == null ||
        staleAfterSeconds < 0 ||
        isStale == null ||
        state == null) {
      throw const FormatException('invalid freshness metadata');
    }
    return StoreAnalyticsFreshness(
      computedAt: computedAt,
      sourceUpdatedAt: _nonBlankString(json['source_updated_at']),
      staleAfterSeconds: staleAfterSeconds,
      isStale: isStale,
      state: state,
    );
  }
}

class StoreAnalyticsInsightLifecycle {
  const StoreAnalyticsInsightLifecycle({
    required this.mode,
    required this.requestParameter,
    required this.clearWhenSeenVersionMatchesCurrent,
  });

  final String mode;
  final String requestParameter;
  final bool clearWhenSeenVersionMatchesCurrent;

  factory StoreAnalyticsInsightLifecycle.fromJson(Map<String, dynamic> json) {
    final mode = _nonBlankString(json['mode']);
    final requestParameter = _nonBlankString(json['request_parameter']);
    final clearWhenSeen = _bool(
      json['clear_when_seen_version_matches_current'],
    );
    if (mode == null || requestParameter == null || clearWhenSeen == null) {
      throw const FormatException('invalid insight lifecycle');
    }
    return StoreAnalyticsInsightLifecycle(
      mode: mode,
      requestParameter: requestParameter,
      clearWhenSeenVersionMatchesCurrent: clearWhenSeen,
    );
  }
}

class StoreAnalyticsInsightState {
  const StoreAnalyticsInsightState({
    required this.version,
    required this.hasNewInsights,
    required this.unreadCount,
    required this.lifecycle,
  });

  final String version;
  final bool hasNewInsights;
  final int unreadCount;
  final StoreAnalyticsInsightLifecycle lifecycle;

  factory StoreAnalyticsInsightState.fromJson(Map<String, dynamic> json) {
    final version = _nonBlankString(json['version']);
    final hasNewInsights = _bool(json['has_new_insights']);
    final unreadCount = _wholeNumber(json['unread_count']);
    if (version == null ||
        !RegExp(r'^[a-fA-F0-9]{64}$').hasMatch(version) ||
        hasNewInsights == null ||
        unreadCount == null ||
        unreadCount < 0) {
      throw const FormatException('invalid insight state');
    }

    return StoreAnalyticsInsightState(
      version: version.toLowerCase(),
      hasNewInsights: hasNewInsights,
      unreadCount: unreadCount,
      lifecycle: StoreAnalyticsInsightLifecycle.fromJson(
        _requiredMap(json, 'lifecycle'),
      ),
    );
  }
}

enum StoreAnalyticsConsumerStatus {
  loading,
  ready,
  empty,
  stale,
  noEntitlement,
  error,
}

class StoreAnalyticsConsumerState {
  const StoreAnalyticsConsumerState._({
    required this.status,
    this.data,
    this.errorCode,
  });

  const StoreAnalyticsConsumerState.loading()
      : this._(status: StoreAnalyticsConsumerStatus.loading);

  factory StoreAnalyticsConsumerState.fromData(
    StoreAnalyticsReadModel data,
  ) {
    if (data.isStale) {
      return StoreAnalyticsConsumerState._(
        status: StoreAnalyticsConsumerStatus.stale,
        data: data,
      );
    }
    if (data.isExplicitlyEmpty) {
      return StoreAnalyticsConsumerState._(
        status: StoreAnalyticsConsumerStatus.empty,
        data: data,
      );
    }
    return StoreAnalyticsConsumerState._(
      status: StoreAnalyticsConsumerStatus.ready,
      data: data,
    );
  }

  const StoreAnalyticsConsumerState.noEntitlement()
      : this._(status: StoreAnalyticsConsumerStatus.noEntitlement);

  const StoreAnalyticsConsumerState.error(String code)
      : this._(
          status: StoreAnalyticsConsumerStatus.error,
          errorCode: code,
        );

  final StoreAnalyticsConsumerStatus status;
  final StoreAnalyticsReadModel? data;
  final String? errorCode;
}

Map<String, dynamic> _requiredMap(
  Map<String, dynamic> json,
  String key,
) {
  final value = _map(json[key]);
  if (value == null) {
    throw FormatException('$key is required');
  }
  return value;
}

String _requiredString(Map<String, dynamic> json, String key) {
  final value = _nonBlankString(json[key]);
  if (value == null) {
    throw FormatException('$key is required');
  }
  return value;
}

Map<String, dynamic>? _map(Object? value) {
  if (value is! Map) return null;
  return Map<String, dynamic>.from(value);
}

List<Map<String, dynamic>> _mapList(Object? value, String key) {
  if (value is! List) {
    throw FormatException('$key must be a list');
  }
  return value.map((item) {
    final map = _map(item);
    if (map == null) {
      throw FormatException('$key must contain objects');
    }
    return map;
  }).toList(growable: false);
}

String? _nonBlankString(Object? value) {
  if (value is! String) return null;
  final trimmed = value.trim();
  return trimmed.isEmpty ? null : trimmed;
}

int? _positiveInt(Object? value) {
  final parsed = _wholeNumber(value);
  return parsed != null && parsed > 0 ? parsed : null;
}

int? _wholeNumber(Object? value) {
  if (value is int) return value;
  if (value is num && value.isFinite && value == value.roundToDouble()) {
    return value.toInt();
  }
  if (value is String) return int.tryParse(value);
  return null;
}

int? _nullableWholeNumber(Map<String, dynamic> json, String key) {
  if (!json.containsKey(key) || json[key] == null) return null;
  final value = _wholeNumber(json[key]);
  if (value == null) {
    throw FormatException('$key must be an integer or null');
  }
  return value;
}

double? _number(Object? value) {
  if (value is num && value.isFinite) return value.toDouble();
  if (value is String) return double.tryParse(value);
  return null;
}

double? _nullableNumber(Map<String, dynamic> json, String key) {
  if (!json.containsKey(key) || json[key] == null) return null;
  final value = _number(json[key]);
  if (value == null) {
    throw FormatException('$key must be numeric or null');
  }
  return value;
}

bool? _bool(Object? value) {
  if (value is bool) return value;
  if (value == 1 || value == '1') return true;
  if (value == 0 || value == '0') return false;
  return null;
}

String? _serverErrorCode(Object? decoded) {
  final map = _map(decoded);
  return map == null ? null : _nonBlankString(map['code']);
}

bool _hasRetailStoreValidationError(Object? decoded) {
  final map = _map(decoded);
  final errors = map == null ? null : _map(map['errors']);
  return errors?.containsKey('retail_store_id') == true;
}

DateTime _dateOnly(DateTime value) => DateTime(
      value.year,
      value.month,
      value.day,
    );

String _formatDate(DateTime value) {
  String two(int part) => part.toString().padLeft(2, '0');
  return '${value.year}-${two(value.month)}-${two(value.day)}';
}
