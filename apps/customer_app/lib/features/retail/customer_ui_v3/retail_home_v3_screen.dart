import 'dart:async';
import '../../storefront/storefront_design_system.dart';

import 'package:flutter/material.dart';

import '../../../core/api/b2c_account_api.dart';
import '../../../core/api/b2c_catalog_api.dart';
import '../../../core/api/storefront_api.dart';
import '../../../core/localization/app_translations.dart';
import '../../../core/routing/customer_routes.dart';
import '../../../shared/customer_ui_v3/customer_ui_v3.dart';
import '../../customer_account/customer_account_data.dart';
import '../catalog/retail_catalog_screens.dart';

class RetailHomeV3Screen extends StatefulWidget {
  const RetailHomeV3Screen({
    required this.storeId,
    required this.catalogApi,
    required this.isAuthenticated,
    this.accountApi,
    this.storefrontApi,
    this.navigation = const RetailCatalogNavigation(),
    this.onAddToCart,
    super.key,
  }) : assert(storeId > 0);

  final int storeId;
  final B2cCatalogApi catalogApi;
  final B2cAccountApi? accountApi;
  final StorefrontApi? storefrontApi;
  final bool isAuthenticated;
  final RetailCatalogNavigation navigation;
  final RetailAddToCart? onAddToCart;

  @override
  State<RetailHomeV3Screen> createState() => _RetailHomeV3ScreenState();
}

