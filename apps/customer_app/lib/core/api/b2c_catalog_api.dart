import 'dart:convert';

import 'package:http/http.dart' as http;

class B2cStore {
  const B2cStore({
    required this.id,
    required this.name,
    required this.code,
    this.themeCode = 'retail_grocery',
    this.address,
    this.logoUrl,
  });

  final int id;
  final String name;
  final String code;
  final String themeCode;
  final String? address;
  final String? logoUrl;

  factory B2cStore.fromJson(Map<String, dynamic> json) => B2cStore(
        id: (json['id'] as num).toInt(),
        name: json['name'] as String? ?? '',
        code: json['code'] as String? ?? '',
        themeCode: json['theme_code'] as String? ?? 'retail_grocery',
        address: json['address'] as String?,
        logoUrl: json['logo_url'] as String?,
      );
}

class B2cCategory {
  const B2cCategory({required this.id, required this.name, this.imageUrl});
  final int id;
  final String name;
  final String? imageUrl;

  factory B2cCategory.fromJson(Map<String, dynamic> json) => B2cCategory(
        id: (json['id'] as num).toInt(),
        name: json['name'] as String? ?? '',
        imageUrl: json['image_url'] as String?,
      );
}

class B2cProduct {
  const B2cProduct({
    required this.id,
    required this.name,
    required this.sku,
    this.price,
    this.currency = 'EGP',
    this.description,
    this.categoryId,
    this.brandId,
    this.brandName,
    this.brandImageUrl,
    this.imageUrl,
    this.thumbnailUrl,
    this.images = const [],
    this.availableQuantity,
    this.isAvailable = true,
    this.availabilityState = 'AVAILABLE',
  });

  final int id;
  final String name;
  final String sku;
  final double? price;
  final String currency;
  final String? description;
  final int? categoryId;
  final int? brandId;
  final String? brandName;
  final String? brandImageUrl;
  final String? imageUrl;
  final String? thumbnailUrl;
  final List<String> images;
  final double? availableQuantity;
  final bool isAvailable;
  final String availabilityState;

  bool get isOutOfStock => availabilityState == 'OUT_OF_STOCK' || !isAvailable;

  factory B2cProduct.fromJson(Map<String, dynamic> json) => B2cProduct(
        id: (json['id'] as num).toInt(),
        name: json['name'] as String? ?? '',
        sku: json['sku'] as String? ?? '',
        price: (json['price'] as num?)?.toDouble(),
        currency: json['currency'] as String? ?? 'EGP',
        description: json['description'] as String?,
        categoryId: (json['category_id'] as num?)?.toInt(),
        brandId: (json['brand_id'] as num?)?.toInt(),
        brandName: json['brand_name'] as String?,
        brandImageUrl: json['brand_image_url'] as String?,
        imageUrl: json['image_url'] as String?,
        thumbnailUrl: json['thumbnail_url'] as String?,
        images: (json['images'] as List?)
                ?.whereType<String>()
                .toList(growable: false) ??
            const [],
        availableQuantity: (json['available_quantity'] as num?)?.toDouble(),
        isAvailable: json['is_available'] as bool? ?? true,
        availabilityState: json['availability_state'] as String? ??
            (json['is_available'] == false ? 'OUT_OF_STOCK' : 'AVAILABLE'),
      );
}

class B2cOffer {
  const B2cOffer({
    required this.id,
    required this.name,
    required this.type,
    this.value,
  });

  final int id;
  final String name;
  final String type;
  final double? value;

  factory B2cOffer.fromJson(Map<String, dynamic> json) => B2cOffer(
        id: (json['id'] as num).toInt(),
        name: json['name'] as String? ?? '',
        type: json['type'] as String? ?? '',
        value: (json['value'] as num?)?.toDouble(),
      );
}

class B2cBanner {
  const B2cBanner({
    required this.id,
    required this.title,
    this.imageUrl,
    this.targetUrl,
  });

  final int id;
  final String title;
  final String? imageUrl;
  final String? targetUrl;

  factory B2cBanner.fromJson(Map<String, dynamic> json) => B2cBanner(
        id: (json['id'] as num).toInt(),
        title: json['title'] as String? ?? '',
        imageUrl: json['image_url'] as String?,
        targetUrl: json['target_url'] as String?,
      );
}

abstract interface class B2cCatalogApi {
  Future<List<B2cStore>> stores();
  Future<List<B2cCategory>> categories(int storeId);
  Future<List<B2cProduct>> products(
    int storeId, {
    String? query,
    int? categoryId,
    String? sort,
    String? direction,
  });
  Future<List<B2cOffer>> offers(int storeId);
  Future<List<B2cBanner>> banners(int storeId);
  Future<B2cProduct> product(int productId, {required int storeId});
}

