import 'package:flutter/material.dart';

import '../core/app_state.dart';
import '../core/foodex_theme.dart';
import '../data/mock_data.dart';
import '../models/marketplace_models.dart';
import '../widgets/foodex_widgets.dart';

class MarketplaceHomeScreen extends StatelessWidget {
  const MarketplaceHomeScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return FoodexPageScaffold(
      navIndex: 0,
      child: ListView(
        padding: EdgeInsets.zero,
        children: [
          const SizedBox(height: 12),
          _MarketplaceHero(
            onTap: () => Navigator.pushNamed(context, '/restaurants'),
          ),
          const SizedBox(height: 12),
          _QuickActions(
            onRestaurants: () => Navigator.pushNamed(context, '/restaurants'),
            onWholesale: () => _showInfo(context, 'متجر الجملة', 'ده مكان الربط المستقبلي مع Foodx Wholesale الموجود.'),
            onOffers: () => Navigator.pushNamed(context, '/offers'),
            onFavorites: () => Navigator.pushNamed(context, '/favorites'),
          ),
          SectionTitle(
            title: 'مطاعم على فودكس',
            action: 'عرض الكل',
            onAction: () => Navigator.pushNamed(context, '/restaurants'),
          ),
          SizedBox(
            height: 260,
            child: ListView.separated(
              padding: const EdgeInsets.symmetric(horizontal: 16),
              scrollDirection: Axis.horizontal,
              itemCount: restaurants.length,
              separatorBuilder: (_, __) => const SizedBox(width: 12),
              itemBuilder: (context, index) {
                final restaurant = restaurants[index];
                return SizedBox(
                  width: 250,
                  child: RestaurantCard(
                    restaurant: restaurant,
                    onTap: () => Navigator.pushNamed(context, '/restaurant', arguments: restaurant),
                  ),
                );
              },
            ),
          ),
          const SectionTitle(title: 'اختيارات سريعة'),
          const _CuisineScroller(),
          SectionTitle(
            title: 'عروض المطاعم',
            action: 'كل العروض',
            onAction: () => Navigator.pushNamed(context, '/offers'),
          ),
          _OfferBanner(
            title: 'خصم 20% على وجبات مختارة',
            subtitle: 'في المطاعم المشاركة على Foodx',
            imageUrl: restaurants.first.imageUrl,
            onTap: () => Navigator.pushNamed(context, '/offers'),
          ),
          const SizedBox(height: 24),
        ],
      ),
    );
  }
}

class RestaurantsScreen extends StatefulWidget {
  const RestaurantsScreen({super.key});

  @override
  State<RestaurantsScreen> createState() => _RestaurantsScreenState();
}

class _RestaurantsScreenState extends State<RestaurantsScreen> {
  String selectedCuisine = 'الكل';
  bool onlyOpen = false;
  double maxFee = 50;
  double minRating = 0;
  String search = '';

  List<Restaurant> get filtered {
    return restaurants.where((r) {
      if (search.trim().isNotEmpty && !r.name.contains(search.trim()) && !r.cuisine.contains(search.trim())) return false;
      if (selectedCuisine != 'الكل' && !r.cuisine.contains(selectedCuisine)) return false;
      if (onlyOpen && !r.isOpen) return false;
      if (r.deliveryFee > maxFee) return false;
      if (r.rating < minRating) return false;
      return true;
    }).toList();
  }

