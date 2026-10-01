import 'package:flutter/material.dart';

import '../../../core/api/b2c_catalog_api.dart';
import '../../../core/localization/app_translations.dart';

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

class _RetailCatalogHomeScreenState extends State<RetailCatalogHomeScreen> {
  late Future<_RetailCatalogHomeData> _future = _load();
  final TextEditingController _search = TextEditingController();

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
    _search.dispose();
    super.dispose();
  }

  void _reload() => setState(() => _future = _load());

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
            return _CatalogErrorState(onRetry: _reload);
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
                if (navigation.openProducts != null)
                  const SizedBox(height: 20),
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
    extends State<RetailCatalogProductsScreen> {
  late final TextEditingController _search =
      TextEditingController(text: widget.initialQuery ?? '');
  late String? _query = _normalized(widget.initialQuery);
  late Future<List<B2cProduct>> _future = _load();

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

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        key: const ValueKey('retail-catalog-products'),
        appBar: AppBar(
          title: Text(context.tr('customer.products.title')),
          actions: [
            if (widget.navigation.openCart != null)
              IconButton(
                key: const ValueKey('retail-products-cart'),
                onPressed: () => widget.navigation.openCart!(
                  context,
                  storeId: widget.storeId,
                ),
                icon: const Icon(Icons.shopping_cart_outlined),
              ),
          ],
        ),
        body: Column(
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
              child: TextField(
                key: const ValueKey('retail-products-search'),
                controller: _search,
                textInputAction: TextInputAction.search,
                onSubmitted: _submit,
                decoration: InputDecoration(
                  hintText: context.tr('customer.products.search'),
                  prefixIcon: const Icon(Icons.search_rounded),
                  border: const OutlineInputBorder(),
                ),
              ),
            ),
            Expanded(
              child: FutureBuilder<List<B2cProduct>>(
                future: _future,
                builder: (context, snapshot) {
                  if (snapshot.connectionState != ConnectionState.done) {
                    return const Center(child: CircularProgressIndicator());
                  }
                  if (snapshot.hasError || !snapshot.hasData) {
                    return _CatalogErrorState(
                      onRetry: () => setState(() => _future = _load()),
                    );
                  }
                  final products = snapshot.data!;
                  if (products.isEmpty) {
                    return Center(
                      child: _EmptyMessage(
                        context.tr('customer.products.empty'),
                      ),
                    );
                  }
                  return ListView.separated(
                    padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
                    itemCount: products.length,
                    separatorBuilder: (_, __) => const SizedBox(height: 10),
                    itemBuilder: (context, index) => _ProductTile(
                      storeId: widget.storeId,
                      product: products[index],
                      navigation: widget.navigation,
                      onAddToCart: widget.onAddToCart,
                    ),
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

class _RetailCatalogProductScreenState
    extends State<RetailCatalogProductScreen> {
  late Future<B2cProduct> _future = _load();
  double _quantity = 1;
  bool _submitting = false;

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
        appBar: AppBar(
          title: Text(context.tr('customer.product.title')),
          actions: [
            if (widget.navigation.openCart != null)
              IconButton(
                key: const ValueKey('retail-product-cart'),
                onPressed: () => widget.navigation.openCart!(
                  context,
                  storeId: widget.storeId,
                ),
                icon: const Icon(Icons.shopping_cart_outlined),
              ),
          ],
        ),
        body: FutureBuilder<B2cProduct>(
          future: _future,
          builder: (context, snapshot) {
            if (snapshot.connectionState != ConnectionState.done) {
              return const Center(child: CircularProgressIndicator());
            }
            if (snapshot.hasError || !snapshot.hasData) {
              return _CatalogErrorState(
                onRetry: () => setState(() => _future = _load()),
              );
            }
            final product = snapshot.data!;
            final image = product.images.isNotEmpty
                ? product.images.first
                : product.imageUrl;
            return ListView(
              padding: const EdgeInsets.fromLTRB(16, 14, 16, 28),
              children: [
                if (image != null && image.isNotEmpty)
                  AspectRatio(
                    aspectRatio: 1.5,
                    child: ClipRRect(
                      borderRadius: BorderRadius.circular(18),
                      child: Image.network(
                        image,
                        fit: BoxFit.cover,
                        errorBuilder: (_, __, ___) =>
                            const ColoredBox(color: Color(0xFFF1F3F2)),
                      ),
                    ),
                  ),
                const SizedBox(height: 18),
                Text(
                  product.name,
                  key: const ValueKey('retail-product-name'),
                  style: Theme.of(context).textTheme.headlineSmall,
                ),
                const SizedBox(height: 6),
                Text(product.sku),
                if (product.price != null) ...[
                  const SizedBox(height: 12),
                  Text(
                    '${product.price!.toStringAsFixed(3)} ${product.currency}',
                    key: const ValueKey('retail-product-price'),
                    style: Theme.of(context).textTheme.titleLarge,
                  ),
                ],
                if (product.description?.trim().isNotEmpty == true) ...[
                  const SizedBox(height: 18),
                  Text(product.description!),
                ],
                if (widget.onAddToCart != null) ...[
                  const SizedBox(height: 24),
                  Row(
                    children: [
                      IconButton(
                        key: const ValueKey('retail-product-minus'),
                        onPressed: _quantity <= 1
                            ? null
                            : () => setState(() => _quantity -= 1),
                        icon: const Icon(Icons.remove_rounded),
                      ),
                      Text(
                        _quantity.toStringAsFixed(0),
                        key: const ValueKey('retail-product-quantity'),
                      ),
                      IconButton(
                        key: const ValueKey('retail-product-plus'),
                        onPressed: () => setState(() => _quantity += 1),
                        icon: const Icon(Icons.add_rounded),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: FilledButton.icon(
                          key: const ValueKey('retail-product-add-cart'),
                          onPressed: _submitting ? null : () => _add(product),
                          icon: const Icon(Icons.add_shopping_cart_rounded),
                          label: Text(context.tr('customer.action.add_cart')),
                        ),
                      ),
                    ],
                  ),
                ],
              ],
            );
          },
        ),
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
  const _CatalogErrorState({required this.onRetry});

  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) => Center(
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
