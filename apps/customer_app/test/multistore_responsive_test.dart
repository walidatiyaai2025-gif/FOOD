// ignore_for_file: deprecated_member_use, prefer_interpolation_to_compose_strings

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/app.dart';
import 'package:foodex_customer_app/core/api/b2b_api.dart';
import 'package:foodex_customer_app/core/api/b2c_account_api.dart';
import 'package:foodex_customer_app/core/api/b2c_catalog_api.dart';
import 'package:foodex_customer_app/core/api/customer_action_api.dart';
import 'package:foodex_customer_app/core/api/storefront_api.dart';
import 'package:foodex_customer_app/core/api/wholesale_commerce_api.dart';
import 'package:foodex_customer_app/core/auth/customer_session.dart';

void main() {
  const devices = <Size>[
    Size(360, 800),
    Size(390, 844),
    Size(412, 915),
    Size(480, 960),
  ];
  const routes = <String>[
    '/customer/store-selector',
    '/retail/7/home',
    '/retail/8/home',
    '/b2b/home?store_id=70',
    '/b2b/products?store_id=70',
    '/retail/7/products/42',
    '/b2b/products/42?store_id=70',
    '/b2b/cart?store=70',
    '/b2b/checkout?store_id=70',
    '/b2b/orders',
  ];

  for (final device in devices) {
    testWidgets(
      'multi-store screens render without overflow at ' +
          device.width.toInt().toString() +
          'x' +
          device.height.toInt().toString(),
      (tester) async {
        await tester.binding.setSurfaceSize(device);
        addTearDown(() => tester.binding.setSurfaceSize(null));

        for (final route in routes) {
          await tester.pumpWidget(
            FoodexCustomerApp(
              initialRoute: route,
              session: const CustomerSession.authenticated(
                CustomerChannel.b2c,
                accessToken: 'responsive-token',
                b2bRetailStoreId: 7,
              ),
              b2cCatalogApi: const _ResponsiveCatalogApi(),
              b2cAccountApi: const _ResponsiveAccountApi(),
              actionApi: const _ResponsiveActionApi(),
              b2bApi: const _ResponsiveB2bApi(),
              storefrontApi: const _ResponsiveStorefrontApi(),
              wholesaleCommerceApi: const _ResponsiveWholesaleApi(),
            ),
          );
          await tester.pumpAndSettle();

          final exception = tester.takeException();
          expect(
            exception,
            isNull,
            reason: 'Route ' +
                route +
                ' failed at ' +
                device.width.toInt().toString() +
                'x' +
                device.height.toInt().toString(),
          );
        }
      },
    );
  }
}

class _ResponsiveCatalogApi implements B2cCatalogApi {
  const _ResponsiveCatalogApi();

  @override
  Future<List<B2cStore>> stores() async => const [
        B2cStore(id: 7, name: 'Fresh Market', code: 'FRESH'),
        B2cStore(
          id: 8,
          name: 'Pharmacy',
          code: 'PHARMACY',
          themeCode: 'retail_pharmacy',
        ),
      ];

  @override
  Future<List<B2cCategory>> categories(int storeId) async => const [
        B2cCategory(id: 1, name: 'خضروات'),
        B2cCategory(id: 2, name: 'ألبان'),
        B2cCategory(id: 3, name: 'مخبوزات'),
        B2cCategory(id: 4, name: 'مشروبات'),
      ];

  @override
  Future<List<B2cProduct>> products(
    int storeId, {
    String? query,
    int? categoryId,
    String? sort,
    String? direction,
  }) async =>
      const [
        B2cProduct(id: 42, name: 'منتج تجريبي', sku: 'SKU-42', price: 25),
        B2cProduct(id: 43, name: 'منتج ثان', sku: 'SKU-43', price: 18),
        B2cProduct(id: 44, name: 'منتج ثالث', sku: 'SKU-44', price: 12),
      ];

  @override
  Future<List<B2cOffer>> offers(int storeId) async => const [
        B2cOffer(id: 1, name: 'عرض اليوم', type: 'percentage', value: 10),
      ];

  @override
  Future<List<B2cBanner>> banners(int storeId) async => const [];

  @override
  Future<B2cProduct> product(
    int productId, {
    required int storeId,
  }) async =>
      const B2cProduct(
        id: 42,
        name: 'منتج تجريبي',
        sku: 'SKU-42',
        price: 25,
        description: 'وصف المنتج',
      );
}

class _ResponsiveStorefrontApi implements StorefrontApi {
  const _ResponsiveStorefrontApi();

  @override
  Future<Map<String, dynamic>> selection({
    String? countryCode,
    String? city,
    String? area,
    bool support = false,
  }) async =>
      {
        'retail_stores': [
          {
            'id': 7,
            'code': 'FRESH',
            'name': 'Fresh Market',
            'theme_code': 'retail_grocery',
          },
          {
            'id': 8,
            'code': 'PHARMACY',
            'name': 'Pharmacy',
            'theme_code': 'retail_pharmacy',
          },
        ],
        'wholesale_stores': [
          {
            'id': 70,
            'code': 'WHOLESALE',
            'name': 'Wholesale',
            'retail_context_ids': [7],
          },
        ],
      };

