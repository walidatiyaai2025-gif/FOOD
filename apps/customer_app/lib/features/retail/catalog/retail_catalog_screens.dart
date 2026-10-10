import 'package:flutter/material.dart';

import '../../../core/api/b2c_catalog_api.dart';
import '../../../core/localization/app_translations.dart';
import '../../../core/routing/customer_routes.dart';
import '../../../shared/customer_ui_v3/customer_ui_v3.dart';

typedef RetailProductsNavigation = void Function(
  BuildContext context, {
  required int storeId,
  String? query,
  int? categoryId,
});
typedef RetailProductNavigation = void Function(
  BuildContext context, {
  required int storeId,
  required int productId,
});
typedef RetailStoreNavigation = void Function(
  BuildContext context, {
  required int storeId,
});
typedef RetailAddToCart = Future<void> Function({
  required int storeId,
  required int productId,
  required double quantity,
});

class RetailCatalogNavigation {
  const RetailCatalogNavigation({
    this.openProducts,
    this.openProduct,
    this.openCart,
    this.openNotifications,
  });

  final RetailProductsNavigation? openProducts;
  final RetailProductNavigation? openProduct;
  final RetailStoreNavigation? openCart;
  final RetailStoreNavigation? openNotifications;
}

class RetailCatalogHomeScreen extends StatefulWidget {
  const RetailCatalogHomeScreen({
    required this.storeId,
    required this.catalogApi,
    this.navigation = const RetailCatalogNavigation(),
    this.onAddToCart,
    super.key,
  }) : assert(storeId > 0);

  final int storeId;
  final B2cCatalogApi catalogApi;
  final RetailCatalogNavigation navigation;
  final RetailAddToCart? onAddToCart;

  @override
  State<RetailCatalogHomeScreen> createState() =>
      _RetailCatalogHomeScreenState();
}

class _RetailCatalogHomeScreenState extends State<RetailCatalogHomeScreen>
    with WidgetsBindingObserver {
  late Future<_RetailCatalogHomeData> _future = _load();
  final TextEditingController _search = TextEditingController();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  Future<_RetailCatalogHomeData> _load() async {
    final values = await Future.wait<Object?>([
      widget.catalogApi.categories(widget.storeId),
      widget.catalogApi.products(widget.storeId),
      widget.catalogApi.offers(widget.storeId),
      widget.catalogApi.banners(widget.storeId),
    ]);
    return _RetailCatalogHomeData(
      categories: values[0] as List<B2cCategory>,
      products: values[1] as List<B2cProduct>,
      offers: values[2] as List<B2cOffer>,
      banners: values[3] as List<B2cBanner>,
    );
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _search.dispose();
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed && mounted) {
      _reload();
    }
  }

  void _reload() {
    setState(() {
      _future = _load();
    });
  }

  @override
  Widget build(BuildContext context) {
    final navigation = widget.navigation;
    return Scaffold(
      key: const ValueKey('retail-catalog-home'),
      appBar: AppBar(
        title: Text(context.tr('customer.home.title')),
        actions: [
          if (navigation.openNotifications != null)
            IconButton(
              key: const ValueKey('retail-catalog-notifications'),
              tooltip: context.tr('customer.nav.notifications'),
              onPressed: () => navigation.openNotifications!(
                context,
                storeId: widget.storeId,
              ),
              icon: const Icon(Icons.notifications_none_rounded),
            ),
          if (navigation.openCart != null)
            IconButton(
              key: const ValueKey('retail-catalog-cart'),
              tooltip: context.tr('customer.nav.cart'),
              onPressed: () => navigation.openCart!(
                context,
                storeId: widget.storeId,
              ),
              icon: const Icon(Icons.shopping_cart_outlined),
            ),
        ],
      ),
      body: FutureBuilder<_RetailCatalogHomeData>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done) {
            return const Center(child: CircularProgressIndicator());
          }
          if (snapshot.hasError || !snapshot.hasData) {
            return _CatalogErrorState(
              error: snapshot.error,
              onRetry: _reload,
            );
          }

          final data = snapshot.data!;
          return RefreshIndicator(
            onRefresh: () async {
              _reload();
              await _future;
            },
            child: ListView(
              padding: const EdgeInsets.fromLTRB(16, 12, 16, 28),
              children: [
                if (navigation.openProducts != null)
                  TextField(
                    key: const ValueKey('retail-catalog-search'),
                    controller: _search,
                    textInputAction: TextInputAction.search,
                    decoration: InputDecoration(
                      hintText: context.tr('customer.products.search'),
                      prefixIcon: const Icon(Icons.search_rounded),
                      border: const OutlineInputBorder(),
                    ),
                    onSubmitted: (value) => navigation.openProducts!(
                      context,
                      storeId: widget.storeId,
                      query: value.trim().isEmpty ? null : value.trim(),
                    ),
                  ),
                if (navigation.openProducts != null) const SizedBox(height: 20),
                if (data.banners.isNotEmpty) ...[
                  _CatalogBanner(banner: data.banners.first),
                  const SizedBox(height: 22),
                ],
                _SectionTitle(context.tr('customer.home.categories')),
                const SizedBox(height: 10),
                if (data.categories.isEmpty)
                  _EmptyMessage(context.tr('customer.empty'))
                else
                  SizedBox(
                    height: 92,
                    child: ListView.separated(
                      scrollDirection: Axis.horizontal,
                      itemCount: data.categories.length,
                      separatorBuilder: (_, __) => const SizedBox(width: 10),
                      itemBuilder: (context, index) {
                        final category = data.categories[index];
                        return _CategoryTile(
                          category: category,
                          enabled: navigation.openProducts != null,
                          onTap: navigation.openProducts == null
                              ? null
                              : () => navigation.openProducts!(
                                    context,
                                    storeId: widget.storeId,
                                    categoryId: category.id,
                                  ),
                        );
                      },
                    ),
                  ),
                if (data.offers.isNotEmpty) ...[
                  const SizedBox(height: 24),
                  _SectionTitle(context.tr('customer.home.offers')),
                  const SizedBox(height: 10),
                  Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    children: data.offers
                        .map(
                          (offer) => Chip(
                            key: ValueKey('retail-offer-${offer.id}'),
                            label: Text(offer.name),
                          ),
                        )
                        .toList(growable: false),
                  ),
                ],
                const SizedBox(height: 24),
                _SectionTitle(context.tr('customer.home.all_products')),
                const SizedBox(height: 10),
                if (data.products.isEmpty)
                  _EmptyMessage(context.tr('customer.products.empty'))
                else
                  ...data.products.map(
                    (product) => Padding(
                      padding: const EdgeInsets.only(bottom: 10),
                      child: _ProductTile(
                        storeId: widget.storeId,
                        product: product,
                        navigation: navigation,
                        onAddToCart: widget.onAddToCart,
                      ),
                    ),
                  ),
              ],
            ),
          );
        },
      ),
    );
  }
}

