import 'dart:convert';

import 'package:http/http.dart' as http;

import 'customer_order_models.dart';

abstract interface class CustomerOrdersApi {
  Future<CustomerOrderPage> orders({
    int page = 1,
    int perPage = 20,
    String? status,
    CustomerOrderContext? context,
  });

  Future<CustomerOrderDetails> order({
    required int orderId,
    CustomerOrderContext? context,
  });
}

class HttpCustomerOrdersApi implements CustomerOrdersApi {
  HttpCustomerOrdersApi({
    required this.baseUrl,
    required this.token,
    http.Client? client,
  }) : _client = client ?? http.Client();

  final String baseUrl;
  final String token;
  final http.Client _client;

  @override
  Future<CustomerOrderPage> orders({
    int page = 1,
    int perPage = 20,
    String? status,
    CustomerOrderContext? context,
  }) async {
    _validateContext(context);

    final query = <String, String>{
      'page': page.toString(),
      'per_page': perPage.toString(),
      if (status != null && status.trim().isNotEmpty)
        'status': status.trim().toLowerCase(),
      if (context != null) 'store_id': context.storeId.toString(),
      if (context != null) 'channel': context.normalizedChannel,
    };

    final response = await _send(
      Uri.parse('$baseUrl/api/v1/orders').replace(queryParameters: query),
      context,
    );
    final decoded = _decodeMap(response);
    return CustomerOrderPage.fromJson(decoded);
  }

  @override
  Future<CustomerOrderDetails> order({
    required int orderId,
    CustomerOrderContext? context,
  }) async {
    if (orderId <= 0) {
      throw const CustomerOrdersException('invalid_order_id');
    }
    _validateContext(context);

    final query = <String, String>{
      if (context != null) 'store_id': context.storeId.toString(),
      if (context != null) 'channel': context.normalizedChannel,
    };

    final response = await _send(
      Uri.parse('$baseUrl/api/v1/orders/$orderId').replace(
        queryParameters: query.isEmpty ? null : query,
      ),
      context,
    );
    return CustomerOrderDetails.fromJson(_decodeMap(response));
  }

  Future<http.Response> _send(
    Uri uri,
    CustomerOrderContext? context,
  ) async {
    if (token.trim().isEmpty) {
      throw const CustomerOrdersException('authentication_required');
    }

    try {
      return await _client.get(
        uri,
        headers: <String, String>{
          'Accept': 'application/json',
          'Authorization': 'Bearer $token',
          if (context != null)
            'X-FOODEX-Store-ID': context.storeId.toString(),
          if (context != null)
            'X-FOODEX-Customer-Domain': context.normalizedChannel,
        },
      );
    } on http.ClientException {
      throw const CustomerOrdersException('network_unavailable');
    }
  }

  Map<String, dynamic> _decodeMap(http.Response response) {
    Object? decoded;
    if (response.body.isNotEmpty) {
      try {
        decoded = jsonDecode(response.body);
      } catch (_) {
        decoded = null;
      }
    }

    if (response.statusCode == 401) {
      throw const CustomerOrdersException('session_expired');
    }
    if (response.statusCode == 403) {
      throw const CustomerOrdersException('forbidden');
    }
    if (response.statusCode == 404) {
      throw const CustomerOrdersException('not_found');
    }
    if (response.statusCode < 200 || response.statusCode >= 300) {
      final code = decoded is Map && decoded['message'] is String
          ? decoded['message'] as String
          : 'http_' + response.statusCode.toString();
      throw CustomerOrdersException(code);
    }
    if (decoded is! Map) {
      throw const CustomerOrdersException('invalid_response');
    }

    return Map<String, dynamic>.from(decoded);
  }

  void _validateContext(CustomerOrderContext? context) {
    if (context != null && !context.isValidChannel) {
      throw const CustomerOrdersException('invalid_order_context');
    }
  }
}

class CustomerOrdersException implements Exception {
  const CustomerOrdersException(this.code);

  final String code;

  @override
  String toString() => code;
}
