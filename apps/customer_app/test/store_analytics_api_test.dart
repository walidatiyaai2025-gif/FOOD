import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/api/store_analytics_api.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  group('HttpStoreAnalyticsApi', () {
    test('matches W1 query contract without Wholesale store_id', () async {
      late http.Request request;
      final api = HttpStoreAnalyticsApi(
        baseUrl: 'https://foodex.test',
        token: 'merchant-token',
        client: MockClient((incoming) async {
          request = incoming;
          return http.Response(
            jsonEncode({'data': _payload()}),
            200,
            headers: {'content-type': 'application/json'},
          );
        }),
      );
      final seenVersion = List<String>.filled(64, 'a').join();

      final model = await api.read(
        retailStoreId: 22,
        from: DateTime(2026, 10, 6),
        to: DateTime(2026, 10, 12),
        seenInsightVersion: seenVersion,
      );

      expect(request.url.path, '/api/v1/mobile/store-analytics');
      expect(
        request.url.queryParameters,
        <String, String>{
          'retail_store_id': '22',
          'from': '2026-10-06',
          'to': '2026-10-12',
          'seen_insight_version': seenVersion,
        },
      );
      expect(request.url.queryParameters.containsKey('store_id'), isFalse);
      expect(request.url.queryParameters.containsKey('store'), isFalse);
      expect(request.headers['Authorization'], 'Bearer merchant-token');
      expect(request.headers['X-FOODEX-Customer-Domain'], 'b2b');
      expect(model.store.id, 22);
      expect(model.store.currency, 'EGP');
    });

    test('allows server-authoritative Retail store resolution without query',
        () async {
      late http.Request request;
      final api = HttpStoreAnalyticsApi(
        baseUrl: 'https://foodex.test',
        token: 'merchant-token',
        client: MockClient((incoming) async {
          request = incoming;
          return http.Response(jsonEncode({'data': _payload()}), 200);
        }),
      );

      final model = await api.read();

      expect(request.url.query, isEmpty);
      expect(model.store.name, 'Downtown Store');
    });

    test('normalizes W1 metrics order facts peak attention and insight state',
        () async {
      final payload = _payload(
        dailySeries: <Map<String, dynamic>>[
          {'date': '2026-10-12', 'sales': 100, 'orders': 2},
        ],
        topProducts: <Map<String, dynamic>>[
          {
            'product_id': 9,
            'sku': 'SKU-9',
            'name': 'Coffee',
            'units': 3,
            'revenue': 100,
            'contribution_percent': 100,
            'currency': 'EGP',
          },
        ],
        hourly: <Map<String, dynamic>>[
          {'hour': 20, 'orders': 2, 'sales': 100},
        ],
        peakWindow: <String, dynamic>{
          'start_hour': 20,
          'end_hour': 21,
          'window_hours': 1,
          'orders': 2,
          'sales': 100,
          'currency': 'EGP',
          'source': 'orders.created_at',
        },
        lowStock: <Map<String, dynamic>>[
          {
            'id': 9,
            'name': 'Coffee',
            'sku': 'SKU-9',
            'image': null,
            'available': 2,
          },
        ],
        merchantAttention: <Map<String, dynamic>>[
          {
            'product_id': 9,
            'sku': 'SKU-9',
            'name': 'Coffee',
            'available': 2,
            'days_of_cover': 1.5,
            'stock_risk': 'high',
            'action': 'reorder',
            'state': 'reorder_now',
            'priority_score': 90,
          },
        ],
        insights: <Map<String, dynamic>>[
          {
            'id': 'top_product',
            'code': 'top_product_contribution',
            'severity': 'positive',
            'priority': 40,
            'facts': {
              'product_id': 9,
              'name': 'Coffee',
              'revenue': 100,
              'contribution_percent': 100,
              'currency': 'EGP',
            },
          },
        ],
        hasNewInsights: true,
        unreadCount: 1,
      );
      final api = HttpStoreAnalyticsApi(
        baseUrl: 'https://foodex.test',
        token: 'merchant-token',
        client: MockClient(
          (_) async => http.Response(jsonEncode({'data': payload}), 200),
        ),
      );

      final model = await api.read(retailStoreId: 22);

      expect(model.metrics.revenue.value, 100);
      expect(model.metrics.revenue.previousValue, 80);
      expect(model.metrics.revenue.deltaPercent, 25);
      expect(model.orderFacts.completed.value, 1);
      expect(model.orderFacts.late.supported, isFalse);
      expect(model.dailySeries.single.orders, 2);
      expect(model.topProducts.single.productId, 9);
      expect(model.peakActivity.peakWindow?.startHour, 20);
      expect(model.attention.lowStock.single.available, 2);
      expect(model.attention.merchantAttention.single.daysOfCover, 1.5);
      expect(model.insights.single.facts.productName, 'Coffee');
      expect(model.hasNewInsights, isTrue);
      expect(model.insightState.unreadCount, 1);
    });

    test('tolerates optional logo, empty series and stale metadata', () async {
      final payload = _payload(
        logoUrl: null,
        dailySeries: const <Map<String, dynamic>>[],
        topProducts: const <Map<String, dynamic>>[],
        stale: true,
        dataState: 'stale',
      );
      final api = HttpStoreAnalyticsApi(
        baseUrl: 'https://foodex.test',
        token: 'merchant-token',
        client: MockClient(
          (_) async => http.Response(jsonEncode({'data': payload}), 200),
        ),
      );

      final model = await api.read(retailStoreId: 22);
      final state = StoreAnalyticsConsumerState.fromData(model);

      expect(model.store.logoUrl, isNull);
      expect(model.dailySeries, isEmpty);
      expect(model.topProducts, isEmpty);
      expect(model.isStale, isTrue);
      expect(state.status, StoreAnalyticsConsumerStatus.stale);
      expect(state.data, same(model));
    });

    test('uses server data_state for empty instead of guessing from zero KPIs',
        () async {
      final emptyApi = HttpStoreAnalyticsApi(
        baseUrl: 'https://foodex.test',
        token: 'merchant-token',
        client: MockClient(
          (_) async => http.Response(
            jsonEncode({'data': _payload(dataState: 'no_data')}),
            200,
          ),
        ),
      );
      final readyApi = HttpStoreAnalyticsApi(
        baseUrl: 'https://foodex.test',
        token: 'merchant-token',
        client: MockClient(
          (_) async => http.Response(
            jsonEncode({'data': _payload(dataState: 'ready')}),
            200,
          ),
        ),
      );

      expect(
        StoreAnalyticsConsumerState.fromData(
          await emptyApi.read(retailStoreId: 22),
        ).status,
        StoreAnalyticsConsumerStatus.empty,
      );
      expect(
        StoreAnalyticsConsumerState.fromData(
          await readyApi.read(retailStoreId: 22),
        ).status,
        StoreAnalyticsConsumerStatus.ready,
      );
    });

    test('maps forbidden response to no-entitlement state contract', () async {
      final api = HttpStoreAnalyticsApi(
        baseUrl: 'https://foodex.test',
        token: 'merchant-token',
        client: MockClient(
          (_) async => http.Response(
            jsonEncode({'message': 'Forbidden'}),
            403,
          ),
        ),
      );

      await expectLater(
        api.read(retailStoreId: 22),
        throwsA(
          isA<StoreAnalyticsApiException>()
              .having((error) => error.isNoEntitlement, 'no entitlement', isTrue)
              .having((error) => error.statusCode, 'status', 403),
        ),
      );
      expect(
        const StoreAnalyticsConsumerState.noEntitlement().status,
        StoreAnalyticsConsumerStatus.noEntitlement,
      );
    });

    test('maps ambiguous entitled stores to explicit selector-required error',
        () async {
      final api = HttpStoreAnalyticsApi(
        baseUrl: 'https://foodex.test',
        token: 'merchant-token',
        client: MockClient(
          (_) async => http.Response(
            jsonEncode({
              'message': 'The given data was invalid.',
              'errors': {
                'retail_store_id': ['Select an authorized Retail store.'],
              },
            }),
            422,
          ),
        ),
      );

      await expectLater(
        api.read(),
        throwsA(
          isA<StoreAnalyticsApiException>().having(
            (error) => error.code,
            'code',
            StoreAnalyticsApiException.retailStoreSelectionRequired,
          ),
        ),
      );
    });

    test('rejects non-EGP Retail identity instead of leaking another currency',
        () async {
      final invalidCurrency = _payload()
        ..['store'] = <String, dynamic>{
          'id': 22,
          'name': 'Downtown Store',
          'logo_url': null,
          'currency': 'KWD',
        };
      final api = HttpStoreAnalyticsApi(
        baseUrl: 'https://foodex.test',
        token: 'merchant-token',
        client: MockClient(
          (_) async => http.Response(jsonEncode(invalidCurrency), 200),
        ),
      );

      await expectLater(
        api.read(retailStoreId: 22),
        throwsA(
          isA<StoreAnalyticsApiException>().having(
            (error) => error.code,
            'code',
            StoreAnalyticsApiException.invalidResponse,
          ),
        ),
      );
    });
  });
}