class RetailCatalogCategoriesScreen extends StatefulWidget {
  const RetailCatalogCategoriesScreen({
    required this.storeId,
    required this.catalogApi,
    this.navigation = const RetailCatalogNavigation(),
    super.key,
  }) : assert(storeId > 0);

  final int storeId;
  final B2cCatalogApi catalogApi;
  final RetailCatalogNavigation navigation;

  @override
  State<RetailCatalogCategoriesScreen> createState() =>
      _RetailCatalogCategoriesScreenState();
}

class _RetailCatalogCategoriesScreenState
    extends State<RetailCatalogCategoriesScreen> with WidgetsBindingObserver {
  late Future<List<B2cCategory>> _future = _load();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed && mounted) {
      _reload();
    }
  }

  Future<List<B2cCategory>> _load() =>
      widget.catalogApi.categories(widget.storeId);

  void _reload() {
    setState(() {
      _future = _load();
    });
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        key: const ValueKey('retail-catalog-categories'),
        backgroundColor: CustomerUiColors.mint,
        appBar: AppBar(
          title: Text(context.tr('customer.home.categories')),
          actions: [
            if (widget.navigation.openCart != null)
              IconButton(
                key: const ValueKey('retail-categories-cart'),
                tooltip: context.tr('customer.nav.cart'),
                onPressed: () => widget.navigation.openCart!(
                  context,
                  storeId: widget.storeId,
                ),
                icon: const Icon(Icons.shopping_bag_outlined),
              ),
          ],
        ),
        body: FutureBuilder<List<B2cCategory>>(
          future: _future,
          builder: (context, snapshot) {
            if (snapshot.connectionState != ConnectionState.done) {
              return GridView.builder(
                key: const ValueKey('retail-categories-loading'),
                padding: const EdgeInsets.all(CustomerUiSpacing.page),
                gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
                  crossAxisCount: 3,
                  crossAxisSpacing: CustomerUiSpacing.sm,
                  mainAxisSpacing: CustomerUiSpacing.md,
                  mainAxisExtent: 112,
                ),
                itemCount: 9,
                itemBuilder: (_, __) =>
                    const Center(child: CustomerCategorySkeleton()),
              );
            }

            if (snapshot.hasError || !snapshot.hasData) {
              return _CatalogErrorState(
                error: snapshot.error,
                onRetry: _reload,
              );
            }

            final categories = snapshot.data!;
            if (categories.isEmpty) {
              return CustomerStateView(
                kind: CustomerStateKind.empty,
                title: context.tr('customer.empty'),
                icon: Icons.category_outlined,
              );
            }

            return LayoutBuilder(
              builder: (context, constraints) {
                final columns = constraints.maxWidth >= 720 ? 5 : 3;
                return GridView.builder(
                  key: const ValueKey('retail-categories-grid'),
                  padding: const EdgeInsets.fromLTRB(
                    CustomerUiSpacing.page,
                    CustomerUiSpacing.lg,
                    CustomerUiSpacing.page,
                    CustomerUiSpacing.xxl,
                  ),
                  gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
                    crossAxisCount: columns,
                    crossAxisSpacing: CustomerUiSpacing.sm,
                    mainAxisSpacing: CustomerUiSpacing.md,
                    mainAxisExtent: 116,
                  ),
                  itemCount: categories.length,
                  itemBuilder: (context, index) {
                    final category = categories[index];
                    return Center(
                      child: CustomerCategoryTile(
                        key: ValueKey('retail-category-grid-${category.id}'),
                        label: category.name,
                        imageUrl: category.imageUrl,
                        onTap: widget.navigation.openProducts == null
                            ? null
                            : () => widget.navigation.openProducts!(
                                  context,
                                  storeId: widget.storeId,
                                  categoryId: category.id,
                                ),
                      ),
                    );
                  },
                );
              },
            );
          },
        ),
      );
}

