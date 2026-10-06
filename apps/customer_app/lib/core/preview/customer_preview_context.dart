import 'package:flutter/widgets.dart';
import 'package:http/http.dart' as http;

import '../api/b2c_account_api.dart';
import '../api/b2c_catalog_api.dart';
import '../api/customer_action_api.dart';
import '../api/storefront_api.dart';
import '../api/wholesale_commerce_api.dart';
import '../auth/customer_session.dart';
import '../location/customer_location_service.dart';
import '../location/customer_map_pin_selector.dart';

class CustomerPreviewContext {
  const CustomerPreviewContext({
    required this.channel,
    required this.storeId,
    required this.authenticated,
    this.sessionId,
    this.auditCorrelationId,
    this.targetUserId,
    this.targetName = 'Preview Customer',
    this.targetLocale = 'ar',
    this.expiresAt,
    this.configurationRevision,
    this.runtimeVersion,
    this.supportAccess = false,
  }) : assert(storeId > 0, 'Customer preview requires an explicit storeId.');

  factory CustomerPreviewContext.guest({
    required CustomerChannel channel,
    required int storeId,
    String targetLocale = 'ar',
    String? configurationRevision,
    String? runtimeVersion,
    bool supportAccess = false,
  }) =>
      CustomerPreviewContext(
        channel: channel,
        storeId: storeId,
        authenticated: false,
        targetLocale: targetLocale,
        configurationRevision: configurationRevision,
        runtimeVersion: runtimeVersion,
        supportAccess: supportAccess,
      );

  factory CustomerPreviewContext.fromResolvedSession(
    Map<String, dynamic> data, {
    String? configurationRevision,
    String? runtimeVersion,
  }) {
    if (data['target_type'] != 'customer' || data['read_only'] != true) {
      throw const FormatException(
        'Customer preview requires a read-only customer preview session.',
      );
    }

    final channel = switch (data['channel']) {
      'b2b' => CustomerChannel.b2b,
      'b2c' => CustomerChannel.b2c,
      _ => throw const FormatException('Unsupported Customer preview channel.'),
    };
    final storeId = data['store_id'];
    final target = data['target'];
    if (storeId is! int || storeId <= 0 || target is! Map) {
      throw const FormatException('Incomplete Customer preview session context.');
    }

    return CustomerPreviewContext(
      channel: channel,
      storeId: storeId,
      authenticated: true,
      sessionId: data['session_id']?.toString(),
      auditCorrelationId: data['audit_correlation_id']?.toString(),
      targetUserId: target['user_id'] is int ? target['user_id'] as int : null,
      targetName: target['name']?.toString() ?? 'Preview Customer',
      targetLocale: target['locale']?.toString() ?? 'ar',
      expiresAt: data['expires_at']?.toString(),
      configurationRevision: configurationRevision,
      runtimeVersion: runtimeVersion,
      supportAccess: data['support_access'] == true,
    );
  }

  final CustomerChannel channel;
  final int storeId;
  final bool authenticated;
  final String? sessionId;
  final String? auditCorrelationId;
  final int? targetUserId;
  final String targetName;
  final String targetLocale;
  final String? expiresAt;
  final String? configurationRevision;
  final String? runtimeVersion;
  final bool supportAccess;

  bool get mutationsAllowed => false;

  String get authMode => authenticated ? 'platform_customer' : 'guest';

  Map<String, Object?> get commerceContext => {
        'channel': channel.name,
        'store_id': storeId,
      };

  CustomerSession get runtimeIdentity => authenticated
      ? const CustomerSession.platformCustomer()
      : const CustomerSession.guest();

  bool allowsStore(int candidateStoreId) => candidateStoreId == storeId;
}

class CustomerPreviewMutationBlocked implements Exception {
  const CustomerPreviewMutationBlocked(this.action);

  final String action;

  @override
  String toString() => 'Customer preview mutation blocked: $action';
}

class CustomerPreviewScopeException implements Exception {
  const CustomerPreviewScopeException(this.code);

  final String code;

  @override
  String toString() => code;
}

void _requireStore(CustomerPreviewContext context, int storeId) {
  if (!context.allowsStore(storeId)) {
    throw const CustomerPreviewScopeException('preview_store_scope_mismatch');
  }
}

void _requireChannel(
  CustomerPreviewContext context,
  CustomerChannel channel,
) {
  if (context.channel != channel) {
    throw const CustomerPreviewScopeException('preview_channel_scope_mismatch');
  }
}

class PreviewCustomerActionApi implements CustomerActionApi {
  const PreviewCustomerActionApi();

  @override
  Future<CustomerLoginResult> login({required String username}) =>
      Future<CustomerLoginResult>.error(
        const CustomerPreviewMutationBlocked('login'),
      );