class _RetailHomeV3ScreenState extends State<RetailHomeV3Screen>
    with WidgetsBindingObserver {
  late Future<_RetailHomeV3Data> _future;
  late final StorefrontApi? _storefrontApi;
  final TextEditingController _search = TextEditingController();

  @override
  void initState() {
    super.initState();
    final catalogApi = widget.catalogApi;
    _storefrontApi = widget.storefrontApi ??
        (catalogApi is HttpB2cCatalogApi
            ? HttpStorefrontApi(
                baseUrl: catalogApi.baseUrl,
              )
            : null);
    _future = _load();
    WidgetsBinding.instance.addObserver(this);
  }

  Future<_RetailHomeV3Data> _load() async {
    final values = await Future.wait<Object?>([
      widget.catalogApi.categories(widget.storeId),
      widget.catalogApi.products(widget.storeId),
      widget.catalogApi.banners(widget.storeId),
      if (_storefrontApi != null) _storefrontApi.retailHome(widget.storeId),
    ]);

    var profile = const <String, dynamic>{};
    var addresses = const <Map<String, dynamic>>[];
    final accountApi = widget.accountApi;
    if (widget.isAuthenticated && accountApi != null) {
      try {
        profile = customerAccountMap(await accountApi.profile());
      } catch (_) {
        profile = const <String, dynamic>{};
      }
      try {
        addresses = customerAccountRows(await accountApi.addresses());
      } catch (_) {
        addresses = const <Map<String, dynamic>>[];
      }
    }

    return _RetailHomeV3Data(
      categories: values[0] as List<B2cCategory>,
      products: values[1] as List<B2cProduct>,
      banners: values[2] as List<B2cBanner>,
      storefront: values.length > 3 && values[3] is Map
          ? Map<String, dynamic>.from(values[3] as Map)
          : const <String, dynamic>{},
      profile: profile,
      addresses: addresses,
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

  Future<void> _refresh() async {
    _reload();
    await _future;
  }

  void _submitSearch(String value) {
    final openProducts = widget.navigation.openProducts;
    if (openProducts == null) return;
    final normalized = value.trim();
    openProducts(
      context,
      storeId: widget.storeId,
      query: normalized.isEmpty ? null : normalized,
    );
  }

  Future<void> _addProduct(B2cProduct product) async {
    final add = widget.onAddToCart;
    if (add == null) return;
    try {
      await add(
        storeId: widget.storeId,
        productId: product.id,
        quantity: 1,
      );
    } catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(context.tr('customer.error.action_failed'))),
      );
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        key: const ValueKey('retail-catalog-home'),
        resizeToAvoidBottomInset: true,
        backgroundColor: CustomerUiColors.mint,
        body: FutureBuilder<_RetailHomeV3Data>(
          future: _future,
          builder: (context, snapshot) {
            return FoodexUnifiedHeaderSurface(
              title: context.tr('customer.home.title'),
              palette: FoodexPalette.grocery,
              searchController: _search,
              searchHint: context.tr(
                'customer.products.search_name_sku_barcode',
              ),
              searchKey: const ValueKey('retail-catalog-search'),
              onSearchSubmitted: _submitSearch,
              notificationKey: const ValueKey('retail-catalog-notifications'),
              cartKey: const ValueKey('retail-catalog-cart'),
              onNotifications: widget.navigation.openNotifications == null
                  ? null
                  : () => widget.navigation.openNotifications!(
                        context,
                        storeId: widget.storeId,
                      ),
              onCart: widget.navigation.openCart == null
                  ? null
                  : () => widget.navigation.openCart!(
                        context,
                        storeId: widget.storeId,
                      ),
              child: _body(context, snapshot),
            );
          },
        ),
      );

  Widget _body(
    BuildContext context,
    AsyncSnapshot<_RetailHomeV3Data> snapshot,
  ) {
    if (snapshot.connectionState != ConnectionState.done) {
      return const _RetailHomeSkeleton();
    }

    if (snapshot.hasError || !snapshot.hasData) {
      final error = snapshot.error;
      if (error is B2cCatalogException && error.isSelfStorePurchaseNotAllowed) {
        WidgetsBinding.instance.addPostFrameCallback((_) {
          if (!mounted) return;
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
            actionLabel:
                context.tr('customer.store.own_purchase_blocked.action'),
            onAction: () => Navigator.of(context).pushNamedAndRemoveUntil(
              CustomerRoutePaths.marketplace,
              (route) => false,
            ),
            icon: Icons.storefront_outlined,
          ),
        );
      }

      final isOffline =
          (error is B2cCatalogException && error.isNetworkUnavailable) ||
              (error is StorefrontApiException && error.statusCode == null);
      return CustomerStateView(
        key: ValueKey(isOffline ? 'retail-home-offline' : 'retail-home-error'),
        kind: CustomerStateKind.error,
        title: context.tr(
          isOffline ? 'customer.error.offline' : 'customer.error.action_failed',
        ),
        actionLabel: context.tr('customer.action.retry'),
        onAction: _reload,
        icon: isOffline ? Icons.wifi_off_rounded : null,
      );
    }

    final data = snapshot.data!;
    if (data.categories.isEmpty &&
        data.products.isEmpty &&
        data.banners.isEmpty) {
      return RefreshIndicator(
        onRefresh: _refresh,
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(CustomerUiSpacing.page),
          children: [
            SizedBox(
              height: 360,
              child: CustomerStateView(
                kind: CustomerStateKind.empty,
                title: context.tr('customer.empty'),
              ),
            ),
          ],
        ),
      );
    }

    return RefreshIndicator(
      onRefresh: _refresh,
      child: ListView(
        key: const ValueKey('retail-home-scroll'),
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsetsDirectional.fromSTEB(
          CustomerUiSpacing.page,
          CustomerUiSpacing.md,
          CustomerUiSpacing.page,
          CustomerUiSpacing.section,
        ),
        children: [
          if (data.banners.isNotEmpty) ...[
            _HomeHeroBanner(banner: data.banners.first),
            const SizedBox(height: CustomerUiSpacing.xl),
          ],
          _HomeSectionTitle(context.tr('customer.home.categories')),
          const SizedBox(height: CustomerUiSpacing.sm),
          if (data.categories.isEmpty)
            CustomerStateView(
              kind: CustomerStateKind.empty,
              title: context.tr('customer.empty'),
            )
          else
            SizedBox(
              height: 144,
              child: ListView.separated(
                scrollDirection: Axis.horizontal,
                itemCount: data.categories.length,
                separatorBuilder: (_, __) =>
                    const SizedBox(width: CustomerUiSpacing.xs),
                itemBuilder: (context, index) {
                  final category = data.categories[index];
                  return CustomerCategoryTile(
                    key: ValueKey('retail-category-${category.id}'),
                    label: category.name,
                    imageUrl: category.imageUrl,
                    onTap: widget.navigation.openProducts == null
                        ? null
                        : () => widget.navigation.openProducts!(
                              context,
                              storeId: widget.storeId,
                              categoryId: category.id,
                            ),
                  );
                },
              ),
            ),
          const SizedBox(height: CustomerUiSpacing.xl),
          _RetailBestSellerTickerHeader(
            title: context.tr('customer.home.best_sellers'),
            products: data.bestSellers,
            onTap: widget.navigation.openProduct == null
                ? null
                : (product) => widget.navigation.openProduct!(
                      context,
                      storeId: widget.storeId,
                      productId: product.id,
                    ),
          ),
          const SizedBox(height: CustomerUiSpacing.sm),
          if (data.bestSellers.isEmpty)
            CustomerStateView(
              kind: CustomerStateKind.empty,
              title: context.tr('customer.products.empty'),
            )
          else
            _productGrid(
              context,
              products: data.bestSellers,
              categories: data.categories,
              gridKey: const ValueKey('retail-best-sellers-grid'),
              keyPrefix: 'retail-best-seller',
            ),
          const SizedBox(height: CustomerUiSpacing.xl),
          _HomeSectionTitle(context.tr('customer.home.all_products')),
          const SizedBox(height: CustomerUiSpacing.sm),
          if (data.products.isEmpty)
            CustomerStateView(
              kind: CustomerStateKind.empty,
              title: context.tr('customer.products.empty'),
            )
          else ...[
            _productGrid(
              context,
              products: data.allProductsPreview,
              categories: data.categories,
              gridKey: const ValueKey('retail-all-products-grid'),
              keyPrefix: 'retail-product',
            ),
            if (widget.navigation.openProducts != null) ...[
              const SizedBox(height: CustomerUiSpacing.sm),
              Align(
                alignment: AlignmentDirectional.centerEnd,
                child: TextButton.icon(
                  key: const ValueKey('retail-home-view-more'),
                  onPressed: () => widget.navigation.openProducts!(
                    context,
                    storeId: widget.storeId,
                  ),
                  icon: const Icon(Icons.arrow_forward_rounded),
                  label: Text(context.tr('customer.home.view_more')),
                ),
              ),
            ],
          ],
        ],
      ),
    );
  }

  Widget _productGrid(
    BuildContext context, {
    required List<B2cProduct> products,
    required List<B2cCategory> categories,
    required Key gridKey,
    required String keyPrefix,
  }) =>
      LayoutBuilder(
        builder: (context, constraints) {
          const columns = 3;
          const rowGap = 0.0;
          final gridWidth = constraints.maxWidth -
              (CustomerUiSpacing.xs * (columns - 1));
          final cellWidth = gridWidth / columns;
          final imageWidth =
              (cellWidth - (CustomerUiSpacing.xs * 2)).clamp(0.0, double.infinity);
          final imageHeight = imageWidth / 1.75;
          final textScale = MediaQuery.textScalerOf(context).scale(1);
          final scaledDelta = (textScale - 1.0).clamp(0.0, 1.0).toDouble();
          final cardExtent = imageHeight + 80 + (scaledDelta * 24);

          return GridView.builder(
            key: gridKey,
            shrinkWrap: true,
            physics: const NeverScrollableScrollPhysics(),
            itemCount: products.length,
            gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
              crossAxisCount: columns,
              crossAxisSpacing: CustomerUiSpacing.xs,
              mainAxisSpacing: rowGap,
              mainAxisExtent: cardExtent,
            ),
            itemBuilder: (context, index) {
              final product = products[index];
              final price = product.price == null
                  ? product.sku
                  : '${product.price!.toStringAsFixed(0)} ${product.currency}';
              String? categoryLabel;
              final categoryId = product.categoryId;
              if (categoryId != null) {
                for (final category in categories) {
                  if (category.id == categoryId) {
                    categoryLabel = category.name;
                    break;
                  }
                }
              }

              return CustomerProductCard(
                key: ValueKey('$keyPrefix-${product.id}'),
                title: product.name,
                priceLabel: price,
                categoryLabel: categoryLabel,
                imageUrl: product.imageUrl,
                brandLabel: product.brandName,
                brandImageUrl: product.brandImageUrl,
                isAvailable: product.isAvailable,
                unavailableLabel: context.tr('customer.product.out_of_stock'),
                onTap: widget.navigation.openProduct == null
                    ? null
                    : () => widget.navigation.openProduct!(
                          context,
                          storeId: widget.storeId,
                          productId: product.id,
                        ),
                onAdd: !product.isAvailable || widget.onAddToCart == null
                    ? null
                    : () => _addProduct(product),
                addSemanticLabel: context.tr('customer.action.add_cart'),
              );
            },
          );
        },
      );

}

