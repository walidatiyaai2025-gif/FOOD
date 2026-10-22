// ignore_for_file: prefer_interpolation_to_compose_strings, deprecated_member_use

import 'dart:async';
import 'dart:math' as math;
import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:foodex_customer_app/shared/foodex_wave_loading.dart';
import 'package:foodex_formatting/foodex_formatting.dart';
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
import '../../core/theme/customer_display_preferences.dart';
import '../../core/theme/customer_ui_v3_tokens.dart';
import '../../shared/customer_favorite_button.dart';
import '../checkout/customer_checkout_vnext_components.dart';
import 'storefront_design_system.dart';

Future<int> resolveWholesaleStoreId({
  required String location,
  required StorefrontApi? storefrontApi,
  int rememberedStoreId = 0,
}) async {
  final scopedStoreId = wholesaleStoreId(location);
  if (scopedStoreId > 0) return scopedStoreId;
  if (rememberedStoreId > 0) return rememberedStoreId;

  if (storefrontApi == null) return 0;

  final selection = await storefrontApi.selection();
  final entitlements = selection['entitlements'] is Map
      ? Map<String, dynamic>.from(selection['entitlements'] as Map)
      : const <String, dynamic>{};

  final principalFromEntitlements =
      intValue(entitlements['principal_wholesale_store_id']);
  if (principalFromEntitlements > 0) return principalFromEntitlements;

  for (final row in mapRows(selection['wholesale_stores'])) {
    if (row['is_platform_principal'] != true) continue;
    final candidate = intValue(row['id'] ?? row['store_id']);
    if (candidate > 0) return candidate;
  }

  return 0;
}

bool _wholesaleProductIsAvailable(Map<String, dynamic> row) {
  final rawAvailable = row['available_quantity'];
  final available =
      rawAvailable == null ? null : double.tryParse(rawAvailable.toString());

  return row['is_available'] != false &&
      row['availability_state']?.toString().toUpperCase() != 'OUT_OF_STOCK' &&
      (available == null || available > .0001);
}

class WholesaleHomeDesignScreen extends StatefulWidget {
  const WholesaleHomeDesignScreen({
    required this.location,
    required this.session,
    required this.api,
    required this.storefrontApi,
    required this.actionApi,
    this.commerceApi,
    this.favoritesApi,
    this.pendingActionStore,
    this.onLocaleChanged,
    this.onLogout,
    this.showBottomNavigation = true,
    super.key,
  });

  final String location;
  final CustomerSession session;
  final B2bApi? api;
  final StorefrontApi? storefrontApi;
  final CustomerActionApi actionApi;
  final WholesaleCommerceApi? commerceApi;
  final B2cRetailFavoritesApi? favoritesApi;
  final CustomerPendingActionStore? pendingActionStore;
  final ValueChanged<Locale>? onLocaleChanged;
  final Future<void> Function()? onLogout;
  final bool showBottomNavigation;

  @override
  State<WholesaleHomeDesignScreen> createState() =>
      _WholesaleHomeDesignScreenState();
}

