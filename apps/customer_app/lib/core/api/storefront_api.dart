import 'dart:convert';

import 'package:http/http.dart' as http;

class StorefrontApiException implements Exception {
  const StorefrontApiException(this.message, {this.statusCode});

  final String message;
  final int? statusCode;

  @override
  String toString() => message;
}

abstract class StorefrontApi {
  Future<Map<String, dynamic>> selection({
    String? countryCode,
    String? city,
    String? area,
    bool support = false,
  });

  Future<Map<String, dynamic>> retailHome(int storeId);

  Future<Map<String, dynamic>> b2bCheckoutOptions(int storeId);
}

class HttpStorefrontApi implements StorefrontApi {
  HttpStorefrontApi({
    required this.baseUrl,
    this.token,
    this.retailStoreContextId,
    http.Client? client,
  }) : _client = client ?? http.Client();

  final String baseUrl;
  final String? token;
  final int? retailStoreContextId;
  final http.Client _client;

  Map<String, String> get _headers => {
        'Accept': 'application/json',
        if (token != null && token!.isNotEmpty)
          'Authorization': 'Bearer $token',
        if (retailStoreContextId != null)
          'X-FOODEX-Retail-Store-ID': '$retailStoreContextId',
      };

  @override
  Future<Map<String, dynamic>> selection({
    String? countryCode,
    String? city,
    String? area,
    bool support = false,
  }) async {
    final params = <String, String>{
      if (countryCode != null && countryCode.trim().isNotEmpty)
        'country_code': countryCode.trim(),
      if (city != null && city.trim().isNotEmpty) 'city': city.trim(),
      if (area != null && area.trim().isNotEmpty) 'area': area.trim(),
      if (support) 'support': '1',
    };

    if (token == null || token!.isEmpty) {
      final body = await _get('/api/v1/stores', query: params);
      final rows = body is Map && body['data'] is List
          ? (body['data'] as List)
              .whereType<Map>()
              .map((row) => Map<String, dynamic>.from(row))
              .toList(growable: false)
          : const <Map<String, dynamic>>[];
      return {
        'retail_stores': rows,
        'wholesale_stores': const <Object>[],
        'entitlements': const <String, Object>{},
      };
    }

    return _asMap(await _get('/api/v1/store-selector', query: params));
  }

  @override
  Future<Map<String, dynamic>> retailHome(int storeId) async =>
      _asMap(await _get('/api/v1/stores/$storeId/storefront'));

  @override
  Future<Map<String, dynamic>> b2bCheckoutOptions(int storeId) async =>
      _asMap(
        await _get(
          '/api/v1/b2b/checkout/options',
          query: {'store_id': '$storeId'},
        ),
      );

  Future<Object?> _get(
    String path, {
    Map<String, String> query = const {},
  }) async {
    final base = Uri.parse('$baseUrl$path');
    final uri = query.isEmpty ? base : base.replace(queryParameters: query);
    http.Response response;
    try {
      response = await _client.get(uri, headers: _headers);
    } on http.ClientException {
      throw const StorefrontApiException('تعذر الاتصال بالخادم.');
    }

    Object? body;
    if (response.body.isNotEmpty) {
      try {
        body = jsonDecode(response.body);
      } catch (_) {
        body = null;
      }
    }

    if (response.statusCode < 200 || response.statusCode >= 300) {
      final message = body is Map && body['message'] is String
          ? body['message'] as String
          : 'حدث خطأ أثناء تحميل بيانات المتجر.';
      throw StorefrontApiException(
        message,
        statusCode: response.statusCode,
      );
    }

    return body;
  }

  Map<String, dynamic> _asMap(Object? value) {
    if (value is Map) {
      return Map<String, dynamic>.from(value);
    }
    throw const StorefrontApiException('استجابة الخادم غير صالحة.');
  }
}