  @override
  Future<void> logout() => Future<void>.error(
        const CustomerPreviewMutationBlocked('logout'),
      );

  @override
  Future<Object?> addCartItem({
    required int storeId,
    required int productId,
    required double quantity,
  }) =>
      Future<Object?>.error(
        const CustomerPreviewMutationBlocked('cart.add'),
      );

  @override
  Future<Object?> checkout({
    required int addressId,
    int? storeId,
    String? paymentMethod,
    String? couponCode,
    required String idempotencyKey,
  }) =>
      Future<Object?>.error(
        const CustomerPreviewMutationBlocked('checkout'),
      );
}

class PreviewB2cAccountApi implements B2cAccountApi {
  const PreviewB2cAccountApi(this.delegate, this.context);

  final B2cAccountApi delegate;
  final CustomerPreviewContext context;

  @override
  Future<Object?> cart({int? storeId}) {
    if (storeId != null && context.channel == CustomerChannel.b2c) {
      _requireStore(context, storeId);
    }
    return delegate.cart(storeId: storeId);
  }

  @override
  Future<Object?> order(int orderId) => delegate.order(orderId);

  @override
  Future<Object?> orders() => delegate.orders();

  @override
  Future<Object?> profile() => delegate.profile();

  @override
  Future<Object?> addresses() => delegate.addresses();

  @override
  Future<Object?> favorites() => delegate.favorites();

  @override
  Future<Object?> accountDeletionStatus() => delegate.accountDeletionStatus();

  @override
  Future<Object?> requestAccountDeletion(String password) =>
      Future<Object?>.error(
        const CustomerPreviewMutationBlocked('account.delete'),
      );

  @override
  Future<Object?> notifications({String locale = 'ar'}) =>
      delegate.notifications(locale: locale);

  @override
  Future<Object?> updateCartItem(int itemId, double quantity) =>
      Future<Object?>.error(
        const CustomerPreviewMutationBlocked('cart.update'),
      );

  @override
  Future<void> removeCartItem(int itemId) => Future<void>.error(
        const CustomerPreviewMutationBlocked('cart.remove'),
      );

  @override
  Future<Object?> updateProfile(Map<String, dynamic> values) =>
      Future<Object?>.error(
        const CustomerPreviewMutationBlocked('profile.update'),
      );

  @override
  Future<Object?> createAddress(Map<String, dynamic> values) =>
      Future<Object?>.error(
        const CustomerPreviewMutationBlocked('address.create'),
      );

  @override
  Future<Object?> updateAddress(
    int addressId,
    Map<String, dynamic> values,
  ) =>
      Future<Object?>.error(
        const CustomerPreviewMutationBlocked('address.update'),
      );

  @override
  Future<Object?> setDefaultAddress(int addressId) =>
      Future<Object?>.error(
        const CustomerPreviewMutationBlocked('address.default'),
      );

  @override
  Future<void> removeAddress(int addressId) => Future<void>.error(
        const CustomerPreviewMutationBlocked('address.remove'),
      );

  @override
  Future<void> addFavorite(int productId) => Future<void>.error(
        const CustomerPreviewMutationBlocked('favorite.add'),
      );

  @override
  Future<void> removeFavorite(int productId) => Future<void>.error(
        const CustomerPreviewMutationBlocked('favorite.remove'),
      );

  @override
  Future<void> markNotificationRead(int notificationId) => Future<void>.error(
        const CustomerPreviewMutationBlocked('notification.read'),
      );
}

class PreviewB2cCatalogApi implements B2cCatalogApi {
  const PreviewB2cCatalogApi(this.delegate, this.context);

  final B2cCatalogApi delegate;
  final CustomerPreviewContext context;

  @override
  Future<List<B2cStore>> stores() async {
    final stores = await delegate.stores();
    if (context.channel != CustomerChannel.b2c) {
      return stores;
    }

    return stores
        .where((store) => context.allowsStore(store.id))
        .toList(growable: false);
  }

  @override
  Future<List<B2cCategory>> categories(int storeId) {
    _requireRetailStore(storeId);
    return delegate.categories(storeId);
  }

  @override
  Future<List<B2cProduct>> products(
    int storeId, {
    String? query,
    int? categoryId,
    String? sort,
    String? direction,
  }) {
    _requireRetailStore(storeId);
    return delegate.products(
      storeId,
      query: query,
      categoryId: categoryId,
      sort: sort,
      direction: direction,
    );
  }

  @override
  Future<List<B2cOffer>> offers(int storeId) {
    _requireRetailStore(storeId);
    return delegate.offers(storeId);
  }

  @override
  Future<List<B2cBanner>> banners(int storeId) {
    _requireRetailStore(storeId);
    return delegate.banners(storeId);
  }

