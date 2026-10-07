// ignore_for_file: prefer_interpolation_to_compose_strings

import 'package:flutter/material.dart';

import '../../core/api/b2c_account_api.dart';
import '../../core/localization/app_translations.dart';
import '../../shared/customer_ui_v3/customer_ui_v3.dart';
import 'customer_account_data.dart';

class CustomerFavoritesScreen extends StatefulWidget {
  const CustomerFavoritesScreen({
    required this.api,
    required this.favoritesApi,
    required this.retailStoreId,
    this.onOpenProduct,
    super.key,
  });

  final B2cAccountApi api;
  final B2cRetailFavoritesApi favoritesApi;
  final int retailStoreId;
  final ValueChanged<int>? onOpenProduct;

  @override
  State<CustomerFavoritesScreen> createState() => _CustomerFavoritesScreenState();
}

class _CustomerFavoritesScreenState extends State<CustomerFavoritesScreen>
    with WidgetsBindingObserver {
  late Future<Object?> _future;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _future = _load();
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

  @override
  void didUpdateWidget(covariant CustomerFavoritesScreen oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.api != widget.api ||
        oldWidget.favoritesApi != widget.favoritesApi ||
        oldWidget.retailStoreId != widget.retailStoreId) {
      _reload();
    }
  }

  Future<Object?> _load() =>
      widget.favoritesApi.favoritesForStore(widget.retailStoreId);

  void _reload() => setState(() => _future = _load());

  Future<void> _remove(int productId) async {
    try {
      await widget.favoritesApi.removeFavoriteForStore(
        widget.retailStoreId,
        productId,
      );
      if (mounted) _reload();
    } catch (error) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(context.tr(customerAccountErrorKey(error)))),
      );
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        key: const ValueKey('customer-favorites-screen'),
        backgroundColor: CustomerUiColors.mint,
        appBar: AppBar(
          title: Text(context.tr('customer.profile.favorites')),
          backgroundColor: CustomerUiColors.deepGreen,
          foregroundColor: CustomerUiColors.white,
          surfaceTintColor: Colors.transparent,
          elevation: 0,
        ),
        body: RefreshIndicator(
          onRefresh: () async {
            _reload();
            try {
              await _future;
            } catch (_) {}
          },
          child: FutureBuilder<Object?>(
            future: _future,
            builder: (context, snapshot) {
              if (snapshot.connectionState != ConnectionState.done) {
                return const _FavoritesSkeleton();
              }
              if (snapshot.hasError) {
                return _ScrollableState(
                  key: const ValueKey('customer-favorites-error'),
                  child: CustomerStateView(
                    kind: CustomerStateKind.error,
                    title: context.tr(customerAccountErrorKey(snapshot.error)),
                    actionLabel: context.tr('customer.action.retry'),
                    onAction: _reload,
                  ),
                );
              }

              final rows = customerAccountRows(snapshot.data);
              if (rows.isEmpty) {
                return _ScrollableState(
                  key: const ValueKey('customer-favorites-empty'),
                  child: CustomerStateView(
                    kind: CustomerStateKind.empty,
                    title: context.tr('customer.favorites.empty'),
                    icon: Icons.favorite_border_rounded,
                  ),
                );
              }

              return LayoutBuilder(
                builder: (context, constraints) {
                  final columns = _favoriteGridColumns(constraints.maxWidth);
                  final cardExtent = _favoriteGridCardExtent(
                    context,
                    constraints.maxWidth,
                    columns,
                  );
                  return GridView.builder(
                    key: const ValueKey('customer-favorites-list'),
                    physics: const AlwaysScrollableScrollPhysics(),
                    padding: const EdgeInsets.fromLTRB(
                      CustomerUiSpacing.page,
                      CustomerUiSpacing.md,
                      CustomerUiSpacing.page,
                      CustomerUiSpacing.xxl,
                    ),
                    gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
                      crossAxisCount: columns,
                      crossAxisSpacing: CustomerUiSpacing.sm,
                      mainAxisSpacing: CustomerUiSpacing.sm,
                      mainAxisExtent: cardExtent,
                    ),
                    itemCount: rows.length,
                    itemBuilder: (context, index) {
                      final raw = rows[index];
                      final product = raw['product'] is Map
                          ? Map<String, dynamic>.from(raw['product'] as Map)
                          : raw;
                      final id = (product['id'] as num?)?.toInt() ??
                          (raw['product_id'] as num?)?.toInt();
                      final name = product['name']?.toString() ??
                          product['title']?.toString() ??
                          '';
                      final price = product['price'] ?? product['sale_price'];
                      final oldPrice = product['old_price'] ??
                          product['regular_price'] ??
                          product['original_price'];
                      final currency = product['currency']?.toString() ??
                          raw['currency']?.toString();
                      final imageUrl = _imageUrl(product);
                      final categoryLabel = _categoryLabel(product);
                      final discountLabel = _discountLabel(product);

                      final card = CustomerProductCard(
                        key: ValueKey(
                          'customer-favorite-' + (id?.toString() ?? 'unknown'),
                        ),
                        title: name,
                        priceLabel: _priceLabel(price, currency),
                        oldPriceLabel: oldPrice == null
                            ? null
                            : _priceLabel(oldPrice, currency),
                        imageUrl: imageUrl,
                        categoryLabel: categoryLabel,
                        discountLabel: discountLabel,
                        onTap: id == null || widget.onOpenProduct == null
                            ? null
                            : () => widget.onOpenProduct!(id),
                      );

                      if (id == null) return card;
                      return Stack(
                        children: [
                          Positioned.fill(child: card),
                          PositionedDirectional(
                            top: CustomerUiSpacing.lg,
                            end: CustomerUiSpacing.lg,
                            child: CustomerOutlineIconButton(
                              key: ValueKey(
                                'customer-favorite-remove-' + id.toString(),
                              ),
                              icon: Icons.favorite_rounded,
                              selected: true,
                              tooltip: context.tr('customer.addresses.delete'),
                              semanticLabel:
                                  context.tr('customer.profile.favorites'),
                              onPressed: () => _remove(id),
                            ),
                          ),
                        ],
                      );
                    },
                  );
                },
              );
            },
          ),
        ),
      );

  static String _priceLabel(Object? value, String? currency) {
    if (value == null) return '—';
    final amount = value is num ? value.toStringAsFixed(3) : value.toString();
    final code = currency?.trim() ?? '';
    return code.isEmpty ? amount : '$amount $code';
  }

  static String? _imageUrl(Map<String, dynamic> product) {
    final direct = product['image_url']?.toString().trim();
    if (direct != null && direct.isNotEmpty) return direct;
    final images = product['images'];
    if (images is List) {
      for (final value in images) {
        final candidate = value?.toString().trim();
        if (candidate != null && candidate.isNotEmpty) return candidate;
      }
    }
    return null;
  }

  static String? _categoryLabel(Map<String, dynamic> product) {
    final direct = product['category_name']?.toString().trim();
    if (direct != null && direct.isNotEmpty) return direct;
    final category = product['category'];
    if (category is Map) {
      final nested = category['name']?.toString().trim();
      if (nested != null && nested.isNotEmpty) return nested;
    }
    return null;
  }

  static String? _discountLabel(Map<String, dynamic> product) {
    final direct = product['discount_label']?.toString().trim();
    if (direct != null && direct.isNotEmpty) return direct;
    final percentage = product['discount_percentage'];
    if (percentage is num && percentage > 0) {
      return '-${percentage.toStringAsFixed(0)}%';
    }
    return null;
  }
}

