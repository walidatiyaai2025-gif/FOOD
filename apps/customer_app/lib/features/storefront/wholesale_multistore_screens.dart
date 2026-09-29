// ignore_for_file: prefer_interpolation_to_compose_strings, deprecated_member_use

import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../../core/api/b2b_api.dart';
import '../../core/api/customer_action_api.dart';
import '../../core/api/storefront_api.dart';
import '../../core/api/wholesale_commerce_api.dart';
import '../../core/auth/customer_session.dart';
import '../../core/localization/app_translations.dart';
import '../../core/routing/customer_routes.dart';
import 'storefront_design_system.dart';

class WholesaleHomeDesignScreen extends StatefulWidget {
  const WholesaleHomeDesignScreen({
    required this.location,
    required this.api,
    required this.storefrontApi,
    required this.actionApi,
    required this.session,
    super.key,
  });

  final String location;
  final B2bApi? api;
  final StorefrontApi? storefrontApi;
  final CustomerActionApi actionApi;
  final CustomerSession session;

  @override
  State<WholesaleHomeDesignScreen> createState() =>
      _WholesaleHomeDesignScreenState();
}

class _WholesaleHomeDesignScreenState
    extends State<WholesaleHomeDesignScreen> {
  final search = TextEditingController();
  late final int storeId = wholesaleStoreId(widget.location);
  late Future<Map<String, dynamic>> future = _load();

  Future<Map<String, dynamic>> _load([String query = '']) async {
    if (storeId <= 0) {
      final publicApi = widget.storefrontApi;
      if (publicApi == null) {
        return const {
          'products': {'data': <Object>[]},
          'storefront': <String, Object?>{},
          'retail_banners': <Object>[],
        };
      }

      final marketplace = await publicApi.platformHome(query: query);
      return {
        'products': marketplace['products'] ?? const {'data': <Object>[]},
        'storefront': marketplace,
        'retail_banners': marketplace['retail_banners'] ?? const <Object>[],
      };
    }

    if (widget.api == null) {
      return const {
        'products': {'data': <Object>[]},
        'storefront': <String, Object?>{},
        'retail_banners': <Object>[],
      };
    }

    var endpoint =
        '/api/v1/b2b/products?store_id=' + storeId.toString();
    if (query.trim().isNotEmpty) {
      endpoint += '&q=' + Uri.encodeQueryComponent(query.trim());
    }

    final products = await widget.api!.get(endpoint);
    Map<String, dynamic> storefront = <String, dynamic>{};
    if (widget.storefrontApi != null) {
      storefront = await widget.storefrontApi!.wholesaleHome(storeId);
    }

    return {
      'products': products,
      'storefront': storefront,
      'retail_banners': const <Object>[],
    };
  }

  @override
  void dispose() {
    search.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Directionality(
        textDirection: TextDirection.rtl,
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
            final effectiveStoreId = intValue(store['id']) > 0
                ? intValue(store['id'])
                : storeId;
            final retailBanners = (payload['retail_banners'] as List? ??
                    const <Object>[])
                .whereType<Map>()
                .map((row) => Map<String, dynamic>.from(row))
                .toList(growable: false);
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
                      storeId: effectiveStoreId,
                      actionApi: widget.actionApi,
                      palette: palette,
                      authenticated: widget.session.isAuthenticated,
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
                  break;
              }
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
                      authenticated: widget.session.isAuthenticated,
                      onAccount: () => Navigator.of(context).pushNamed(
                        widget.session.isAuthenticated
                            ? CustomerRoutePaths.profile
                            : Uri(
                                path: CustomerRoutePaths.register,
                                queryParameters: {
                                  'return': CustomerRoutePaths.b2bHome,
                                },
                              ).toString(),
                      ),
                      onCart: () => Navigator.of(context).pushNamed(
                        widget.session.isAuthenticated
                            ? '/b2b/cart?store=' + effectiveStoreId.toString()
                            : Uri(
                                path: CustomerRoutePaths.register,
                                queryParameters: {
                                  'return': '/b2b/cart?store=' +
                                      effectiveStoreId.toString(),
                                },
                              ).toString(),
                      ),
                    ),
                    if (retailBanners.isNotEmpty)
                      _RetailStoreBannerStrip(
                        stores: retailBanners,
                        palette: palette,
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
              bottomNavigationBar: _WholesaleBottomNav(
                storeId: effectiveStoreId,
                palette: palette,
                authenticated: widget.session.isAuthenticated,
              ),
            );
          },
        ),
      );
}