  @override
  Widget build(BuildContext context) {
    return FoodexPageScaffold(
      navIndex: 1,
      onSearch: (value) => setState(() => search = value),
      child: Column(
        children: [
          Padding(
            padding: const EdgeInsetsDirectional.fromSTEB(16, 13, 16, 6),
            child: Row(
              children: [
                Expanded(child: Text('المطاعم', style: Theme.of(context).textTheme.headlineMedium)),
                FilledButton.tonalIcon(
                  onPressed: () async {
                    final result = await showModalBottomSheet<RestaurantFilterResult>(
                      context: context,
                      isScrollControlled: true,
                      useSafeArea: true,
                      builder: (_) => RestaurantFilterSheet(
                        initialOnlyOpen: onlyOpen,
                        initialFee: maxFee,
                        initialRating: minRating,
                      ),
                    );
                    if (result != null) {
                      setState(() {
                        onlyOpen = result.onlyOpen;
                        maxFee = result.maxFee;
                        minRating = result.minRating;
                      });
                    }
                  },
                  icon: const Icon(Icons.tune_rounded, size: 18),
                  label: const Text('فلتر'),
                ),
              ],
            ),
          ),
          SizedBox(
            height: 49,
            child: ListView(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(horizontal: 16),
              children: ['الكل', 'برجر', 'مصري', 'بيتزا', 'قهوة'].map((cuisine) {
                return Padding(
                  padding: const EdgeInsetsDirectional.only(end: 8),
                  child: ChoiceChip(
                    label: Text(cuisine),
                    selected: selectedCuisine == cuisine,
                    onSelected: (_) => setState(() => selectedCuisine = cuisine),
                  ),
                );
              }).toList(),
            ),
          ),
          Expanded(
            child: filtered.isEmpty
                ? const _EmptyRestaurants()
                : ListView.separated(
                    padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
                    itemCount: filtered.length,
                    separatorBuilder: (_, __) => const SizedBox(height: 11),
                    itemBuilder: (context, index) {
                      final restaurant = filtered[index];
                      return RestaurantCard(
                        restaurant: restaurant,
                        compact: true,
                        onTap: () => Navigator.pushNamed(context, '/restaurant', arguments: restaurant),
                      );
                    },
                  ),
          ),
        ],
      ),
    );
  }
}

class RestaurantDetailScreen extends StatefulWidget {
  const RestaurantDetailScreen({super.key, required this.restaurant});
  final Restaurant restaurant;

  @override
  State<RestaurantDetailScreen> createState() => _RestaurantDetailScreenState();
}

class _RestaurantDetailScreenState extends State<RestaurantDetailScreen> {
  String category = 'الكل';

  @override
  Widget build(BuildContext context) {
    final items = menuItems.where((item) => item.restaurantId == widget.restaurant.id || widget.restaurant.id != 1).toList();
    final visible = category == 'الكل' ? items : items.where((e) => e.category == category).toList();

    return FoodexPageScaffold(
      canPop: true,
      child: CustomScrollView(
        slivers: [
          SliverToBoxAdapter(child: _RestaurantHero(restaurant: widget.restaurant)),
          SliverToBoxAdapter(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Card(
                child: Padding(
                  padding: const EdgeInsets.all(14),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          Expanded(child: Text(widget.restaurant.name, style: Theme.of(context).textTheme.titleLarge)),
                          IconButton(
                            onPressed: () => setState(() => appState.toggleFavorite(widget.restaurant.id)),
                            icon: Icon(
                              appState.favoriteRestaurants.contains(widget.restaurant.id) ? Icons.favorite_rounded : Icons.favorite_border_rounded,
                              color: appState.favoriteRestaurants.contains(widget.restaurant.id) ? FoodexColors.destructive : FoodexColors.deepGreen,
                            ),
                          ),
                        ],
                      ),
                      Text(widget.restaurant.cuisine, style: Theme.of(context).textTheme.bodySmall),
                      const SizedBox(height: 12),
                      Row(
                        children: [
                          Expanded(child: _InfoTile(icon: Icons.star_rounded, title: '${widget.restaurant.rating}', subtitle: '${widget.restaurant.reviewCount} تقييم')),
                          const SizedBox(width: 8),
                          Expanded(child: _InfoTile(icon: Icons.schedule_rounded, title: widget.restaurant.deliveryMinutes, subtitle: 'وقت التوصيل')),
                          const SizedBox(width: 8),
                          Expanded(child: _InfoTile(icon: Icons.delivery_dining_rounded, title: '${widget.restaurant.deliveryFee.toStringAsFixed(0)} جنيه', subtitle: 'رسوم التوصيل')),
                        ],
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
          SliverToBoxAdapter(
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16),
              child: SegmentedButton<String>(
                segments: const [
                  ButtonSegment(value: 'menu', label: Text('المنيو')),
                  ButtonSegment(value: 'offers', label: Text('العروض')),
                  ButtonSegment(value: 'reviews', label: Text('التقييمات')),
                ],
                selected: const {'menu'},
                onSelectionChanged: (selection) {
                  if (selection.first == 'offers') Navigator.pushNamed(context, '/offers');
                  if (selection.first == 'reviews') _showInfo(context, 'التقييمات', '4.8 من 5 — تقييمات موثقة من طلبات Foodx.');
                },
                showSelectedIcon: false,
              ),
            ),
          ),
          SliverToBoxAdapter(
            child: SizedBox(
              height: 60,
              child: ListView(
                padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
                scrollDirection: Axis.horizontal,
                children: ['الكل', 'وجبات', 'برجر', 'مقبلات'].map((value) {
                  return Padding(
                    padding: const EdgeInsetsDirectional.only(end: 7),
                    child: ChoiceChip(
                      label: Text(value),
                      selected: category == value,
                      onSelected: (_) => setState(() => category = value),
                    ),
                  );
                }).toList(),
              ),
            ),
          ),
          SliverToBoxAdapter(child: Padding(padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6), child: Text('وجبات', style: Theme.of(context).textTheme.titleLarge))),
          if (visible.isEmpty)
            const SliverToBoxAdapter(child: Padding(padding: EdgeInsets.all(40), child: Center(child: Text('لا توجد أصناف في القسم ده في بيانات الموكاب.'))))
          else
            SliverList.builder(
              itemCount: visible.length,
              itemBuilder: (context, index) => MenuItemCard(
                item: visible[index],
                onTap: () => Navigator.pushNamed(context, '/meal', arguments: visible[index]),
              ),
            ),
          const SliverToBoxAdapter(child: SizedBox(height: 32)),
        ],
      ),
    );
  }
}