class _WholesaleHomeDesignScreenState extends State<WholesaleHomeDesignScreen>
    with WidgetsBindingObserver {
  final search = TextEditingController();
  final campaignPopups = CustomerNotificationCampaignPopupService();
  final liveAds = CustomerLiveAdService();
  int _resolvedStoreId = 0;
  int get storeId {
    final scopedStoreId = wholesaleStoreId(widget.location);
    return scopedStoreId > 0 ? scopedStoreId : _resolvedStoreId;
  }

  late Future<Map<String, dynamic>> future = _load();
  late WholesaleProductCardSize cardSize;
  bool _liveAdScheduled = false;

  Future<void> _showHomeVehicleIntro() async {
    if (!mounted) return;
    if (WidgetsBinding.instance.runtimeType
        .toString()
        .contains('TestWidgetsFlutterBinding')) {
      return;
    }

    final overlay = Overlay.maybeOf(context, rootOverlay: true);
    if (overlay == null) return;

    final completed = Completer<void>();
    var removed = false;
    late final OverlayEntry entry;

    void finish() {
      if (!removed) {
        removed = true;
        entry.remove();
      }
      if (!completed.isCompleted) completed.complete();
    }

    entry = OverlayEntry(
      builder: (_) => _WholesaleFirstHomeVehicleIntro(
        onFinished: finish,
      ),
    );
    overlay.insert(entry);

    await completed.future.timeout(
      const Duration(seconds: 7),
      onTimeout: () {
        finish();
      },
    );
  }

  @override
  void initState() {
    super.initState();
    cardSize =
        CustomerDisplayPreferences.instance.wholesaleProductCardSize.value;
    CustomerDisplayPreferences.instance.wholesaleProductCardSize
        .addListener(_onHomeProductCardSizeChanged);
    WidgetsBinding.instance.addObserver(this);
  }

  void _onHomeProductCardSizeChanged() {
    if (!mounted) return;
    setState(() {
      cardSize =
          CustomerDisplayPreferences.instance.wholesaleProductCardSize.value;
    });
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed && mounted) {
      setState(() => future = _load(search.text));
    }
  }

  Future<int> _resolveWholesaleHomeStoreId() async {
    final resolved = await resolveWholesaleStoreId(
      location: widget.location,
      storefrontApi: widget.storefrontApi,
      rememberedStoreId: _resolvedStoreId,
    );
    if (resolved > 0) _resolvedStoreId = resolved;
    return resolved;
  }

  Future<Map<String, dynamic>> _load([String query = '']) async {
    final resolvedStoreId = await _resolveWholesaleHomeStoreId();
    if (resolvedStoreId <= 0) {
      syncFoodexWholesaleCartProductCount(null);
      return const {
        'products': {'data': <Object>[]},
        'storefront': <String, Object?>{},
      };
    }

    _resolvedStoreId = resolvedStoreId;

    Map<String, dynamic> storefront = <String, dynamic>{};
    if (widget.storefrontApi != null) {
      storefront = await widget.storefrontApi!.wholesaleHome(resolvedStoreId);
    }

    Object? products;
    if (widget.api != null) {
      try {
        var endpoint =
            '/api/v1/b2b/products?store_id=' + resolvedStoreId.toString();
        if (query.trim().isNotEmpty) {
          endpoint += '&q=' + Uri.encodeQueryComponent(query.trim());
        }
        products = await widget.api!.get(endpoint);
      } catch (_) {
        products = <String, Object?>{
          'data': _filterWholesaleHomeRows(
            mapRows(storefront['products']),
            query,
          ),
        };
      }
    } else {
      products = <String, Object?>{
        'data': _filterWholesaleHomeRows(
          mapRows(storefront['products']),
          query,
        ),
      };
    }

    Object? cart;
    if (widget.commerceApi != null) {
      try {
        cart = await widget.commerceApi!.cart(resolvedStoreId);
        syncFoodexWholesaleCartProductCount(cart);
      } catch (_) {
        // Never leave a stale badge visible when cart refresh fails.
        syncFoodexWholesaleCartProductCount(null);
      }
    } else {
      syncFoodexWholesaleCartProductCount(null);
    }

    return {
      'products': products,
      'storefront': storefront,
      if (cart != null) 'cart': cart,
    };
  }

  List<Map<String, dynamic>> _filterWholesaleHomeRows(
    List<Map<String, dynamic>> rows,
    String query,
  ) {
    final needle = query.trim().toLowerCase();
    if (needle.isEmpty) return rows;
    return rows.where((row) {
      final name = row['name']?.toString().toLowerCase() ?? '';
      final sku = row['sku']?.toString().toLowerCase() ?? '';
      return name.contains(needle) || sku.contains(needle);
    }).toList(growable: false);
  }

  @override
  void reassemble() {
    super.reassemble();
    // Hot reload does not re-run top-level initializers, so clear any old
    // cart badge and re-read the authoritative cart immediately.
    syncFoodexWholesaleCartProductCount(null);
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      setState(() => future = _load(search.text));
    });
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    CustomerDisplayPreferences.instance.wholesaleProductCardSize
        .removeListener(_onHomeProductCardSizeChanged);
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
              return Scaffold(
                body: FoodexUnifiedHeaderSurface(
                  title: context.tr('b2b.app.title'),
                  location: widget.location,
                  searchHint: context.tr(
                    'customer.products.search_name_sku_barcode',
                  ),
                  child: const FoodexLoading(
                    key: ValueKey('b2b-loading'),
                  ),
                ),
              );
            }
            if (snapshot.hasError) {
              return Scaffold(
                body: FoodexUnifiedHeaderSurface(
                  title: context.tr('b2b.app.title'),
                  location: widget.location,
                  searchHint: context.tr(
                    'customer.products.search_name_sku_barcode',
                  ),
                  child: FoodexErrorState(
                    key: const ValueKey('b2b-error'),
                    message: 'تعذر تحميل متجر الجملة.',
                    onRetry: () => setState(() => future = _load(search.text)),
                  ),
                ),
              );
            }

            if (!_liveAdScheduled) {
              _liveAdScheduled = true;
              WidgetsBinding.instance.addPostFrameCallback((_) async {
                if (!mounted) return;
                await _showHomeVehicleIntro();
                if (!mounted || !context.mounted) return;
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
            final hero = storefront['hero'] is Map
                ? Map<String, dynamic>.from(storefront['hero'] as Map)
                : <String, dynamic>{};
            final banners = mapRows(storefront['banners']);
            final palette = _wholesalePalette(theme);

            final title =
                custom['brand_title_ar']?.toString().trim().isNotEmpty == true
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
                .toList(growable: true)
              ..sort(
                (left, right) => intValue(left['sort_order'])
                    .compareTo(intValue(right['sort_order'])),
              );
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
                      'sort_order': 30
                    },
                  ]
                : sections;

            void openCatalog([
              Map<String, String> filters = const <String, String>{},
            ]) {
              if (storeId <= 0) return;
              Navigator.of(context).pushNamed(
                Uri(
                  path: CustomerRoutePaths.b2bProducts,
                  queryParameters: <String, String>{
                    'channel': 'wholesale',
                    'store_id': storeId.toString(),
                    ...filters,
                  },
                ).toString(),
              );
            }

            final content = <Widget>[];
            var homeCardSizeControlPlaced = false;

            Widget? takeHomeCardSizeControl() {
              if (homeCardSizeControlPlaced) return null;
              homeCardSizeControlPlaced = true;
              final icon = switch (cardSize) {
                WholesaleProductCardSize.compact => Icons.view_module_rounded,
                WholesaleProductCardSize.comfortable =>
                  Icons.grid_view_rounded,
                WholesaleProductCardSize.expanded => Icons.view_agenda_rounded,
              };
              return SizedBox(
                width: 30,
                height: 30,
                child: IconButton(
                  key: const ValueKey('wholesale-home-card-size'),
                  tooltip: context.tr('b2b.catalog.card_size'),
                  padding: EdgeInsets.zero,
                  visualDensity: VisualDensity.compact,
                  onPressed: () async {
                    await CustomerDisplayPreferences.instance
                        .cycleWholesaleProductCardSize();
                  },
                  icon: Icon(icon, size: 17),
                ),
              );
            }

            final hasBannerSection = effectiveSections.any((section) {
              final type = section['type']?.toString() ?? '';
              return type == 'hero' || type == 'banner_slider';
            });
            if (banners.isNotEmpty && !hasBannerSection) {
              content.add(
                _WholesaleBannerCarousel(
                  banners: banners,
                  fallbackTitle: heroTitle,
                  cta: heroCta,
                  palette: palette,
                  onCta: storeId > 0 ? () => openCatalog() : null,
                ),
              );
            }

            for (final section in effectiveSections) {
              final type = section['type']?.toString() ?? '';
              final configuredSectionTitle =
                  section['title_ar']?.toString().trim().isNotEmpty == true
                      ? section['title_ar'].toString()
                      : _defaultWholesaleSectionTitle(type);
              final sectionTitle = type == 'brands' ||
                      configuredSectionTitle.trim() == 'منتجاتنا'
                  ? context.tr('b2b.home.our_brands')
                  : configuredSectionTitle;

              switch (type) {
                case 'hero':
                case 'banner_slider':
                  content.add(
                    _WholesaleBannerCarousel(
                      banners: banners.isNotEmpty
                          ? banners
                          : hero.isEmpty
                              ? const <Map<String, dynamic>>[]
                              : <Map<String, dynamic>>[hero],
                      fallbackTitle: heroTitle,
                      cta: heroCta,
                      palette: palette,
                      onCta: storeId > 0 ? () => openCatalog() : null,
                    ),
                  );
                  break;
                case 'categories':
                case 'departments':
                  content.add(
                    FoodexSectionHeader(
                      title: sectionTitle,
                      palette: palette,
                      textScale: .8,
                      spacingScale: .25,
                      animatedGoldTitle: type == 'categories',
                      onAction: storeId > 0
                          ? () => openCatalog(const <String, String>{
                                'browse': 'categories',
                              })
                          : null,
                    ),
                  );
                  content.add(
                    _WholesaleCategoryGrid(
                      rows: rows,
                      palette: palette,
                      storeId: storeId,
                    ),
                  );
                  break;
                case 'offers':
                case 'featured_products':
                case 'best_sellers':
                case 'reorder':
                case 'product_grid':
                case 'product_carousel':
                  {
                    final sectionRows = _wholesaleRowsForSection(rows, section);
                    final cardSizeControl = takeHomeCardSizeControl();
                    content.add(
                      type == 'best_sellers'
                          ? _WholesaleBestSellerTickerHeader(
                              title: sectionTitle,
                              rows: sectionRows,
                              palette: palette,
                              storeId: storeId,
                              onAction: storeId > 0
                                  ? () => openCatalog(
                                        _wholesaleSectionFilters(
                                          section,
                                          rows: sectionRows,
                                        ),
                                      )
                                  : null,
                              trailing: cardSizeControl,
                            )
                          : FoodexSectionHeader(
                              title: sectionTitle,
                              palette: palette,
                              textScale: .8,
                              spacingScale: .25,
                              onAction: storeId > 0
                                  ? () => openCatalog(
                                        _wholesaleSectionFilters(
                                          section,
                                          rows: sectionRows,
                                        ),
                                      )
                                  : null,
                              trailing: cardSizeControl,
                            ),
                    );
                    content.add(
                      _WholesaleProductGrid(
                        rows: sectionRows,
                        storeId: storeId,
                        sourceLocation: widget.location,
                        session: widget.session,
                        actionApi: widget.actionApi,
                        pendingActionStore: widget.pendingActionStore,
                        palette: palette,
                        favoritesApi: widget.favoritesApi,
                        maxItems: type == 'best_sellers' ? 9 : 3,
                        columnsOverride: cardSize.columns,
                        showCommerceActions: true,
                        homeWholeNumberPrice: true,
                      ),
                    );
                    break;
                  }
                case 'brands':
                  content.add(
                    _WholesaleBrandTickerHeader(
                      title: sectionTitle,
                      rows: rows,
                      palette: palette,
                      storeId: storeId,
                      onAction: storeId > 0
                          ? () => openCatalog(const <String, String>{
                                'browse': 'brands',
                              })
                          : null,
                    ),
                  );
                  content.add(
                    _WholesaleBrandRail(
                      rows: rows,
                      palette: palette,
                      storeId: storeId,
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
              (section) => productSectionTypes
                  .contains(section['type']?.toString() ?? ''),
            );
            if (rows.isNotEmpty && !hasProductSection) {
              content.add(
                FoodexSectionHeader(
                  title: context.tr('customer.products.title'),
                  palette: palette,
                  textScale: .8,
                  spacingScale: .25,
                  onAction: storeId > 0 ? () => openCatalog() : null,
                  trailing: takeHomeCardSizeControl(),
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
                  favoritesApi: widget.favoritesApi,
                  maxItems: 3,
                  columnsOverride: cardSize.columns,
                  showCommerceActions: true,
                  homeWholeNumberPrice: true,
                ),
              );
            }

            return Scaffold(
              backgroundColor: Theme.of(context).brightness == Brightness.dark
                  ? Theme.of(context).scaffoldBackgroundColor
                  : palette.background,
              body: SafeArea(
                child: Column(
                  children: [
                    FoodexWholesaleHeader(
                      title: title,
                      logoUrl: branding['logo_url']?.toString(),
                      address: branding['address']?.toString(),
                      palette: palette,
                      location: widget.location,
                      isAuthenticated: widget.session.isAuthenticated,
                      onLocaleChanged: widget.onLocaleChanged,
                      onLogout: widget.onLogout,
                      searchController: search,
                      searchKey: const ValueKey('wholesale-home-search'),
                      searchHint: context.tr(
                        'customer.products.search_name_sku_barcode',
                      ),
                      onSearchSubmitted: (value) =>
                          setState(() => future = _load(value)),
                      onCart: () => Navigator.of(context)
                          .pushNamed('/b2b/cart?store=' + storeId.toString()),
                    ),
                    Expanded(
                      child: ListView(
                        key: const ValueKey('wholesale-home-scroll-body'),
                        padding: EdgeInsets.zero,
                        children: [
                          ...content,
                          const SizedBox(height: 5.5),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
              bottomNavigationBar: widget.showBottomNavigation
                  ? _WholesaleBottomNav(storeId: storeId, palette: palette)
                  : null,
            );
          },
        ),
      );
}

class _WholesaleFirstHomeVehicleIntro extends StatefulWidget {
  const _WholesaleFirstHomeVehicleIntro({
    required this.onFinished,
  });

  final VoidCallback onFinished;

  @override
  State<_WholesaleFirstHomeVehicleIntro> createState() =>
      _WholesaleFirstHomeVehicleIntroState();
}

class _WholesaleFirstHomeVehicleIntroState
    extends State<_WholesaleFirstHomeVehicleIntro>
    with SingleTickerProviderStateMixin {
  late final AnimationController controller;
  bool finished = false;

  @override
  void initState() {
    super.initState();
    controller = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 4800),
    )
      ..addStatusListener((status) {
        if (status == AnimationStatus.completed) _finish();
      })
      ..forward();
  }

  void _finish() {
    if (finished) return;
    finished = true;
    widget.onFinished();
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (CustomerUiMotion.isReduced(context) && controller.duration != null) {
      controller.duration = const Duration(milliseconds: 700);
    }
  }

  @override
  void dispose() {
    controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Positioned.fill(
        child: IgnorePointer(
          child: AnimatedBuilder(
            animation: controller,
            builder: (context, _) {
              final size = MediaQuery.sizeOf(context);
              final rtl = Directionality.of(context) == TextDirection.rtl;
              final t = controller.value;
              final drive = Curves.easeInOutCubic.transform(
                ((t - .10) / .80).clamp(0.0, 1.0).toDouble(),
              );
              final zoomOut = Curves.easeOutCubic.transform(
                ((t - .02) / .86).clamp(0.0, 1.0).toDouble(),
              );
              final fadeIn = Curves.easeOut.transform(
                (t / .16).clamp(0.0, 1.0).toDouble(),
              );
              final fadeOut = Curves.easeIn.transform(
                ((t - .84) / .16).clamp(0.0, 1.0).toDouble(),
              );
              final opacity =
                  (fadeIn * (1 - fadeOut)).clamp(0.0, 1.0).toDouble();

              // Start large from the middle of Home, then move away while
              // continuously zooming out for a stronger pseudo-3D exit.
              final centerX = (size.width - 190.0) / 2;
              final exitX = rtl ? -210.0 : size.width + 210.0;
              final x = ui.lerpDouble(centerX, exitX, drive) ?? centerX;
              final scale = ui.lerpDouble(1.48, .62, zoomOut) ?? 1.48;
              final startY = size.height * .43 - 54;
              final y = startY - (drive * 34) + math.sin(t * math.pi * 7) * 1.6;
              final tilt = math.sin(t * math.pi * 4) * .010;
              final wheelTurn = drive * math.pi * 18;

              return Opacity(
                opacity: opacity,
                child: Stack(
                  children: [
                    Positioned.fill(
                      child: DecoratedBox(
                        decoration: BoxDecoration(
                          gradient: RadialGradient(
                            center: const Alignment(0, .05),
                            radius: .85,
                            colors: [
                              Colors.white.withValues(alpha: .08),
                              Colors.transparent,
                            ],
                          ),
                        ),
                      ),
                    ),
                    Positioned(
                      left: x,
                      top: y,
                      child: Transform.rotate(
                        angle: tilt,
                        child: Transform.scale(
                          scale: scale,
                          child: CustomPaint(
                            key: const ValueKey(
                              'wholesale-first-home-vehicle-intro',
                            ),
                            size: const Size(190, 108),
                            painter: _WholesalePseudo3dVehiclePainter(
                              facingLeft: rtl,
                              wheelTurn: wheelTurn,
                            ),
                          ),
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

class _WholesalePseudo3dVehiclePainter extends CustomPainter {
  const _WholesalePseudo3dVehiclePainter({
    required this.facingLeft,
    required this.wheelTurn,
  });

  final bool facingLeft;
  final double wheelTurn;

  @override
  void paint(Canvas canvas, Size size) {
    canvas.save();
    if (facingLeft) {
      canvas.translate(size.width, 0);
      canvas.scale(-1, 1);
    }

    final shadow = Paint()
      ..color = CustomerUiColors.wholesaleVehicleShadow
      ..maskFilter = const MaskFilter.blur(BlurStyle.normal, 8);
    canvas.drawOval(
      Rect.fromCenter(
        center: Offset(size.width * .52, size.height * .82),
        width: size.width * .78,
        height: 18,
      ),
      shadow,
    );

    final boxSide = ui.Gradient.linear(
      const Offset(18, 28),
      const Offset(128, 78),
      const [
        CustomerUiColors.wholesaleVehicleGreenLight,
        CustomerUiColors.wholesaleVehicleGreen,
      ],
    );
    final sidePaint = Paint()..shader = boxSide;
    final topPaint = Paint()..color = CustomerUiColors.wholesaleVehicleTop;
    final frontPaint = Paint()..color = CustomerUiColors.wholesaleVehicleFront;
    final trimPaint = Paint()
      ..color = CustomerUiColors.wholesaleVehicleTrim
      ..style = PaintingStyle.fill;

    final cargoSide = ui.Path()
      ..moveTo(18, 31)
      ..lineTo(119, 31)
      ..lineTo(126, 73)
      ..lineTo(18, 73)
      ..close();
    canvas.drawPath(cargoSide, sidePaint);

    final cargoTop = ui.Path()
      ..moveTo(18, 31)
      ..lineTo(35, 21)
      ..lineTo(119, 21)
      ..lineTo(133, 31)
      ..close();
    canvas.drawPath(cargoTop, topPaint);

    final cab = ui.Path()
      ..moveTo(119, 36)
      ..lineTo(153, 39)
      ..lineTo(174, 59)
      ..lineTo(175, 73)
      ..lineTo(124, 73)
      ..close();
    canvas.drawPath(cab, frontPaint);

    final cabTop = ui.Path()
      ..moveTo(119, 36)
      ..lineTo(131, 28)
      ..lineTo(156, 32)
      ..lineTo(170, 45)
      ..lineTo(153, 39)
      ..close();
    canvas.drawPath(
        cabTop, Paint()..color = CustomerUiColors.wholesaleVehicleCabTop);

    final windshield = ui.Path()
      ..moveTo(134, 34)
      ..lineTo(154, 37)
      ..lineTo(166, 49)
      ..lineTo(143, 47)
      ..close();
    canvas.drawPath(
      windshield,
      Paint()
        ..shader = ui.Gradient.linear(
          const Offset(137, 34),
          const Offset(164, 50),
          const [
            CustomerUiColors.wholesaleVehicleGlassLight,
            CustomerUiColors.wholesaleVehicleGlassDark,
          ],
        ),
    );

    final sideWindow = ui.Path()
      ..moveTo(123, 40)
      ..lineTo(140, 42)
      ..lineTo(141, 55)
      ..lineTo(125, 55)
      ..close();
    canvas.drawPath(
      sideWindow,
      Paint()..color = CustomerUiColors.wholesaleVehicleSideGlass,
    );

    canvas.drawRRect(
      RRect.fromRectAndRadius(
        const Rect.fromLTWH(26, 39, 72, 24),
        const Radius.circular(6),
      ),
      Paint()..color = Colors.white.withValues(alpha: .95),
    );

    final logoPainter = TextPainter(
      text: const TextSpan(
        text: 'FOODEX',
        style: TextStyle(
          color: CustomerUiColors.wholesaleVehicleGreen,
          fontSize: 15,
          fontWeight: FontWeight.w900,
          letterSpacing: .4,
        ),
      ),
      textDirection: TextDirection.ltr,
    )..layout();

    // The vehicle body is mirrored in RTL, but the FOODEX wordmark must
    // remain readable left-to-right on the mirrored side panel.
    canvas.save();
    if (facingLeft) {
      const logoCenterX = 62.0;
      canvas.translate(logoCenterX, 0);
      canvas.scale(-1, 1);
      canvas.translate(-logoCenterX, 0);
    }
    logoPainter.paint(
      canvas,
      Offset(
        62 - logoPainter.width / 2,
        51 - logoPainter.height / 2,
      ),
    );
    canvas.restore();

    canvas.drawRRect(
      RRect.fromRectAndRadius(
        const Rect.fromLTWH(18, 67, 158, 8),
        const Radius.circular(4),
      ),
      Paint()..color = CustomerUiColors.wholesaleVehicleChassis,
    );

    canvas.drawRRect(
      RRect.fromRectAndRadius(
        const Rect.fromLTWH(160, 58, 16, 8),
        const Radius.circular(3),
      ),
      trimPaint,
    );
    canvas.drawRRect(
      RRect.fromRectAndRadius(
        const Rect.fromLTWH(169, 70, 13, 5),
        const Radius.circular(2),
      ),
      Paint()..color = CustomerUiColors.wholesaleVehicleBumper,
    );

    _wheel(canvas, const Offset(52, 76), wheelTurn);
    _wheel(canvas, const Offset(145, 76), wheelTurn);

    canvas.drawPath(
      ui.Path()
        ..moveTo(28, 27)
        ..lineTo(112, 27),
      Paint()
        ..color = Colors.white.withValues(alpha: .58)
        ..strokeWidth = 2
        ..strokeCap = StrokeCap.round
        ..style = PaintingStyle.stroke,
    );

    canvas.restore();
  }

  void _wheel(Canvas canvas, Offset center, double angle) {
    canvas.drawCircle(
        center, 16, Paint()..color = CustomerUiColors.wholesaleWheelTire);
    canvas.drawCircle(
        center, 10, Paint()..color = CustomerUiColors.wholesaleWheelRim);
    canvas.drawCircle(
        center, 4, Paint()..color = CustomerUiColors.wholesaleWheelHub);

    canvas.save();
    canvas.translate(center.dx, center.dy);
    canvas.rotate(angle);
    final spoke = Paint()
      ..color = CustomerUiColors.wholesaleWheelSpoke
      ..strokeWidth = 1.5
      ..strokeCap = StrokeCap.round;
    for (var i = 0; i < 4; i++) {
      final a = i * math.pi / 2;
      canvas.drawLine(
        Offset(math.cos(a) * 4, math.sin(a) * 4),
        Offset(math.cos(a) * 9, math.sin(a) * 9),
        spoke,
      );
    }
    canvas.restore();
  }

  @override
  bool shouldRepaint(covariant _WholesalePseudo3dVehiclePainter oldDelegate) =>
      oldDelegate.facingLeft != facingLeft ||
      oldDelegate.wheelTurn != wheelTurn;
}

class _WholesaleBannerCarousel extends StatefulWidget {
  const _WholesaleBannerCarousel({
    required this.banners,
    required this.fallbackTitle,
    required this.cta,
    required this.palette,
    this.onCta,
  });

  final List<Map<String, dynamic>> banners;
  final String fallbackTitle;
  final String cta;
  final FoodexPalette palette;
  final VoidCallback? onCta;

  @override
  State<_WholesaleBannerCarousel> createState() =>
      _WholesaleBannerCarouselState();
}

class _WholesaleBannerCarouselState extends State<_WholesaleBannerCarousel> {
  static const Duration _autoSlideEvery = Duration(seconds: 5);
  static const Duration _autoSlideDuration = Duration(milliseconds: 650);

  final PageController _pageController = PageController();
  Timer? _autoSlideTimer;
  int _currentPage = 0;

  @override
  void initState() {
    super.initState();
    _scheduleAutoSlide();
  }

  @override
  void didUpdateWidget(covariant _WholesaleBannerCarousel oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.banners.length != widget.banners.length) {
      _currentPage = 0;
      _autoSlideTimer?.cancel();
      _scheduleAutoSlide();
      if (_pageController.hasClients) {
        _pageController.jumpToPage(0);
      }
    }
  }

  void _scheduleAutoSlide() {
    if (widget.banners.length <= 1) return;
    _autoSlideTimer = Timer.periodic(_autoSlideEvery, (_) {
      if (!mounted || !_pageController.hasClients) return;
      final nextPage = (_currentPage + 1) % widget.banners.length;
      _pageController.animateToPage(
        nextPage,
        duration: _autoSlideDuration,
        curve: Curves.easeInOutCubic,
      );
    });
  }

  @override
  void dispose() {
    _autoSlideTimer?.cancel();
    _pageController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (widget.banners.isEmpty) {
      return _WholesaleHero(
        title: widget.fallbackTitle,
        cta: widget.cta,
        palette: widget.palette,
        onCta: widget.onCta,
      );
    }

    return SizedBox(
      key: const ValueKey('wholesale-home-banner-carousel'),
      height: 143,
      child: Stack(
        children: [
          PageView.builder(
            controller: _pageController,
            itemCount: widget.banners.length,
            onPageChanged: (index) {
              if (!mounted) return;
              setState(() => _currentPage = index);
            },
            itemBuilder: (context, index) {
              final banner = widget.banners[index];
              final bannerTitle = banner['title']?.toString().trim();
              return _WholesaleHero(
                key: ValueKey('wholesale-home-banner-$index'),
                title: bannerTitle != null && bannerTitle.isNotEmpty
                    ? bannerTitle
                    : widget.fallbackTitle,
                cta: widget.cta,
                imageUrl: banner['image_url']?.toString(),
                palette: widget.palette,
                onCta: widget.onCta,
              );
            },
          ),
          if (widget.banners.length > 1)
            PositionedDirectional(
              bottom: 7,
              start: 0,
              end: 0,
              child: IgnorePointer(
                child: Row(
                  key: const ValueKey('wholesale-home-banner-indicators'),
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    for (var index = 0; index < widget.banners.length; index++)
                      AnimatedContainer(
                        duration: const Duration(milliseconds: 260),
                        curve: Curves.easeOutCubic,
                        width: index == _currentPage ? 18 : 6,
                        height: 6,
                        margin: const EdgeInsets.symmetric(horizontal: 2),
                        decoration: BoxDecoration(
                          color: Colors.white.withOpacity(
                            index == _currentPage ? .95 : .45,
                          ),
                          borderRadius: BorderRadius.circular(999),
                          boxShadow: const [
                            BoxShadow(
                              color: CustomerUiColors.wholesaleVehicleShadow,
                              blurRadius: 5,
                              offset: Offset(0, 1),
                            ),
                          ],
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

class _WholesaleHero extends StatefulWidget {
  const _WholesaleHero({
    required this.title,
    required this.cta,
    required this.palette,
    this.imageUrl,
    this.onCta,
    super.key,
  });

  final String title;
  final String cta;
  final String? imageUrl;
  final FoodexPalette palette;
  final VoidCallback? onCta;

  @override
  State<_WholesaleHero> createState() => _WholesaleHeroState();
}

class _WholesaleHeroState extends State<_WholesaleHero>
    with TickerProviderStateMixin {
  late final AnimationController _entranceController;
  late final AnimationController _ambientController;
  bool _reduceMotion = false;

  @override
  void initState() {
    super.initState();
    _entranceController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 720),
    )..forward();
    _ambientController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 5600),
    )..repeat();
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    final reduceMotion = CustomerUiMotion.isReduced(context);
    if (reduceMotion == _reduceMotion) return;
    _reduceMotion = reduceMotion;
    if (_reduceMotion) {
      _entranceController.value = 1;
      _ambientController.stop();
    } else {
      if (_entranceController.value < 1) {
        _entranceController.forward();
      }
      if (!_ambientController.isAnimating) {
        _ambientController.repeat();
      }
    }
  }

  @override
  void didUpdateWidget(covariant _WholesaleHero oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.title != widget.title ||
        oldWidget.imageUrl != widget.imageUrl) {
      if (_reduceMotion) {
        _entranceController.value = 1;
      } else {
        _entranceController
          ..value = 0
          ..forward();
      }
    }
  }

  @override
  void dispose() {
    _entranceController.dispose();
    _ambientController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final hasImage =
        widget.imageUrl != null && widget.imageUrl!.trim().isNotEmpty;
    final rtl = Directionality.of(context) == TextDirection.rtl;

    return Padding(
      padding: const EdgeInsets.fromLTRB(0, 0, 0, .85),
      child: Container(
        width: double.infinity,
        height: 135,
        clipBehavior: Clip.antiAlias,
        decoration: BoxDecoration(
          gradient: LinearGradient(
            colors: [widget.palette.primary, widget.palette.primaryDark],
          ),
        ),
        child: AnimatedBuilder(
          animation: _ambientController,
          builder: (context, child) {
            final t = _reduceMotion ? .5 : _ambientController.value;
            final wave = math.sin(t * math.pi * 2);
            final imageScale = 1.035 + (.012 * wave);
            final imageShift = 3.5 * wave;
            final glowX = rtl ? (1 - t) : t;
            final ctaScale = 1 + (.018 * math.sin(t * math.pi * 4));

            return Stack(
              fit: StackFit.expand,
              children: [
                if (hasImage)
                  Transform.translate(
                    offset: Offset(imageShift, 0),
                    child: Transform.scale(
                      scale: imageScale,
                      child: Image.network(
                        widget.imageUrl!,
                        fit: BoxFit.cover,
                        errorBuilder: (_, __, ___) => const SizedBox.shrink(),
                      ),
                    ),
                  ),
                DecoratedBox(
                  decoration: BoxDecoration(
                    gradient: LinearGradient(
                      begin: AlignmentDirectional.centerStart,
                      end: AlignmentDirectional.centerEnd,
                      colors: [
                        Colors.black.withValues(alpha: hasImage ? .60 : .20),
                        Colors.black.withValues(alpha: hasImage ? .28 : .09),
                        Colors.transparent,
                      ],
                    ),
                  ),
                ),
                if (!_reduceMotion)
                  Positioned(
                    key: const ValueKey('wholesale-home-banner-illumination'),
                    left:
                        -100 + (MediaQuery.sizeOf(context).width + 200) * glowX,
                    top: -36,
                    bottom: -36,
                    width: 92,
                    child: Transform.rotate(
                      angle: -.16,
                      child: DecoratedBox(
                        decoration: BoxDecoration(
                          gradient: LinearGradient(
                            begin: Alignment.centerLeft,
                            end: Alignment.centerRight,
                            colors: [
                              Colors.white.withValues(alpha: 0),
                              Colors.white.withValues(alpha: .16),
                              Colors.white.withValues(alpha: .04),
                              Colors.white.withValues(alpha: 0),
                            ],
                          ),
                        ),
                      ),
                    ),
                  ),
                AnimatedBuilder(
                  animation: _entranceController,
                  builder: (context, _) {
                    final eased = Curves.easeOutCubic.transform(
                      _reduceMotion ? 1 : _entranceController.value,
                    );
                    return Opacity(
                      opacity: eased,
                      child: Transform.translate(
                        offset: Offset(0, 14 * (1 - eased)),
                        child: Padding(
                          padding: const EdgeInsets.all(20),
                          child: Align(
                            alignment: AlignmentDirectional.centerStart,
                            child: ConstrainedBox(
                              constraints: const BoxConstraints(maxWidth: 230),
                              child: Column(
                                mainAxisSize: MainAxisSize.min,
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(
                                    widget.title,
                                    maxLines: 2,
                                    overflow: TextOverflow.ellipsis,
                                    style: const TextStyle(
                                      color: Colors.white,
                                      fontSize: 20,
                                      height: 1.25,
                                      fontWeight: FontWeight.w800,
                                      shadows: [
                                        Shadow(
                                          blurRadius: 8,
                                          offset: Offset(0, 2),
                                          color: Color(0x66000000),
                                        ),
                                        Shadow(
                                          blurRadius: 20,
                                          color: Color(0x5500FFB0),
                                        ),
                                      ],
                                    ),
                                  ),
                                  const SizedBox(height: 12),
                                  Transform.scale(
                                    alignment: AlignmentDirectional.centerStart,
                                    scale: _reduceMotion ? 1 : ctaScale,
                                    child: _WhitePill(
                                      label: widget.cta,
                                      palette: widget.palette,
                                      onTap: widget.onCta,
                                    ),
                                  ),
                                ],
                              ),
                            ),
                          ),
                        ),
                      ),
                    );
                  },
                ),
              ],
            );
          },
        ),
      ),
    );
  }
}

class _WhitePill extends StatelessWidget {
  const _WhitePill({required this.label, required this.palette, this.onTap});

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
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
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

class _WholesaleCategoryGrid extends StatefulWidget {
  const _WholesaleCategoryGrid({
    required this.rows,
    required this.palette,
    required this.storeId,
  });

  final List<Map<String, dynamic>> rows;
  final FoodexPalette palette;
  final int storeId;

  @override
  State<_WholesaleCategoryGrid> createState() => _WholesaleCategoryGridState();
}

class _WholesaleCategoryGridState extends State<_WholesaleCategoryGrid> {
  static const double _itemStep = 65;
  static const Duration _slideEvery = Duration(seconds: 3);
  static const Duration _slideDuration = Duration(milliseconds: 500);

  final ScrollController _controller = ScrollController();
  Timer? _autoSlideTimer;

  @override
  void initState() {
    super.initState();
    _autoSlideTimer = Timer.periodic(_slideEvery, (_) => _slideNext());
  }

  @override
  void dispose() {
    _autoSlideTimer?.cancel();
    _controller.dispose();
    super.dispose();
  }

  Future<void> _slideNext() async {
    if (!mounted || !_controller.hasClients) return;
    final position = _controller.position;
    if (position.maxScrollExtent <= 0) return;

    final next = position.pixels + _itemStep;
    final target = next >= position.maxScrollExtent - 2
        ? 0.0
        : math.min(next, position.maxScrollExtent);

    await _controller.animateTo(
      target,
      duration: _slideDuration,
      curve: Curves.easeInOutCubic,
    );
  }

  @override
  Widget build(BuildContext context) {
    final byId = <int, Map<String, dynamic>>{};
    for (final row in widget.rows) {
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
      height: 71,
      child: ListView.separated(
        controller: _controller,
        padding: const EdgeInsets.symmetric(horizontal: 14),
        scrollDirection: Axis.horizontal,
        physics: const BouncingScrollPhysics(),
        itemCount: categories.length,
        separatorBuilder: (_, __) => const SizedBox(width: 8),
        itemBuilder: (_, index) {
          final category = categories[index];
          final id = intValue(category['id']);
          final name = category['name']?.toString().trim();
          final imageUrl = category['image_url']?.toString().trim();
          return InkWell(
            key: ValueKey('wholesale-category-$id'),
            borderRadius: BorderRadius.circular(14),
            onTap: widget.storeId > 0
                ? () => Navigator.of(context).pushNamed(
                      Uri(
                        path: CustomerRoutePaths.b2bProducts,
                        queryParameters: <String, String>{
                          'channel': 'wholesale',
                          'store_id': widget.storeId.toString(),
                          'category_id': id.toString(),
                        },
                      ).toString(),
                    )
                : null,
            child: SizedBox(
              width: 57,
              child: Column(
                children: [
                  Container(
                    width: 50,
                    height: 50,
                    clipBehavior: Clip.antiAlias,
                    decoration: BoxDecoration(
                      color: Colors.white,
                      shape: BoxShape.circle,
                      border: Border.all(color: widget.palette.soft),
                      boxShadow: [
                        BoxShadow(
                          color: Colors.black.withValues(alpha: .04),
                          blurRadius: 6,
                          offset: const Offset(0, 2),
                        ),
                      ],
                    ),
                    child: imageUrl != null && imageUrl.isNotEmpty
                        ? Image.network(
                            imageUrl,
                            fit: BoxFit.cover,
                            errorBuilder: (_, __, ___) => Icon(
                              Icons.category_outlined,
                              color: widget.palette.primary,
                              size: 21,
                            ),
                          )
                        : Icon(
                            Icons.category_outlined,
                            color: widget.palette.primary,
                            size: 21,
                          ),
                  ),
                  const SizedBox(height: 5),
                  Text(
                    name != null && name.isNotEmpty ? name : 'تصنيف $id',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    textAlign: TextAlign.center,
                    style: const TextStyle(
                      fontSize: 8.5,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                ],
              ),
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
    required this.storeId,
  });

  final List<Map<String, dynamic>> rows;
  final FoodexPalette palette;
  final int storeId;

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
          return InkWell(
            key: ValueKey('wholesale-brand-$id'),
            borderRadius: BorderRadius.circular(16),
            onTap: storeId > 0 && id > 0
                ? () => Navigator.of(context).pushNamed(
                      Uri(
                        path: CustomerRoutePaths.b2bProducts,
                        queryParameters: <String, String>{
                          'channel': 'wholesale',
                          'store_id': storeId.toString(),
                          'brand_id': id.toString(),
                          'browse': 'brands',
                        },
                      ).toString(),
                    )
                : null,
            child: SizedBox(
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
                        : Icon(Icons.sell_outlined, color: palette.primary),
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
            ),
          );
        },
      ),
    );
  }
}

class _WholesaleBrandTickerHeader extends StatefulWidget {
  const _WholesaleBrandTickerHeader({
    required this.title,
    required this.rows,
    required this.palette,
    required this.storeId,
    required this.onAction,
    this.trailing,
  });

  final String title;
  final List<Map<String, dynamic>> rows;
  final FoodexPalette palette;
  final int storeId;
  final VoidCallback? onAction;
  final Widget? trailing;

  @override
  State<_WholesaleBrandTickerHeader> createState() =>
      _WholesaleBrandTickerHeaderState();
}

class _WholesaleBrandTickerHeaderState
    extends State<_WholesaleBrandTickerHeader>
    with SingleTickerProviderStateMixin {
  static const _interval = Duration(seconds: 8);
  static const _tones = <Color>[
    CustomerUiColors.wholesaleBrandToneGreen,
    CustomerUiColors.wholesaleBrandToneBlue,
    CustomerUiColors.wholesaleBrandToneOrange,
    CustomerUiColors.wholesaleBrandTonePurple,
    CustomerUiColors.wholesaleBrandToneAmber,
  ];

  Timer? _timer;
  AnimationController? _marquee;
  int _index = 0;

  AnimationController get _marqueeController =>
      _marquee ??= AnimationController(vsync: this, duration: _interval);

  List<Map<String, dynamic>> get _brands {
    final byId = <int, Map<String, dynamic>>{};
    for (final row in widget.rows) {
      final id = intValue(row['brand_id']);
      final name = row['brand_name']?.toString().trim() ?? '';
      if (id <= 0 || name.isEmpty || byId.containsKey(id)) continue;
      byId[id] = <String, dynamic>{'id': id, 'name': name};
    }
    return byId.values.toList(growable: false);
  }

  @override
  void initState() {
    super.initState();
    _restartTicker();
  }

  @override
  void didUpdateWidget(covariant _WholesaleBrandTickerHeader oldWidget) {
    super.didUpdateWidget(oldWidget);
    final oldIds =
        oldWidget.rows.map((row) => intValue(row['brand_id'])).join(',');
    final newIds =
        widget.rows.map((row) => intValue(row['brand_id'])).join(',');
    if (oldIds != newIds) {
      _index = 0;
      _restartTicker();
    }
  }

  void _restartTicker() {
    _timer?.cancel();
    final marquee = _marqueeController;
    marquee.stop();
    final brands = _brands;
    if (brands.isEmpty) return;

    marquee.forward(from: 0);
    _timer = Timer.periodic(_interval, (_) {
      final currentBrands = _brands;
      if (!mounted || currentBrands.isEmpty) return;
      setState(() {
        if (currentBrands.length > 1) {
          _index = (_index + 1) % currentBrands.length;
        }
      });
      marquee.forward(from: 0);
    });
  }

  void _openBrand(Map<String, dynamic> brand) {
    final id = intValue(brand['id']);
    if (id <= 0 || widget.storeId <= 0) return;
    Navigator.of(context).pushNamed(
      Uri(
        path: CustomerRoutePaths.b2bProducts,
        queryParameters: <String, String>{
          'channel': 'wholesale',
          'store_id': widget.storeId.toString(),
          'brand_id': id.toString(),
          'browse': 'brands',
        },
      ).toString(),
    );
  }

  Widget _marqueeBrand(
    BuildContext context,
    Map<String, dynamic> brand,
    Color tone,
  ) {
    final isArabic = Localizations.localeOf(context).languageCode == 'ar';
    final name = brand['name'].toString();
    final style = TextStyle(
      color: tone,
      fontSize: 10.5,
      fontWeight: FontWeight.w900,
      decoration: TextDecoration.underline,
      decorationColor: tone,
    );
    final painter = TextPainter(
      text: TextSpan(text: name, style: style),
      textDirection: isArabic ? TextDirection.rtl : TextDirection.ltr,
      textScaler: MediaQuery.textScalerOf(context),
      maxLines: 1,
    )..layout();
    final textWidth = painter.width + 18;

    return LayoutBuilder(
      builder: (context, constraints) => ClipRect(
        child: SizedBox(
          height: 28,
          child: AnimatedBuilder(
            animation: _marqueeController,
            child: SizedBox(
              width: textWidth,
              height: 28,
              child: InkWell(
                key: ValueKey(
                  'wholesale-brand-ticker-${intValue(brand['id'])}',
                ),
                onTap: () => _openBrand(brand),
                borderRadius: BorderRadius.circular(999),
                child: Padding(
                  padding: const EdgeInsets.symmetric(
                    horizontal: 7,
                    vertical: 4,
                  ),
                  child: Text(
                    name,
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
                  ? constraints.maxWidth - (_marqueeController.value * travel)
                  : -textWidth + (_marqueeController.value * travel);
              return Transform.translate(
                offset: Offset(dx, 0),
                child: child,
              );
            },
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
    final brands = _brands;
    final brand = brands.isEmpty ? null : brands[_index % brands.length];
    final tone = _tones[_index % _tones.length];

    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 3.5, 16, 2.25),
      child: Row(
        key: const ValueKey('wholesale-brand-ticker-header'),
        children: [
          FoodexAnimatedOxbloodTitle(
            title: widget.title,
            fontSize: 14.4,
            fontWeight: FontWeight.w800,
          ),
          if (brand != null) ...[
            const SizedBox(width: 8),
            Expanded(child: _marqueeBrand(context, brand, tone)),
          ] else
            const Spacer(),
          if (widget.onAction != null)
            TextButton(
              key: ValueKey('section-action-' + widget.title),
              onPressed: widget.onAction,
              child: Text(
                'عرض الكل',
                style: TextStyle(
                  color: widget.palette.primary,
                  fontSize: 9.6,
                  fontWeight: FontWeight.w700,
                ),
              ),
            ),
          if (widget.trailing != null) ...[
            const SizedBox(width: 2),
            widget.trailing!,
          ],
        ],
      ),
    );
  }
}

class _WholesaleBestSellerTickerHeader extends StatefulWidget {
  const _WholesaleBestSellerTickerHeader({
    required this.title,
    required this.rows,
    required this.palette,
    required this.storeId,
    required this.onAction,
  });

  final String title;
  final List<Map<String, dynamic>> rows;
  final FoodexPalette palette;
  final int storeId;
  final VoidCallback? onAction;

  @override
  State<_WholesaleBestSellerTickerHeader> createState() =>
      _WholesaleBestSellerTickerHeaderState();
}

class _WholesaleBestSellerTickerHeaderState
    extends State<_WholesaleBestSellerTickerHeader>
    with SingleTickerProviderStateMixin {
  static const _interval = Duration(seconds: 10);
  static const _tones = <Color>[
    CustomerUiColors.wholesaleBrandToneGreen,
    CustomerUiColors.wholesaleBrandToneBlue,
    CustomerUiColors.wholesaleBrandToneAmber,
    CustomerUiColors.wholesaleBrandTonePurple,
    CustomerUiColors.wholesaleBrandToneOrange,
  ];

  Timer? _timer;
  AnimationController? _marquee;
  int _index = 0;

  AnimationController get _marqueeController =>
      _marquee ??= AnimationController(vsync: this, duration: _interval);

  List<Map<String, dynamic>> get _products => widget.rows
      .where(
        (row) =>
            intValue(row['id']) > 0 &&
            (row['name']?.toString().trim().isNotEmpty ?? false),
      )
      .toList(growable: false);

  @override
  void initState() {
    super.initState();
    _restartTicker();
  }

  @override
  void didUpdateWidget(covariant _WholesaleBestSellerTickerHeader oldWidget) {
    super.didUpdateWidget(oldWidget);
    final oldIds = oldWidget.rows.map((row) => intValue(row['id'])).join(',');
    final newIds = widget.rows.map((row) => intValue(row['id'])).join(',');
    if (oldIds != newIds) {
      _index = 0;
      _restartTicker();
    }
  }

  void _restartTicker() {
    _timer?.cancel();
    final marquee = _marqueeController;
    marquee.stop();
    final products = _products;
    if (products.isEmpty) return;

    marquee.forward(from: 0);
    _timer = Timer.periodic(_interval, (_) {
      final currentProducts = _products;
      if (!mounted || currentProducts.isEmpty) return;
      setState(() {
        if (currentProducts.length > 1) {
          _index = (_index + 1) % currentProducts.length;
        }
      });
      marquee.forward(from: 0);
    });
  }

  void _openProduct(Map<String, dynamic> product) {
    final id = intValue(product['id']);
    if (id <= 0 || widget.storeId <= 0) return;
    Navigator.of(context).pushNamed(
      '/b2b/products/' +
          id.toString() +
          '?store_id=' +
          widget.storeId.toString(),
    );
  }

  Widget _marqueeLink(
    BuildContext context,
    Map<String, dynamic> product,
    Color tone,
  ) {
    final isArabic = Localizations.localeOf(context).languageCode == 'ar';
    final name = product['name'].toString();
    final style = TextStyle(
      color: tone,
      fontSize: 10.5,
      fontWeight: FontWeight.w900,
      decoration: TextDecoration.underline,
      decorationColor: tone,
    );
    final painter = TextPainter(
      text: TextSpan(text: name, style: style),
      textDirection: isArabic ? TextDirection.rtl : TextDirection.ltr,
      textScaler: MediaQuery.textScalerOf(context),
      maxLines: 1,
    )..layout();
    final textWidth = painter.width + 18;

    return LayoutBuilder(
      builder: (context, constraints) => ClipRect(
        child: SizedBox(
          height: 28,
          child: Stack(
            alignment: Alignment.centerLeft,
            children: [
              AnimatedBuilder(
                animation: _marqueeController,
                child: SizedBox(
                  width: textWidth,
                  height: 28,
                  child: InkWell(
                    key: ValueKey(
                      'wholesale-best-seller-ticker-product-${intValue(product['id'])}',
                    ),
                    onTap: () => _openProduct(product),
                    borderRadius: BorderRadius.circular(999),
                    child: Padding(
                      padding: const EdgeInsets.symmetric(
                        horizontal: 7,
                        vertical: 4,
                      ),
                      child: Text(
                        name,
                        key: ValueKey(
                          'wholesale-best-seller-ticker-label-${intValue(product['id'])}',
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
    final products = _products;
    final product =
        products.isEmpty ? null : products[_index % products.length];
    final tone = _tones[_index % _tones.length];

    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 3.5, 16, 2.25),
      child: Row(
        key: const ValueKey('wholesale-best-seller-ticker-header'),
        children: [
          FoodexAnimatedOxbloodTitle(
            title: widget.title,
            fontSize: 14.4,
            fontWeight: FontWeight.w800,
          ),
          if (product != null) ...[
            const SizedBox(width: 8),
            Expanded(child: _marqueeLink(context, product, tone)),
          ] else
            const Spacer(),
          if (widget.onAction != null)
            TextButton(
              key: ValueKey('section-action-' + widget.title),
              onPressed: widget.onAction,
              child: Text(
                'عرض الكل',
                style: TextStyle(
                  color: widget.palette.primary,
                  fontSize: 9.6,
                  fontWeight: FontWeight.w700,
                ),
              ),
            ),
        ],
      ),
    );
  }
}

int _wholesaleProductColumns(int itemCount) => 3;

class _WholesaleProductGrid extends StatelessWidget {
  const _WholesaleProductGrid({
    required this.rows,
    required this.storeId,
    required this.sourceLocation,
    required this.session,
    required this.actionApi,
    required this.pendingActionStore,
    required this.palette,
    this.favoritesApi,
    this.maxItems,
    this.defaultCurrency = 'EGP',
    this.noResults = false,
    this.showCommerceActions = false,
    this.homeWholeNumberPrice = false,
    this.columnsOverride,
    this.onRemoveFavorite,
  });

  final List<Map<String, dynamic>> rows;
  final int storeId;
  final String sourceLocation;
  final CustomerSession session;
  final CustomerActionApi actionApi;
  final CustomerPendingActionStore? pendingActionStore;
  final FoodexPalette palette;
  final B2cRetailFavoritesApi? favoritesApi;
  final int? maxItems;
  final String defaultCurrency;
  final bool noResults;
  final bool showCommerceActions;
  final bool homeWholeNumberPrice;
  final int? columnsOverride;
  final Future<void> Function(int productId)? onRemoveFavorite;

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

    final displayRows =
        maxItems == null ? rows : rows.take(maxItems!).toList(growable: false);
    final columns =
        columnsOverride ?? _wholesaleProductColumns(displayRows.length);
    final mainAxisExtent = switch (columns) {
      1 => 248.0,
      2 => 182.0,
      _ => 132.0,
    };

    return GridView.builder(
      key: const ValueKey('wholesale-product-grid'),
      padding: const EdgeInsets.symmetric(horizontal: 14),
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      itemCount: displayRows.length,
      gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
        crossAxisCount: columns,
        crossAxisSpacing: 8,
        mainAxisSpacing: columns >= 3 ? 0 : 8,
        mainAxisExtent: mainAxisExtent,
      ),
      itemBuilder: (context, index) => _WholesaleProductCard(
        row: displayRows[index],
        storeId: storeId,
        sourceLocation: sourceLocation,
        session: session,
        actionApi: actionApi,
        pendingActionStore: pendingActionStore,
        palette: palette,
        favoritesApi: favoritesApi,
        defaultCurrency: defaultCurrency,
        showCommerceActions: showCommerceActions,
        homeWholeNumberPrice: homeWholeNumberPrice,
        gridColumns: columns,
        onRemoveFavorite: onRemoveFavorite,
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
    required this.homeWholeNumberPrice,
    required this.gridColumns,
    this.favoritesApi,
    this.onRemoveFavorite,
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
  final bool homeWholeNumberPrice;
  final int gridColumns;
  final B2cRetailFavoritesApi? favoritesApi;
  final Future<void> Function(int productId)? onRemoveFavorite;

  @override
  State<_WholesaleProductCard> createState() => _WholesaleProductCardState();
}

class _WholesaleProductCardState extends State<_WholesaleProductCard> {
  bool busy = false;
  bool removingFavorite = false;

  Future<void> _removeFavorite() async {
    final callback = widget.onRemoveFavorite;
    final id = intValue(widget.row['id']);
    if (callback == null || id <= 0 || removingFavorite) return;

    setState(() => removingFavorite = true);
    try {
      await callback(id);
    } finally {
      if (mounted) setState(() => removingFavorite = false);
    }
  }

  Future<void> _addToCart() async {
    final id = intValue(widget.row['id']);
    final minimum = doubleValue(
      widget.row['minimum_order_quantity'] ?? widget.row['minimum_quantity'],
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

    // Resolve every inherited dependency before the async gap. A product card
    // may be deactivated while the request is in flight; its BuildContext must
    // never be used again after await.
    final messenger = ScaffoldMessenger.maybeOf(context);
    final overlay = Overlay.maybeOf(context, rootOverlay: true);
    final textDirection = Directionality.of(context);
    final topInset = MediaQuery.paddingOf(context).top;
    final productName = widget.row['name']?.toString().trim().isNotEmpty == true
        ? widget.row['name'].toString().trim()
        : context.tr('customer.products.title');
    final productImageUrl = widget.row['image_url']?.toString();
    final actionFailedMessage = context.tr('customer.error.action_failed');

    setState(() => busy = true);
    try {
      final cartPayload = await widget.actionApi.addCartItem(
        storeId: widget.storeId,
        productId: id,
        quantity: minimum,
      );
      syncFoodexWholesaleCartProductCount(cartPayload);
      if (overlay != null && overlay.mounted) {
        showFoodexWholesaleCartAddHover(
          overlay: overlay,
          textDirection: textDirection,
          topInset: topInset,
          productName: productName,
          imageUrl: productImageUrl,
        );
      }
    } catch (error) {
      if (messenger != null && messenger.mounted) {
        messenger
          ..hideCurrentSnackBar()
          ..showSnackBar(
            SnackBar(
              key: ValueKey('wholesale-cart-add-error-$id'),
              behavior: SnackBarBehavior.floating,
              content: Text(actionFailedMessage),
            ),
          );
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
    final brand = row['brand_name']?.toString().trim() ?? '';
    final category = row['category_name']?.toString().trim() ?? '';
    final currency = row['currency']?.toString().trim().isNotEmpty == true
        ? row['currency'].toString().trim().toUpperCase()
        : widget.defaultCurrency;
    final isAvailable = _wholesaleProductIsAvailable(row);
    final promotion = row['promotion'] is Map
        ? (row['promotion'] as Map)['name']?.toString().trim()
        : row['promotion_name']?.toString().trim();
    final amount = row['account_price'] ?? row['unit_price'] ?? row['price'];
    final priceLabel = widget.homeWholeNumberPrice
        ? '${doubleValue(amount, 0).toStringAsFixed(0)} $currency'
        : money(amount, currency: currency);
    final twoColumns = widget.gridColumns == 2;
    final singleColumn = widget.gridColumns == 1;
    final imageHeight = singleColumn ? 176.0 : (twoColumns ? 126.0 : 96.0);
    final cardPadding = singleColumn
        ? const EdgeInsets.fromLTRB(14, 14, 14, 12)
        : twoColumns
            ? const EdgeInsets.fromLTRB(11, 11, 11, 9)
            : const EdgeInsets.fromLTRB(7, 7, 7, 6);
    final nameHeight = singleColumn ? 28.0 : (twoColumns ? 20.0 : 14.0);
    final nameFontSize = singleColumn ? 15.0 : (twoColumns ? 12.0 : 9.0);
    final actionDimension = singleColumn ? 36.0 : (twoColumns ? 28.0 : 22.0);
    final actionIconSize = singleColumn ? 18.0 : (twoColumns ? 15.0 : 13.0);
    final surfaceColor = Theme.of(context).cardColor;
    final darkMode = Theme.of(context).brightness == Brightness.dark;

    return Material(
      key: ValueKey('wholesale-product-card-$id'),
      color: surfaceColor,
      borderRadius: BorderRadius.circular(18),
      child: Opacity(
        key: ValueKey('wholesale-product-dimmed-$id'),
        opacity: isAvailable ? 1 : .48,
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
            padding: cardPadding,
            decoration: BoxDecoration(
              border: Border.all(color: palette.soft),
              borderRadius: BorderRadius.circular(18),
              boxShadow: [
                BoxShadow(
                  color: Colors.black.withValues(alpha: darkMode ? .22 : .035),
                  blurRadius: 8,
                  offset: const Offset(0, 3),
                ),
              ],
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                SizedBox(
                  height: imageHeight,
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
                      if (category.isNotEmpty)
                        Positioned(
                          right: 0,
                          top: 0,
                          child: _WholesaleCardBadge(
                            key: ValueKey('wholesale-product-category-$id'),
                            label: category,
                            background: CustomerUiColors
                                .wholesaleCategoryBadgeBackground,
                            foreground: CustomerUiColors
                                .wholesaleCategoryBadgeForeground,
                            compact: true,
                          ),
                        ),
                      Positioned(
                        right: 0,
                        bottom: 0,
                        child: _WholesaleCardBadge(
                          key: ValueKey('wholesale-product-price-$id'),
                          label: priceLabel,
                          background:
                              CustomerUiColors.wholesalePriceBadgeBackground,
                          foreground: CustomerUiColors.wholesaleNegativeValue,
                        ),
                      ),
                      if (promotion != null && promotion.isNotEmpty)
                        Positioned(
                          right: 0,
                          top: category.isNotEmpty ? 23 : 0,
                          child: _WholesaleCardBadge(
                            key: ValueKey('wholesale-product-promotion-$id'),
                            label: promotion,
                            background: CustomerUiColors
                                .wholesalePromotionBadgeBackground,
                            foreground: CustomerUiColors
                                .wholesalePromotionBadgeForeground,
                          ),
                        ),
                      if (brand.isNotEmpty)
                        Positioned(
                          right: 0,
                          top: category.isNotEmpty
                              ? (promotion != null && promotion.isNotEmpty
                                  ? 46
                                  : 23)
                              : (promotion != null && promotion.isNotEmpty
                                  ? 23
                                  : 0),
                          child: _WholesaleCardBadge(
                            key: ValueKey('wholesale-product-brand-$id'),
                            label: brand,
                            background: surfaceColor.withValues(alpha: .94),
                            foreground: palette.muted,
                          ),
                        ),
                      if (id > 0)
                        Positioned(
                          left: 0,
                          top: 0,
                          child: widget.onRemoveFavorite != null
                              ? Material(
                                  color: Colors.black.withValues(alpha: .20),
                                  shape: const CircleBorder(),
                                  elevation: 1,
                                  child: InkWell(
                                    key: ValueKey(
                                      'wholesale-favorite-remove-$id',
                                    ),
                                    customBorder: const CircleBorder(),
                                    onTap: removingFavorite
                                        ? null
                                        : _removeFavorite,
                                    child: SizedBox.square(
                                      dimension: 27,
                                      child: removingFavorite
                                          ? const Padding(
                                              padding: EdgeInsets.all(7),
                                              child: FoodexWaveLoading(),
                                            )
                                          : const Icon(
                                              Icons.favorite_rounded,
                                              size: 17,
                                              color:
                                                  CustomerUiColors.destructive,
                                            ),
                                    ),
                                  ),
                                )
                              : CustomerFavoriteButton(
                                  api: widget.favoritesApi,
                                  storeId: widget.storeId,
                                  productId: id,
                                  isAuthenticated:
                                      widget.session.isAuthenticated,
                                  loginRoute:
                                      '/auth/checkout?next=${Uri.encodeComponent(widget.sourceLocation)}',
                                  compactOverlay: true,
                                  inactiveColor: surfaceColor,
                                  activeColor: CustomerUiColors.destructive,
                                ),
                        ),
                      if (!isAvailable)
                        PositionedDirectional(
                          start: 0,
                          bottom: 0,
                          child: _WholesaleCardBadge(
                            key: ValueKey('wholesale-product-out-of-stock-$id'),
                            label: context.tr('customer.product.out_of_stock'),
                            background:
                                CustomerUiColors.wholesaleOutOfStockBackground,
                            foreground: Colors.white,
                          ),
                        ),
                      if (widget.showCommerceActions && isAvailable && id > 0)
                        Positioned(
                          left: 0,
                          bottom: 0,
                          child: Semantics(
                            button: true,
                            label: context.tr('customer.action.add_cart'),
                            child: Material(
                              color: palette.primary,
                              shape: const CircleBorder(),
                              elevation: 2,
                              child: InkWell(
                                key: ValueKey('wholesale-product-add-$id'),
                                customBorder: const CircleBorder(),
                                onTap: busy ? null : _addToCart,
                                child: SizedBox.square(
                                  dimension: actionDimension,
                                  child: busy
                                      ? const Padding(
                                          padding: EdgeInsets.all(6),
                                          child: FoodexWaveLoading(),
                                        )
                                      : Icon(
                                          Icons.add_rounded,
                                          size: actionIconSize,
                                          color: Colors.white,
                                        ),
                                ),
                              ),
                            ),
                          ),
                        ),
                    ],
                  ),
                ),
                const SizedBox(height: 4),
                SizedBox(
                  key: ValueKey('wholesale-product-name-$id'),
                  width: double.infinity,
                  height: nameHeight,
                  child: FittedBox(
                    fit: BoxFit.scaleDown,
                    alignment: AlignmentDirectional.centerStart,
                    child: Text(
                      row['name']?.toString() ?? '',
                      maxLines: 1,
                      softWrap: false,
                      style: TextStyle(
                        fontSize: nameFontSize,
                        height: 1.05,
                        fontWeight: FontWeight.w900,
                        color: Theme.of(context).colorScheme.onSurface,
                      ),
                    ),
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

class _WholesaleCardBadge extends StatelessWidget {
  const _WholesaleCardBadge({
    required this.label,
    required this.background,
    required this.foreground,
    this.compact = false,
    super.key,
  });

  final String label;
  final Color background;
  final Color foreground;
  final bool compact;

  @override
  Widget build(BuildContext context) => Container(
        constraints: BoxConstraints(maxWidth: compact ? 78 : 92),
        padding: EdgeInsets.symmetric(
          horizontal: compact ? 5 : 7,
          vertical: compact ? 3 : 4,
        ),
        decoration: BoxDecoration(
          color: background,
          borderRadius: BorderRadius.circular(999),
          border: Border.all(
            color: compact
                ? CustomerUiColors.wholesaleBadgeBorder
                : Colors.white.withValues(alpha: .75),
          ),
        ),
        child: Text(
          label,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          textAlign: TextAlign.center,
          style: TextStyle(
            color: foreground,
            fontSize: compact ? 7.5 : 8.5,
            height: 1,
            fontWeight: FontWeight.w900,
          ),
        ),
      );
}

class WholesaleFavoritesDesignScreen extends StatefulWidget {
  const WholesaleFavoritesDesignScreen({
    required this.location,
    required this.session,
    required this.favoritesApi,
    required this.storefrontApi,
    required this.actionApi,
    this.pendingActionStore,
    this.onLocaleChanged,
    this.onLogout,
    super.key,
  });

  final String location;
  final CustomerSession session;
  final B2cRetailFavoritesApi favoritesApi;
  final StorefrontApi? storefrontApi;
  final CustomerActionApi actionApi;
  final CustomerPendingActionStore? pendingActionStore;
  final ValueChanged<Locale>? onLocaleChanged;
  final Future<void> Function()? onLogout;

  @override
  State<WholesaleFavoritesDesignScreen> createState() =>
      _WholesaleFavoritesDesignScreenState();
}

class _WholesaleFavoritesDesignScreenState
    extends State<WholesaleFavoritesDesignScreen> with WidgetsBindingObserver {
  final search = TextEditingController();
  int storeId = 0;
  late Future<Map<String, dynamic>> future = _load();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed && mounted) _reload();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    search.dispose();
    super.dispose();
  }

  Future<Map<String, dynamic>> _load() async {
    final resolvedStoreId = await resolveWholesaleStoreId(
      location: widget.location,
      storefrontApi: widget.storefrontApi,
      rememberedStoreId: storeId,
    );
    if (resolvedStoreId <= 0) {
      return const {
        'store_required': true,
        'favorites': <Object>[],
        'storefront': <String, Object?>{},
      };
    }
    storeId = resolvedStoreId;

    final favorites = await widget.favoritesApi.favoritesForStore(storeId);
    Map<String, dynamic> storefront = <String, dynamic>{};
    final storefrontApi = widget.storefrontApi;
    if (storefrontApi != null) {
      try {
        storefront = await storefrontApi.wholesaleHome(storeId);
      } catch (_) {
        // Favorites remain usable even if optional storefront branding fails.
      }
    }

    return <String, dynamic>{
      'favorites': favorites,
      'storefront': storefront,
    };
  }

  void _reload() => setState(() => future = _load());

  List<Map<String, dynamic>> _rows(Object? raw) {
    final needle = search.text.trim().toLowerCase();
    return dataRows(raw).map((favorite) {
      final product = favorite['product'] is Map
          ? Map<String, dynamic>.from(favorite['product'] as Map)
          : Map<String, dynamic>.from(favorite);
      if (product['currency'] == null && favorite['currency'] != null) {
        product['currency'] = favorite['currency'];
      }
      return product;
    }).where((product) {
      if (needle.isEmpty) return true;
      final name = product['name']?.toString().toLowerCase() ?? '';
      final sku = product['sku']?.toString().toLowerCase() ?? '';
      final barcode = product['barcode']?.toString().toLowerCase() ?? '';
      return name.contains(needle) ||
          sku.contains(needle) ||
          barcode.contains(needle);
    }).toList(growable: false);
  }

  Future<void> _removeFavorite(int productId) async {
    try {
      await widget.favoritesApi.removeFavoriteForStore(storeId, productId);
      if (mounted) _reload();
    } catch (error) {
      if (mounted) await showOperationalError(context, error);
    }
  }

  @override
  Widget build(BuildContext context) => FutureBuilder<Map<String, dynamic>>(
        future: future,
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done) {
            return Scaffold(
              body: FoodexUnifiedHeaderSurface(
                title: context.tr('b2b.app.title'),
                location: widget.location,
                isAuthenticated: widget.session.isAuthenticated,
                onLocaleChanged: widget.onLocaleChanged,
                onLogout: widget.onLogout,
                searchHint: context.tr(
                  'customer.products.search_name_sku_barcode',
                ),
                child: const FoodexLoading(
                  key: ValueKey('wholesale-favorites-loading'),
                ),
              ),
            );
          }
          if (snapshot.hasError) {
            return Scaffold(
              body: FoodexUnifiedHeaderSurface(
                title: context.tr('b2b.app.title'),
                location: widget.location,
                isAuthenticated: widget.session.isAuthenticated,
                onLocaleChanged: widget.onLocaleChanged,
                onLogout: widget.onLogout,
                searchHint: context.tr(
                  'customer.products.search_name_sku_barcode',
                ),
                child: FoodexErrorState(
                  key: const ValueKey('wholesale-favorites-error'),
                  message: context.tr('customer.error.action_failed'),
                  onRetry: _reload,
                ),
              ),
            );
          }

          final payload = snapshot.data ?? const <String, dynamic>{};
          if (payload['store_required'] == true) {
            return Scaffold(
              backgroundColor: FoodexPalette.wholesale.background,
              body: FoodexUnifiedHeaderSurface(
                title: context.tr('b2b.app.title'),
                location: widget.location,
                isAuthenticated: widget.session.isAuthenticated,
                onLocaleChanged: widget.onLocaleChanged,
                onLogout: widget.onLogout,
                searchHint: context.tr(
                  'customer.products.search_name_sku_barcode',
                ),
                child: FoodexErrorState(
                  key: const ValueKey('wholesale-favorites-store-error'),
                  message: context.tr('b2b.catalog.load_error'),
                  onRetry: _reload,
                ),
              ),
            );
          }

          final storefront = payload['storefront'] is Map
              ? Map<String, dynamic>.from(payload['storefront'] as Map)
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
          final theme = storefront['theme'] is Map
              ? Map<String, dynamic>.from(storefront['theme'] as Map)
              : <String, dynamic>{};
          final palette = _wholesalePalette(theme);
          final title =
              custom['brand_title_ar']?.toString().trim().isNotEmpty == true
                  ? custom['brand_title_ar'].toString()
                  : (store['name']?.toString().trim().isNotEmpty == true
                      ? store['name'].toString()
                      : 'متجر الجملة');
          final rows = _rows(payload['favorites']);
          final scopedLocation = Uri(
            path: CustomerRoutePaths.b2bFavorites,
            queryParameters: <String, String>{
              'channel': 'wholesale',
              'store_id': storeId.toString(),
            },
          ).toString();

          return Scaffold(
            key: const ValueKey('wholesale-favorites-screen'),
            backgroundColor: Theme.of(context).brightness == Brightness.dark
                ? Theme.of(context).scaffoldBackgroundColor
                : palette.background,
            body: SafeArea(
              child: Column(
                children: [
                  FoodexWholesaleHeader(
                    title: title,
                    logoUrl: branding['logo_url']?.toString(),
                    address: branding['address']?.toString(),
                    palette: palette,
                    location: widget.location,
                    isAuthenticated: widget.session.isAuthenticated,
                    onLocaleChanged: widget.onLocaleChanged,
                    onLogout: widget.onLogout,
                    searchController: search,
                    searchKey: const ValueKey('wholesale-favorites-search'),
                    searchHint: context.tr('b2b.catalog.search_hint'),
                    onSearchSubmitted: (_) => setState(() {}),
                    onSearchChanged: (_) => setState(() {}),
                    onClearSearch: () {
                      search.clear();
                      setState(() {});
                    },
                    onCart: () => Navigator.of(context).pushNamed(
                      '/b2b/cart?store_id=' + storeId.toString(),
                    ),
                  ),
                  Expanded(
                    child: RefreshIndicator(
                      onRefresh: () async {
                        _reload();
                        await future;
                      },
                      child: ListView(
                        key: const ValueKey(
                          'wholesale-favorites-scroll-body',
                        ),
                        physics: const AlwaysScrollableScrollPhysics(),
                        padding: const EdgeInsets.fromLTRB(0, 14, 0, 28),
                        children: [
                          Padding(
                            padding: const EdgeInsets.symmetric(horizontal: 14),
                            child: Text(
                              context.tr('customer.profile.favorites'),
                              style: const TextStyle(
                                color: CustomerUiColors.ink,
                                fontSize: 20,
                                fontWeight: FontWeight.w900,
                              ),
                            ),
                          ),
                          const SizedBox(height: 10),
                          if (rows.isEmpty)
                            Padding(
                              padding:
                                  const EdgeInsets.symmetric(horizontal: 14),
                              child: FoodexEmptyState(
                                key: const ValueKey(
                                  'wholesale-favorites-empty',
                                ),
                                title: context.tr('customer.favorites.empty'),
                                subtitle:
                                    context.tr('customer.favorites.subtitle'),
                              ),
                            )
                          else
                            _WholesaleProductGrid(
                              rows: rows,
                              storeId: storeId,
                              sourceLocation: scopedLocation,
                              session: widget.session,
                              actionApi: widget.actionApi,
                              pendingActionStore: widget.pendingActionStore,
                              palette: palette,
                              favoritesApi: widget.favoritesApi,
                              showCommerceActions: true,
                              onRemoveFavorite: _removeFavorite,
                            ),
                        ],
                      ),
                    ),
                  ),
                ],
              ),
            ),
          );
        },
      );
}

Future<void> _beginWholesaleAddHandoff({
  required BuildContext context,
  required CustomerPendingActionStore? pendingActionStore,
  required int storeId,
  required int productId,
  required double quantity,
  String? sourceLocation,
}) async {
  final parsedContext = CustomerCommerceContext.tryParseLocation(
    sourceLocation,
  );
  final commerceContext = parsedContext != null &&
          parsedContext.isWholesale &&
          parsedContext.storeId == storeId
      ? parsedContext
      : CustomerCommerceContext(
          channel: CustomerCommerceChannel.wholesale,
          storeId: storeId,
          source: CustomerCommerceSource.wholesaleEntry,
        );
  final nextLocation = CustomerRouteLocations.wholesaleProduct(
    commerceContext,
    productId,
  );

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
    this.favoritesApi,
    this.pendingActionStore,
    this.onLocaleChanged,
    this.onLogout,
    super.key,
  });

  final String location;
  final CustomerSession session;
  final B2bApi? api;
  final StorefrontApi? storefrontApi;
  final CustomerActionApi actionApi;
  final B2cRetailFavoritesApi? favoritesApi;
  final CustomerPendingActionStore? pendingActionStore;
  final ValueChanged<Locale>? onLocaleChanged;
  final Future<void> Function()? onLogout;

  @override
  State<WholesaleCatalogDesignScreen> createState() =>
      _WholesaleCatalogDesignScreenState();
}

class _WholesaleCatalogDesignScreenState
    extends State<WholesaleCatalogDesignScreen> with WidgetsBindingObserver {
  final search = TextEditingController();
  int storeId = 0;
  late Future<Map<String, dynamic>> future;
  int? selectedCategoryId;
  int? selectedBrandId;
  String? browseMode;
  String? sectionFilter;
  Set<int> productFilterIds = <int>{};
  String sortMode = 'default';
  late WholesaleProductCardSize cardSize;
  int visibleLimit = 24;
  bool stale = false;
  Map<String, dynamic>? _lastPayload;

  @override
  void initState() {
    super.initState();
    cardSize =
        CustomerDisplayPreferences.instance.wholesaleProductCardSize.value;
    CustomerDisplayPreferences.instance.wholesaleProductCardSize
        .addListener(_onProductCardSizeChanged);
    final query = Uri.parse(widget.location).queryParameters;
    final initialQuery = query['q']?.trim() ?? '';
    if (initialQuery.isNotEmpty) search.text = initialQuery;
    final categoryId = int.tryParse(query['category_id'] ?? '');
    final brandId = int.tryParse(query['brand_id'] ?? '');
    selectedCategoryId =
        categoryId != null && categoryId > 0 ? categoryId : null;
    selectedBrandId = brandId != null && brandId > 0 ? brandId : null;
    browseMode = query['browse']?.trim();
    sectionFilter = query['section']?.trim();
    productFilterIds = (query['product_ids'] ?? '')
        .split(',')
        .map((value) => int.tryParse(value.trim()))
        .whereType<int>()
        .where((value) => value > 0)
        .toSet();
    future = _load(search.text);
    WidgetsBinding.instance.addObserver(this);
  }

  void _onProductCardSizeChanged() {
    if (!mounted) return;
    setState(() {
      cardSize =
          CustomerDisplayPreferences.instance.wholesaleProductCardSize.value;
    });
  }

  String _cardSizeTranslationKey() => switch (cardSize) {
        WholesaleProductCardSize.compact => 'b2b.catalog.card_size_compact',
        WholesaleProductCardSize.comfortable =>
          'b2b.catalog.card_size_comfortable',
        WholesaleProductCardSize.expanded => 'b2b.catalog.card_size_expanded',
      };

  IconData _cardSizeIcon() => switch (cardSize) {
        WholesaleProductCardSize.compact => Icons.view_module_rounded,
        WholesaleProductCardSize.comfortable => Icons.grid_view_rounded,
        WholesaleProductCardSize.expanded => Icons.view_agenda_rounded,
      };

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed && mounted) {
      _reload(resetLimit: false);
    }
  }

  Future<Map<String, dynamic>> _load([String query = '']) async {
    try {
      final resolvedStoreId = await resolveWholesaleStoreId(
        location: widget.location,
        storefrontApi: widget.storefrontApi,
        rememberedStoreId: storeId,
      );
      if (resolvedStoreId > 0) storeId = resolvedStoreId;

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
        var endpoint = '/api/v1/b2b/products?store_id=' + storeId.toString();
        if (query.trim().isNotEmpty) {
          endpoint += '&q=' + Uri.encodeQueryComponent(query.trim());
        }
        products = await widget.api!.get(endpoint);
      } else {
        var rows = mapRows(storefront['products']);
        final needle = query.trim().toLowerCase();
        if (needle.isNotEmpty) {
          rows = rows.where((row) {
            final name = row['name']?.toString().toLowerCase() ?? '';
            final sku = row['sku']?.toString().toLowerCase() ?? '';
            final barcode = row['barcode']?.toString().toLowerCase() ?? '';
            return name.contains(needle) ||
                sku.contains(needle) ||
                barcode.contains(needle);
          }).toList(growable: false);
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
    CustomerDisplayPreferences.instance.wholesaleProductCardSize
        .removeListener(_onProductCardSizeChanged);
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
                      selected: selectedCategoryId == intValue(category['id']),
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
            return Scaffold(
              body: FoodexUnifiedHeaderSurface(
                title: context.tr('b2b.app.title'),
                location: widget.location,
                isAuthenticated: widget.session.isAuthenticated,
                onLocaleChanged: widget.onLocaleChanged,
                onLogout: widget.onLogout,
                searchHint: context.tr(
                  'customer.products.search_name_sku_barcode',
                ),
                child: const FoodexLoading(
                  key: ValueKey('b2b-catalog-loading'),
                ),
              ),
            );
          }
          if (snapshot.hasError) {
            return Scaffold(
              body: FoodexUnifiedHeaderSurface(
                title: context.tr('b2b.app.title'),
                location: widget.location,
                isAuthenticated: widget.session.isAuthenticated,
                onLocaleChanged: widget.onLocaleChanged,
                onLogout: widget.onLogout,
                searchHint: context.tr(
                  'customer.products.search_name_sku_barcode',
                ),
                child: FoodexErrorState(
                  key: const ValueKey('b2b-catalog-error'),
                  message: context.tr('b2b.catalog.load_error'),
                  onRetry: () => setState(() => future = _load(search.text)),
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
                      : 'فوديكس');

          if (payload['store_required'] == true) {
            return Scaffold(
              backgroundColor: FoodexPalette.wholesale.background,
              body: FoodexUnifiedHeaderSurface(
                title: context.tr('b2b.app.title'),
                location: widget.location,
                isAuthenticated: widget.session.isAuthenticated,
                onLocaleChanged: widget.onLocaleChanged,
                onLogout: widget.onLogout,
                searchHint: context.tr(
                  'customer.products.search_name_sku_barcode',
                ),
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
            if (id <= 0) continue;
            final localizedName =
                row['category_name_$localeCode']?.toString().trim();
            final fallbackName = row['category_name']?.toString().trim();
            final imageUrl = row['category_image_url']?.toString().trim();
            final existing = categoryMap[id];
            final currentName = existing?['name']?.toString().trim();
            final currentImage = existing?['image_url']?.toString().trim();
            final resolvedName = localizedName?.isNotEmpty == true
                ? localizedName
                : (fallbackName?.isNotEmpty == true
                    ? fallbackName
                    : (currentName?.isNotEmpty == true
                        ? currentName
                        : context.tr('b2b.catalog.category') + ' $id'));
            categoryMap[id] = {
              'id': id,
              'name': resolvedName,
              'image_url': imageUrl?.isNotEmpty == true
                  ? imageUrl
                  : (currentImage?.isNotEmpty == true ? currentImage : null),
            };
          }
          final categories = categoryMap.values.toList(growable: false);
          final brandMap = <int, Map<String, dynamic>>{};
          for (final row in categorySources) {
            final id = intValue(row['brand_id']);
            if (id <= 0) continue;
            final name = row['brand_name']?.toString().trim();
            final imageUrl = row['brand_image_url']?.toString().trim();
            final existing = brandMap[id];
            final currentName = existing?['name']?.toString().trim();
            final currentImage = existing?['image_url']?.toString().trim();
            brandMap[id] = {
              'id': id,
              'name': name?.isNotEmpty == true
                  ? name
                  : (currentName?.isNotEmpty == true
                      ? currentName
                      : context.tr('b2b.catalog.brand') + ' $id'),
              'image_url': imageUrl?.isNotEmpty == true
                  ? imageUrl
                  : (currentImage?.isNotEmpty == true ? currentImage : null),
            };
          }
          final brands = brandMap.values.toList(growable: false);

          if (browseMode == 'categories' && selectedCategoryId == null) {
            return Scaffold(
              key: const ValueKey('wholesale-category-browser-screen'),
              backgroundColor: Theme.of(context).brightness == Brightness.dark
                  ? Theme.of(context).scaffoldBackgroundColor
                  : palette.background,
              body: SafeArea(
                child: Column(
                  children: [
                    FoodexWholesaleHeader(
                      title: title,
                      logoUrl: branding['logo_url']?.toString(),
                      address: branding['address']?.toString(),
                      palette: palette,
                      searchController: search,
                      searchKey:
                          const ValueKey('wholesale-category-browser-search'),
                      searchHint: context.tr('b2b.catalog.search_hint'),
                      onSearchSubmitted: (_) => _reload(),
                      onSearchChanged: (_) => setState(() {}),
                      onClearSearch: () {
                        search.clear();
                        _reload();
                      },
                      onCart: () => Navigator.of(context)
                          .pushNamed('/b2b/cart?store=' + storeId.toString()),
                    ),
                    Expanded(
                      child: ListView(
                        padding: const EdgeInsets.fromLTRB(14, 14, 14, 28),
                        children: [
                          Text(
                            context.tr('b2b.catalog.categories_title'),
                            style: const TextStyle(
                              color: CustomerUiColors.ink,
                              fontSize: 20,
                              fontWeight: FontWeight.w900,
                            ),
                          ),
                          const SizedBox(height: 4),
                          Text(
                            context.tr('b2b.catalog.categories_subtitle'),
                            style: const TextStyle(
                              color: CustomerUiColors.muted,
                              fontSize: 12,
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                          const SizedBox(height: 14),
                          _WholesaleCategoryBrowserGrid(
                            categories: categories,
                            palette: palette,
                            storeId: storeId,
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            );
          }

          if (browseMode == 'brands' && selectedBrandId == null) {
            final needle = search.text.trim().toLowerCase();
            final visibleBrands = needle.isEmpty
                ? brands
                : brands
                    .where(
                      (brand) => (brand['name']?.toString().toLowerCase() ?? '')
                          .contains(needle),
                    )
                    .toList(growable: false);

            return Scaffold(
              key: const ValueKey('wholesale-brand-browser-screen'),
              backgroundColor: Theme.of(context).brightness == Brightness.dark
                  ? Theme.of(context).scaffoldBackgroundColor
                  : palette.background,
              body: SafeArea(
                child: Column(
                  children: [
                    FoodexWholesaleHeader(
                      title: title,
                      logoUrl: branding['logo_url']?.toString(),
                      address: branding['address']?.toString(),
                      palette: palette,
                      searchController: search,
                      searchKey:
                          const ValueKey('wholesale-brand-browser-search'),
                      searchHint: context.tr('b2b.catalog.brands_search_hint'),
                      onSearchSubmitted: (_) => setState(() {}),
                      onSearchChanged: (_) => setState(() {}),
                      onClearSearch: () {
                        search.clear();
                        setState(() {});
                      },
                      onCart: () => Navigator.of(context)
                          .pushNamed('/b2b/cart?store=' + storeId.toString()),
                    ),
                    Expanded(
                      child: ListView(
                        padding: const EdgeInsets.fromLTRB(14, 14, 14, 28),
                        children: [
                          Text(
                            context.tr('b2b.catalog.brands_title'),
                            style: const TextStyle(
                              color: CustomerUiColors.ink,
                              fontSize: 20,
                              fontWeight: FontWeight.w900,
                            ),
                          ),
                          const SizedBox(height: 4),
                          Text(
                            context.tr('b2b.catalog.brands_subtitle'),
                            style: const TextStyle(
                              color: CustomerUiColors.muted,
                              fontSize: 12,
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                          const SizedBox(height: 14),
                          _WholesaleBrandBrowserGrid(
                            brands: visibleBrands,
                            palette: palette,
                            storeId: storeId,
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            );
          }

          final visibleRows = allRows.where((row) {
            if (selectedCategoryId != null &&
                intValue(row['category_id']) != selectedCategoryId) {
              return false;
            }
            if (selectedBrandId != null &&
                intValue(row['brand_id']) != selectedBrandId) {
              return false;
            }
            if (productFilterIds.isNotEmpty &&
                !productFilterIds.contains(intValue(row['id']))) {
              return false;
            }
            if (productFilterIds.isEmpty &&
                sectionFilter != null &&
                sectionFilter!.isNotEmpty &&
                !_wholesaleRowMatchesSection(row, sectionFilter!)) {
              return false;
            }
            return true;
          }).toList(growable: true);

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
              (a, b) => (a['name']?.toString() ?? '').compareTo(
                b['name']?.toString() ?? '',
              ),
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
            backgroundColor: Theme.of(context).brightness == Brightness.dark
                ? Theme.of(context).scaffoldBackgroundColor
                : palette.background,
            body: SafeArea(
              child: Column(
                children: [
                  FoodexWholesaleHeader(
                    title: title,
                    logoUrl: branding['logo_url']?.toString(),
                    address: branding['address']?.toString(),
                    palette: palette,
                    location: widget.location,
                    isAuthenticated: widget.session.isAuthenticated,
                    onLocaleChanged: widget.onLocaleChanged,
                    onLogout: widget.onLogout,
                    searchController: search,
                    searchKey: const ValueKey('wholesale-catalog-search'),
                    searchHint: context.tr('b2b.catalog.search_hint'),
                    onSearchSubmitted: (_) => _reload(),
                    onSearchChanged: (_) => setState(() {}),
                    onClearSearch: () {
                      search.clear();
                      _reload();
                    },
                    onCart: () => Navigator.of(context)
                        .pushNamed('/b2b/cart?store=' + storeId.toString()),
                  ),
                  Expanded(
                    child: ListView(
                      key: const ValueKey('wholesale-catalog-scroll-body'),
                      padding: EdgeInsets.zero,
                      children: [
                        if (categories.isNotEmpty)
                          SizedBox(
                            key: const ValueKey('wholesale-catalog-categories'),
                            height: 76,
                            child: ListView(
                              padding:
                                  const EdgeInsets.symmetric(horizontal: 12),
                              scrollDirection: Axis.horizontal,
                              children: [
                                _WholesaleCatalogCategoryIcon(
                                  key: const ValueKey(
                                    'wholesale-category-all',
                                  ),
                                  label: context.tr('b2b.catalog.all'),
                                  selected: selectedCategoryId == null,
                                  palette: palette,
                                  icon: Icons.grid_view_rounded,
                                  onTap: () => setState(() {
                                    selectedCategoryId = null;
                                    visibleLimit = 24;
                                  }),
                                ),
                                for (final category in categories)
                                  _WholesaleCatalogCategoryIcon(
                                    key: ValueKey(
                                      'wholesale-category-filter-${category['id']}',
                                    ),
                                    label: category['name']?.toString() ?? '',
                                    imageUrl: category['image_url']?.toString(),
                                    selected: selectedCategoryId ==
                                        intValue(category['id']),
                                    palette: palette,
                                    onTap: () => setState(() {
                                      selectedCategoryId =
                                          intValue(category['id']);
                                      visibleLimit = 24;
                                    }),
                                  ),
                              ],
                            ),
                          ),
                        if (brands.isNotEmpty &&
                            (browseMode == 'brands' || selectedBrandId != null))
                          SizedBox(
                            key: const ValueKey('wholesale-catalog-brands'),
                            height: 48,
                            child: ListView(
                              padding:
                                  const EdgeInsets.symmetric(horizontal: 14),
                              scrollDirection: Axis.horizontal,
                              children: [
                                Padding(
                                  padding:
                                      const EdgeInsetsDirectional.only(end: 8),
                                  child: ChoiceChip(
                                    key: const ValueKey('wholesale-brand-all'),
                                    label: Text(context.tr('b2b.catalog.all')),
                                    selected: selectedBrandId == null,
                                    onSelected: (_) => setState(() {
                                      selectedBrandId = null;
                                      visibleLimit = 24;
                                    }),
                                  ),
                                ),
                                for (final brand in brands)
                                  Padding(
                                    padding: const EdgeInsetsDirectional.only(
                                        end: 8),
                                    child: ChoiceChip(
                                      key: ValueKey(
                                        'wholesale-brand-filter-${brand['id']}',
                                      ),
                                      label:
                                          Text(brand['name']?.toString() ?? ''),
                                      selected: selectedBrandId ==
                                          intValue(brand['id']),
                                      onSelected: (_) => setState(() {
                                        selectedBrandId = intValue(brand['id']);
                                        visibleLimit = 24;
                                      }),
                                    ),
                                  ),
                              ],
                            ),
                          ),
                        Padding(
                          padding: const EdgeInsets.fromLTRB(14, 7, 14, 9),
                          child: Row(
                            crossAxisAlignment: CrossAxisAlignment.center,
                            children: [
                              Expanded(
                                child: Container(
                                  key: const ValueKey(
                                    'wholesale-catalog-count',
                                  ),
                                  height: 34,
                                  alignment: Alignment.center,
                                  padding: const EdgeInsets.symmetric(
                                    horizontal: 6,
                                  ),
                                  decoration: BoxDecoration(
                                    color: Colors.white,
                                    borderRadius: BorderRadius.circular(10),
                                    border: Border.all(color: palette.soft),
                                  ),
                                  child: Text(
                                    '${visibleRows.length} ' +
                                        context
                                            .tr('b2b.catalog.products_count'),
                                    maxLines: 1,
                                    overflow: TextOverflow.ellipsis,
                                    style: TextStyle(
                                      color: palette.muted,
                                      fontSize: 9.5,
                                      fontWeight: FontWeight.w800,
                                    ),
                                  ),
                                ),
                              ),
                              const SizedBox(width: 6),
                              Expanded(
                                child: SizedBox(
                                  height: 34,
                                  child: OutlinedButton.icon(
                                    key: const ValueKey(
                                      'wholesale-catalog-filter',
                                    ),
                                    style: OutlinedButton.styleFrom(
                                      padding: const EdgeInsets.symmetric(
                                        horizontal: 6,
                                      ),
                                      minimumSize: const Size(0, 34),
                                      side: BorderSide(color: palette.soft),
                                      shape: RoundedRectangleBorder(
                                        borderRadius: BorderRadius.circular(10),
                                      ),
                                    ),
                                    onPressed: () => _openCategoryFilter(
                                      context,
                                      categories,
                                    ),
                                    icon: const Icon(
                                      Icons.tune_rounded,
                                      size: 14,
                                    ),
                                    label: Text(
                                      context.tr('b2b.catalog.filter'),
                                      maxLines: 1,
                                      overflow: TextOverflow.ellipsis,
                                      style: const TextStyle(
                                        fontSize: 9.5,
                                        fontWeight: FontWeight.w800,
                                      ),
                                    ),
                                  ),
                                ),
                              ),
                              const SizedBox(width: 6),
                              Expanded(
                                child: SizedBox(
                                  height: 34,
                                  child: DropdownButtonFormField<String>(
                                    key: const ValueKey(
                                      'wholesale-catalog-sort',
                                    ),
                                    value: sortMode,
                                    isDense: true,
                                    isExpanded: true,
                                    icon: const Icon(
                                      Icons.unfold_more_rounded,
                                      size: 14,
                                    ),
                                    style: TextStyle(
                                      color: palette.text,
                                      fontSize: 9.5,
                                      fontWeight: FontWeight.w800,
                                    ),
                                    decoration: InputDecoration(
                                      isDense: true,
                                      filled: true,
                                      fillColor: Colors.white,
                                      contentPadding:
                                          const EdgeInsets.symmetric(
                                        horizontal: 7,
                                        vertical: 8,
                                      ),
                                      enabledBorder: OutlineInputBorder(
                                        borderRadius: BorderRadius.circular(10),
                                        borderSide:
                                            BorderSide(color: palette.soft),
                                      ),
                                      focusedBorder: OutlineInputBorder(
                                        borderRadius: BorderRadius.circular(10),
                                        borderSide: BorderSide(
                                          color: palette.primary,
                                        ),
                                      ),
                                    ),
                                    items: [
                                      DropdownMenuItem(
                                        value: 'default',
                                        child: Text(
                                          context.tr(
                                            'b2b.catalog.sort_default',
                                          ),
                                          maxLines: 1,
                                          overflow: TextOverflow.ellipsis,
                                        ),
                                      ),
                                      DropdownMenuItem(
                                        value: 'price_low',
                                        child: Text(
                                          context.tr(
                                            'b2b.catalog.sort_price_low',
                                          ),
                                          maxLines: 1,
                                          overflow: TextOverflow.ellipsis,
                                        ),
                                      ),
                                      DropdownMenuItem(
                                        value: 'price_high',
                                        child: Text(
                                          context.tr(
                                            'b2b.catalog.sort_price_high',
                                          ),
                                          maxLines: 1,
                                          overflow: TextOverflow.ellipsis,
                                        ),
                                      ),
                                      DropdownMenuItem(
                                        value: 'name',
                                        child: Text(
                                          context.tr(
                                            'b2b.catalog.sort_name',
                                          ),
                                          maxLines: 1,
                                          overflow: TextOverflow.ellipsis,
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
                              ),
                              const SizedBox(width: 3),
                              SizedBox(
                                width: 34,
                                height: 34,
                                child: IconButton(
                                  key: const ValueKey(
                                    'wholesale-catalog-card-size',
                                  ),
                                  tooltip:
                                      '${context.tr('b2b.catalog.card_size')}: '
                                      '${context.tr(_cardSizeTranslationKey())}',
                                  padding: EdgeInsets.zero,
                                  visualDensity: VisualDensity.compact,
                                  onPressed: () async {
                                    await CustomerDisplayPreferences.instance
                                        .cycleWholesaleProductCardSize();
                                  },
                                  icon: Icon(_cardSizeIcon(), size: 17),
                                ),
                              ),
                              const SizedBox(width: 3),
                              SizedBox(
                                width: 34,
                                height: 34,
                                child: IconButton(
                                  key: const ValueKey(
                                    'wholesale-catalog-refresh',
                                  ),
                                  tooltip: context.tr('b2b.catalog.refresh'),
                                  padding: EdgeInsets.zero,
                                  visualDensity: VisualDensity.compact,
                                  onPressed: () => _reload(resetLimit: false),
                                  icon: const Icon(
                                    Icons.refresh_rounded,
                                    size: 17,
                                  ),
                                ),
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
                          favoritesApi: widget.favoritesApi,
                          defaultCurrency: defaultCurrency,
                          columnsOverride: cardSize.columns,
                          noResults: allRows.isNotEmpty &&
                              visibleRows.isEmpty &&
                              (search.text.trim().isNotEmpty ||
                                  selectedCategoryId != null ||
                                  selectedBrandId != null ||
                                  productFilterIds.isNotEmpty ||
                                  (sectionFilter?.isNotEmpty ?? false)),
                          showCommerceActions: true,
                        ),
                        if (hasMore)
                          Padding(
                            padding: const EdgeInsets.fromLTRB(14, 12, 14, 0),
                            child: OutlinedButton.icon(
                              key:
                                  const ValueKey('wholesale-catalog-load-more'),
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
                ],
              ),
            ),
          );
        },
      );
}

class _WholesaleCategoryBrowserGrid extends StatelessWidget {
  const _WholesaleCategoryBrowserGrid({
    required this.categories,
    required this.palette,
    required this.storeId,
  });

  final List<Map<String, dynamic>> categories;
  final FoodexPalette palette;
  final int storeId;

  @override
  Widget build(BuildContext context) {
    if (categories.isEmpty) {
      return FoodexEmptyState(
        key: const ValueKey('wholesale-category-browser-empty'),
        title: context.tr('b2b.catalog.categories_title'),
        subtitle: context.tr('b2b.catalog.categories_subtitle'),
      );
    }

    return GridView.builder(
      key: const ValueKey('wholesale-category-browser-grid'),
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      itemCount: categories.length,
      gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
        crossAxisCount: 2,
        crossAxisSpacing: 10,
        mainAxisSpacing: 10,
        mainAxisExtent: 154,
      ),
      itemBuilder: (context, index) {
        final category = categories[index];
        final id = intValue(category['id']);
        final imageUrl = category['image_url']?.toString().trim() ?? '';
        return Material(
          color: Colors.white,
          borderRadius: BorderRadius.circular(18),
          child: InkWell(
            key: ValueKey('wholesale-category-browser-$id'),
            borderRadius: BorderRadius.circular(18),
            onTap: id <= 0 || storeId <= 0
                ? null
                : () => Navigator.of(context).pushReplacementNamed(
                      Uri(
                        path: CustomerRoutePaths.b2bProducts,
                        queryParameters: <String, String>{
                          'channel': 'wholesale',
                          'store_id': storeId.toString(),
                          'category_id': id.toString(),
                        },
                      ).toString(),
                    ),
            child: Container(
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(
                border: Border.all(color: palette.soft),
                borderRadius: BorderRadius.circular(18),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Expanded(
                    child: ClipRRect(
                      borderRadius: BorderRadius.circular(13),
                      child: imageUrl.isEmpty
                          ? ColoredBox(
                              color: palette.soft,
                              child: Icon(
                                Icons.category_outlined,
                                color: palette.primary,
                                size: 34,
                              ),
                            )
                          : Image.network(
                              imageUrl,
                              fit: BoxFit.cover,
                              errorBuilder: (_, __, ___) => ColoredBox(
                                color: palette.soft,
                                child: Icon(
                                  Icons.category_outlined,
                                  color: palette.primary,
                                  size: 34,
                                ),
                              ),
                            ),
                    ),
                  ),
                  const SizedBox(height: 8),
                  Text(
                    category['name']?.toString() ?? '',
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    textAlign: TextAlign.center,
                    style: const TextStyle(
                      color: CustomerUiColors.ink,
                      fontSize: 12,
                      fontWeight: FontWeight.w900,
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

class _WholesaleBrandBrowserGrid extends StatelessWidget {
  const _WholesaleBrandBrowserGrid({
    required this.brands,
    required this.palette,
    required this.storeId,
  });

  final List<Map<String, dynamic>> brands;
  final FoodexPalette palette;
  final int storeId;

  @override
  Widget build(BuildContext context) {
    if (brands.isEmpty) {
      return FoodexEmptyState(
        key: const ValueKey('wholesale-brand-browser-empty'),
        title: context.tr('b2b.catalog.brands_title'),
        subtitle: context.tr('b2b.catalog.brands_subtitle'),
      );
    }

    return GridView.builder(
      key: const ValueKey('wholesale-brand-browser-grid'),
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      itemCount: brands.length,
      gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
        crossAxisCount: 2,
        crossAxisSpacing: 10,
        mainAxisSpacing: 10,
        mainAxisExtent: 154,
      ),
      itemBuilder: (context, index) {
        final brand = brands[index];
        final id = intValue(brand['id']);
        final imageUrl = brand['image_url']?.toString().trim() ?? '';
        return Material(
          color: Colors.white,
          borderRadius: BorderRadius.circular(18),
          child: InkWell(
            key: ValueKey('wholesale-brand-browser-$id'),
            borderRadius: BorderRadius.circular(18),
            onTap: id <= 0 || storeId <= 0
                ? null
                : () => Navigator.of(context).pushReplacementNamed(
                      Uri(
                        path: CustomerRoutePaths.b2bProducts,
                        queryParameters: <String, String>{
                          'channel': 'wholesale',
                          'store_id': storeId.toString(),
                          'brand_id': id.toString(),
                          'browse': 'brands',
                        },
                      ).toString(),
                    ),
            child: Container(
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(
                border: Border.all(color: palette.soft),
                borderRadius: BorderRadius.circular(18),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Expanded(
                    child: ClipRRect(
                      borderRadius: BorderRadius.circular(13),
                      child: imageUrl.isEmpty
                          ? ColoredBox(
                              color: palette.soft,
                              child: Icon(
                                Icons.sell_outlined,
                                color: palette.primary,
                                size: 34,
                              ),
                            )
                          : Image.network(
                              imageUrl,
                              fit: BoxFit.contain,
                              errorBuilder: (_, __, ___) => ColoredBox(
                                color: palette.soft,
                                child: Icon(
                                  Icons.sell_outlined,
                                  color: palette.primary,
                                  size: 34,
                                ),
                              ),
                            ),
                    ),
                  ),
                  const SizedBox(height: 8),
                  Text(
                    brand['name']?.toString() ?? '',
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    textAlign: TextAlign.center,
                    style: const TextStyle(
                      color: CustomerUiColors.ink,
                      fontSize: 12,
                      fontWeight: FontWeight.w900,
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
  const _WholesaleBottomNav({required this.storeId, required this.palette});

  final int storeId;
  final FoodexPalette palette;

  @override
  Widget build(BuildContext context) => NavigationBar(
        selectedIndex: 0,
        indicatorColor: palette.soft,
        onDestinationSelected: (index) {
          if (index == 1) {
            Navigator.of(context)
                .pushNamed('/b2b/products?store_id=' + storeId.toString());
          } else if (index == 2) {
            Navigator.of(context)
                .pushNamed('/b2b/cart?store=' + storeId.toString());
          } else if (index == 3) {
            Navigator.of(context).pushNamed(
              '/b2b/orders?channel=wholesale&store_id=' + storeId.toString(),
            );
          }
        },
        destinations: [
          NavigationDestination(
            icon: Icon(Icons.home_outlined),
            label: context.tr('customer.nav.home'),
          ),
          NavigationDestination(
            icon: Icon(Icons.grid_view_rounded),
            label: context.tr('customer.nav.catalog'),
          ),
          NavigationDestination(
            icon: Icon(Icons.shopping_cart_outlined),
            label: context.tr('customer.nav.cart'),
          ),
          NavigationDestination(
            icon: Icon(Icons.receipt_long_outlined),
            label: context.tr('customer.profile.orders'),
          ),
        ],
      );
}

void _showWholesaleCartConfirmation({
  required ScaffoldMessengerState messenger,
  required NavigatorState navigator,
  required int storeId,
  required String message,
  required String openCartLabel,
  required String closeTooltip,
  int? productId,
}) {
  messenger.hideCurrentSnackBar();
  final identity = productId ?? storeId;
  messenger.showSnackBar(
    SnackBar(
      key: ValueKey('wholesale-cart-confirmation-$identity'),
      behavior: SnackBarBehavior.floating,
      duration: const Duration(seconds: 5),
      content: Row(
        children: [
          Expanded(child: Text(message)),
          IconButton(
            key: ValueKey('wholesale-cart-confirmation-close-$identity'),
            tooltip: closeTooltip,
            visualDensity: VisualDensity.compact,
            constraints: const BoxConstraints.tightFor(width: 32, height: 32),
            padding: EdgeInsets.zero,
            onPressed: messenger.hideCurrentSnackBar,
            icon: const Icon(
              Icons.close_rounded,
              color: Colors.white,
              size: 19,
            ),
          ),
        ],
      ),
      action: SnackBarAction(
        label: openCartLabel,
        onPressed: () {
          if (!navigator.mounted) return;
          navigator.pushNamed(
            '/b2b/cart?channel=wholesale&store_id=' + storeId.toString(),
          );
        },
      ),
    ),
  );
}

List<Map<String, dynamic>> _wholesaleRowsForSection(
  List<Map<String, dynamic>> rows,
  Map<String, dynamic> section,
) {
  final config = section['config'] is Map
      ? Map<String, dynamic>.from(section['config'] as Map)
      : const <String, dynamic>{};
  final configuredIds = config['product_ids'] is List
      ? (config['product_ids'] as List)
          .map(intValue)
          .where((id) => id > 0)
          .toSet()
      : <int>{};
  if (configuredIds.isNotEmpty) {
    return rows
        .where((row) => configuredIds.contains(intValue(row['id'])))
        .toList(growable: false);
  }

  final type = section['type']?.toString() ?? '';
  final filtered = rows
      .where((row) => _wholesaleRowMatchesSection(row, type))
      .toList(growable: false);
  return filtered.isEmpty ? rows : filtered;
}

Map<String, String> _wholesaleSectionFilters(
  Map<String, dynamic> section, {
  List<Map<String, dynamic>> rows = const <Map<String, dynamic>>[],
}) {
  final type = section['type']?.toString().trim() ?? '';
  final config = section['config'] is Map
      ? Map<String, dynamic>.from(section['config'] as Map)
      : const <String, dynamic>{};

  final configuredIds = config['product_ids'] is List
      ? (config['product_ids'] as List)
          .map(intValue)
          .where((id) => id > 0)
          .toList(growable: false)
      : const <int>[];

  final resolvedIds = configuredIds.isNotEmpty
      ? configuredIds
      : rows
          .map((row) => intValue(row['id']))
          .where((id) => id > 0)
          .toSet()
          .toList(growable: false);

  return <String, String>{
    if (type.isNotEmpty) 'section': type,
    if (resolvedIds.isNotEmpty) 'product_ids': resolvedIds.join(','),
  };
}

bool _wholesaleRowMatchesSection(Map<String, dynamic> row, String type) {
  switch (type) {
    case 'offers':
      final promotion = row['promotion'];
      return promotion is Map ||
          (row['promotion_name']?.toString().trim().isNotEmpty ?? false) ||
          doubleValue(row['discount_percent'] ?? row['discount_value']) > 0;
    case 'featured_products':
      return row['is_featured'] == true || row['featured'] == true;
    case 'best_sellers':
      return row['is_best_seller'] == true ||
          row['best_seller'] == true ||
          doubleValue(row['sales_count']) > 0;
    case 'reorder':
      return row['is_reorder'] == true || row['reorder_candidate'] == true;
    case 'product_grid':
    case 'product_carousel':
    case '':
      return true;
    default:
      return true;
  }
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
    this.onLocaleChanged,
    this.onLogout,
    super.key,
  });

  final String location;
  final CustomerSession session;
  final B2bApi? api;
  final StorefrontApi? storefrontApi;
  final CustomerActionApi actionApi;
  final B2cRetailFavoritesApi? favoritesApi;
  final CustomerPendingActionStore? pendingActionStore;
  final ValueChanged<Locale>? onLocaleChanged;
  final Future<void> Function()? onLogout;

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
  int? _similarCategoryId;
  Future<List<Map<String, dynamic>>>? _similarProductsFuture;

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
    _primeSimilarProducts(row);
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
          'minimum_order_quantity': product['minimum_order_quantity'] ?? 1,
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

  void _primeSimilarProducts(Map<String, dynamic> row) {
    final categoryId = intValue(row['category_id']);
    if (categoryId <= 0 || categoryId == _similarCategoryId) return;
    _similarCategoryId = categoryId;
    _similarProductsFuture = _loadSimilarProducts(categoryId);
  }

  Future<List<Map<String, dynamic>>> _loadSimilarProducts(
    int categoryId,
  ) async {
    List<Map<String, dynamic>> rows;
    final api = widget.api;
    if (api != null) {
      final raw = await api.get(
        '/api/v1/b2b/products?store_id=' +
            storeId.toString() +
            '&category_id=' +
            categoryId.toString(),
      );
      rows = dataRows(raw);
    } else {
      final storefrontApi = widget.storefrontApi;
      if (storefrontApi == null) return const <Map<String, dynamic>>[];
      final storefront = await storefrontApi.wholesaleHome(storeId);
      rows = mapRows(storefront['products']);
    }

    return rows
        .where(
          (item) =>
              intValue(item['id']) != productId &&
              intValue(item['category_id']) == categoryId,
        )
        .take(6)
        .toList(growable: false);
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
    final increment = math.max(.001, doubleValue(row['ordering_increment'], 1));
    final available = row['available_quantity'] == null
        ? null
        : doubleValue(row['available_quantity'], 0);
    final isAvailable = _wholesaleProductIsAvailable(row);
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
    final increment = math.max(.001, doubleValue(row['ordering_increment'], 1));
    final available = row['available_quantity'] == null
        ? null
        : doubleValue(row['available_quantity'], 0);
    final isAvailable = _wholesaleProductIsAvailable(row);
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

  Future<void> _addProduct(Map<String, dynamic> row) async {
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
          final latestMinimum = doubleValue(
            latest['minimum_order_quantity'],
            1,
          );
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
                content: Text(context.tr('b2b.product.terms_updated')),
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
          ScaffoldMessenger.of(context)
              .showSnackBar(SnackBar(content: Text(issue)));
        }
        return;
      }

      if (!mounted) return;
      final confirmationMessenger = ScaffoldMessenger.maybeOf(context);
      final confirmationNavigator = Navigator.of(context);
      final confirmationMessage = context.tr('b2b.catalog.added');
      final confirmationOpenCart = context.tr('b2b.catalog.open_cart');
      final confirmationClose =
          MaterialLocalizations.of(context).closeButtonTooltip;

      await widget.actionApi.addCartItem(
        storeId: storeId,
        productId: productId,
        quantity: selectedQuantity,
      );
      if (mounted &&
          confirmationMessenger != null &&
          confirmationMessenger.mounted) {
        _showWholesaleCartConfirmation(
          messenger: confirmationMessenger,
          navigator: confirmationNavigator,
          storeId: storeId,
          productId: productId,
          message: confirmationMessage,
          openCartLabel: confirmationOpenCart,
          closeTooltip: confirmationClose,
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
        body: FoodexUnifiedHeaderSurface(
          title: context.tr('b2b.app.title'),
          location: widget.location,
          searchHint: context.tr(
            'customer.products.search_name_sku_barcode',
          ),
          child: FutureBuilder<Object?>(
            future: future,
            builder: (context, snapshot) {
              if (snapshot.connectionState != ConnectionState.done) {
                return const FoodexLoading(key: ValueKey('b2b-loading'));
              }
              if (snapshot.hasError) {
                final failure = _wholesaleProductFailureInfo(snapshot.error);
                return ListView(
                  key: ValueKey(
                    failure.forbiddenVisual ? 'b2b-forbidden' : 'b2b-error',
                  ),
                  padding: const EdgeInsets.fromLTRB(15, 10, 15, 22),
                  children: [
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
                      onPressed: () =>
                          Navigator.of(context).pushReplacementNamed(
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

              final minimum = doubleValue(row['minimum_order_quantity'], 1);
              final increment = math.max(
                .001,
                doubleValue(row['ordering_increment'], 1),
              );
              quantity ??= minimum;
              final images = (row['images'] as List? ?? const <Object>[])
                  .whereType<String>()
                  .where((value) => value.trim().isNotEmpty)
                  .toList(growable: true);
              final primary = row['image_url']?.toString();
              if (images.isEmpty && primary != null && primary.isNotEmpty) {
                images.add(primary);
              }
              final currency =
                  row['currency']?.toString().trim().isNotEmpty == true
                      ? row['currency'].toString().trim().toUpperCase()
                      : 'EGP';
              final isAvailable = _wholesaleProductIsAvailable(row);
              final availableQuantity = row['available_quantity'] == null
                  ? null
                  : doubleValue(row['available_quantity'], 0);
              final quantityIssue = _quantityIssue(context, row, quantity!);
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
                  Row(
                    mainAxisAlignment: MainAxisAlignment.end,
                    children: [
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
                        color: CustomerUiColors.wholesaleSecondaryText,
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
                    child: _PricingPanel(row: row, currency: currency),
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
                    child: _InfoPill(
                      icon: Icons.inventory_2_outlined,
                      label: context.tr('b2b.minimum_order') +
                          ' ' +
                          compactNumber(minimum),
                    ),
                  ),
                  if (quantityIssue != null && isAvailable) ...[
                    const SizedBox(height: 8),
                    Text(
                      quantityIssue,
                      key: const ValueKey('b2b-product-quantity-error'),
                      style: const TextStyle(
                        color: CustomerUiColors.destructive,
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
                                    context.tr('b2b.product.quantity_stock') +
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
                            final issue = _quantityIssue(
                              context,
                              row,
                              quantity!,
                            );
                            if (issue != null) {
                              ScaffoldMessenger.of(context)
                                  .showSnackBar(SnackBar(content: Text(issue)));
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
                        color: CustomerUiColors.wholesaleSecondaryText,
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
                            color: CustomerUiColors.wholesaleSecondaryText,
                            fontSize: 13,
                            height: 1.55,
                          ),
                        ),
                      ],
                    ),
                  ),
                  if (_similarProductsFuture != null) ...[
                    const SizedBox(height: 16),
                    Text(
                      context.tr('b2b.product.similar_products'),
                      key: const ValueKey('b2b-product-similar-title'),
                      style: const TextStyle(
                        color: Color(0xFF10233F),
                        fontSize: 16,
                        fontWeight: FontWeight.w900,
                      ),
                    ),
                    const SizedBox(height: 8),
                    FutureBuilder<List<Map<String, dynamic>>>(
                      future: _similarProductsFuture,
                      builder: (context, similarSnapshot) {
                        if (similarSnapshot.connectionState !=
                            ConnectionState.done) {
                          return const Padding(
                            padding: EdgeInsets.symmetric(vertical: 18),
                            child: Center(
                              child: FoodexWaveLoading(),
                            ),
                          );
                        }
                        final similar = similarSnapshot.data ??
                            const <Map<String, dynamic>>[];
                        if (similar.isEmpty) return const SizedBox.shrink();
                        return _WholesaleProductGrid(
                          rows: similar,
                          storeId: storeId,
                          sourceLocation: widget.location,
                          session: widget.session,
                          actionApi: widget.actionApi,
                          pendingActionStore: widget.pendingActionStore,
                          palette: FoodexPalette.wholesale,
                          favoritesApi: widget.favoritesApi,
                          maxItems: 6,
                          showCommerceActions: true,
                        );
                      },
                    ),
                  ],
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
  const _ProductMetaChip({required this.label, required this.value, super.key});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 11, vertical: 6),
        decoration: BoxDecoration(
          color: CustomerUiColors.wholesalePriceBadgeBackground,
          borderRadius: BorderRadius.circular(999),
        ),
        child: Text(
          '$label: $value',
          style: const TextStyle(
            color: CustomerUiColors.wholesaleVehicleGreen,
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
    final supportReference = _safeWholesaleSupportReference(
      error.supportReference,
    );

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
  return RegExp(r'^[A-Za-z0-9._:/-]+$').hasMatch(reference) ? reference : null;
}

class _WholesaleCatalogCategoryIcon extends StatelessWidget {
  const _WholesaleCatalogCategoryIcon({
    required this.label,
    required this.selected,
    required this.palette,
    required this.onTap,
    this.imageUrl,
    this.icon,
    super.key,
  });

  final String label;
  final String? imageUrl;
  final IconData? icon;
  final bool selected;
  final FoodexPalette palette;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsetsDirectional.only(end: 8),
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(14),
          child: SizedBox(
            width: 58,
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                AnimatedContainer(
                  duration: const Duration(milliseconds: 160),
                  width: 34,
                  height: 34,
                  clipBehavior: Clip.antiAlias,
                  decoration: BoxDecoration(
                    color: selected
                        ? palette.accent.withValues(alpha: .22)
                        : Colors.white,
                    shape: BoxShape.circle,
                    border: Border.all(
                      color: selected ? palette.primary : palette.soft,
                      width: selected ? 1.5 : 1,
                    ),
                    boxShadow: const <BoxShadow>[
                      BoxShadow(
                        color: CustomerUiColors.wholesaleSoftShadow,
                        blurRadius: 5,
                        offset: Offset(0, 2),
                      ),
                    ],
                  ),
                  child: imageUrl != null && imageUrl!.trim().isNotEmpty
                      ? Image.network(
                          imageUrl!,
                          fit: BoxFit.cover,
                          errorBuilder: (_, __, ___) => Icon(
                            icon ?? Icons.category_outlined,
                            size: 16,
                            color: palette.primary,
                          ),
                        )
                      : Icon(
                          icon ?? Icons.category_outlined,
                          size: 16,
                          color: palette.primary,
                        ),
                ),
                const SizedBox(height: 4),
                Text(
                  label,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  textAlign: TextAlign.center,
                  style: TextStyle(
                    color: selected ? palette.primaryDark : palette.text,
                    fontSize: 8.5,
                    height: 1.05,
                    fontWeight: selected ? FontWeight.w900 : FontWeight.w700,
                  ),
                ),
              ],
            ),
          ),
        ),
      );
}

class _PricingPanel extends StatelessWidget {
  const _PricingPanel({required this.row, required this.currency});

  final Map<String, dynamic> row;
  final String currency;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 2, vertical: 4),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              money(row['account_price'], currency: currency),
              style: const TextStyle(
                color: CustomerUiColors.wholesalePositiveValue,
                fontSize: 28,
                fontWeight: FontWeight.w900,
                height: 1,
              ),
            ),
            const SizedBox(height: 5),
            Text(
              context.tr('b2b.product.account_price'),
              style: const TextStyle(
                color: CustomerUiColors.muted,
                fontSize: 12,
                fontWeight: FontWeight.w700,
              ),
            ),
            if (row['retail_reference_price'] != null) ...[
              const SizedBox(height: 8),
              Text(
                context.tr('b2b.product.retail_reference_price') +
                    ': ' +
                    money(row['retail_reference_price'], currency: currency),
                style: const TextStyle(
                  color: CustomerUiColors.wholesaleSecondaryText,
                  fontSize: 11,
                  fontWeight: FontWeight.w700,
                ),
              ),
            ],
          ],
        ),
      );
}

class _InfoPill extends StatelessWidget {
  const _InfoPill({required this.icon, required this.label});

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
            Icon(icon,
                size: 18, color: CustomerUiColors.wholesalePositiveValue),
            const SizedBox(width: 7),
            Expanded(
              child: Text(
                label,
                style:
                    const TextStyle(fontSize: 11, fontWeight: FontWeight.w700),
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

class _WholesaleCartDesignScreenState extends State<WholesaleCartDesignScreen>
    with WidgetsBindingObserver {
  late int storeId = wholesaleStoreId(widget.location);
  late Future<Object?> future = _load();
  final Set<int> _removedItemIds = <int>{};
  final Map<int, double> _optimisticQuantities = <int, double>{};
  final Map<int, double> _queuedQuantities = <int, double>{};
  final Set<int> _syncingQuantityIds = <int>{};
  Map<String, dynamic>? _cartOverride;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed && mounted) {
      setState(() {
        _cartOverride = null;
        future = _load();
      });
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
          for (final id in removedItemIds) {
            _optimisticQuantities.remove(id);
            _queuedQuantities.remove(id);
          }
          _cartOverride = null;
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

  void _stepQuantity({
    required int id,
    required double baseQuantity,
    required double increment,
    required double minimum,
    required bool increase,
    double? available,
  }) {
    final api = widget.commerceApi;
    if (api == null || id <= 0 || increment <= 0) return;

    final current = _optimisticQuantities[id] ?? baseQuantity;
    final rawNext = increase ? current + increment : current - increment;
    final next = increase ? rawNext : math.max(minimum, rawNext).toDouble();

    if ((next - current).abs() < .0001) return;
    if (available != null && next > available + .0001) return;

    setState(() {
      _optimisticQuantities[id] = next;
      // Keep only the latest desired target while an earlier request is in
      // flight. This makes rapid taps cheap and deterministic on slow links.
      _queuedQuantities[id] = next;
    });
    unawaited(_drainQuantityUpdates(id));
  }

  Future<void> _drainQuantityUpdates(int id) async {
    if (_syncingQuantityIds.contains(id)) return;
    final api = widget.commerceApi;
    if (api == null) return;

    _syncingQuantityIds.add(id);
    try {
      while (mounted) {
        final target = _queuedQuantities.remove(id);
        if (target == null) break;

        final value = await api.updateItem(id, target);
        if (!mounted) return;

        if (value is Map) {
          final authoritative = Map<String, dynamic>.from(value);
          setState(() {
            _cartOverride = authoritative;
            final optimistic = _optimisticQuantities[id];
            if (!_queuedQuantities.containsKey(id) &&
                optimistic != null &&
                (optimistic - target).abs() < .0001) {
              _optimisticQuantities.remove(id);
            }
          });
          continue;
        }

        // Legacy/custom adapters may not return the authoritative cart from
        // PATCH. Refresh once only after the queue is drained, never per tap.
        if (!_queuedQuantities.containsKey(id)) {
          final refreshed = await _load();
          if (!mounted) return;
          if (!_queuedQuantities.containsKey(id)) {
            setState(() {
              _cartOverride = refreshed is Map
                  ? Map<String, dynamic>.from(refreshed)
                  : null;
              _optimisticQuantities.remove(id);
            });
          }
        }
      }
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _queuedQuantities.remove(id);
        _optimisticQuantities.remove(id);
      });
      await showOperationalError(context, error);
      if (mounted) {
        setState(() {
          _cartOverride = null;
          future = _load();
        });
      }
    } finally {
      _syncingQuantityIds.remove(id);
      if (mounted && _queuedQuantities.containsKey(id)) {
        unawaited(_drainQuantityUpdates(id));
      }
    }
  }

  Future<void> _remove(int id) async {
    final api = widget.commerceApi;
    if (api == null) return;
    await _mutate(() => api.removeItem(id), removedItemIds: <int>[id]);
  }

  Future<void> _clear(List<Map<String, dynamic>> rows) async {
    final api = widget.commerceApi;
    if (api == null) return;
    await _mutate(
      () async {
        for (final row in rows) {
          final id = intValue(row['id']);
          if (id > 0) {
            await api.removeItem(id);
          }
        }
      },
      removedItemIds:
          rows.map((row) => intValue(row['id'])).where((id) => id > 0),
    );
  }

  @override
  Widget build(BuildContext context) => Directionality(
        textDirection: Directionality.of(context),
        child: Scaffold(
          backgroundColor: const Color(0xFFF8FBF9),
          body: FoodexUnifiedHeaderSurface(
            title: context.tr('b2b.app.title'),
            location: widget.location,
            searchHint: context.tr(
              'customer.products.search_name_sku_barcode',
            ),
            leading: IconButton(
              key: const ValueKey('b2b-cart-back'),
              tooltip: MaterialLocalizations.of(context).backButtonTooltip,
              onPressed: () => Navigator.of(context).maybePop(),
              icon: const BackButtonIcon(),
              color: Colors.white,
            ),
            child: FutureBuilder<Object?>(
              future: future,
              builder: (context, snapshot) {
                if (snapshot.connectionState != ConnectionState.done) {
                  return const FoodexLoading(key: ValueKey('b2b-loading'));
                }
                if (snapshot.hasError) {
                  return FoodexErrorState(
                    key: const ValueKey('b2b-error'),
                    message: context.tr('b2b.cart.subtitle'),
                    onRetry: () => setState(() => future = _load()),
                  );
                }

                final cart = _cartOverride ??
                    (snapshot.data is Map
                        ? Map<String, dynamic>.from(snapshot.data as Map)
                        : <String, dynamic>{});
                WidgetsBinding.instance.addPostFrameCallback((_) {
                  syncFoodexWholesaleCartProductCount(cart);
                });
                if (storeId <= 0) {
                  storeId = intValue(cart['store_id']);
                }
                final rows = mapRows(cart['items'])
                    .where(
                        (row) => !_removedItemIds.contains(intValue(row['id'])))
                    .toList(growable: false);
                final hasUnavailable = cart['has_unavailable_items'] == true ||
                    rows.any((row) => row['is_available'] == false);

                return ListView(
                  key: const ValueKey('b2b-cart-data'),
                  padding: const EdgeInsets.fromLTRB(22, 8, 22, 24),
                  children: [
                    Text(
                      context.tr('b2b.cart.title'),
                      key: const ValueKey('b2b-cart-content-title'),
                      style: const TextStyle(
                        color: CustomerUiColors.ink,
                        fontSize: 17,
                        fontWeight: FontWeight.w900,
                      ),
                    ),
                    const SizedBox(height: 6),
                    if (rows.isNotEmpty && widget.commerceApi != null)
                      Align(
                        alignment: AlignmentDirectional.centerEnd,
                        child: TextButton.icon(
                          key: const ValueKey('b2b-cart-clear'),
                          onPressed: () => _clear(rows),
                          icon:
                              const Icon(Icons.delete_sweep_outlined, size: 16),
                          label: Text(context.tr('b2b.cart.clear')),
                        ),
                      ),
                    const SizedBox(height: 6),
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
                        final serverQty = doubleValue(row['quantity'], 1);
                        final qty = _optimisticQuantities[id] ?? serverQty;
                        final increment =
                            doubleValue(row['ordering_increment'], 1);
                        final minimum = doubleValue(
                          row['minimum_order_quantity'],
                          1,
                        );
                        final available = row['available_quantity'] == null
                            ? null
                            : doubleValue(row['available_quantity'], 0);

                        return _CartLine(
                          itemId: id,
                          name: product['name']?.toString() ??
                              row['name']?.toString() ??
                              '',
                          subtitle: row['pack_label']?.toString().trim() ?? '',
                          imageUrl: product['image_url']?.toString(),
                          quantity: qty,
                          unitPrice:
                              row['unit_price_snapshot'] ?? row['unit_price'],
                          lineTotal: row['line_total'],
                          onMinus: widget.commerceApi == null ||
                                  qty <= minimum + .0001
                              ? null
                              : () => _stepQuantity(
                                    id: id,
                                    baseQuantity: serverQty,
                                    increment: increment,
                                    minimum: minimum,
                                    available: available,
                                    increase: false,
                                  ),
                          onPlus: widget.commerceApi == null ||
                                  (available != null &&
                                      qty + increment > available + .0001)
                              ? null
                              : () => _stepQuantity(
                                    id: id,
                                    baseQuantity: serverQty,
                                    increment: increment,
                                    minimum: minimum,
                                    available: available,
                                    increase: true,
                                  ),
                          onRemove: widget.commerceApi == null || id <= 0
                              ? null
                              : () => _remove(id),
                        );
                      }),
                    const SizedBox(height: 14),
                    _CartTotalPanel(
                      cart: cart,
                      enabled:
                          rows.isNotEmpty && storeId > 0 && !hasUnavailable,
                      onCheckout: () => Navigator.of(
                        context,
                      ).pushNamed(
                          '/b2b/checkout?store_id=' + storeId.toString()),
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
  final VoidCallback? onMinus;
  final VoidCallback? onPlus;
  final VoidCallback? onRemove;

  @override
  Widget build(BuildContext context) {
    final validImage = imageUrl != null && imageUrl!.trim().isNotEmpty;
    Widget imageFallback() => const DecoratedBox(
          decoration: BoxDecoration(
            color: Color(0xFFF1F8F4),
            borderRadius: BorderRadius.all(Radius.circular(14)),
          ),
          child: Center(
            child: Icon(Icons.inventory_2_outlined,
                color: CustomerUiColors.wholesalePositiveValue),
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
                        icon: const Icon(
                          Icons.delete_outline_rounded,
                          size: 20,
                          color: Color(0xFFD92D20),
                        ),
                      ),
                  ],
                ),
                if (subtitle.isNotEmpty)
                  Text(
                    subtitle,
                    style: const TextStyle(
                      fontSize: 10,
                      color: CustomerUiColors.wholesaleSecondaryText,
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
                        color: CustomerUiColors.wholesalePositiveValue,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 8),
                CustomerQuantityStepper(
                  valueText: compactNumber(quantity),
                  decreaseKey: ValueKey('b2b-cart-minus-$itemId'),
                  increaseKey: ValueKey('b2b-cart-plus-$itemId'),
                  onDecrease: onMinus,
                  onIncrease: onPlus,
                  iconSize: 12,
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
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

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        CustomerCheckoutTotalsCard(
          key: const ValueKey('b2b-cart-totals'),
          rows: [
            CustomerCheckoutTotalRow(
              label: context.tr('b2b.cart.subtotal'),
              value: money(subtotal, currency: currency),
            ),
            if (doubleValue(discount, 0) > 0)
              CustomerCheckoutTotalRow(
                label: context.tr('b2b.cart.discount'),
                value: '- ' + money(discount, currency: currency),
              ),
            if (delivery != null)
              CustomerCheckoutTotalRow(
                label: context.tr('b2b.cart.delivery'),
                value: money(delivery, currency: currency),
              ),
            if (tax != null)
              CustomerCheckoutTotalRow(
                label: context.tr('b2b.cart.tax'),
                value: money(tax, currency: currency),
              ),
            CustomerCheckoutTotalRow(
              label: context.tr('b2b.cart.total'),
              value: money(total, currency: currency),
              emphasized: true,
            ),
          ],
        ),
        const SizedBox(height: 9),
        Text(
          context.tr('b2b.cart.revalidation'),
          style: const TextStyle(
            color: CustomerUiColors.wholesaleSecondaryText,
            fontSize: 10,
            height: 1.35,
          ),
        ),
        const SizedBox(height: 13),
        SizedBox(
          height: 52,
          child: _WholesaleWaveCheckoutButton(
            key: const ValueKey('b2b-cart-checkout'),
            enabled: enabled,
            label: context.tr('b2b.action.checkout'),
            onPressed: onCheckout,
          ),
        ),
      ],
    );
  }
}

class _WholesaleWaveCheckoutButton extends StatefulWidget {
  const _WholesaleWaveCheckoutButton({
    required this.enabled,
    required this.label,
    required this.onPressed,
    super.key,
  });

  final bool enabled;
  final String label;
  final VoidCallback onPressed;

  @override
  State<_WholesaleWaveCheckoutButton> createState() =>
      _WholesaleWaveCheckoutButtonState();
}

class _WholesaleWaveCheckoutButtonState
    extends State<_WholesaleWaveCheckoutButton>
    with SingleTickerProviderStateMixin {
  late final AnimationController _waves = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 1800),
  );

  @override
  void initState() {
    super.initState();
    if (widget.enabled) _waves.repeat();
  }

  @override
  void didUpdateWidget(covariant _WholesaleWaveCheckoutButton oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (widget.enabled && !_waves.isAnimating) {
      _waves.repeat();
    } else if (!widget.enabled && _waves.isAnimating) {
      _waves.stop();
      _waves.value = 0;
    }
  }

  @override
  void dispose() {
    _waves.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Material(
        color: widget.enabled
            ? CustomerUiColors.wholesaleVehicleGreen
            : CustomerUiColors.wholesaleDisabled,
        borderRadius: BorderRadius.circular(18),
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: widget.enabled ? widget.onPressed : null,
          child: Stack(
            fit: StackFit.expand,
            children: [
              if (widget.enabled)
                AnimatedBuilder(
                  animation: _waves,
                  builder: (context, _) => CustomPaint(
                    painter: _WholesaleCheckoutWavePainter(
                      progress: _waves.value,
                    ),
                  ),
                ),
              Center(
                child: Text(
                  widget.label,
                  style: const TextStyle(
                    color: Colors.white,
                    fontSize: 16,
                    fontWeight: FontWeight.w900,
                  ),
                ),
              ),
            ],
          ),
        ),
      );
}

class _WholesaleCheckoutWavePainter extends CustomPainter {
  const _WholesaleCheckoutWavePainter({required this.progress});

  final double progress;

  @override
  void paint(Canvas canvas, Size size) {
    final phase = progress * math.pi * 2;

    void drawWave({
      required double baseline,
      required double amplitude,
      required double frequency,
      required double phaseShift,
      required Color color,
    }) {
      final path = ui.Path()..moveTo(0, size.height);
      path.lineTo(0, baseline);
      for (double x = 0; x <= size.width; x += 3) {
        final y = baseline +
            math.sin((x / size.width) * math.pi * 2 * frequency +
                    phase +
                    phaseShift) *
                amplitude;
        path.lineTo(x, y);
      }
      path
        ..lineTo(size.width, size.height)
        ..close();
      canvas.drawPath(path, Paint()..color = color);
    }

    drawWave(
      baseline: size.height * .53,
      amplitude: 4.5,
      frequency: 1.35,
      phaseShift: 0,
      color: Colors.white.withValues(alpha: .12),
    );
    drawWave(
      baseline: size.height * .64,
      amplitude: 5.5,
      frequency: 1.1,
      phaseShift: math.pi,
      color: CustomerUiColors.wholesaleWaveAccent.withValues(alpha: .13),
    );
  }

  @override
  bool shouldRepaint(covariant _WholesaleCheckoutWavePainter oldDelegate) =>
      oldDelegate.progress != progress;
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
  String? paymentMethod;
  bool submitting = false;
  bool applyingCoupon = false;
  String? appliedCouponCode;
  Map<String, dynamic>? appliedQuote;
  Map<String, dynamic>? createdOrder;
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

  String get _normalizedCoupon => coupon.text.trim().toUpperCase();

  Map<String, dynamic> _quoteMap(Object? raw) {
    if (raw is! Map) return const <String, dynamic>{};
    final map = Map<String, dynamic>.from(raw);
    final data = map['data'];
    return data is Map ? Map<String, dynamic>.from(data) : map;
  }

  void _couponChanged(String value) {
    final normalized = value.trim().toUpperCase();
    setState(() {
      if (appliedCouponCode != null && normalized != appliedCouponCode) {
        appliedCouponCode = null;
        appliedQuote = null;
      }
    });
  }

  Future<void> _applyCoupon() async {
    final code = _normalizedCoupon;
    final commerceApi = widget.commerceApi;
    if (code.isEmpty ||
        commerceApi == null ||
        commerceApi is! WholesaleQuoteApi ||
        applyingCoupon) {
      return;
    }
    final WholesaleQuoteApi quoteApi = commerceApi as WholesaleQuoteApi;

    setState(() => applyingCoupon = true);
    try {
      final raw = await quoteApi.quote(
        storeId: storeId,
        couponCode: code,
        paymentMethod: paymentMethod,
      );
      final next = _quoteMap(raw);
      if (next.isEmpty) {
        throw const WholesaleCommerceException(
          'Coupon quote did not return pricing details.',
        );
      }
      if (!mounted) return;
      final couponData = next['coupon'];
      final confirmedCode = couponData is Map &&
              couponData['code']?.toString().trim().isNotEmpty == true
          ? couponData['code'].toString().trim().toUpperCase()
          : code;
      coupon.value = coupon.value.copyWith(
        text: confirmedCode,
        selection: TextSelection.collapsed(offset: confirmedCode.length),
      );
      setState(() {
        appliedCouponCode = confirmedCode;
        appliedQuote = next;
        applyingCoupon = false;
      });
    } catch (error) {
      if (!mounted) return;
      setState(() {
        appliedCouponCode = null;
        appliedQuote = null;
        applyingCoupon = false;
      });
      await showOperationalError(context, error);
    }
  }

  Future<void> _submit() async {
    final api = widget.commerceApi;
    if (api == null ||
        addressId == null ||
        paymentMethod == null ||
        submitting) {
      return;
    }

    setState(() => submitting = true);
    try {
      final result = await api.checkout(
        storeId: storeId,
        addressId: addressId!,
        paymentMethod: paymentMethod!,
        requestedDeliveryDate: null,
        note: note.text,
        couponCode: appliedCouponCode,
        idempotencyKey: idempotencyKey,
      );
      if (!mounted) return;
      final orderId = result is Map ? intValue(result['id']) : 0;
      if (result is! Map || orderId <= 0) {
        throw const WholesaleCommerceException(
          'Checkout completed without a valid order reference.',
        );
      }
      setState(() {
        createdOrder = Map<String, dynamic>.from(result);
        submitting = false;
      });
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
          body: FoodexUnifiedHeaderSurface(
            title: context.tr('b2b.app.title'),
            location: widget.location,
            searchHint: context.tr(
              'customer.products.search_name_sku_barcode',
            ),
            child: FutureBuilder<_CheckoutPayload>(
              future: future,
              builder: (context, snapshot) {
                final success = createdOrder;
                if (success != null) {
                  final ar =
                      Localizations.localeOf(context).languageCode == 'ar';
                  final orderId = intValue(success['id']);
                  final currency =
                      success['currency']?.toString().trim().isNotEmpty == true
                          ? success['currency'].toString()
                          : 'KWD';
                  final reference = success['order_number']
                              ?.toString()
                              .trim()
                              .isNotEmpty ==
                          true
                      ? success['order_number'].toString().trim()
                      : (success['display_order_number']
                                  ?.toString()
                                  .trim()
                                  .isNotEmpty ==
                              true
                          ? success['display_order_number'].toString().trim()
                          : '#$orderId');
                  final subtotal = doubleValue(success['subtotal'], 0);
                  final discount = doubleValue(success['discount_total'], 0);
                  final delivery = doubleValue(success['delivery_total'], 0);
                  final total = doubleValue(success['grand_total'], 0);

                  return CustomerOrderSuccessPanel(
                    title: ar ? 'تم تأكيد طلبك' : 'Order confirmed',
                    message: ar
                        ? 'تم إنشاء الطلب بنجاح ويمكنك متابعة حالته الآن.'
                        : 'Your order was created successfully and is ready to track.',
                    orderReferenceLabel: ar ? 'رقم الطلب' : 'Order number',
                    orderReference: reference,
                    trackLabel: ar ? 'تتبع الطلب' : 'Track order',
                    continueLabel: ar ? 'متابعة التسوق' : 'Continue shopping',
                    onTrack: () => Navigator.of(context).pushReplacementNamed(
                      Uri(
                        path: '/b2b/orders/$orderId',
                        queryParameters: <String, String>{
                          'channel': 'wholesale',
                          'store_id': storeId.toString(),
                        },
                      ).toString(),
                    ),
                    onContinue: () =>
                        Navigator.of(context).pushReplacementNamed(
                      Uri(
                        path: CustomerRoutePaths.b2bProducts,
                        queryParameters: <String, String>{
                          'channel': 'wholesale',
                          'store_id': storeId.toString(),
                        },
                      ).toString(),
                    ),
                    summary: CustomerCheckoutTotalsCard(
                      rows: [
                        CustomerCheckoutTotalRow(
                          label:
                              ar ? 'السعر قبل الخصم' : 'Price before discount',
                          value: foodexMoneyText(subtotal, currency),
                          valueColor: CustomerUiColors.wholesalePositiveValue,
                        ),
                        if (discount > 0)
                          CustomerCheckoutTotalRow(
                            label: ar ? 'الخصم' : 'Discount',
                            value: '-${foodexMoneyText(discount, currency)}',
                            valueColor: CustomerUiColors.wholesaleNegativeValue,
                          ),
                        if (delivery > 0)
                          CustomerCheckoutTotalRow(
                            label: ar ? 'التوصيل' : 'Delivery',
                            value: foodexMoneyText(delivery, currency),
                          ),
                        CustomerCheckoutTotalRow(
                          label: ar ? 'السعر النهائي' : 'Final price',
                          value: foodexMoneyText(total, currency),
                          emphasized: true,
                          valueColor: CustomerUiColors.wholesaleFinalValue,
                        ),
                      ],
                    ),
                  );
                }

                if (snapshot.connectionState != ConnectionState.done) {
                  return const FoodexLoading(
                      key: ValueKey('b2b-checkout-loading'));
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
                final methods =
                    (data['payment_methods'] as List? ?? const <Object>[])
                        .map((value) => value.toString())
                        .toList(growable: false);
                final items = mapRows(cart['items']);
                final cartQuote = cart['quote'] is Map
                    ? Map<String, dynamic>.from(cart['quote'] as Map)
                    : <String, dynamic>{};
                final effectiveQuote = appliedQuote ?? cartQuote;
                final grandTotal = doubleValue(
                  effectiveQuote['grand_total'] ??
                      cart['grand_total'] ??
                      cart['subtotal'],
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

                final creditInsufficient = paymentMethod == 'account_credit' &&
                    grandTotal > purchasingPower + .0001;
                final couponReady = _normalizedCoupon.isEmpty ||
                    appliedCouponCode == _normalizedCoupon;
                final canSubmit = widget.commerceApi != null &&
                    items.isNotEmpty &&
                    !hasUnavailable &&
                    addressId != null &&
                    paymentMethod != null &&
                    couponReady &&
                    !creditInsufficient &&
                    !submitting;

                return ListView(
                  key: const ValueKey('b2b-checkout-data'),
                  padding: const EdgeInsets.fromLTRB(22, 8, 22, 24),
                  children: [
                    CustomerCheckoutProgress(
                      key: const ValueKey('b2b-checkout-progress'),
                      steps: [
                        CustomerCheckoutStep(
                          label: context.tr('b2b.checkout.address'),
                          icon: Icons.location_on_outlined,
                        ),
                        CustomerCheckoutStep(
                          label: context.tr('b2b.checkout.payment_method'),
                          icon: Icons.payments_outlined,
                        ),
                        CustomerCheckoutStep(
                          label: context.tr('b2b.checkout.review'),
                          icon: Icons.fact_check_outlined,
                        ),
                      ],
                    ),
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
                          title: address['label']?.toString() ??
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
                              () => addressId = intValue(address['id'])),
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
                      onChanged: (value) => setState(() {
                        paymentMethod = value;
                        appliedCouponCode = null;
                        appliedQuote = null;
                      }),
                    ),
                    if (creditInsufficient) ...[
                      const SizedBox(height: 8),
                      Text(
                        context.tr('b2b.checkout.insufficient_credit'),
                        key: const ValueKey('b2b-checkout-insufficient-credit'),
                        style: const TextStyle(
                          color: Color(0xFFB3261E),
                          fontSize: 11,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    ],
                    const SizedBox(height: 12),
                    Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Expanded(
                          child: TextField(
                            key: const ValueKey('b2b-checkout-coupon'),
                            controller: coupon,
                            textCapitalization: TextCapitalization.characters,
                            onChanged: _couponChanged,
                            decoration: InputDecoration(
                              labelText: context.tr('b2b.checkout.coupon'),
                              prefixIcon: const Icon(
                                Icons.confirmation_number_outlined,
                              ),
                            ),
                          ),
                        ),
                        const SizedBox(width: 8),
                        SizedBox(
                          height: 56,
                          child: FilledButton(
                            key: const ValueKey(
                              'b2b-checkout-apply-coupon',
                            ),
                            onPressed: applyingCoupon ||
                                    _normalizedCoupon.isEmpty ||
                                    widget.commerceApi is! WholesaleQuoteApi
                                ? null
                                : _applyCoupon,
                            child: applyingCoupon
                                ? const SizedBox.square(
                                    dimension: 18,
                                    child: FoodexWaveLoading(),
                                  )
                                : Text(
                                    context.tr(
                                      'b2b.checkout.apply_coupon',
                                    ),
                                  ),
                          ),
                        ),
                      ],
                    ),
                    if (appliedCouponCode != null) ...[
                      const SizedBox(height: 6),
                      Text(
                        context.tr('b2b.checkout.coupon_applied') +
                            ': ' +
                            appliedCouponCode!,
                        key: const ValueKey(
                          'b2b-checkout-coupon-applied',
                        ),
                        style: const TextStyle(
                          color: CustomerUiColors.wholesalePositiveValue,
                          fontSize: 11,
                          fontWeight: FontWeight.w800,
                        ),
                      ),
                    ],
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
                      paymentLabel: paymentMethod == null
                          ? '-'
                          : _paymentLabel(context, paymentMethod!),
                    ),
                    const SizedBox(height: 12),
                    _CheckoutSummary(
                      cart: cart,
                      quoteOverride: appliedQuote,
                    ),
                    const SizedBox(height: 18),
                    SizedBox(
                      height: 54,
                      child: _WholesaleWaveCheckoutButton(
                        key: const ValueKey('b2b-checkout-submit'),
                        enabled: canSubmit,
                        label: submitting
                            ? context.tr('b2b.checkout.submitting')
                            : context.tr('b2b.checkout.confirm'),
                        onPressed: _submit,
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

class _CheckoutReviewCard extends StatelessWidget {
  const _CheckoutReviewCard({
    required this.data,
    required this.address,
    required this.paymentLabel,
  });

  final Map<String, dynamic> data;
  final Map<String, dynamic>? address;
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
            style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w800),
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
                        color: CustomerUiColors.wholesaleSecondaryText,
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
  const _CheckoutPayload({required this.options, required this.cart});

  final Map<String, dynamic> options;
  final Map<String, dynamic> cart;
}

class _CheckoutSummary extends StatelessWidget {
  const _CheckoutSummary({
    required this.cart,
    this.quoteOverride,
  });

  final Map<String, dynamic> cart;
  final Map<String, dynamic>? quoteOverride;

  @override
  Widget build(BuildContext context) {
    final items = mapRows(cart['items']);
    final currency = cart['currency']?.toString() ?? 'EGP';
    final quote = quoteOverride ??
        (cart['quote'] is Map
            ? Map<String, dynamic>.from(cart['quote'] as Map)
            : <String, dynamic>{});
    final subtotal = quote['subtotal'] ?? cart['subtotal'];
    final discount = quote['discount_total'];
    final delivery = quote['delivery_total'];
    final tax = quote['tax_total'];
    final grandTotal = quote['grand_total'] ??
        cart['grand_total'] ??
        cart['total'] ??
        subtotal;

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
            style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w800),
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
                        color: CustomerUiColors.wholesaleSecondaryText,
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
            label: context.tr('b2b.checkout.price_before'),
            value: money(subtotal, currency: currency),
            valueColor: CustomerUiColors.wholesalePositiveValue,
          ),
          if (doubleValue(discount, 0) > 0)
            _SummaryRow(
              label: context.tr('b2b.cart.discount'),
              value: '- ' + money(discount, currency: currency),
              valueColor: CustomerUiColors.wholesaleNegativeValue,
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
            label: context.tr('b2b.checkout.final_price'),
            value: money(grandTotal, currency: currency),
            strong: true,
            valueColor: CustomerUiColors.wholesaleFinalValue,
          ),
          const SizedBox(height: 7),
          Text(
            context.tr('b2b.cart.revalidation'),
            style: const TextStyle(
              fontSize: 10,
              height: 1.4,
              color: CustomerUiColors.wholesaleSecondaryText,
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
    this.valueColor,
  });

  final String label;
  final String value;
  final bool strong;
  final Color? valueColor;

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
                  fontWeight: strong ? FontWeight.w800 : FontWeight.w500,
                ),
              ),
            ),
            Text(
              value,
              style: TextStyle(
                color: valueColor ??
                    (strong
                        ? CustomerUiColors.wholesalePositiveValue
                        : CustomerUiColors.wholesaleCommerceInk),
                fontSize: strong ? 16 : 12,
                fontWeight: FontWeight.w800,
              ),
            ),
          ],
        ),
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
                      ? CustomerUiColors.wholesalePositiveValue
                      : CustomerUiColors.wholesaleSelectionIdle,
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
                        ? CustomerUiColors.wholesalePositiveValue
                        : CustomerUiColors.wholesaleSecondaryText,
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          title,
                          style: const TextStyle(fontWeight: FontWeight.w800),
                        ),
                        const SizedBox(height: 3),
                        Text(
                          subtitle,
                          style: const TextStyle(
                            color: CustomerUiColors.wholesaleSecondaryText,
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
    required this.location,
    required this.api,
    required this.commerceApi,
    super.key,
  });

  final String location;
  final B2bApi? api;
  final WholesaleCommerceApi? commerceApi;

  @override
  State<WholesaleOrdersDesignScreen> createState() =>
      _WholesaleOrdersDesignScreenState();
}

class _WholesaleOrdersDesignScreenState
    extends State<WholesaleOrdersDesignScreen> with WidgetsBindingObserver {
  String status = 'all';
  final Set<int> _reordering = <int>{};
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

  void _goBack() {
    final navigator = Navigator.of(context);
    if (navigator.canPop()) {
      navigator.pop();
      return;
    }

    final commerceContext = CustomerCommerceContext.tryParseLocation(
      widget.location,
    );
    final dashboardRoute = commerceContext?.isWholesale == true
        ? Uri(
            path: CustomerRoutePaths.b2bDashboard,
            queryParameters: commerceContext!.toQueryParameters(),
          ).toString()
        : CustomerRoutePaths.b2bDashboard;
    navigator.pushReplacementNamed(dashboardRoute);
  }

  int _orderStoreId(Map<String, dynamic> order) {
    final nestedStore = order['store'];
    final fromRow = intValue(
      order['store_id'] ?? (nestedStore is Map ? nestedStore['id'] : null),
    );
    return fromRow > 0 ? fromRow : wholesaleStoreId(widget.location);
  }

  Future<double?> _currentReorderQuantity(
    int storeId,
    Map<String, dynamic> item,
  ) async {
    final productId = intValue(
      item['product_id'] ??
          (item['product'] is Map ? (item['product'] as Map)['id'] : null),
    );
    if (productId <= 0) return null;

    final requested = math.max(.001, doubleValue(item['quantity'], 1));
    final api = widget.api;
    if (api == null) return requested;

    final raw = await api.get(
      '/api/v1/b2b/products/' +
          productId.toString() +
          '?store_id=' +
          storeId.toString(),
    );
    if (raw is! Map) return null;

    final product = Map<String, dynamic>.from(raw);
    final isAvailable = _wholesaleProductIsAvailable(product);
    if (!isAvailable) return null;

    final minimum = math.max(
      .001,
      doubleValue(
        product['minimum_order_quantity'] ?? product['minimum_quantity'],
        1,
      ),
    );
    final increment = math.max(
      .001,
      doubleValue(product['ordering_increment'], 1),
    );
    final available = product['available_quantity'] == null
        ? null
        : doubleValue(product['available_quantity'], 0);

    var normalized = math.max(requested, minimum).toDouble();
    final steps = math.max(
      0,
      ((normalized - minimum) / increment - .000001).ceil(),
    );
    normalized = minimum + steps * increment;

    if (available != null && normalized > available + .0001) {
      if (available + .0001 < minimum) return null;
      final maxSteps = math.max(
        0,
        ((available - minimum) / increment + .000001).floor(),
      );
      normalized = minimum + maxSteps * increment;
    }

    return normalized > 0 ? normalized : null;
  }

  Future<bool> _confirmMergeExistingCart(int storeId) async {
    final commerceApi = widget.commerceApi;
    if (commerceApi == null) return true;

    final raw = await commerceApi.cart(storeId);
    final cart =
        raw is Map ? Map<String, dynamic>.from(raw) : <String, dynamic>{};
    if (mapRows(cart['items']).isEmpty) return true;
    if (!mounted) return false;

    final isArabic = Localizations.localeOf(context).languageCode == 'ar';
    return await showDialog<bool>(
          context: context,
          builder: (dialogContext) => AlertDialog(
            title: Text(context.tr('customer.orders.reorder')),
            content: Text(
              isArabic
                  ? 'السلة تحتوي على أصناف بالفعل. هل تريد دمج أصناف هذا الطلب معها؟ لن يتم حذف أي صنف موجود.'
                  : 'Your cart already has items. Merge this order into the current cart? Existing items will not be removed.',
            ),
            actions: [
              TextButton(
                onPressed: () => Navigator.of(dialogContext).pop(false),
                child: Text(
                  MaterialLocalizations.of(context).cancelButtonLabel,
                ),
              ),
              FilledButton(
                key: const ValueKey('b2b-reorder-merge-confirm'),
                onPressed: () => Navigator.of(dialogContext).pop(true),
                child: Text(
                  isArabic ? 'دمج وفتح السلة' : 'Merge and open cart',
                ),
              ),
            ],
          ),
        ) ??
        false;
  }

  Future<void> _reorder(Map<String, dynamic> summary) async {
    final orderId = intValue(summary['id']);
    final commerceApi = widget.commerceApi;
    if (orderId <= 0 || commerceApi == null || _reordering.contains(orderId)) {
      return;
    }

    setState(() => _reordering.add(orderId));
    try {
      final raw = await widget.api?.get(
        '/api/v1/b2b/orders/' + orderId.toString(),
      );
      final order = raw is Map
          ? Map<String, dynamic>.from(raw)
          : Map<String, dynamic>.from(summary);
      final storeId = _orderStoreId(order);
      final items = mapRows(order['items']);

      if (storeId <= 0 || items.isEmpty) {
        throw const WholesaleCommerceException(
          'Order items are not available for reorder.',
        );
      }

      final merge = await _confirmMergeExistingCart(storeId);
      if (!merge || !mounted) return;

      final failed = <String>[];
      final adjusted = <String>[];
      var added = 0;

      for (final item in items) {
        final productId = intValue(
          item['product_id'] ??
              (item['product'] is Map ? (item['product'] as Map)['id'] : null),
        );
        final label = item['name']?.toString().trim().isNotEmpty == true
            ? item['name'].toString().trim()
            : (item['name_snapshot']?.toString().trim().isNotEmpty == true
                ? item['name_snapshot'].toString().trim()
                : (item['sku']?.toString().trim().isNotEmpty == true
                    ? item['sku'].toString().trim()
                    : '#' + productId.toString()));

        try {
          final original = math.max(.001, doubleValue(item['quantity'], 1));
          final quantity = await _currentReorderQuantity(storeId, item);

          if (productId <= 0 || quantity == null) {
            failed.add(label);
            continue;
          }

          if ((quantity - original).abs() >= .0001) {
            adjusted.add(
              label +
                  ': ' +
                  compactNumber(original) +
                  ' → ' +
                  compactNumber(quantity),
            );
          }

          await commerceApi.addItem(storeId, productId, quantity);
          added++;
        } catch (_) {
          failed.add(label);
        }
      }

      if (!mounted) return;

      if (added == 0) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
              failed.isEmpty
                  ? context.tr('customer.orders.reorder_failed')
                  : context.tr('customer.orders.reorder_failed') +
                      ': ' +
                      failed.join(', '),
            ),
          ),
        );
        return;
      }

      if (failed.isNotEmpty || adjusted.isNotEmpty) {
        final isArabic = Localizations.localeOf(context).languageCode == 'ar';
        await showDialog<void>(
          context: context,
          builder: (dialogContext) => AlertDialog(
            title: Text(
              isArabic
                  ? 'تم تجهيز السلة بالشروط الحالية'
                  : 'Cart rebuilt with current rules',
            ),
            content: SingleChildScrollView(
              child: Text(
                [
                  if (adjusted.isNotEmpty)
                    (isArabic ? 'تم تعديل الكمية:\n' : 'Quantity adjusted:\n') +
                        adjusted.join('\n'),
                  if (failed.isNotEmpty)
                    (isArabic ? 'تعذر إضافة:\n' : 'Could not add:\n') +
                        failed.join('\n'),
                  if (isArabic)
                    'راجع السلة ويمكنك تعديل الكميات أو حذف وإضافة أصناف قبل إتمام الطلب.'
                  else
                    'Review the cart. You can edit quantities, remove items, or add products before checkout.',
                ].join('\n\n'),
              ),
            ),
            actions: [
              FilledButton(
                onPressed: () => Navigator.of(dialogContext).pop(),
                child: Text(isArabic ? 'مراجعة السلة' : 'Review cart'),
              ),
            ],
          ),
        );
      }

      if (!mounted) return;
      Navigator.of(
        context,
      ).pushNamed('/b2b/cart?channel=wholesale&store_id=' + storeId.toString());
    } catch (error) {
      if (mounted) {
        await showOperationalError(context, error);
      }
    } finally {
      if (mounted) {
        setState(() => _reordering.remove(orderId));
      }
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
                                message:
                                    context.tr('customer.error.action_failed'),
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
                              itemBuilder: (_, index) {
                                final row = rows[index];
                                final orderId = intValue(row['id']);
                                return _OrderCard(
                                  row: row,
                                  compact: compact,
                                  reordering: _reordering.contains(orderId),
                                  onReorder:
                                      widget.commerceApi != null && orderId > 0
                                          ? () => unawaited(_reorder(row))
                                          : null,
                                );
                              },
                            ),
                          );
                        }

                        return Column(
                          children: [
                            FoodexWholesaleHeader(
                              title: context.tr('b2b.app.title'),
                              palette: FoodexPalette.wholesale,
                              leading: IconButton(
                                key: const ValueKey('b2b-orders-back'),
                                tooltip: MaterialLocalizations.of(context)
                                    .backButtonTooltip,
                                onPressed: _goBack,
                                icon: const BackButtonIcon(),
                                color: Colors.white,
                              ),
                              searchHint: context.tr(
                                'customer.products.search_name_sku_barcode',
                              ),
                              trailingActions: [
                                IconButton(
                                  key: const ValueKey('b2b-orders-refresh'),
                                  tooltip: context.tr('b2b.dashboard.refresh'),
                                  onPressed: loading
                                      ? null
                                      : () => unawaited(_refresh()),
                                  icon: const Icon(Icons.refresh_rounded),
                                  color: Colors.white,
                                ),
                              ],
                              onSearchSubmitted: (value) {
                                final query = value.trim();
                                if (query.isEmpty) return;
                                final scopedStoreId =
                                    wholesaleStoreId(widget.location);
                                Navigator.of(context).pushNamed(
                                  Uri(
                                    path: CustomerRoutePaths.b2bProducts,
                                    queryParameters: <String, String>{
                                      if (scopedStoreId > 0)
                                        'store_id': scopedStoreId.toString(),
                                      'channel': 'wholesale',
                                      'q': query,
                                    },
                                  ).toString(),
                                );
                              },
                              onNotifications: () =>
                                  Navigator.of(context).pushNamed(
                                CustomerRoutePaths.b2bNotifications,
                              ),
                              onCart: () {
                                final scopedStoreId =
                                    wholesaleStoreId(widget.location);
                                Navigator.of(context).pushNamed(
                                  scopedStoreId > 0
                                      ? '/b2b/cart?store_id=' +
                                          scopedStoreId.toString()
                                      : CustomerRoutePaths.b2bCart,
                                );
                              },
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
      final raw = await widget.api!.get(
        '/api/v1/b2b/orders/' + orderId.toString(),
      );
      if (raw is Map) {
        final order = Map<String, dynamic>.from(raw);
        _lastOrder = order;
        return order;
      }
      return raw;
    } catch (_) {
      if (_lastOrder != null) {
        return <String, dynamic>{..._lastOrder!, '__stale': true};
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
          body: FoodexUnifiedHeaderSurface(
            title: context.tr('b2b.app.title'),
            location: widget.location,
            searchHint: context.tr(
              'customer.products.search_name_sku_barcode',
            ),
            leading: IconButton(
              key: const ValueKey('b2b-order-back'),
              tooltip: MaterialLocalizations.of(context).backButtonTooltip,
              onPressed: () =>
                  Navigator.of(context).pushReplacementNamed('/b2b/orders'),
              icon: const BackButtonIcon(),
              color: Colors.white,
            ),
            child: Column(
              children: [
                Padding(
                  padding: const EdgeInsets.fromLTRB(22, 8, 22, 0),
                  child: Align(
                    alignment: AlignmentDirectional.centerStart,
                    child: Text(
                      context.tr('b2b.order.title'),
                      key: const ValueKey('b2b-order-content-title'),
                      style: const TextStyle(
                        color: CustomerUiColors.ink,
                        fontSize: 17,
                        fontWeight: FontWeight.w900,
                      ),
                    ),
                  ),
                ),
                Align(
                  alignment: AlignmentDirectional.centerEnd,
                  child: IconButton(
                    key: const ValueKey('b2b-order-refresh'),
                    tooltip: context.tr('b2b.order.refresh'),
                    onPressed: _refresh,
                    icon: const Icon(Icons.refresh_rounded),
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
                      final currency = order['currency']?.toString() ?? 'KWD';
                      final total = order['grand_total'] ?? order['total'];
                      final status = order['status']?.toString() ?? '';
                      final isTerminal = order['is_terminal'] == true ||
                          const {
                            'delivered',
                            'failed',
                            'cancelled',
                          }.contains(status);
                      final storeName = order['store_name']?.toString() ??
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
                            title: order['display_order_number']?.toString() ??
                                '#' + orderId.toString(),
                            rows: [
                              (
                                context.tr('b2b.order.status'),
                                _orderStatusText(context, status),
                              ),
                              if (payment != null)
                                (
                                  context.tr('b2b.order.payment_status'),
                                  _paymentStatusText(
                                    context,
                                    payment['status']?.toString() ?? '',
                                  ),
                                ),
                              (
                                context.tr('b2b.order.total'),
                                money(total, currency: currency),
                              ),
                              (
                                context.tr('b2b.order.created_at'),
                                _friendlyOrderDate(
                                    order['created_at']?.toString()),
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
                                  label: Text(context.tr('b2b.order.open_map')),
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

                                return _B2bOrderTimelineEntry(
                                  key: ValueKey(
                                    'b2b-timeline-' +
                                        index.toString() +
                                        '-' +
                                        stage,
                                  ),
                                  stage: stage,
                                  occurredAt: occurredAt,
                                  vanCode: vanCode,
                                  reasonCode: reasonCode,
                                  isLast: index == timeline.length - 1,
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
                                  item: item, currency: currency),
                            ),
                          ],
                          const SizedBox(height: 2),
                          _OrderDetailCard(
                            title: context.tr('b2b.order.price_summary'),
                            rows: [
                              (
                                context.tr('b2b.order.subtotal'),
                                money(order['subtotal'], currency: currency),
                              ),
                              (
                                context.tr('b2b.order.discount'),
                                money(order['discount_total'],
                                    currency: currency),
                              ),
                              (
                                context.tr('b2b.order.delivery_fee'),
                                money(order['delivery_total'],
                                    currency: currency),
                              ),
                              (
                                context.tr('b2b.order.payment_method'),
                                _paymentMethodText(context, paymentMethod),
                              ),
                              if (accountCredit != null)
                                (
                                  context.tr('b2b.order.account_credit_impact'),
                                  money(
                                    accountCredit['amount'],
                                    currency:
                                        accountCredit['currency']?.toString() ??
                                            currency,
                                  ),
                                ),
                              (
                                context.tr('b2b.order.grand_total'),
                                money(total, currency: currency),
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
                                  invoice['display_invoice_number']
                                          ?.toString() ??
                                      (invoice['id'] == null
                                          ? '-'
                                          : '#${invoice['id']}'),
                                ),
                                (
                                  context.tr('b2b.invoice.payment_status'),
                                  _invoiceStatusText(
                                    context,
                                    invoice['status']?.toString() ?? '',
                                  ),
                                ),
                              ],
                            ),
                            Align(
                              alignment: AlignmentDirectional.centerStart,
                              child: FilledButton.icon(
                                key: const ValueKey('b2b-order-open-invoice'),
                                onPressed: () {
                                  final invoiceId = int.tryParse(
                                    invoice['id'].toString(),
                                  );
                                  if (invoiceId == null || invoiceId <= 0) {
                                    return;
                                  }
                                  Navigator.of(context).pushNamed(
                                    '/b2b/invoices/' + invoiceId.toString(),
                                  );
                                },
                                icon: const Icon(Icons.receipt_long_outlined),
                                label:
                                    Text(context.tr('b2b.order.open_invoice')),
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
      return method.isEmpty || method == '-'
          ? '-'
          : method.replaceAll('_', ' ');
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

String _invoiceStatusText(BuildContext context, String status) {
  switch (status.trim().toLowerCase()) {
    case 'paid':
    case 'settled':
      return context.tr('b2b.invoice.status.settled');
    case 'partially_paid':
      return context.tr('b2b.invoice.status.partially_settled');
    case 'overdue':
      return context.tr('b2b.invoice.status.overdue');
    case 'cancelled':
    case 'canceled':
    case 'void':
    case 'voided':
      return context.tr('b2b.invoice.status.cancelled');
    case 'credited':
      return context.tr('b2b.invoice.status.credited');
    default:
      return context.tr('b2b.invoice.status.due');
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
                      userAgentPackageName: 'com.fiftysolution.foodex.customer',
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
                            color: CustomerUiColors.wholesaleBrandToneGreen,
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
  const _OrderNotice({required this.icon, required this.text, super.key});

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
            Icon(icon, color: CustomerUiColors.wholesaleBrandToneGreen),
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
  const _OrderLineCard({required this.item, required this.currency});

  final Map<String, dynamic> item;
  final String currency;

  @override
  Widget build(BuildContext context) {
    final imageUrl = item['image_url']?.toString() ?? '';
    final quantity = item['quantity']?.toString() ?? '-';
    final total = money(item['line_total'], currency: currency);

    return Container(
      key: ValueKey('b2b-order-line-${item['id'] ?? item['product_id']}'),
      margin: const EdgeInsets.only(bottom: 8),
      padding: const EdgeInsets.all(10),
      decoration: BoxDecoration(
        color: Colors.white,
        border: Border.all(color: const Color(0xFFE9E3F0)),
        borderRadius: BorderRadius.circular(15),
      ),
      child: Row(
        children: [
          ClipRRect(
            borderRadius: BorderRadius.circular(10),
            child: SizedBox(
              width: 54,
              height: 54,
              child: imageUrl.isEmpty
                  ? const ColoredBox(
                      color: CustomerUiColors.mint,
                      child: Icon(Icons.inventory_2_outlined),
                    )
                  : Image.network(
                      imageUrl,
                      fit: BoxFit.cover,
                      errorBuilder: (_, __, ___) => const ColoredBox(
                        color: CustomerUiColors.mint,
                        child: Icon(Icons.inventory_2_outlined),
                      ),
                    ),
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              '${context.tr('b2b.order.quantity')}: $quantity',
              style: const TextStyle(
                color: CustomerUiColors.wholesaleSecondaryText,
                fontSize: 12,
                fontWeight: FontWeight.w700,
              ),
            ),
          ),
          const SizedBox(width: 8),
          Text(
            total,
            style: const TextStyle(
              color: CustomerUiColors.ink,
              fontSize: 13,
              fontWeight: FontWeight.w900,
            ),
          ),
        ],
      ),
    );
  }
}

({IconData icon, Color foreground, Color background, Color line})
    _orderTimelineVisual(String stage) {
  switch (stage) {
    case 'placed':
    case 'pending':
      return (
        icon: Icons.receipt_long_outlined,
        foreground: const Color(0xFF2563EB),
        background: const Color(0xFFEFF6FF),
        line: const Color(0xFFBFDBFE),
      );
    case 'confirmed':
      return (
        icon: Icons.verified_rounded,
        foreground: const Color(0xFF0F766E),
        background: const Color(0xFFECFDF5),
        line: const Color(0xFFA7F3D0),
      );
    case 'accepted':
      return (
        icon: Icons.thumb_up_alt_rounded,
        foreground: const Color(0xFF15803D),
        background: const Color(0xFFF0FDF4),
        line: const Color(0xFFBBF7D0),
      );
    case 'preparing':
      return (
        icon: Icons.inventory_2_outlined,
        foreground: const Color(0xFFD97706),
        background: const Color(0xFFFFF7ED),
        line: const Color(0xFFFED7AA),
      );
    case 'ready':
      return (
        icon: Icons.task_alt_rounded,
        foreground: const Color(0xFF7C3AED),
        background: const Color(0xFFF5F3FF),
        line: const Color(0xFFDDD6FE),
      );
    case 'van_assigned':
    case 'assigned':
      return (
        icon: Icons.local_shipping_outlined,
        foreground: const Color(0xFF4F46E5),
        background: const Color(0xFFEEF2FF),
        line: const Color(0xFFC7D2FE),
      );
    case 'awaiting_dispatch':
      return (
        icon: Icons.schedule_rounded,
        foreground: const Color(0xFFB45309),
        background: const Color(0xFFFFFBEB),
        line: const Color(0xFFFDE68A),
      );
    case 'picked_up':
      return (
        icon: Icons.move_to_inbox_rounded,
        foreground: const Color(0xFF0369A1),
        background: const Color(0xFFF0F9FF),
        line: const Color(0xFFBAE6FD),
      );
    case 'out_for_delivery':
      return (
        icon: Icons.delivery_dining_rounded,
        foreground: const Color(0xFF0284C7),
        background: const Color(0xFFE0F2FE),
        line: const Color(0xFF7DD3FC),
      );
    case 'delivered':
      return (
        icon: Icons.home_rounded,
        foreground: const Color(0xFF15803D),
        background: const Color(0xFFDCFCE7),
        line: const Color(0xFF86EFAC),
      );
    case 'failed':
      return (
        icon: Icons.error_rounded,
        foreground: const Color(0xFFDC2626),
        background: const Color(0xFFFEE2E2),
        line: const Color(0xFFFCA5A5),
      );
    case 'cancelled':
      return (
        icon: Icons.cancel_rounded,
        foreground: const Color(0xFFB91C1C),
        background: const Color(0xFFFEF2F2),
        line: const Color(0xFFFECACA),
      );
    default:
      return (
        icon: Icons.help_outline_rounded,
        foreground: const Color(0xFF64748B),
        background: const Color(0xFFF1F5F9),
        line: const Color(0xFFCBD5E1),
      );
  }
}

class _B2bOrderTimelineEntry extends StatelessWidget {
  const _B2bOrderTimelineEntry({
    required this.stage,
    required this.occurredAt,
    required this.vanCode,
    required this.reasonCode,
    required this.isLast,
    super.key,
  });

  final String stage;
  final String occurredAt;
  final String vanCode;
  final String reasonCode;
  final bool isLast;

  @override
  Widget build(BuildContext context) {
    final visual = _orderTimelineVisual(stage);

    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SizedBox(
            width: 36,
            child: Column(
              children: [
                Container(
                  key: ValueKey('b2b-timeline-icon-$stage'),
                  width: 30,
                  height: 30,
                  decoration: BoxDecoration(
                    color: visual.background,
                    shape: BoxShape.circle,
                    border: Border.all(
                      color: visual.foreground.withValues(alpha: .75),
                      width: 1.6,
                    ),
                    boxShadow: [
                      BoxShadow(
                        color: visual.foreground.withValues(alpha: .12),
                        blurRadius: 7,
                        offset: const Offset(0, 2),
                      ),
                    ],
                  ),
                  child: Icon(
                    visual.icon,
                    size: 16,
                    color: visual.foreground,
                  ),
                ),
                if (!isLast)
                  Expanded(
                    child: Container(
                      width: 2,
                      margin: const EdgeInsets.symmetric(vertical: 3),
                      color: visual.line,
                    ),
                  ),
              ],
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Padding(
              padding: EdgeInsets.only(bottom: isLast ? 0 : 14),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    _orderStatusText(context, stage),
                    style: TextStyle(
                      fontSize: 13,
                      fontWeight: FontWeight.w900,
                      color: visual.foreground,
                    ),
                  ),
                  if (occurredAt.isNotEmpty) ...[
                    const SizedBox(height: 2),
                    Text(
                      _friendlyOrderDate(occurredAt),
                      style: const TextStyle(
                        fontSize: 11,
                        color: CustomerUiColors.wholesaleSecondaryText,
                      ),
                    ),
                  ],
                  if (vanCode.isNotEmpty) ...[
                    const SizedBox(height: 2),
                    Text(
                      vanCode,
                      style: const TextStyle(
                        fontSize: 11,
                        color: CustomerUiColors.wholesaleSecondaryText,
                      ),
                    ),
                  ],
                  if (reasonCode.isNotEmpty) ...[
                    const SizedBox(height: 2),
                    Text(
                      _failureReasonText(context, reasonCode),
                      style: TextStyle(
                        fontSize: 11,
                        fontWeight: FontWeight.w700,
                        color: stage == 'failed' || stage == 'cancelled'
                            ? visual.foreground
                            : CustomerUiColors.wholesaleSecondaryText,
                      ),
                    ),
                  ],
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _OrderDetailCard extends StatelessWidget {
  const _OrderDetailCard({required this.title, required this.rows});

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
            Text(title, style: const TextStyle(fontWeight: FontWeight.w800)),
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
                          color: CustomerUiColors.wholesaleSecondaryText,
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
              if (index != values.length - 1) SizedBox(width: compact ? 6 : 8),
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
            constraints: BoxConstraints(minWidth: compact ? 58 : 64),
            padding: EdgeInsets.symmetric(horizontal: compact ? 10 : 14),
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
    required this.reordering,
    this.onReorder,
  });

  final Map<String, dynamic> row;
  final bool compact;
  final bool reordering;
  final VoidCallback? onReorder;

  @override
  Widget build(BuildContext context) {
    final palette = FoodexPalette.wholesale;
    final id = intValue(row['id']);
    final currency = (row['currency']?.toString().trim().isNotEmpty == true
            ? row['currency'].toString().trim()
            : 'EGP')
        .toUpperCase();
    final total = row['grand_total'] ?? row['total'];
    final totalLabel = _compactOrderAmount(total, currency);
    final title =
        row['display_order_number']?.toString().trim().isNotEmpty == true
            ? row['display_order_number'].toString().trim()
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
            constraints: BoxConstraints(minHeight: compact ? 86 : 94),
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
                color: CustomerUiColors.border.withValues(alpha: .72),
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
                          style:
                              Theme.of(context).textTheme.titleMedium?.copyWith(
                                    color: CustomerUiColors.ink,
                                    fontSize: compact ? 12.8 : 14,
                                    fontWeight: FontWeight.w900,
                                    height: 1.08,
                                  ),
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
                        style:
                            Theme.of(context).textTheme.titleMedium?.copyWith(
                                  color: palette.primary,
                                  fontSize: compact ? 12.8 : 14.5,
                                  fontWeight: FontWeight.w900,
                                  height: 1.05,
                                ),
                      ),
                    ],
                  ),
                ),
                SizedBox(width: compact ? 5 : 8),
                Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.center,
                  children: [
                    _StatusPill(
                      status: row['status']?.toString() ?? '',
                      compact: compact,
                    ),
                    if (onReorder != null) ...[
                      SizedBox(height: compact ? 5 : 7),
                      TextButton(
                        key: ValueKey(
                          'b2b-order-reorder-' + id.toString(),
                        ),
                        onPressed: reordering ? null : onReorder,
                        style: TextButton.styleFrom(
                          minimumSize: Size.zero,
                          padding: EdgeInsets.symmetric(
                            horizontal: compact ? 8 : 10,
                            vertical: compact ? 4 : 5,
                          ),
                          tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                          foregroundColor: CustomerUiColors.deepGreenStrong,
                          backgroundColor: CustomerUiColors.limeSoft,
                          shape: RoundedRectangleBorder(
                            borderRadius: BorderRadius.circular(12),
                          ),
                        ),
                        child: reordering
                            ? SizedBox.square(
                                dimension: compact ? 14 : 16,
                                child: const FoodexWaveLoading(),
                              )
                            : Text(
                                context.tr('customer.orders.reorder'),
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: TextStyle(
                                  fontSize: compact ? 9.5 : 10.5,
                                  fontWeight: FontWeight.w800,
                                  height: 1,
                                ),
                              ),
                      ),
                    ],
                  ],
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
  const _StatusPill({required this.status, required this.compact});

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
        color: foreground.withValues(alpha: .10),
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
  final number = value is num
      ? value.toDouble()
      : double.tryParse(value?.toString() ?? '');
  if (number == null) return currency + ' —';

  final code = currency.trim().toUpperCase();
  if (code == 'EGP') {
    return foodexMoneyText(number, code, currencyFirst: true);
  }

  final amount = foodexCompactDecimal(number);
  return code.isEmpty ? amount : '$code $amount';
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