class _WholesaleHeader extends StatelessWidget {
  const _WholesaleHeader({
    required this.title,
    required this.onCart,
    required this.onAccount,
    required this.authenticated,
    required this.palette,
    this.logoUrl,
    this.address,
  });

  final String title;
  final String? logoUrl;
  final String? address;
  final VoidCallback onCart;
  final VoidCallback onAccount;
  final bool authenticated;
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
            _RoundHeaderIcon(
              icon: authenticated
                  ? Icons.person_rounded
                  : Icons.person_add_alt_1_rounded,
              onTap: onAccount,
            ),
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

class _RetailStoreBannerStrip extends StatelessWidget {
  const _RetailStoreBannerStrip({
    required this.stores,
    required this.palette,
  });

  final List<Map<String, dynamic>> stores;
  final FoodexPalette palette;

  @override
  Widget build(BuildContext context) {
    final height = (MediaQuery.sizeOf(context).height * .20)
        .clamp(118.0, 176.0)
        .toDouble();

    return Padding(
      padding: const EdgeInsets.fromLTRB(14, 10, 14, 8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            context.tr('customer.marketplace.retail_stores'),
            style: const TextStyle(
              fontSize: 16,
              fontWeight: FontWeight.w900,
            ),
          ),
          const SizedBox(height: 3),
          Text(
            context.tr('customer.marketplace.retail_hint'),
            style: TextStyle(
              color: palette.muted,
              fontSize: 11,
              fontWeight: FontWeight.w600,
            ),
          ),
          const SizedBox(height: 8),
          SizedBox(
            height: height,
            child: ListView.separated(
              key: const ValueKey('platform-retail-banner-strip'),
              scrollDirection: Axis.horizontal,
              itemCount: stores.length,
              separatorBuilder: (_, __) => const SizedBox(width: 10),
              itemBuilder: (context, index) {
                final store = stores[index];
                final id = intValue(store['id']);
                final image = store['banner_url']?.toString();
                final logo = store['logo_url']?.toString();
                return SizedBox(
                  width: (MediaQuery.sizeOf(context).width * .76)
                      .clamp(250.0, 420.0)
                      .toDouble(),
                  child: Material(
                    color: palette.soft,
                    borderRadius: BorderRadius.circular(18),
                    clipBehavior: Clip.antiAlias,
                    child: InkWell(
                      key: ValueKey('platform-retail-banner-$id'),
                      onTap: id <= 0
                          ? null
                          : () => Navigator.of(context)
                              .pushNamed('/retail/$id/home'),
                      child: Stack(
                        fit: StackFit.expand,
                        children: [
                          if (image != null && image.trim().isNotEmpty)
                            Image.network(
                              image,
                              fit: BoxFit.cover,
                              errorBuilder: (_, __, ___) =>
                                  _RetailBannerFallback(
                                name: store['name']?.toString() ?? '',
                                logoUrl: logo,
                                palette: palette,
                              ),
                            )
                          else
                            _RetailBannerFallback(
                              name: store['name']?.toString() ?? '',
                              logoUrl: logo,
                              palette: palette,
                            ),
                          const DecoratedBox(
                            decoration: BoxDecoration(
                              gradient: LinearGradient(
                                begin: Alignment.topCenter,
                                end: Alignment.bottomCenter,
                                colors: [
                                  Colors.transparent,
                                  Color(0xB3000000),
                                ],
                              ),
                            ),
                          ),
                          PositionedDirectional(
                            start: 14,
                            end: 14,
                            bottom: 12,
                            child: Text(
                              store['title']?.toString() ??
                                  store['name']?.toString() ??
                                  '',
                              maxLines: 2,
                              overflow: TextOverflow.ellipsis,
                              style: const TextStyle(
                                color: Colors.white,
                                fontSize: 15,
                                fontWeight: FontWeight.w900,
                              ),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                );
              },
            ),
          ),
        ],
      ),
    );
  }
}

class _RetailBannerFallback extends StatelessWidget {
  const _RetailBannerFallback({
    required this.name,
    required this.logoUrl,
    required this.palette,
  });

  final String name;
  final String? logoUrl;
  final FoodexPalette palette;

  @override
  Widget build(BuildContext context) => Container(
        decoration: BoxDecoration(
          gradient: LinearGradient(
            colors: [palette.primary, palette.primaryDark],
          ),
        ),
        child: Center(
          child: logoUrl != null && logoUrl!.trim().isNotEmpty
              ? Image.network(
                  logoUrl!,
                  width: 76,
                  height: 76,
                  fit: BoxFit.contain,
                  errorBuilder: (_, __, ___) => const Icon(
                    Icons.storefront_rounded,
                    color: Colors.white,
                    size: 54,
                  ),
                )
              : const Icon(
                  Icons.storefront_rounded,
                  color: Colors.white,
                  size: 54,
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
    final categories = rows
        .map((row) => row['category_id'])
        .where((value) => value != null)
        .map((value) => value.toString())
        .toSet()
        .take(6)
        .toList(growable: false);
    final count = math.max(6, categories.length);

    return Padding(
      padding: const EdgeInsets.fromLTRB(14, 0, 14, 4),
      child: GridView.builder(
        shrinkWrap: true,
        physics: const NeverScrollableScrollPhysics(),
        itemCount: count,
        gridDelegate:
            const SliverGridDelegateWithFixedCrossAxisCount(
          crossAxisCount: 3,
          crossAxisSpacing: 9,
          mainAxisSpacing: 9,
          childAspectRatio: 1.45,
        ),
        itemBuilder: (_, index) => Container(
          decoration: BoxDecoration(
            color: palette.soft,
            borderRadius: BorderRadius.circular(16),
          ),
          alignment: Alignment.center,
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Icon(
                Icons.inventory_2_outlined,
                color: palette.primary,
              ),
              const SizedBox(height: 4),
              Text(
                index < categories.length
                    ? 'تصنيف ' + categories[index]
                    : 'المزيد',
                style: const TextStyle(
                  fontSize: 10,
                  fontWeight: FontWeight.w700,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _WholesaleProductGrid extends StatelessWidget {
  const _WholesaleProductGrid({
    required this.rows,
    required this.storeId,
    required this.actionApi,
    required this.palette,
    required this.authenticated,
  });

  final List<Map<String, dynamic>> rows;
  final int storeId;
  final CustomerActionApi actionApi;
  final FoodexPalette palette;
  final bool authenticated;

  @override
  Widget build(BuildContext context) {
    if (rows.isEmpty) {
      return const FoodexEmptyState(
        key: ValueKey('b2b-empty'),
        title: 'لا توجد منتجات جملة',
        subtitle: 'لا توجد منتجات متاحة لهذا الحساب حاليًا.',
      );
    }

    return GridView.builder(
      padding: const EdgeInsets.symmetric(horizontal: 14),
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      itemCount: rows.length,
      gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
        crossAxisCount:
            MediaQuery.sizeOf(context).width < 360 ? 1 : 2,
        crossAxisSpacing: 10,
        mainAxisSpacing: 10,
        childAspectRatio: .78,
      ),
      itemBuilder: (context, index) {
        final row = rows[index];
        final id = intValue(row['id']);
        final minimum = doubleValue(
          row['minimum_order_quantity'] ?? row['minimum_quantity'],
          1,
        );
        final detailRoute = '/b2b/products/' +
            id.toString() +
            '?store_id=' +
            storeId.toString();

        return Material(
          color: Colors.white,
          borderRadius: BorderRadius.circular(18),
          child: InkWell(
            borderRadius: BorderRadius.circular(18),
            onTap: () => Navigator.of(context).pushNamed(detailRoute),
            child: Container(
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(
                border: Border.all(color: palette.soft),
                borderRadius: BorderRadius.circular(18),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(
                    child: FoodexProductImage(
                      url: row['image_url']?.toString(),
                      palette: palette,
                    ),
                  ),
                  const SizedBox(height: 8),
                  Text(
                    row['name']?.toString() ?? '',
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(
                      fontSize: 12,
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                  const SizedBox(height: 5),
                  Text(
                    'سعر العميل ' +
                        money(
                          row['account_price'] ?? row['unit_price'],
                        ),
                    style: TextStyle(
                      color: palette.primary,
                      fontWeight: FontWeight.w800,
                      fontSize: 11,
                    ),
                  ),
                  Text(
                    'الحد الأدنى ' + compactNumber(minimum),
                    style: TextStyle(
                      color: palette.muted,
                      fontSize: 10,
                    ),
                  ),
                  const SizedBox(height: 7),
                  SizedBox(
                    width: double.infinity,
                    height: 38,
                    child: FilledButton.icon(
                      style: FilledButton.styleFrom(
                        backgroundColor: palette.primary,
                        padding:
                            const EdgeInsets.symmetric(horizontal: 8),
                      ),
                      onPressed: authenticated
                          ? () async {
                              try {
                                await actionApi.addCartItem(
                                  storeId: storeId,
                                  productId: id,
                                  quantity: minimum,
                                );
                              } catch (error) {
                                if (context.mounted) {
                                  await showOperationalError(context, error);
                                }
                              }
                            }
                          : () => Navigator.of(context).pushNamed(
                                Uri(
                                  path: CustomerRoutePaths.register,
                                  queryParameters: {'return': detailRoute},
                                ).toString(),
                              ),
                      icon: const Icon(
                        Icons.add_shopping_cart_rounded,
                        size: 16,
                      ),
                      label: const Text(
                        'أضف',
                        style: TextStyle(fontSize: 11),
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
        );
      },
    );
  }
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
            Navigator.of(context).pushNamed('/b2b/orders');
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
  final base = FoodexPalette.wholesale;
  return FoodexPalette(
    primary: _colorFromHex(theme['primary'], base.primary),
    primaryDark:
        _colorFromHex(theme['primary_dark'], base.primaryDark),
    accent: _colorFromHex(theme['accent'], base.accent),
    background: _colorFromHex(theme['background'], base.background),
    soft: base.soft,
    text: base.text,
    muted: base.muted,
  );
}

Color _colorFromHex(Object? value, Color fallback) {
  final text = value?.toString().trim() ?? '';
  final match = RegExp(r'^#([0-9a-fA-F]{6})$').firstMatch(text);
  if (match == null) return fallback;
  return Color(int.parse('FF' + match.group(1)!, radix: 16));
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
    default:
      return 'عروض الجملة';
  }
}

class WholesaleProductDetailsDesignScreen extends StatefulWidget {
  const WholesaleProductDetailsDesignScreen({
    required this.location,
    required this.api,
    required this.actionApi,
    super.key,
  });

  final String location;
  final B2bApi? api;
  final CustomerActionApi actionApi;

  @override
  State<WholesaleProductDetailsDesignScreen> createState() =>
      _WholesaleProductDetailsDesignScreenState();
}

class _WholesaleProductDetailsDesignScreenState
    extends State<WholesaleProductDetailsDesignScreen> {
  late final int storeId = wholesaleStoreId(widget.location);
  late final int productId = productIdFromLocation(widget.location);
  double? quantity;

  @override
  Widget build(BuildContext context) {
    final endpoint = '/api/v1/b2b/products/' +
        productId.toString() +
        '?store_id=' +
        storeId.toString();

    return Directionality(
      textDirection: TextDirection.rtl,
      child: Scaffold(
        backgroundColor: Colors.white,
        body: SafeArea(
          child: FutureBuilder<Object?>(
            future:
                widget.api?.get(endpoint) ?? Future<Object?>.value(null),
            builder: (context, snapshot) {
              if (snapshot.connectionState != ConnectionState.done) {
                return const FoodexLoading(
                  key: ValueKey('b2b-loading'),
                );
              }
              if (snapshot.hasError) {
                return const FoodexErrorState(
                  key: ValueKey('b2b-error'),
                  message: 'تعذر تحميل المنتج.',
                );
              }

              final row = snapshot.data is Map
                  ? Map<String, dynamic>.from(snapshot.data as Map)
                  : <String, dynamic>{};
              if (row.isEmpty) {
                return const FoodexEmptyState(
                  key: ValueKey('b2b-empty'),
                  title: 'المنتج غير متاح',
                  subtitle: 'لا تتوفر بيانات لهذا المنتج.',
                );
              }

              final minimum =
                  doubleValue(row['minimum_order_quantity'], 1);
              final increment =
                  doubleValue(row['ordering_increment'], 1);
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
                  row['currency']?.toString() ?? 'KWD';

              return ListView(
                key: const ValueKey('b2b-product-detail-data'),
                padding: const EdgeInsets.fromLTRB(15, 10, 15, 22),
                children: [
                  FoodexTopBar(
                    title: 'تفاصيل المنتج',
                    actions: [
                      IconButton(
                        onPressed: () {},
                        icon:
                            const Icon(Icons.favorite_border_rounded),
                      ),
                    ],
                  ),
                  const SizedBox(height: 10),
                  FoodexGallery(
                    key: const ValueKey('b2b-product-gallery'),
                    urls: images,
                    palette: FoodexPalette.wholesale,
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
                      color: Color(0xFF6F6A7D),
                      fontSize: 12,
                    ),
                  ),
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
                          label:
                              'الحد الأدنى ' + compactNumber(minimum),
                        ),
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: _InfoPill(
                          icon: Icons.warehouse_outlined,
                          label: 'متاح ' +
                              (row['available_quantity']?.toString() ??
                                  '—'),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 14),
                  FoodexQuantityCta(
                    key: const ValueKey('customer-add-cart'),
                    quantity: quantity!,
                    increment: increment,
                    minimum: minimum,
                    palette: FoodexPalette.wholesale,
                    label: 'إضافة إلى السلة',
                    onChanged: (value) =>
                        setState(() => quantity = value),
                    onPressed: () async {
                      try {
                        await widget.actionApi.addCartItem(
                          storeId: storeId,
                          productId: productId,
                          quantity: quantity!,
                        );
                        if (context.mounted) {
                          Navigator.of(context).pushNamed(
                            '/b2b/cart?store=' + storeId.toString(),
                          );
                        }
                      } catch (error) {
                        if (context.mounted) {
                          await showOperationalError(context, error);
                        }
                      }
                    },
                  ),
                  const SizedBox(height: 15),
                  FoodexDetailAccordion(
                    title: 'تفاصيل العبوة والتحويل',
                    body: 'حجم العبوة: ' +
                        (row['pack_size']?.toString() ?? '1') +
                        ' · حجم الكرتونة: ' +
                        (row['case_size']?.toString() ?? '—') +
                        ' · الزيادة: ' +
                        (row['ordering_increment']?.toString() ?? '1'),
                  ),
                  FoodexDetailAccordion(
                    title: 'الوصف',
                    body: row['description']?.toString() ??
                        'لا توجد تفاصيل إضافية.',
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
          color: Color(0xFFF5F0FA),
          borderRadius: BorderRadius.circular(16),
        ),
        child: Column(
          children: [
            _PriceRow(
              label: 'سعر الجملة الأساسي',
              value: money(
                row['base_wholesale_price'],
                currency: currency,
              ),
            ),
            const Divider(height: 18),
            _PriceRow(
              label: 'سعر حسابك',
              value: money(
                row['account_price'],
                currency: currency,
              ),
              emphasize: true,
            ),
            if (row['retail_reference_price'] != null) ...[
              const Divider(height: 18),
              _PriceRow(
                label: 'سعر التجزئة المرجعي',
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
                  ? Color(0xFF5D2A91)
                  : Color(0xFF17142A),
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
          color: Color(0xFFF5F0FA),
          borderRadius: BorderRadius.circular(13),
        ),
        child: Row(
          children: [
            Icon(
              icon,
              size: 18,
              color: Color(0xFF5D2A91),
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
        textDirection: TextDirection.rtl,
        child: Scaffold(
          backgroundColor: Color(0xFFFBFAFD),
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
                        color: Color(0xFFF5F0FA),
                        borderRadius: BorderRadius.circular(12),
                      ),
                      child: const Row(
                        children: [
                          Icon(
                            Icons.warehouse_outlined,
                            color:
                                Color(0xFF5D2A91),
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
                  color: Color(0xFFF5F0FA),
                  borderRadius:
                      BorderRadius.all(Radius.circular(14)),
                ),
                child: Icon(
                  Icons.inventory_2_outlined,
                  color: Color(0xFF5D2A91),
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
                      color: Color(0xFF6F6A7D),
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
                              Color(0xFF5D2A91),
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
        color: Color(0xFFF5F0FA),
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
          color: Color(0xFF35195E),
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
                      Color(0xFF35195E),
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
        textDirection: TextDirection.rtl,
        child: Scaffold(
          backgroundColor: Color(0xFFFBFAFD),
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
                    if (addresses.isEmpty)
                      const FoodexEmptyState(
                        title: 'لا يوجد عنوان',
                        subtitle:
                            'أضف عنوانًا لحساب الجملة قبل إتمام الطلب.',
                      )
                    else
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
                              Color(0xFF5D2A91),
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
                        color: Color(0xFF6F6A7D),
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
              color: Color(0xFF6F6A7D),
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
                    ? const Color(0xFF5D2A91)
                    : const Color(0xFF17142A),
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
              color: Color(0xFFB983F0),
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
              color: Color(0xFFB983F0),
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
                ? Color(0xFF5D2A91)
                : const Color(0xFFE7E3EA),
            child: Text(
              number,
              style: TextStyle(
                color: active
                    ? Colors.white
                    : Color(0xFF6F6A7D),
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
                      ? Color(0xFF5D2A91)
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
                        ? Color(0xFF5D2A91)
                        : Color(0xFF6F6A7D),
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
                                Color(0xFF6F6A7D),
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

  @override
  Widget build(BuildContext context) => Directionality(
        textDirection: TextDirection.rtl,
        child: Scaffold(
          backgroundColor: Color(0xFFFBFAFD),
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
                    onRetry: () =>
                        setState(() => future = _load()),
                  );
                }

                final rows = dataRows(snapshot.data);
                return ListView(
                  padding: const EdgeInsets.fromLTRB(22, 8, 22, 24),
                  children: [
                    const FoodexTopBar(title: 'طلباتي'),
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
  Widget build(BuildContext context) => Material(
        color: Colors.white,
        borderRadius: BorderRadius.circular(17),
        child: InkWell(
          onTap: () {
            final id = intValue(row['id']);
            if (id > 0) {
              Navigator.of(context)
                  .pushNamed('/b2b/orders/' + id.toString());
            }
          },
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
                    color: Color(0xFFF5F0FA),
                    borderRadius: BorderRadius.circular(13),
                  ),
                  child: const Icon(
                    Icons.receipt_long_outlined,
                    color: Color(0xFF5D2A91),
                  ),
                ),
                const SizedBox(width: 11),
                Expanded(
                  child: Column(
                    crossAxisAlignment:
                        CrossAxisAlignment.start,
                    children: [
                      Text(
                        row['order_number']?.toString() ??
                            '#' +
                                (row['id']?.toString() ?? ''),
                        style: const TextStyle(
                          fontWeight: FontWeight.w800,
                        ),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        row['created_at']?.toString() ?? '',
                        style: const TextStyle(
                          fontSize: 10,
                          color:
                              Color(0xFF6F6A7D),
                        ),
                      ),
                      const SizedBox(height: 5),
                      Text(
                        money(
                          row['grand_total'] ?? row['total'],
                        ),
                        style: const TextStyle(
                          color:
                              Color(0xFF5D2A91),
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
    case 'confirmed':
    case 'preparing':
      return 'قيد التجهيز';
    case 'ready':
    case 'out_for_delivery':
      return 'قيد التوصيل';
    case 'delivered':
      return 'مكتمل';
    case 'cancelled':
      return 'ملغي';
    case 'failed':
      return 'تعذر التسليم';
    default:
      return status;
  }
}