class MealDetailScreen extends StatefulWidget {
  const MealDetailScreen({super.key, required this.item});
  final MenuItem item;

  @override
  State<MealDetailScreen> createState() => _MealDetailScreenState();
}

class _MealDetailScreenState extends State<MealDetailScreen> {
  String size = 'عادي';
  String drink = 'بدون مشروب';
  int quantity = 1;
  bool extraCheese = false;
  bool bacon = false;

  double get extras => (extraCheese ? 15 : 0) + (bacon ? 25 : 0);
  double get unitTotal => widget.item.price + (size == 'كبير' ? 25 : size == 'جامبو' ? 40 : 0) + extras;

  @override
  Widget build(BuildContext context) {
    return FoodexPageScaffold(
      canPop: true,
      child: ListView(
        padding: EdgeInsets.zero,
        children: [
          Image.network(
            widget.item.imageUrl,
            height: 245,
            fit: BoxFit.cover,
            errorBuilder: (_, __, ___) => Container(height: 245, color: FoodexColors.limeSoft, child: const Icon(Icons.fastfood_rounded, size: 70)),
          ),
          Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(widget.item.name, style: Theme.of(context).textTheme.headlineMedium),
                const SizedBox(height: 7),
                Text(widget.item.description, style: Theme.of(context).textTheme.bodyMedium),
                const SizedBox(height: 8),
                Text('${widget.item.price.toStringAsFixed(0)} جنيه', style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w900, color: FoodexColors.deepGreen)),
                const SizedBox(height: 22),
                const _GroupTitle(title: 'اختر حجم الوجبة', isRequired: true),
                _ChoiceRow(
                  values: const ['عادي', 'كبير', 'جامبو'],
                  selected: size,
                  labels: const ['170 جنيه', '+25 جنيه', '+40 جنيه'],
                  onChanged: (value) => setState(() => size = value),
                ),
                const SizedBox(height: 22),
                const _GroupTitle(title: 'اختر المشروب', isRequired: true),
                _ChoiceRow(
                  values: const ['بدون مشروب', 'كوكاكولا', 'سبرايت'],
                  selected: drink,
                  labels: const ['0', '+15 جنيه', '+15 جنيه'],
                  onChanged: (value) => setState(() => drink = value),
                ),
                const SizedBox(height: 22),
                const _GroupTitle(title: 'إضافات', isRequired: false),
                CheckboxListTile(
                  contentPadding: EdgeInsets.zero,
                  value: extraCheese,
                  onChanged: (v) => setState(() => extraCheese = v ?? false),
                  title: const Text('جبنة إضافية'),
                  secondary: const Text('+15 جنيه'),
                  controlAffinity: ListTileControlAffinity.leading,
                ),
                CheckboxListTile(
                  contentPadding: EdgeInsets.zero,
                  value: bacon,
                  onChanged: (v) => setState(() => bacon = v ?? false),
                  title: const Text('بيكون'),
                  secondary: const Text('+25 جنيه'),
                  controlAffinity: ListTileControlAffinity.leading,
                ),
                const SizedBox(height: 14),
                Row(
                  children: [
                    Container(
                      decoration: BoxDecoration(color: FoodexColors.white, border: Border.all(color: FoodexColors.border), borderRadius: BorderRadius.circular(15)),
                      child: Row(
                        children: [
                          IconButton(onPressed: quantity > 1 ? () => setState(() => quantity--) : null, icon: const Icon(Icons.remove_rounded)),
                          Text('$quantity', style: const TextStyle(fontWeight: FontWeight.w900)),
                          IconButton(onPressed: () => setState(() => quantity++), icon: const Icon(Icons.add_rounded)),
                        ],
                      ),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: FilledButton(
                        onPressed: () {
                          appState.addToCart(widget.item, quantity: quantity, size: size, drink: drink);
                          ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('تمت الإضافة إلى السلة')));
                          Navigator.pushNamed(context, '/cart');
                        },
                        child: Text('أضف للسلة • ${(unitTotal * quantity).toStringAsFixed(0)} جنيه'),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 24),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class SearchResultsScreen extends StatefulWidget {
  const SearchResultsScreen({super.key, this.initialQuery = ''});
  final String initialQuery;

  @override
  State<SearchResultsScreen> createState() => _SearchResultsScreenState();
}

class _SearchResultsScreenState extends State<SearchResultsScreen> {
  late String query = widget.initialQuery;

  @override
  Widget build(BuildContext context) {
    final restaurantResults = query.isEmpty ? restaurants : restaurants.where((r) => r.name.contains(query) || r.cuisine.contains(query)).toList();
    final mealResults = query.isEmpty ? menuItems : menuItems.where((m) => m.name.contains(query) || m.description.contains(query)).toList();

    return FoodexPageScaffold(
      canPop: true,
      onSearch: (value) => setState(() => query = value.trim()),
      child: ListView(
        padding: const EdgeInsets.only(bottom: 24),
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 18, 16, 4),
            child: Text(query.isEmpty ? 'استكشف فودكس' : 'نتائج البحث عن “$query”', style: Theme.of(context).textTheme.titleLarge),
          ),
          if (restaurantResults.isNotEmpty) ...[
            const SectionTitle(title: 'مطاعم'),
            ...restaurantResults.map((r) => Padding(
                  padding: const EdgeInsets.fromLTRB(16, 0, 16, 10),
                  child: RestaurantCard(restaurant: r, compact: true, onTap: () => Navigator.pushNamed(context, '/restaurant', arguments: r)),
                )),
          ],
          if (mealResults.isNotEmpty) ...[
            const SectionTitle(title: 'وجبات'),
            ...mealResults.map((m) => MenuItemCard(item: m, onTap: () => Navigator.pushNamed(context, '/meal', arguments: m))),
          ],
          if (restaurantResults.isEmpty && mealResults.isEmpty)
            const Padding(
              padding: EdgeInsets.all(50),
              child: Column(
                children: [
                  Icon(Icons.search_off_rounded, size: 52, color: FoodexColors.muted),
                  SizedBox(height: 12),
                  Text('مفيش نتائج مطابقة. جرّب كلمة تانية.'),
                ],
              ),
            ),
        ],
      ),
    );
  }
}

class RestaurantFilterResult {
  const RestaurantFilterResult({required this.onlyOpen, required this.maxFee, required this.minRating});
  final bool onlyOpen;
  final double maxFee;
  final double minRating;
}

class RestaurantFilterSheet extends StatefulWidget {
  const RestaurantFilterSheet({
    super.key,
    required this.initialOnlyOpen,
    required this.initialFee,
    required this.initialRating,
  });

  final bool initialOnlyOpen;
  final double initialFee;
  final double initialRating;

  @override
  State<RestaurantFilterSheet> createState() => _RestaurantFilterSheetState();
}

class _RestaurantFilterSheetState extends State<RestaurantFilterSheet> {
  late bool onlyOpen = widget.initialOnlyOpen;
  late double fee = widget.initialFee;
  late double rating = widget.initialRating;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(18, 8, 18, 24),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Center(child: Container(width: 44, height: 5, decoration: BoxDecoration(color: FoodexColors.border, borderRadius: BorderRadius.circular(99)))),
          const SizedBox(height: 16),
          Row(
            children: [
              Expanded(child: Text('فلتر المطاعم', style: Theme.of(context).textTheme.titleLarge)),
              TextButton(
                onPressed: () => setState(() {
                  onlyOpen = false;
                  fee = 50;
                  rating = 0;
                }),
                child: const Text('إعادة تعيين'),
              ),
            ],
          ),
          SwitchListTile.adaptive(
            contentPadding: EdgeInsets.zero,
            title: const Text('المطاعم المفتوحة فقط'),
            value: onlyOpen,
            onChanged: (v) => setState(() => onlyOpen = v),
          ),
          const SizedBox(height: 8),
          Text('أقل تقييم: ${rating == 0 ? 'الكل' : rating.toStringAsFixed(0)} نجوم', style: Theme.of(context).textTheme.titleMedium),
          Slider(min: 0, max: 5, divisions: 5, value: rating, onChanged: (v) => setState(() => rating = v)),
          const SizedBox(height: 8),
          Text('أقصى رسوم توصيل: ${fee.toStringAsFixed(0)} جنيه', style: Theme.of(context).textTheme.titleMedium),
          Slider(min: 0, max: 50, divisions: 10, value: fee, onChanged: (v) => setState(() => fee = v)),
          const SizedBox(height: 12),
          FilledButton(
            onPressed: () => Navigator.pop(context, RestaurantFilterResult(onlyOpen: onlyOpen, maxFee: fee, minRating: rating)),
            child: const Text('تطبيق الفلاتر'),
          ),
        ],
      ),
    );
  }
}