class RetailCatalogOffersScreen extends StatefulWidget {
  const RetailCatalogOffersScreen({
    required this.storeId,
    required this.catalogApi,
    this.navigation = const RetailCatalogNavigation(),
    super.key,
  }) : assert(storeId > 0);

  final int storeId;
  final B2cCatalogApi catalogApi;
  final RetailCatalogNavigation navigation;

  @override
  State<RetailCatalogOffersScreen> createState() =>
      _RetailCatalogOffersScreenState();
}

class _RetailCatalogOffersScreenState extends State<RetailCatalogOffersScreen>
    with WidgetsBindingObserver {
  late Future<List<B2cOffer>> _future = _load();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed && mounted) {
      _reload();
    }
  }

  Future<List<B2cOffer>> _load() => widget.catalogApi.offers(widget.storeId);

  void _reload() {
    setState(() {
      _future = _load();
    });
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        key: const ValueKey('retail-catalog-offers'),
        backgroundColor: CustomerUiColors.mint,
        appBar: AppBar(
          title: Text(context.tr('customer.home.offers')),
          actions: [
            if (widget.navigation.openCart != null)
              IconButton(
                key: const ValueKey('retail-offers-cart'),
                tooltip: context.tr('customer.nav.cart'),
                onPressed: () => widget.navigation.openCart!(
                  context,
                  storeId: widget.storeId,
                ),
                icon: const Icon(Icons.shopping_bag_outlined),
              ),
          ],
        ),
        body: FutureBuilder<List<B2cOffer>>(
          future: _future,
          builder: (context, snapshot) {
            if (snapshot.connectionState != ConnectionState.done) {
              return ListView.separated(
                key: const ValueKey('retail-offers-loading'),
                padding: const EdgeInsets.all(CustomerUiSpacing.page),
                itemCount: 4,
                separatorBuilder: (_, __) =>
                    const SizedBox(height: CustomerUiSpacing.sm),
                itemBuilder: (_, __) => const CustomerSkeletonBox(
                  height: 96,
                  radius: CustomerUiRadii.xl,
                ),
              );
            }

            if (snapshot.hasError || !snapshot.hasData) {
              return _CatalogErrorState(
                error: snapshot.error,
                onRetry: _reload,
              );
            }

            final offers = snapshot.data!;
            if (offers.isEmpty) {
              return CustomerStateView(
                kind: CustomerStateKind.empty,
                title: context.tr('customer.empty'),
                icon: Icons.local_offer_outlined,
              );
            }

            return ListView.separated(
              key: const ValueKey('retail-offers-list'),
              padding: const EdgeInsets.fromLTRB(
                CustomerUiSpacing.page,
                CustomerUiSpacing.lg,
                CustomerUiSpacing.page,
                CustomerUiSpacing.xxl,
              ),
              itemCount: offers.length,
              separatorBuilder: (_, __) =>
                  const SizedBox(height: CustomerUiSpacing.sm),
              itemBuilder: (context, index) {
                final offer = offers[index];
                final value = offer.value;
                return Material(
                  color: CustomerUiColors.white,
                  borderRadius: BorderRadius.circular(CustomerUiRadii.xl),
                  child: DecoratedBox(
                    decoration: BoxDecoration(
                      borderRadius: BorderRadius.circular(CustomerUiRadii.xl),
                      border: Border.all(color: CustomerUiColors.border),
                    ),
                    child: Padding(
                      padding: const EdgeInsets.all(CustomerUiSpacing.md),
                      child: Row(
                        children: [
                          DecoratedBox(
                            decoration: const BoxDecoration(
                              color: CustomerUiColors.limeSoft,
                              shape: BoxShape.circle,
                            ),
                            child: const SizedBox.square(
                              dimension: 52,
                              child: Icon(
                                Icons.local_offer_outlined,
                                color: CustomerUiColors.deepGreenStrong,
                              ),
                            ),
                          ),
                          const SizedBox(width: CustomerUiSpacing.md),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  offer.name,
                                  style:
                                      Theme.of(context).textTheme.titleMedium,
                                ),
                                if (offer.type.trim().isNotEmpty) ...[
                                  const SizedBox(
                                    height: CustomerUiSpacing.xs,
                                  ),
                                  Text(
                                    _offerTypeLabel(offer.type),
                                    style: Theme.of(context)
                                        .textTheme
                                        .bodyMedium
                                        ?.copyWith(
                                          color: CustomerUiColors.muted,
                                        ),
                                  ),
                                ],
                              ],
                            ),
                          ),
                          if (value != null)
                            CustomerBadge(
                              label: _formatOfferValue(offer),
                              tone: CustomerBadgeTone.discount,
                            ),
                        ],
                      ),
                    ),
                  ),
                );
              },
            );
          },
        ),
      );

  String _offerTypeLabel(String value) {
    final normalized = value.trim().toLowerCase();
    final ar = Localizations.localeOf(context).languageCode == 'ar';
    if (normalized.contains('percent')) {
      return ar ? 'خصم بالنسبة المئوية' : 'Percentage discount';
    }
    if (normalized.contains('fixed') || normalized.contains('amount')) {
      return ar ? 'خصم بقيمة ثابتة' : 'Fixed discount';
    }
    if (normalized.contains('bundle') || normalized.contains('buy')) {
      return ar ? 'عرض باقة' : 'Bundle offer';
    }
    if (normalized.contains('flash')) {
      return ar ? 'عرض سريع' : 'Flash offer';
    }
    return ar ? 'عرض' : 'Offer';
  }

  String _formatOfferValue(B2cOffer offer) {
    final value = offer.value;
    if (value == null) return '';
    final normalizedType = offer.type.toLowerCase();
    final fractionDigits = value == value.roundToDouble() ? 0 : 2;
    final formatted = value.toStringAsFixed(fractionDigits);
    return normalizedType.contains('percent') ? '$formatted%' : formatted;
  }
}