class HttpB2cCatalogApi implements B2cCatalogApi {
  HttpB2cCatalogApi({
    required this.baseUrl,
    this.token,
    http.Client? client,
  }) : _client = client ?? http.Client();

  final String baseUrl;
  final String? token;
  final http.Client _client;

  @override
  Future<List<B2cStore>> stores() async =>
      _collection('/api/v1/stores', B2cStore.fromJson);

  @override
  Future<List<B2cCategory>> categories(int storeId) async {
    _requireStoreId(storeId);
    return _collection(
      '/api/v1/stores/$storeId/categories',
      B2cCategory.fromJson,
      storeId: storeId,
    );
  }

  @override
  Future<List<B2cProduct>> products(
    int storeId, {
    String? query,
    int? categoryId,
    String? sort,
    String? direction,
  }) async {
    final params = <String, String>{
      if (query != null && query.trim().isNotEmpty) 'q': query.trim(),
      if (categoryId != null) 'category': '$categoryId',
      if (sort != null && sort.trim().isNotEmpty) 'sort': sort.trim(),
      if (direction != null && direction.trim().isNotEmpty)
        'direction': direction.trim(),
    };
    _requireStoreId(storeId);
    return _collection(
      '/api/v1/stores/$storeId/products',
      B2cProduct.fromJson,
      query: params,
      storeId: storeId,
    );
  }

  @override
  Future<List<B2cOffer>> offers(int storeId) async {
    _requireStoreId(storeId);
    return _collection(
      '/api/v1/stores/$storeId/offers',
      B2cOffer.fromJson,
      storeId: storeId,
    );
  }

  @override
  Future<List<B2cBanner>> banners(int storeId) async {
    _requireStoreId(storeId);
    return _collection(
      '/api/v1/stores/$storeId/banners',
      B2cBanner.fromJson,
      storeId: storeId,
    );
  }

  @override
  Future<B2cProduct> product(int productId, {required int storeId}) async {
    _requireStoreId(storeId);
    if (productId <= 0) {
      throw ArgumentError.value(productId, 'productId', 'must be positive');
    }
    final body = await _get(
      '/api/v1/products/$productId',
      query: {'store': '$storeId'},
      storeId: storeId,
    );
    if (body is! Map<String, dynamic>) {
      throw const B2cCatalogException('invalid_product_response');
    }
    return B2cProduct.fromJson(body);
  }

  Future<List<T>> _collection<T>(
    String path,
    T Function(Map<String, dynamic>) factory, {
    Map<String, String> query = const {},
    int? storeId,
  }) async {
    final body = await _get(path, query: query, storeId: storeId);
    if (body is! Map || body['data'] is! List) {
      throw const B2cCatalogException('invalid_collection_response');
    }
    return (body['data'] as List)
        .whereType<Map>()
        .map((item) => factory(Map<String, dynamic>.from(item)))
        .toList(growable: false);
  }

  Future<Object?> _get(
    String path, {
    Map<String, String> query = const {},
    int? storeId,
  }) async {
    final base = Uri.parse('$baseUrl$path');
    final uri = query.isEmpty ? base : base.replace(queryParameters: query);
    final response = await _client.get(
      uri,
      headers: {
        'Accept': 'application/json',
        if (token != null && token!.isNotEmpty)
          'Authorization': 'Bearer $token',
        if (storeId != null) 'X-FOODEX-Store-ID': '$storeId',
      },
    );

    Object? body;
    if (response.body.isNotEmpty) {
      try {
        body = jsonDecode(response.body);
      } catch (_) {
        body = null;
      }
    }

    if (response.statusCode < 200 || response.statusCode >= 300) {
      var code = 'http_${response.statusCode}';
      if (body is Map && body['code'] is String) {
        final serverCode = (body['code'] as String).trim();
        if (serverCode.isNotEmpty) code = serverCode;
      }
      throw B2cCatalogException(code);
    }
    return body;
  }

  static void _requireStoreId(int storeId) {
    if (storeId <= 0) {
      throw ArgumentError.value(storeId, 'storeId', 'must be positive');
    }
  }
}

class B2cCatalogException implements Exception {
  const B2cCatalogException(this.code);

  static const String selfStorePurchaseNotAllowed =
      'SELF_STORE_PURCHASE_NOT_ALLOWED';

  final String code;

  bool get isSelfStorePurchaseNotAllowed => code == selfStorePurchaseNotAllowed;
}
