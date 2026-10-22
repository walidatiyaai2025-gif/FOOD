import 'package:flutter/material.dart';

import '../core/app_state.dart';
import '../core/foodex_theme.dart';
import '../models/marketplace_models.dart';

class FoodexHeader extends StatefulWidget {
  const FoodexHeader({
    super.key,
    this.onSearch,
    this.canPop = false,
    this.searchHint = 'ابحث عن مطعم أو وجبة أو منتج...',
  });

  final ValueChanged<String>? onSearch;
  final bool canPop;
  final String searchHint;

  @override
  State<FoodexHeader> createState() => _FoodexHeaderState();
}

class _FoodexHeaderState extends State<FoodexHeader> {
  final controller = TextEditingController();

  @override
  void dispose() {
    controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: appState,
      builder: (context, _) => Container(
        color: FoodexColors.deepGreen,
        padding: EdgeInsets.only(
          top: MediaQuery.paddingOf(context).top + 7,
          left: 10,
          right: 10,
          bottom: 8,
        ),
        child: Row(
          children: [
            if (widget.canPop) ...[
              _HeaderIcon(icon: Icons.arrow_back_ios_new_rounded, onTap: () => Navigator.maybePop(context)),
              const SizedBox(width: 4),
            ] else if (appState.isLoggedIn) ...[
              _HeaderIcon(
                icon: Icons.person_outline_rounded,
                onTap: () => Navigator.pushNamed(context, '/profile'),
              ),
              const SizedBox(width: 5),
            ],
            const Text(
              'FOODEX',
              style: TextStyle(
                color: Colors.white,
                fontWeight: FontWeight.w900,
                fontSize: 17,
                letterSpacing: .4,
              ),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: SizedBox(
                height: 38,
                child: TextField(
                  controller: controller,
                  textInputAction: TextInputAction.search,
                  onSubmitted: (value) {
                    if (widget.onSearch != null) {
                      widget.onSearch!(value);
                    } else if (value.trim().isNotEmpty) {
                      Navigator.pushNamed(context, '/search', arguments: value.trim());
                    }
                  },
                  style: const TextStyle(fontSize: 12),
                  decoration: InputDecoration(
                    isDense: true,
                    hintText: widget.searchHint,
                    prefixIcon: const Icon(Icons.search_rounded, size: 18),
                    suffixIcon: controller.text.isEmpty
                        ? null
                        : IconButton(
                            onPressed: () => setState(controller.clear),
                            icon: const Icon(Icons.close_rounded, size: 17),
                          ),
                    contentPadding: const EdgeInsets.symmetric(horizontal: 8, vertical: 8),
                  ),
                  onChanged: (_) => setState(() {}),
                ),
              ),
            ),
            if (appState.isLoggedIn) ...[
              const SizedBox(width: 4),
              _HeaderIcon(
                icon: Icons.notifications_none_rounded,
                onTap: () => Navigator.pushNamed(context, '/notifications'),
                showDot: true,
              ),
              const SizedBox(width: 2),
              _HeaderIcon(
                icon: Icons.shopping_cart_outlined,
                onTap: () => Navigator.pushNamed(context, '/cart'),
                badge: appState.cartCount,
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class _HeaderIcon extends StatelessWidget {
  const _HeaderIcon({required this.icon, required this.onTap, this.badge = 0, this.showDot = false});

  final IconData icon;
  final VoidCallback onTap;
  final int badge;
  final bool showDot;

  @override
  Widget build(BuildContext context) {
    return InkWell(
      customBorder: const CircleBorder(),
      onTap: onTap,
      child: SizedBox(
        width: 34,
        height: 36,
        child: Stack(
          clipBehavior: Clip.none,
          alignment: Alignment.center,
          children: [
            const DecoratedBox(
              decoration: BoxDecoration(color: Colors.white, shape: BoxShape.circle),
              child: SizedBox(width: 32, height: 32),
            ),
            Icon(icon, size: 20, color: FoodexColors.deepGreenStrong),
            if (badge > 0)
              Positioned(
                top: 0,
                right: 0,
                child: Container(
                  constraints: const BoxConstraints(minWidth: 15, minHeight: 15),
                  alignment: Alignment.center,
                  padding: const EdgeInsets.symmetric(horizontal: 3),
                  decoration: const BoxDecoration(color: FoodexColors.lime, shape: BoxShape.circle),
                  child: Text('$badge', style: const TextStyle(fontSize: 8, fontWeight: FontWeight.w900, color: FoodexColors.deepGreenStrong)),
                ),
              ),
            if (showDot)
              const Positioned(
                top: 3,
                right: 4,
                child: CircleAvatar(radius: 3.5, backgroundColor: FoodexColors.lime),
              ),
          ],
        ),
      ),
    );
  }
}

class FoodexPageScaffold extends StatelessWidget {
  const FoodexPageScaffold({
    super.key,
    required this.child,
    this.navIndex,
    this.canPop = false,
    this.onSearch,
    this.floatingActionButton,
  });

  final Widget child;
  final int? navIndex;
  final bool canPop;
  final ValueChanged<String>? onSearch;
  final Widget? floatingActionButton;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Column(
        children: [
          FoodexHeader(canPop: canPop, onSearch: onSearch),
          Expanded(child: child),
        ],
      ),
      floatingActionButton: floatingActionButton,
      bottomNavigationBar: navIndex == null ? null : FoodexBottomNav(currentIndex: navIndex!),
    );
  }
}

class FoodexBottomNav extends StatelessWidget {
  const FoodexBottomNav({super.key, required this.currentIndex});
  final int currentIndex;

  @override
  Widget build(BuildContext context) {
    return NavigationBar(
      selectedIndex: currentIndex,
      onDestinationSelected: (index) {
        final routes = ['/home', '/restaurants', '/cart', '/orders', '/profile'];
        if (index == currentIndex) return;
        Navigator.pushNamedAndRemoveUntil(context, routes[index], (route) => false);
      },
      destinations: const [
        NavigationDestination(icon: Icon(Icons.home_outlined), selectedIcon: Icon(Icons.home_rounded), label: 'الرئيسية'),
        NavigationDestination(icon: Icon(Icons.restaurant_menu_outlined), selectedIcon: Icon(Icons.restaurant_menu_rounded), label: 'المطاعم'),
        NavigationDestination(icon: Icon(Icons.shopping_cart_outlined), selectedIcon: Icon(Icons.shopping_cart_rounded), label: 'السلة'),
        NavigationDestination(icon: Icon(Icons.receipt_long_outlined), selectedIcon: Icon(Icons.receipt_long_rounded), label: 'الطلبات'),
        NavigationDestination(icon: Icon(Icons.more_horiz_rounded), selectedIcon: Icon(Icons.person_rounded), label: 'المزيد'),
      ],
    );
  }
}

class SectionTitle extends StatelessWidget {
  const SectionTitle({super.key, required this.title, this.action, this.onAction});
  final String title;
  final String? action;
  final VoidCallback? onAction;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsetsDirectional.fromSTEB(16, 18, 16, 10),
      child: Row(
        children: [
          Expanded(child: Text(title, style: Theme.of(context).textTheme.titleLarge)),
          if (action != null)
            TextButton(
              onPressed: onAction,
              child: Text(action!, style: const TextStyle(color: FoodexColors.deepGreen, fontWeight: FontWeight.w800)),
            ),
        ],
      ),
    );
  }
}

class RestaurantCard extends StatelessWidget {
  const RestaurantCard({super.key, required this.restaurant, required this.onTap, this.compact = false});

  final Restaurant restaurant;
  final VoidCallback onTap;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final favorite = appState.favoriteRestaurants.contains(restaurant.id);
    return Card(
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: onTap,
        child: compact
            ? Row(
                children: [
                  _RestaurantImage(restaurant: restaurant, width: 118, height: 112),
                  Expanded(child: _RestaurantInfo(restaurant: restaurant, dense: true)),
                ],
              )
            : Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Stack(
                    children: [
                      _RestaurantImage(restaurant: restaurant, height: 128),
                      PositionedDirectional(
                        top: 9,
                        end: 9,
                        child: IconButton.filledTonal(
                          onPressed: () => appState.toggleFavorite(restaurant.id),
                          icon: Icon(favorite ? Icons.favorite_rounded : Icons.favorite_border_rounded, color: favorite ? FoodexColors.destructive : FoodexColors.deepGreenStrong),
                        ),
                      ),
                    ],
                  ),
                  _RestaurantInfo(restaurant: restaurant),
                ],
              ),
      ),
    );
  }
}