class _FavoritesSkeleton extends StatelessWidget {
  const _FavoritesSkeleton();

  @override
  Widget build(BuildContext context) => LayoutBuilder(
        builder: (context, constraints) {
          final columns = _favoriteGridColumns(constraints.maxWidth);
          final cardExtent = _favoriteGridCardExtent(
            context,
            constraints.maxWidth,
            columns,
          );
          return GridView.builder(
            key: const ValueKey('customer-favorites-loading'),
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.fromLTRB(
              CustomerUiSpacing.page,
              CustomerUiSpacing.md,
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

int _favoriteGridColumns(double maxWidth) => maxWidth >= 720 ? 3 : 2;

double _favoriteGridCardExtent(
  BuildContext context,
  double maxWidth,
  int columns,
) {
  const horizontalPadding = CustomerUiSpacing.page * 2;
  final gaps = CustomerUiSpacing.sm * (columns - 1);
  final usableWidth = (maxWidth - horizontalPadding - gaps)
      .clamp(0.0, double.infinity)
      .toDouble();
  final cellWidth = usableWidth / columns;
  final imageWidth = (cellWidth - (CustomerUiSpacing.sm * 2))
      .clamp(0.0, double.infinity)
      .toDouble();
  final imageHeight = imageWidth / 1.35;
  final textScale = MediaQuery.textScalerOf(context).scale(1);
  final scaledTextAllowance =
      210 + (textScale > 1 ? (textScale - 1) * 80 : 0);
  final extent = imageHeight + scaledTextAllowance;
  return extent < 330 ? 330.0 : extent;
}

class _ScrollableState extends StatelessWidget {
  const _ScrollableState({
    required this.child,
    super.key,
  });

  final Widget child;

  @override
  Widget build(BuildContext context) => ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.all(CustomerUiSpacing.xl),
        children: [
          const SizedBox(height: 72),
          child,
        ],
      );
}