class _HomeHeroBanner extends StatelessWidget {
  const _HomeHeroBanner({required this.banner});

  final B2cBanner banner;

  @override
  Widget build(BuildContext context) => ClipRRect(
        borderRadius: BorderRadius.circular(CustomerUiRadii.xl),
        child: SizedBox(
          height: 123,
          child: Stack(
            fit: StackFit.expand,
            children: [
              if (banner.imageUrl?.trim().isNotEmpty == true)
                Image.network(
                  banner.imageUrl!,
                  fit: BoxFit.cover,
                  errorBuilder: (_, __, ___) => const ColoredBox(
                    color: CustomerUiColors.mintStrong,
                  ),
                )
              else
                const ColoredBox(color: CustomerUiColors.mintStrong),
              DecoratedBox(
                decoration: BoxDecoration(
                  gradient: LinearGradient(
                    begin: AlignmentDirectional.bottomStart,
                    end: AlignmentDirectional.topEnd,
                    colors: [
                      CustomerUiColors.deepGreenStrong.withValues(alpha: 0.80),
                      CustomerUiColors.deepGreen.withValues(alpha: 0.12),
                    ],
                  ),
                ),
              ),
              Align(
                alignment: AlignmentDirectional.bottomStart,
                child: Padding(
                  padding: const EdgeInsets.all(CustomerUiSpacing.lg),
                  child: Text(
                    banner.title,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: Theme.of(context).textTheme.titleLarge?.copyWith(
                          color: CustomerUiColors.white,
                        ),
                  ),
                ),
              ),
            ],
          ),
        ),
      );
}