class _RestaurantImage extends StatelessWidget {
  const _RestaurantImage({required this.restaurant, this.width = double.infinity, this.height = 120});
  final Restaurant restaurant;
  final double width;
  final double height;

  @override
  Widget build(BuildContext context) {
    return Image.network(
      restaurant.imageUrl,
      width: width,
      height: height,
      fit: BoxFit.cover,
      errorBuilder: (_, __, ___) => Container(width: width, height: height, color: FoodexColors.limeSoft, child: const Icon(Icons.restaurant_rounded, size: 44, color: FoodexColors.deepGreen)),
    );
  }
}

class _RestaurantInfo extends StatelessWidget {
  const _RestaurantInfo({required this.restaurant, this.dense = false});
  final Restaurant restaurant;
  final bool dense;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.all(dense ? 11 : 13),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(child: Text(restaurant.name, style: Theme.of(context).textTheme.titleMedium)),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 4),
                decoration: BoxDecoration(color: FoodexColors.limeSoft, borderRadius: BorderRadius.circular(999)),
                child: const Text('مفتوح الآن', style: TextStyle(fontSize: 9.5, fontWeight: FontWeight.w800, color: FoodexColors.deepGreen)),
              ),
            ],
          ),
          const SizedBox(height: 5),
          Text(restaurant.cuisine, style: Theme.of(context).textTheme.bodySmall),
          const SizedBox(height: 7),
          Wrap(
            spacing: 9,
            runSpacing: 4,
            children: [
              _Meta(icon: Icons.star_rounded, text: '${restaurant.rating} (${_shortCount(restaurant.reviewCount)})', color: FoodexColors.warning),
              _Meta(icon: Icons.schedule_rounded, text: restaurant.deliveryMinutes),
              _Meta(icon: Icons.delivery_dining_rounded, text: '${restaurant.deliveryFee.toStringAsFixed(0)} ج توصيل'),
            ],
          ),
        ],
      ),
    );
  }
}

