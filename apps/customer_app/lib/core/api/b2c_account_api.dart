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
  Future<Object?> setDefaultAddress(int addressId);
  Future<void> removeAddress(int addressId);
  Future<Object?> favorites();
  Future<void> addFavorite(int productId);
  Future<void> removeFavorite(int productId);
  Future<Object?> notifications({String locale = 'ar'});
  Future<void> markNotificationRead(int notificationId);
}

abstract interface class B2cRetailFavoritesApi {
  Future<Object?> favoritesForStore(int storeId);
  Future<void> addFavoriteForStore(int storeId, int productId);
  Future<void> removeFavoriteForStore(int storeId, int productId);
}

class HttpB2cAccountApi implements B2cAccountApi, B2cRetailFavoritesApi {
  HttpB2cAccountApi({
    required this.baseUrl,
    this.token,
    required this.guestSession,
    this.customerDomain = 'b2c',
    this.retailStoreContextId,
    http.Client? client,
  })  : assert(customerDomain == 'b2c' || customerDomain == 'b2b'),
        _client = client ?? http.Client();

  final String baseUrl;
  final String? token;
  final CustomerGuestSession guestSession;
  final String customerDomain;
  final int? retailStoreContextId;
  final http.Client _client;

  Map<String, String> get _headers => _headersForStore(null);

  Map<String, String> _headersForStore(int? storeId) {
    final guestToken = storeId == null
        ? guestSession.token
        : guestSession.tokenForStore(storeId);

    return {
      'Accept': 'application/json',
      'Content-Type': 'application/json',
      if (token != null && token!.isNotEmpty) 'Authorization': 'Bearer $token',
      if (guestToken != null) 'X-Guest-Token': guestToken,
      if (storeId != null) 'X-FOODEX-Store-ID': storeId.toString(),
      if (retailStoreContextId != null)
        'X-FOODEX-Retail-Store-ID': retailStoreContextId.toString(),
      'X-FOODEX-Customer-Domain': customerDomain,
    };
  }

  @override
  Future<Object?> cart({int? storeId}) async {
    final uri = Uri.parse('$baseUrl/api/v1/cart').replace(
      queryParameters: storeId == null ? null : {'store': '$storeId'},
    );
    final response = await _send(
      () => _client.get(uri, headers: _headersForStore(storeId)),
    );
    if (storeId != null) {
      guestSession.activateStore(storeId);
    }
    _captureGuestToken(response, storeId: storeId);
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
  Future<Object?> setDefaultAddress(int addressId) async {
    _requireToken();
    return _write('POST', '/api/v1/profile/addresses/$addressId/default', const {});
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
  Future<Object?> favoritesForStore(int storeId) async {
    _requireToken();
    return _get('/api/v1/profile/favorites', storeId: storeId);
  }

  @override
  Future<void> addFavoriteForStore(int storeId, int productId) async {
    _requireToken();
    final response = await _send(
      () => _client.post(
        Uri.parse('$baseUrl/api/v1/profile/favorites/$productId'),
        headers: _headersForStore(storeId),
      ),
    );
    if (response.statusCode < 200 || response.statusCode >= 300) {
      _decode(response);
    }
  }

  @override
  Future<void> removeFavoriteForStore(int storeId, int productId) async {
    _requireToken();
    final response = await _send(
      () => _client.delete(
        Uri.parse('$baseUrl/api/v1/profile/favorites/$productId'),
        headers: _headersForStore(storeId),
      ),
    );
    if (response.statusCode < 200 || response.statusCode >= 300) {
      _decode(response);
    }
  }

  @override
  Future<Object?> accountDeletionStatus() async {
    _requireToken();
    return _get('/api/v1/account-deletion');
  }

  Future<Object?> requestAccountDeletion(String password) async {
    _requireToken();
    return _write('POST', '/api/v1/account-deletion', {
      'password': password,
      'confirmation': true,
    });
  }

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

  Future<Object?> _get(String path, {int? storeId}) async {
    final response = await _send(
      () => _client.get(
        Uri.parse('$baseUrl$path'),
        headers: _headersForStore(storeId),
      ),
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

  void _captureGuestToken(http.Response response, {int? storeId}) {
    final value = response.headers['x-guest-token'];
    if (value == null || value.trim().isEmpty) return;

    if (storeId != null) {
      guestSession.captureStoreToken(storeId, value.trim());
    } else {
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
      final serverCode = body is Map && body['code'] is String
          ? (body['code'] as String).trim()
          : '';
      throw B2cAccountException(
        serverCode.isEmpty ? 'forbidden' : serverCode,
      );
    }
    if (response.statusCode < 200 || response.statusCode >= 300) {
      final serverMessage = body is Map && body['message'] is String
          ? (body['message'] as String).trim()
          : null;
      final fieldErrors = <String, List<String>>{};
      if (body is Map && body['errors'] is Map) {
        for (final entry in (body['errors'] as Map).entries) {
          final value = entry.value;
          fieldErrors[entry.key.toString()] = value is List
              ? value.map((item) => item.toString()).toList(growable: false)
              : <String>[value.toString()];
        }
      }
      throw B2cAccountException(
        'http_${response.statusCode}',
        serverMessage: serverMessage == null || serverMessage.isEmpty
            ? null
            : serverMessage,
        fieldErrors: fieldErrors,
      );
    }
    return body;
  }
}

class B2cAccountException implements Exception {
  const B2cAccountException(
    this.code, {
    this.serverMessage,
    this.fieldErrors = const <String, List<String>>{},
  });
  final String code;
  final String? serverMessage;
  final Map<String, List<String>> fieldErrors;
}
