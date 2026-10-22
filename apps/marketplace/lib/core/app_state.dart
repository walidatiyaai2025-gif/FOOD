import 'package:flutter/foundation.dart';

import '../models/marketplace_models.dart';

class MockAppState extends ChangeNotifier {
  bool isLoggedIn = true;
  final List<CartLine> cart = [];
  final Set<int> favoriteRestaurants = {1};

  int get cartCount => cart.fold<int>(0, (sum, line) => sum + line.quantity);
  double get subtotal => cart.fold<double>(0, (sum, line) => sum + line.total);

  void toggleLogin() {
    isLoggedIn = !isLoggedIn;
    notifyListeners();
  }

  void toggleFavorite(int restaurantId) {
    if (!favoriteRestaurants.add(restaurantId)) {
      favoriteRestaurants.remove(restaurantId);
    }
    notifyListeners();
  }

  void addToCart(MenuItem item, {int quantity = 1, String size = 'عادي', String drink = 'بدون مشروب'}) {
    final index = cart.indexWhere((line) => line.item.id == item.id && line.size == size && line.drink == drink);
    if (index >= 0) {
      cart[index].quantity += quantity;
    } else {
      cart.add(CartLine(item: item, quantity: quantity, size: size, drink: drink));
    }
    notifyListeners();
  }

  void increase(CartLine line) {
    line.quantity += 1;
    notifyListeners();
  }

  void decrease(CartLine line) {
    if (line.quantity > 1) {
      line.quantity -= 1;
    } else {
      cart.remove(line);
    }
    notifyListeners();
  }

  void clearCart() {
    cart.clear();
    notifyListeners();
  }
}

final appState = MockAppState();