  @override
  Future<B2cProduct> product(int productId, {required int storeId}) {
    _requireRetailStore(storeId);
    return delegate.product(productId, storeId: storeId);
  }

  void _requireRetailStore(int storeId) {
    _requireChannel(context, CustomerChannel.b2c);
    _requireStore(context, storeId);
  }
}

class PreviewStorefrontApi implements StorefrontApi {
  const PreviewStorefrontApi(this.delegate, this.context);

  final StorefrontApi delegate;
  final CustomerPreviewContext context;

  @override
  Future<Map<String, dynamic>> selection({
    String? countryCode,
    String? city,
    String? area,
    bool support = false,
  }) async {
    final value = await delegate.selection(
      countryCode: countryCode,
      city: city,
      area: area,
      support: false,
    );

    if (context.channel != CustomerChannel.b2c) {
      return value;
    }

    final retailStores = (value['retail_stores'] as List?)
            ?.whereType<Map>()
            .map((row) => Map<String, dynamic>.from(row))
            .where((row) => (row['id'] as num?)?.toInt() == context.storeId)
            .toList(growable: false) ??
        const <Map<String, dynamic>>[];

    return {
      ...value,
      'retail_stores': retailStores,
      'wholesale_stores': const <Object>[],
      'entitlements': {
        'direct_b2b': false,
        'retail_context_ids': [context.storeId],
        'support_access': false,
      },
    };
  }

  @override
  Future<Map<String, dynamic>> retailHome(int storeId) {
    _requireChannel(context, CustomerChannel.b2c);
    _requireStore(context, storeId);
    return delegate.retailHome(storeId);
  }

  @override
  Future<Map<String, dynamic>> wholesaleHome(int storeId) {
    _requireChannel(context, CustomerChannel.b2b);
    _requireStore(context, storeId);
    return delegate.wholesaleHome(storeId);
  }

  @override
  Future<Map<String, dynamic>> b2bCheckoutOptions(int storeId) {
    _requireChannel(context, CustomerChannel.b2b);
    _requireStore(context, storeId);
    return delegate.b2bCheckoutOptions(storeId);
  }
}

class PreviewWholesaleCommerceApi implements WholesaleCommerceApi {
  const PreviewWholesaleCommerceApi(this.delegate, this.context);

  final WholesaleCommerceApi delegate;
  final CustomerPreviewContext context;

  @override
  Future<Object?> cart(int storeId) {
    _requireChannel(context, CustomerChannel.b2b);
    _requireStore(context, storeId);
    return delegate.cart(storeId);
  }

  @override
  Future<Object?> addItem(int storeId, int productId, double quantity) {
    _requireChannel(context, CustomerChannel.b2b);
    _requireStore(context, storeId);
    return Future<Object?>.error(
      const CustomerPreviewMutationBlocked('wholesale.cart.add'),
    );
  }

  @override
  Future<Object?> updateItem(int itemId, double quantity) =>
      Future<Object?>.error(
        const CustomerPreviewMutationBlocked('wholesale.cart.update'),
      );

  @override
  Future<void> removeItem(int itemId) => Future<void>.error(
        const CustomerPreviewMutationBlocked('wholesale.cart.remove'),
      );

  @override
  Future<Object?> checkout({
    required int storeId,
    required int addressId,
    required String paymentMethod,
    String? requestedDeliveryDate,
    String? note,
    String? couponCode,
    required String idempotencyKey,
  }) {
    _requireChannel(context, CustomerChannel.b2b);
    _requireStore(context, storeId);
    return Future<Object?>.error(
      const CustomerPreviewMutationBlocked('wholesale.checkout'),
    );
  }
}

class PreviewCustomerLocationService implements CustomerLocationService {
  const PreviewCustomerLocationService();

  @override
  Future<CustomerLocationPoint> currentLocation() =>
      Future<CustomerLocationPoint>.error(
        const CustomerLocationException('preview_location_unavailable'),
      );
}

Future<CustomerMapPinSelection?> previewCustomerMapPinPicker(
  BuildContext context, {
  double? initialLatitude,
  double? initialLongitude,
}) async =>
    null;

class PreviewReadOnlyHttpClient extends http.BaseClient {
  PreviewReadOnlyHttpClient(this.delegate);

  final http.Client delegate;

  @override
  Future<http.StreamedResponse> send(http.BaseRequest request) {
    if (request.method.toUpperCase() != 'GET') {
      return Future<http.StreamedResponse>.error(
        CustomerPreviewMutationBlocked(
          'marketplace.${request.method.toLowerCase()}',
        ),
      );
    }

    return delegate.send(request);
  }

  @override
  void close() {
    // The Dashboard host owns the injected transport lifecycle.
  }
}