String _shortCount(int value) => value >= 1000 ? '${(value / 1000).toStringAsFixed(1)}k' : '$value';

class _Meta extends StatelessWidget {
  const _Meta({required this.icon, required this.text, this.color = FoodexColors.muted});
  final IconData icon;
  final String text;
  final Color color;

  @override
  Widget build(BuildContext context) => Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 15, color: color),
          const SizedBox(width: 3),
          Text(text, style: Theme.of(context).textTheme.bodySmall),
        ],
      );
}

class MenuItemCard extends StatelessWidget {
  const MenuItemCard({super.key, required this.item, required this.onTap});
  final MenuItem item;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Card(
      margin: const EdgeInsetsDirectional.fromSTEB(16, 0, 16, 11),
      child: InkWell(
        borderRadius: BorderRadius.circular(18),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(11),
          child: Row(
            children: [
              ClipRRect(
                borderRadius: BorderRadius.circular(14),
                child: Image.network(
                  item.imageUrl,
                  width: 100,
                  height: 88,
                  fit: BoxFit.cover,
                  errorBuilder: (_, __, ___) => Container(width: 100, height: 88, color: FoodexColors.limeSoft, child: const Icon(Icons.fastfood_rounded)),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Expanded(child: Text(item.name, style: Theme.of(context).textTheme.titleMedium)),
                        if (item.isBestSeller)
                          Container(
                            padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 3),
                            decoration: BoxDecoration(color: FoodexColors.limeSoft, borderRadius: BorderRadius.circular(999)),
                            child: const Text('الأكثر طلبًا', style: TextStyle(fontSize: 9, fontWeight: FontWeight.w800, color: FoodexColors.deepGreen)),
                          ),
                      ],
                    ),
                    const SizedBox(height: 4),
                    Text(item.description, maxLines: 2, overflow: TextOverflow.ellipsis, style: Theme.of(context).textTheme.bodySmall),
                    const SizedBox(height: 8),
                    Row(
                      children: [
                        Text('${item.price.toStringAsFixed(0)} جنيه', style: const TextStyle(fontWeight: FontWeight.w900, color: FoodexColors.deepGreen, fontSize: 15)),
                        const Spacer(),
                        const CircleAvatar(radius: 17, backgroundColor: FoodexColors.deepGreen, child: Icon(Icons.add_rounded, color: Colors.white, size: 20)),
                      ],
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class StatusPill extends StatelessWidget {
  const StatusPill({super.key, required this.text});
  final String text;

  @override
  Widget build(BuildContext context) {
    final processing = text.contains('تحضير');
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      decoration: BoxDecoration(
        color: processing ? const Color(0xFFFFF6DD) : FoodexColors.limeSoft,
        borderRadius: BorderRadius.circular(999),
      ),
      child: Text(
        text,
        style: TextStyle(fontSize: 10.5, fontWeight: FontWeight.w800, color: processing ? const Color(0xFF9A6700) : FoodexColors.deepGreen),
      ),
    );
  }
}