class RetailCatalogProductsScreen extends StatefulWidget {
  const RetailCatalogProductsScreen({
    required this.storeId,
    required this.catalogApi,
    this.navigation = const RetailCatalogNavigation(),
    this.initialQuery,
    this.categoryId,
    this.onAddToCart,
    super.key,
  }) : assert(storeId > 0);

  final int storeId;
  final B2cCatalogApi catalogApi;
  final RetailCatalogNavigation navigation;
  final String? initialQuery;
  final int? categoryId;
  final RetailAddToCart? onAddToCart;

  @override
  State<RetailCatalogProductsScreen> createState() =>
      _RetailCatalogProductsScreenState();
}

class _RetailCatalogProductsScreenState
    extends State<RetailCatalogProductsScreen> with WidgetsBindingObserver {
  late final TextEditingController _search =
      TextEditingController(text: widget.initialQuery ?? '');
  late String? _query = _normalized(widget.initialQuery);
  late Future<List<B2cProduct>> _future = _load();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  Future<List<B2cProduct>> _load() => widget.catalogApi.products(
        widget.storeId,
        query: _query,
        categoryId: widget.categoryId,
      );

  void _submit(String value) {
    setState(() {
      _query = _normalized(value);
      _future = _load();
    });
  }

  void _reload() {
    setState(() {
      _future = _load();
    });
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _search.dispose();
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed && mounted) {
      _reload();
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        key: const ValueKey('retail-catalog-products'),
        backgroundColor: CustomerUiColors.mint,
        appBar: AppBar(
          title: Text(context.tr('customer.products.title')),
          backgroundColor: CustomerUiColors.deepGreen,
          foregroundColor: CustomerUiColors.white,
          surfaceTintColor: Colors.transparent,
          elevation: 0,
          actions: [
            if (widget.navigation.openCart != null)
              IconButton(
                key: const ValueKey('retail-products-cart'),
                tooltip: context.tr('customer.nav.cart'),
                onPressed: () => widget.navigation.openCart!(
                  context,
                  storeId: widget.storeId,
                ),
                icon: const Icon(Icons.shopping_bag_outlined),
              ),
          ],
        ),
        body: Column(
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(
                CustomerUiSpacing.page,
                CustomerUiSpacing.md,
                CustomerUiSpacing.page,
                CustomerUiSpacing.xs,
              ),
              child: CustomerSearchPill(
                key: const ValueKey('retail-products-search'),
                controller: _search,
                hintText: context.tr('customer.products.search'),
                semanticLabel: context.tr('customer.products.search'),
                onSubmitted: _submit,
              ),
            ),
            Expanded(
              child: FutureBuilder<List<B2cProduct>>(
                future: _future,
                builder: (context, snapshot) {
                  if (snapshot.connectionState != ConnectionState.done) {
                    return const _BrowseProductsSkeleton();
                  }
                  if (snapshot.hasError || !snapshot.hasData) {
                    return _CatalogErrorState(
                      error: snapshot.error,
                      onRetry: _reload,
                    );
                  }
                  final products = snapshot.data!;
                  if (products.isEmpty) {
                    return CustomerStateView(
                      kind: CustomerStateKind.empty,
                      title: context.tr('customer.products.empty'),
                      icon: Icons.search_off_rounded,
                    );
                  }
                  return LayoutBuilder(
                    builder: (context, constraints) {
                      final columns = constraints.maxWidth >= 720 ? 3 : 2;
                      final textScale =
                          MediaQuery.textScalerOf(context).scale(1);
                      final scaledDelta =
                          (textScale - 1.0).clamp(0.0, 1.0).toDouble();
                      final cardExtent = 324.0 + (scaledDelta * 210.0);
                      return GridView.builder(
                        key: const ValueKey('retail-products-grid'),
                        padding: const EdgeInsets.fromLTRB(
                          CustomerUiSpacing.page,
                          CustomerUiSpacing.xs,
                          CustomerUiSpacing.page,
                          CustomerUiSpacing.xxl,
                        ),
                        gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
                          crossAxisCount: columns,
                          crossAxisSpacing: CustomerUiSpacing.sm,
                          mainAxisSpacing: CustomerUiSpacing.sm,
                          mainAxisExtent: cardExtent,
                        ),
                        itemCount: products.length,
                        itemBuilder: (context, index) => _BrowseProductCard(
                          storeId: widget.storeId,
                          product: products[index],
                          navigation: widget.navigation,
                          onAddToCart: widget.onAddToCart,
                        ),
                      );
                    },
                  );
                },
              ),
            ),
          ],
        ),
      );

  static String? _normalized(String? value) {
    final normalized = value?.trim();
    return normalized == null || normalized.isEmpty ? null : normalized;
  }
}

