import 'dart:convert';

import 'package:http/http.dart' as http;

class WholesaleCommerceException implements Exception {
  const WholesaleCommerceException(this.message, {this.statusCode});

  final String message;
  final int? statusCode;

  @override
  String toString() => message;
}

abstract interface class WholesaleCommerceApi {
  Future<Object?> cart(int storeId);
  Future<Object?> addItem(int storeId, int productId, double quantity);
  Future<Object?> updateItem(int itemId, double quantity);
  Future<void> removeItem(int itemId);
  Future<Object?> checkout({
    required int storeId,
    required int addressId,
    required String paymentMethod,
    String? requestedDeliveryDate,
    String? note,
    required String idempotencyKey,
  });
}

class HttpWholesaleCommerceApi implements WholesaleCommerceApi {
  HttpWholesaleCommerceApi({
    required this.baseUrl,
    required this.token,
    this.retailStoreContextId,
    http.Client? client,
  }) : _client = client ?? http.Client();

  final String baseUrl;
  final String token;
  final int? retailStoreContextId;
  final http.Client _client;

  Map<String, String> get _headers => {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'Authorization': 'Bearer $token',
        if (retailStoreContextId != null)
          'X-FOODEX-Retail-Store-ID': '$retailStoreContextId',
      };

  @override
  Future<Object?> cart(int storeId) =>
      _request('GET', '/api/v1/cart', query: {'store': '$storeId'});

  @override
  Future<Object?> addItem(
    int storeId,
    int productId,
    double quantity,
  ) =>
      _request(
        'POST',
        '/api/v1/cart/items',
        body: {
          'store_id': storeId,
          'product_id': productId,
          'quantity': quantity,
        },
      );

  @override
  Future<Object?> updateItem(int itemId, double quantity) =>
      _request(
        'PATCH',
        '/api/v1/cart/items/$itemId',
        body: {'quantity': quantity},
      );

  @override
  Future<void> removeItem(int itemId) async {
    await _request('DELETE', '/api/v1/cart/items/$itemId');
  }

  @override
  Future<Object?> checkout({
    required int storeId,
    required int addressId,
    required String paymentMethod,
    String? requestedDeliveryDate,
    String? note,
    required String idempotencyKey,
  }) =>
      _request(
        'POST',
        '/api/v1/checkout',
        body: {
          'store_id': storeId,
          'address_id': addressId,
          'payment_method': paymentMethod,
          if (requestedDeliveryDate != null)
            'requested_delivery_date': requestedDeliveryDate,
          if (note != null && note.trim().isNotEmpty) 'note': note.trim(),
        },
        extraHeaders: {'Idempotency-Key': idempotencyKey},
      );

  Future<Object?> _request(
    String method,
    String path, {
    Map<String, String> query = const {},
    Map<String, Object?>? body,
    Map<String, String> extraHeaders = const {},
  }) async {
    final base = Uri.parse('$baseUrl$path');
    final uri = query.isEmpty ? base : base.replace(queryParameters: query);
    final headers = {..._headers, ...extraHeaders};

    http.Response response;
    try {
      switch (method) {
        case 'POST':
          response = await _client.post(
            uri,
            headers: headers,
            body: jsonEncode(body ?? const {}),
          );
        case 'PATCH':
          response = await _client.patch(
            uri,
            headers: headers,
            body: jsonEncode(body ?? const {}),
          );
        case 'DELETE':
          response = await _client.delete(uri, headers: headers);
        default:
          response = await _client.get(uri, headers: headers);
      }
    } on http.ClientException {
      throw const WholesaleCommerceException('تعذر الاتصال بالخادم.');
    }

    Object? value;
    if (response.body.isNotEmpty) {
      try {
        value = jsonDecode(response.body);
      } catch (_) {
        value = null;
      }
    }

    if (response.statusCode < 200 || response.statusCode >= 300) {
      final message = value is Map && value['message'] is String
          ? value['message'] as String
          : 'تعذر تنفيذ العملية.';
      throw WholesaleCommerceException(
        message,
        statusCode: response.statusCode,
      );
    }

    return value;
  }
}
