import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/api/b2c_account_api.dart';
import 'package:foodex_customer_app/features/customer_account/customer_favorites_screen.dart';
import 'package:foodex_customer_app/shared/customer_ui_v3/customer_ui_v3.dart';

void main() {
  testWidgets(
    'Customer UI V3 favorites uses shared product cards and preserves store scope',
    (tester) async {
      final api = _FavoritesFakeApi(
        value: {
          'data': [
            {
              'product': {
                'id': 8,
                'name': 'Coffee',
                'price': 2.5,
                'old_price': 3.0,
                'currency': 'KWD',
                'category_name': 'Drinks',
                'discount_percentage': 17,
              },
            },
          ],
        },
      );
      int? openedProduct;

      await tester.pumpWidget(
        MaterialApp(
          locale: const Locale('ar'),
          home: MediaQuery(
            data: const MediaQueryData(
              size: Size(360, 800),
              textScaler: TextScaler.linear(1.30),
            ),
            child: Directionality(
              textDirection: TextDirection.rtl,
              child: CustomerFavoritesScreen(
                api: api,
                favoritesApi: api,
                retailStoreId: 19,
                onOpenProduct: (id) => openedProduct = id,
              ),
            ),
          ),
        ),
      );
      await tester.pumpAndSettle();

      expect(api.favoriteLoads, <int>[19]);
      expect(find.byType(CustomerProductCard), findsOneWidget);
      expect(find.text('Drinks'), findsOneWidget);
      expect(find.text('2.500 KWD'), findsOneWidget);
      expect(find.text('3.000 KWD'), findsOneWidget);
      expect(find.text('-17%'), findsOneWidget);

      final product = find.byKey(const ValueKey('customer-favorite-8'));
      await tester.ensureVisible(product);
      await tester.tap(product);
      await tester.pump();
      expect(openedProduct, 8);

      final remove =
          find.byKey(const ValueKey('customer-favorite-remove-8'));
      await tester.tap(remove);
      await tester.pumpAndSettle();

      expect(api.removedFavorites, <(int, int)>[(19, 8)]);
      expect(api.favoriteLoads, everyElement(19));
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets('Customer UI V3 favorites loading uses geometry skeletons',
      (tester) async {
    final pending = Completer<Object?>();
    final api = _FavoritesFakeApi(pending: pending);

    await tester.pumpWidget(
      MaterialApp(
        home: CustomerFavoritesScreen(
          api: api,
          favoritesApi: api,
          retailStoreId: 19,
        ),
      ),
    );
    await tester.pump();

    expect(
      find.byKey(const ValueKey('customer-favorites-loading')),
      findsOneWidget,
    );
    expect(find.byType(CustomerProductCardSkeleton), findsNWidgets(6));
    expect(find.byType(CircularProgressIndicator), findsNothing);
  });
}

class _FavoritesFakeApi implements B2cAccountApi, B2cRetailFavoritesApi {
  _FavoritesFakeApi({
    this.value = const {'data': []},
    this.pending,
  });

  final Object? value;
  final Completer<Object?>? pending;
  final List<int> favoriteLoads = <int>[];
  final List<(int, int)> removedFavorites = <(int, int)>[];

  @override
  Future<Object?> favoritesForStore(int storeId) {
    favoriteLoads.add(storeId);
    return pending?.future ?? Future<Object?>.value(value);
  }

  @override
  Future<void> addFavoriteForStore(int storeId, int productId) async {}

  @override
  Future<void> removeFavoriteForStore(int storeId, int productId) async {
    removedFavorites.add((storeId, productId));
  }

  @override
  dynamic noSuchMethod(Invocation invocation) =>
      Future<Object?>.value(null);
}