class _MarketplaceHero extends StatelessWidget {
  const _MarketplaceHero({required this.onTap});
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.symmetric(horizontal: 16),
      height: 174,
      clipBehavior: Clip.antiAlias,
      decoration: BoxDecoration(borderRadius: BorderRadius.circular(22), color: FoodexColors.deepGreen),
      child: Stack(
        fit: StackFit.expand,
        children: [
          Image.network(
            restaurants[1].imageUrl,
            fit: BoxFit.cover,
            errorBuilder: (_, __, ___) => const ColoredBox(color: FoodexColors.deepGreen),
          ),
          const DecoratedBox(
            decoration: BoxDecoration(
              gradient: LinearGradient(
                begin: AlignmentDirectional.centerStart,
                end: AlignmentDirectional.centerEnd,
                colors: [Color(0xF200452F), Color(0xB300452F), Color(0x2000452F)],
              ),
            ),
          ),
          Padding(
            padding: const EdgeInsetsDirectional.fromSTEB(18, 18, 18, 16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                  decoration: BoxDecoration(color: const Color(0x33FFFFFF), borderRadius: BorderRadius.circular(999)),
                  child: const Text('FOODEX RESTAURANTS', style: TextStyle(color: Colors.white, fontSize: 9, fontWeight: FontWeight.w900)),
                ),
                const Spacer(),
                const Text('مطاعمك المفضلة\nبقت على فودكس', style: TextStyle(color: Colors.white, fontSize: 23, height: 1.15, fontWeight: FontWeight.w900)),
                const SizedBox(height: 7),
                const Text('تصفح المنيو واطلب مباشرة من المطعم', style: TextStyle(color: Color(0xE8FFFFFF), fontSize: 11.5, fontWeight: FontWeight.w600)),
                const SizedBox(height: 11),
                SizedBox(
                  width: 118,
                  height: 36,
                  child: FilledButton(
                    style: FilledButton.styleFrom(backgroundColor: FoodexColors.lime, foregroundColor: FoodexColors.deepGreenStrong, padding: EdgeInsets.zero),
                    onPressed: onTap,
                    child: const Text('استكشف المطاعم'),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _QuickActions extends StatelessWidget {
  const _QuickActions({required this.onRestaurants, required this.onWholesale, required this.onOffers, required this.onFavorites});
  final VoidCallback onRestaurants;
  final VoidCallback onWholesale;
  final VoidCallback onOffers;
  final VoidCallback onFavorites;

  @override
  Widget build(BuildContext context) {
    final items = [
      (Icons.restaurant_menu_rounded, 'المطاعم', onRestaurants),
      (Icons.storefront_rounded, 'متجر الجملة', onWholesale),
      (Icons.local_offer_outlined, 'العروض', onOffers),
      (Icons.favorite_border_rounded, 'المفضلة', onFavorites),
    ];
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 16),
      child: Row(
        children: items.map((item) {
          return Expanded(
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 4),
              child: InkWell(
                borderRadius: BorderRadius.circular(16),
                onTap: item.$3,
                child: Container(
                  height: 78,
                  decoration: BoxDecoration(color: FoodexColors.white, border: Border.all(color: FoodexColors.border), borderRadius: BorderRadius.circular(16)),
                  child: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      Container(
                        width: 34,
                        height: 34,
                        decoration: BoxDecoration(color: FoodexColors.limeSoft, borderRadius: BorderRadius.circular(12)),
                        child: Icon(item.$1, size: 19, color: FoodexColors.deepGreen),
                      ),
                      const SizedBox(height: 5),
                      Text(item.$2, style: const TextStyle(fontSize: 9.5, fontWeight: FontWeight.w800)),
                    ],
                  ),
                ),
              ),
            ),
          );
        }).toList(),
      ),
    );
  }
}

