import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/api/b2c_account_api.dart';
import 'package:foodex_customer_app/shared/customer_favorite_button.dart';

void main() {
  testWidgets('favorite button loads, adds and removes a product', (tester) async {
    final api = _FavoritesApi();

    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: CustomerFavoriteButton(
            api: api,
            storeId: 70,
            productId: 42,
            isAuthenticated: true,
            loginRoute: '/auth/checkout',
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byIcon(Icons.favorite_border_rounded), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('favorite-70-42')));
    await tester.pumpAndSettle();
    expect(api.added, [42]);
    expect(find.byIcon(Icons.favorite_rounded), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('favorite-70-42')));
    await tester.pumpAndSettle();
    expect(api.removed, [42]);
    expect(find.byIcon(Icons.favorite_border_rounded), findsOneWidget);
  });
}

class _FavoritesApi implements B2cRetailFavoritesApi {
  final Set<int> ids = <int>{};
  final List<int> added = <int>[];
  final List<int> removed = <int>[];

  @override
  Future<Object?> favoritesForStore(int storeId) async => {
        'data': ids.map((id) => {'id': id}).toList(),
      };

  @override
  Future<void> addFavoriteForStore(int storeId, int productId) async {
    ids.add(productId);
    added.add(productId);
  }

  @override
  Future<void> removeFavoriteForStore(int storeId, int productId) async {
    ids.remove(productId);
    removed.add(productId);
  }
}