class RetailCatalogProductScreen extends StatefulWidget {
  const RetailCatalogProductScreen({
    required this.storeId,
    required this.productId,
    required this.catalogApi,
    this.navigation = const RetailCatalogNavigation(),
    this.onAddToCart,
    super.key,
  })  : assert(storeId > 0),
        assert(productId > 0);

  final int storeId;
  final int productId;
  final B2cCatalogApi catalogApi;
  final RetailCatalogNavigation navigation;
  final RetailAddToCart? onAddToCart;

  @override
  State<RetailCatalogProductScreen> createState() =>
      _RetailCatalogProductScreenState();
}

class _RetailCatalogProductScreenState extends State<RetailCatalogProductScreen>
    with WidgetsBindingObserver {
  late Future<B2cProduct> _future = _load();
  double _quantity = 1;
  bool _submitting = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed && mounted) {
      _reload();
    }
  }

  void _reload() {
    setState(() {
      _future = _load();
    });
  }

  Future<B2cProduct> _load() =>
      widget.catalogApi.product(widget.productId, storeId: widget.storeId);

  Future<void> _add(B2cProduct product) async {
    final callback = widget.onAddToCart;
    if (callback == null || _submitting) return;
    setState(() => _submitting = true);
    try {
      await callback(
        storeId: widget.storeId,
        productId: product.id,
        quantity: _quantity,
      );
    } catch (_) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(context.tr('customer.error.action_failed'))),
        );
      }
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        key: const ValueKey('retail-catalog-product'),
        backgroundColor: CustomerUiColors.mint,
        appBar: AppBar(
          title: Text(context.tr('customer.product.title')),
          backgroundColor: CustomerUiColors.deepGreen,
          foregroundColor: CustomerUiColors.white,
          surfaceTintColor: Colors.transparent,
          elevation: 0,
          actions: [
            if (widget.navigation.openCart != null)
              IconButton(
                key: const ValueKey('retail-product-cart'),
                tooltip: context.tr('customer.nav.cart'),
                onPressed: () => widget.navigation.openCart!(
                  context,
                  storeId: widget.storeId,
                ),
                icon: const Icon(Icons.shopping_bag_outlined),
              ),
          ],
        ),
        body: FutureBuilder<B2cProduct>(
          future: _future,
          builder: (context, snapshot) {
            if (snapshot.connectionState != ConnectionState.done) {
              return const _ProductDetailSkeleton();
            }
            if (snapshot.hasError || !snapshot.hasData) {
              return _CatalogErrorState(
                error: snapshot.error,
                onRetry: _reload,
              );
            }
            final product = snapshot.data!;
            final image = product.images.isNotEmpty
                ? product.images.first
                : product.imageUrl;
            return ListView(
              padding: const EdgeInsets.fromLTRB(
                CustomerUiSpacing.page,
                CustomerUiSpacing.md,
                CustomerUiSpacing.page,
                CustomerUiSpacing.xxl,
              ),
              children: [
                if (image?.trim().isNotEmpty == true)
                  Opacity(
                    opacity: product.isAvailable ? 1 : 0.42,
                    child: CustomerProductImage(
                      imageUrl: image,
                      aspectRatio: 1.15,
                    ),
                  )
                else
                  SizedBox(
                    height: 136,
                    child: Opacity(
                      opacity: product.isAvailable ? 1 : 0.42,
                      child: CustomerProductImage(
                        imageUrl: image,
                        aspectRatio: 2.4,
                      ),
                    ),
                  ),
                const SizedBox(height: CustomerUiSpacing.md),
                DecoratedBox(
                  decoration: BoxDecoration(
                    color: CustomerUiColors.white,
                    borderRadius: BorderRadius.circular(CustomerUiRadii.xl),
                    border: Border.all(color: CustomerUiColors.border),
                    boxShadow: CustomerUiElevation.cardShadow,
                  ),
                  child: Padding(
                    padding: const EdgeInsets.all(CustomerUiSpacing.lg),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          product.name,
                          key: const ValueKey('retail-product-name'),
                          style: Theme.of(context).textTheme.headlineSmall,
                        ),
                        const SizedBox(height: CustomerUiSpacing.xs),
                        Text(
                          product.sku,
                          style:
                              Theme.of(context).textTheme.bodyMedium?.copyWith(
                                    color: CustomerUiColors.muted,
                                  ),
                        ),
                        if (product.price != null) ...[
                          const SizedBox(height: CustomerUiSpacing.md),
                          Text(
                            '${product.price!.toStringAsFixed(3)} ${product.currency}',
                            key: const ValueKey('retail-product-price'),
                            style: Theme.of(context)
                                .textTheme
                                .titleLarge
                                ?.copyWith(
                                  color: CustomerUiColors.deepGreenStrong,
                                ),
                          ),
                        ],
                        const SizedBox(height: CustomerUiSpacing.sm),
                        Text(
                          product.isAvailable
                              ? context.tr('customer.product.available')
                              : context.tr('customer.product.out_of_stock'),
                          key: ValueKey(
                            product.isAvailable
                                ? 'retail-product-available'
                                : 'retail-product-out-of-stock',
                          ),
                          style:
                              Theme.of(context).textTheme.labelLarge?.copyWith(
                                    color: product.isAvailable
                                        ? CustomerUiColors.deepGreenStrong
                                        : CustomerUiColors.muted,
                                    fontWeight: FontWeight.w800,
                                  ),
                        ),
                        if (product.availableQuantity != null)
                          Text(
                            product.availableQuantity!.toStringAsFixed(3),
                            key: const ValueKey(
                                'retail-product-available-quantity'),
                            style:
                                Theme.of(context).textTheme.bodySmall?.copyWith(
                                      color: CustomerUiColors.muted,
                                    ),
                          ),
                        if (product.description?.trim().isNotEmpty == true) ...[
                          const SizedBox(height: CustomerUiSpacing.lg),
                          Text(
                            product.description!,
                            style: Theme.of(context).textTheme.bodyLarge,
                          ),
                        ],
                        if (widget.onAddToCart != null) ...[
                          const SizedBox(height: CustomerUiSpacing.xl),
                          DecoratedBox(
                            decoration: BoxDecoration(
                              color: CustomerUiColors.mint,
                              borderRadius:
                                  BorderRadius.circular(CustomerUiRadii.lg),
                            ),
                            child: Padding(
                              padding: const EdgeInsets.symmetric(
                                horizontal: CustomerUiSpacing.xs,
                                vertical: CustomerUiSpacing.xxs,
                              ),
                              child: Row(
                                mainAxisSize: MainAxisSize.min,
                                children: [
                                  IconButton(
                                    key: const ValueKey('retail-product-minus'),
                                    onPressed: !product.isAvailable ||
                                            _quantity <= 1
                                        ? null
                                        : () => setState(() => _quantity -= 1),
                                    icon: const Icon(Icons.remove_rounded),
                                  ),
                                  ConstrainedBox(
                                    constraints:
                                        const BoxConstraints(minWidth: 40),
                                    child: Text(
                                      _quantity.toStringAsFixed(0),
                                      key: const ValueKey(
                                        'retail-product-quantity',
                                      ),
                                      textAlign: TextAlign.center,
                                      style: Theme.of(context)
                                          .textTheme
                                          .titleMedium,
                                    ),
                                  ),
                                  IconButton(
                                    key: const ValueKey('retail-product-plus'),
                                    onPressed: product.isAvailable
                                        ? () => setState(() => _quantity += 1)
                                        : null,
                                    icon: const Icon(Icons.add_rounded),
                                  ),
                                ],
                              ),
                            ),
                          ),
                          const SizedBox(height: CustomerUiSpacing.md),
                          SizedBox(
                            width: double.infinity,
                            child: FilledButton.icon(
                              key: const ValueKey('retail-product-add-cart'),
                              onPressed: _submitting || !product.isAvailable
                                  ? null
                                  : () => _add(product),
                              icon: const Icon(Icons.add_shopping_cart_rounded),
                              label:
                                  Text(context.tr('customer.action.add_cart')),
                            ),
                          ),
                        ],
                      ],
                    ),
                  ),
                ),
              ],
            );
          },
        ),
      );
}

