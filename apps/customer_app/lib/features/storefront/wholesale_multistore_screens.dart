// ignore_for_file: prefer_interpolation_to_compose_strings, deprecated_member_use

import 'dart:async';
import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../../core/api/b2b_api.dart';
import '../../core/api/b2c_account_api.dart';
import '../../core/api/customer_action_api.dart';
import '../../core/api/storefront_api.dart';
import '../../core/api/wholesale_commerce_api.dart';
import '../../core/auth/customer_session.dart';
import '../../core/diagnostics/customer_diagnostics.dart';
import '../../core/engagement/live_ad_service.dart';
import '../../core/engagement/notification_campaign_popup_service.dart';
import '../../core/localization/app_translations.dart';
import '../../core/routing/customer_commerce_context.dart';
import '../../core/routing/customer_pending_action.dart';
import '../../core/routing/customer_routes.dart';
import '../../shared/customer_favorite_button.dart';
import 'storefront_design_system.dart';

class WholesaleHomeDesignScreen extends StatefulWidget {
  const WholesaleHomeDesignScreen({
    required this.location,
    required this.session,
    required this.api,
    required this.storefrontApi,
    required this.actionApi,
    this.pendingActionStore,
    this.showBottomNavigation = true,
    super.key,
  });

  final String location;
  final CustomerSession session;
  final B2bApi? api;
  final StorefrontApi? storefrontApi;
  final CustomerActionApi actionApi;
  final CustomerPendingActionStore? pendingActionStore;
  final bool showBottomNavigation;

  @override
  State<WholesaleHomeDesignScreen> createState() =>
      _WholesaleHomeDesignScreenState();
}

class _WholesaleHomeDesignScreenState
    extends State<WholesaleHomeDesignScreen> {
  final search = TextEditingController();
  final campaignPopups = CustomerNotificationCampaignPopupService();
  final liveAds = CustomerLiveAdService();
  late final int storeId = wholesaleStoreId(widget.location);
  late Future<Map<String, dynamic>> future = _load();
  bool _liveAdScheduled = false;

  Future<Map<String, dynamic>> _load([String query = '']) async {
    if (storeId <= 0) {
      return const {
        'products': {'data': <Object>[]},
        'storefront': <String, Object?>{},
      };
    }

    Map<String, dynamic> storefront = <String, dynamic>{};
    if (widget.storefrontApi != null) {
      storefront = await widget.storefrontApi!.wholesaleHome(storeId);
    }

    Object? products;
    if (widget.api != null) {
      var endpoint =
          '/api/v1/b2b/products?store_id=' + storeId.toString();
      if (query.trim().isNotEmpty) {
        endpoint += '&q=' + Uri.encodeQueryComponent(query.trim());
      }
      products = await widget.api!.get(endpoint);
    } else {
      var rows = mapRows(storefront['products']);
      final needle = query.trim().toLowerCase();
      if (needle.isNotEmpty) {
        rows = rows
            .where((row) {
              final name = row['name']?.toString().toLowerCase() ?? '';
              final sku = row['sku']?.toString().toLowerCase() ?? '';
              return name.contains(needle) || sku.contains(needle);
            })
            .toList(growable: false);
      }
      products = <String, Object?>{'data': rows};
    }

    return {
      'products': products,
      'storefront': storefront,
    };
  }

  @override
  void dispose() {
    search.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Directionality(
        textDirection: Directionality.of(context),
        child: FutureBuilder<Map<String, dynamic>>(
          future: future,
          builder: (context, snapshot) {
            if (snapshot.connectionState != ConnectionState.done) {
              return const Scaffold(
                body: SafeArea(
                  child: FoodexLoading(
                    key: ValueKey('b2b-loading'),
                  ),
                ),
              );
            }
            if (snapshot.hasError) {
              return Scaffold(
                body: SafeArea(
                  child: FoodexErrorState(
                    key: const ValueKey('b2b-error'),
                    message: 'تعذر تحميل متجر الجملة.',
                    onRetry: () =>
                        setState(() => future = _load(search.text)),
                  ),
                ),
              );
            }

            if (!_liveAdScheduled) {
              _liveAdScheduled = true;
              WidgetsBinding.instance.addPostFrameCallback((_) async {
                if (!mounted) return;
                await campaignPopups
                    .showForContext(
                      context,
                      channel: 'b2b',
                      storeId: storeId > 0 ? storeId : null,
                      accessToken: widget.session.accessToken,
                    )
                    .catchError((_) {});
                if (!context.mounted) return;
                await liveAds
                    .showForContext(
                      context,
                      channel: 'b2b',
                      storeId: storeId > 0 ? storeId : null,
                    )
                    .catchError((_) {});
              });
            }

            final payload = snapshot.data ?? const <String, dynamic>{};
            final rows = dataRows(payload['products']);
            final storefront = payload['storefront'] is Map
                ? Map<String, dynamic>.from(
                    payload['storefront'] as Map,
                  )
                : <String, dynamic>{};
            final theme = storefront['theme'] is Map
                ? Map<String, dynamic>.from(storefront['theme'] as Map)
                : <String, dynamic>{};
            final branding = storefront['branding'] is Map
                ? Map<String, dynamic>.from(
                    storefront['branding'] as Map,
                  )
                : <String, dynamic>{};
            final custom = branding['custom'] is Map
                ? Map<String, dynamic>.from(branding['custom'] as Map)
                : <String, dynamic>{};
            final store = storefront['store'] is Map
                ? Map<String, dynamic>.from(storefront['store'] as Map)
                : <String, dynamic>{};
            final hero = storefront['hero'] is Map
                ? Map<String, dynamic>.from(storefront['hero'] as Map)
                : <String, dynamic>{};
            final palette = _wholesalePalette(theme);

            final title =
                custom['brand_title_ar']?.toString().trim().isNotEmpty ==
                        true
                    ? custom['brand_title_ar'].toString()
                    : (store['name']?.toString().trim().isNotEmpty == true
                        ? store['name'].toString()
                        : 'متجر الجملة');
            final heroTitle =
                custom['brand_subtitle_ar']?.toString().trim().isNotEmpty ==
                        true
                    ? custom['brand_subtitle_ar'].toString()
                    : 'أفضل الأسعار لمتاجر التجزئة';
            final heroCta =
                custom['hero_cta_ar']?.toString().trim().isNotEmpty == true
                    ? custom['hero_cta_ar'].toString()
                    : 'تصفح الكتالوج';

            final sections = (storefront['sections'] as List? ??
                    const <Object>[])
                .whereType<Map>()
                .map((row) => Map<String, dynamic>.from(row))
                .toList(growable: false);
            final effectiveSections = sections.isEmpty
                ? <Map<String, dynamic>>[
                    {'type': 'hero', 'sort_order': 10},
                    {
                      'type': 'categories',
                      'title_ar': 'التصنيفات',
                      'sort_order': 20,
                    },
                    {
                      'type': 'offers',
                      'title_ar': 'عروض الجملة',
                      'sort_order': 30,
                    },
                  ]
                : sections;

            final content = <Widget>[];
            for (final section in effectiveSections) {
              final type = section['type']?.toString() ?? '';
              final sectionTitle =
                  section['title_ar']?.toString().trim().isNotEmpty == true
                      ? section['title_ar'].toString()
                      : _defaultWholesaleSectionTitle(type);

              switch (type) {
                case 'hero':
                case 'banner_slider':
                  content.add(
                    _WholesaleHero(
                      title: heroTitle,
                      cta: heroCta,
                      imageUrl: hero['image_url']?.toString(),
                      palette: palette,
                    ),
                  );
                  break;
                case 'categories':
                case 'departments':
                  content.add(
                    FoodexSectionHeader(
                      title: sectionTitle,
                      palette: palette,
                    ),
                  );
                  content.add(
                    _WholesaleCategoryGrid(
                      rows: rows,
                      palette: palette,
                    ),
                  );
                  break;
                case 'offers':
                case 'featured_products':
                case 'best_sellers':
                case 'reorder':
                case 'product_grid':
                case 'product_carousel':
                  content.add(
                    FoodexSectionHeader(
                      title: sectionTitle,
                      palette: palette,
                    ),
                  );
                  content.add(
                    _WholesaleProductGrid(
                      rows: rows,
                      storeId: storeId,
                      sourceLocation: widget.location,
                      session: widget.session,
                      actionApi: widget.actionApi,
                      pendingActionStore: widget.pendingActionStore,
                      palette: palette,
                      maxItems: 4,
                    ),
                  );
                  break;
                case 'brands':
                  content.add(
                    FoodexSectionHeader(
                      title: sectionTitle,
                      palette: palette,
                    ),
                  );
                  content.add(
                    _WholesaleBrandRail(
                      rows: rows,
                      palette: palette,
                    ),
                  );
                  break;
              }
            }

            final productSectionTypes = <String>{
              'offers',
              'featured_products',
              'best_sellers',
              'reorder',
              'product_grid',
              'product_carousel',
            };
            final hasProductSection = effectiveSections.any(
              (section) => productSectionTypes.contains(
                section['type']?.toString() ?? '',
              ),
            );
            if (rows.isNotEmpty && !hasProductSection) {
              content.add(
                FoodexSectionHeader(
                  title: 'المنتجات',
                  palette: palette,
                ),
              );
              content.add(
                _WholesaleProductGrid(
                  rows: rows,
                  storeId: storeId,
                  sourceLocation: widget.location,
                  session: widget.session,
                  actionApi: widget.actionApi,
                  pendingActionStore: widget.pendingActionStore,
                  palette: palette,
                  maxItems: 4,
                ),
              );
            }

            return Scaffold(
              backgroundColor: palette.background,
              body: SafeArea(
                child: ListView(
                  padding: EdgeInsets.zero,
                  children: [
                    _WholesaleHeader(
                      title: title,
                      logoUrl: branding['logo_url']?.toString(),
                      address: branding['address']?.toString(),
                      palette: palette,
                      onCart: () => Navigator.of(context).pushNamed(
                        '/b2b/cart?store=' + storeId.toString(),
                      ),
                    ),
                    Padding(
                      padding: const EdgeInsets.fromLTRB(14, 0, 14, 8),
                      child: TextField(
                        controller: search,
                        textInputAction: TextInputAction.search,
                        onSubmitted: (value) =>
                            setState(() => future = _load(value)),
                        decoration: const InputDecoration(
                          hintText: 'البحث بالاسم أو SKU أو الباركود',
                          prefixIcon: Icon(Icons.search_rounded),
                          suffixIcon:
                              Icon(Icons.qr_code_scanner_rounded),
                        ),
                      ),
                    ),
                    ...content,
                    const SizedBox(height: 22),
                  ],
                ),
              ),
              bottomNavigationBar: widget.showBottomNavigation
                  ? _WholesaleBottomNav(
                      storeId: storeId,
                      palette: palette,
                    )
                  : null,
            );
          },
        ),
      );
}

class _WholesaleHeader extends StatelessWidget {
  const _WholesaleHeader({
    required this.title,
    required this.onCart,
    required this.palette,
    this.logoUrl,
    this.address,
  });

  final String title;
  final String? logoUrl;
  final String? address;
  final VoidCallback onCart;
  final FoodexPalette palette;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.fromLTRB(16, 14, 16, 18),
        color: palette.primaryDark,
        child: Row(
          children: [
            if (logoUrl != null && logoUrl!.trim().isNotEmpty) ...[
              ClipRRect(
                borderRadius: BorderRadius.circular(12),
                child: Image.network(
                  logoUrl!,
                  width: 44,
                  height: 44,
                  fit: BoxFit.cover,
                  errorBuilder: (_, __, ___) =>
                      const SizedBox(width: 44, height: 44),
                ),
              ),
              const SizedBox(width: 9),
            ] else ...[
              Container(
                padding:
                    const EdgeInsets.symmetric(horizontal: 9, vertical: 5),
                decoration: BoxDecoration(
                  color: palette.accent,
                  borderRadius: BorderRadius.circular(999),
                ),
                child: Text(
                  'B2B',
                  style: TextStyle(
                    color: palette.primaryDark,
                    fontSize: 10,
                    fontWeight: FontWeight.w900,
                  ),
                ),
              ),
              const SizedBox(width: 10),
            ],
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    title,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(
                      color: Colors.white,
                      fontSize: 19,
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                  if (address != null && address!.trim().isNotEmpty)
                    Text(
                      address!,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(
                        color: Colors.white.withOpacity(.78),
                        fontSize: 10,
                      ),
                    ),
                ],
              ),
            ),
            const _RoundHeaderIcon(icon: Icons.notifications_none_rounded),
            const SizedBox(width: 8),
            _RoundHeaderIcon(
              icon: Icons.shopping_cart_outlined,
              onTap: onCart,
            ),
          ],
        ),
      );
}

class _RoundHeaderIcon extends StatelessWidget {
  const _RoundHeaderIcon({
    required this.icon,
    this.onTap,
  });

  final IconData icon;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) => Material(
        color: Colors.white.withOpacity(.14),
        shape: const CircleBorder(),
        child: InkWell(
          onTap: onTap,
          customBorder: const CircleBorder(),
          child: SizedBox(
            width: 44,
            height: 44,
            child: Icon(icon, color: Colors.white),
          ),
        ),
      );
}

class _WholesaleHero extends StatelessWidget {
  const _WholesaleHero({
    required this.title,
    required this.cta,
    required this.palette,
    this.imageUrl,
  });

  final String title;
  final String cta;
  final String? imageUrl;
  final FoodexPalette palette;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.fromLTRB(14, 8, 14, 4),
        child: Container(
          height: 170,
          padding: const EdgeInsets.all(20),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(22),
            gradient: LinearGradient(
              colors: [palette.primary, palette.primaryDark],
            ),
          ),
          child: Row(
            children: [
              Expanded(
                child: Column(
                  mainAxisAlignment: MainAxisAlignment.center,
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      title,
                      style: const TextStyle(
                        color: Colors.white,
                        fontSize: 20,
                        height: 1.25,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                    const SizedBox(height: 12),
                    _WhitePill(
                      label: cta,
                      palette: palette,
                    ),
                  ],
                ),
              ),
              if (imageUrl != null && imageUrl!.trim().isNotEmpty)
                ClipRRect(
                  borderRadius: BorderRadius.circular(16),
                  child: Image.network(
                    imageUrl!,
                    width: 100,
                    height: 112,
                    fit: BoxFit.cover,
                    errorBuilder: (_, __, ___) => Icon(
                      Icons.warehouse_rounded,
                      size: 78,
                      color: Colors.white.withOpacity(.45),
                    ),
                  ),
                )
              else
                Icon(
                  Icons.warehouse_rounded,
                  size: 78,
                  color: Colors.white.withOpacity(.45),
                ),
            ],
          ),
        ),
      );
}