class _RetailBestSellerTickerHeader extends StatefulWidget {
  const _RetailBestSellerTickerHeader({
    required this.title,
    required this.products,
    required this.onTap,
  });

  final String title;
  final List<B2cProduct> products;
  final ValueChanged<B2cProduct>? onTap;

  @override
  State<_RetailBestSellerTickerHeader> createState() =>
      _RetailBestSellerTickerHeaderState();
}

class _RetailBestSellerTickerHeaderState
    extends State<_RetailBestSellerTickerHeader>
    with SingleTickerProviderStateMixin {
  static const _interval = Duration(seconds: 10);
  static const _tones = <Color>[
    Color(0xFF087347),
    Color(0xFF2E5FA8),
    Color(0xFF8B5A00),
    Color(0xFF7A4EA3),
    Color(0xFFB54708),
  ];

  Timer? _timer;
  AnimationController? _marquee;
  int _index = 0;

  AnimationController get _marqueeController =>
      _marquee ??= AnimationController(vsync: this, duration: _interval);

  @override
  void initState() {
    super.initState();
    _restartTicker();
  }

  @override
  void didUpdateWidget(covariant _RetailBestSellerTickerHeader oldWidget) {
    super.didUpdateWidget(oldWidget);
    final oldIds = oldWidget.products.map((product) => product.id).join(',');
    final newIds = widget.products.map((product) => product.id).join(',');
    if (oldIds != newIds) {
      _index = 0;
      _restartTicker();
    }
  }

  void _restartTicker() {
    _timer?.cancel();
    final marquee = _marqueeController;
    marquee.stop();
    if (widget.products.isEmpty) return;

    marquee.forward(from: 0);
    _timer = Timer.periodic(_interval, (_) {
      if (!mounted || widget.products.isEmpty) return;
      setState(() {
        if (widget.products.length > 1) {
          _index = (_index + 1) % widget.products.length;
        }
      });
      marquee.forward(from: 0);
    });
  }

  Widget _marqueeLink(
    BuildContext context,
    B2cProduct product,
    Color tone,
  ) {
    final isArabic = Localizations.localeOf(context).languageCode == 'ar';
    final style = Theme.of(context).textTheme.labelLarge?.copyWith(
          color: tone,
          fontWeight: FontWeight.w900,
          decoration: TextDecoration.underline,
          decorationColor: tone,
        );
    final painter = TextPainter(
      text: TextSpan(text: product.name, style: style),
      textDirection: isArabic ? TextDirection.rtl : TextDirection.ltr,
      textScaler: MediaQuery.textScalerOf(context),
      maxLines: 1,
    )..layout();
    final textWidth = painter.width + 20;

    return LayoutBuilder(
      builder: (context, constraints) => ClipRect(
        child: SizedBox(
          height: 30,
          child: Stack(
            alignment: Alignment.centerLeft,
            children: [
              AnimatedBuilder(
                animation: _marqueeController,
                child: SizedBox(
                  width: textWidth,
                  height: 30,
                  child: InkWell(
                    key: ValueKey(
                      'retail-best-seller-ticker-product-${product.id}',
                    ),
                    onTap: widget.onTap == null
                        ? null
                        : () => widget.onTap!(product),
                    borderRadius: BorderRadius.circular(999),
                    child: Padding(
                      padding: const EdgeInsets.symmetric(
                        horizontal: 8,
                        vertical: 4,
                      ),
                      child: Text(
                        product.name,
                        key: ValueKey(
                          'retail-best-seller-ticker-label-${product.id}',
                        ),
                        maxLines: 1,
                        softWrap: false,
                        overflow: TextOverflow.visible,
                        style: style,
                      ),
                    ),
                  ),
                ),
                builder: (context, child) {
                  final travel = constraints.maxWidth + textWidth;
                  final dx = isArabic
                      ? constraints.maxWidth -
                          (_marqueeController.value * travel)
                      : -textWidth + (_marqueeController.value * travel);
                  return Transform.translate(
                    offset: Offset(dx, 0),
                    child: child,
                  );
                },
              ),
            ],
          ),
        ),
      ),
    );
  }

  @override
  void reassemble() {
    super.reassemble();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) _restartTicker();
    });
  }

  @override
  void dispose() {
    _timer?.cancel();
    _marquee?.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final products = widget.products;
    final product =
        products.isEmpty ? null : products[_index % products.length];
    final tone = _tones[_index % _tones.length];

    return Row(
      key: const ValueKey('retail-best-seller-ticker-header'),
      children: [
        Flexible(
          child: FoodexAnimatedOxbloodTitle(
            title: widget.title,
            fontSize:
                Theme.of(context).textTheme.titleLarge?.fontSize ?? 22,
          ),
        ),
        if (product != null) ...[
          const SizedBox(width: CustomerUiSpacing.sm),
          Expanded(child: _marqueeLink(context, product, tone)),
        ],
      ],
    );
  }
}