class _CuisineScroller extends StatelessWidget {
  const _CuisineScroller();

  @override
  Widget build(BuildContext context) {
    final cuisines = [('🍔', 'برجر'), ('🍕', 'بيتزا'), ('🍚', 'مصري'), ('☕', 'قهوة'), ('🍰', 'حلويات')];
    return SizedBox(
      height: 86,
      child: ListView.separated(
        padding: const EdgeInsets.symmetric(horizontal: 16),
        scrollDirection: Axis.horizontal,
        itemCount: cuisines.length,
        separatorBuilder: (_, __) => const SizedBox(width: 10),
        itemBuilder: (context, index) => InkWell(
          borderRadius: BorderRadius.circular(18),
          onTap: () => Navigator.pushNamed(context, '/restaurants'),
          child: Container(
            width: 78,
            decoration: BoxDecoration(color: FoodexColors.white, borderRadius: BorderRadius.circular(18), border: Border.all(color: FoodexColors.border)),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Text(cuisines[index].$1, style: const TextStyle(fontSize: 29)),
                const SizedBox(height: 4),
                Text(cuisines[index].$2, style: const TextStyle(fontSize: 10.5, fontWeight: FontWeight.w800)),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _OfferBanner extends StatelessWidget {
  const _OfferBanner({required this.title, required this.subtitle, required this.imageUrl, required this.onTap});
  final String title;
  final String subtitle;
  final String imageUrl;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => Container(
        margin: const EdgeInsets.symmetric(horizontal: 16),
        height: 126,
        clipBehavior: Clip.antiAlias,
        decoration: BoxDecoration(borderRadius: BorderRadius.circular(20)),
        child: InkWell(
          onTap: onTap,
          child: Stack(
            fit: StackFit.expand,
            children: [
              Image.network(imageUrl, fit: BoxFit.cover, errorBuilder: (_, __, ___) => const ColoredBox(color: FoodexColors.deepGreen)),
              const DecoratedBox(decoration: BoxDecoration(gradient: LinearGradient(colors: [Color(0xEC003C2A), Color(0x60003C2A), Colors.transparent]))),
              Padding(
                padding: const EdgeInsets.all(16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 4),
                      decoration: BoxDecoration(color: FoodexColors.lime, borderRadius: BorderRadius.circular(99)),
                      child: const Text('عرض خاص', style: TextStyle(fontSize: 9.5, fontWeight: FontWeight.w900, color: FoodexColors.deepGreenStrong)),
                    ),
                    const SizedBox(height: 8),
                    Text(title, style: const TextStyle(fontWeight: FontWeight.w900, color: Colors.white, fontSize: 17)),
                    Text(subtitle, style: const TextStyle(color: Color(0xE6FFFFFF), fontSize: 10.5)),
                  ],
                ),
              ),
            ],
          ),
        ),
      );
}

class _RestaurantHero extends StatelessWidget {
  const _RestaurantHero({required this.restaurant});
  final Restaurant restaurant;

  @override
  Widget build(BuildContext context) => SizedBox(
        height: 220,
        child: Image.network(
          restaurant.imageUrl,
          fit: BoxFit.cover,
          errorBuilder: (_, __, ___) => const ColoredBox(color: FoodexColors.limeSoft),
        ),
      );
}

class _InfoTile extends StatelessWidget {
  const _InfoTile({required this.icon, required this.title, required this.subtitle});
  final IconData icon;
  final String title;
  final String subtitle;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 10),
        decoration: BoxDecoration(color: FoodexColors.mint, borderRadius: BorderRadius.circular(13)),
        child: Column(
          children: [
            Icon(icon, size: 18, color: FoodexColors.deepGreen),
            const SizedBox(height: 5),
            Text(title, maxLines: 1, overflow: TextOverflow.ellipsis, textAlign: TextAlign.center, style: const TextStyle(fontSize: 10.5, fontWeight: FontWeight.w900)),
            const SizedBox(height: 2),
            Text(subtitle, textAlign: TextAlign.center, style: Theme.of(context).textTheme.bodySmall?.copyWith(fontSize: 9)),
          ],
        ),
      );
}

class _GroupTitle extends StatelessWidget {
  const _GroupTitle({required this.title, required this.isRequired});
  final String title;
  final bool isRequired;

