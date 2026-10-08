import 'dart:convert';

import 'package:http/http.dart' as http;

class CustomerGuestSession {
  CustomerGuestSession({String? token}) : _legacyToken = token;

  final Map<int, String> _storeTokens = <int, String>{};
  String? _legacyToken;
  int? _activeStoreId;

  int? get activeStoreId => _activeStoreId;

  String? get token {
    final storeId = _activeStoreId;
    if (storeId != null && _storeTokens.containsKey(storeId)) {
      return _storeTokens[storeId];
    }
    return _legacyToken;
  }

  set token(String? value) {
    final storeId = _activeStoreId;
    if (storeId == null) {
      _legacyToken = value;
      return;
    }
    if (value == null || value.isEmpty) {
      _storeTokens.remove(storeId);
    } else {
      _storeTokens[storeId] = value;
    }
  }

  String? tokenForStore(int storeId) {
    return _storeTokens[storeId] ?? _legacyToken;
  }

  void activateStore(int storeId) {
    _activeStoreId = storeId;
  }

  void captureStoreToken(int storeId, String value) {
    _activeStoreId = storeId;
    _storeTokens[storeId] = value;
    _legacyToken = null;
  }

  void clear() {
    _storeTokens.clear();
    _legacyToken = null;
    _activeStoreId = null;
  }
}

class CustomerLoginResult {
  const CustomerLoginResult({
    required this.token,
    this.platformCustomer = false,
  });

  final String token;
  final bool platformCustomer;
}

abstract interface class CustomerActionApi {
  Future<CustomerLoginResult> login({required String username});

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

  Map<String, String> get _headers => _headersForStore(null);

  Map<String, String> _headersForStore(int? storeId) {
    final guestToken = storeId == null
        ? guestSession.token
        : guestSession.tokenForStore(storeId);

    return {
      'Accept': 'application/json',
      'Content-Type': 'application/json',
      if (token != null) 'Authorization': 'Bearer $token',
      if (guestToken != null) 'X-Guest-Token': guestToken,
      if (storeId != null) 'X-FOODEX-Store-ID': storeId.toString(),
      if (b2bRetailStoreId != null)
        'X-FOODEX-Retail-Store-ID': b2bRetailStoreId.toString(),
    };
  }

  Future<CustomerLoginResult> credentialLogin({
    required String email,
    required String password,
  }) async {
    final response = await _client.post(
      Uri.parse('$baseUrl/api/v1/auth/login'),
      headers: _headers,
      body: jsonEncode({
        'email': email.trim().toLowerCase(),
        'password': password,
        'app': 'customer',
      }),
    );
    final body = _decode(response);
    final value = body is Map ? body['token'] : null;
    if (value is! String || value.isEmpty) {
      throw const CustomerActionException('invalid_login_response');
    }

    final user = body is Map && body['user'] is Map
        ? Map<String, dynamic>.from(body['user'] as Map)
        : const <String, dynamic>{};

    return CustomerLoginResult(
      token: value,
      platformCustomer: user['platform_customer'] == true,
    );
  }

  Future<CustomerLoginResult> credentialRegister({
    required String name,
    required String email,
    required String phone,
    required String password,
    required String passwordConfirmation,
    required String locale,
    int? storeId,
  }) async {
    final response = await _client.post(
      Uri.parse('$baseUrl/api/v1/auth/register'),
      headers: _headersForStore(storeId),
      body: jsonEncode({
        'name': name.trim(),
        'email': email.trim().toLowerCase(),
        'phone': phone.trim(),
        'password': password,
        'password_confirmation': passwordConfirmation,
        'locale': locale == 'en' ? 'en' : 'ar',
        if (storeId != null) 'store_id': storeId,
      }),
    );
    final body = _decode(response);
    final value = body is Map ? body['token'] : null;
    if (value is! String || value.isEmpty) {
      throw const CustomerActionException('invalid_registration_response');
    }

    return CustomerLoginResult(
      token: value,
      platformCustomer: true,
    );
  }

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

    final user = body is Map && body['user'] is Map
        ? Map<String, dynamic>.from(body['user'] as Map)
        : const <String, dynamic>{};

    return CustomerLoginResult(
      token: value,
      platformCustomer: user['platform_customer'] == true,
    );
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
      headers: _headersForStore(storeId),
      body: jsonEncode({
        'store_id': storeId,
        'product_id': productId,
        'quantity': quantity,
      }),
    );

    final value = _decode(response);
    final guestToken = response.headers['x-guest-token'];
    if (guestToken != null && guestToken.trim().isNotEmpty) {
      guestSession.captureStoreToken(storeId, guestToken.trim());
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
    if (storeId == null || storeId <= 0) {
      throw const CustomerActionException(
        'store_required',
        fieldErrors: <String, List<String>>{
          'store_id': <String>['A Retail store is required for checkout.'],
        },
      );
    }
    final response = await _client.post(
      Uri.parse('$baseUrl/api/v1/checkout'),
      headers: {
        ..._headersForStore(storeId),
        'Idempotency-Key': idempotencyKey,
      },
      body: jsonEncode({
        'address_id': addressId,
        'store_id': storeId,
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
      if (body is Map && body['code'] is String) {
        final serverCode = (body['code'] as String).trim();
        if (serverCode.isNotEmpty) code = serverCode;
      } else if (body is Map && body['message'] is String) {
        code = body['message'] as String;
      }
      throw CustomerActionException(
        code,
        fieldErrors: _validationErrors(body),
      );
    }

    return body;
  }

  Map<String, List<String>> _validationErrors(Object? body) {
    if (body is! Map || body['errors'] is! Map) {
      return const <String, List<String>>{};
    }

    final result = <String, List<String>>{};
    for (final entry in (body['errors'] as Map).entries) {
      final value = entry.value;
      result[entry.key.toString()] = value is List
          ? value.map((item) => item.toString()).toList(growable: false)
          : <String>[value.toString()];
    }
    return result;
  }
}

class CustomerActionException implements Exception {
  const CustomerActionException(
    this.code, {
    this.fieldErrors = const <String, List<String>>{},
  });

  final String code;
  final Map<String, List<String>> fieldErrors;
}
