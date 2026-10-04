import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;

import '../../app.dart';
import '../api/b2b_api.dart';
import '../api/b2c_account_api.dart';
import '../api/b2c_catalog_api.dart';
import '../api/customer_action_api.dart';
import '../api/storefront_api.dart';
import '../api/wholesale_commerce_api.dart';
import '../auth/customer_session.dart';
import '../engagement/notification_campaign_popup_service.dart';
import '../routing/customer_routes.dart';
import 'customer_preview_bootstrap.dart';
import 'customer_preview_configuration.dart';
import 'customer_preview_context.dart';
import 'customer_preview_transport.dart';

class CustomerPreviewRuntime {
  CustomerPreviewRuntime._({
    required this.app,
    required this.client,
    required this.safeStatusMetadata,
    required this.bootstrap,
    required this.baseUrl,
    required this.dashboardBaseUrl,
    this.authenticatedBundle,
    required this.configuration,
  });

  final Widget app;
  final http.Client client;
  final Map<String, Object?> safeStatusMetadata;
  final CustomerPreviewBootstrap bootstrap;
  final String baseUrl;
  final String dashboardBaseUrl;
  final CustomerPreviewApiBundle? authenticatedBundle;
  final CustomerPreviewResolvedConfiguration configuration;

  http.Client get invalidationClient =>
      authenticatedBundle?.transport ?? client;

  void close() {
    authenticatedBundle?.close();
    client.close();
  }

  static Future<CustomerPreviewRuntime> create({
    required String baseUrl,
    required CustomerPreviewBootstrap bootstrap,
    String? dashboardBaseUrl,
    CustomerPreviewResolvedConfiguration? resolvedConfiguration,
    http.Client? client,
  }) async {
    final transportOwner = client ?? http.Client();
    final context = bootstrap.context;
    final dashboardOrigin = _normalizedBase(
      dashboardBaseUrl == null || dashboardBaseUrl.trim().isEmpty
          ? baseUrl
          : dashboardBaseUrl,
    );
    final initialRoute = context.channel == CustomerChannel.b2c
        ? '/retail/${context.storeId}/home'
        : (context.authenticated
            ? CustomerRoutePaths.b2bHome
            : CustomerRoutePaths.marketplace);

    if (context.authenticated) {
      final credential = bootstrap.credential;
      if (credential == null || credential.isEmpty) {
        transportOwner.close();
        throw const CustomerPreviewBootstrapException(
          'preview_credential_required',
        );
      }

      final bundle = CustomerPreviewApiBundle(
        baseUrl: baseUrl,
        context: context,
        credential: credential,
        client: transportOwner,
      );

      try {
        final configuration = resolvedConfiguration ??
            await CustomerPreviewResolvedConfiguration.resolve(
              baseUrl: baseUrl,
              client: bundle.transport,
              context: context,
              mode: bootstrap.configuration,
            );
        _requireConfigurationScope(
          configuration,
          context: context,
          mode: bootstrap.configuration,
        );
        final storefront = PreviewRevisionStorefrontApi(
          delegate: bundle.storefront,
          context: context,
          configuration: configuration,
          baseUrl: baseUrl,
        );

        final app = FoodexCustomerApp.preview(
          previewContext: context,
          b2cCatalogApi: bundle.catalog,
          b2cAccountApi: bundle.account,
          storefrontApi: storefront,
          marketplaceClient: bundle.transport,
          b2bApi: bundle.b2b,
          wholesaleCommerceApi: bundle.wholesale,
          initialRoute: initialRoute,
          locale: Locale(bootstrap.locale),
          previewBootstrap: bootstrap,
          notificationCampaignPopupService:
              CustomerNotificationCampaignPopupService(
            client: bundle.transport,
            baseUrl: baseUrl,
            recordEvents: false,
            strictReadErrors: true,
          ),
        );

        final loadedAt = DateTime.now().toUtc().toIso8601String();

        return CustomerPreviewRuntime._(
          app: app,
          client: transportOwner,
          authenticatedBundle: bundle,
          configuration: configuration,
          bootstrap: bootstrap,
          baseUrl: baseUrl,
          dashboardBaseUrl: dashboardOrigin,
          safeStatusMetadata: {
            ...bootstrap.safeStatusMetadata,
            ...configuration.safeStatusMetadata,
            'loaded_at': loadedAt,
            'updated_at': loadedAt,
          },
        );
      } catch (_) {
        bundle.close();
        transportOwner.close();
        rethrow;
      }
    }

    try {
      final configuration = resolvedConfiguration ??
          await CustomerPreviewResolvedConfiguration.resolveGuest(
            dashboardBaseUrl: dashboardOrigin,
            client: transportOwner,
            context: context,
            mode: bootstrap.configuration,
          );
      _requireConfigurationScope(
        configuration,
        context: context,
        mode: bootstrap.configuration,
      );

      final guestSession = CustomerGuestSession();
      final catalog = HttpB2cCatalogApi(
        baseUrl: baseUrl,
        client: transportOwner,
      );
      final account = HttpB2cAccountApi(
        baseUrl: baseUrl,
        token: null,
        guestSession: guestSession,
        client: transportOwner,
      );
      final delegate = _GuestPreviewStorefrontApi(
        baseUrl: baseUrl,
        client: transportOwner,
      );
      final storefront = PreviewRevisionStorefrontApi(
        delegate: delegate,
        context: context,
        configuration: configuration,
        baseUrl: baseUrl,
      );

      final app = FoodexCustomerApp.preview(
        previewContext: context,
        b2cCatalogApi: catalog,
        b2cAccountApi: account,
        storefrontApi: storefront,
        marketplaceClient: transportOwner,
        b2bApi: context.channel == CustomerChannel.b2b
            ? const _GuestB2bApi()
            : null,
        wholesaleCommerceApi: context.channel == CustomerChannel.b2b
            ? const _GuestWholesaleCommerceApi()
            : null,
        initialRoute: initialRoute,
        locale: Locale(bootstrap.locale),
        previewBootstrap: bootstrap,
        notificationCampaignPopupService:
            CustomerNotificationCampaignPopupService(
          client: transportOwner,
          baseUrl: baseUrl,
          recordEvents: false,
          strictReadErrors: true,
        ),
      );

      final loadedAt = DateTime.now().toUtc().toIso8601String();

      return CustomerPreviewRuntime._(
        app: app,
        client: transportOwner,
        configuration: configuration,
        bootstrap: bootstrap,
        baseUrl: baseUrl,
        dashboardBaseUrl: dashboardOrigin,
        safeStatusMetadata: {
          ...bootstrap.safeStatusMetadata,
          ...configuration.safeStatusMetadata,
          'loaded_at': loadedAt,
          'updated_at': loadedAt,
        },
      );
    } catch (_) {
      transportOwner.close();
      rethrow;
    }
  }