class _BrowseProductCard extends StatelessWidget {
  const _BrowseProductCard({
    required this.storeId,
    required this.product,
    required this.navigation,
    required this.onAddToCart,
  });

  final int storeId;
  final B2cProduct product;
  final RetailCatalogNavigation navigation;
  final RetailAddToCart? onAddToCart;

  @override
  Widget build(BuildContext context) {
    final openProduct = navigation.openProduct;
    final image = product.thumbnailUrl?.trim().isNotEmpty == true
        ? product.thumbnailUrl
        : product.imageUrl;
    final priceLabel = product.price == null
        ? '—'
        : '${product.price!.toStringAsFixed(3)} ${product.currency}';

    return CustomerProductCard(
      key: ValueKey('retail-product-${product.id}'),
      title: product.name,
      priceLabel: priceLabel,
      imageUrl: image,
      brandLabel: product.brandName,
      brandImageUrl: product.brandImageUrl,
      isAvailable: product.isAvailable,
      unavailableLabel: context.tr('customer.product.out_of_stock'),
      onTap: openProduct == null
          ? null
          : () => openProduct(
                context,
                storeId: storeId,
                productId: product.id,
              ),
      onAdd: !product.isAvailable || onAddToCart == null
          ? null
          : () => onAddToCart!(
                storeId: storeId,
                productId: product.id,
                quantity: 1,
              ),
      addSemanticLabel: context.tr('customer.action.add_cart'),
    );
  }
}