class _HomeSectionTitle extends StatelessWidget {
  const _HomeSectionTitle(this.title);

  final String title;

  @override
  Widget build(BuildContext context) => Text(
        title,
        style: Theme.of(context).textTheme.titleLarge?.copyWith(
              color: CustomerUiColors.deepGreenStrong,
            ),
      );
}

class _RetailHomeSkeleton extends StatelessWidget {
  const _RetailHomeSkeleton();

  @override
  Widget build(BuildContext context) {
    final textScale = MediaQuery.textScalerOf(context).scale(1);
    final scaledDelta = (textScale - 1.0).clamp(0.0, 1.0).toDouble();
    final cardExtent = (368.0 + (scaledDelta * 240.0)) * .85;

    return ListView(
      padding: const EdgeInsets.all(CustomerUiSpacing.page),
      children: [
        const CustomerSkeletonBox(
          height: 123,
          radius: CustomerUiRadii.xl,
        ),
        const SizedBox(height: CustomerUiSpacing.xl),
        const CustomerSkeletonBox(height: 24, width: 130, radius: 12),
        const SizedBox(height: CustomerUiSpacing.sm),
        SizedBox(
          height: 144,
          child: ListView.separated(
            scrollDirection: Axis.horizontal,
            itemCount: 4,
            separatorBuilder: (_, __) =>
                const SizedBox(width: CustomerUiSpacing.xs),
            itemBuilder: (_, __) => const CustomerCategorySkeleton(),
          ),
        ),
        const SizedBox(height: CustomerUiSpacing.xl),
        const CustomerSkeletonBox(height: 24, width: 150, radius: 12),
        const SizedBox(height: CustomerUiSpacing.sm),
        GridView.builder(
          shrinkWrap: true,
          physics: const NeverScrollableScrollPhysics(),
          itemCount: 4,
          gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
            crossAxisCount: 3,
            crossAxisSpacing: CustomerUiSpacing.xs,
            mainAxisSpacing: CustomerUiSpacing.sm,
            mainAxisExtent: cardExtent,
          ),
          itemBuilder: (_, __) => const CustomerProductCardSkeleton(),
        ),
      ],
    );
  }
}

