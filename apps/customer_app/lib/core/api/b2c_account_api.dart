import 'dart:convert';

import 'package:http/http.dart' as http;

import 'customer_action_api.dart';

abstract interface class B2cAccountApi {
  Future<Object?> cart({int? storeId});
  Future<Object?> updateCartItem(int itemId, double quantity);
  Future<void> removeCartItem(int itemId);
  Future<Object?> order(int orderId);
  Future<Object?> profile();
  Future<Object?> addresses();
  Future<Object?> favorites();
}

class HttpB2cAccountApi implements B2cAccountApi {
  HttpB2cAccountApi({
    required this.baseUrl,
    this.token,
    required this.guestSession,
    http.Client? client,
  }) : _client = client ?? http.Client();

  final String baseUrl;
  final String? token;
  final CustomerGuestSession guestSession;
  final http.Client _client;

  Map<String, String> get _headers => {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        if (token != null && token!.isNotEmpty) 'Authorization': 'Bearer $token',
        if (guestSession.token != null) 'X-Guest-Token': guestSession.token!,
      };

  @override
  Future<Object?> cart({int? storeId}) async {
    final uri = Uri.parse('$baseUrl/api/v1/cart').replace(
      queryParameters: storeId == null ? null : {'store': '$storeId'},
    );
    final response = await _client.get(uri, headers: _headers);
    _captureGuestToken(response);
    return _decode(response);
  }

  @override
  Future<Object?> updateCartItem(int itemId, double quantity) async {
    final response = await _client.patch(
      Uri.parse('$baseUrl/api/v1/cart/items/$itemId'),
      headers: _headers,
      body: jsonEncode({'quantity': quantity}),
    );
    _captureGuestToken(response);
    return _decode(response);
  }

  @override
  Future<void> removeCartItem(int itemId) async {
    final response = await _client.delete(
      Uri.parse('$baseUrl/api/v1/cart/items/$itemId'),
      headers: _headers,
    );
    if (response.statusCode < 200 || response.statusCode >= 300) {
      _decode(response);
    }
  }

  @override
  Future<Object?> order(int orderId) async {
    _requireToken();
    return _get('/api/v1/orders/$orderId');
  }

  @override
  Future<Object?> profile() async {
    _requireToken();
    return _get('/api/v1/profile');
  }

  @override
  Future<Object?> addresses() async {
    _requireToken();
    return _get('/api/v1/profile/addresses');
  }

  @override
  Future<Object?> favorites() async {
    _requireToken();
    return _get('/api/v1/profile/favorites');
  }

  Future<Object?> _get(String path) async {
    final response = await _client.get(Uri.parse('$baseUrl$path'), headers: _headers);
    return _decode(response);
  }

  void _captureGuestToken(http.Response response) {
    final value = response.headers['x-guest-token'];
    if (value != null && value.trim().isNotEmpty) {
      guestSession.token = value.trim();
    }
  }

  void _requireToken() {
    if (token == null || token!.isEmpty) {
      throw const B2cAccountException('authentication_required');
    }
  }

  Object? _decode(http.Response response) {
    Object? body;
    if (response.body.isNotEmpty) {
      try {
        body = jsonDecode(response.body);
      } catch (_) {
        body = null;
      }
    }
    if (response.statusCode < 200 || response.statusCode >= 300) {
      throw B2cAccountException('http_${response.statusCode}');
    }
    return body;
  }
}

class B2cAccountException implements Exception {
  const B2cAccountException(this.code);
  final String code;
}
