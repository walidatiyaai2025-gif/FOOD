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
import '../config/foodex_environment.dart';
import 'customer_preview_bridge_contract.dart';
import 'customer_preview_context.dart';
import 'customer_preview_transport.dart';

FoodexCustomerApp buildCustomerPreviewApp(CustomerPreviewBootstrap bootstrap) {
  final baseUrl = FoodexEnvironment.apiBaseUrl;
  if (baseUrl.trim().isEmpty) {
    throw const FormatException('Customer preview API base URL is missing.');
  }

  if (bootstrap.authenticated) {
    final bundle = CustomerPreviewApiBundle(
      baseUrl: baseUrl,
      context: bootstrap.context,
      credential: bootstrap.credential!,
    );

    return FoodexCustomerApp.preview(
      previewContext: bootstrap.context,
      b2cCatalogApi: bundle.catalog,
      b2cAccountApi: bundle.account,
      storefrontApi: bundle.storefront,
      marketplaceClient: bundle.transport,
      b2bApi: bundle.b2b,
      wholesaleCommerceApi: bundle.wholesale,
      locale: Locale(bootstrap.locale),
    );
  }

  final client = http.Client();
  final account = HttpB2cAccountApi(
    baseUrl: baseUrl,
    token: null,
    guestSession: CustomerGuestSession(),
    client: client,
  );
  final catalog = HttpB2cCatalogApi(baseUrl: baseUrl, client: client);
  final storefront = HttpStorefrontApi(baseUrl: baseUrl, client: client);

  return FoodexCustomerApp.preview(
    previewContext: bootstrap.context,
    b2cCatalogApi: catalog,
    b2cAccountApi: account,
    storefrontApi: storefront,
    marketplaceClient: client,
    b2bApi: bootstrap.context.channel == CustomerChannel.b2b
        ? const _GuestPreviewB2bApi()
        : null,
    wholesaleCommerceApi: bootstrap.context.channel == CustomerChannel.b2b
        ? _GuestPreviewWholesaleApi(account)
        : null,
    locale: Locale(bootstrap.locale),
  );
}

class _GuestPreviewB2bApi implements B2bApi {
  const _GuestPreviewB2bApi();

  @override
  Future<Object?> get(String path) => Future<Object?>.error(
        const B2bApiException('authentication_required'),
      );
}

class _GuestPreviewWholesaleApi implements WholesaleCommerceApi {
  const _GuestPreviewWholesaleApi(this.account);

  final B2cAccountApi account;

  @override
  Future<Object?> cart(int storeId) => account.cart(storeId: storeId);

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