class _BrowseProductsSkeleton extends StatelessWidget {
  const _BrowseProductsSkeleton();

  @override
  Widget build(BuildContext context) => LayoutBuilder(
        builder: (context, constraints) {
          final columns = constraints.maxWidth >= 720 ? 3 : 2;
          final textScale = MediaQuery.textScalerOf(context).scale(1);
          final scaledDelta =
              (textScale - 1.0).clamp(0.0, 1.0).toDouble();
          final cardExtent = 324.0 + (scaledDelta * 210.0);
          return GridView.builder(
            key: const ValueKey('retail-products-loading'),
            padding: const EdgeInsets.fromLTRB(
              CustomerUiSpacing.page,
              CustomerUiSpacing.xs,
              CustomerUiSpacing.page,
              CustomerUiSpacing.xxl,
            ),
            gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
              crossAxisCount: columns,
              crossAxisSpacing: CustomerUiSpacing.sm,
              mainAxisSpacing: CustomerUiSpacing.sm,
              mainAxisExtent: cardExtent,
            ),
            itemCount: 6,
            itemBuilder: (_, __) => const CustomerProductCardSkeleton(),
          );
        },
      );
}

class _ProductDetailSkeleton extends StatelessWidget {
  const _ProductDetailSkeleton();

  @override
  Widget build(BuildContext context) => ListView(
        key: const ValueKey('retail-product-loading'),
        padding: const EdgeInsets.all(CustomerUiSpacing.page),
        children: [
          const CustomerSkeletonBox(
            height: 280,
            radius: CustomerUiRadii.lg,
          ),
          const SizedBox(height: CustomerUiSpacing.md),
          DecoratedBox(
            decoration: BoxDecoration(
              color: CustomerUiColors.white,
              borderRadius: BorderRadius.circular(CustomerUiRadii.xl),
              border: Border.all(color: CustomerUiColors.border),
            ),
            child: const Padding(
              padding: EdgeInsets.all(CustomerUiSpacing.lg),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  CustomerSkeletonBox(height: 22, width: 190, radius: 11),
                  SizedBox(height: CustomerUiSpacing.sm),
                  CustomerSkeletonBox(height: 16, width: 96, radius: 8),
                  SizedBox(height: CustomerUiSpacing.lg),
                  CustomerSkeletonBox(height: 24, width: 128, radius: 12),
                  SizedBox(height: CustomerUiSpacing.lg),
                  CustomerSkeletonBox(height: 16, radius: 8),
                  SizedBox(height: CustomerUiSpacing.xs),
                  CustomerSkeletonBox(height: 16, width: 220, radius: 8),
                ],
              ),
            ),
          ),
        ],
      );
}

class _RetailCatalogHomeData {
  const _RetailCatalogHomeData({
    required this.categories,
    required this.products,
    required this.offers,
    required this.banners,
  });

  final List<B2cCategory> categories;
  final List<B2cProduct> products;
  final List<B2cOffer> offers;
  final List<B2cBanner> banners;
}

class _CatalogBanner extends StatelessWidget {
  const _CatalogBanner({required this.banner});

  final B2cBanner banner;

  @override
  Widget build(BuildContext context) => Card(
        clipBehavior: Clip.antiAlias,
        child: Stack(
          alignment: AlignmentDirectional.bottomStart,
          children: [
            if (banner.imageUrl?.isNotEmpty == true)
              AspectRatio(
                aspectRatio: 2.4,
                child: Image.network(
                  banner.imageUrl!,
                  fit: BoxFit.cover,
                  errorBuilder: (_, __, ___) =>
                      const ColoredBox(color: Color(0xFFF1F3F2)),
                ),
              )
            else
              const SizedBox(height: 96, width: double.infinity),
            Padding(
              padding: const EdgeInsets.all(14),
              child: Text(
                banner.title,
                style: Theme.of(context).textTheme.titleMedium,
              ),
            ),
          ],
        ),
      );
}

