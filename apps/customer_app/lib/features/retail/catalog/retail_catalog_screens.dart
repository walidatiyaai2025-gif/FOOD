import 'dart:async';
import '../../storefront/storefront_design_system.dart';

import 'package:flutter/material.dart';

import '../../../core/api/b2c_catalog_api.dart';
import '../../../core/localization/app_translations.dart';
import '../../../core/routing/customer_routes.dart';
import '../../../shared/customer_ui_v3/customer_ui_v3.dart';

import 'package:foodex_formatting/foodex_formatting.dart';

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
      body: FoodexUnifiedHeaderSurface(
        title: context.tr('customer.home.title'),
        searchController: _search,
        searchHint: context.tr('customer.products.search_name_sku_barcode'),
        searchKey: const ValueKey('retail-catalog-search'),
        notificationKey: const ValueKey('retail-catalog-notifications'),
        cartKey: const ValueKey('retail-catalog-cart'),
        onSearchSubmitted: (value) => navigation.openProducts?.call(
          context,
          storeId: widget.storeId,
          query: value.trim().isEmpty ? null : value.trim(),
        ),
        onNotifications: navigation.openNotifications == null
            ? null
            : () => navigation.openNotifications!(
                  context,
                  storeId: widget.storeId,
                ),
        onCart: navigation.openCart == null
            ? null
            : () => navigation.openCart!(context, storeId: widget.storeId),
        child: FutureBuilder<_RetailCatalogHomeData>(
          future: _future,
          builder: (context, snapshot) {
            if (snapshot.connectionState != ConnectionState.done) {
              return const Center(child: CircularProgressIndicator());
            }
            if (snapshot.hasError || !snapshot.hasData) {
              return _CatalogErrorState(
                  error: snapshot.error, onRetry: _reload);
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
  late Future<B2cCatalogPage<B2cCategory>> _future = _loadPage(1);
  final List<B2cCategory> _additional = <B2cCategory>[];
  B2cCatalogPage<B2cCategory>? _lastLoadedPage;
  Object? _loadMoreError;
  bool _loadingMore = false;
  int _generation = 0;

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

  Future<B2cCatalogPage<B2cCategory>> _loadPage(int page) async {
    final api = widget.catalogApi;
    if (api is B2cPagedCatalogApi) {
      return api.categoryPage(widget.storeId, page: page);
    }
    if (page != 1) {
      return B2cCatalogPage<B2cCategory>.single(const <B2cCategory>[]);
    }
    return B2cCatalogPage<B2cCategory>.single(
      await api.categories(widget.storeId),
    );
  }

  void _resetPaging() {
    _generation++;
    _additional.clear();
    _lastLoadedPage = null;
    _loadMoreError = null;
    _loadingMore = false;
    _future = _loadPage(1);
  }

  void _reload() {
    setState(_resetPaging);
  }

  Future<void> _loadMore(B2cCatalogPage<B2cCategory> firstPage) async {
    if (_loadingMore) return;
    final basePage = _lastLoadedPage ?? firstPage;
    if (!basePage.hasNextPage) return;
    final generation = _generation;

    setState(() {
      _loadingMore = true;
      _loadMoreError = null;
    });

    try {
      final nextPage = await _loadPage(basePage.currentPage + 1);
      if (!mounted || generation != _generation) return;

      final seen = <int>{
        ...firstPage.items.map((category) => category.id),
        ..._additional.map((category) => category.id),
      };
      setState(() {
        _additional.addAll(
          nextPage.items.where((category) => seen.add(category.id)),
        );
        _lastLoadedPage = nextPage;
        _loadingMore = false;
      });
    } catch (error) {
      if (!mounted || generation != _generation) return;
      setState(() {
        _loadMoreError = error;
        _loadingMore = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        key: const ValueKey('retail-catalog-categories'),
        backgroundColor: CustomerUiColors.mint,
        body: FoodexUnifiedHeaderSurface(
          title: context.tr('customer.home.title'),
          searchHint: context.tr('customer.products.search_name_sku_barcode'),
          cartKey: const ValueKey('retail-categories-cart'),
          onCart: widget.navigation.openCart == null
              ? null
              : () => widget.navigation.openCart!(
                    context,
                    storeId: widget.storeId,
                  ),
          child: FutureBuilder<B2cCatalogPage<B2cCategory>>(
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
                    error: snapshot.error, onRetry: _reload);
              }

              final firstPage = snapshot.data!;
              final categories = <B2cCategory>[
                ...firstPage.items,
                ..._additional
              ];
              if (categories.isEmpty) {
                return CustomerStateView(
                  kind: CustomerStateKind.empty,
                  title: context.tr('customer.empty'),
                  icon: Icons.category_outlined,
                );
              }

              final currentPage = _lastLoadedPage ?? firstPage;
              return Column(
                children: [
                  Expanded(
                    child: LayoutBuilder(
                      builder: (context, constraints) {
                        final columns = constraints.maxWidth >= 720 ? 5 : 3;
                        return GridView.builder(
                          key: const ValueKey('retail-categories-grid'),
                          padding: const EdgeInsets.fromLTRB(
                            CustomerUiSpacing.page,
                            CustomerUiSpacing.lg,
                            CustomerUiSpacing.page,
                            CustomerUiSpacing.md,
                          ),
                          gridDelegate:
                              SliverGridDelegateWithFixedCrossAxisCount(
                            crossAxisCount: columns,
                            crossAxisSpacing: CustomerUiSpacing.sm,
                            mainAxisSpacing: CustomerUiSpacing.md,
                            mainAxisExtent: 128,
                          ),
                          itemCount: categories.length,
                          itemBuilder: (context, index) {
                            final category = categories[index];
                            return Center(
                              child: CustomerCategoryTile(
                                key: ValueKey(
                                    'retail-category-grid-${category.id}'),
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
                    ),
                  ),
                  _CatalogPaginationFooter(
                    keyPrefix: 'retail-categories',
                    hasMore: currentPage.hasNextPage,
                    loading: _loadingMore,
                    error: _loadMoreError,
                    onLoadMore: () => _loadMore(firstPage),
                  ),
                ],
              );
            },
          ),
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
  late Future<B2cCatalogPage<B2cOffer>> _future = _loadPage(1);
  final List<B2cOffer> _additional = <B2cOffer>[];
  B2cCatalogPage<B2cOffer>? _lastLoadedPage;
  Object? _loadMoreError;
  bool _loadingMore = false;
  int _generation = 0;

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

  Future<B2cCatalogPage<B2cOffer>> _loadPage(int page) async {
    final api = widget.catalogApi;
    if (api is B2cPagedCatalogApi) {
      return api.offerPage(widget.storeId, page: page);
    }
    if (page != 1) {
      return B2cCatalogPage<B2cOffer>.single(const <B2cOffer>[]);
    }
    return B2cCatalogPage<B2cOffer>.single(await api.offers(widget.storeId));
  }

  void _resetPaging() {
    _generation++;
    _additional.clear();
    _lastLoadedPage = null;
    _loadMoreError = null;
    _loadingMore = false;
    _future = _loadPage(1);
  }

  void _reload() {
    setState(_resetPaging);
  }

  Future<void> _loadMore(B2cCatalogPage<B2cOffer> firstPage) async {
    if (_loadingMore) return;
    final basePage = _lastLoadedPage ?? firstPage;
    if (!basePage.hasNextPage) return;
    final generation = _generation;

    setState(() {
      _loadingMore = true;
      _loadMoreError = null;
    });

    try {
      final nextPage = await _loadPage(basePage.currentPage + 1);
      if (!mounted || generation != _generation) return;

      final seen = <int>{
        ...firstPage.items.map((offer) => offer.id),
        ..._additional.map((offer) => offer.id),
      };
      setState(() {
        _additional.addAll(nextPage.items.where((offer) => seen.add(offer.id)));
        _lastLoadedPage = nextPage;
        _loadingMore = false;
      });
    } catch (error) {
      if (!mounted || generation != _generation) return;
      setState(() {
        _loadMoreError = error;
        _loadingMore = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        key: const ValueKey('retail-catalog-offers'),
        backgroundColor: CustomerUiColors.mint,
        body: FoodexUnifiedHeaderSurface(
          title: context.tr('customer.home.title'),
          searchHint: context.tr('customer.products.search_name_sku_barcode'),
          cartKey: const ValueKey('retail-offers-cart'),
          onCart: widget.navigation.openCart == null
              ? null
              : () => widget.navigation.openCart!(
                    context,
                    storeId: widget.storeId,
                  ),
          child: FutureBuilder<B2cCatalogPage<B2cOffer>>(
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
                    error: snapshot.error, onRetry: _reload);
              }

              final firstPage = snapshot.data!;
              final offers = <B2cOffer>[...firstPage.items, ..._additional];
              if (offers.isEmpty) {
                return CustomerStateView(
                  kind: CustomerStateKind.empty,
                  title: context.tr('customer.empty'),
                  icon: Icons.local_offer_outlined,
                );
              }

              final currentPage = _lastLoadedPage ?? firstPage;
              return Column(
                children: [
                  Expanded(
                    child: ListView.separated(
                      key: const ValueKey('retail-offers-list'),
                      padding: const EdgeInsets.fromLTRB(
                        CustomerUiSpacing.page,
                        CustomerUiSpacing.lg,
                        CustomerUiSpacing.page,
                        CustomerUiSpacing.md,
                      ),
                      itemCount: offers.length,
                      separatorBuilder: (_, __) =>
                          const SizedBox(height: CustomerUiSpacing.sm),
                      itemBuilder: (context, index) {
                        final offer = offers[index];
                        final value = offer.value;
                        return Material(
                          color: CustomerUiColors.white,
                          borderRadius:
                              BorderRadius.circular(CustomerUiRadii.xl),
                          child: DecoratedBox(
                            decoration: BoxDecoration(
                              borderRadius:
                                  BorderRadius.circular(CustomerUiRadii.xl),
                              border:
                                  Border.all(color: CustomerUiColors.border),
                            ),
                            child: Padding(
                              padding:
                                  const EdgeInsets.all(CustomerUiSpacing.md),
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
                                      crossAxisAlignment:
                                          CrossAxisAlignment.start,
                                      children: [
                                        Text(
                                          offer.name,
                                          style: Theme.of(context)
                                              .textTheme
                                              .titleMedium,
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
                    ),
                  ),
                  _CatalogPaginationFooter(
                    keyPrefix: 'retail-offers',
                    hasMore: currentPage.hasNextPage,
                    loading: _loadingMore,
                    error: _loadMoreError,
                    onLoadMore: () => _loadMore(firstPage),
                  ),
                ],
              );
            },
          ),
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
  static const _searchDelay = Duration(milliseconds: 350);

  late final TextEditingController _search = TextEditingController(
    text: widget.initialQuery ?? '',
  );
  late String? _query = _normalized(widget.initialQuery);
  late int? _categoryId = widget.categoryId;
  _RetailSortOption _sortOption = _RetailSortOption.nameAsc;
  late Future<B2cCatalogPage<B2cProduct>> _future = _loadPage(1);
  late Future<List<B2cCategory>> _categoriesFuture =
      widget.catalogApi.categories(widget.storeId);
  final List<B2cProduct> _additional = <B2cProduct>[];
  B2cCatalogPage<B2cProduct>? _lastLoadedPage;
  Timer? _searchDebounce;
  Object? _loadMoreError;
  bool _typing = false;
  bool _loadingMore = false;
  int _generation = 0;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  Future<B2cCatalogPage<B2cProduct>> _loadPage(int page) async {
    final api = widget.catalogApi;
    if (api is B2cPagedCatalogApi) {
      return api.productPage(
        widget.storeId,
        query: _query,
        categoryId: _categoryId,
        sort: _sortOption.sort,
        direction: _sortOption.direction,
        page: page,
      );
    }
    if (page != 1) {
      return B2cCatalogPage<B2cProduct>.single(const <B2cProduct>[]);
    }
    return B2cCatalogPage<B2cProduct>.single(
      await api.products(
        widget.storeId,
        query: _query,
        categoryId: _categoryId,
        sort: _sortOption.sort,
        direction: _sortOption.direction,
      ),
    );
  }

  void _resetPaging() {
    _generation++;
    _additional.clear();
    _lastLoadedPage = null;
    _loadMoreError = null;
    _loadingMore = false;
    _future = _loadPage(1);
  }

  void _submit(String value) {
    _searchDebounce?.cancel();
    final normalized = _normalized(value);
    setState(() {
      _typing = false;
      _query = normalized;
      _resetPaging();
    });
  }

  void _onSearchChanged(String value) {
    _searchDebounce?.cancel();
    final normalized = _normalized(value);
    if (normalized == _query) {
      if (_typing) setState(() => _typing = false);
      return;
    }

    setState(() => _typing = true);
    _searchDebounce = Timer(_searchDelay, () {
      if (!mounted) return;
      _submit(value);
    });
  }

  void _selectCategory(int? categoryId) {
    if (_categoryId == categoryId) return;
    setState(() {
      _categoryId = categoryId;
      _resetPaging();
    });
  }

  void _selectSort(_RetailSortOption option) {
    if (_sortOption == option) return;
    setState(() {
      _sortOption = option;
      _resetPaging();
    });
  }

  void _reload() {
    setState(() {
      _categoriesFuture = widget.catalogApi.categories(widget.storeId);
      _resetPaging();
    });
  }

  Future<void> _loadMore(B2cCatalogPage<B2cProduct> firstPage) async {
    if (_loadingMore) return;
    final basePage = _lastLoadedPage ?? firstPage;
    if (!basePage.hasNextPage) return;
    final generation = _generation;

    setState(() {
      _loadingMore = true;
      _loadMoreError = null;
    });

    try {
      final nextPage = await _loadPage(basePage.currentPage + 1);
      if (!mounted || generation != _generation) return;

      final seen = <int>{
        ...firstPage.items.map((product) => product.id),
        ..._additional.map((product) => product.id),
      };
      setState(() {
        _additional.addAll(
          nextPage.items.where((product) => seen.add(product.id)),
        );
        _lastLoadedPage = nextPage;
        _loadingMore = false;
      });
    } catch (error) {
      if (!mounted || generation != _generation) return;
      setState(() {
        _loadMoreError = error;
        _loadingMore = false;
      });
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _searchDebounce?.cancel();
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
        body: FoodexUnifiedHeaderSurface(
          title: context.tr('customer.home.title'),
          searchHint: context.tr('customer.products.search_name_sku_barcode'),
          cartKey: const ValueKey('retail-products-cart'),
          onCart: widget.navigation.openCart == null
              ? null
              : () => widget.navigation.openCart!(
                    context,
                    storeId: widget.storeId,
                  ),
          child: Column(
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
                  hintText:
                      context.tr('customer.products.search_name_sku_barcode'),
                  semanticLabel: context.tr('customer.products.search'),
                  onChanged: _onSearchChanged,
                  onSubmitted: _submit,
                ),
              ),
              _BrowseControls(
                categoriesFuture: _categoriesFuture,
                selectedCategoryId: _categoryId,
                sortOption: _sortOption,
                onCategorySelected: _selectCategory,
                onSortSelected: _selectSort,
              ),
              if (_typing)
                Padding(
                  padding: const EdgeInsetsDirectional.fromSTEB(
                    CustomerUiSpacing.page,
                    CustomerUiSpacing.xs,
                    CustomerUiSpacing.page,
                    CustomerUiSpacing.xs,
                  ),
                  child: Align(
                    alignment: AlignmentDirectional.centerStart,
                    child: CustomerBadge(
                      key: const ValueKey('retail-products-typing'),
                      label: context.tr('customer.products.searching'),
                      tone: CustomerBadgeTone.accent,
                    ),
                  ),
                ),
              Expanded(
                child: FutureBuilder<B2cCatalogPage<B2cProduct>>(
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

                    final firstPage = snapshot.data!;
                    final products = <B2cProduct>[
                      ...firstPage.items,
                      ..._additional
                    ];
                    if (products.isEmpty) {
                      return KeyedSubtree(
                        key: const ValueKey('retail-products-no-results'),
                        child: CustomerStateView(
                          kind: CustomerStateKind.empty,
                          title: context.tr('customer.products.empty'),
                          icon: Icons.search_off_rounded,
                        ),
                      );
                    }

                    final currentPage = _lastLoadedPage ?? firstPage;
                    return Column(
                      children: [
                        if (_query != null)
                          _SearchSuggestions(
                            products: products.take(4).toList(growable: false),
                            onSelected: (product) {
                              _search.text = product.name;
                              _search.selection = TextSelection.collapsed(
                                offset: _search.text.length,
                              );
                              _submit(product.name);
                            },
                          ),
                        Expanded(
                          child: _BrowseProductGrid(
                            products: products,
                            storeId: widget.storeId,
                            navigation: widget.navigation,
                            onAddToCart: widget.onAddToCart,
                          ),
                        ),
                        _CatalogPaginationFooter(
                          keyPrefix: 'retail-products',
                          hasMore: currentPage.hasNextPage,
                          loading: _loadingMore,
                          error: _loadMoreError,
                          onLoadMore: () => _loadMore(firstPage),
                        ),
                      ],
                    );
                  },
                ),
              ),
            ],
          ),
        ),
      );

  static String? _normalized(String? value) {
    final normalized = value?.trim();
    return normalized == null || normalized.isEmpty ? null : normalized;
  }
}

class _CatalogPaginationFooter extends StatelessWidget {
  const _CatalogPaginationFooter({
    required this.keyPrefix,
    required this.hasMore,
    required this.loading,
    required this.error,
    required this.onLoadMore,
  });

  final String keyPrefix;
  final bool hasMore;
  final bool loading;
  final Object? error;
  final VoidCallback onLoadMore;

  @override
  Widget build(BuildContext context) {
    Widget child;
    if (loading) {
      child = Semantics(
        liveRegion: true,
        label: context.tr('customer.catalog.loading_more'),
        child: const SizedBox.square(
          dimension: 28,
          child: CircularProgressIndicator(strokeWidth: 2.5),
        ),
      );
    } else if (error != null) {
      child = OutlinedButton.icon(
        key: ValueKey('$keyPrefix-load-more-retry'),
        onPressed: onLoadMore,
        icon: const Icon(Icons.refresh_rounded),
        label: Text(context.tr('customer.action.retry')),
      );
    } else if (hasMore) {
      child = FilledButton.tonalIcon(
        key: ValueKey('$keyPrefix-load-more'),
        onPressed: onLoadMore,
        icon: const Icon(Icons.expand_more_rounded),
        label: Text(context.tr('customer.catalog.load_more')),
      );
    } else {
      child = Text(
        context.tr('customer.catalog.end'),
        key: ValueKey('$keyPrefix-end'),
        textAlign: TextAlign.center,
        style: Theme.of(context)
            .textTheme
            .bodySmall
            ?.copyWith(color: CustomerUiColors.muted),
      );
    }

    return SafeArea(
      top: false,
      minimum: const EdgeInsets.fromLTRB(
        CustomerUiSpacing.page,
        CustomerUiSpacing.xs,
        CustomerUiSpacing.page,
        CustomerUiSpacing.md,
      ),
      child: Center(child: child),
    );
  }
}

enum _RetailSortOption {
  nameAsc('name', 'asc'),
  nameDesc('name', 'desc'),
  priceAsc('price', 'asc'),
  priceDesc('price', 'desc');

  const _RetailSortOption(this.sort, this.direction);

  final String sort;
  final String direction;

  String label(BuildContext context) {
    final fieldKey = sort == 'price'
        ? 'customer.products.sort_price'
        : 'customer.products.sort_name';
    final directionKey = direction == 'desc'
        ? 'customer.products.descending'
        : 'customer.products.ascending';
    return '${context.tr(fieldKey)} · ${context.tr(directionKey)}';
  }
}

class _BrowseControls extends StatelessWidget {
  const _BrowseControls({
    required this.categoriesFuture,
    required this.selectedCategoryId,
    required this.sortOption,
    required this.onCategorySelected,
    required this.onSortSelected,
  });

  final Future<List<B2cCategory>> categoriesFuture;
  final int? selectedCategoryId;
  final _RetailSortOption sortOption;
  final ValueChanged<int?> onCategorySelected;
  final ValueChanged<_RetailSortOption> onSortSelected;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsetsDirectional.fromSTEB(
          CustomerUiSpacing.page,
          CustomerUiSpacing.xs,
          CustomerUiSpacing.page,
          CustomerUiSpacing.xs,
        ),
        child: Row(
          children: [
            Expanded(
              child: SizedBox(
                height: 44,
                child: FutureBuilder<List<B2cCategory>>(
                  future: categoriesFuture,
                  builder: (context, snapshot) {
                    final categories = snapshot.data ?? const <B2cCategory>[];
                    return ListView.separated(
                      key: const ValueKey('retail-products-category-filter'),
                      scrollDirection: Axis.horizontal,
                      itemCount: categories.length + 1,
                      separatorBuilder: (_, __) =>
                          const SizedBox(width: CustomerUiSpacing.xs),
                      itemBuilder: (context, index) {
                        final category =
                            index == 0 ? null : categories[index - 1];
                        final id = category?.id;
                        return ConstrainedBox(
                          constraints: const BoxConstraints(minHeight: 44),
                          child: ChoiceChip(
                            key: ValueKey(
                              id == null
                                  ? 'retail-products-category-all'
                                  : 'retail-products-category-$id',
                            ),
                            selected: selectedCategoryId == id,
                            label: Text(
                              category?.name ??
                                  context
                                      .tr('customer.products.all_categories'),
                            ),
                            onSelected: (_) => onCategorySelected(id),
                          ),
                        );
                      },
                    );
                  },
                ),
              ),
            ),
            const SizedBox(width: CustomerUiSpacing.xs),
            PopupMenuButton<_RetailSortOption>(
              key: const ValueKey('retail-products-sort'),
              tooltip:
                  '${context.tr('customer.products.sort')}: ${sortOption.label(context)}',
              initialValue: sortOption,
              onSelected: onSortSelected,
              itemBuilder: (context) => [
                for (final option in _RetailSortOption.values)
                  PopupMenuItem<_RetailSortOption>(
                    value: option,
                    child: Text(option.label(context)),
                  ),
              ],
              icon: const Icon(Icons.swap_vert_rounded),
            ),
          ],
        ),
      );
}

class _SearchSuggestions extends StatelessWidget {
  const _SearchSuggestions({required this.products, required this.onSelected});

  final List<B2cProduct> products;
  final ValueChanged<B2cProduct> onSelected;

  @override
  Widget build(BuildContext context) => SizedBox(
        key: const ValueKey('retail-products-suggestions'),
        height: 58,
        child: Row(
          children: [
            Padding(
              padding: const EdgeInsetsDirectional.only(
                start: CustomerUiSpacing.page,
                end: CustomerUiSpacing.xs,
              ),
              child: Text(
                context.tr('customer.products.suggestions'),
                style: Theme.of(context).textTheme.labelLarge,
              ),
            ),
            Expanded(
              child: ListView.separated(
                scrollDirection: Axis.horizontal,
                padding: const EdgeInsetsDirectional.only(
                  end: CustomerUiSpacing.page,
                ),
                itemCount: products.length,
                separatorBuilder: (_, __) =>
                    const SizedBox(width: CustomerUiSpacing.xs),
                itemBuilder: (context, index) {
                  final product = products[index];
                  return Center(
                    child: ActionChip(
                      key: ValueKey('retail-search-suggestion-${product.id}'),
                      label: Text(
                        product.name,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                      onPressed: () => onSelected(product),
                    ),
                  );
                },
              ),
            ),
          ],
        ),
      );
}

class _BrowseProductGrid extends StatelessWidget {
  const _BrowseProductGrid({
    required this.products,
    required this.storeId,
    required this.navigation,
    required this.onAddToCart,
  });

  final List<B2cProduct> products;
  final int storeId;
  final RetailCatalogNavigation navigation;
  final RetailAddToCart? onAddToCart;

  @override
  Widget build(BuildContext context) => LayoutBuilder(
        builder: (context, constraints) {
          final cardExtent = _threeColumnProductExtent(
            maxWidth: constraints.maxWidth,
            horizontalPadding: CustomerUiSpacing.page,
            textScale: MediaQuery.textScalerOf(context).scale(1),
          );

          return GridView.builder(
            key: const ValueKey('retail-products-grid'),
            padding: const EdgeInsets.fromLTRB(
              CustomerUiSpacing.page,
              CustomerUiSpacing.xs,
              CustomerUiSpacing.page,
              CustomerUiSpacing.xxl,
            ),
            gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
              crossAxisCount: 3,
              crossAxisSpacing: CustomerUiSpacing.xs,
              mainAxisSpacing: CustomerUiSpacing.sm,
              mainAxisExtent: cardExtent,
            ),
            itemCount: products.length,
            itemBuilder: (context, index) => _BrowseProductCard(
              storeId: storeId,
              product: products[index],
              navigation: navigation,
              onAddToCart: onAddToCart,
            ),
          );
        },
      );
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
  late Future<_RetailProductDetailData> _future = _load();
  double _quantity = 1;
  bool _submitting = false;
  int _selectedImageIndex = 0;

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
      _selectedImageIndex = 0;
      _future = _load();
    });
  }

  Future<_RetailProductDetailData> _load() async {
    final product = await widget.catalogApi.product(
      widget.productId,
      storeId: widget.storeId,
    );

    var related = const <B2cProduct>[];
    if (product.categoryId != null) {
      try {
        final candidates = await widget.catalogApi.products(
          widget.storeId,
          categoryId: product.categoryId,
          sort: 'name',
          direction: 'asc',
        );
        related = candidates
            .where((candidate) => candidate.id != product.id)
            .take(6)
            .toList(growable: false);
      } catch (_) {
        related = const <B2cProduct>[];
      }
    }

    return _RetailProductDetailData(product: product, related: related);
  }

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
        body: FoodexUnifiedHeaderSurface(
          title: context.tr('customer.home.title'),
          searchHint: context.tr('customer.products.search_name_sku_barcode'),
          cartKey: const ValueKey('retail-product-cart'),
          onCart: widget.navigation.openCart == null
              ? null
              : () => widget.navigation.openCart!(
                    context,
                    storeId: widget.storeId,
                  ),
          child: FutureBuilder<_RetailProductDetailData>(
            future: _future,
            builder: (context, snapshot) {
              if (snapshot.connectionState != ConnectionState.done) {
                return const _ProductDetailSkeleton();
              }
              if (snapshot.hasError || !snapshot.hasData) {
                return _CatalogErrorState(
                    error: snapshot.error, onRetry: _reload);
              }

              final data = snapshot.data!;
              final product = data.product;
              final gallery = product.images
                  .where((value) => value.trim().isNotEmpty)
                  .toList(growable: true);
              if (gallery.isEmpty &&
                  product.imageUrl?.trim().isNotEmpty == true) {
                gallery.add(product.imageUrl!);
              }
              final selectedIndex =
                  gallery.isEmpty || _selectedImageIndex >= gallery.length
                      ? 0
                      : _selectedImageIndex;
              final selectedImage =
                  gallery.isEmpty ? null : gallery[selectedIndex];

              return RefreshIndicator(
                onRefresh: () async {
                  _reload();
                  await _future;
                },
                child: ListView(
                  key: const ValueKey('retail-product-scroll'),
                  padding: const EdgeInsets.fromLTRB(
                    CustomerUiSpacing.page,
                    CustomerUiSpacing.md,
                    CustomerUiSpacing.page,
                    CustomerUiSpacing.xxl,
                  ),
                  children: [
                    Semantics(
                      label: context.tr('customer.product.gallery'),
                      child: Opacity(
                        opacity: product.isAvailable ? 1 : 0.42,
                        child: CustomerProductImage(
                          key: const ValueKey('retail-product-gallery-main'),
                          imageUrl: selectedImage,
                          aspectRatio: 1.15,
                        ),
                      ),
                    ),
                    if (gallery.length > 1) ...[
                      const SizedBox(height: CustomerUiSpacing.sm),
                      SizedBox(
                        key:
                            const ValueKey('retail-product-gallery-thumbnails'),
                        height: 68,
                        child: ListView.separated(
                          scrollDirection: Axis.horizontal,
                          itemCount: gallery.length,
                          separatorBuilder: (_, __) =>
                              const SizedBox(width: CustomerUiSpacing.xs),
                          itemBuilder: (context, index) => InkWell(
                            key: ValueKey('retail-product-image-$index'),
                            onTap: () =>
                                setState(() => _selectedImageIndex = index),
                            borderRadius:
                                BorderRadius.circular(CustomerUiRadii.md),
                            child: DecoratedBox(
                              decoration: BoxDecoration(
                                borderRadius: BorderRadius.circular(
                                  CustomerUiRadii.md,
                                ),
                                border: Border.all(
                                  color: index == selectedIndex
                                      ? CustomerUiColors.deepGreen
                                      : CustomerUiColors.border,
                                  width: index == selectedIndex ? 2 : 1,
                                ),
                              ),
                              child: SizedBox.square(
                                dimension: 68,
                                child: CustomerProductImage(
                                  imageUrl: gallery[index],
                                  aspectRatio: 1,
                                ),
                              ),
                            ),
                          ),
                        ),
                      ),
                    ],
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
                            if (product.brandName?.trim().isNotEmpty ==
                                true) ...[
                              const SizedBox(height: CustomerUiSpacing.xxs),
                              Text(
                                product.brandName!,
                                style: Theme.of(context)
                                    .textTheme
                                    .labelLarge
                                    ?.copyWith(color: CustomerUiColors.muted),
                              ),
                            ],
                            const SizedBox(height: CustomerUiSpacing.xs),
                            Text(
                              product.sku,
                              style: Theme.of(context)
                                  .textTheme
                                  .bodyMedium
                                  ?.copyWith(color: CustomerUiColors.muted),
                            ),
                            if (product.price != null) ...[
                              const SizedBox(height: CustomerUiSpacing.md),
                              Text(
                                foodexMoneyText(
                                    product.price!, product.currency),
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
                              style: Theme.of(context)
                                  .textTheme
                                  .labelLarge
                                  ?.copyWith(
                                    color: product.isAvailable
                                        ? CustomerUiColors.deepGreenStrong
                                        : CustomerUiColors.muted,
                                    fontWeight: FontWeight.w800,
                                  ),
                            ),
                            if (product.availableQuantity != null)
                              Text(
                                foodexQuantity(product.availableQuantity!),
                                key: const ValueKey(
                                  'retail-product-available-quantity',
                                ),
                                style: Theme.of(context)
                                    .textTheme
                                    .bodySmall
                                    ?.copyWith(color: CustomerUiColors.muted),
                              ),
                            if (product.description?.trim().isNotEmpty ==
                                true) ...[
                              const SizedBox(height: CustomerUiSpacing.lg),
                              Text(
                                product.description!,
                                style: Theme.of(context).textTheme.bodyLarge,
                              ),
                            ],
                            if (product.sellingUnits.isNotEmpty) ...[
                              const SizedBox(height: CustomerUiSpacing.xl),
                              Text(
                                context.tr('customer.product.selling_units'),
                                style: Theme.of(context).textTheme.titleMedium,
                              ),
                              const SizedBox(height: CustomerUiSpacing.sm),
                              Wrap(
                                key: const ValueKey(
                                    'retail-product-selling-units'),
                                spacing: CustomerUiSpacing.xs,
                                runSpacing: CustomerUiSpacing.xs,
                                children: [
                                  for (final unit in product.sellingUnits)
                                    _SellingUnitCard(
                                      unit: unit,
                                      currency: product.currency,
                                    ),
                                ],
                              ),
                            ],
                            if (widget.onAddToCart != null) ...[
                              const SizedBox(height: CustomerUiSpacing.xl),
                              DecoratedBox(
                                decoration: BoxDecoration(
                                  color: CustomerUiColors.mint,
                                  borderRadius: BorderRadius.circular(
                                    CustomerUiRadii.lg,
                                  ),
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
                                        key: const ValueKey(
                                            'retail-product-minus'),
                                        onPressed: !product.isAvailable ||
                                                _quantity <= 1
                                            ? null
                                            : () =>
                                                setState(() => _quantity -= 1),
                                        icon: const Icon(Icons.remove_rounded),
                                      ),
                                      ConstrainedBox(
                                        constraints: const BoxConstraints(
                                          minWidth: 40,
                                        ),
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
                                        key: const ValueKey(
                                            'retail-product-plus'),
                                        onPressed: product.isAvailable
                                            ? () =>
                                                setState(() => _quantity += 1)
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
                                  key:
                                      const ValueKey('retail-product-add-cart'),
                                  onPressed: _submitting || !product.isAvailable
                                      ? null
                                      : () => _add(product),
                                  icon: _submitting
                                      ? const SizedBox.square(
                                          dimension: 18,
                                          child: CircularProgressIndicator(
                                            strokeWidth: 2,
                                          ),
                                        )
                                      : const Icon(
                                          Icons.add_shopping_cart_rounded),
                                  label: Text(
                                      context.tr('customer.action.add_cart')),
                                ),
                              ),
                            ],
                          ],
                        ),
                      ),
                    ),
                    if (data.related.isNotEmpty) ...[
                      const SizedBox(height: CustomerUiSpacing.xl),
                      Text(
                        context.tr('customer.product.related'),
                        style: Theme.of(context).textTheme.titleLarge,
                      ),
                      const SizedBox(height: CustomerUiSpacing.sm),
                      _RelatedProductsGrid(
                        products: data.related,
                        storeId: widget.storeId,
                        navigation: widget.navigation,
                        onAddToCart: widget.onAddToCart,
                      ),
                    ],
                  ],
                ),
              );
            },
          ),
        ),
      );
}

class _RetailProductDetailData {
  const _RetailProductDetailData({
    required this.product,
    required this.related,
  });

  final B2cProduct product;
  final List<B2cProduct> related;
}

class _SellingUnitCard extends StatelessWidget {
  const _SellingUnitCard({required this.unit, required this.currency});

  final B2cSellingUnit unit;
  final String currency;

  @override
  Widget build(BuildContext context) {
    final conversion = unit.conversionFactor == 1
        ? null
        : '× ${foodexQuantity(unit.conversionFactor)}';
    final price =
        unit.price == null ? null : foodexMoneyText(unit.price!, currency);

    return DecoratedBox(
      decoration: BoxDecoration(
        color: unit.isBase ? CustomerUiColors.limeSoft : CustomerUiColors.mint,
        borderRadius: BorderRadius.circular(CustomerUiRadii.lg),
        border: Border.all(color: CustomerUiColors.border),
      ),
      child: Padding(
        padding: const EdgeInsets.symmetric(
          horizontal: CustomerUiSpacing.sm,
          vertical: CustomerUiSpacing.xs,
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(unit.name, style: Theme.of(context).textTheme.labelLarge),
            if (conversion != null)
              Text(
                conversion,
                style: Theme.of(context)
                    .textTheme
                    .bodySmall
                    ?.copyWith(color: CustomerUiColors.muted),
              ),
            if (price != null)
              Text(
                price,
                style: Theme.of(context)
                    .textTheme
                    .bodySmall
                    ?.copyWith(color: CustomerUiColors.deepGreenStrong),
              ),
          ],
        ),
      ),
    );
  }
}

class _RelatedProductsGrid extends StatelessWidget {
  const _RelatedProductsGrid({
    required this.products,
    required this.storeId,
    required this.navigation,
    required this.onAddToCart,
  });

  final List<B2cProduct> products;
  final int storeId;
  final RetailCatalogNavigation navigation;
  final RetailAddToCart? onAddToCart;

  @override
  Widget build(BuildContext context) => LayoutBuilder(
        builder: (context, constraints) {
          final cardExtent = _threeColumnProductExtent(
            maxWidth: constraints.maxWidth,
            horizontalPadding: 0,
            textScale: MediaQuery.textScalerOf(context).scale(1),
          );

          return GridView.builder(
            key: const ValueKey('retail-product-related-grid'),
            shrinkWrap: true,
            physics: const NeverScrollableScrollPhysics(),
            gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
              crossAxisCount: 3,
              crossAxisSpacing: CustomerUiSpacing.xs,
              mainAxisSpacing: CustomerUiSpacing.sm,
              mainAxisExtent: cardExtent,
            ),
            itemCount: products.length,
            itemBuilder: (context, index) => _BrowseProductCard(
              storeId: storeId,
              product: products[index],
              navigation: navigation,
              onAddToCart: onAddToCart,
            ),
          );
        },
      );
}

double _threeColumnProductExtent({
  required double maxWidth,
  required double horizontalPadding,
  required double textScale,
}) {
  const columns = 3;
  const skeletonBarsHeight = 78.0;
  const cardVerticalSafety = 12.0;
  final gridWidth = maxWidth -
      (horizontalPadding * 2) -
      (CustomerUiSpacing.xs * (columns - 1));
  final cellWidth = gridWidth / columns;
  final skeletonImageWidth = (cellWidth - (CustomerUiSpacing.sm * 2)).clamp(
    0.0,
    double.infinity,
  );
  final skeletonExtent = (skeletonImageWidth / 1.35) +
      (CustomerUiSpacing.sm * 2) +
      CustomerUiSpacing.sm +
      (CustomerUiSpacing.xs * 2) +
      CustomerUiSpacing.md +
      skeletonBarsHeight;
  final scaledDelta = (textScale - 1).clamp(0.0, 1.0).toDouble();
  final textSafeExtent = 258.0 + (scaledDelta * 72.0);
  final minimumExtent =
      skeletonExtent > textSafeExtent ? skeletonExtent : textSafeExtent;
  return (minimumExtent + cardVerticalSafety) * .85;
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
        : foodexMoneyText(product.price!, product.currency);

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
          : () => openProduct(context, storeId: storeId, productId: product.id),
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
          final cardExtent = _threeColumnProductExtent(
            maxWidth: constraints.maxWidth,
            horizontalPadding: CustomerUiSpacing.page,
            textScale: MediaQuery.textScalerOf(context).scale(1),
          );
          return GridView.builder(
            key: const ValueKey('retail-products-loading'),
            padding: const EdgeInsets.fromLTRB(
              CustomerUiSpacing.page,
              CustomerUiSpacing.xs,
              CustomerUiSpacing.page,
              CustomerUiSpacing.xxl,
            ),
            gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
              crossAxisCount: 3,
              crossAxisSpacing: CustomerUiSpacing.xs,
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
          const CustomerSkeletonBox(height: 280, radius: CustomerUiRadii.lg),
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
              Text(category.name, maxLines: 1, overflow: TextOverflow.ellipsis),
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
            : () =>
                openProduct(context, storeId: storeId, productId: product.id),
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
                      Text(foodexMoneyText(product.price!, product.currency)),
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
  Widget build(BuildContext context) =>
      Text(title, style: Theme.of(context).textTheme.titleLarge);
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
  const _CatalogErrorState({required this.onRetry, this.error});

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

    final isOffline = catalogError is B2cCatalogException &&
        catalogError.isNetworkUnavailable;
    return KeyedSubtree(
      key: ValueKey(
        isOffline ? 'retail-catalog-offline' : 'retail-catalog-error',
      ),
      child: CustomerStateView(
        kind: CustomerStateKind.error,
        title: context.tr(
          isOffline ? 'customer.error.offline' : 'customer.error.action_failed',
        ),
        actionLabel: context.tr('customer.action.retry'),
        onAction: onRetry,
        icon: isOffline ? Icons.wifi_off_rounded : Icons.error_outline_rounded,
      ),
    );
  }
}