  static void _requireConfigurationScope(
    CustomerPreviewResolvedConfiguration configuration, {
    required CustomerPreviewContext context,
    required String mode,
  }) {
    if (configuration.channel != context.channel ||
        configuration.storeId != context.storeId ||
        configuration.mode != mode) {
      throw const CustomerPreviewConfigurationException(
        'preview_configuration_scope_mismatch',
      );
    }
  }

  static String _normalizedBase(String value) {
    final base = value.trim();
    return base.endsWith('/') ? base.substring(0, base.length - 1) : base;
  }
}

class _GuestPreviewStorefrontApi implements StorefrontApi {
  const _GuestPreviewStorefrontApi({
    required this.baseUrl,
    required this.client,
  });

  final String baseUrl;
  final http.Client client;

  @override
  Future<Map<String, dynamic>> selection({
    String? countryCode,
    String? city,
    String? area,
    bool support = false,
  }) async {
    final params = <String, String>{
      if (countryCode != null && countryCode.trim().isNotEmpty)
        'country_code': countryCode.trim(),
      if (city != null && city.trim().isNotEmpty) 'city': city.trim(),
      if (area != null && area.trim().isNotEmpty) 'area': area.trim(),
    };
    final body = await _get('/api/v1/stores', query: params);
    final rows = body is Map && body['data'] is List
        ? (body['data'] as List)
            .whereType<Map>()
            .map((row) => Map<String, dynamic>.from(row))
            .toList(growable: false)
        : const <Map<String, dynamic>>[];

    return {
      'retail_stores': rows,
      'wholesale_stores': const <Object>[],
      'entitlements': const <String, Object>{},
    };
  }

  @override
  Future<Map<String, dynamic>> retailHome(int storeId) async =>
      _map(await _get('/api/v1/stores/$storeId/storefront'));

  @override
  Future<Map<String, dynamic>> wholesaleHome(int storeId) async =>
      _map(await _get('/api/v1/wholesale/stores/$storeId/storefront'));

  @override
  Future<Map<String, dynamic>> b2bCheckoutOptions(int storeId) =>
      Future<Map<String, dynamic>>.error(
        const CustomerPreviewMutationBlocked('guest.b2b.checkout'),
      );

  Future<Object?> _get(
    String path, {
    Map<String, String> query = const {},
  }) async {
    final uri = Uri.parse('$baseUrl$path').replace(
      queryParameters: query.isEmpty ? null : query,
    );
    final response = await client.get(
      uri,
      headers: const {'Accept': 'application/json'},
    );
    if (response.statusCode < 200 || response.statusCode >= 300) {
      throw StorefrontApiException(
        'preview_http_${response.statusCode}',
        statusCode: response.statusCode,
      );
    }
    return response.body.isEmpty ? null : jsonDecode(response.body);
  }

  Map<String, dynamic> _map(Object? value) {
    if (value is Map) return Map<String, dynamic>.from(value);
    throw const StorefrontApiException('preview_invalid_response');
  }
}

class _GuestB2bApi implements B2bApi {
  const _GuestB2bApi();

  @override
  Future<Object?> get(String path) => Future<Object?>.error(
        const B2bApiException('authentication_required'),
      );
}

class _GuestWholesaleCommerceApi implements WholesaleCommerceApi {
  const _GuestWholesaleCommerceApi();

  @override
  Future<Object?> cart(int storeId) => Future<Object?>.error(
        const WholesaleCommerceException('authentication_required'),
      );

  @override
  Future<Object?> addItem(int storeId, int productId, double quantity) =>
      Future<Object?>.error(
        const CustomerPreviewMutationBlocked('wholesale.cart.add'),
      );

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
  }) =>
      Future<Object?>.error(
        const CustomerPreviewMutationBlocked('wholesale.checkout'),
      );
}
