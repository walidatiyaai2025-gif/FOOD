import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/api/store_analytics_api.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  group('HttpStoreAnalyticsApi', () {
    test('sends only retail_store_id and preserves Business auth semantics',
        () async {
      late http.Request request;
      final api = HttpStoreAnalyticsApi(
        baseUrl: 'https://foodex.test',
        token: 'merchant-token',
        client: MockClient((incoming) async {
          request = incoming;
          return http.Response(
            jsonEncode(_payload()),
            200,
            headers: {'content-type': 'application/json'},
          );
        }),
      );

      final model = await api.read(retailStoreId: 22);

      expect(request.url.path, HttpStoreAnalyticsApi.endpoint);
      expect(request.url.queryParameters, {'retail_store_id': '22'});
      expect(request.url.queryParameters, isNot(contains('store_id')));
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

    test('tolerates optional logo, empty series and explicit stale metadata',
        () async {
      final payload = _payload(
        logoUrl: null,
        dailySeries: const [],
        stale: true,
        hasData: true,
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

    test('keeps an explicit empty period distinct from all-zero valid data',
        () async {
      final emptyApi = HttpStoreAnalyticsApi(
        baseUrl: 'https://foodex.test',
        token: 'merchant-token',
        client: MockClient(
          (_) async => http.Response(
            jsonEncode({'data': _payload(hasData: false)}),
            200,
          ),
        ),
      );
      final readyApi = HttpStoreAnalyticsApi(
        baseUrl: 'https://foodex.test',
        token: 'merchant-token',
        client: MockClient(
          (_) async => http.Response(
            jsonEncode({'data': _payload(hasData: null)}),
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
            jsonEncode({'code': 'STORE_ANALYTICS_NOT_ENTITLED'}),
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

    test('rejects non-EGP or malformed Retail identity instead of faking data',
        () async {
      final invalidCurrency = _payload()..['store'] = {
          'id': 22,
          'name': 'Downtown Store',
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
  List<Map<String, dynamic>> dailySeries = const [],
  bool stale = false,
  bool? hasData = true,
}) =>
    <String, dynamic>{
      'store': <String, dynamic>{
        'id': 22,
        'name': 'Downtown Store',
        'logo_url': logoUrl,
        'currency': 'EGP',
      },
      'period': <String, dynamic>{
        'current': <String, dynamic>{
          'start': '2026-10-01',
          'end': '2026-10-12',
        },
        'previous': <String, dynamic>{
          'start': '2026-09-19',
          'end': '2026-09-30',
        },
      },
      'kpis': <String, dynamic>{
        'sales': 0,
        'orders': 0,
        'average_order_value': 0,
      },
      'daily_series': dailySeries,
      'top_products': <Object>[],
      'attention': <Object>[],
      'insights': <Object>[],
      'freshness': <String, dynamic>{
        'generated_at': '2026-10-12T18:30:00Z',
        'is_stale': stale,
        'has_new_insights': false,
      },
      if (hasData != null) 'has_data': hasData,
    };