  @override
  Widget build(BuildContext context) => Row(
        children: [
          Text(title, style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(width: 6),
          if (isRequired) const Text('مطلوب', style: TextStyle(color: FoodexColors.deepGreen, fontSize: 10, fontWeight: FontWeight.w800)),
        ],
      );
}

class _ChoiceRow extends StatelessWidget {
  const _ChoiceRow({required this.values, required this.selected, required this.labels, required this.onChanged});
  final List<String> values;
  final String selected;
  final List<String> labels;
  final ValueChanged<String> onChanged;

  @override
  Widget build(BuildContext context) => Row(
        children: List.generate(values.length, (index) {
          final active = selected == values[index];
          return Expanded(
            child: Padding(
              padding: EdgeInsetsDirectional.only(end: index == values.length - 1 ? 0 : 7),
              child: InkWell(
                borderRadius: BorderRadius.circular(14),
                onTap: () => onChanged(values[index]),
                child: AnimatedContainer(
                  duration: const Duration(milliseconds: 160),
                  padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 6),
                  decoration: BoxDecoration(
                    color: active ? FoodexColors.limeSoft : FoodexColors.white,
                    border: Border.all(color: active ? FoodexColors.deepGreen : FoodexColors.border, width: active ? 1.5 : 1),
                    borderRadius: BorderRadius.circular(14),
                  ),
                  child: Column(
                    children: [
                      Text(values[index], textAlign: TextAlign.center, style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w800)),
                      const SizedBox(height: 3),
                      Text(labels[index], textAlign: TextAlign.center, style: Theme.of(context).textTheme.bodySmall?.copyWith(fontSize: 9)),
                    ],
                  ),
                ),
              ),
            ),
          );
        }),
      );
}

class _EmptyRestaurants extends StatelessWidget {
  const _EmptyRestaurants();
  @override
  Widget build(BuildContext context) => const Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.restaurant_menu_rounded, size: 52, color: FoodexColors.muted),
            SizedBox(height: 10),
            Text('مفيش مطاعم مطابقة للفلاتر الحالية.'),
          ],
        ),
      );
}

void _showInfo(BuildContext context, String title, String text) {
  showDialog<void>(
    context: context,
    builder: (_) => AlertDialog(title: Text(title), content: Text(text), actions: [TextButton(onPressed: () => Navigator.pop(context), child: const Text('تمام'))]),
  );
}
