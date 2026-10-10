// ignore_for_file: prefer_interpolation_to_compose_strings, deprecated_member_use

import 'dart:async';
import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';

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
import '../../core/theme/customer_ui_v3_tokens.dart';
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
    extends State<WholesaleHomeDesignScreen> with WidgetsBindingObserver {
  final search = TextEditingController();
  final campaignPopups = CustomerNotificationCampaignPopupService();
  final liveAds = CustomerLiveAdService();
  late final int storeId = wholesaleStoreId(widget.location);
  late Future<Map<String, dynamic>> future = _load();
  bool _liveAdScheduled = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed && mounted) {
      setState(() => future = _load(search.text));
    }
  }

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
    WidgetsBinding.instance.removeObserver(this);
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
                      onCta: storeId > 0
                          ? () => Navigator.of(context).pushNamed(
                                Uri(
                                  path: CustomerRoutePaths.b2bProducts,
                                  queryParameters: <String, String>{
                                    'channel': 'wholesale',
                                    'store_id': storeId.toString(),
                                  },
                                ).toString(),
                              )
                          : null,
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
                      gridMode: true,
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
                  gridMode: true,
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
                      onBusinessDashboard:
                          widget.session.isAuthenticated &&
                                  (widget.session
                                          .allowsChannel(CustomerChannel.b2b) ||
                                      widget.session.b2bRetailStoreId != null)
                              ? () => Navigator.of(context).pushNamed(
                                    Uri(
                                      path: CustomerRoutePaths.b2bDashboard,
                                      queryParameters: <String, String>{
                                        'channel': 'wholesale',
                                        if (storeId > 0)
                                          'store_id': storeId.toString(),
                                      },
                                    ).toString(),
                                  )
                              : null,
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
    this.onBusinessDashboard,
    this.logoUrl,
    this.address,
  });

  final String title;
  final String? logoUrl;
  final String? address;
  final VoidCallback onCart;
  final VoidCallback? onBusinessDashboard;
  final FoodexPalette palette;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.fromLTRB(16, 14, 16, 18),
        color: palette.primaryDark,
        child: Row(
          children: [
            if (onBusinessDashboard != null) ...[
              _BusinessDashboardHeaderButton(
                onTap: onBusinessDashboard!,
                palette: palette,
              ),
              const SizedBox(width: 8),
            ],
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

class _BusinessDashboardHeaderButton extends StatelessWidget {
  const _BusinessDashboardHeaderButton({
    required this.onTap,
    required this.palette,
  });

  final VoidCallback onTap;
  final FoodexPalette palette;

  @override
  Widget build(BuildContext context) => Material(
        color: Colors.transparent,
        borderRadius: BorderRadius.circular(14),
        child: InkWell(
          key: const ValueKey('wholesale-business-dashboard-button'),
          onTap: onTap,
          borderRadius: BorderRadius.circular(14),
          child: Ink(
            width: 82,
            height: 62,
            padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 6),
            decoration: BoxDecoration(
              gradient: LinearGradient(
                begin: Alignment.topRight,
                end: Alignment.bottomLeft,
                colors: [
                  Color.lerp(palette.accent, Colors.white, .18)!,
                  Color.lerp(palette.accent, Colors.white, .42)!,
                ],
              ),
              borderRadius: BorderRadius.circular(14),
              border: Border.all(
                color: Colors.white.withOpacity(.30),
              ),
              boxShadow: [
                BoxShadow(
                  color: Colors.black.withOpacity(.10),
                  blurRadius: 8,
                  offset: const Offset(0, 2),
                ),
              ],
            ),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Icon(
                  Icons.grid_view_rounded,
                  size: 23,
                  color: palette.primaryDark,
                ),
                const SizedBox(height: 2),
                Text(
                  context.tr('b2b.dashboard.title'),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  textAlign: TextAlign.center,
                  style: TextStyle(
                    color: palette.primaryDark,
                    fontSize: 10,
                    height: 1.05,
                    fontWeight: FontWeight.w900,
                  ),
                ),
              ],
            ),
          ),
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
    this.onCta,
  });

  final String title;
  final String cta;
  final String? imageUrl;
  final FoodexPalette palette;
  final VoidCallback? onCta;

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
                      onTap: onCta,
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
    this.onTap,
  });

  final String label;
  final FoodexPalette palette;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) => Align(
        alignment: AlignmentDirectional.centerStart,
        child: Material(
          color: palette.accent,
          borderRadius: BorderRadius.circular(999),
          child: InkWell(
            key: const ValueKey('wholesale-home-open-catalog'),
            onTap: onTap,
            borderRadius: BorderRadius.circular(999),
            child: Padding(
              padding:
                  const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
              child: Text(
                label,
                style: TextStyle(
                  color: palette.primaryDark,
                  fontSize: 11,
                  fontWeight: FontWeight.w800,
                ),
              ),
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
    required this.gridMode,
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
  final bool gridMode;
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
        crossAxisCount: gridMode ? 2 : 1,
        crossAxisSpacing: gridMode ? 10 : 0,
        mainAxisSpacing: 12,
        mainAxisExtent: showCommerceActions
            ? (gridMode
                ? (width < 360 ? 424 : 438)
                : (width < 360 ? 350 : 370))
            : (gridMode
                ? (width < 360 ? 286 : 306)
                : (width < 360 ? 260 : 280)),
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
    extends State<WholesaleCatalogDesignScreen> with WidgetsBindingObserver {
  final search = TextEditingController();
  late final int storeId = wholesaleStoreId(widget.location);
  late Future<Map<String, dynamic>> future = _load();
  int? selectedCategoryId;
  String sortMode = 'default';
  bool gridMode = true;
  int visibleLimit = 24;
  bool stale = false;
  Map<String, dynamic>? _lastPayload;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed && mounted) {
      _reload(resetLimit: false);
    }
  }

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
    WidgetsBinding.instance.removeObserver(this);
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
          final localeCode = Localizations.localeOf(context).languageCode;
          for (final row in categorySources) {
            final id = intValue(row['category_id']);
            if (id <= 0 || categoryMap.containsKey(id)) continue;
            final localizedName =
                row['category_name_$localeCode']?.toString().trim();
            final fallbackName = row['category_name']?.toString().trim();
            categoryMap[id] = {
              'id': id,
              'name': localizedName?.isNotEmpty == true
                  ? localizedName
                  : (fallbackName?.isNotEmpty == true
                      ? fallbackName
                      : context.tr('b2b.catalog.category') + ' $id'),
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
                    child: Column(
                      children: [
                        Row(
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
                                isExpanded: true,
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
                            IconButton(
                              key: const ValueKey('wholesale-catalog-refresh'),
                              tooltip: context.tr('b2b.catalog.refresh'),
                              onPressed: () => _reload(resetLimit: false),
                              icon: const Icon(Icons.refresh_rounded),
                            ),
                          ],
                        ),
                        const SizedBox(height: 6),
                        Row(
                          children: [
                            Expanded(
                              child: Text(
                                '${visibleRows.length} ' +
                                    context.tr('b2b.catalog.products_count'),
                                key: const ValueKey('wholesale-catalog-count'),
                                style: TextStyle(
                                  color: palette.muted,
                                  fontSize: 11,
                                  fontWeight: FontWeight.w700,
                                ),
                              ),
                            ),
                            IconButton(
                              key: const ValueKey(
                                'wholesale-catalog-grid-view',
                              ),
                              tooltip: context.tr('b2b.catalog.grid_view'),
                              isSelected: gridMode,
                              selectedIcon: Icon(
                                Icons.grid_view_rounded,
                                color: palette.primary,
                              ),
                              onPressed: () => setState(() => gridMode = true),
                              icon: const Icon(Icons.grid_view_outlined),
                            ),
                            IconButton(
                              key: const ValueKey(
                                'wholesale-catalog-list-view',
                              ),
                              tooltip: context.tr('b2b.catalog.list_view'),
                              isSelected: !gridMode,
                              selectedIcon: Icon(
                                Icons.view_list_rounded,
                                color: palette.primary,
                              ),
                              onPressed: () => setState(() => gridMode = false),
                              icon: const Icon(Icons.view_list_outlined),
                            ),
                          ],
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
                    gridMode: gridMode,
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
    extends State<WholesaleProductDetailsDesignScreen>
    with WidgetsBindingObserver {
  late final int storeId = wholesaleStoreId(widget.location);
  late final int productId = productIdFromLocation(widget.location);
  late Future<Object?> future = _loadProduct();
  double? quantity;
  Map<String, dynamic>? _lastProduct;
  Timer? _stockRefreshTimer;
  bool stale = false;
  bool revalidating = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed && mounted) {
      _retry();
    }
  }

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
    WidgetsBinding.instance.removeObserver(this);
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
                  Container(
                    key: const ValueKey('b2b-product-detail-header'),
                    decoration: BoxDecoration(
                      color: FoodexPalette.wholesale.primaryDark,
                      borderRadius: BorderRadius.circular(18),
                    ),
                    child: DefaultTextStyle.merge(
                      style: const TextStyle(color: Colors.white),
                      child: IconTheme(
                        data: const IconThemeData(color: Colors.white),
                        child: FoodexTopBar(
                          title: context.tr('customer.product.title'),
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
                      ),
                    ),
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
                  Container(
                    key: const ValueKey('b2b-product-detail-hero'),
                    padding: const EdgeInsets.symmetric(
                      horizontal: 6,
                      vertical: 8,
                    ),
                    decoration: BoxDecoration(
                      color: const Color(0xFFF6F9F7),
                      borderRadius: BorderRadius.circular(22),
                    ),
                    child: Opacity(
                      opacity: isAvailable ? 1 : 0.42,
                      child: FoodexGallery(
                        key: const ValueKey('b2b-product-gallery'),
                        urls: images,
                        palette: FoodexPalette.wholesale,
                      ),
                    ),
                  ),
                  const SizedBox(height: 14),
                  Text(
                    row['name']?.toString() ?? '',
                    style: const TextStyle(
                      color: Color(0xFF10233F),
                      fontSize: 24,
                      fontWeight: FontWeight.w900,
                      height: 1.15,
                    ),
                  ),
                  const SizedBox(height: 4),
                  KeyedSubtree(
                    key: const ValueKey('b2b-product-meta'),
                    child: Text(
                      (row['sku']?.toString() ?? '') +
                          (row['pack_label'] == null
                              ? ''
                              : ' · ' + row['pack_label'].toString()),
                      style: const TextStyle(
                        color: Color(0xFF6B7785),
                        fontSize: 12,
                      ),
                    ),
                  ),
                  if (brand.isNotEmpty || category.isNotEmpty) ...[
                    const SizedBox(height: 8),
                    Wrap(
                      spacing: 8,
                      runSpacing: 8,
                      children: [
                        if (category.isNotEmpty)
                          _ProductMetaChip(
                            key: const ValueKey('b2b-product-category'),
                            label: context.tr('b2b.product.category'),
                            value: category,
                          ),
                        if (brand.isNotEmpty)
                          _ProductMetaChip(
                            key: const ValueKey('b2b-product-brand'),
                            label: context.tr('b2b.product.brand'),
                            value: brand,
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
                  KeyedSubtree(
                    key: const ValueKey('b2b-product-price'),
                    child: _PricingPanel(
                      row: row,
                      currency: currency,
                    ),
                  ),
                  const SizedBox(height: 12),
                  Container(
                    key: const ValueKey('b2b-product-commerce-strip'),
                    padding: const EdgeInsets.all(10),
                    decoration: BoxDecoration(
                      color: Colors.white,
                      border: Border.all(color: const Color(0xFFE4ECE7)),
                      borderRadius: BorderRadius.circular(18),
                    ),
                    child: Row(
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
                            icon: isAvailable
                                ? Icons.verified_rounded
                                : Icons.remove_shopping_cart_outlined,
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
                  Container(
                    key: const ValueKey('b2b-product-description-section'),
                    padding: const EdgeInsets.all(16),
                    decoration: BoxDecoration(
                      color: Colors.white,
                      border: Border.all(color: const Color(0xFFE4ECE7)),
                      borderRadius: BorderRadius.circular(18),
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        Text(
                          context.tr('b2b.product.description'),
                          style: const TextStyle(
                            color: Color(0xFF10233F),
                            fontSize: 16,
                            fontWeight: FontWeight.w900,
                          ),
                        ),
                        const SizedBox(height: 8),
                        Text(
                          description.isNotEmpty
                              ? description
                              : context.tr('b2b.product.no_description'),
                          style: const TextStyle(
                            color: Color(0xFF6B7785),
                            fontSize: 13,
                            height: 1.55,
                          ),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 10),
                  Container(
                    key: const ValueKey('b2b-product-additional-info'),
                    padding: const EdgeInsets.all(16),
                    decoration: BoxDecoration(
                      color: Colors.white,
                      border: Border.all(color: const Color(0xFFE4ECE7)),
                      borderRadius: BorderRadius.circular(18),
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        Text(
                          context.tr('b2b.product.pack_details'),
                          style: const TextStyle(
                            color: Color(0xFF10233F),
                            fontSize: 16,
                            fontWeight: FontWeight.w900,
                          ),
                        ),
                        const SizedBox(height: 12),
                        _ProductInfoLine(
                          label: context.tr('b2b.product.category'),
                          value: category.isNotEmpty
                              ? category
                              : context.tr('b2b.product.not_specified'),
                        ),
                        _ProductInfoLine(
                          label: context.tr('b2b.product.pack_size'),
                          value: row['pack_label']?.toString() ??
                              row['pack_size']?.toString() ??
                              '1',
                        ),
                        _ProductInfoLine(
                          label: 'SKU',
                          value: row['sku']?.toString() ?? '—',
                        ),
                        _ProductInfoLine(
                          label: context.tr('b2b.product.brand'),
                          value: brand.isNotEmpty
                              ? brand
                              : context.tr('b2b.product.not_specified'),
                          showDivider: false,
                        ),
                      ],
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

class _ProductInfoLine extends StatelessWidget {
  const _ProductInfoLine({
    required this.label,
    required this.value,
    this.showDivider = true,
  });

  final String label;
  final String value;
  final bool showDivider;

  @override
  Widget build(BuildContext context) => Column(
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  label,
                  style: const TextStyle(
                    color: Color(0xFF6B7785),
                    fontSize: 12,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ),
              const SizedBox(width: 12),
              Flexible(
                child: Text(
                  value,
                  textAlign: TextAlign.end,
                  style: const TextStyle(
                    color: Color(0xFF10233F),
                    fontSize: 12,
                    fontWeight: FontWeight.w800,
                  ),
                ),
              ),
            ],
          ),
          if (showDivider)
            const Divider(
              height: 20,
              color: Color(0xFFE8EEEA),
            ),
        ],
      );
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
        padding: const EdgeInsets.symmetric(horizontal: 2, vertical: 4),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              money(
                row['account_price'],
                currency: currency,
              ),
              style: const TextStyle(
                color: Color(0xFF078A43),
                fontSize: 28,
                fontWeight: FontWeight.w900,
                height: 1,
              ),
            ),
            const SizedBox(height: 5),
            Text(
              context.tr('b2b.product.account_price'),
              style: const TextStyle(
                color: Color(0xFF6B7785),
                fontSize: 12,
                fontWeight: FontWeight.w700,
              ),
            ),
            if (row['base_wholesale_price'] != null ||
                row['retail_reference_price'] != null) ...[
              const SizedBox(height: 10),
              Wrap(
                spacing: 16,
                runSpacing: 6,
                children: [
                  if (row['base_wholesale_price'] != null)
                    Text(
                      context.tr('b2b.product.base_price') +
                          ': ' +
                          money(
                            row['base_wholesale_price'],
                            currency: currency,
                          ),
                      style: const TextStyle(
                        color: Color(0xFF6B7785),
                        fontSize: 11,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                  if (row['retail_reference_price'] != null)
                    Text(
                      context.tr('b2b.product.retail_reference_price') +
                          ': ' +
                          money(
                            row['retail_reference_price'],
                            currency: currency,
                          ),
                      style: const TextStyle(
                        color: Color(0xFF6B7785),
                        fontSize: 11,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                ],
              ),
            ],
          ],
        ),
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
    extends State<WholesaleCartDesignScreen> with WidgetsBindingObserver {
  late int storeId = wholesaleStoreId(widget.location);
  late Future<Object?> future = _load();
  final Set<int> _removedItemIds = <int>{};
  final Map<int, double> _optimisticQuantities = <int, double>{};
  final Map<int, double> _desiredQuantities = <int, double>{};
  final Set<int> _updatingItemIds = <int>{};

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed && mounted) {
      setState(() => future = _load());
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

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

  Future<void> _mutate(
    Future<void> Function() action, {
    Iterable<int> removedItemIds = const <int>[],
  }) async {
    try {
      await action();
      final refreshed = await _load();
      if (mounted) {
        setState(() {
          _removedItemIds.addAll(removedItemIds);
          future = Future<Object?>.value(refreshed);
        });
      }
    } catch (error) {
      if (mounted) {
        await showOperationalError(context, error);
        if (mounted) {
          setState(() => future = _load());
        }
      }
    }
  }

  void _adjustQuantity({
    required int id,
    required double serverQuantity,
    required double delta,
    required double minimum,
    required double? available,
  }) {
    if (id <= 0 || widget.commerceApi == null) return;
    final current = _optimisticQuantities[id] ?? serverQuantity;
    var next = current + delta;
    if (delta < 0) {
      next = math.max(minimum, next).toDouble();
    }
    next = double.parse(next.toStringAsFixed(6));
    if (available != null && next > available + .0001) return;
    _queueQuantityUpdate(id, next);
  }

  void _queueQuantityUpdate(int id, double value) {
    final api = widget.commerceApi;
    if (api == null || id <= 0) return;
    final shouldStart = !_updatingItemIds.contains(id);
    setState(() {
      _optimisticQuantities[id] = value;
      _desiredQuantities[id] = value;
      if (shouldStart) {
        _updatingItemIds.add(id);
      }
    });
    if (shouldStart) {
      unawaited(_drainQuantityUpdates(id, api));
    }
  }

  Future<void> _drainQuantityUpdates(
    int id,
    WholesaleCommerceApi api,
  ) async {
    try {
      while (mounted) {
        final target = _desiredQuantities[id];
        if (target == null) return;
        await api.updateItem(id, target);
        if (!mounted) return;
        if (_desiredQuantities[id] != target) {
          continue;
        }
        final refreshed = await _load();
        if (!mounted) return;
        if (_desiredQuantities[id] != target) {
          continue;
        }
        setState(() {
          _optimisticQuantities.remove(id);
          _desiredQuantities.remove(id);
          _updatingItemIds.remove(id);
          future = Future<Object?>.value(refreshed);
        });
        return;
      }
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _optimisticQuantities.remove(id);
        _desiredQuantities.remove(id);
        _updatingItemIds.remove(id);
      });
      await showOperationalError(context, error);
      if (mounted) {
        setState(() => future = _load());
      }
    }
  }

  Future<void> _remove(int id) async {
    final api = widget.commerceApi;
    if (api == null) return;
    await _mutate(
      () => api.removeItem(id),
      removedItemIds: <int>[id],
    );
  }

  Future<void> _clear(List<Map<String, dynamic>> rows) async {
    final api = widget.commerceApi;
    if (api == null) return;
    await _mutate(() async {
      for (final row in rows) {
        final id = intValue(row['id']);
        if (id > 0) {
          await api.removeItem(id);
        }
      }
    }, removedItemIds: rows.map((row) => intValue(row['id'])).where((id) => id > 0));
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
                    message: context.tr('b2b.cart.subtitle'),
                    onRetry: () => setState(() => future = _load()),
                  );
                }

                final cart = snapshot.data is Map
                    ? Map<String, dynamic>.from(snapshot.data as Map)
                    : <String, dynamic>{};
                if (storeId <= 0) {
                  storeId = intValue(cart['store_id']);
                }
                final rows = mapRows(cart['items'])
                    .where(
                      (row) => !_removedItemIds.contains(intValue(row['id'])),
                    )
                    .toList(growable: false);
                final hasUnavailable = cart['has_unavailable_items'] == true ||
                    rows.any((row) => row['is_available'] == false);

                return ListView(
                  key: const ValueKey('b2b-cart-data'),
                  padding: const EdgeInsets.fromLTRB(22, 8, 22, 24),
                  children: [
                    FoodexTopBar(title: context.tr('b2b.cart.title')),
                    const SizedBox(height: 5),
                    Text(
                      context.tr('b2b.cart.subtitle'),
                      style: const TextStyle(
                        color: Color(0xFF6B7785),
                        fontSize: 11,
                        height: 1.35,
                      ),
                    ),
                    const SizedBox(height: 10),
                    Container(
                      height: 38,
                      padding: const EdgeInsets.symmetric(horizontal: 12),
                      decoration: BoxDecoration(
                        color: const Color(0xFFF1F8F4),
                        borderRadius: BorderRadius.circular(12),
                      ),
                      child: Row(
                        children: [
                          const Icon(
                            Icons.warehouse_outlined,
                            color: Color(0xFF078A43),
                            size: 19,
                          ),
                          const SizedBox(width: 8),
                          Expanded(
                            child: Text(
                              context.tr('b2b.cart.store'),
                              style:
                                  const TextStyle(fontWeight: FontWeight.w700),
                            ),
                          ),
                          if (rows.isNotEmpty && widget.commerceApi != null)
                            TextButton.icon(
                              key: const ValueKey('b2b-cart-clear'),
                              onPressed: _updatingItemIds.isEmpty
                                  ? () => _clear(rows)
                                  : null,
                              icon: const Icon(Icons.delete_sweep_outlined,
                                  size: 18),
                              label: Text(context.tr('b2b.cart.clear')),
                            ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 10),
                    if (rows.isEmpty)
                      FoodexEmptyState(
                        key: const ValueKey('b2b-empty'),
                        title: context.tr('b2b.cart.empty'),
                        subtitle: context.tr('b2b.cart.subtitle'),
                      )
                    else
                      ...rows.map((row) {
                        final product = row['product'] is Map
                            ? Map<String, dynamic>.from(row['product'] as Map)
                            : row;
                        final id = intValue(row['id']);
                        final qty = doubleValue(row['quantity'], 1);
                        final effectiveQty = _optimisticQuantities[id] ?? qty;
                        final increment =
                            doubleValue(row['ordering_increment'], 1);
                        final minimum =
                            doubleValue(row['minimum_order_quantity'], 1);
                        final available = row['available_quantity'] == null
                            ? null
                            : doubleValue(row['available_quantity'], 0);

                        return _CartLine(
                          itemId: id,
                          name: product['name']?.toString() ??
                              row['name']?.toString() ??
                              '',
                          subtitle: [
                            product['sku']?.toString(),
                            row['pack_label']?.toString(),
                          ]
                              .where((value) =>
                                  value != null && value.trim().isNotEmpty)
                              .join(' • '),
                          imageUrl: product['image_url']?.toString(),
                          quantity: effectiveQty,
                          unitPrice:
                              row['unit_price_snapshot'] ?? row['unit_price'],
                          lineTotal: row['line_total'],
                          availableQuantity: available,
                          isAvailable: row['is_available'] != false,
                          isUpdating: _updatingItemIds.contains(id),
                          onMinus: widget.commerceApi == null ||
                                  effectiveQty <= minimum + .0001
                              ? null
                              : () => _adjustQuantity(
                                    id: id,
                                    serverQuantity: qty,
                                    delta: -increment,
                                    minimum: minimum,
                                    available: available,
                                  ),
                          onPlus: widget.commerceApi == null ||
                                  (available != null &&
                                      effectiveQty + increment >
                                          available + .0001)
                              ? null
                              : () => _adjustQuantity(
                                    id: id,
                                    serverQuantity: qty,
                                    delta: increment,
                                    minimum: minimum,
                                    available: available,
                                  ),
                          onRemove: widget.commerceApi == null ||
                                  id <= 0 ||
                                  _updatingItemIds.contains(id)
                              ? null
                              : () => _remove(id),
                        );
                      }),
                    const SizedBox(height: 14),
                    _CartTotalPanel(
                      cart: cart,
                      enabled: rows.isNotEmpty &&
                          storeId > 0 &&
                          !hasUnavailable &&
                          _updatingItemIds.isEmpty,
                      onCheckout: () => Navigator.of(context).pushNamed(
                        '/b2b/checkout?store_id=' + storeId.toString(),
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
    required this.itemId,
    required this.name,
    required this.subtitle,
    required this.quantity,
    required this.unitPrice,
    required this.lineTotal,
    required this.availableQuantity,
    required this.isAvailable,
    required this.isUpdating,
    required this.onMinus,
    required this.onPlus,
    required this.onRemove,
    this.imageUrl,
  });

  final int itemId;
  final String name;
  final String subtitle;
  final String? imageUrl;
  final double quantity;
  final Object? unitPrice;
  final Object? lineTotal;
  final double? availableQuantity;
  final bool isAvailable;
  final bool isUpdating;
  final VoidCallback? onMinus;
  final VoidCallback? onPlus;
  final VoidCallback? onRemove;

  @override
  Widget build(BuildContext context) {
    final validImage = imageUrl != null && imageUrl!.trim().isNotEmpty;
    final availability = !isAvailable
        ? context.tr('b2b.cart.unavailable')
        : availableQuantity == null
            ? context.tr('b2b.cart.available')
            : context.tr('b2b.cart.available') +
                ': ' +
                compactNumber(availableQuantity!);

    Widget imageFallback() => const DecoratedBox(
          decoration: BoxDecoration(
            color: Color(0xFFF1F8F4),
            borderRadius: BorderRadius.all(Radius.circular(14)),
          ),
          child: Center(
            child: Icon(
              Icons.inventory_2_outlined,
              color: Color(0xFF078A43),
            ),
          ),
        );

    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(11),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(17),
        border: Border.all(color: const Color(0xFFE9E3F0)),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          ClipRRect(
            borderRadius: BorderRadius.circular(14),
            child: SizedBox(
              width: 66,
              height: 66,
              child: validImage
                  ? Image.network(
                      imageUrl!,
                      fit: BoxFit.cover,
                      errorBuilder: (_, __, ___) => imageFallback(),
                    )
                  : imageFallback(),
            ),
          ),
          const SizedBox(width: 11),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Expanded(
                      child: Text(
                        name,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(fontWeight: FontWeight.w800),
                      ),
                    ),
                    if (onRemove != null)
                      IconButton(
                        key: ValueKey('b2b-cart-remove-$name'),
                        tooltip: context.tr('b2b.cart.remove'),
                        visualDensity: VisualDensity.compact,
                        onPressed: onRemove,
                        icon: const Icon(Icons.delete_outline_rounded, size: 20),
                      ),
                  ],
                ),
                if (subtitle.isNotEmpty)
                  Text(
                    subtitle,
                    style: const TextStyle(
                      fontSize: 10,
                      color: Color(0xFF6B7785),
                    ),
                  ),
                const SizedBox(height: 5),
                Text(
                  availability,
                  style: TextStyle(
                    fontSize: 10,
                    fontWeight: FontWeight.w700,
                    color: isAvailable
                        ? const Color(0xFF078A43)
                        : const Color(0xFFB3261E),
                  ),
                ),
                const SizedBox(height: 7),
                Wrap(
                  spacing: 12,
                  runSpacing: 4,
                  children: [
                    Text(
                      context.tr('b2b.cart.unit_price') +
                          ': ' +
                          money(unitPrice),
                      style: const TextStyle(fontSize: 10),
                    ),
                    Text(
                      context.tr('b2b.cart.line_total') +
                          ': ' +
                          money(lineTotal),
                      style: const TextStyle(
                        fontSize: 11,
                        color: Color(0xFF078A43),
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 8),
                Row(
                  children: [
                    _MiniStep(
                      key: ValueKey('b2b-cart-minus-$itemId'),
                      icon: Icons.remove,
                      onTap: onMinus,
                    ),
                    Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 9),
                      child: Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Text(
                            compactNumber(quantity),
                            key: ValueKey('b2b-cart-quantity-$itemId'),
                            style: const TextStyle(fontWeight: FontWeight.w800),
                          ),
                          if (isUpdating) ...[
                            const SizedBox(width: 6),
                            const SizedBox(
                              width: 12,
                              height: 12,
                              child: CircularProgressIndicator(strokeWidth: 1.6),
                            ),
                          ],
                        ],
                      ),
                    ),
                    _MiniStep(
                      key: ValueKey('b2b-cart-plus-$itemId'),
                      icon: Icons.add,
                      onTap: onPlus,
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
}

class _MiniStep extends StatelessWidget {
  const _MiniStep({
    super.key,
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
    required this.cart,
    required this.enabled,
    required this.onCheckout,
  });

  final Map<String, dynamic> cart;
  final bool enabled;
  final VoidCallback onCheckout;

  @override
  Widget build(BuildContext context) {
    final quote = cart['quote'] is Map
        ? Map<String, dynamic>.from(cart['quote'] as Map)
        : <String, dynamic>{};
    final currency = cart['currency']?.toString() ?? 'EGP';
    final subtotal = cart['subtotal'];
    final discount = quote['discount_total'];
    final delivery = quote['delivery_total'];
    final tax = quote['tax_total'];
    final total = quote['grand_total'] ?? cart['grand_total'] ?? subtotal;

    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: const Color(0xFF006736),
        borderRadius: BorderRadius.circular(18),
      ),
      child: Column(
        children: [
          _CartMoneyRow(
            label: context.tr('b2b.cart.subtotal'),
            value: money(subtotal, currency: currency),
          ),
          if (doubleValue(discount, 0) > 0)
            _CartMoneyRow(
              label: context.tr('b2b.cart.discount'),
              value: '- ' + money(discount, currency: currency),
            ),
          if (delivery != null)
            _CartMoneyRow(
              label: context.tr('b2b.cart.delivery'),
              value: money(delivery, currency: currency),
            ),
          if (tax != null)
            _CartMoneyRow(
              label: context.tr('b2b.cart.tax'),
              value: money(tax, currency: currency),
            ),
          const Divider(color: Colors.white24, height: 18),
          _CartMoneyRow(
            label: context.tr('b2b.cart.total'),
            value: money(total, currency: currency),
            strong: true,
          ),
          const SizedBox(height: 9),
          Text(
            context.tr('b2b.cart.revalidation'),
            style: const TextStyle(
              color: Colors.white70,
              fontSize: 10,
              height: 1.35,
            ),
          ),
          const SizedBox(height: 13),
          SizedBox(
            width: double.infinity,
            child: FilledButton(
              key: const ValueKey('b2b-cart-checkout'),
              onPressed: enabled ? onCheckout : null,
              style: FilledButton.styleFrom(
                backgroundColor: Colors.white,
                foregroundColor: const Color(0xFF006736),
              ),
              child: Text(context.tr('b2b.action.checkout')),
            ),
          ),
        ],
      ),
    );
  }
}

class _CartMoneyRow extends StatelessWidget {
  const _CartMoneyRow({
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
                  color: strong ? Colors.white : Colors.white70,
                  fontWeight: strong ? FontWeight.w800 : FontWeight.w500,
                ),
              ),
            ),
            Text(
              value,
              style: TextStyle(
                color: Colors.white,
                fontSize: strong ? 20 : 13,
                fontWeight: strong ? FontWeight.w900 : FontWeight.w700,
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
    extends State<WholesaleCheckoutDesignScreen> with WidgetsBindingObserver {
  late final int storeId = wholesaleStoreId(widget.location);
  late Future<_CheckoutPayload> future = _load();
  late final String idempotencyKey;
  int? addressId;
  String? deliveryDate;
  String? paymentMethod;
  bool submitting = false;
  final note = TextEditingController();
  final coupon = TextEditingController();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    idempotencyKey = 'fdx-b2b-' +
        DateTime.now().microsecondsSinceEpoch.toString() +
        '-' +
        math.Random().nextInt(1 << 32).toString();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed && mounted && !submitting) {
      setState(() => future = _load());
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
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

  String _paymentLabel(BuildContext context, String method) {
    if (Localizations.localeOf(context).languageCode == 'en') {
      switch (method) {
        case 'cash_on_delivery':
          return 'Cash on delivery';
        case 'account_credit':
          return 'Account credit';
      }
    }
    return paymentLabel(method);
  }

  Future<void> _submit() async {
    final api = widget.commerceApi;
    if (api == null || addressId == null || paymentMethod == null || submitting) {
      return;
    }

    setState(() => submitting = true);
    try {
      final result = await api.checkout(
        storeId: storeId,
        addressId: addressId!,
        paymentMethod: paymentMethod!,
        requestedDeliveryDate: deliveryDate,
        note: note.text,
        couponCode: coupon.text,
        idempotencyKey: idempotencyKey,
      );
      if (!mounted) return;
      final orderId = result is Map ? intValue(result['id']) : 0;
      Navigator.of(context).pushReplacementNamed(
        orderId > 0 ? '/b2b/orders/' + orderId.toString() : '/b2b/orders',
      );
    } catch (error) {
      if (!mounted) return;
      setState(() => submitting = false);
      await showOperationalError(context, error);
      if (mounted) {
        setState(() => future = _load());
      }
    }
  }

  @override
  Widget build(BuildContext context) => Directionality(
        textDirection: Directionality.of(context),
        child: Scaffold(
          backgroundColor: const Color(0xFFF8FBF9),
          body: SafeArea(
            child: FutureBuilder<_CheckoutPayload>(
              future: future,
              builder: (context, snapshot) {
                if (snapshot.connectionState != ConnectionState.done) {
                  return const FoodexLoading(
                    key: ValueKey('b2b-checkout-loading'),
                  );
                }
                if (snapshot.hasError) {
                  return FoodexErrorState(
                    key: const ValueKey('b2b-checkout-error'),
                    message: context.tr('b2b.cart.subtitle'),
                    onRetry: () => setState(() => future = _load()),
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
                final dates = (data['delivery_dates'] as List? ??
                        const <Object>[])
                    .map((value) => value.toString())
                    .toList(growable: false);
                final methods = (data['payment_methods'] as List? ??
                        const <Object>[])
                    .map((value) => value.toString())
                    .toList(growable: false);
                final items = mapRows(cart['items']);
                final quote = cart['quote'] is Map
                    ? Map<String, dynamic>.from(cart['quote'] as Map)
                    : <String, dynamic>{};
                final grandTotal = doubleValue(
                  quote['grand_total'] ?? cart['grand_total'] ?? cart['subtotal'],
                  0,
                );
                final purchasingPower =
                    doubleValue(data['purchasing_power'], 0);
                final hasUnavailable = cart['has_unavailable_items'] == true ||
                    items.any((item) => item['is_available'] == false);

                if (addresses.isEmpty) {
                  addressId = null;
                } else if (addressId == null ||
                    !addresses.any(
                      (address) => intValue(address['id']) == addressId,
                    )) {
                  addressId = intValue(addresses.first['id']);
                }
                if (dates.isEmpty) {
                  deliveryDate = null;
                } else if (deliveryDate == null ||
                    !dates.contains(deliveryDate)) {
                  deliveryDate = dates.first;
                }
                if (methods.isEmpty) {
                  paymentMethod = null;
                } else if (paymentMethod == null ||
                    !methods.contains(paymentMethod)) {
                  paymentMethod = methods.first;
                }

                Map<String, dynamic>? selectedAddress;
                for (final address in addresses) {
                  if (intValue(address['id']) == addressId) {
                    selectedAddress = address;
                    break;
                  }
                }

                final creditInsufficient =
                    paymentMethod == 'account_credit' &&
                        grandTotal > purchasingPower + .0001;
                final canSubmit = widget.commerceApi != null &&
                    items.isNotEmpty &&
                    !hasUnavailable &&
                    addressId != null &&
                    paymentMethod != null &&
                    !creditInsufficient &&
                    !submitting;

                return ListView(
                  key: const ValueKey('b2b-checkout-data'),
                  padding: const EdgeInsets.fromLTRB(22, 8, 22, 24),
                  children: [
                    FoodexTopBar(title: context.tr('b2b.checkout.title')),
                    const SizedBox(height: 8),
                    const _CheckoutStepper(),
                    const SizedBox(height: 14),
                    _CheckoutFinanceCard(data: data),
                    const SizedBox(height: 14),
                    Text(
                      context.tr('b2b.checkout.address'),
                      style: const TextStyle(fontWeight: FontWeight.w800),
                    ),
                    const SizedBox(height: 8),
                    if (addresses.isEmpty) ...[
                      FoodexEmptyState(
                        title: context.tr('b2b.checkout.no_address'),
                        subtitle: context.tr('b2b.checkout.no_address_hint'),
                      ),
                      const SizedBox(height: 10),
                      FilledButton.icon(
                        key: const ValueKey('wholesale-checkout-add-address'),
                        onPressed: _openAddressBook,
                        icon: const Icon(Icons.add_location_alt_outlined),
                        label: Text(context.tr('b2b.checkout.add_address')),
                      ),
                    ] else ...[
                      ...addresses.map(
                        (address) => _SelectCard(
                          selected: addressId == intValue(address['id']),
                          title:
                              address['label']?.toString() ??
                              context.tr('b2b.checkout.address'),
                          subtitle: <Object?>[
                            address['line1'],
                            address['area'],
                            address['city'],
                          ]
                              .where(
                                (value) =>
                                    value != null &&
                                    value.toString().trim().isNotEmpty,
                              )
                              .map((value) => value.toString())
                              .join('، '),
                          onTap: () => setState(
                            () => addressId = intValue(address['id']),
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
                          label:
                              Text(context.tr('b2b.checkout.manage_addresses')),
                        ),
                      ),
                    ],
                    const SizedBox(height: 12),
                    DropdownButtonFormField<String>(
                      value: deliveryDate,
                      decoration: InputDecoration(
                        labelText: context.tr('b2b.checkout.delivery_date'),
                        prefixIcon:
                            const Icon(Icons.calendar_today_outlined),
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
                      decoration: InputDecoration(
                        labelText: context.tr('b2b.checkout.payment_method'),
                        prefixIcon: const Icon(Icons.payments_outlined),
                      ),
                      items: methods
                          .map(
                            (method) => DropdownMenuItem(
                              value: method,
                              child: Text(_paymentLabel(context, method)),
                            ),
                          )
                          .toList(growable: false),
                      onChanged: (value) =>
                          setState(() => paymentMethod = value),
                    ),
                    if (creditInsufficient) ...[
                      const SizedBox(height: 8),
                      Text(
                        context.tr('b2b.checkout.insufficient_credit'),
                        key: const ValueKey(
                          'b2b-checkout-insufficient-credit',
                        ),
                        style: const TextStyle(
                          color: Color(0xFFB3261E),
                          fontSize: 11,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    ],
                    const SizedBox(height: 12),
                    TextField(
                      controller: coupon,
                      textCapitalization: TextCapitalization.characters,
                      decoration: InputDecoration(
                        labelText: context.tr('b2b.checkout.coupon'),
                        prefixIcon:
                            const Icon(Icons.confirmation_number_outlined),
                      ),
                    ),
                    const SizedBox(height: 12),
                    TextField(
                      controller: note,
                      minLines: 3,
                      maxLines: 4,
                      decoration: InputDecoration(
                        labelText: context.tr('b2b.checkout.note'),
                        hintText: context.tr('b2b.checkout.note_hint'),
                      ),
                    ),
                    const SizedBox(height: 16),
                    _CheckoutReviewCard(
                      data: data,
                      address: selectedAddress,
                      deliveryDate: deliveryDate,
                      paymentLabel: paymentMethod == null
                          ? '-'
                          : _paymentLabel(context, paymentMethod!),
                    ),
                    const SizedBox(height: 12),
                    _CheckoutSummary(cart: cart),
                    const SizedBox(height: 18),
                    SizedBox(
                      height: 54,
                      child: FilledButton(
                        key: const ValueKey('b2b-checkout-submit'),
                        style: FilledButton.styleFrom(
                          backgroundColor: const Color(0xFF078A43),
                        ),
                        onPressed: canSubmit ? _submit : null,
                        child: Text(
                          submitting
                              ? context.tr('b2b.checkout.submitting')
                              : context.tr('b2b.checkout.confirm'),
                        ),
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

class _CheckoutFinanceCard extends StatelessWidget {
  const _CheckoutFinanceCard({required this.data});

  final Map<String, dynamic> data;

  @override
  Widget build(BuildContext context) {
    final currency = data['currency']?.toString() ?? 'EGP';
    final rows = <(String, Object?)>[
      (
        context.tr('b2b.checkout.customer_credit'),
        data['customer_credit_balance'],
      ),
      (
        context.tr('b2b.checkout.amount_owed'),
        data['outstanding_receivable'],
      ),
      (
        context.tr('b2b.checkout.credit_limit'),
        data['credit_limit'],
      ),
      (
        context.tr('b2b.checkout.available_credit'),
        data['available_credit_line'],
      ),
      (
        context.tr('b2b.checkout.purchasing_power'),
        data['purchasing_power'],
      ),
    ];

    return Container(
      key: const ValueKey('b2b-checkout-finance'),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: const Color(0xFFF1F8F4),
        borderRadius: BorderRadius.circular(16),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            context.tr('b2b.checkout.finance'),
            style: const TextStyle(
              fontWeight: FontWeight.w900,
              color: Color(0xFF006736),
            ),
          ),
          const SizedBox(height: 8),
          ...rows.map(
            (row) => Padding(
              padding: const EdgeInsets.symmetric(vertical: 2),
              child: Row(
                children: [
                  Expanded(
                    child: Text(
                      row.$1,
                      style: const TextStyle(fontSize: 11),
                    ),
                  ),
                  Text(
                    money(row.$2 ?? 0, currency: currency),
                    style: const TextStyle(
                      fontSize: 11,
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _CheckoutReviewCard extends StatelessWidget {
  const _CheckoutReviewCard({
    required this.data,
    required this.address,
    required this.deliveryDate,
    required this.paymentLabel,
  });

  final Map<String, dynamic> data;
  final Map<String, dynamic>? address;
  final String? deliveryDate;
  final String paymentLabel;

  @override
  Widget build(BuildContext context) {
    final addressText = address == null
        ? '-'
        : <Object?>[
            address!['label'],
            address!['line1'],
            address!['area'],
            address!['city'],
          ]
            .where(
              (value) => value != null && value.toString().trim().isNotEmpty,
            )
            .map((value) => value.toString())
            .join('، ');

    final rows = <(String, String)>[
      (
        context.tr('b2b.checkout.customer'),
        data['customer_name']?.toString() ?? '-',
      ),
      (
        context.tr('b2b.checkout.store'),
        data['store_name']?.toString() ??
            (data['store_id'] == null ? '-' : '#${data['store_id']}'),
      ),
      (context.tr('b2b.checkout.selected_address'), addressText),
      (
        context.tr('b2b.checkout.selected_delivery'),
        deliveryDate ?? '-',
      ),
      (context.tr('b2b.checkout.selected_payment'), paymentLabel),
    ];

    return Container(
      key: const ValueKey('b2b-checkout-review'),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: const Color(0xFFE9E3F0)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            context.tr('b2b.checkout.review'),
            style: const TextStyle(
              fontSize: 15,
              fontWeight: FontWeight.w800,
            ),
          ),
          const SizedBox(height: 8),
          ...rows.map(
            (row) => Padding(
              padding: const EdgeInsets.symmetric(vertical: 3),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  SizedBox(
                    width: 105,
                    child: Text(
                      row.$1,
                      style: const TextStyle(
                        fontSize: 11,
                        color: Color(0xFF6B7785),
                      ),
                    ),
                  ),
                  Expanded(
                    child: Text(
                      row.$2,
                      style: const TextStyle(
                        fontSize: 11,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
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
    final quote = cart['quote'] is Map
        ? Map<String, dynamic>.from(cart['quote'] as Map)
        : <String, dynamic>{};
    final subtotal = cart['subtotal'];
    final discount = quote['discount_total'];
    final delivery = quote['delivery_total'];
    final tax = quote['tax_total'];
    final grandTotal =
        quote['grand_total'] ?? cart['grand_total'] ?? cart['total'] ?? subtotal;

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
          Text(
            context.tr('b2b.checkout.order_summary'),
            style: const TextStyle(
              fontSize: 15,
              fontWeight: FontWeight.w800,
            ),
          ),
          if (items.isNotEmpty) ...[
            const SizedBox(height: 10),
            ...items.map(
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
                                    context.tr('b2b.checkout.product')
                                : context.tr('b2b.checkout.product')),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(fontSize: 12),
                      ),
                    ),
                    const SizedBox(width: 8),
                    Text(
                      '× ' + (item['quantity']?.toString() ?? '1'),
                      style: const TextStyle(
                        fontSize: 11,
                        color: Color(0xFF6B7785),
                      ),
                    ),
                    const SizedBox(width: 10),
                    Text(
                      money(
                        item['line_total'] ??
                            item['unit_price_snapshot'] ??
                            item['unit_price'],
                        currency: currency,
                      ),
                      style: const TextStyle(
                        fontSize: 11,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ],
          const Divider(height: 18),
          _SummaryRow(
            label: context.tr('b2b.cart.subtotal'),
            value: money(subtotal, currency: currency),
          ),
          if (doubleValue(discount, 0) > 0)
            _SummaryRow(
              label: context.tr('b2b.cart.discount'),
              value: '- ' + money(discount, currency: currency),
            ),
          if (delivery != null)
            _SummaryRow(
              label: context.tr('b2b.cart.delivery'),
              value: money(delivery, currency: currency),
            ),
          if (tax != null)
            _SummaryRow(
              label: context.tr('b2b.cart.tax'),
              value: money(tax, currency: currency),
            ),
          const SizedBox(height: 7),
          _SummaryRow(
            label: context.tr('b2b.cart.total'),
            value: money(grandTotal, currency: currency),
            strong: true,
          ),
          const SizedBox(height: 7),
          Text(
            context.tr('b2b.cart.revalidation'),
            style: const TextStyle(
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
  Widget build(BuildContext context) => Row(
        children: [
          Expanded(
            child: _StepDot(
              number: '1',
              label: context.tr('b2b.checkout.selected_address'),
              active: true,
            ),
          ),
          const Expanded(
            child: Divider(color: Color(0xFF92D853)),
          ),
          Expanded(
            child: _StepDot(
              number: '2',
              label: context.tr('b2b.checkout.selected_delivery'),
              active: true,
            ),
          ),
          const Expanded(
            child: Divider(color: Color(0xFF92D853)),
          ),
          Expanded(
            child: _StepDot(
              number: '3',
              label: context.tr('b2b.checkout.selected_payment'),
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
    extends State<WholesaleOrdersDesignScreen> with WidgetsBindingObserver {
  String status = 'all';
  late Future<Object?> future = _load();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed && mounted) {
      unawaited(_refresh());
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  Future<Object?> _load() {
    var endpoint = '/api/v1/b2b/orders';
    if (status != 'all') {
      endpoint += '?status=' + status;
    }
    return widget.api?.get(endpoint) ??
        Future<Object?>.value(const {'data': <Object>[]});
  }

  void _select(String value) {
    if (status == value) return;
    setState(() {
      status = value;
      future = _load();
    });
  }

  Future<void> _refresh() async {
    final next = _load();
    setState(() {
      future = next;
    });
    try {
      await next;
    } catch (_) {
      // FutureBuilder renders the authoritative error state.
    }
  }

  @override
  Widget build(BuildContext context) => Directionality(
        textDirection: Directionality.of(context),
        child: Scaffold(
          key: const ValueKey('b2b-orders-screen'),
          backgroundColor: FoodexPalette.wholesale.background,
          body: SafeArea(
            bottom: false,
            child: LayoutBuilder(
              builder: (context, constraints) {
                final viewportWidth = constraints.maxWidth;
                final compact = viewportWidth < 360;
                final pageInset = viewportWidth <= 340
                    ? 10.0
                    : viewportWidth <= 430
                        ? 16.0
                        : 20.0;
                final contentWidth = math.min(560.0, viewportWidth);

                return Align(
                  alignment: Alignment.topCenter,
                  child: SizedBox(
                    width: contentWidth,
                    child: FutureBuilder<Object?>(
                      future: future,
                      builder: (context, snapshot) {
                        final loading =
                            snapshot.connectionState != ConnectionState.done;
                        final rows = snapshot.hasData
                            ? dataRows(snapshot.data)
                            : const <Map<String, dynamic>>[];

                        Widget content;
                        if (loading) {
                          content = const FoodexLoading(
                            key: ValueKey('b2b-loading'),
                          );
                        } else if (snapshot.hasError) {
                          content = ListView(
                            physics: const AlwaysScrollableScrollPhysics(),
                            padding: EdgeInsets.fromLTRB(
                              pageInset,
                              10,
                              pageInset,
                              24,
                            ),
                            children: [
                              FoodexErrorState(
                                key: const ValueKey('b2b-error'),
                                message: context.tr('customer.error.action_failed'),
                                retryLabel: context.tr('customer.action.retry'),
                                onRetry: () => unawaited(_refresh()),
                              ),
                            ],
                          );
                        } else if (rows.isEmpty) {
                          content = ListView(
                            physics: const AlwaysScrollableScrollPhysics(),
                            padding: EdgeInsets.fromLTRB(
                              pageInset,
                              10,
                              pageInset,
                              24,
                            ),
                            children: [
                              FoodexEmptyState(
                                key: const ValueKey('b2b-empty'),
                                title: context.tr('customer.orders.empty'),
                                subtitle: context.tr('b2b.orders.subtitle'),
                              ),
                            ],
                          );
                        } else {
                          content = RefreshIndicator(
                            onRefresh: _refresh,
                            child: ListView.separated(
                              key: const ValueKey('b2b-orders-data'),
                              physics: const AlwaysScrollableScrollPhysics(),
                              padding: EdgeInsets.fromLTRB(
                                pageInset,
                                7,
                                pageInset,
                                20,
                              ),
                              itemCount: rows.length,
                              separatorBuilder: (_, __) =>
                                  SizedBox(height: compact ? 9 : 11),
                              itemBuilder: (_, index) => _OrderCard(
                                row: rows[index],
                                compact: compact,
                              ),
                            ),
                          );
                        }

                        return Column(
                          children: [
                            _WholesaleOrdersHeader(
                              pageInset: pageInset,
                              loading: loading,
                              onRefresh: () => unawaited(_refresh()),
                            ),
                            _OrderTabs(
                              selected: status,
                              onChanged: _select,
                              pageInset: pageInset,
                              compact: compact,
                              enabled: !loading,
                            ),
                            Expanded(child: content),
                          ],
                        );
                      },
                    ),
                  ),
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
    extends State<WholesaleOrderDetailsDesignScreen>
    with WidgetsBindingObserver {
  late final int orderId = _orderId(widget.location);
  Map<String, dynamic>? _lastOrder;
  late Future<Object?> future = _load();

  static int _orderId(String location) {
    final segments = Uri.parse(location).pathSegments;
    final index = segments.indexOf('orders');
    if (index < 0 || segments.length <= index + 1) return 0;
    return int.tryParse(segments[index + 1]) ?? 0;
  }

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
      _refresh();
    }
  }

  Future<Object?> _load() async {
    if (orderId <= 0 || widget.api == null) {
      return null;
    }

    try {
      final raw =
          await widget.api!.get('/api/v1/b2b/orders/' + orderId.toString());
      if (raw is Map) {
        final order = Map<String, dynamic>.from(raw);
        _lastOrder = order;
        return order;
      }
      return raw;
    } catch (_) {
      if (_lastOrder != null) {
        return <String, dynamic>{
          ..._lastOrder!,
          '__stale': true,
        };
      }
      rethrow;
    }
  }

  void _refresh() {
    if (!mounted) return;
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
                      Expanded(
                        child: FoodexTopBar(
                          title: context.tr('b2b.order.title'),
                        ),
                      ),
                      IconButton(
                        key: const ValueKey('b2b-order-refresh'),
                        tooltip: context.tr('b2b.order.refresh'),
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
                          message: context.tr('b2b.order.load_error'),
                          onRetry: _refresh,
                        );
                      }

                      final raw = snapshot.data;
                      final order = raw is Map
                          ? Map<String, dynamic>.from(raw)
                          : <String, dynamic>{};
                      if (order.isEmpty) {
                        return FoodexEmptyState(
                          key: const ValueKey('b2b-order-detail-empty'),
                          title: context.tr('b2b.order.unavailable'),
                          subtitle: context.tr('b2b.order.unavailable_body'),
                        );
                      }

                      final stale = order['__stale'] == true;
                      final items = mapRows(order['items']);
                      final timeline = mapRows(order['timeline']);
                      final currency =
                          order['currency']?.toString() ?? 'KWD';
                      final total = order['grand_total'] ?? order['total'];
                      final status = order['status']?.toString() ?? '';
                      final isTerminal = order['is_terminal'] == true ||
                          const {'delivered', 'failed', 'cancelled'}
                              .contains(status);
                      final storeName =
                          order['store_name']?.toString() ??
                              (order['store'] is Map
                                  ? (order['store'] as Map)['name']?.toString()
                                  : null) ??
                              '-';
                      final paymentMethod =
                          order['payment_method']?.toString() ?? '-';
                      final payment = order['payment'] is Map
                          ? Map<String, dynamic>.from(order['payment'] as Map)
                          : null;
                      final accountCredit =
                          order['account_credit_impact'] is Map
                              ? Map<String, dynamic>.from(
                                  order['account_credit_impact'] as Map,
                                )
                              : null;
                      final invoice = order['invoice'] is Map
                          ? Map<String, dynamic>.from(order['invoice'] as Map)
                          : null;
                      final tracking = order['tracking'] is Map
                          ? Map<String, dynamic>.from(order['tracking'] as Map)
                          : null;
                      final allowed = order['allowed_actions'] is Map
                          ? Map<String, dynamic>.from(
                              order['allowed_actions'] as Map,
                            )
                          : <String, dynamic>{};
                      final address = order['delivery_address'] is Map
                          ? Map<String, dynamic>.from(
                              order['delivery_address'] as Map,
                            )
                          : null;
                      final latitude = _numberValue(address?['latitude']);
                      final longitude = _numberValue(address?['longitude']);
                      final canOpenMap = allowed['view_map'] == true &&
                          latitude != null &&
                          longitude != null;

                      return ListView(
                        key: const ValueKey('b2b-order-detail'),
                        padding: const EdgeInsets.fromLTRB(22, 12, 22, 24),
                        children: [
                          if (stale) ...[
                            _OrderNotice(
                              key: const ValueKey('b2b-order-detail-stale'),
                              icon: Icons.cloud_off_outlined,
                              text: context.tr('b2b.order.stale'),
                            ),
                            const SizedBox(height: 10),
                          ],
                          if (isTerminal) ...[
                            _OrderNotice(
                              key: const ValueKey('b2b-order-terminal'),
                              icon: status == 'delivered'
                                  ? Icons.check_circle_outline_rounded
                                  : Icons.info_outline_rounded,
                              text: _orderStatusText(context, status) +
                                  ' · ' +
                                  context.tr('b2b.order.terminal'),
                            ),
                            const SizedBox(height: 10),
                          ],
                          _OrderDetailCard(
                            title: order['order_number']?.toString() ??
                                '#' + orderId.toString(),
                            rows: [
                              (
                                context.tr('b2b.order.status'),
                                _orderStatusText(context, status),
                              ),
                              (
                                context.tr('b2b.order.created_at'),
                                _friendlyOrderDate(
                                  order['created_at']?.toString(),
                                ),
                              ),
                              (context.tr('b2b.order.store'), storeName),
                              (
                                context.tr('b2b.order.channel'),
                                order['channel']?.toString() == 'b2b'
                                    ? context.tr('b2b.order.channel_b2b')
                                    : (order['channel']?.toString() ?? '-'),
                              ),
                              (
                                context.tr('b2b.order.total'),
                                money(total, currency: currency),
                              ),
                              (
                                context.tr('b2b.order.payment_method'),
                                _paymentMethodText(
                                  context,
                                  paymentMethod,
                                ),
                              ),
                              if ((order['requested_delivery_date']
                                          ?.toString() ??
                                      '')
                                  .isNotEmpty)
                                (
                                  context.tr('b2b.order.requested_delivery'),
                                  order['requested_delivery_date'].toString(),
                                ),
                            ],
                          ),
                          if (address != null) ...[
                            const SizedBox(height: 2),
                            _OrderDetailCard(
                              title: context.tr('b2b.order.delivery_address'),
                              rows: _deliveryAddressRows(context, address),
                            ),
                            if (canOpenMap)
                              Align(
                                alignment: AlignmentDirectional.centerStart,
                                child: OutlinedButton.icon(
                                  key: const ValueKey('b2b-order-open-map'),
                                  onPressed: () => _showOrderMap(
                                    context,
                                    latitude: latitude,
                                    longitude: longitude,
                                    label: address['formatted']?.toString() ??
                                        storeName,
                                  ),
                                  icon: const Icon(Icons.map_outlined),
                                  label:
                                      Text(context.tr('b2b.order.open_map')),
                                ),
                              ),
                          ],
                          if (timeline.isNotEmpty) ...[
                            const SizedBox(height: 14),
                            Text(
                              context.tr('b2b.order.timeline'),
                              style: const TextStyle(
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
                                final vanCode =
                                    event['van_code']?.toString() ?? '';
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
                                  title:
                                      Text(_orderStatusText(context, stage)),
                                  subtitle: Column(
                                    crossAxisAlignment:
                                        CrossAxisAlignment.start,
                                    children: [
                                      if (occurredAt.isNotEmpty)
                                        Text(_friendlyOrderDate(occurredAt)),
                                      if (vanCode.isNotEmpty)
                                        Text(vanCode),
                                      if (reasonCode.isNotEmpty)
                                        Text(
                                          _failureReasonText(
                                            context,
                                            reasonCode,
                                          ),
                                        ),
                                    ],
                                  ),
                                );
                              }).toList(growable: false),
                            ),
                          ],
                          if (tracking != null) ...[
                            const SizedBox(height: 12),
                            _OrderDetailCard(
                              title: context.tr('b2b.order.van_tracking'),
                              rows: [
                                if ((tracking['van_code']?.toString() ?? '')
                                    .isNotEmpty)
                                  (
                                    context.tr('b2b.order.van'),
                                    tracking['van_code'].toString(),
                                  ),
                                (
                                  context.tr('b2b.order.tracking_status'),
                                  _orderStatusText(
                                    context,
                                    tracking['status']?.toString() ?? '',
                                  ),
                                ),
                                if ((tracking['assigned_at']?.toString() ?? '')
                                    .isNotEmpty)
                                  (
                                    context.tr('b2b.order.assigned_at'),
                                    _friendlyOrderDate(
                                      tracking['assigned_at']?.toString(),
                                    ),
                                  ),
                              ],
                            ),
                          ],
                          if (items.isNotEmpty) ...[
                            const SizedBox(height: 14),
                            Text(
                              context.tr('b2b.order.items'),
                              style: const TextStyle(
                                fontSize: 15,
                                fontWeight: FontWeight.w800,
                              ),
                            ),
                            const SizedBox(height: 8),
                            ...items.map(
                              (item) => _OrderLineCard(
                                item: item,
                                currency: currency,
                              ),
                            ),
                          ],
                          const SizedBox(height: 2),
                          _OrderDetailCard(
                            title: context.tr('b2b.order.price_summary'),
                            rows: [
                              (
                                context.tr('b2b.order.subtotal'),
                                money(
                                  order['subtotal'],
                                  currency: currency,
                                ),
                              ),
                              (
                                context.tr('b2b.order.discount'),
                                money(
                                  order['discount_total'],
                                  currency: currency,
                                ),
                              ),
                              (
                                context.tr('b2b.order.tax'),
                                money(
                                  order['tax_total'],
                                  currency: currency,
                                ),
                              ),
                              (
                                context.tr('b2b.order.delivery_fee'),
                                money(
                                  order['delivery_total'],
                                  currency: currency,
                                ),
                              ),
                              (
                                context.tr('b2b.order.grand_total'),
                                money(total, currency: currency),
                              ),
                            ],
                          ),
                          _OrderDetailCard(
                            title: context.tr('b2b.order.payment'),
                            rows: [
                              (
                                context.tr('b2b.order.payment_method'),
                                _paymentMethodText(
                                  context,
                                  paymentMethod,
                                ),
                              ),
                              if (payment != null)
                                (
                                  context.tr('b2b.order.payment_status'),
                                  _paymentStatusText(
                                    context,
                                    payment['status']?.toString() ?? '',
                                  ),
                                ),
                              if (accountCredit != null)
                                (
                                  context
                                      .tr('b2b.order.account_credit_impact'),
                                  money(
                                    accountCredit['amount'],
                                    currency:
                                        accountCredit['currency']?.toString() ??
                                            currency,
                                  ),
                                ),
                            ],
                          ),
                          if (invoice != null &&
                              allowed['view_invoice'] == true) ...[
                            _OrderDetailCard(
                              title: context.tr('b2b.order.invoice'),
                              rows: [
                                (
                                  context.tr('b2b.invoice.title'),
                                  invoice['invoice_number']?.toString() ?? '-',
                                ),
                                (
                                  context.tr('b2b.invoice.payment_status'),
                                  invoice['status']?.toString() ?? '-',
                                ),
                              ],
                            ),
                            Align(
                              alignment: AlignmentDirectional.centerStart,
                              child: FilledButton.icon(
                                key: const ValueKey(
                                  'b2b-order-open-invoice',
                                ),
                                onPressed: () {
                                  final invoiceId =
                                      int.tryParse(invoice['id'].toString());
                                  if (invoiceId == null || invoiceId <= 0) {
                                    return;
                                  }
                                  Navigator.of(context).pushNamed(
                                    '/b2b/invoices/' +
                                        invoiceId.toString(),
                                  );
                                },
                                icon: const Icon(Icons.receipt_long_outlined),
                                label: Text(
                                  context.tr('b2b.order.open_invoice'),
                                ),
                              ),
                            ),
                          ],
                          if ((order['customer_note']?.toString().trim() ?? '')
                              .isNotEmpty) ...[
                            const SizedBox(height: 12),
                            _OrderDetailCard(
                              title: context.tr('b2b.order.note'),
                              rows: [
                                (
                                  context.tr('b2b.order.note'),
                                  order['customer_note'].toString().trim(),
                                ),
                              ],
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

List<(String, String)> _deliveryAddressRows(
  BuildContext context,
  Map<String, dynamic> address,
) {
  final rows = <(String, String)>[];

  void add(String label, Object? raw) {
    final value = raw?.toString().trim() ?? '';
    if (value.isNotEmpty) rows.add((label, value));
  }

  add(context.tr('b2b.order.address.recipient'), address['recipient_name']);
  add(context.tr('b2b.order.address.phone'), address['delivery_phone']);
  add(context.tr('b2b.order.address.line1'), address['line1']);
  add(context.tr('b2b.order.address.line2'), address['line2']);
  add(context.tr('b2b.order.address.area'), address['area']);
  add(context.tr('b2b.order.address.city'), address['city']);
  add(
    context.tr('b2b.order.address.country'),
    address['country'] ?? address['country_code'],
  );
  add(context.tr('b2b.order.address.notes'), address['delivery_notes']);

  return rows.isEmpty
      ? [(context.tr('b2b.order.delivery_address'), '-')]
      : rows;
}

double? _numberValue(Object? value) {
  if (value is num) return value.toDouble();
  return double.tryParse(value?.toString() ?? '');
}

String _friendlyOrderDate(String? raw) {
  if (raw == null || raw.trim().isEmpty) return '-';
  final parsed = DateTime.tryParse(raw);
  if (parsed == null) return raw;
  final local = parsed.toLocal();
  String two(int value) => value.toString().padLeft(2, '0');
  return '${local.year}-${two(local.month)}-${two(local.day)} '
      '${two(local.hour)}:${two(local.minute)}';
}

String _orderStatusText(BuildContext context, String status) {
  switch (status) {
    case 'placed':
      return context.tr('b2b.order.stage.placed');
    case 'van_assigned':
    case 'assigned':
      return context.tr('b2b.order.stage.van_assigned');
    case 'awaiting_dispatch':
      return context.tr('b2b.order.status.awaiting_dispatch');
    case 'accepted':
      return context.tr('b2b.order.stage.accepted');
    case 'picked_up':
      return context.tr('b2b.order.stage.picked_up');
    case 'pending':
    case 'confirmed':
    case 'preparing':
    case 'ready':
    case 'out_for_delivery':
    case 'failed':
    case 'delivered':
    case 'cancelled':
      return context.tr('customer.order.status.' + status);
    default:
      return status.isEmpty ? '-' : status.replaceAll('_', ' ');
  }
}

String _paymentMethodText(BuildContext context, String method) {
  switch (method) {
    case 'cash_on_delivery':
      return context.tr('b2b.order.payment.cash_on_delivery');
    case 'account_credit':
    case 'credit_balance':
      return context.tr('b2b.order.payment.account_credit');
    default:
      return method.isEmpty || method == '-' ? '-' : method.replaceAll('_', ' ');
  }
}

String _paymentStatusText(BuildContext context, String status) {
  switch (status) {
    case 'pending':
    case 'paid':
    case 'completed':
    case 'failed':
    case 'refunded':
      return context.tr('b2b.order.payment_status.' + status);
    default:
      return status.isEmpty ? '-' : status.replaceAll('_', ' ');
  }
}

String _failureReasonText(BuildContext context, String reason) {
  switch (reason) {
    case 'customer_no_answer':
    case 'wrong_address':
    case 'customer_refused':
    case 'customer_absent':
    case 'payment_issue':
    case 'order_issue':
    case 'other':
      return context.tr('b2b.order.failure.' + reason);
    default:
      return reason.replaceAll('_', ' ');
  }
}

Future<void> _showOrderMap(
  BuildContext context, {
  required double latitude,
  required double longitude,
  required String label,
}) {
  final point = LatLng(latitude, longitude);
  return showModalBottomSheet<void>(
    context: context,
    isScrollControlled: true,
    useSafeArea: true,
    builder: (sheetContext) => FractionallySizedBox(
      heightFactor: .72,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    label,
                    style: const TextStyle(
                      fontWeight: FontWeight.w800,
                      fontSize: 16,
                    ),
                  ),
                ),
                IconButton(
                  onPressed: () => Navigator.of(sheetContext).pop(),
                  icon: const Icon(Icons.close_rounded),
                ),
              ],
            ),
            const SizedBox(height: 8),
            Expanded(
              child: ClipRRect(
                borderRadius: BorderRadius.circular(18),
                child: FlutterMap(
                  options: MapOptions(
                    initialCenter: point,
                    initialZoom: 15,
                    minZoom: 3,
                    maxZoom: 19,
                  ),
                  children: [
                    TileLayer(
                      urlTemplate:
                          'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
                      userAgentPackageName:
                          'com.fiftysolution.foodex.customer',
                    ),
                    MarkerLayer(
                      markers: [
                        Marker(
                          point: point,
                          width: 56,
                          height: 56,
                          child: const Icon(
                            Icons.location_pin,
                            size: 48,
                            color: Color(0xFF087347),
                          ),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    ),
  );
}

class _OrderNotice extends StatelessWidget {
  const _OrderNotice({
    required this.icon,
    required this.text,
    super.key,
  });

  final IconData icon;
  final String text;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: Colors.white,
          border: Border.all(color: const Color(0xFFD7E7DE)),
          borderRadius: BorderRadius.circular(14),
        ),
        child: Row(
          children: [
            Icon(icon, color: const Color(0xFF087347)),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                text,
                style: const TextStyle(fontWeight: FontWeight.w700),
              ),
            ),
          ],
        ),
      );
}

class _OrderLineCard extends StatelessWidget {
  const _OrderLineCard({
    required this.item,
    required this.currency,
  });

  final Map<String, dynamic> item;
  final String currency;

  @override
  Widget build(BuildContext context) {
    final imageUrl = item['image_url']?.toString() ?? '';
    final sku = item['sku']?.toString() ?? '';
    final pack = _numberValue(
          item['pack_size'] ?? item['quantity_conversion_factor'],
        ) ??
        1;

    return Container(
      key: ValueKey('b2b-order-line-${item['id'] ?? item['product_id']}'),
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.white,
        border: Border.all(color: const Color(0xFFE9E3F0)),
        borderRadius: BorderRadius.circular(17),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              ClipRRect(
                borderRadius: BorderRadius.circular(10),
                child: SizedBox(
                  width: 56,
                  height: 56,
                  child: imageUrl.isEmpty
                      ? const ColoredBox(
                          color: Color(0xFFF1F5F2),
                          child: Icon(Icons.inventory_2_outlined),
                        )
                      : Image.network(
                          imageUrl,
                          fit: BoxFit.cover,
                          errorBuilder: (_, __, ___) => const ColoredBox(
                            color: Color(0xFFF1F5F2),
                            child: Icon(Icons.inventory_2_outlined),
                          ),
                        ),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      item['name']?.toString() ??
                          item['name_snapshot']?.toString() ??
                          '-',
                      style: const TextStyle(fontWeight: FontWeight.w800),
                    ),
                    if (sku.isNotEmpty) ...[
                      const SizedBox(height: 3),
                      Text(
                        sku,
                        style: const TextStyle(
                          color: Color(0xFF667085),
                          fontSize: 12,
                        ),
                      ),
                    ],
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: 10),
          _OrderInlineRow(
            label: context.tr('b2b.order.quantity'),
            value: item['quantity']?.toString() ?? '-',
          ),
          _OrderInlineRow(
            label: context.tr('b2b.order.pack'),
            value: compactNumber(pack),
          ),
          _OrderInlineRow(
            label: context.tr('b2b.order.unit_price'),
            value: money(item['unit_price'], currency: currency),
          ),
          _OrderInlineRow(
            label: context.tr('b2b.order.line_total'),
            value: money(item['line_total'], currency: currency),
          ),
        ],
      ),
    );
  }
}

class _OrderInlineRow extends StatelessWidget {
  const _OrderInlineRow({
    required this.label,
    required this.value,
  });

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(top: 6),
        child: Row(
          children: [
            Expanded(child: Text(label)),
            const SizedBox(width: 8),
            Text(
              value,
              style: const TextStyle(fontWeight: FontWeight.w700),
            ),
          ],
        ),
      );
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

class _WholesaleOrdersHeader extends StatelessWidget {
  const _WholesaleOrdersHeader({
    required this.pageInset,
    required this.loading,
    required this.onRefresh,
  });

  final double pageInset;
  final bool loading;
  final VoidCallback onRefresh;

  @override
  Widget build(BuildContext context) => Padding(
        padding: EdgeInsetsDirectional.fromSTEB(
          pageInset,
          4,
          pageInset,
          0,
        ),
        child: SizedBox(
          width: double.infinity,
          height: 58,
          child: Stack(
            alignment: Alignment.center,
            children: [
              Text(
                context.tr('customer.profile.orders'),
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                      color: CustomerUiColors.ink,
                      fontWeight: FontWeight.w900,
                      fontSize: 20,
                      height: 1.1,
                    ),
              ),
              Positioned(
                left: 0,
                child: Semantics(
                  button: true,
                  label: context.tr('customer.orders.refresh'),
                  child: Tooltip(
                    message: context.tr('customer.orders.refresh'),
                    child: Material(
                      color: CustomerUiColors.mintStrong.withOpacity(.45),
                      shape: const CircleBorder(),
                      clipBehavior: Clip.antiAlias,
                      child: InkWell(
                        key: const ValueKey('b2b-orders-refresh'),
                        customBorder: const CircleBorder(),
                        onTap: loading ? null : onRefresh,
                        child: SizedBox.square(
                          dimension: 42,
                          child: loading
                              ? const Padding(
                                  padding: EdgeInsets.all(12),
                                  child: CircularProgressIndicator(
                                    strokeWidth: 2,
                                    color: CustomerUiColors.deepGreenStrong,
                                  ),
                                )
                              : const Icon(
                                  Icons.refresh_rounded,
                                  size: 24,
                                  color: CustomerUiColors.ink,
                                ),
                        ),
                      ),
                    ),
                  ),
                ),
              ),
            ],
          ),
        ),
      );
}

class _OrderTabs extends StatelessWidget {
  const _OrderTabs({
    required this.selected,
    required this.onChanged,
    required this.pageInset,
    required this.compact,
    required this.enabled,
  });

  final String selected;
  final ValueChanged<String> onChanged;
  final double pageInset;
  final bool compact;
  final bool enabled;

  @override
  Widget build(BuildContext context) {
    const values = <String>[
      'all',
      'pending',
      'preparing',
      'out_for_delivery',
      'delivered',
    ];

    return SizedBox(
      height: compact ? 54 : 58,
      child: SingleChildScrollView(
        key: const ValueKey('b2b-orders-status-filters'),
        scrollDirection: Axis.horizontal,
        physics: const BouncingScrollPhysics(),
        padding: EdgeInsetsDirectional.fromSTEB(
          pageInset,
          5,
          pageInset,
          compact ? 7 : 9,
        ),
        child: Row(
          children: [
            for (var index = 0; index < values.length; index++) ...[
              _OrderFilterPill(
                key: ValueKey('b2b-orders-filter-' + values[index]),
                value: values[index],
                label: _wholesaleOrderFilterLabel(context, values[index]),
                selected: selected == values[index],
                compact: compact,
                enabled: enabled,
                showFilterIcon: values[index] == 'all',
                onTap: () => onChanged(values[index]),
              ),
              if (index != values.length - 1)
                SizedBox(width: compact ? 6 : 8),
            ],
          ],
        ),
      ),
    );
  }
}

class _OrderFilterPill extends StatelessWidget {
  const _OrderFilterPill({
    required this.value,
    required this.label,
    required this.selected,
    required this.compact,
    required this.enabled,
    required this.showFilterIcon,
    required this.onTap,
    super.key,
  });

  final String value;
  final String label;
  final bool selected;
  final bool compact;
  final bool enabled;
  final bool showFilterIcon;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final palette = FoodexPalette.wholesale;
    final foreground =
        selected ? CustomerUiColors.white : CustomerUiColors.inkSoft;

    return Semantics(
      button: true,
      selected: selected,
      label: label,
      child: Material(
        color: Colors.transparent,
        child: InkWell(
          borderRadius: BorderRadius.circular(CustomerUiRadii.pill),
          onTap: enabled ? onTap : null,
          child: AnimatedContainer(
            duration: CustomerUiMotion.resolve(
              context,
              CustomerUiMotion.standard,
            ),
            curve: CustomerUiMotion.standardCurve,
            height: compact ? 40 : 44,
            constraints: BoxConstraints(
              minWidth: compact ? 58 : 64,
            ),
            padding: EdgeInsets.symmetric(
              horizontal: compact ? 10 : 14,
            ),
            decoration: BoxDecoration(
              color: selected ? palette.primary : CustomerUiColors.white,
              borderRadius: BorderRadius.circular(CustomerUiRadii.pill),
              border: Border.all(
                color: selected ? palette.primary : CustomerUiColors.border,
              ),
              boxShadow: selected
                  ? [
                      BoxShadow(
                        color: CustomerUiColors.shadow,
                        blurRadius: 8,
                        offset: const Offset(0, 3),
                      ),
                    ]
                  : const <BoxShadow>[],
            ),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                if (showFilterIcon) ...[
                  Icon(
                    Icons.filter_list_rounded,
                    size: compact ? 18 : 20,
                    color: foreground,
                  ),
                  SizedBox(width: compact ? 5 : 7),
                ],
                Text(
                  label,
                  maxLines: 1,
                  softWrap: false,
                  style: Theme.of(context).textTheme.labelLarge?.copyWith(
                        color: foreground,
                        fontSize: compact ? 11.5 : 12.5,
                        fontWeight:
                            selected ? FontWeight.w900 : FontWeight.w700,
                      ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _OrderCard extends StatelessWidget {
  const _OrderCard({
    required this.row,
    required this.compact,
  });

  final Map<String, dynamic> row;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final palette = FoodexPalette.wholesale;
    final id = intValue(row['id']);
    final store = row['store'];
    final storeName = row['store_name']?.toString().trim().isNotEmpty == true
        ? row['store_name'].toString().trim()
        : (store is Map && store['name']?.toString().trim().isNotEmpty == true)
            ? store['name'].toString().trim()
            : context.tr('customer.orders.channel.wholesale');
    final currency = (row['currency']?.toString().trim().isNotEmpty == true
            ? row['currency'].toString().trim()
            : 'EGP')
        .toUpperCase();
    final total = row['grand_total'] ?? row['total'];
    final totalLabel = _compactOrderAmount(total, currency);
    final title = row['order_number']?.toString().trim().isNotEmpty == true
        ? row['order_number'].toString().trim()
        : '#' + (row['id']?.toString() ?? '');
    final createdAt = _friendlyOrderDate(row['created_at']?.toString());
    final canOpen = id > 0;

    return Semantics(
      button: canOpen,
      label: title,
      child: Material(
        key: ValueKey('b2b-order-row-' + id.toString()),
        color: CustomerUiColors.white,
        borderRadius: BorderRadius.circular(compact ? 18 : 20),
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: canOpen
              ? () => Navigator.of(context)
                  .pushNamed('/b2b/orders/' + id.toString())
              : null,
          child: Container(
            constraints: BoxConstraints(
              minHeight: compact ? 86 : 94,
            ),
            padding: EdgeInsetsDirectional.fromSTEB(
              compact ? 9 : 12,
              compact ? 8 : 9,
              compact ? 9 : 12,
              compact ? 8 : 9,
            ),
            decoration: BoxDecoration(
              color: CustomerUiColors.white,
              borderRadius: BorderRadius.circular(compact ? 18 : 20),
              border: Border.all(
                color: CustomerUiColors.border.withOpacity(.72),
              ),
              boxShadow: CustomerUiElevation.cardShadow,
            ),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.center,
              children: [
                _OrderOpenAffordance(
                  orderId: id,
                  compact: compact,
                  enabled: canOpen,
                ),
                SizedBox(width: compact ? 7 : 10),
                Expanded(
                  child: Column(
                    textDirection: TextDirection.ltr,
                    mainAxisAlignment: MainAxisAlignment.center,
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                        Tooltip(
                          message: title,
                          child: Text(
                            title,
                            textDirection: TextDirection.ltr,
                            maxLines: 1,
                            softWrap: false,
                            overflow: TextOverflow.ellipsis,
                            style: Theme.of(context).textTheme.titleMedium?.copyWith(
                                  color: CustomerUiColors.ink,
                                  fontSize: compact ? 12.8 : 14,
                                  fontWeight: FontWeight.w900,
                                  height: 1.08,
                                ),
                          ),
                        ),
                        SizedBox(height: compact ? 3 : 4),
                        Text(
                          storeName,
                          textDirection: TextDirection.ltr,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                                color: CustomerUiColors.muted,
                                fontSize: compact ? 11 : 12.5,
                                height: 1.05,
                              ),
                        ),
                        if (createdAt != '-') ...[
                          SizedBox(height: compact ? 4 : 5),
                          Row(
                            textDirection: TextDirection.ltr,
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              Flexible(
                                child: Text(
                                  createdAt,
                                  textDirection: TextDirection.ltr,
                                  maxLines: 1,
                                  softWrap: false,
                                  overflow: TextOverflow.ellipsis,
                                  style: Theme.of(context)
                                      .textTheme
                                      .bodySmall
                                      ?.copyWith(
                                        color: CustomerUiColors.muted,
                                        fontSize: compact ? 10 : 11,
                                        height: 1.05,
                                      ),
                                ),
                              ),
                              SizedBox(width: compact ? 5 : 7),
                              Icon(
                                Icons.calendar_today_outlined,
                                size: compact ? 14 : 16,
                                color: CustomerUiColors.muted,
                              ),
                            ],
                          ),
                        ],
                        SizedBox(height: compact ? 4 : 5),
                        Text(
                          totalLabel,
                          textDirection: TextDirection.ltr,
                          maxLines: 1,
                          softWrap: false,
                          overflow: TextOverflow.ellipsis,
                          style: Theme.of(context).textTheme.titleMedium?.copyWith(
                                color: palette.primary,
                                fontSize: compact ? 12.8 : 14.5,
                                fontWeight: FontWeight.w900,
                                height: 1.05,
                              ),
                        ),
                      ],
                  ),
                ),
                SizedBox(width: compact ? 7 : 10),
                _StatusPill(
                  status: row['status']?.toString() ?? '',
                  compact: compact,
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _OrderOpenAffordance extends StatelessWidget {
  const _OrderOpenAffordance({
    required this.orderId,
    required this.compact,
    required this.enabled,
  });

  final int orderId;
  final bool compact;
  final bool enabled;

  @override
  Widget build(BuildContext context) => ExcludeSemantics(
        child: Opacity(
          opacity: enabled ? 1 : .45,
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(
                Icons.chevron_right_rounded,
                size: compact ? 21 : 24,
                color: CustomerUiColors.inkSoft,
              ),
              SizedBox(width: compact ? 2 : 4),
              Container(
                key: ValueKey('b2b-order-receipt-' + orderId.toString()),
                width: compact ? 38 : 42,
                height: compact ? 38 : 42,
                decoration: BoxDecoration(
                  color: CustomerUiColors.mint,
                  borderRadius: BorderRadius.circular(compact ? 11 : 13),
                ),
                child: Icon(
                  Icons.receipt_long_outlined,
                  size: compact ? 23 : 25,
                  color: FoodexPalette.wholesale.primary,
                ),
              ),
            ],
          ),
        ),
      );
}

class _StatusPill extends StatelessWidget {
  const _StatusPill({
    required this.status,
    required this.compact,
  });

  final String status;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final foreground = _wholesaleOrderStatusColor(status);
    final label = _wholesaleOrderStatusLabel(context, status);

    return Container(
      constraints: BoxConstraints(
        minWidth: compact ? 66 : 74,
        maxWidth: compact ? 84 : 100,
      ),
      padding: EdgeInsetsDirectional.fromSTEB(
        compact ? 7 : 9,
        compact ? 5 : 6,
        compact ? 7 : 9,
        compact ? 5 : 6,
      ),
      decoration: BoxDecoration(
        color: foreground.withOpacity(.10),
        borderRadius: BorderRadius.circular(CustomerUiRadii.pill),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Flexible(
            child: Text(
              label,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: Theme.of(context).textTheme.labelMedium?.copyWith(
                    color: foreground,
                    fontWeight: FontWeight.w900,
                    fontSize: compact ? 10.5 : 11.5,
                    height: 1.05,
                  ),
            ),
          ),
          SizedBox(width: compact ? 5 : 7),
          Container(
            width: compact ? 8 : 9,
            height: compact ? 8 : 9,
            decoration: BoxDecoration(
              color: foreground,
              shape: BoxShape.circle,
            ),
          ),
        ],
      ),
    );
  }
}

String _wholesaleOrderFilterLabel(BuildContext context, String value) {
  switch (value) {
    case 'all':
      return context.tr('customer.orders.status.all');
    case 'pending':
      return context.tr('customer.orders.status.new_filter');
    case 'preparing':
      return context.tr('customer.orders.status.preparing_filter');
    case 'out_for_delivery':
      return context.tr('customer.orders.status.delivery_filter');
    case 'delivered':
      return context.tr('customer.orders.status.completed_filter');
    default:
      return _orderStatusText(context, value);
  }
}

String _wholesaleOrderStatusLabel(BuildContext context, String status) {
  switch (status.trim().toLowerCase()) {
    case 'pending':
      return context.tr('customer.orders.status.new_filter');
    case 'processing':
    case 'preparing':
    case 'confirmed':
      return context.tr('customer.orders.status.preparing_filter');
    case 'shipped':
    case 'in_delivery':
    case 'out_for_delivery':
      return context.tr('customer.orders.status.delivery_filter');
    case 'completed':
      return context.tr('customer.orders.status.completed_filter');
    case 'ready':
      return context.tr('customer.orders.status.ready_compact');
    default:
      return _orderStatusText(context, status.trim().toLowerCase());
  }
}

Color _wholesaleOrderStatusColor(String status) {
  switch (status.trim().toLowerCase()) {
    case 'cancelled':
    case 'canceled':
    case 'failed':
      return CustomerUiColors.destructive;
    case 'shipped':
    case 'in_delivery':
    case 'out_for_delivery':
      return CustomerUiColors.info;
    case 'processing':
    case 'preparing':
    case 'confirmed':
      return CustomerUiColors.warning;
    case 'pending':
    case 'delivered':
    case 'completed':
      return CustomerUiColors.success;
    default:
      return CustomerUiColors.deepGreenSoft;
  }
}

String _compactOrderAmount(Object? value, String currency) {
  final number = value is num ? value.toDouble() : double.tryParse(value?.toString() ?? '');
  if (number == null) return currency + ' —';
  var amount = number.toStringAsFixed(3);
  amount = amount.replaceFirst(RegExp(r'\.?0+$'), '');
  return currency + ' ' + amount;
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