class _RetailHomeV3Data {
  const _RetailHomeV3Data({
    required this.categories,
    required this.products,
    required this.banners,
    required this.storefront,
    required this.profile,
    required this.addresses,
  });

  final List<B2cCategory> categories;
  final List<B2cProduct> products;
  final List<B2cBanner> banners;
  final Map<String, dynamic> storefront;
  final Map<String, dynamic> profile;
  final List<Map<String, dynamic>> addresses;

  List<B2cProduct> get bestSellers {
    final section = _section('best_sellers');
    final config = section?['config'] is Map
        ? Map<String, dynamic>.from(section!['config'] as Map)
        : const <String, dynamic>{};
    final ids = config['product_ids'] is List
        ? (config['product_ids'] as List)
            .map((value) =>
                value is num ? value.toInt() : int.tryParse('$value'))
            .whereType<int>()
            .where((id) => id > 0)
            .toList(growable: false)
        : const <int>[];
    final configuredLimit = config['limit'] is num
        ? (config['limit'] as num).toInt()
        : int.tryParse('${config['limit'] ?? ''}');
    final limit = (configuredLimit ?? 3).clamp(1, 6);

    if (ids.isNotEmpty) {
      final byId = <int, B2cProduct>{
        for (final product in products) product.id: product,
      };
      return ids
          .map((id) => byId[id])
          .whereType<B2cProduct>()
          .take(limit)
          .toList(growable: false);
    }

    // The published storefront section may specify only a limit. Do not invent
    // a client-side sales score: preserve the authoritative catalog order.
    return products.take(limit).toList(growable: false);
  }

  List<B2cProduct> get allProductsPreview =>
      products.take(6).toList(growable: false);

  Map<String, dynamic>? _section(String type) {
    final rows = storefront['sections'];
    if (rows is! List) return null;
    for (final row in rows.whereType<Map>()) {
      if (row['type']?.toString() == type) {
        return Map<String, dynamic>.from(row);
      }
    }
    return null;
  }

  String? get customerName {
    final value = profile['name']?.toString().trim();
    return value == null || value.isEmpty ? null : value;
  }

  String? get defaultAddressLabel {
    if (addresses.isEmpty) return null;
    final address = addresses.firstWhere(
      (item) => item['is_default'] == true,
      orElse: () => addresses.first,
    );
    final values = <String?>[
      address['label']?.toString(),
      address['line1']?.toString(),
      address['area']?.toString(),
      address['city']?.toString(),
    ]
        .map((value) => value?.trim())
        .whereType<String>()
        .where((value) => value.isNotEmpty)
        .toList(growable: false);
    if (values.isEmpty) return null;
    return values.take(2).join(' · ');
  }
}
