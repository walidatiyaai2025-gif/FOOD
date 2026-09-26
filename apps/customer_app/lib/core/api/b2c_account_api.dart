import 'dart:convert';

import 'package:http/http.dart' as http;

import 'customer_action_api.dart';

abstract interface class B2cAccountApi {
  Future<Object?> cart({int? storeId});
  Future<Object?> updateCartItem(int itemId, double quantity);
  Future<void> removeCartItem(int itemId);
  Future<Object?> order(int orderId);
  Future<Object?> orders();
  Future<Object?> profile();
  Future<Object?> updateProfile(Map<String, dynamic> values);
  Future<Object?> addresses();
  Future<Object?> createAddress(Map<String, dynamic> values);
  Future<Object?> updateAddress(int addressId, Map<String, dynamic> values);
  Future<void> removeAddress(int addressId);
  Future<Object?> favorites();
  Future<void> addFavorite(int productId);
  Future<void> removeFavorite(int productId);
  Future<Object?> notifications({String locale = 'ar'});
  Future<void> markNotificationRead(int notificationId);
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
    final response = await _send(() => _client.get(uri, headers: _headers));
    _captureGuestToken(response);
    return _decode(response);
  }

  @override
  Future<Object?> updateCartItem(int itemId, double quantity) async {
    final response = await _send(
      () => _client.patch(
        Uri.parse('$baseUrl/api/v1/cart/items/$itemId'),
        headers: _headers,
        body: jsonEncode({'quantity': quantity}),
      ),
    );
    _captureGuestToken(response);
    return _decode(response);
  }

  @override
  Future<void> removeCartItem(int itemId) async {
    final response = await _send(
      () => _client.delete(
        Uri.parse('$baseUrl/api/v1/cart/items/$itemId'),
        headers: _headers,
      ),
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
  Future<Object?> orders() async {
    _requireToken();
    return _get('/api/v1/orders');
  }

  @override
  Future<Object?> profile() async {
    _requireToken();
    return _get('/api/v1/profile');
  }

  @override
  Future<Object?> updateProfile(Map<String, dynamic> values) async {
    _requireToken();
    return _write('PATCH', '/api/v1/profile', values);
  }

  @override
  Future<Object?> addresses() async {
    _requireToken();
    return _get('/api/v1/profile/addresses');
  }

  @override
  Future<Object?> createAddress(Map<String, dynamic> values) async {
    _requireToken();
    return _write('POST', '/api/v1/profile/addresses', values);
  }

  @override
  Future<Object?> updateAddress(
    int addressId,
    Map<String, dynamic> values,
  ) async {
    _requireToken();
    return _write('PATCH', '/api/v1/profile/addresses/$addressId', values);
  }

  @override
  Future<void> removeAddress(int addressId) async {
    _requireToken();
    final response = await _send(
      () => _client.delete(
        Uri.parse('$baseUrl/api/v1/profile/addresses/$addressId'),
        headers: _headers,
      ),
    );
    if (response.statusCode < 200 || response.statusCode >= 300) {
      _decode(response);
    }
  }

  @override
  Future<Object?> favorites() async {
    _requireToken();
    return _get('/api/v1/profile/favorites');
  }

  @override
  Future<void> addFavorite(int productId) async {
    _requireToken();
    final response = await _send(
      () => _client.post(
        Uri.parse('$baseUrl/api/v1/profile/favorites/$productId'),
        headers: _headers,
      ),
    );
    if (response.statusCode < 200 || response.statusCode >= 300) {
      _decode(response);
    }
  }

  @override
  Future<void> removeFavorite(int productId) async {
    _requireToken();
    final response = await _send(
      () => _client.delete(
        Uri.parse('$baseUrl/api/v1/profile/favorites/$productId'),
        headers: _headers,
      ),
    );
    if (response.statusCode < 200 || response.statusCode >= 300) {
      _decode(response);
    }
  }

  @override
  Future<Object?> notifications({String locale = 'ar'}) async {
    _requireToken();
    final uri = Uri.parse('$baseUrl/api/v1/notifications').replace(
      queryParameters: {'locale': locale},
    );
    final response = await _send(() => _client.get(uri, headers: _headers));
    return _decode(response);
  }

  @override
  Future<void> markNotificationRead(int notificationId) async {
    _requireToken();
    final response = await _send(
      () => _client.post(
        Uri.parse('$baseUrl/api/v1/notifications/$notificationId/read'),
        headers: _headers,
      ),
    );
    if (response.statusCode < 200 || response.statusCode >= 300) {
      _decode(response);
    }
  }

  Future<Object?> _get(String path) async {
    final response = await _send(
      () => _client.get(Uri.parse('$baseUrl$path'), headers: _headers),
    );
    return _decode(response);
  }

  Future<Object?> _write(
    String method,
    String path,
    Map<String, dynamic> values,
  ) async {
    final uri = Uri.parse('$baseUrl$path');
    final body = jsonEncode(values);
    final response = await _send(() {
      if (method == 'POST') {
        return _client.post(uri, headers: _headers, body: body);
      }
      return _client.patch(uri, headers: _headers, body: body);
    });
    return _decode(response);
  }

  Future<http.Response> _send(
    Future<http.Response> Function() operation,
  ) async {
    try {
      return await operation();
    } on http.ClientException {
      throw const B2cAccountException('network_unavailable');
    }
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
    if (response.statusCode == 401) {
      throw const B2cAccountException('session_expired');
    }
    if (response.statusCode == 403) {
      throw const B2cAccountException('forbidden');
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
