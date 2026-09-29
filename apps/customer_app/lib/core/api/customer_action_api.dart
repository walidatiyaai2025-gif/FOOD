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

class CustomerRegistrationResult {
  const CustomerRegistrationResult({required this.token});

  final String token;
}

abstract interface class CustomerActionApi {
  Future<CustomerLoginResult> login({required String username});

  Future<CustomerLoginResult> loginWithPassword({
    required String email,
    required String password,
  });

  Future<CustomerRegistrationResult> register({
    required String name,
    required String email,
    required String phone,
    required String password,
    required String passwordConfirmation,
    String locale = 'ar',
  });

  Future<void> logout();

  Future<Object?> addCartItem({
    required int storeId,
    required int productId,
    required double quantity,
  });

  Future<Object?> checkout({
    required int addressId,
    int? storeId,
    String? paymentMethod,
    String? couponCode,
    required String idempotencyKey,
  });
}

class HttpCustomerActionApi implements CustomerActionApi {
  HttpCustomerActionApi({
    required this.baseUrl,
    this.token,
    CustomerGuestSession? guestSession,
    this.b2bRetailStoreId,
    http.Client? client,
  })  : guestSession = guestSession ?? CustomerGuestSession(),
        _client = client ?? http.Client();

  final String baseUrl;
  final String? token;
  final CustomerGuestSession guestSession;
  final int? b2bRetailStoreId;
  final http.Client _client;

  Map<String, String> get _headers => {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        if (token != null) 'Authorization': 'Bearer $token',
        if (guestSession.token != null) 'X-Guest-Token': guestSession.token!,
        if (b2bRetailStoreId != null)
          'X-FOODEX-Retail-Store-ID': b2bRetailStoreId.toString(),
      };

  @override
  Future<CustomerLoginResult> login({
    required String username,
  }) async {
    final response = await _client.post(
      Uri.parse('$baseUrl/api/v1/auth/mobile-trial'),
      headers: _headers,
      body: jsonEncode({'username': username, 'app': 'customer'}),
    );
    final body = _decode(response);
    final value = body is Map ? body['token'] : null;
    if (value is! String || value.isEmpty) {
      throw const CustomerActionException('invalid_login_response');
    }

    return CustomerLoginResult(token: value);
  }

  @override
  Future<CustomerLoginResult> loginWithPassword({
    required String email,
    required String password,
  }) async {
    final response = await _client.post(
      Uri.parse('$baseUrl/api/v1/auth/login'),
      headers: _headers,
      body: jsonEncode({
        'email': email.trim().toLowerCase(),
        'password': password,
      }),
    );
    final body = _decode(response);
    final value = body is Map ? body['token'] : null;
    if (value is! String || value.isEmpty) {
      throw const CustomerActionException('invalid_login_response');
    }

    return CustomerLoginResult(token: value);
  }

  @override
  Future<CustomerRegistrationResult> register({
    required String name,
    required String email,
    required String phone,
    required String password,
    required String passwordConfirmation,
    String locale = 'ar',
  }) async {
    final response = await _client.post(
      Uri.parse('$baseUrl/api/v1/auth/register'),
      headers: _headers,
      body: jsonEncode({
        'name': name.trim(),
        'email': email.trim().toLowerCase(),
        'phone': phone.trim(),
        'password': password,
        'password_confirmation': passwordConfirmation,
        'locale': locale,
      }),
    );
    final body = _decode(response);
    final value = body is Map ? body['token'] : null;
    if (value is! String || value.isEmpty) {
      throw const CustomerActionException('invalid_registration_response');
    }

    return CustomerRegistrationResult(token: value);
  }

  @override
  Future<void> logout() async {
    if (token == null || token!.isEmpty) return;
    final response = await _client.post(
      Uri.parse('$baseUrl/api/v1/auth/logout'),
      headers: _headers,
    );
    if (response.statusCode == 401 || response.statusCode == 204) return;
    _decode(response);
  }

  @override
  Future<Object?> addCartItem({
    required int storeId,
    required int productId,
    required double quantity,
  }) async {
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
    int? storeId,
    String? paymentMethod,
    String? couponCode,
    required String idempotencyKey,
  }) async {
    _requireToken();
    final response = await _client.post(
      Uri.parse('$baseUrl/api/v1/checkout'),
      headers: {..._headers, 'Idempotency-Key': idempotencyKey},
      body: jsonEncode({
        'address_id': addressId,
        if (storeId != null) 'store_id': storeId,
        if (paymentMethod != null && paymentMethod.trim().isNotEmpty)
          'payment_method': paymentMethod.trim(),
        if (couponCode != null && couponCode.trim().isNotEmpty)
          'coupon_code': couponCode.trim().toUpperCase(),
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