class _CategoryTile extends StatelessWidget {
  const _CategoryTile({
    required this.category,
    required this.enabled,
    this.onTap,
  });

  final B2cCategory category;
  final bool enabled;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) => SizedBox(
        width: 82,
        child: InkWell(
          key: ValueKey('retail-category-${category.id}'),
          onTap: enabled ? onTap : null,
          borderRadius: BorderRadius.circular(14),
          child: Column(
            children: [
              CircleAvatar(
                radius: 28,
                backgroundImage: category.imageUrl?.isNotEmpty == true
                    ? NetworkImage(category.imageUrl!)
                    : null,
                child: category.imageUrl?.isNotEmpty == true
                    ? null
                    : const Icon(Icons.category_outlined),
              ),
              const SizedBox(height: 6),
              Text(
                category.name,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
              ),
            ],
          ),
        ),
      );
}

class _ProductTile extends StatelessWidget {
  const _ProductTile({
    required this.storeId,
    required this.product,
    required this.navigation,
    required this.onAddToCart,
  });

  final int storeId;
  final B2cProduct product;
  final RetailCatalogNavigation navigation;
  final RetailAddToCart? onAddToCart;

  @override
  Widget build(BuildContext context) {
    final openProduct = navigation.openProduct;
    return Card(
      child: InkWell(
        key: ValueKey('retail-product-${product.id}'),
        onTap: openProduct == null
            ? null
            : () => openProduct(
                  context,
                  storeId: storeId,
                  productId: product.id,
                ),
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Row(
            children: [
              SizedBox(
                width: 58,
                height: 58,
                child: product.imageUrl?.isNotEmpty == true
                    ? ClipRRect(
                        borderRadius: BorderRadius.circular(10),
                        child: Image.network(
                          product.imageUrl!,
                          fit: BoxFit.cover,
                          errorBuilder: (_, __, ___) =>
                              const ColoredBox(color: Color(0xFFF1F3F2)),
                        ),
                      )
                    : const Icon(Icons.inventory_2_outlined),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      product.name,
                      style: const TextStyle(fontWeight: FontWeight.w700),
                    ),
                    const SizedBox(height: 4),
                    Text(product.sku),
                    if (product.price != null)
                      Text(
                        '${product.price!.toStringAsFixed(3)} ${product.currency}',
                      ),
                  ],
                ),
              ),
              if (onAddToCart != null)
                IconButton(
                  key: ValueKey('retail-product-add-${product.id}'),
                  tooltip: context.tr('customer.action.add_cart'),
                  onPressed: () => onAddToCart!(
                    storeId: storeId,
                    productId: product.id,
                    quantity: 1,
                  ),
                  icon: const Icon(Icons.add_shopping_cart_rounded),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

class _SectionTitle extends StatelessWidget {
  const _SectionTitle(this.title);
  final String title;

  @override
  Widget build(BuildContext context) => Text(
        title,
        style: Theme.of(context).textTheme.titleLarge,
      );
}

class _EmptyMessage extends StatelessWidget {
  const _EmptyMessage(this.message);
  final String message;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 24),
        child: Text(message, textAlign: TextAlign.center),
      );
}

class _CatalogErrorState extends StatelessWidget {
  const _CatalogErrorState({
    required this.onRetry,
    this.error,
  });

  final VoidCallback onRetry;
  final Object? error;

  @override
  Widget build(BuildContext context) {
    final catalogError = error;
    if (catalogError is B2cCatalogException &&
        catalogError.isSelfStorePurchaseNotAllowed) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (!context.mounted) return;
        Navigator.of(context).pushNamedAndRemoveUntil(
          CustomerRoutePaths.marketplace,
          (route) => false,
        );
      });
      return KeyedSubtree(
        key: const ValueKey('retail-own-store-blocked'),
        child: CustomerStateView(
          kind: CustomerStateKind.error,
          title: context.tr('customer.store.own_purchase_blocked.title'),
          message: context.tr('customer.store.own_purchase_blocked.body'),
          actionLabel: context.tr('customer.store.own_purchase_blocked.action'),
          onAction: () => Navigator.of(context).pushNamedAndRemoveUntil(
            CustomerRoutePaths.marketplace,
            (route) => false,
          ),
          icon: Icons.storefront_outlined,
        ),
      );
    }

    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              context.tr('customer.error.action_failed'),
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 12),
            OutlinedButton(
              key: const ValueKey('retail-catalog-retry'),
              onPressed: onRetry,
              child: Text(context.tr('customer.action.retry')),
            ),
          ],
        ),
      ),
    );
  }
}
