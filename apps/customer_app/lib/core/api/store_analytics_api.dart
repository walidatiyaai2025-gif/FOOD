import 'dart:async';
import 'dart:convert';

import 'package:http/http.dart' as http;

abstract interface class StoreAnalyticsApi {
  Future<StoreAnalyticsReadModel> read({int? retailStoreId});
}

class HttpStoreAnalyticsApi implements StoreAnalyticsApi {
  HttpStoreAnalyticsApi({
    required this.baseUrl,
    required this.token,
    this.requestTimeout = const Duration(seconds: 20),
    http.Client? client,
  }) : _client = client ?? http.Client();

  static const endpoint = '/api/v1/b2b/store-analytics';

  final String baseUrl;
  final String token;
  final Duration requestTimeout;
  final http.Client _client;

  @override
  Future<StoreAnalyticsReadModel> read({int? retailStoreId}) async {
    if (retailStoreId != null && retailStoreId <= 0) {
      throw ArgumentError.value(
        retailStoreId,
        'retailStoreId',
        'must be positive',
      );
    }

    final uri = Uri.parse('$baseUrl$endpoint').replace(
      queryParameters: <String, String>{
        if (retailStoreId != null) 'retail_store_id': '$retailStoreId',
      },
    );

    late final http.Response response;
    try {
      response = await _client.get(
        uri,
        headers: <String, String>{
          'Accept': 'application/json',
          'Authorization': 'Bearer $token',
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
    if (response.statusCode < 200 || response.statusCode >= 300) {
      throw StoreAnalyticsApiException(
        _serverErrorCode(decoded) ?? 'http_${response.statusCode}',
        statusCode: response.statusCode,
      );
    }

    final envelope = _map(decoded);
    final payload = envelope == null
        ? null
        : _map(envelope['data']) ?? envelope;
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
  static const networkUnavailable = 'network_unavailable';
  static const timeout = 'timeout';
  static const invalidResponse = 'invalid_response';

  final String code;
  final int? statusCode;

  bool get isNoEntitlement => code == noEntitlement;
}

class StoreAnalyticsReadModel {
  const StoreAnalyticsReadModel({
    required this.store,
    required this.kpis,
    required this.dailySeries,
    required this.topProducts,
    required this.attention,
    required this.insights,
    required this.freshness,
    this.period,
    this.previousPeriod,
    this.hasData,
  });

  final StoreAnalyticsStore store;
  final StoreAnalyticsPeriod? period;
  final StoreAnalyticsPeriod? previousPeriod;
  final StoreAnalyticsKpis kpis;
  final List<StoreAnalyticsSeriesPoint> dailySeries;
  final List<StoreAnalyticsTopProduct> topProducts;
  final List<StoreAnalyticsAttentionItem> attention;
  final List<StoreAnalyticsInsight> insights;
  final StoreAnalyticsFreshness freshness;

  /// Null means the server did not make an explicit empty/non-empty assertion.
  /// Consumers must not guess that a valid all-zero period is empty.
  final bool? hasData;

  bool get isExplicitlyEmpty => hasData == false;
  bool get isStale => freshness.isStale == true;
  bool get hasNewInsights => freshness.hasNewInsights == true;

  factory StoreAnalyticsReadModel.fromJson(Map<String, dynamic> json) {
    final storeMap = _map(json['store']);
    if (storeMap == null) {
      throw const FormatException('store is required');
    }

    final kpiMap = _map(json['kpis']) ??
        _map(json['metrics']) ??
        const <String, dynamic>{};

    final periodValue = json['period'];
    final periodContainer = _map(periodValue);
    final currentPeriodMap = _map(periodContainer?['current']) ??
        (periodContainer != null &&
                (periodContainer.containsKey('start') ||
                    periodContainer.containsKey('start_date') ||
                    periodContainer.containsKey('from'))
            ? periodContainer
            : null);
    final previousPeriodMap = _map(json['previous_period']) ??
        _map(periodContainer?['previous']);

    final seriesValue = json['daily_series'] ??
        json['series'] ??
        json['daily'];
    final seriesList = seriesValue is Map
        ? seriesValue['daily'] ?? seriesValue['points']
        : seriesValue;

    final freshnessMap = _map(json['freshness']) ?? const <String, dynamic>{};

    return StoreAnalyticsReadModel(
      store: StoreAnalyticsStore.fromJson(storeMap),
      period: currentPeriodMap == null
          ? null
          : StoreAnalyticsPeriod.fromJson(currentPeriodMap),
      previousPeriod: previousPeriodMap == null
          ? null
          : StoreAnalyticsPeriod.fromJson(previousPeriodMap),
      kpis: StoreAnalyticsKpis.fromJson(kpiMap),
      dailySeries: _mapList(seriesList)
          .map(StoreAnalyticsSeriesPoint.fromJson)
          .toList(growable: false),
      topProducts: _mapList(json['top_products'])
          .map(StoreAnalyticsTopProduct.fromJson)
          .toList(growable: false),
      attention: _mapList(
        json['attention'] ?? json['merchant_attention'] ?? json['low_stock'],
      )
          .map(StoreAnalyticsAttentionItem.fromJson)
          .toList(growable: false),
      insights: _mapList(json['insights'])
          .map(StoreAnalyticsInsight.fromJson)
          .toList(growable: false),
      freshness: StoreAnalyticsFreshness.fromJson(
        <String, dynamic>{
          ...freshnessMap,
          if (!freshnessMap.containsKey('is_stale') &&
              json.containsKey('is_stale'))
            'is_stale': json['is_stale'],
          if (!freshnessMap.containsKey('has_new_insights') &&
              json.containsKey('has_new_insights'))
            'has_new_insights': json['has_new_insights'],
        },
      ),
      hasData: _bool(json['has_data']),
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

    if (id == null || name == null || currency != 'EGP') {
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
    this.label,
    this.start,
    this.end,
  });

  final String? label;
  final String? start;
  final String? end;

  factory StoreAnalyticsPeriod.fromJson(Map<String, dynamic> json) =>
      StoreAnalyticsPeriod(
        label: _nonBlankString(json['label']),
        start: _nonBlankString(
          json['start'] ?? json['start_date'] ?? json['from'],
        ),
        end: _nonBlankString(
          json['end'] ?? json['end_date'] ?? json['to'],
        ),
      );
}

class StoreAnalyticsKpis {
  const StoreAnalyticsKpis({
    this.sales,
    this.orders,
    this.averageOrderValue,
    this.completedOrders,
    this.cancelledOrders,
    this.lateOrders,
    this.salesChangePercent,
    this.ordersChangePercent,
    this.averageOrderValueChangePercent,
  });

  final double? sales;
  final int? orders;
  final double? averageOrderValue;
  final int? completedOrders;
  final int? cancelledOrders;
  final int? lateOrders;
  final double? salesChangePercent;
  final double? ordersChangePercent;
  final double? averageOrderValueChangePercent;

  factory StoreAnalyticsKpis.fromJson(Map<String, dynamic> json) =>
      StoreAnalyticsKpis(
        sales: _number(json['sales'] ?? json['revenue']),
        orders: _wholeNumber(json['orders'] ?? json['order_count']),
        averageOrderValue: _number(
          json['average_order_value'] ?? json['aov'],
        ),
        completedOrders: _wholeNumber(
          json['completed_orders'] ?? json['completed'],
        ),
        cancelledOrders: _wholeNumber(
          json['cancelled_orders'] ?? json['cancelled'],
        ),
        lateOrders: _wholeNumber(json['late_orders'] ?? json['late']),
        salesChangePercent: _number(
          json['sales_change_percent'] ??
              json['revenue_change_percent'] ??
              json['sales_change'],
        ),
        ordersChangePercent: _number(
          json['orders_change_percent'] ?? json['orders_change'],
        ),
        averageOrderValueChangePercent: _number(
          json['average_order_value_change_percent'] ??
              json['aov_change_percent'] ??
              json['aov_change'],
        ),
      );
}

class StoreAnalyticsSeriesPoint {
  const StoreAnalyticsSeriesPoint({
    required this.date,
    this.sales,
    this.orders,
  });

  final String date;
  final double? sales;
  final int? orders;

  factory StoreAnalyticsSeriesPoint.fromJson(Map<String, dynamic> json) {
    final date = _nonBlankString(
      json['date'] ?? json['day'] ?? json['bucket'],
    );
    if (date == null) {
      throw const FormatException('analytics series date is required');
    }

    return StoreAnalyticsSeriesPoint(
      date: date,
      sales: _number(json['sales'] ?? json['revenue']),
      orders: _wholeNumber(json['orders'] ?? json['order_count']),
    );
  }
}

class StoreAnalyticsTopProduct {
  const StoreAnalyticsTopProduct({
    required this.id,
    required this.name,
    this.sales,
    this.orders,
    this.quantity,
    this.contributionPercent,
  });

  final int id;
  final String name;
  final double? sales;
  final int? orders;
  final double? quantity;
  final double? contributionPercent;

  factory StoreAnalyticsTopProduct.fromJson(Map<String, dynamic> json) {
    final id = _positiveInt(json['id'] ?? json['product_id']);
    final name = _nonBlankString(json['name'] ?? json['product_name']);
    if (id == null || name == null) {
      throw const FormatException('invalid top product');
    }

    return StoreAnalyticsTopProduct(
      id: id,
      name: name,
      sales: _number(json['sales'] ?? json['revenue']),
      orders: _wholeNumber(json['orders'] ?? json['order_count']),
      quantity: _number(json['quantity'] ?? json['units']),
      contributionPercent: _number(
        json['contribution_percent'] ?? json['contribution'],
      ),
    );
  }
}

class StoreAnalyticsAttentionItem {
  const StoreAnalyticsAttentionItem({
    required this.type,
    this.productId,
    this.title,
    this.count,
    this.quantity,
  });

  final String type;
  final int? productId;
  final String? title;
  final int? count;
  final double? quantity;

  factory StoreAnalyticsAttentionItem.fromJson(Map<String, dynamic> json) {
    final type = _nonBlankString(json['type'] ?? json['kind']);
    if (type == null) {
      throw const FormatException('attention type is required');
    }

    return StoreAnalyticsAttentionItem(
      type: type,
      productId: _positiveInt(json['product_id']),
      title: _nonBlankString(
        json['title'] ?? json['name'] ?? json['product_name'],
      ),
      count: _wholeNumber(json['count']),
      quantity: _number(json['quantity'] ?? json['available_quantity']),
    );
  }
}

class StoreAnalyticsInsight {
  const StoreAnalyticsInsight({
    required this.kind,
    this.id,
    this.title,
    this.message,
    this.priority,
    this.actionRoute,
  });

  final String kind;
  final String? id;
  final String? title;
  final String? message;
  final String? priority;
  final String? actionRoute;

  factory StoreAnalyticsInsight.fromJson(Map<String, dynamic> json) {
    final kind = _nonBlankString(json['kind'] ?? json['type']);
    if (kind == null) {
      throw const FormatException('insight kind is required');
    }

    return StoreAnalyticsInsight(
      kind: kind,
      id: _nonBlankString(json['id']),
      title: _nonBlankString(json['title']),
      message: _nonBlankString(
        json['message'] ?? json['body'] ?? json['summary'],
      ),
      priority: _nonBlankString(json['priority']),
      actionRoute: _nonBlankString(
        json['action_route'] ?? json['route'],
      ),
    );
  }
}

class StoreAnalyticsFreshness {
  const StoreAnalyticsFreshness({
    this.generatedAt,
    this.staleAt,
    this.isStale,
    this.hasNewInsights,
  });

  final String? generatedAt;
  final String? staleAt;
  final bool? isStale;
  final bool? hasNewInsights;

  factory StoreAnalyticsFreshness.fromJson(Map<String, dynamic> json) =>
      StoreAnalyticsFreshness(
        generatedAt: _nonBlankString(
          json['generated_at'] ?? json['updated_at'] ?? json['as_of'],
        ),
        staleAt: _nonBlankString(json['stale_at']),
        isStale: _bool(json['is_stale'] ?? json['stale']),
        hasNewInsights: _bool(
          json['has_new_insights'] ?? json['unread_insights'],
        ),
      );
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

String? _serverErrorCode(Object? decoded) {
  final map = _map(decoded);
  final code = map == null ? null : _nonBlankString(map['code']);
  return code;
}

Map<String, dynamic>? _map(Object? value) {
  if (value is! Map) return null;
  return Map<String, dynamic>.from(value);
}

List<Map<String, dynamic>> _mapList(Object? value) {
  if (value == null) return const <Map<String, dynamic>>[];
  if (value is! List) {
    throw const FormatException('expected a list');
  }
  return value.map((item) {
    final map = _map(item);
    if (map == null) {
      throw const FormatException('expected an object');
    }
    return map;
  }).toList(growable: false);
}

String? _nonBlankString(Object? value) {
  if (value == null) return null;
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
  if (value is String) {
    return int.tryParse(value);
  }
  return null;
}

double? _number(Object? value) {
  if (value is num && value.isFinite) return value.toDouble();
  if (value is String) return double.tryParse(value);
  return null;
}

bool? _bool(Object? value) {
  if (value is bool) return value;
  if (value == 1 || value == '1') return true;
  if (value == 0 || value == '0') return false;
  return null;
}
