import 'dart:convert';

import 'package:http/http.dart' as http;

class CustomerGuestSession {
  CustomerGuestSession({this.token});
  String? token;
}

class CustomerLoginResult {
  const CustomerLoginResult({required this.token});

  final String token;
}

abstract interface class CustomerActionApi {
  Future<CustomerLoginResult> login({required String email, required String password});

  Future<Object?> addCartItem({
    required int storeId,
    required int productId,
    required double quantity,
  });

  Future<Object?> checkout({
    required int addressId,
    String? paymentMethod,
    required String idempotencyKey,
  });
}

class HttpCustomerActionApi implements CustomerActionApi {
  HttpCustomerActionApi({
    required this.baseUrl,
    this.token,
    CustomerGuestSession? guestSession,
    http.Client? client,
  })  : guestSession = guestSession ?? CustomerGuestSession(),
        _client = client ?? http.Client();

  final String baseUrl;
  final String? token;
  final CustomerGuestSession guestSession;
  final http.Client _client;

  Map<String, String> get _headers => {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        if (token != null) 'Authorization': 'Bearer $token',
        if (guestSession.token != null) 'X-Guest-Token': guestSession.token!,
      };

  @override
  Future<CustomerLoginResult> login({
    required String email,
    required String password,
  }) async {
    final response = await _client.post(
      Uri.parse('$baseUrl/api/v1/auth/login'),
      headers: _headers,
      body: jsonEncode({'email': email, 'password': password}),
    );
    final body = _decode(response);
    final value = body is Map ? body['token'] : null;
    if (value is! String || value.isEmpty) {
      throw const CustomerActionException('invalid_login_response');
    }

    return CustomerLoginResult(token: value);
  }

  @override
  Future<Object?> addCartItem({
    required int storeId,
    required int productId,
    required double quantity,
  }) async {
    _requireToken();
    final response = await _client.post(
      Uri.parse('$baseUrl/api/v1/cart/items'),
      headers: _headers,
      body: jsonEncode({
        'store_id': storeId,
        'product_id': productId,
        'quantity': quantity,
      }),
    );

    final value = _decode(response);
    final guestToken = response.headers['x-guest-token'];
    if (guestToken != null && guestToken.trim().isNotEmpty) {
      guestSession.token = guestToken.trim();
    }
    return value;
  }

  @override
  Future<Object?> checkout({
    required int addressId,
    String? paymentMethod,
    required String idempotencyKey,
  }) async {
    _requireToken();
    final response = await _client.post(
      Uri.parse('$baseUrl/api/v1/checkout'),
      headers: {..._headers, 'Idempotency-Key': idempotencyKey},
      body: jsonEncode({
        'address_id': addressId,
        if (paymentMethod != null && paymentMethod.trim().isNotEmpty)
          'payment_method': paymentMethod.trim(),
      }),
    );

    return _decode(response);
  }

  void _requireToken() {
    if (token == null || token!.isEmpty) {
      throw const CustomerActionException('authentication_required');
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
      String code = 'http_${response.statusCode}';
      if (body is Map && body['message'] is String) {
        code = body['message'] as String;
      }
      throw CustomerActionException(code);
    }

    return body;
  }
}

class CustomerActionException implements Exception {
  const CustomerActionException(this.code);

  final String code;
}