class _WhitePill extends StatelessWidget {
  const _WhitePill({
    required this.label,
    required this.palette,
  });

  final String label;
  final FoodexPalette palette;

  @override
  Widget build(BuildContext context) => Align(
        alignment: AlignmentDirectional.centerStart,
        child: Container(
          padding:
              const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
          decoration: BoxDecoration(
            color: palette.accent,
            borderRadius: BorderRadius.circular(999),
          ),
          child: Text(
            label,
            style: TextStyle(
              color: palette.primaryDark,
              fontSize: 11,
              fontWeight: FontWeight.w800,
            ),
          ),
        ),
      );
}

class _WholesaleCategoryGrid extends StatelessWidget {
  const _WholesaleCategoryGrid({
    required this.rows,
    required this.palette,
  });

  final List<Map<String, dynamic>> rows;
  final FoodexPalette palette;

  @override
  Widget build(BuildContext context) {
    final byId = <int, Map<String, dynamic>>{};
    for (final row in rows) {
      final id = intValue(row['category_id']);
      if (id <= 0 || byId.containsKey(id)) continue;
      byId[id] = {
        'id': id,
        'name': row['category_name']?.toString().trim(),
        'image_url': row['category_image_url']?.toString().trim(),
      };
    }
    final categories = byId.values.take(8).toList(growable: false);
    if (categories.isEmpty) return const SizedBox.shrink();

    return SizedBox(
      key: const ValueKey('wholesale-home-category-rail'),
      height: 104,
      child: ListView.separated(
        padding: const EdgeInsets.symmetric(horizontal: 14),
        scrollDirection: Axis.horizontal,
        itemCount: categories.length,
        separatorBuilder: (_, __) => const SizedBox(width: 10),
        itemBuilder: (_, index) {
          final category = categories[index];
          final id = intValue(category['id']);
          final name = category['name']?.toString().trim();
          final imageUrl = category['image_url']?.toString().trim();
          return SizedBox(
            key: ValueKey('wholesale-category-$id'),
            width: 76,
            child: Column(
              children: [
                Container(
                  width: 66,
                  height: 66,
                  clipBehavior: Clip.antiAlias,
                  decoration: BoxDecoration(
                    color: Colors.white,
                    shape: BoxShape.circle,
                    border: Border.all(color: palette.soft),
                    boxShadow: [
                      BoxShadow(
                        color: Colors.black.withOpacity(.04),
                        blurRadius: 8,
                        offset: const Offset(0, 3),
                      ),
                    ],
                  ),
                  child: imageUrl != null && imageUrl.isNotEmpty
                      ? Image.network(
                          imageUrl,
                          fit: BoxFit.cover,
                          errorBuilder: (_, __, ___) => Icon(
                            Icons.category_outlined,
                            color: palette.primary,
                            size: 28,
                          ),
                        )
                      : Icon(
                          Icons.category_outlined,
                          color: palette.primary,
                          size: 28,
                        ),
                ),
                const SizedBox(height: 6),
                Text(
                  name != null && name.isNotEmpty ? name : 'تصنيف $id',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  textAlign: TextAlign.center,
                  style: const TextStyle(
                    fontSize: 10,
                    fontWeight: FontWeight.w700,
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

class _WholesaleBrandRail extends StatelessWidget {
  const _WholesaleBrandRail({
    required this.rows,
    required this.palette,
  });

  final List<Map<String, dynamic>> rows;
  final FoodexPalette palette;

  @override
  Widget build(BuildContext context) {
    final byId = <int, Map<String, dynamic>>{};
    for (final row in rows) {
      final id = intValue(row['brand_id']);
      if (id <= 0 || byId.containsKey(id)) continue;
      byId[id] = {
        'id': id,
        'name': row['brand_name']?.toString().trim(),
        'image_url': row['brand_image_url']?.toString().trim(),
      };
    }
    final brands = byId.values.toList(growable: false);
    if (brands.isEmpty) return const SizedBox.shrink();

    return SizedBox(
      key: const ValueKey('wholesale-brand-rail'),
      height: 96,
      child: ListView.separated(
        padding: const EdgeInsets.symmetric(horizontal: 14),
        scrollDirection: Axis.horizontal,
        itemCount: brands.length,
        separatorBuilder: (_, __) => const SizedBox(width: 9),
        itemBuilder: (_, index) {
          final brand = brands[index];
          final id = intValue(brand['id']);
          final name = brand['name']?.toString().trim() ?? '';
          final imageUrl = brand['image_url']?.toString().trim();
          return SizedBox(
            key: ValueKey('wholesale-brand-$id'),
            width: 76,
            child: Column(
              children: [
                Container(
                  width: 58,
                  height: 58,
                  clipBehavior: Clip.antiAlias,
                  decoration: BoxDecoration(
                    color: Colors.white,
                    shape: BoxShape.circle,
                    border: Border.all(color: palette.soft),
                  ),
                  child: imageUrl != null && imageUrl.isNotEmpty
                      ? Image.network(
                          imageUrl,
                          fit: BoxFit.contain,
                          errorBuilder: (_, __, ___) => Icon(
                            Icons.sell_outlined,
                            color: palette.primary,
                          ),
                        )
                      : Icon(
                          Icons.sell_outlined,
                          color: palette.primary,
                        ),
                ),
                const SizedBox(height: 5),
                Text(
                  name,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  textAlign: TextAlign.center,
                  style: const TextStyle(
                    fontSize: 9.5,
                    fontWeight: FontWeight.w700,
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

class _WholesaleProductGrid extends StatelessWidget {
  const _WholesaleProductGrid({
    required this.rows,
    required this.storeId,
    required this.sourceLocation,
    required this.session,
    required this.actionApi,
    required this.pendingActionStore,
    required this.palette,
    this.maxItems,
    this.defaultCurrency = 'EGP',
    this.noResults = false,
    this.showCommerceActions = false,
  });

  final List<Map<String, dynamic>> rows;
  final int storeId;
  final String sourceLocation;
  final CustomerSession session;
  final CustomerActionApi actionApi;
  final CustomerPendingActionStore? pendingActionStore;
  final FoodexPalette palette;
  final int? maxItems;
  final String defaultCurrency;
  final bool noResults;
  final bool showCommerceActions;

  @override
  Widget build(BuildContext context) {
    if (rows.isEmpty) {
      return FoodexEmptyState(
        key: const ValueKey('b2b-empty'),
        title: context.tr(
          noResults ? 'b2b.catalog.no_results' : 'b2b.catalog.empty_title',
        ),
        subtitle: context.tr(
          noResults ? 'b2b.catalog.no_results_body' : 'b2b.catalog.empty_body',
        ),
      );
    }

    final displayRows = maxItems == null
        ? rows
        : rows.take(maxItems!).toList(growable: false);
    final width = MediaQuery.sizeOf(context).width;

    return GridView.builder(
      key: const ValueKey('wholesale-product-grid'),
      padding: const EdgeInsets.symmetric(horizontal: 14),
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      itemCount: displayRows.length,
      gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
        crossAxisCount: 2,
        crossAxisSpacing: 10,
        mainAxisSpacing: 12,
        mainAxisExtent: showCommerceActions
            ? (width < 360 ? 424 : 438)
            : (width < 360 ? 286 : 306),
      ),
      itemBuilder: (context, index) => _WholesaleProductCard(
        row: displayRows[index],
        storeId: storeId,
        sourceLocation: sourceLocation,
        session: session,
        actionApi: actionApi,
        pendingActionStore: pendingActionStore,
        palette: palette,
        defaultCurrency: defaultCurrency,
        showCommerceActions: showCommerceActions,
      ),
    );
  }
}

class _WholesaleProductCard extends StatefulWidget {
  const _WholesaleProductCard({
    required this.row,
    required this.storeId,
    required this.sourceLocation,
    required this.session,
    required this.actionApi,
    required this.pendingActionStore,
    required this.palette,
    required this.defaultCurrency,
    required this.showCommerceActions,
  });

  final Map<String, dynamic> row;
  final int storeId;
  final String sourceLocation;
  final CustomerSession session;
  final CustomerActionApi actionApi;
  final CustomerPendingActionStore? pendingActionStore;
  final FoodexPalette palette;
  final String defaultCurrency;
  final bool showCommerceActions;

  @override
  State<_WholesaleProductCard> createState() => _WholesaleProductCardState();
}

class _WholesaleProductCardState extends State<_WholesaleProductCard> {
  bool busy = false;

  Future<void> _addToCart() async {
    final id = intValue(widget.row['id']);
    final minimum = doubleValue(
      widget.row['minimum_order_quantity'] ??
          widget.row['minimum_quantity'],
      1,
    );
    if (id <= 0 || widget.storeId <= 0 || busy) return;

    if (!widget.session.isAuthenticated) {
      await _beginWholesaleAddHandoff(
        context: context,
        pendingActionStore: widget.pendingActionStore,
        storeId: widget.storeId,
        productId: id,
        quantity: minimum,
        sourceLocation: widget.sourceLocation,
      );
      return;
    }

    setState(() => busy = true);
    try {
      await widget.actionApi.addCartItem(
        storeId: widget.storeId,
        productId: id,
        quantity: minimum,
      );
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          key: ValueKey('wholesale-product-added-$id'),
          content: Text(context.tr('b2b.catalog.added')),
          action: SnackBarAction(
            label: context.tr('b2b.catalog.open_cart'),
            onPressed: () => Navigator.of(context).pushNamed(
              '/b2b/cart?channel=wholesale&store_id=' +
                  widget.storeId.toString(),
            ),
          ),
        ),
      );
    } catch (error) {
      if (mounted) {
        await showOperationalError(context, error);
      }
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final row = widget.row;
    final palette = widget.palette;
    final id = intValue(row['id']);
    final minimum = doubleValue(
      row['minimum_order_quantity'] ?? row['minimum_quantity'],
      1,
    );
    final increment = doubleValue(row['ordering_increment'], 1);
    final packSize = doubleValue(row['pack_size'], 1);
    final packLabel = row['pack_label']?.toString().trim() ?? '';
    final brand = row['brand_name']?.toString().trim() ?? '';
    final category = row['category_name']?.toString().trim() ?? '';
    final availableQuantity = row['available_quantity'];
    final currency = row['currency']?.toString().trim().isNotEmpty == true
        ? row['currency'].toString().trim().toUpperCase()
        : widget.defaultCurrency;
    final isAvailable = row['is_available'] != false &&
        row['availability_state'] != 'OUT_OF_STOCK';
    final promotion = row['promotion'] is Map
        ? (row['promotion'] as Map)['name']?.toString().trim()
        : row['promotion_name']?.toString().trim();

    return Material(
      key: ValueKey('wholesale-product-card-$id'),
      color: Colors.white,
      borderRadius: BorderRadius.circular(18),
      child: InkWell(
        borderRadius: BorderRadius.circular(18),
        onTap: id <= 0 || widget.storeId <= 0
            ? null
            : () => Navigator.of(context).pushNamed(
                  '/b2b/products/' +
                      id.toString() +
                      '?store_id=' +
                      widget.storeId.toString(),
                ),
        child: Container(
          padding: const EdgeInsets.fromLTRB(10, 10, 10, 11),
          decoration: BoxDecoration(
            border: Border.all(color: palette.soft),
            borderRadius: BorderRadius.circular(18),
            boxShadow: [
              BoxShadow(
                color: Colors.black.withOpacity(.035),
                blurRadius: 10,
                offset: const Offset(0, 4),
              ),
            ],
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Expanded(
                child: Stack(
                  children: [
                    Positioned.fill(
                      child: Opacity(
                        opacity: isAvailable ? 1 : 0.42,
                        child: FoodexProductImage(
                          url: row['image_url']?.toString(),
                          palette: palette,
                        ),
                      ),
                    ),
                    if (!isAvailable)
                      PositionedDirectional(
                        start: 0,
                        bottom: 0,
                        child: Container(
                          key: ValueKey('wholesale-product-out-of-stock-$id'),
                          padding: const EdgeInsets.symmetric(
                            horizontal: 8,
                            vertical: 4,
                          ),
                          decoration: BoxDecoration(
                            color: Colors.white,
                            borderRadius: BorderRadius.circular(999),
                            border: Border.all(color: palette.soft),
                          ),
                          child: Text(
                            context.tr('customer.product.out_of_stock'),
                            style: TextStyle(
                              color: palette.muted,
                              fontSize: 9,
                              fontWeight: FontWeight.w900,
                            ),
                          ),
                        ),
                      ),
                    if (brand.isNotEmpty)
                      PositionedDirectional(
                        top: 0,
                        end: 0,
                        child: Container(
                          key: ValueKey('wholesale-product-brand-$id'),
                          constraints: const BoxConstraints(maxWidth: 112),
                          padding: const EdgeInsets.symmetric(
                            horizontal: 8,
                            vertical: 4,
                          ),
                          decoration: BoxDecoration(
                            color: const Color(0xFFE9F7EE),
                            borderRadius: BorderRadius.circular(999),
                          ),
                          child: Text(
                            brand,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: const TextStyle(
                              color: Color(0xFF006736),
                              fontSize: 9,
                              fontWeight: FontWeight.w800,
                            ),
                          ),
                        ),
                      ),
                    if (promotion != null && promotion.isNotEmpty)
                      PositionedDirectional(
                        start: 0,
                        top: 0,
                        child: Container(
                          key: ValueKey('wholesale-product-promotion-$id'),
                          constraints: const BoxConstraints(maxWidth: 112),
                          padding: const EdgeInsets.symmetric(
                            horizontal: 8,
                            vertical: 4,
                          ),
                          decoration: BoxDecoration(
                            color: palette.soft,
                            borderRadius: BorderRadius.circular(999),
                          ),
                          child: Text(
                            promotion,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: TextStyle(
                              color: palette.primaryDark,
                              fontSize: 9,
                              fontWeight: FontWeight.w800,
                            ),
                          ),
                        ),
                      ),
                  ],
                ),
              ),
              const SizedBox(height: 8),
              Text(
                row['name']?.toString() ?? '',
                key: ValueKey('wholesale-product-name-$id'),
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(
                  fontSize: 13,
                  height: 1.2,
                  fontWeight: FontWeight.w900,
                  color: Color(0xFF102033),
                ),
              ),
              if (widget.showCommerceActions && category.isNotEmpty) ...[
                const SizedBox(height: 3),
                Text(
                  category,
                  key: ValueKey('wholesale-product-category-$id'),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    color: palette.muted,
                    fontSize: 9,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ],
              const SizedBox(height: 6),
              Text(
                money(
                  row['account_price'] ?? row['unit_price'] ?? row['price'],
                  currency: currency,
                ),
                key: ValueKey('wholesale-product-price-$id'),
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: TextStyle(
                  color: palette.primary,
                  fontWeight: FontWeight.w900,
                  fontSize: 14,
                ),
              ),
              const SizedBox(height: 5),
              Row(
                children: [
                  Icon(
                    Icons.inventory_2_outlined,
                    size: 14,
                    color: palette.primaryDark,
                  ),
                  const SizedBox(width: 5),
                  Expanded(
                    child: Text(
                      context.tr('b2b.minimum_order') +
                          ': ' +
                          compactNumber(minimum),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(
                        color: palette.muted,
                        fontSize: 10,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                  ),
                ],
              ),
              if (widget.showCommerceActions) ...[
                const SizedBox(height: 4),
                Text(
                  (packLabel.isNotEmpty
                          ? packLabel
                          : context.tr('b2b.catalog.pack') +
                              ' ' +
                              compactNumber(packSize)) +
                      ' · ' +
                      context.tr('b2b.catalog.step') +
                      ' ' +
                      compactNumber(increment),
                  key: ValueKey('wholesale-product-pack-$id'),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    color: palette.muted,
                    fontSize: 9,
                    fontWeight: FontWeight.w700,
                  ),
                ),
                const SizedBox(height: 4),
                Text(
                  isAvailable
                      ? (availableQuantity == null
                          ? context.tr('b2b.product.inventory_unbounded')
                          : context.tr('b2b.product.inventory') +
                              ': ' +
                              compactNumber(
                                doubleValue(availableQuantity, 0),
                              ))
                      : context.tr('customer.product.out_of_stock'),
                  key: ValueKey('wholesale-product-availability-$id'),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    color: isAvailable ? palette.primaryDark : palette.muted,
                    fontSize: 9,
                    fontWeight: FontWeight.w800,
                  ),
                ),
                const SizedBox(height: 8),
                FilledButton.icon(
                  key: ValueKey('wholesale-product-add-$id'),
                  onPressed: isAvailable && id > 0 && !busy
                      ? _addToCart
                      : null,
                  style: FilledButton.styleFrom(
                    minimumSize: const Size.fromHeight(44),
                  ),
                  icon: busy
                      ? const SizedBox.square(
                          dimension: 16,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        )
                      : const Icon(Icons.add_shopping_cart_rounded, size: 18),
                  label: Text(
                    isAvailable
                        ? context.tr('customer.action.add_cart')
                        : context.tr('customer.product.out_of_stock'),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

Future<void> _beginWholesaleAddHandoff({
  required BuildContext context,
  required CustomerPendingActionStore? pendingActionStore,
  required int storeId,
  required int productId,
  required double quantity,
  String? sourceLocation,
}) async {
  final parsedContext =
      CustomerCommerceContext.tryParseLocation(sourceLocation);
  final commerceContext = parsedContext != null &&
          parsedContext.isWholesale &&
          parsedContext.storeId == storeId
      ? parsedContext
      : CustomerCommerceContext(
          channel: CustomerCommerceChannel.wholesale,
          storeId: storeId,
          source: CustomerCommerceSource.wholesaleEntry,
        );
  final nextLocation =
      CustomerRouteLocations.wholesaleProduct(commerceContext, productId);

  final pending = CustomerPendingAction(
    kind: CustomerPendingActionKind.addToCart,
    context: commerceContext,
    nextLocation: nextLocation,
    createdAtEpochMs: DateTime.now().toUtc().millisecondsSinceEpoch,
    productId: productId,
    quantity: quantity,
  );

  try {
    await pendingActionStore?.write(pending);
  } catch (_) {
    // The safe route carries enough context to continue authentication even
    // when secure pending-action persistence is unavailable.
  }

  if (!context.mounted) return;
  Navigator.of(context).pushNamed(
    CustomerRouteLocations.authHandoff(
      context: commerceContext,
      next: nextLocation,
    ),
  );
}


class WholesaleCatalogDesignScreen extends StatefulWidget {
  const WholesaleCatalogDesignScreen({
    required this.location,
    required this.session,
    required this.api,
    required this.storefrontApi,
    required this.actionApi,
    this.pendingActionStore,
    super.key,
  });

  final String location;
  final CustomerSession session;
  final B2bApi? api;
  final StorefrontApi? storefrontApi;
  final CustomerActionApi actionApi;
  final CustomerPendingActionStore? pendingActionStore;

  @override
  State<WholesaleCatalogDesignScreen> createState() =>
      _WholesaleCatalogDesignScreenState();
}

class _WholesaleCatalogDesignScreenState
    extends State<WholesaleCatalogDesignScreen> {
  final search = TextEditingController();
  late final int storeId = wholesaleStoreId(widget.location);
  late Future<Map<String, dynamic>> future = _load();
  int? selectedCategoryId;
  String sortMode = 'default';
  int visibleLimit = 24;
  bool stale = false;
  Map<String, dynamic>? _lastPayload;

  Future<Map<String, dynamic>> _load([String query = '']) async {
    try {
      if (storeId <= 0) {
        return const {
          'store_required': true,
          'products': {'data': <Object>[]},
          'storefront': <String, Object?>{},
        };
      }

      Map<String, dynamic> storefront = <String, dynamic>{};
      if (widget.storefrontApi != null) {
        storefront = await widget.storefrontApi!.wholesaleHome(storeId);
      }

      Object? products;
      if (widget.api != null) {
        var endpoint =
            '/api/v1/b2b/products?store_id=' + storeId.toString();
        if (query.trim().isNotEmpty) {
          endpoint += '&q=' + Uri.encodeQueryComponent(query.trim());
        }
        products = await widget.api!.get(endpoint);
      } else {
        var rows = mapRows(storefront['products']);
        final needle = query.trim().toLowerCase();
        if (needle.isNotEmpty) {
          rows = rows
              .where((row) {
                final name = row['name']?.toString().toLowerCase() ?? '';
                final sku = row['sku']?.toString().toLowerCase() ?? '';
                final barcode =
                    row['barcode']?.toString().toLowerCase() ?? '';
                return name.contains(needle) ||
                    sku.contains(needle) ||
                    barcode.contains(needle);
              })
              .toList(growable: false);
        }
        products = <String, Object?>{'data': rows};
      }

      final payload = <String, dynamic>{
        'products': products,
        'storefront': storefront,
      };
      stale = false;
      _lastPayload = payload;
      return payload;
    } catch (_) {
      final cached = _lastPayload;
      if (cached != null) {
        stale = true;
        return cached;
      }
      rethrow;
    }
  }

  void _reload({bool resetLimit = true}) {
    setState(() {
      if (resetLimit) visibleLimit = 24;
      future = _load(search.text);
    });
  }

  @override
  void dispose() {
    search.dispose();
    super.dispose();
  }

  Future<void> _openCategoryFilter(
    BuildContext context,
    List<Map<String, dynamic>> categories,
  ) async {
    final selected = await showModalBottomSheet<int>(
      context: context,
      showDragHandle: true,
      builder: (sheetContext) => SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(18, 4, 18, 24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(
                context.tr('b2b.catalog.filter_title'),
                textAlign: TextAlign.center,
                style: const TextStyle(
                  fontSize: 18,
                  fontWeight: FontWeight.w900,
                ),
              ),
              const SizedBox(height: 14),
              Wrap(
                spacing: 8,
                runSpacing: 8,
                children: [
                  ChoiceChip(
                    label: Text(context.tr('b2b.catalog.all')),
                    selected: selectedCategoryId == null,
                    onSelected: (_) => Navigator.of(sheetContext).pop(-1),
                  ),
                  for (final category in categories)
                    ChoiceChip(
                      label: Text(category['name']?.toString() ?? ''),
                      selected:
                          selectedCategoryId == intValue(category['id']),
                      onSelected: (_) => Navigator.of(sheetContext)
                          .pop(intValue(category['id'])),
                    ),
                ],
              ),
            ],
          ),
        ),
      ),
    );

    if (!mounted || selected == null) return;
    setState(() {
      selectedCategoryId = selected < 0 ? null : selected;
      visibleLimit = 24;
    });
  }

  @override
  Widget build(BuildContext context) => FutureBuilder<Map<String, dynamic>>(
        future: future,
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done) {
            return const Scaffold(
              body: SafeArea(
                child: FoodexLoading(
                  key: ValueKey('b2b-catalog-loading'),
                ),
              ),
            );
          }
          if (snapshot.hasError) {
            return Scaffold(
              body: SafeArea(
                child: FoodexErrorState(
                  key: const ValueKey('b2b-catalog-error'),
                  message: context.tr('b2b.catalog.load_error'),
                  onRetry: () =>
                      setState(() => future = _load(search.text)),
                ),
              ),
            );
          }

          final payload = snapshot.data ?? const <String, dynamic>{};
          final allRows = dataRows(payload['products']);
          final storefront = payload['storefront'] is Map
              ? Map<String, dynamic>.from(payload['storefront'] as Map)
              : <String, dynamic>{};
          final theme = storefront['theme'] is Map
              ? Map<String, dynamic>.from(storefront['theme'] as Map)
              : <String, dynamic>{};
          final branding = storefront['branding'] is Map
              ? Map<String, dynamic>.from(storefront['branding'] as Map)
              : <String, dynamic>{};
          final custom = branding['custom'] is Map
              ? Map<String, dynamic>.from(branding['custom'] as Map)
              : <String, dynamic>{};
          final store = storefront['store'] is Map
              ? Map<String, dynamic>.from(storefront['store'] as Map)
              : <String, dynamic>{};
          final palette = _wholesalePalette(theme);
          final title =
              custom['brand_title_ar']?.toString().trim().isNotEmpty == true
                  ? custom['brand_title_ar'].toString()
                  : (store['name']?.toString().trim().isNotEmpty == true
                      ? store['name'].toString()
                      : 'فودكس');

          if (payload['store_required'] == true) {
            return Scaffold(
              backgroundColor: FoodexPalette.wholesale.background,
              body: SafeArea(
                child: FoodexEmptyState(
                  key: const ValueKey('b2b-catalog-store-required'),
                  title: context.tr('b2b.catalog.store_required_title'),
                  subtitle: context.tr('b2b.catalog.store_required_body'),
                ),
              ),
            );
          }

          final categoryMap = <int, Map<String, dynamic>>{};
          final categorySources = <Map<String, dynamic>>[
            ...mapRows(storefront['products']),
            ...allRows,
          ];
          for (final row in categorySources) {
            final id = intValue(row['category_id']);
            if (id <= 0 || categoryMap.containsKey(id)) continue;
            categoryMap[id] = {
              'id': id,
              'name': row['category_name']?.toString().trim().isNotEmpty ==
                      true
                  ? row['category_name'].toString()
                  : context.tr('b2b.catalog.category') + ' $id',
            };
          }
          final categories = categoryMap.values.toList(growable: false);

          var visibleRows = allRows
              .where(
                (row) =>
                    selectedCategoryId == null ||
                    intValue(row['category_id']) == selectedCategoryId,
              )
              .toList(growable: true);

          double priceOf(Map<String, dynamic> row) => doubleValue(
                row['account_price'] ?? row['unit_price'] ?? row['price'],
                0,
              );
          if (sortMode == 'price_low') {
            visibleRows.sort((a, b) => priceOf(a).compareTo(priceOf(b)));
          } else if (sortMode == 'price_high') {
            visibleRows.sort((a, b) => priceOf(b).compareTo(priceOf(a)));
          } else if (sortMode == 'name') {
            visibleRows.sort(
              (a, b) => (a['name']?.toString() ?? '')
                  .compareTo(b['name']?.toString() ?? ''),
            );
          }

          final displayedRows =
              visibleRows.take(visibleLimit).toList(growable: false);
          final hasMore = displayedRows.length < visibleRows.length;
          final responseCurrency = payload['products'] is Map
              ? (payload['products'] as Map)['currency']?.toString()
              : null;
          final defaultCurrency =
              responseCurrency == null || responseCurrency.trim().isEmpty
                  ? 'EGP'
                  : responseCurrency.trim().toUpperCase();

          return Scaffold(
            key: const ValueKey('wholesale-catalog-screen'),
            backgroundColor: palette.background,
            body: SafeArea(
              child: ListView(
                padding: EdgeInsets.zero,
                children: [
                  _WholesaleHeader(
                    title: title,
                    logoUrl: branding['logo_url']?.toString(),
                    address: branding['address']?.toString(),
                    palette: palette,
                    onCart: () => Navigator.of(context).pushNamed(
                      '/b2b/cart?store=' + storeId.toString(),
                    ),
                  ),
                  Padding(
                    padding: const EdgeInsets.fromLTRB(14, 0, 14, 10),
                    child: TextField(
                      key: const ValueKey('wholesale-catalog-search'),
                      controller: search,
                      textInputAction: TextInputAction.search,
                      onSubmitted: (_) => _reload(),
                      onChanged: (_) => setState(() {}),
                      decoration: InputDecoration(
                        hintText: context.tr('b2b.catalog.search_hint'),
                        prefixIcon: const Icon(Icons.search_rounded),
                        suffixIcon: IconButton(
                          key: const ValueKey('wholesale-catalog-clear-search'),
                          tooltip: context.tr('b2b.catalog.clear_search'),
                          onPressed: search.text.trim().isEmpty
                              ? null
                              : () {
                                  search.clear();
                                  _reload();
                                },
                          icon: const Icon(Icons.close_rounded),
                        ),
                      ),
                    ),
                  ),
                  if (categories.isNotEmpty)
                    SizedBox(
                      key: const ValueKey('wholesale-catalog-categories'),
                      height: 48,
                      child: ListView(
                        padding: const EdgeInsets.symmetric(horizontal: 14),
                        scrollDirection: Axis.horizontal,
                        children: [
                          Padding(
                            padding: const EdgeInsetsDirectional.only(end: 8),
                            child: ChoiceChip(
                              key: const ValueKey('wholesale-category-all'),
                              label: Text(context.tr('b2b.catalog.all')),
                              selected: selectedCategoryId == null,
                              onSelected: (_) => setState(() {
                                selectedCategoryId = null;
                                visibleLimit = 24;
                              }),
                            ),
                          ),
                          for (final category in categories)
                            Padding(
                              padding:
                                  const EdgeInsetsDirectional.only(end: 8),
                              child: ChoiceChip(
                                key: ValueKey(
                                  'wholesale-category-filter-${category['id']}',
                                ),
                                label:
                                    Text(category['name']?.toString() ?? ''),
                                selected: selectedCategoryId ==
                                    intValue(category['id']),
                                onSelected: (_) => setState(() {
                                  selectedCategoryId =
                                      intValue(category['id']);
                                  visibleLimit = 24;
                                }),
                              ),
                            ),
                        ],
                      ),
                    ),
                  Padding(
                    padding: const EdgeInsets.fromLTRB(14, 8, 14, 12),
                    child: Row(
                      children: [
                        OutlinedButton.icon(
                          key: const ValueKey('wholesale-catalog-filter'),
                          onPressed: () =>
                              _openCategoryFilter(context, categories),
                          icon: const Icon(Icons.tune_rounded, size: 18),
                          label: Text(context.tr('b2b.catalog.filter')),
                        ),
                        const SizedBox(width: 8),
                        Expanded(
                          child: DropdownButtonFormField<String>(
                            key: const ValueKey('wholesale-catalog-sort'),
                            value: sortMode,
                            isDense: true,
                            decoration: const InputDecoration(
                              contentPadding: EdgeInsets.symmetric(
                                horizontal: 12,
                                vertical: 9,
                              ),
                            ),
                            items: [
                              DropdownMenuItem(
                                value: 'default',
                                child: Text(
                                  context.tr('b2b.catalog.sort_default'),
                                ),
                              ),
                              DropdownMenuItem(
                                value: 'price_low',
                                child: Text(
                                  context.tr('b2b.catalog.sort_price_low'),
                                ),
                              ),
                              DropdownMenuItem(
                                value: 'price_high',
                                child: Text(
                                  context.tr('b2b.catalog.sort_price_high'),
                                ),
                              ),
                              DropdownMenuItem(
                                value: 'name',
                                child: Text(
                                  context.tr('b2b.catalog.sort_name'),
                                ),
                              ),
                            ],
                            onChanged: (value) {
                              if (value != null) {
                                setState(() {
                                  sortMode = value;
                                  visibleLimit = 24;
                                });
                              }
                            },
                          ),
                        ),
                        const SizedBox(width: 10),
                        Text(
                          '${displayedRows.length}/${visibleRows.length} ' +
                              context.tr('b2b.catalog.products_count'),
                          key: const ValueKey('wholesale-catalog-count'),
                          style: TextStyle(
                            color: palette.muted,
                            fontSize: 11,
                            fontWeight: FontWeight.w700,
                          ),
                        ),
                        IconButton(
                          key: const ValueKey('wholesale-catalog-refresh'),
                          tooltip: context.tr('b2b.catalog.refresh'),
                          onPressed: () => _reload(resetLimit: false),
                          icon: const Icon(Icons.refresh_rounded),
                        ),
                      ],
                    ),
                  ),
                  if (stale)
                    Padding(
                      padding: const EdgeInsets.fromLTRB(14, 0, 14, 12),
                      child: Material(
                        key: const ValueKey('b2b-catalog-stale'),
                        color: palette.soft,
                        borderRadius: BorderRadius.circular(14),
                        child: Padding(
                          padding: const EdgeInsets.all(12),
                          child: Row(
                            children: [
                              Icon(
                                Icons.cloud_off_outlined,
                                color: palette.primaryDark,
                              ),
                              const SizedBox(width: 8),
                              Expanded(
                                child: Text(
                                  context.tr('b2b.catalog.stale'),
                                  style: const TextStyle(
                                    fontWeight: FontWeight.w700,
                                  ),
                                ),
                              ),
                            ],
                          ),
                        ),
                      ),
                    ),
                  _WholesaleProductGrid(
                    rows: displayedRows,
                    storeId: storeId,
                    sourceLocation: widget.location,
                    session: widget.session,
                    actionApi: widget.actionApi,
                    pendingActionStore: widget.pendingActionStore,
                    palette: palette,
                    defaultCurrency: defaultCurrency,
                    noResults: allRows.isNotEmpty &&
                        visibleRows.isEmpty &&
                        (search.text.trim().isNotEmpty ||
                            selectedCategoryId != null),
                    showCommerceActions: true,
                  ),
                  if (hasMore)
                    Padding(
                      padding: const EdgeInsets.fromLTRB(14, 12, 14, 0),
                      child: OutlinedButton.icon(
                        key: const ValueKey('wholesale-catalog-load-more'),
                        onPressed: () =>
                            setState(() => visibleLimit += 24),
                        icon: const Icon(Icons.expand_more_rounded),
                        label: Text(context.tr('b2b.catalog.load_more')),
                      ),
                    ),
                  const SizedBox(height: 24),
                ],
              ),
            ),
          );
        },
      );
}

class _WholesaleBottomNav extends StatelessWidget {
  const _WholesaleBottomNav({
    required this.storeId,
    required this.palette,
  });

  final int storeId;
  final FoodexPalette palette;

  @override
  Widget build(BuildContext context) => NavigationBar(
        selectedIndex: 0,
        indicatorColor: palette.soft,
        onDestinationSelected: (index) {
          if (index == 1) {
            Navigator.of(context).pushNamed(
              '/b2b/products?store_id=' + storeId.toString(),
            );
          } else if (index == 2) {
            Navigator.of(context).pushNamed(
              '/b2b/cart?store=' + storeId.toString(),
            );
          } else if (index == 3) {
            Navigator.of(context).pushNamed(
              '/b2b/orders?channel=wholesale&store_id=' +
                  storeId.toString(),
            );
          }
        },
        destinations: const [
          NavigationDestination(
            icon: Icon(Icons.home_outlined),
            label: 'الرئيسية',
          ),
          NavigationDestination(
            icon: Icon(Icons.grid_view_rounded),
            label: 'الكتالوج',
          ),
          NavigationDestination(
            icon: Icon(Icons.shopping_cart_outlined),
            label: 'السلة',
          ),
          NavigationDestination(
            icon: Icon(Icons.receipt_long_outlined),
            label: 'طلباتي',
          ),
        ],
      );
}

FoodexPalette _wholesalePalette(Map<String, dynamic> theme) {
  // B2B customer identity is intentionally locked to the FOODEx green system.
  // Storefront payloads may still contain legacy purple values; do not render
  // those values on the customer B2B surfaces.
  return FoodexPalette.wholesale;
}

String _defaultWholesaleSectionTitle(String type) {
  switch (type) {
    case 'categories':
      return 'التصنيفات';
    case 'featured_products':
      return 'منتجات مميزة';
    case 'best_sellers':
      return 'الأكثر مبيعًا';
    case 'reorder':
      return 'إعادة الطلب';
    case 'brands':
      return 'العلامات التجارية';
    case 'departments':
      return 'الأقسام';
    default:
      return 'عروض الجملة';
  }
}

class WholesaleProductDetailsDesignScreen extends StatefulWidget {
  const WholesaleProductDetailsDesignScreen({
    required this.location,
    required this.session,
    required this.api,
    required this.storefrontApi,
    required this.actionApi,
    this.favoritesApi,
    this.pendingActionStore,
    super.key,
  });

  final String location;
  final CustomerSession session;
  final B2bApi? api;
  final StorefrontApi? storefrontApi;
  final CustomerActionApi actionApi;
  final B2cRetailFavoritesApi? favoritesApi;
  final CustomerPendingActionStore? pendingActionStore;

  @override
  State<WholesaleProductDetailsDesignScreen> createState() =>
      _WholesaleProductDetailsDesignScreenState();
}

class _WholesaleProductDetailsDesignScreenState
    extends State<WholesaleProductDetailsDesignScreen> {
  late final int storeId = wholesaleStoreId(widget.location);
  late final int productId = productIdFromLocation(widget.location);
  late Future<Object?> future = _loadProduct();
  double? quantity;
  Map<String, dynamic>? _lastProduct;
  Timer? _stockRefreshTimer;
  bool stale = false;
  bool revalidating = false;

  String get endpoint =>
      '/api/v1/b2b/products/' +
      productId.toString() +
      '?store_id=' +
      storeId.toString();

  void _cacheFreshProduct(Map<String, dynamic> row) {
    stale = false;
    _lastProduct = Map<String, dynamic>.from(row);
    _stockRefreshTimer?.cancel();
    final available = row['is_available'] != false &&
        row['availability_state'] != 'OUT_OF_STOCK';
    if (!available) {
      _stockRefreshTimer = Timer(const Duration(seconds: 15), () {
        if (mounted) _retry();
      });
    }
  }

  Future<Object?> _loadProduct() async {
    try {
      final api = widget.api;
      Object? result;
      if (api == null) {
        final storefrontApi = widget.storefrontApi;
        if (storefrontApi == null || storeId <= 0 || productId <= 0) {
          return null;
        }
        final storefront = await storefrontApi.wholesaleHome(storeId);
        final rows = mapRows(storefront['products']);
        Map<String, dynamic>? product;
        for (final row in rows) {
          if (intValue(row['id']) == productId) {
            product = row;
            break;
          }
        }
        if (product == null) return null;
        result = <String, dynamic>{
          ...product,
          'store_id': storeId,
          'base_wholesale_price': product['price'],
          'account_price': product['price'],
          'minimum_order_quantity':
              product['minimum_order_quantity'] ?? 1,
          'ordering_increment': product['ordering_increment'] ?? 1,
          'pack_size': product['pack_size'] ?? 1,
          'currency': product['currency'] ?? 'EGP',
        };
      } else {
        result = await api.get(endpoint);
      }

      if (result is Map) {
        final row = Map<String, dynamic>.from(result);
        _cacheFreshProduct(row);
        return row;
      }
      return result;
    } catch (error, stack) {
      final failure = _wholesaleProductFailureInfo(error);
      CustomerDiagnostics.instance.recordRuntimeFailure(
        operation: 'b2b_product_load',
        path: endpoint,
        category: failure.category,
        statusCode: failure.statusCode,
        supportReference: failure.supportReference,
      );
      final cached = _lastProduct;
      if (cached != null) {
        stale = true;
        return Map<String, dynamic>.from(cached);
      }
      Error.throwWithStackTrace(error, stack);
    }
  }

  void _retry() {
    if (!mounted) return;
    setState(() {
      future = _loadProduct();
    });
  }

  bool _commercialTermsChanged(
    Map<String, dynamic> previous,
    Map<String, dynamic> latest,
  ) {
    const keys = <String>[
      'account_price',
      'base_wholesale_price',
      'retail_reference_price',
      'minimum_order_quantity',
      'ordering_increment',
      'pack_size',
      'case_size',
      'pack_label',
      'available_quantity',
      'is_available',
      'availability_state',
      'currency',
    ];
    for (final key in keys) {
      if (previous[key]?.toString() != latest[key]?.toString()) {
        return true;
      }
    }
    return false;
  }

  bool _quantityIsValid(Map<String, dynamic> row, double value) {
    final minimum = doubleValue(row['minimum_order_quantity'], 1);
    final increment = math.max(
      .001,
      doubleValue(row['ordering_increment'], 1),
    );
    final available = row['available_quantity'] == null
        ? null
        : doubleValue(row['available_quantity'], 0);
    final isAvailable = row['is_available'] != false &&
        row['availability_state'] != 'OUT_OF_STOCK';
    if (!isAvailable || value + .0001 < minimum) return false;
    final steps = (value - minimum) / increment;
    if ((steps - steps.round()).abs() >= .0001) return false;
    return available == null || value <= available + .0001;
  }

  String? _quantityIssue(
    BuildContext context,
    Map<String, dynamic> row,
    double value,
  ) {
    final minimum = doubleValue(row['minimum_order_quantity'], 1);
    final increment = math.max(
      .001,
      doubleValue(row['ordering_increment'], 1),
    );
    final available = row['available_quantity'] == null
        ? null
        : doubleValue(row['available_quantity'], 0);
    final isAvailable = row['is_available'] != false &&
        row['availability_state'] != 'OUT_OF_STOCK';
    if (!isAvailable) {
      return context.tr('customer.product.out_of_stock');
    }
    if (value + .0001 < minimum) {
      return context.tr('b2b.product.quantity_minimum') +
          ' ' +
          compactNumber(minimum);
    }
    final steps = (value - minimum) / increment;
    if ((steps - steps.round()).abs() >= .0001) {
      return context.tr('b2b.product.quantity_step') +
          ' ' +
          compactNumber(increment);
    }
    if (available != null && value > available + .0001) {
      return context.tr('b2b.product.quantity_stock') +
          ' ' +
          compactNumber(available);
    }
    return null;
  }

  Future<void> _addProduct(
    Map<String, dynamic> row,
  ) async {
    if (!widget.session.isAuthenticated) {
      await _beginWholesaleAddHandoff(
        context: context,
        pendingActionStore: widget.pendingActionStore,
        storeId: storeId,
        productId: productId,
        quantity: quantity!,
        sourceLocation: widget.location,
      );
      return;
    }

    setState(() => revalidating = true);
    try {
      final api = widget.api;
      if (api != null) {
        final latestRaw = await api.get(endpoint);
        if (latestRaw is Map) {
          final latest = Map<String, dynamic>.from(latestRaw);
          final changed = _commercialTermsChanged(row, latest);
          _cacheFreshProduct(latest);
          final latestMinimum =
              doubleValue(latest['minimum_order_quantity'], 1);
          final latestAvailable = latest['available_quantity'] == null
              ? null
              : doubleValue(latest['available_quantity'], 0);
          var nextQuantity = quantity ?? latestMinimum;
          if (nextQuantity < latestMinimum) nextQuantity = latestMinimum;
          if (latestAvailable != null &&
              nextQuantity > latestAvailable &&
              latestAvailable + .0001 >= latestMinimum) {
            nextQuantity = latestAvailable;
          }
          if (changed || !_quantityIsValid(latest, nextQuantity)) {
            if (!mounted) return;
            setState(() {
              quantity = nextQuantity;
              future = Future<Object?>.value(latest);
            });
            ScaffoldMessenger.of(context).showSnackBar(
              SnackBar(
                key: const ValueKey('b2b-product-terms-updated'),
                content: Text(
                  context.tr('b2b.product.terms_updated'),
                ),
              ),
            );
            return;
          }
          row = latest;
        }
      }

      final selectedQuantity = quantity!;
      if (!_quantityIsValid(row, selectedQuantity)) {
        if (!mounted) return;
        final issue = _quantityIssue(context, row, selectedQuantity);
        if (issue != null) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text(issue)),
          );
        }
        return;
      }

      await widget.actionApi.addCartItem(
        storeId: storeId,
        productId: productId,
        quantity: selectedQuantity,
      );
      if (mounted) {
        Navigator.of(context).pushNamed(
          '/b2b/cart?channel=wholesale&store_id=' +
              storeId.toString(),
        );
      }
    } catch (error) {
      if (mounted) {
        await showOperationalError(context, error);
        _retry();
      }
    } finally {
      if (mounted) setState(() => revalidating = false);
    }
  }

  @override
  void dispose() {
    _stockRefreshTimer?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Directionality(
      textDirection: Directionality.of(context),
      child: Scaffold(
        backgroundColor: Colors.white,
        body: SafeArea(
          child: FutureBuilder<Object?>(
            future: future,
            builder: (context, snapshot) {
              if (snapshot.connectionState != ConnectionState.done) {
                return const FoodexLoading(
                  key: ValueKey('b2b-loading'),
                );
              }
              if (snapshot.hasError) {
                final failure = _wholesaleProductFailureInfo(snapshot.error);
                return ListView(
                  key: ValueKey(
                    failure.forbiddenVisual ? 'b2b-forbidden' : 'b2b-error',
                  ),
                  padding: const EdgeInsets.fromLTRB(15, 10, 15, 22),
                  children: [
                    FoodexTopBar(title: context.tr('b2b.product.title')),
                    const SizedBox(height: 18),
                    FoodexErrorState(
                      message: context.tr(failure.messageKey),
                      onRetry: _retry,
                      retryLabel: context.tr('customer.action.retry'),
                      retryKey: const ValueKey('b2b-error-retry'),
                    ),
                    if (failure.supportReference != null) ...[
                      const SizedBox(height: 8),
                      Text(
                        '${context.tr('b2b.remote.support_reference')}: '
                        '${failure.supportReference}',
                        key: const ValueKey('b2b-error-support-reference'),
                        textAlign: TextAlign.center,
                        style: Theme.of(context).textTheme.labelMedium,
                      ),
                    ],
                    const SizedBox(height: 12),
                    OutlinedButton.icon(
                      key: const ValueKey('b2b-error-back-products'),
                      onPressed: () => Navigator.of(context).pushReplacementNamed(
                        '/b2b/products?store_id=' + storeId.toString(),
                      ),
                      icon: const Icon(Icons.arrow_back_rounded),
                      label: Text(context.tr('b2b.remote.back_products')),
                    ),
                  ],
                );
              }

              final row = snapshot.data is Map
                  ? Map<String, dynamic>.from(snapshot.data as Map)
                  : <String, dynamic>{};
              if (row.isEmpty) {
                return FoodexEmptyState(
                  key: const ValueKey('b2b-empty'),
                  title: context.tr('b2b.remote.not_found'),
                  subtitle: context.tr('b2b.remote.empty'),
                );
              }

              final minimum =
                  doubleValue(row['minimum_order_quantity'], 1);
              final increment = math.max(
                .001,
                doubleValue(row['ordering_increment'], 1),
              );
              quantity ??= minimum;
              final images =
                  (row['images'] as List? ?? const <Object>[])
                      .whereType<String>()
                      .where((value) => value.trim().isNotEmpty)
                      .toList(growable: true);
              final primary = row['image_url']?.toString();
              if (images.isEmpty &&
                  primary != null &&
                  primary.isNotEmpty) {
                images.add(primary);
              }
              final currency =
                  row['currency']?.toString().trim().isNotEmpty == true
                      ? row['currency'].toString().trim().toUpperCase()
                      : 'EGP';
              final isAvailable = row['is_available'] != false &&
                  row['availability_state'] != 'OUT_OF_STOCK';
              final availableQuantity = row['available_quantity'] == null
                  ? null
                  : doubleValue(row['available_quantity'], 0);
              final quantityIssue =
                  _quantityIssue(context, row, quantity!);
              final brand = row['brand_name']?.toString().trim() ?? '';
              final category = row['category_name']?.toString().trim() ?? '';
              final description = row['description']?.toString().trim() ?? '';
              final promotion = row['promotion'] is Map
                  ? (row['promotion'] as Map)['name']?.toString().trim()
                  : row['promotion_name']?.toString().trim();

              return ListView(
                key: const ValueKey('b2b-product-detail-data'),
                padding: const EdgeInsets.fromLTRB(15, 10, 15, 22),
                children: [
                  FoodexTopBar(
                    title: context.tr('b2b.product.title'),
                    actions: [
                      IconButton(
                        key: const ValueKey('b2b-product-refresh'),
                        tooltip: context.tr('b2b.product.refresh'),
                        onPressed: revalidating ? null : _retry,
                        icon: const Icon(Icons.refresh_rounded),
                      ),
                      CustomerFavoriteButton(
                        api: widget.favoritesApi,
                        storeId: storeId,
                        productId: productId,
                        isAuthenticated: widget.session.isAuthenticated,
                        loginRoute:
                            '/auth/checkout?next=${Uri.encodeComponent(widget.location)}',
                      ),
                    ],
                  ),
                  if (stale) ...[
                    const SizedBox(height: 10),
                    Material(
                      key: const ValueKey('b2b-product-stale'),
                      color: FoodexPalette.wholesale.soft,
                      borderRadius: BorderRadius.circular(14),
                      child: Padding(
                        padding: const EdgeInsets.all(12),
                        child: Row(
                          children: [
                            const Icon(Icons.cloud_off_outlined),
                            const SizedBox(width: 8),
                            Expanded(
                              child: Text(
                                context.tr('b2b.product.stale'),
                                style: const TextStyle(
                                  fontWeight: FontWeight.w700,
                                ),
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
                  ],
                  const SizedBox(height: 10),
                  Opacity(
                    opacity: isAvailable ? 1 : 0.42,
                    child: FoodexGallery(
                      key: const ValueKey('b2b-product-gallery'),
                      urls: images,
                      palette: FoodexPalette.wholesale,
                    ),
                  ),
                  const SizedBox(height: 14),
                  Text(
                    row['name']?.toString() ?? '',
                    style: const TextStyle(
                      fontSize: 20,
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                  const SizedBox(height: 4),
                  Text(
                    (row['sku']?.toString() ?? '') +
                        (row['pack_label'] == null
                            ? ''
                            : ' · ' + row['pack_label'].toString()),
                    style: const TextStyle(
                      color: Color(0xFF6B7785),
                      fontSize: 12,
                    ),
                  ),
                  if (brand.isNotEmpty || category.isNotEmpty) ...[
                    const SizedBox(height: 8),
                    Wrap(
                      spacing: 8,
                      runSpacing: 8,
                      children: [
                        if (brand.isNotEmpty)
                          _ProductMetaChip(
                            key: const ValueKey('b2b-product-brand'),
                            label: context.tr('b2b.product.brand'),
                            value: brand,
                          ),
                        if (category.isNotEmpty)
                          _ProductMetaChip(
                            key: const ValueKey('b2b-product-category'),
                            label: context.tr('b2b.product.category'),
                            value: category,
                          ),
                      ],
                    ),
                  ],
                  if (promotion != null && promotion.isNotEmpty) ...[
                    const SizedBox(height: 8),
                    _ProductMetaChip(
                      key: const ValueKey('b2b-product-promotion'),
                      label: context.tr('b2b.product.promotion'),
                      value: promotion,
                    ),
                  ],
                  const SizedBox(height: 13),
                  _PricingPanel(
                    row: row,
                    currency: currency,
                  ),
                  const SizedBox(height: 12),
                  Row(
                    children: [
                      Expanded(
                        child: _InfoPill(
                          icon: Icons.inventory_2_outlined,
                          label: context.tr('b2b.minimum_order') +
                              ' ' +
                              compactNumber(minimum),
                        ),
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: _InfoPill(
                          icon: Icons.warehouse_outlined,
                          label: isAvailable
                              ? context.tr('b2b.product.inventory') +
                                  ' ' +
                                  (availableQuantity == null
                                      ? context.tr(
                                          'b2b.product.inventory_unbounded',
                                        )
                                      : compactNumber(availableQuantity))
                              : context.tr('customer.product.out_of_stock'),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 8),
                  _InfoPill(
                    icon: Icons.straighten_rounded,
                    label: context.tr('b2b.product.quantity_step') +
                        ' ' +
                        compactNumber(increment),
                  ),
                  if (quantityIssue != null && isAvailable) ...[
                    const SizedBox(height: 8),
                    Text(
                      quantityIssue,
                      key: const ValueKey('b2b-product-quantity-error'),
                      style: const TextStyle(
                        color: Color(0xFFB42318),
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                  ],
                  const SizedBox(height: 14),
                  FoodexQuantityCta(
                    key: const ValueKey('customer-add-cart'),
                    quantity: quantity!,
                    increment: increment,
                    minimum: minimum,
                    palette: FoodexPalette.wholesale,
                    label: revalidating
                        ? context.tr('b2b.product.revalidating')
                        : context.tr('customer.action.add_cart'),
                    onChanged: isAvailable && !revalidating
                        ? (value) {
                            if (availableQuantity != null &&
                                value > availableQuantity + .0001) {
                              ScaffoldMessenger.of(context).showSnackBar(
                                SnackBar(
                                  content: Text(
                                    context.tr(
                                          'b2b.product.quantity_stock',
                                    ) +
                                        ' ' +
                                        compactNumber(availableQuantity),
                                  ),
                                ),
                              );
                              return;
                            }
                            setState(() => quantity = value);
                          }
                        : null,
                    onPressed: isAvailable && !revalidating
                        ? () async {
                            final issue =
                                _quantityIssue(context, row, quantity!);
                            if (issue != null) {
                              ScaffoldMessenger.of(context).showSnackBar(
                                SnackBar(content: Text(issue)),
                              );
                              return;
                            }
                            await _addProduct(row);
                          }
                        : null,
                  ),
                  if (!isAvailable) ...[
                    const SizedBox(height: 8),
                    Text(
                      context.tr('b2b.product.stock_auto_refresh'),
                      key: const ValueKey('b2b-product-stock-auto-refresh'),
                      textAlign: TextAlign.center,
                      style: const TextStyle(
                        color: Color(0xFF6B7785),
                        fontSize: 11,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                  ],
                  const SizedBox(height: 15),
                  FoodexDetailAccordion(
                    title: context.tr('b2b.product.pack_details'),
                    body: context.tr('b2b.product.pack_size') +
                        ': ' +
                        (row['pack_size']?.toString() ?? '1') +
                        ' · ' +
                        context.tr('b2b.product.case_size') +
                        ': ' +
                        (row['case_size']?.toString() ?? '—') +
                        ' · ' +
                        context.tr('b2b.product.quantity_step') +
                        ': ' +
                        (row['ordering_increment']?.toString() ?? '1'),
                  ),
                  FoodexDetailAccordion(
                    title: context.tr('b2b.product.brand'),
                    body: brand.isNotEmpty
                        ? brand
                        : context.tr('b2b.product.not_specified'),
                  ),
                  FoodexDetailAccordion(
                    title: context.tr('b2b.product.category'),
                    body: category.isNotEmpty
                        ? category
                        : context.tr('b2b.product.not_specified'),
                  ),
                  FoodexDetailAccordion(
                    title: context.tr('b2b.product.description'),
                    body: description.isNotEmpty
                        ? description
                        : context.tr('b2b.product.no_description'),
                  ),
                ],
              );
            },
          ),
        ),
      ),
    );
  }
}

class _ProductMetaChip extends StatelessWidget {
  const _ProductMetaChip({
    required this.label,
    required this.value,
    super.key,
  });

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 11, vertical: 6),
        decoration: BoxDecoration(
          color: const Color(0xFFE9F7EE),
          borderRadius: BorderRadius.circular(999),
        ),
        child: Text(
          '$label: $value',
          style: const TextStyle(
            color: Color(0xFF006736),
            fontSize: 11,
            fontWeight: FontWeight.w900,
          ),
        ),
      );
}

class _WholesaleProductFailureInfo {
  const _WholesaleProductFailureInfo({
    required this.category,
    required this.messageKey,
    required this.forbiddenVisual,
    this.statusCode,
    this.supportReference,
  });

  final String category;
  final String messageKey;
  final bool forbiddenVisual;
  final int? statusCode;
  final String? supportReference;
}

_WholesaleProductFailureInfo _wholesaleProductFailureInfo(Object? error) {
  if (error is B2bApiException) {
    final status = error.statusCode;
    final supportReference =
        _safeWholesaleSupportReference(error.supportReference);

    if (status == 401) {
      return _WholesaleProductFailureInfo(
        category: 'unauthorized',
        messageKey: 'customer.error.session_expired',
        forbiddenVisual: false,
        statusCode: status,
        supportReference: supportReference,
      );
    }
    if (status == 403 || (status == null && error.code == 'not_authorized')) {
      return _WholesaleProductFailureInfo(
        category: 'forbidden',
        messageKey: 'customer.error.forbidden',
        forbiddenVisual: true,
        statusCode: status,
        supportReference: supportReference,
      );
    }
    if (status == 404) {
      return _WholesaleProductFailureInfo(
        category: 'not_found',
        messageKey: 'b2b.remote.not_found',
        forbiddenVisual: false,
        statusCode: status,
        supportReference: supportReference,
      );
    }
    if (status != null && status >= 500) {
      return _WholesaleProductFailureInfo(
        category: 'server_failure',
        messageKey: 'b2b.remote.error',
        forbiddenVisual: false,
        statusCode: status,
        supportReference: supportReference,
      );
    }
  }

  return const _WholesaleProductFailureInfo(
    category: 'network_or_client_failure',
    messageKey: 'b2b.remote.error',
    forbiddenVisual: false,
  );
}

String? _safeWholesaleSupportReference(String? value) {
  final reference = value?.trim();
  if (reference == null || reference.isEmpty || reference.length > 128) {
    return null;
  }
  return RegExp(r'^[A-Za-z0-9._:/-]+$').hasMatch(reference)
      ? reference
      : null;
}

class _PricingPanel extends StatelessWidget {
  const _PricingPanel({
    required this.row,
    required this.currency,
  });

  final Map<String, dynamic> row;
  final String currency;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: Color(0xFFF1F8F4),
          borderRadius: BorderRadius.circular(16),
        ),
        child: Column(
          children: [
            _PriceRow(
              label: context.tr('b2b.product.base_price'),
              value: money(
                row['base_wholesale_price'],
                currency: currency,
              ),
            ),
            const Divider(height: 18),
            _PriceRow(
              label: context.tr('b2b.product.account_price'),
              value: money(
                row['account_price'],
                currency: currency,
              ),
              emphasize: true,
            ),
            if (row['retail_reference_price'] != null) ...[
              const Divider(height: 18),
              _PriceRow(
                label: context.tr('b2b.product.retail_reference_price'),
                value: money(
                  row['retail_reference_price'],
                  currency: currency,
                ),
              ),
            ],
          ],
        ),
      );
}

class _PriceRow extends StatelessWidget {
  const _PriceRow({
    required this.label,
    required this.value,
    this.emphasize = false,
  });

  final String label;
  final String value;
  final bool emphasize;

  @override
  Widget build(BuildContext context) => Row(
        children: [
          Expanded(
            child: Text(
              label,
              style: const TextStyle(fontSize: 12),
            ),
          ),
          Text(
            value,
            style: TextStyle(
              color: emphasize
                  ? Color(0xFF078A43)
                  : Color(0xFF102033),
              fontSize: emphasize ? 17 : 13,
              fontWeight: FontWeight.w800,
            ),
          ),
        ],
      );
}

class _InfoPill extends StatelessWidget {
  const _InfoPill({
    required this.icon,
    required this.label,
  });

  final IconData icon;
  final String label;

  @override
  Widget build(BuildContext context) => Container(
        height: 48,
        padding: const EdgeInsets.symmetric(horizontal: 10),
        decoration: BoxDecoration(
          color: Color(0xFFF1F8F4),
          borderRadius: BorderRadius.circular(13),
        ),
        child: Row(
          children: [
            Icon(
              icon,
              size: 18,
              color: Color(0xFF078A43),
            ),
            const SizedBox(width: 7),
            Expanded(
              child: Text(
                label,
                style: const TextStyle(
                  fontSize: 11,
                  fontWeight: FontWeight.w700,
                ),
              ),
            ),
          ],
        ),
      );
}

class WholesaleCartDesignScreen extends StatefulWidget {
  const WholesaleCartDesignScreen({
    required this.location,
    required this.api,
    required this.commerceApi,
    super.key,
  });

  final String location;
  final B2bApi? api;
  final WholesaleCommerceApi? commerceApi;

  @override
  State<WholesaleCartDesignScreen> createState() =>
      _WholesaleCartDesignScreenState();
}

class _WholesaleCartDesignScreenState
    extends State<WholesaleCartDesignScreen> {
  late int storeId = wholesaleStoreId(widget.location);
  late Future<Object?> future = _load();

  Future<Object?> _load() {
    if (widget.commerceApi != null && storeId > 0) {
      return widget.commerceApi!.cart(storeId);
    }
    var endpoint = '/api/v1/cart';
    if (storeId > 0) {
      endpoint += '?store=' + storeId.toString();
    }
    return widget.api?.get(endpoint) ?? Future<Object?>.value(null);
  }

  Future<void> _update(int id, double value) async {
    if (widget.commerceApi == null) return;
    try {
      await widget.commerceApi!.updateItem(id, value);
      setState(() => future = _load());
    } catch (error) {
      if (mounted) {
        await showOperationalError(context, error);
      }
    }
  }

  @override
  Widget build(BuildContext context) => Directionality(
        textDirection: Directionality.of(context),
        child: Scaffold(
          backgroundColor: Color(0xFFF8FBF9),
          body: SafeArea(
            child: FutureBuilder<Object?>(
              future: future,
              builder: (context, snapshot) {
                if (snapshot.connectionState != ConnectionState.done) {
                  return const FoodexLoading(
                    key: ValueKey('b2b-loading'),
                  );
                }
                if (snapshot.hasError) {
                  return FoodexErrorState(
                    key: const ValueKey('b2b-error'),
                    message: 'تعذر تحميل سلة الجملة.',
                    onRetry: () =>
                        setState(() => future = _load()),
                  );
                }

                final cart = snapshot.data is Map
                    ? Map<String, dynamic>.from(snapshot.data as Map)
                    : <String, dynamic>{};
                if (storeId <= 0) {
                  storeId = intValue(cart['store_id']);
                }
                final rows = mapRows(cart['items']);

                return ListView(
                  padding: const EdgeInsets.fromLTRB(22, 8, 22, 24),
                  children: [
                    const FoodexTopBar(title: 'سلة الجملة'),
                    const SizedBox(height: 8),
                    Container(
                      height: 38,
                      padding:
                          const EdgeInsets.symmetric(horizontal: 12),
                      decoration: BoxDecoration(
                        color: Color(0xFFF1F8F4),
                        borderRadius: BorderRadius.circular(12),
                      ),
                      child: const Row(
                        children: [
                          Icon(
                            Icons.warehouse_outlined,
                            color:
                                Color(0xFF078A43),
                            size: 19,
                          ),
                          SizedBox(width: 8),
                          Text(
                            'متجر الجملة',
                            style: TextStyle(
                              fontWeight: FontWeight.w700,
                            ),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 10),
                    if (rows.isEmpty)
                      const FoodexEmptyState(
                        key: ValueKey('b2b-empty'),
                        title: 'السلة فارغة',
                        subtitle: 'أضف منتجات من كتالوج الجملة.',
                      )
                    else
                      ...rows.map((row) {
                        final product = row['product'] is Map
                            ? Map<String, dynamic>.from(
                                row['product'] as Map,
                              )
                            : row;
                        final id = intValue(row['id']);
                        final qty =
                            doubleValue(row['quantity'], 1);
                        final increment = doubleValue(
                          row['ordering_increment'],
                          1,
                        );
                        final minimum = doubleValue(
                          row['minimum_order_quantity'],
                          1,
                        );

                        return _CartLine(
                          name: product['name']?.toString() ??
                              row['name']?.toString() ??
                              '',
                          subtitle:
                              product['sku']?.toString() ?? '',
                          quantity: qty,
                          price:
                              row['line_total'] ?? row['unit_price'],
                          onMinus: widget.commerceApi == null
                              ? null
                              : () {
                                  final next =
                                      math.max(minimum, qty - increment);
                                  _update(id, next.toDouble());
                                },
                          onPlus: widget.commerceApi == null
                              ? null
                              : () => _update(id, qty + increment),
                        );
                      }),
                    const SizedBox(height: 14),
                    _CartTotalPanel(
                      subtotal: cart['subtotal'],
                      enabled: rows.isNotEmpty && storeId > 0,
                      onCheckout: () => Navigator.of(context)
                          .pushNamed(
                            '/b2b/checkout?store_id=' +
                                storeId.toString(),
                          ),
                    ),
                  ],
                );
              },
            ),
          ),
        ),
      );
}

class _CartLine extends StatelessWidget {
  const _CartLine({
    required this.name,
    required this.subtitle,
    required this.quantity,
    required this.price,
    required this.onMinus,
    required this.onPlus,
  });

  final String name;
  final String subtitle;
  final double quantity;
  final Object? price;
  final VoidCallback? onMinus;
  final VoidCallback? onPlus;

  @override
  Widget build(BuildContext context) => Container(
        margin: const EdgeInsets.only(bottom: 10),
        padding: const EdgeInsets.all(11),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(17),
          border: Border.all(color: const Color(0xFFE9E3F0)),
        ),
        child: Row(
          children: [
            const SizedBox(
              width: 66,
              height: 66,
              child: DecoratedBox(
                decoration: BoxDecoration(
                  color: Color(0xFFF1F8F4),
                  borderRadius:
                      BorderRadius.all(Radius.circular(14)),
                ),
                child: Icon(
                  Icons.inventory_2_outlined,
                  color: Color(0xFF078A43),
                ),
              ),
            ),
            const SizedBox(width: 11),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    name,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                  Text(
                    subtitle,
                    style: const TextStyle(
                      fontSize: 10,
                      color: Color(0xFF6B7785),
                    ),
                  ),
                  const SizedBox(height: 8),
                  Row(
                    children: [
                      _MiniStep(
                        icon: Icons.remove,
                        onTap: onMinus,
                      ),
                      Padding(
                        padding:
                            const EdgeInsets.symmetric(horizontal: 9),
                        child: Text(
                          compactNumber(quantity),
                          style: const TextStyle(
                            fontWeight: FontWeight.w800,
                          ),
                        ),
                      ),
                      _MiniStep(
                        icon: Icons.add,
                        onTap: onPlus,
                      ),
                      const Spacer(),
                      Text(
                        money(price),
                        style: const TextStyle(
                          color:
                              Color(0xFF078A43),
                          fontWeight: FontWeight.w800,
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),
          ],
        ),
      );
}

class _MiniStep extends StatelessWidget {
  const _MiniStep({
    required this.icon,
    required this.onTap,
  });

  final IconData icon;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) => Material(
        color: Color(0xFFF1F8F4),
        borderRadius: BorderRadius.circular(9),
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(9),
          child: SizedBox(
            width: 32,
            height: 32,
            child: Icon(icon, size: 17),
          ),
        ),
      );
}

class _CartTotalPanel extends StatelessWidget {
  const _CartTotalPanel({
    required this.subtotal,
    required this.enabled,
    required this.onCheckout,
  });

  final Object? subtotal;
  final bool enabled;
  final VoidCallback onCheckout;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: Color(0xFF006736),
          borderRadius: BorderRadius.circular(18),
        ),
        child: Column(
          children: [
            Row(
              children: [
                const Expanded(
                  child: Text(
                    'الإجمالي',
                    style: TextStyle(color: Colors.white70),
                  ),
                ),
                Text(
                  money(subtotal),
                  style: const TextStyle(
                    color: Colors.white,
                    fontSize: 20,
                    fontWeight: FontWeight.w900,
                  ),
                ),
              ],
            ),
            const SizedBox(height: 13),
            SizedBox(
              width: double.infinity,
              child: FilledButton(
                onPressed: enabled ? onCheckout : null,
                style: FilledButton.styleFrom(
                  backgroundColor: Colors.white,
                  foregroundColor:
                      Color(0xFF006736),
                ),
                child: const Text('إتمام الطلب'),
              ),
            ),
          ],
        ),
      );
}

class WholesaleCheckoutDesignScreen extends StatefulWidget {
  const WholesaleCheckoutDesignScreen({
    required this.location,
    required this.storefrontApi,
    required this.commerceApi,
    super.key,
  });

  final String location;
  final StorefrontApi? storefrontApi;
  final WholesaleCommerceApi? commerceApi;

  @override
  State<WholesaleCheckoutDesignScreen> createState() =>
      _WholesaleCheckoutDesignScreenState();
}

class _WholesaleCheckoutDesignScreenState
    extends State<WholesaleCheckoutDesignScreen> {
  late final int storeId = wholesaleStoreId(widget.location);
  late Future<_CheckoutPayload> future = _load();
  int? addressId;
  String? deliveryDate;
  String? paymentMethod;
  final note = TextEditingController();
  final coupon = TextEditingController();

  @override
  void dispose() {
    note.dispose();
    coupon.dispose();
    super.dispose();
  }

  Future<void> _openAddressBook() async {
    await Navigator.of(context).pushNamed(
      Uri(
        path: CustomerRoutePaths.b2bAddresses,
        queryParameters: storeId > 0
            ? <String, String>{'store_id': storeId.toString()}
            : null,
      ).toString(),
    );
    if (!mounted) return;
    setState(() {
      addressId = null;
      future = _load();
    });
  }

  Future<_CheckoutPayload> _load() async {
    final options = widget.storefrontApi == null
        ? const <String, dynamic>{}
        : await widget.storefrontApi!.b2bCheckoutOptions(storeId);
    final rawCart = widget.commerceApi == null
        ? null
        : await widget.commerceApi!.cart(storeId);
    final cart = rawCart is Map
        ? Map<String, dynamic>.from(rawCart)
        : <String, dynamic>{};

    return _CheckoutPayload(options: options, cart: cart);
  }

  @override
  Widget build(BuildContext context) => Directionality(
        textDirection: Directionality.of(context),
        child: Scaffold(
          backgroundColor: Color(0xFFF8FBF9),
          body: SafeArea(
            child: FutureBuilder<_CheckoutPayload>(
              future: future,
              builder: (context, snapshot) {
                if (snapshot.connectionState != ConnectionState.done) {
                  return const FoodexLoading();
                }
                if (snapshot.hasError) {
                  return FoodexErrorState(
                    message:
                        'تعذر تحميل خيارات إتمام الطلب.',
                    onRetry: () =>
                        setState(() => future = _load()),
                  );
                }

                final payload = snapshot.data ??
                    const _CheckoutPayload(
                      options: <String, dynamic>{},
                      cart: <String, dynamic>{},
                    );
                final data = payload.options;
                final cart = payload.cart;
                final addresses = mapRows(data['addresses']);
                final dates =
                    (data['delivery_dates'] as List? ??
                            const <Object>[])
                        .map((value) => value.toString())
                        .toList(growable: false);
                final methods =
                    (data['payment_methods'] as List? ??
                            const <Object>[])
                        .map((value) => value.toString())
                        .toList(growable: false);

                addressId ??= addresses.isEmpty
                    ? null
                    : intValue(addresses.first['id']);
                deliveryDate ??=
                    dates.isEmpty ? null : dates.first;
                paymentMethod ??=
                    methods.isEmpty ? null : methods.first;

                return ListView(
                  padding: const EdgeInsets.fromLTRB(22, 8, 22, 24),
                  children: [
                    const FoodexTopBar(title: 'إتمام الطلب'),
                    const SizedBox(height: 8),
                    const _CheckoutStepper(),
                    const SizedBox(height: 14),
                    const Text(
                      'عنوان التوصيل',
                      style: TextStyle(
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                    const SizedBox(height: 8),
                    if (addresses.isEmpty) ...[
                      const FoodexEmptyState(
                        title: 'لا يوجد عنوان',
                        subtitle:
                            'أضف عنوانًا لحساب الجملة قبل إتمام الطلب.',
                      ),
                      const SizedBox(height: 10),
                      FilledButton.icon(
                        key: const ValueKey(
                          'wholesale-checkout-add-address',
                        ),
                        onPressed: _openAddressBook,
                        icon: const Icon(Icons.add_location_alt_outlined),
                        label: const Text('إضافة عنوان'),
                      ),
                    ] else ...[
                      ...addresses.map(
                        (address) => _SelectCard(
                          selected: addressId ==
                              intValue(address['id']),
                          title:
                              address['label']?.toString() ??
                                  'العنوان',
                          subtitle: <Object?>[
                            address['line1'],
                            address['area'],
                            address['city'],
                          ]
                              .where(
                                (value) =>
                                    value != null &&
                                    value
                                        .toString()
                                        .trim()
                                        .isNotEmpty,
                              )
                              .map((value) => value.toString())
                              .join('، '),
                          onTap: () => setState(
                            () => addressId =
                                intValue(address['id']),
                          ),
                        ),
                      ),
                      Align(
                        alignment: AlignmentDirectional.centerStart,
                        child: TextButton.icon(
                          key: const ValueKey(
                            'wholesale-checkout-manage-addresses',
                          ),
                          onPressed: _openAddressBook,
                          icon: const Icon(Icons.edit_location_alt_outlined),
                          label: const Text('إدارة العناوين'),
                        ),
                      ),
                    ],
                    const SizedBox(height: 12),
                    DropdownButtonFormField<String>(
                      value: deliveryDate,
                      decoration: const InputDecoration(
                        labelText: 'تاريخ التوصيل',
                        prefixIcon:
                            Icon(Icons.calendar_today_outlined),
                      ),
                      items: dates
                          .map(
                            (date) => DropdownMenuItem(
                              value: date,
                              child: Text(date),
                            ),
                          )
                          .toList(growable: false),
                      onChanged: (value) =>
                          setState(() => deliveryDate = value),
                    ),
                    const SizedBox(height: 12),
                    DropdownButtonFormField<String>(
                      value: paymentMethod,
                      decoration: const InputDecoration(
                        labelText: 'طريقة الدفع',
                        prefixIcon:
                            Icon(Icons.payments_outlined),
                      ),
                      items: methods
                          .map(
                            (method) => DropdownMenuItem(
                              value: method,
                              child: Text(
                                paymentLabel(method),
                              ),
                            ),
                          )
                          .toList(growable: false),
                      onChanged: (value) =>
                          setState(() => paymentMethod = value),
                    ),
                    const SizedBox(height: 12),
                    TextField(
                      controller: coupon,
                      textCapitalization: TextCapitalization.characters,
                      decoration: const InputDecoration(
                        labelText: 'كود الكوبون - اختياري',
                        prefixIcon: Icon(Icons.confirmation_number_outlined),
                      ),
                    ),
                    const SizedBox(height: 12),
                    TextField(
                      controller: note,
                      minLines: 3,
                      maxLines: 4,
                      decoration: const InputDecoration(
                        labelText: 'ملاحظات الطلب',
                        hintText:
                            'أضف ملاحظات للتجهيز أو التسليم',
                      ),
                    ),
                    const SizedBox(height: 16),
                    _CheckoutSummary(cart: cart),
                    const SizedBox(height: 18),
                    SizedBox(
                      height: 54,
                      child: FilledButton(
                        style: FilledButton.styleFrom(
                          backgroundColor:
                              Color(0xFF078A43),
                        ),
                        onPressed: widget.commerceApi == null ||
                                addressId == null ||
                                paymentMethod == null
                            ? null
                            : () async {
                                try {
                                  final key = 'fdx-b2b-' +
                                      storeId.toString() +
                                      '-' +
                                      DateTime.now()
                                          .microsecondsSinceEpoch
                                          .toString();
                                  final result = await widget
                                      .commerceApi!
                                      .checkout(
                                    storeId: storeId,
                                    addressId: addressId!,
                                    paymentMethod: paymentMethod!,
                                    requestedDeliveryDate:
                                        deliveryDate,
                                    note: note.text,
                                    couponCode: coupon.text,
                                    idempotencyKey: key,
                                  );
                                  if (!context.mounted) return;
                                  final orderId = result is Map
                                      ? intValue(result['id'])
                                      : 0;
                                  Navigator.of(context)
                                      .pushReplacementNamed(
                                    orderId > 0
                                        ? '/b2b/orders/' +
                                            orderId.toString()
                                        : '/b2b/orders',
                                  );
                                } catch (error) {
                                  if (context.mounted) {
                                    await showOperationalError(
                                      context,
                                      error,
                                    );
                                  }
                                }
                              },
                        child: const Text('تأكيد الطلب'),
                      ),
                    ),
                  ],
                );
              },
            ),
          ),
        ),
      );
}

class _CheckoutPayload {
  const _CheckoutPayload({
    required this.options,
    required this.cart,
  });

  final Map<String, dynamic> options;
  final Map<String, dynamic> cart;
}

class _CheckoutSummary extends StatelessWidget {
  const _CheckoutSummary({required this.cart});

  final Map<String, dynamic> cart;

  @override
  Widget build(BuildContext context) {
    final items = mapRows(cart['items']);
    final currency = cart['currency']?.toString() ?? 'EGP';
    final subtotal = cart['subtotal'];
    final delivery = cart['delivery_total'] ?? cart['delivery_fee'];
    final grandTotal = cart['grand_total'] ?? cart['total'] ?? subtotal;

    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: const Color(0xFFE9E3F0)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text(
            'ملخص الطلب',
            style: TextStyle(
              fontSize: 15,
              fontWeight: FontWeight.w800,
            ),
          ),
          if (items.isNotEmpty) ...[
            const SizedBox(height: 10),
            ...items.take(3).map(
              (item) => Padding(
                padding: const EdgeInsets.only(bottom: 7),
                child: Row(
                  children: [
                    Expanded(
                      child: Text(
                        item['name']?.toString() ??
                            (item['product'] is Map
                                ? (item['product'] as Map)['name']
                                        ?.toString() ??
                                    'منتج'
                                : 'منتج'),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(fontSize: 12),
                      ),
                    ),
                    const SizedBox(width: 8),
                    Text(
                      '× ' +
                          (item['quantity']?.toString() ?? '1'),
                      style: const TextStyle(
                        fontSize: 11,
                        color: Color(0xFF6B7785),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ],
          const Divider(height: 18),
          _SummaryRow(
            label: 'الإجمالي الفرعي',
            value: money(subtotal, currency: currency),
          ),
          if (delivery != null)
            _SummaryRow(
              label: 'التوصيل',
              value: money(delivery, currency: currency),
            ),
          const SizedBox(height: 7),
          _SummaryRow(
            label: 'الإجمالي',
            value: money(grandTotal, currency: currency),
            strong: true,
          ),
          const SizedBox(height: 7),
          const Text(
            'يتم التحقق من السعر والحد الأدنى والكميات مرة أخرى على الخادم عند التأكيد.',
            style: TextStyle(
              fontSize: 10,
              height: 1.4,
              color: Color(0xFF6B7785),
            ),
          ),
        ],
      ),
    );
  }
}

class _SummaryRow extends StatelessWidget {
  const _SummaryRow({
    required this.label,
    required this.value,
    this.strong = false,
  });

  final String label;
  final String value;
  final bool strong;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 2),
        child: Row(
          children: [
            Expanded(
              child: Text(
                label,
                style: TextStyle(
                  fontSize: strong ? 13 : 11,
                  fontWeight:
                      strong ? FontWeight.w800 : FontWeight.w500,
                ),
              ),
            ),
            Text(
              value,
              style: TextStyle(
                color: strong
                    ? const Color(0xFF078A43)
                    : const Color(0xFF102033),
                fontSize: strong ? 16 : 12,
                fontWeight: FontWeight.w800,
              ),
            ),
          ],
        ),
      );
}

class _CheckoutStepper extends StatelessWidget {
  const _CheckoutStepper();

  @override
  Widget build(BuildContext context) => const Row(
        children: [
          Expanded(
            child: _StepDot(
              number: '1',
              label: 'العنوان',
              active: true,
            ),
          ),
          Expanded(
            child: Divider(
              color: Color(0xFF92D853),
            ),
          ),
          Expanded(
            child: _StepDot(
              number: '2',
              label: 'التوصيل',
              active: true,
            ),
          ),
          Expanded(
            child: Divider(
              color: Color(0xFF92D853),
            ),
          ),
          Expanded(
            child: _StepDot(
              number: '3',
              label: 'الدفع',
              active: false,
            ),
          ),
        ],
      );
}

class _StepDot extends StatelessWidget {
  const _StepDot({
    required this.number,
    required this.label,
    required this.active,
  });

  final String number;
  final String label;
  final bool active;

  @override
  Widget build(BuildContext context) => Column(
        children: [
          CircleAvatar(
            radius: 16,
            backgroundColor: active
                ? Color(0xFF078A43)
                : const Color(0xFFE7E3EA),
            child: Text(
              number,
              style: TextStyle(
                color: active
                    ? Colors.white
                    : Color(0xFF6B7785),
                fontSize: 11,
              ),
            ),
          ),
          const SizedBox(height: 4),
          Text(
            label,
            style: const TextStyle(fontSize: 9),
          ),
        ],
      );
}

class _SelectCard extends StatelessWidget {
  const _SelectCard({
    required this.selected,
    required this.title,
    required this.subtitle,
    required this.onTap,
  });

  final bool selected;
  final String title;
  final String subtitle;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(bottom: 8),
        child: Material(
          color: Colors.white,
          borderRadius: BorderRadius.circular(16),
          child: InkWell(
            onTap: onTap,
            borderRadius: BorderRadius.circular(16),
            child: Container(
              padding: const EdgeInsets.all(13),
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(16),
                border: Border.all(
                  color: selected
                      ? Color(0xFF078A43)
                      : const Color(0xFFE4E0E7),
                  width: selected ? 1.6 : 1,
                ),
              ),
              child: Row(
                children: [
                  Icon(
                    selected
                        ? Icons.radio_button_checked
                        : Icons.radio_button_off,
                    color: selected
                        ? Color(0xFF078A43)
                        : Color(0xFF6B7785),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Column(
                      crossAxisAlignment:
                          CrossAxisAlignment.start,
                      children: [
                        Text(
                          title,
                          style: const TextStyle(
                            fontWeight: FontWeight.w800,
                          ),
                        ),
                        const SizedBox(height: 3),
                        Text(
                          subtitle,
                          style: const TextStyle(
                            color:
                                Color(0xFF6B7785),
                            fontSize: 11,
                          ),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      );
}

class WholesaleOrdersDesignScreen extends StatefulWidget {
  const WholesaleOrdersDesignScreen({
    required this.api,
    super.key,
  });

  final B2bApi? api;

  @override
  State<WholesaleOrdersDesignScreen> createState() =>
      _WholesaleOrdersDesignScreenState();
}

class _WholesaleOrdersDesignScreenState
    extends State<WholesaleOrdersDesignScreen> {
  String status = 'all';
  late Future<Object?> future = _load();

  Future<Object?> _load() {
    var endpoint = '/api/v1/b2b/orders';
    if (status != 'all') {
      endpoint += '?status=' + status;
    }
    return widget.api?.get(endpoint) ??
        Future<Object?>.value(const {'data': <Object>[]});
  }

  void _select(String value) {
    setState(() {
      status = value;
      future = _load();
    });
  }

  void _refresh() {
    setState(() {
      future = _load();
    });
  }

  @override
  Widget build(BuildContext context) => Directionality(
        textDirection: Directionality.of(context),
        child: Scaffold(
          backgroundColor: const Color(0xFFF8FBF9),
          body: SafeArea(
            child: FutureBuilder<Object?>(
              future: future,
              builder: (context, snapshot) {
                if (snapshot.connectionState != ConnectionState.done) {
                  return const FoodexLoading(
                    key: ValueKey('b2b-loading'),
                  );
                }
                if (snapshot.hasError) {
                  return FoodexErrorState(
                    key: const ValueKey('b2b-error'),
                    message: 'تعذر تحميل الطلبات.',
                    onRetry: _refresh,
                  );
                }

                final rows = dataRows(snapshot.data);
                return ListView(
                  key: rows.isEmpty
                      ? null
                      : const ValueKey('b2b-orders-data'),
                  padding: const EdgeInsets.fromLTRB(22, 8, 22, 24),
                  children: [
                    Row(
                      children: [
                        const Expanded(child: FoodexTopBar(title: 'طلباتي')),
                        IconButton(
                          key: const ValueKey('b2b-orders-refresh'),
                          tooltip: 'تحديث',
                          onPressed: _refresh,
                          icon: const Icon(Icons.refresh_rounded),
                        ),
                      ],
                    ),
                    const SizedBox(height: 10),
                    _OrderTabs(
                      selected: status,
                      onChanged: _select,
                    ),
                    const SizedBox(height: 12),
                    if (rows.isEmpty)
                      const FoodexEmptyState(
                        key: ValueKey('b2b-empty'),
                        title: 'لا توجد طلبات',
                        subtitle: 'لا توجد طلبات بهذه الحالة.',
                      )
                    else
                      ...rows.map(
                        (row) => _OrderCard(row: row),
                      ),
                  ],
                );
              },
            ),
          ),
        ),
      );
}

class WholesaleOrderDetailsDesignScreen extends StatefulWidget {
  const WholesaleOrderDetailsDesignScreen({
    required this.location,
    required this.api,
    super.key,
  });

  final String location;
  final B2bApi? api;

  @override
  State<WholesaleOrderDetailsDesignScreen> createState() =>
      _WholesaleOrderDetailsDesignScreenState();
}

class _WholesaleOrderDetailsDesignScreenState
    extends State<WholesaleOrderDetailsDesignScreen> {
  late final int orderId = _orderId(widget.location);
  late Future<Object?> future = _load();

  static int _orderId(String location) {
    final segments = Uri.parse(location).pathSegments;
    final index = segments.indexOf('orders');
    if (index < 0 || segments.length <= index + 1) return 0;
    return int.tryParse(segments[index + 1]) ?? 0;
  }

  Future<Object?> _load() {
    if (orderId <= 0 || widget.api == null) {
      return Future<Object?>.value(null);
    }
    return widget.api!.get('/api/v1/b2b/orders/' + orderId.toString());
  }

  void _refresh() {
    setState(() {
      future = _load();
    });
  }

  @override
  Widget build(BuildContext context) => Directionality(
        textDirection: Directionality.of(context),
        child: Scaffold(
          backgroundColor: const Color(0xFFF8FBF9),
          body: SafeArea(
            child: Column(
              children: [
                Padding(
                  padding: const EdgeInsets.fromLTRB(22, 8, 22, 0),
                  child: Row(
                    children: [
                      IconButton(
                        key: const ValueKey('b2b-order-back'),
                        onPressed: () => Navigator.of(context)
                            .pushReplacementNamed('/b2b/orders'),
                        icon: const Icon(Icons.arrow_back_rounded),
                      ),
                      const Expanded(
                        child: FoodexTopBar(title: 'تفاصيل طلب الجملة'),
                      ),
                      IconButton(
                        key: const ValueKey('b2b-order-refresh'),
                        tooltip: 'تحديث',
                        onPressed: _refresh,
                        icon: const Icon(Icons.refresh_rounded),
                      ),
                    ],
                  ),
                ),
                Expanded(
                  child: FutureBuilder<Object?>(
                    future: future,
                    builder: (context, snapshot) {
                      if (snapshot.connectionState != ConnectionState.done) {
                        return const FoodexLoading(
                          key: ValueKey('b2b-order-detail-loading'),
                        );
                      }
                      if (snapshot.hasError) {
                        return FoodexErrorState(
                          key: const ValueKey('b2b-order-detail-error'),
                          message: 'تعذر تحميل تفاصيل طلب الجملة.',
                          onRetry: _refresh,
                        );
                      }

                      final raw = snapshot.data;
                      final order = raw is Map
                          ? Map<String, dynamic>.from(raw)
                          : <String, dynamic>{};
                      if (order.isEmpty) {
                        return const FoodexEmptyState(
                          key: ValueKey('b2b-order-detail-empty'),
                          title: 'الطلب غير متاح',
                          subtitle: 'تعذر العثور على بيانات طلب الجملة.',
                        );
                      }

                      final items = mapRows(order['items']);
                      // Customer order history is authoritative only when supplied
                      // by the backend timeline contract. Do not synthesize or
                      // fall back to raw operational status history here.
                      final timeline = mapRows(order['timeline']);
                      final currency =
                          order['currency']?.toString() ?? 'KWD';
                      final total = order['grand_total'] ?? order['total'];
                      final storeName =
                          order['store_name']?.toString() ??
                          (order['store'] is Map
                              ? (order['store'] as Map)['name']?.toString()
                              : null) ??
                          '-';
                      final payment = paymentLabel(
                        order['payment_method']?.toString() ?? '-',
                      );
                      final address = order['delivery_address'] is Map
                          ? Map<String, dynamic>.from(
                              order['delivery_address'] as Map,
                            )
                          : null;

                      return ListView(
                        key: const ValueKey('b2b-order-detail'),
                        padding: const EdgeInsets.fromLTRB(22, 12, 22, 24),
                        children: [
                          _OrderDetailCard(
                            title: order['order_number']?.toString() ??
                                '#' + orderId.toString(),
                            rows: [
                              (
                                'الحالة',
                                orderStatusLabel(
                                  order['status']?.toString() ?? '',
                                ),
                              ),
                              ('المتجر', storeName),
                              ('الإجمالي', money(total, currency: currency)),
                              ('الدفع', payment),
                            ],
                          ),
                          if (address != null) ...[
                            const SizedBox(height: 12),
                            _OrderDetailCard(
                              title: 'عنوان التوصيل',
                              rows: _deliveryAddressRows(address),
                            ),
                          ],
                          if (timeline.isNotEmpty) ...[
                            const SizedBox(height: 12),
                            const Text(
                              'مسار الطلب',
                              style: TextStyle(
                                fontSize: 15,
                                fontWeight: FontWeight.w800,
                              ),
                            ),
                            const SizedBox(height: 8),
                            Column(
                              children: timeline.asMap().entries.map((entry) {
                                final index = entry.key;
                                final event = entry.value;
                                final stage =
                                    event['stage']?.toString() ?? 'unknown';
                                final occurredAt =
                                    event['occurred_at']?.toString() ?? '';
                                final driverName =
                                    event['driver_name']?.toString() ?? '';
                                final reasonCode =
                                    event['reason_code']?.toString() ?? '';

                                return ListTile(
                                  key: ValueKey(
                                    'b2b-timeline-' +
                                        index.toString() +
                                        '-' +
                                        stage,
                                  ),
                                  contentPadding: EdgeInsets.zero,
                                  leading:
                                      const Icon(Icons.timeline_rounded),
                                  title: Text(orderStatusLabel(stage)),
                                  subtitle: Column(
                                    crossAxisAlignment:
                                        CrossAxisAlignment.start,
                                    children: [
                                      if (occurredAt.isNotEmpty)
                                        Text(occurredAt),
                                      if (driverName.isNotEmpty)
                                        Text(driverName),
                                      if (reasonCode.isNotEmpty)
                                        Text(
                                          failureReasonLabel(reasonCode),
                                        ),
                                    ],
                                  ),
                                );
                              }).toList(growable: false),
                            ),
                          ],
                          if (items.isNotEmpty) ...[
                            const SizedBox(height: 12),
                            const Text(
                              'الأصناف',
                              style: TextStyle(
                                fontSize: 15,
                                fontWeight: FontWeight.w800,
                              ),
                            ),
                            const SizedBox(height: 8),
                            ...items.map(
                              (item) => _OrderDetailCard(
                                title: item['name']?.toString() ??
                                    item['name_snapshot']?.toString() ??
                                    'منتج',
                                rows: [
                                  (
                                    'الكمية',
                                    item['quantity']?.toString() ?? '-',
                                  ),
                                  (
                                    'سعر الوحدة',
                                    money(
                                      item['unit_price'],
                                      currency: currency,
                                    ),
                                  ),
                                  (
                                    'الإجمالي',
                                    money(
                                      item['line_total'],
                                      currency: currency,
                                    ),
                                  ),
                                ],
                              ),
                            ),
                          ],
                        ],
                      );
                    },
                  ),
                ),
              ],
            ),
          ),
        ),
      );
}

List<(String, String)> _deliveryAddressRows(Map<String, dynamic> address) {
  final rows = <(String, String)>[];
  void add(String label, String key) {
    final value = address[key]?.toString().trim() ?? '';
    if (value.isNotEmpty) rows.add((label, value));
  }

  add('الاسم', 'recipient_name');
  add('العنوان', 'line1');
  add('تفاصيل العنوان', 'line2');
  add('المنطقة', 'area');
  add('المدينة', 'city');
  add('الدولة', 'country_code');

  return rows.isEmpty ? const [('العنوان', '-')] : rows;
}

class _OrderDetailCard extends StatelessWidget {
  const _OrderDetailCard({
    required this.title,
    required this.rows,
  });

  final String title;
  final List<(String, String)> rows;

  @override
  Widget build(BuildContext context) => Container(
        margin: const EdgeInsets.only(bottom: 10),
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: Colors.white,
          border: Border.all(color: const Color(0xFFE9E3F0)),
          borderRadius: BorderRadius.circular(17),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              title,
              style: const TextStyle(fontWeight: FontWeight.w800),
            ),
            const SizedBox(height: 10),
            ...rows.map(
              (row) => Padding(
                padding: const EdgeInsets.only(bottom: 6),
                child: Row(
                  children: [
                    Expanded(
                      child: Text(
                        row.$1,
                        style: const TextStyle(
                          color: Color(0xFF6B7785),
                          fontSize: 11,
                        ),
                      ),
                    ),
                    Text(
                      row.$2,
                      style: const TextStyle(fontWeight: FontWeight.w700),
                    ),
                  ],
                ),
              ),
            ),
          ],
        ),
      );
}

class _OrderTabs extends StatelessWidget {
  const _OrderTabs({
    required this.selected,
    required this.onChanged,
  });

  final String selected;
  final ValueChanged<String> onChanged;

  @override
  Widget build(BuildContext context) {
    const values = <(String, String)>[
      ('all', 'الكل'),
      ('pending', 'جديد'),
      ('preparing', 'قيد التجهيز'),
      ('out_for_delivery', 'قيد التوصيل'),
      ('delivered', 'مكتمل'),
    ];
    return Wrap(
      spacing: 7,
      runSpacing: 7,
      children: values
          .map(
            (item) => ChoiceChip(
              label: Text(item.$2),
              selected: selected == item.$1,
              onSelected: (_) => onChanged(item.$1),
            ),
          )
          .toList(growable: false),
    );
  }
}

class _OrderCard extends StatelessWidget {
  const _OrderCard({required this.row});

  final Map<String, dynamic> row;

  @override
  Widget build(BuildContext context) {
    final id = intValue(row['id']);
    final store = row['store'];
    final storeName = row['store_name']?.toString() ??
        (store is Map ? store['name']?.toString() : null) ??
        '';
    final currency = row['currency']?.toString() ?? 'KWD';
    final total = row['grand_total'] ?? row['total'];
    final totalLabel =
        total == null ? '—' : total.toString() + ' ' + currency;

    return Material(
      key: ValueKey('b2b-order-row-' + id.toString()),
      color: Colors.white,
      borderRadius: BorderRadius.circular(17),
      child: InkWell(
        onTap: id <= 0
            ? null
            : () => Navigator.of(context)
                .pushNamed('/b2b/orders/' + id.toString()),
        borderRadius: BorderRadius.circular(17),
        child: Container(
          margin: const EdgeInsets.only(bottom: 10),
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
            border: Border.all(color: const Color(0xFFE9E3F0)),
            borderRadius: BorderRadius.circular(17),
          ),
          child: Row(
            children: [
              Container(
                width: 46,
                height: 46,
                decoration: BoxDecoration(
                  color: const Color(0xFFF1F8F4),
                  borderRadius: BorderRadius.circular(13),
                ),
                child: const Icon(
                  Icons.receipt_long_outlined,
                  color: Color(0xFF078A43),
                ),
              ),
              const SizedBox(width: 11),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      row['order_number']?.toString() ??
                          '#' + (row['id']?.toString() ?? ''),
                      style: const TextStyle(
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                    if (storeName.isNotEmpty) ...[
                      const SizedBox(height: 4),
                      Text(
                        storeName,
                        style: const TextStyle(
                          fontSize: 11,
                          color: Color(0xFF6B7785),
                        ),
                      ),
                    ],
                    const SizedBox(height: 4),
                    Text(
                      row['created_at']?.toString() ?? '',
                      style: const TextStyle(
                        fontSize: 10,
                        color: Color(0xFF6B7785),
                      ),
                    ),
                    const SizedBox(height: 5),
                    Text(
                      totalLabel,
                      style: const TextStyle(
                        color: Color(0xFF078A43),
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                  ],
                ),
              ),
              _StatusPill(
                status: row['status']?.toString() ?? '',
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _StatusPill extends StatelessWidget {
  const _StatusPill({required this.status});

  final String status;

  @override
  Widget build(BuildContext context) {
    final delivered = status == 'delivered';
    final color = delivered
        ? const Color(0xFF078A43)
        : const Color(0xFFE58B22);
    return Container(
      padding:
          const EdgeInsets.symmetric(horizontal: 9, vertical: 5),
      decoration: BoxDecoration(
        color: color.withOpacity(.1),
        borderRadius: BorderRadius.circular(999),
      ),
      child: Text(
        orderStatusLabel(status),
        style: TextStyle(
          color: color,
          fontSize: 10,
          fontWeight: FontWeight.w800,
        ),
      ),
    );
  }
}

String paymentLabel(String method) {
  switch (method) {
    case 'cash_on_delivery':
      return 'الدفع عند الاستلام';
    case 'account_credit':
      return 'الرصيد الائتماني';
    default:
      return method;
  }
}

String orderStatusLabel(String status) {
  switch (status) {
    case 'pending':
      return 'جديد';
    case 'placed':
      return 'تم إنشاء الطلب';
    case 'confirmed':
      return 'تم التأكيد';
    case 'preparing':
      return 'جاري التجهيز';
    case 'ready':
      return 'جاهز';
    case 'driver_assigned':
    case 'assigned':
      return 'تم تعيين السائق';
    case 'accepted':
      return 'قبل السائق الطلب';
    case 'picked_up':
      return 'استلم السائق الطلب';
    case 'out_for_delivery':
      return 'خرج للتوصيل';
    case 'delivered':
      return 'تم التسليم';
    case 'cancelled':
      return 'ملغي';
    case 'failed':
      return 'تعذر التسليم';
    default:
      return status.replaceAll('_', ' ');
  }
}

String failureReasonLabel(String reason) {
  switch (reason) {
    case 'customer_no_answer':
      return 'تعذر التواصل مع العميل';
    case 'wrong_address':
      return 'العنوان غير صحيح';
    case 'customer_refused':
      return 'رفض العميل الاستلام';
    case 'customer_absent':
      return 'العميل غير موجود';
    case 'payment_issue':
      return 'مشكلة في الدفع';
    case 'order_issue':
      return 'مشكلة في الطلب';
    case 'other':
      return 'سبب آخر';
    default:
      return reason.replaceAll('_', ' ');
  }
}
