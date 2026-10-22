import 'package:flutter/material.dart';

import 'core/foodex_theme.dart';
import 'models/marketplace_models.dart';
import 'screens/account_screens.dart';
import 'screens/commerce_screens.dart';
import 'screens/marketplace_screens.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  runApp(const FoodexMarketplaceMockupApp());
}

class FoodexMarketplaceMockupApp extends StatelessWidget {
  const FoodexMarketplaceMockupApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      debugShowCheckedModeBanner: false,
      title: 'Foodx Marketplace Mockup',
      theme: FoodexTheme.light(),
      locale: const Locale('ar'),
      builder: (context, child) => Directionality(textDirection: TextDirection.rtl, child: child ?? const SizedBox.shrink()),
      initialRoute: '/home',
      onGenerateRoute: (settings) {
        Widget page;
        switch (settings.name) {
          case '/home':
            page = const MarketplaceHomeScreen();
            break;
          case '/restaurants':
            page = const RestaurantsScreen();
            break;
          case '/restaurant':
            page = RestaurantDetailScreen(restaurant: settings.arguments as Restaurant);
            break;
          case '/meal':
            page = MealDetailScreen(item: settings.arguments as MenuItem);
            break;
          case '/cart':
            page = const CartScreen();
            break;
          case '/checkout':
            page = const CheckoutScreen();
            break;
          case '/orders':
            page = const OrdersScreen();
            break;
          case '/tracking':
            page = const OrderTrackingScreen();
            break;
          case '/offers':
            page = const OffersScreen();
            break;
          case '/notifications':
            page = const NotificationsScreen();
            break;
          case '/favorites':
            page = const FavoritesScreen();
            break;
          case '/profile':
            page = const ProfileScreen();
            break;
          case '/search':
            page = SearchResultsScreen(initialQuery: (settings.arguments as String?) ?? '');
            break;
          default:
            page = const MarketplaceHomeScreen();
            break;
        }
        return MaterialPageRoute<void>(settings: settings, builder: (_) => page);
      },
    );
  }
}