  @override
  Future<Map<String, dynamic>> retailHome(int storeId) async => {
        'store': {
          'id': storeId,
          'name': storeId == 8 ? 'Pharmacy' : 'Fresh Market',
        },
        'theme': {
          'code': storeId == 8 ? 'retail_pharmacy' : 'retail_grocery',
        },
        'branding': {'address': 'عنوان التوصيل'},
        'sections': [
          {'type': 'hero'},
          {'type': 'categories', 'title_ar': 'التصنيفات'},
          {'type': 'products', 'title_ar': 'منتجات مميزة'},
        ],
      };

  @override
  Future<Map<String, dynamic>> wholesaleHome(int storeId) async => {
        'store': {
          'id': storeId,
          'code': 'WHOLESALE-$storeId',
          'name': 'FOODEX Wholesale',
          'theme_code': 'wholesale_b2b',
        },
        'theme': {
          'code': 'wholesale_b2b',
          'primary': '#5D2A91',
          'primary_dark': '#35195E',
          'accent': '#B983F0',
          'background': '#FBFAFD',
        },
        'branding': {
          'address': 'تغطية توريد الجملة',
          'custom': {
            'brand_title_ar': 'FOODEX جملة',
            'brand_subtitle_ar': 'أفضل الأسعار لمتاجر التجزئة',
            'hero_cta_ar': 'تصفح الكتالوج',
          },
        },
        'hero': {
          'title': 'عرض الجملة',
          'image_url': null,
        },
        'sections': [
          {'key': 'hero', 'type': 'hero', 'sort_order': 10},
          {
            'key': 'categories',
            'type': 'categories',
            'title_ar': 'التصنيفات',
            'sort_order': 20,
          },
          {
            'key': 'offers',
            'type': 'offers',
            'title_ar': 'عروض الجملة',
            'sort_order': 30,
          },
        ],
      };

  @override
  Future<Map<String, dynamic>> b2bCheckoutOptions(int storeId) async => {
        'store_id': storeId,
        'addresses': [
          {
            'id': 5,
            'label': 'المتجر',
            'line1': 'عنوان المتجر',
            'city': 'الإسكندرية',
          },
        ],
        'delivery_dates': ['2026-09-29'],
        'payment_methods': ['cash_on_delivery'],
      };
}

class _ResponsiveB2bApi implements B2bApi {
  const _ResponsiveB2bApi();

  @override
  Future<Object?> get(String path) async {
    if (path.contains('/products/42')) {
      return {
        'id': 42,
        'name': 'كرتونة مياه',
        'sku': 'WATER-42',
        'account_price': 72.5,
        'base_wholesale_price': 75,
        'retail_reference_price': 90,
        'minimum_order_quantity': 5,
        'ordering_increment': 5,
        'pack_size': 12,
        'available_quantity': 120,
      };
    }
    if (path.contains('/orders')) {
      return {
        'data': [
          {
            'id': 1001,
            'order_number': 'B2B-1001',
            'status': 'pending',
            'grand_total': 725,
          },
        ],
      };
    }
    return {
      'data': [
        {
          'id': 42,
          'name': 'كرتونة مياه',
          'sku': 'WATER-42',
          'account_price': 72.5,
          'minimum_order_quantity': 5,
          'ordering_increment': 5,
          'pack_size': 12,
        },
        {
          'id': 43,
          'name': 'أرز جملة',
          'sku': 'RICE-43',
          'account_price': 150,
          'minimum_order_quantity': 2,
          'ordering_increment': 1,
          'pack_size': 10,
        },
      ],
    };
  }
}

class _ResponsiveWholesaleApi implements WholesaleCommerceApi {
  const _ResponsiveWholesaleApi();

  @override
  Future<Object?> cart(int storeId) async => {
        'store_id': storeId,
        'subtotal': 725,
        'items': [
          {
            'id': 1,
            'name': 'كرتونة مياه',
            'quantity': 10,
            'unit_price': 72.5,
            'line_total': 725,
            'minimum_order_quantity': 5,
            'ordering_increment': 5,
          },
        ],
      };

  @override
  Future<Object?> addItem(
    int storeId,
    int productId,
    double quantity,
  ) async =>
      cart(storeId);

  @override
  Future<Object?> updateItem(int itemId, double quantity) async =>
      {'id': itemId, 'quantity': quantity};

  @override
  Future<void> removeItem(int itemId) async {}

  @override
  Future<Object?> checkout({
    required int storeId,
    required int addressId,
    required String paymentMethod,
    String? requestedDeliveryDate,
    String? note,
    String? couponCode,
    required String idempotencyKey,
  }) async =>
      {'id': 1, 'status': 'pending'};
}

class _ResponsiveAccountApi implements B2cAccountApi {
  const _ResponsiveAccountApi();

  @override
  dynamic noSuchMethod(Invocation invocation) =>
      Future<Object?>.value(null);
}

class _ResponsiveActionApi implements CustomerActionApi {
  const _ResponsiveActionApi();

  @override
  dynamic noSuchMethod(Invocation invocation) =>
      Future<Object?>.value({});
}