Map<String, dynamic> _payload({
  String? logoUrl = 'https://cdn.foodex.test/store.png',
  List<Map<String, dynamic>> dailySeries = const <Map<String, dynamic>>[],
  List<Map<String, dynamic>> topProducts = const <Map<String, dynamic>>[],
  List<Map<String, dynamic>> hourly = const <Map<String, dynamic>>[],
  Map<String, dynamic>? peakWindow,
  List<Map<String, dynamic>> lowStock = const <Map<String, dynamic>>[],
  List<Map<String, dynamic>> merchantAttention =
      const <Map<String, dynamic>>[],
  List<Map<String, dynamic>> insights = const <Map<String, dynamic>>[],
  bool stale = false,
  String dataState = 'ready',
  bool hasNewInsights = false,
  int unreadCount = 0,
}) =>
    <String, dynamic>{
      'store': <String, dynamic>{
        'id': 22,
        'name': 'Downtown Store',
        'logo_url': logoUrl,
        'currency': 'EGP',
      },
      'period': <String, dynamic>{
        'from': '2026-10-06',
        'to': '2026-10-12',
        'days': 7,
      },
      'previous_period': <String, dynamic>{
        'from': '2026-09-29',
        'to': '2026-10-05',
        'days': 7,
      },
      'metrics': <String, dynamic>{
        'revenue': <String, dynamic>{
          'value': 100,
          'previous_value': 80,
          'delta_percent': 25,
        },
        'orders': <String, dynamic>{
          'value': 2,
          'previous_value': 1,
          'delta_percent': 100,
        },
        'average_order_value': <String, dynamic>{
          'value': 50,
          'previous_value': 80,
          'delta_percent': -37.5,
        },
      },
      'order_facts': <String, dynamic>{
        'completed': <String, dynamic>{
          'value': 1,
          'previous_value': 1,
        },
        'cancelled': <String, dynamic>{
          'value': 1,
          'previous_value': 0,
        },
        'late': <String, dynamic>{
          'supported': false,
          'value': null,
          'previous_value': null,
        },
      },
      'daily_series': dailySeries,
      'top_products': topProducts,
      'peak_activity': <String, dynamic>{
        'hourly': hourly,
        'peak_window': peakWindow,
      },
      'attention': <String, dynamic>{
        'low_stock': lowStock,
        'merchant_summary': <String, dynamic>{
          'reorder_now': lowStock.isEmpty ? 0 : 1,
          'reorder_soon': 0,
          'blocked': 0,
          'expected_reorder_spend': 0,
        },
        'merchant_attention': merchantAttention,
      },
      'insights': insights,
      'freshness': <String, dynamic>{
        'computed_at': '2026-10-12T21:30:00+03:00',
        'source_updated_at': stale
            ? '2026-10-10T20:00:00+03:00'
            : '2026-10-12T21:00:00+03:00',
        'stale_after_seconds': 86400,
        'is_stale': stale,
        'state': stale ? 'stale' : 'fresh',
      },
      'insight_state': <String, dynamic>{
        'version': List<String>.filled(64, 'b').join(),
        'has_new_insights': hasNewInsights,
        'unread_count': unreadCount,
        'lifecycle': <String, dynamic>{
          'mode': 'client_version_echo',
          'request_parameter': 'seen_insight_version',
          'clear_when_seen_version_matches_current': true,
        },
      },
      'data_state': dataState,
    };
