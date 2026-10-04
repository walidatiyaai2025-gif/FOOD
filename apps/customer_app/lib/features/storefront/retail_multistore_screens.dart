// ignore_for_file: prefer_interpolation_to_compose_strings, deprecated_member_use

import 'package:flutter/material.dart';

import '../../core/api/b2c_account_api.dart';
import '../../core/api/b2c_catalog_api.dart';
import '../../core/api/customer_action_api.dart';
import '../../core/api/storefront_api.dart';
import '../../core/auth/customer_session.dart';
import '../../core/engagement/live_ad_service.dart';
import '../../core/engagement/notification_campaign_popup_service.dart';
import '../../shared/customer_favorite_button.dart';
import 'storefront_design_system.dart';

typedef WholesaleContextCallback = void Function(int? retailStoreId);

class StoreSelectionDesignScreen extends StatefulWidget {
  const StoreSelectionDesignScreen({
    required this.catalogApi,
    required this.accountApi,
    required this.storefrontApi,
    required this.session,
    required this.enterWholesale,
    super.key,
  });

  final B2cCatalogApi catalogApi;
  final B2cAccountApi accountApi;
  final StorefrontApi? storefrontApi;
  final CustomerSession session;
  final WholesaleContextCallback enterWholesale;

  @override
  State<StoreSelectionDesignScreen> createState() =>
      _StoreSelectionDesignScreenState();
}

class _StoreSelectionDesignScreenState
    extends State<StoreSelectionDesignScreen> {
  late Future<Map<String, dynamic>> future = _load();
  String filter = 'all';

  Future<Map<String, dynamic>> _load() async {
    if (widget.storefrontApi != null) {
      String? countryCode;
      String? city;
      String? area;

      if (widget.session.isAuthenticated) {
        try {
          final rawAddresses = await widget.accountApi.addresses();
          final addresses = rawAddresses is Map
              ? mapRows(rawAddresses['data'])
              : mapRows(rawAddresses);
          if (addresses.isNotEmpty) {
            final selected = addresses.cast<Map<String, dynamic>>().firstWhere(
                  (address) => address['is_default'] == true ||
                      address['is_default'] == 1,
                  orElse: () => addresses.first,
                );
            countryCode = selected['country_code']?.toString();
            city = selected['city']?.toString();
            area = selected['area']?.toString();
          }
        } catch (_) {
          // Store discovery remains available when no saved address exists.
        }
      }

      return widget.storefrontApi!.selection(
        countryCode: countryCode,
        city: city,
        area: area,
      );
    }
    final stores = await widget.catalogApi.stores();
    return {
      'retail_stores': stores
          .map(
            (store) => {
              'id': store.id,
              'name': store.name,
              'code': store.code,
              'theme_code': store.themeCode,
              'address': store.address,
              'logo_url': store.logoUrl,
            },
          )
          .toList(growable: false),
      'wholesale_stores': const <Object>[],
    };
  }

  Future<int?> _chooseRetailContext(
    BuildContext context,
    List<int> contexts,
    List<Map<String, dynamic>> retailStores,
  ) async {
    if (contexts.isEmpty) return null;
    if (contexts.length == 1) return contexts.first;

    final names = <int, String>{
      for (final store in retailStores)
        intValue(store['id']): store['name']?.toString() ?? 'متجر التجزئة',
    };

    return showModalBottomSheet<int>(
      context: context,
      showDragHandle: true,
      builder: (sheetContext) => Directionality(
        textDirection: Directionality.of(context),
        child: SafeArea(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text(
                  'اختر متجر التجزئة المستلم',
                  style: TextStyle(
                    fontSize: 17,
                    fontWeight: FontWeight.w800,
                  ),
                ),
                const SizedBox(height: 6),
                const Text(
                  'سيتم ربط طلب الجملة ومخزون الاستلام بهذا المتجر.',
                  style: TextStyle(
                    fontSize: 12,
                    color: Color(0xFF6B7785),
                  ),
                ),
                const SizedBox(height: 10),
                ...contexts.map(
                  (storeId) => ListTile(
                    leading: const Icon(Icons.storefront_outlined),
                    title: Text(
                      names[storeId] ?? 'متجر #' + storeId.toString(),
                    ),
                    trailing:
                        const Icon(Icons.arrow_back_ios_new_rounded, size: 16),
                    onTap: () =>
                        Navigator.of(sheetContext).pop(storeId),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) => Directionality(
        textDirection: Directionality.of(context),
        child: Scaffold(
          backgroundColor: const Color(0xFFFAFBFA),
          body: SafeArea(
            child: FutureBuilder<Map<String, dynamic>>(
              future: future,
              builder: (context, snapshot) {
                if (snapshot.connectionState != ConnectionState.done) {
                  return const FoodexLoading(
                    key: ValueKey('store-selector-loading'),
                  );
                }
                if (snapshot.hasError) {
                  return FoodexErrorState(
                    message: 'تعذر تحميل المتاجر المتاحة.',
                    onRetry: () => setState(() => future = _load()),
                  );
                }

                final data = snapshot.data ?? const <String, dynamic>{};
                final retail = mapRows(data['retail_stores']);
                final wholesale = mapRows(data['wholesale_stores']);
                final visibleRetail =
                    filter == 'wholesale' ? const <Map<String, dynamic>>[] : retail;
                final visibleWholesale =
                    filter == 'retail' ? const <Map<String, dynamic>>[] : wholesale;

                return LayoutBuilder(
                  builder: (context, constraints) {
                    final side =
                        (constraints.maxWidth * .09).clamp(14.0, 38.0).toDouble();
                    return ListView(
                      padding: EdgeInsets.fromLTRB(side, 20, side, 30),
                      children: [
                        const _BrandMark(),
                        const SizedBox(height: 24),
                        const Text(
                          'اختر المتجر',
                          textAlign: TextAlign.center,
                          style: TextStyle(
                            fontSize: 26,
                            fontWeight: FontWeight.w800,
                            color: Color(0xFF102033),
                          ),
                        ),
                        const SizedBox(height: 8),
                        const Text(
                          'المتاجر المتاحة حسب موقع الخدمة',
                          textAlign: TextAlign.center,
                          style: TextStyle(
                            fontSize: 14,
                            color: Color(0xFF6B7785),
                          ),
                        ),
                        const SizedBox(height: 22),
                        _StoreFilter(
                          selected: filter,
                          wholesaleVisible: wholesale.isNotEmpty,
                          onChanged: (value) => setState(() => filter = value),
                        ),
                        const SizedBox(height: 18),
                        ...visibleRetail.map(
                          (store) => Padding(
                            padding: const EdgeInsets.only(bottom: 14),
                            child: _RetailStoreCard(
                              store: store,
                              onTap: () {
                                final id = intValue(store['id']);
                                Navigator.of(context)
                                    .pushNamed('/retail/$id/home');
                              },
                            ),
                          ),
                        ),
                        ...visibleWholesale.map(
                          (store) => Padding(
                            padding: const EdgeInsets.only(bottom: 14),
                            child: _WholesaleStoreCard(
                              store: store,
                              onTap: () async {
                                final storeId = intValue(store['id']);
                                final contexts = (store['retail_context_ids']
                                            as List? ??
                                        const <Object>[])
                                    .map(intValue)
                                    .where((id) => id > 0)
                                    .toList(growable: false);
                                final retailContext =
                                    await _chooseRetailContext(
                                  context,
                                  contexts,
                                  retail,
                                );
                                if (contexts.isNotEmpty &&
                                    retailContext == null) {
                                  return;
                                }

                                widget.enterWholesale(retailContext);
                                var route =
                                    '/b2b/home?store_id=' + storeId.toString();
                                if (retailContext != null) {
                                  route += '&retail_store_id=' +
                                      retailContext.toString();
                                }
                                WidgetsBinding.instance
                                    .addPostFrameCallback((_) {
                                  if (context.mounted) {
                                    Navigator.of(context).pushNamed(route);
                                  }
                                });
                              },
                            ),
                          ),
                        ),
                        if (retail.isEmpty && wholesale.isEmpty)
                          const FoodexEmptyState(
                            title: 'لا توجد متاجر متاحة',
                            subtitle: 'لا توجد متاجر ضمن نطاق الخدمة الحالي.',
                          ),
                      ],
                    );
                  },
                );
              },
            ),
          ),
        ),
      );
}

class _BrandMark extends StatelessWidget {
  const _BrandMark();

  @override
  Widget build(BuildContext context) => Center(
        child: Image.asset(
          'assets/branding/foodex-economical-group.webp',
          height: 72,
          fit: BoxFit.contain,
          semanticLabel: 'FOODEX Economical Group',
        ),
      );
}

class _StoreFilter extends StatelessWidget {
  const _StoreFilter({
    required this.selected,
    required this.wholesaleVisible,
    required this.onChanged,
  });

  final String selected;
  final bool wholesaleVisible;
  final ValueChanged<String> onChanged;

  @override
  Widget build(BuildContext context) {
    final values = <(String, String)>[
      ('all', 'الكل'),
      ('retail', 'تجزئة'),
      if (wholesaleVisible) ('wholesale', 'جملة'),
    ];
    return Container(
      height: 46,
      padding: const EdgeInsets.all(4),
      decoration: BoxDecoration(
        color: const Color(0xFFF0F3F1),
        borderRadius: BorderRadius.circular(999),
      ),
      child: Row(
        children: values
            .map(
              (item) => Expanded(
                child: InkWell(
                  onTap: () => onChanged(item.$1),
                  borderRadius: BorderRadius.circular(999),
                  child: AnimatedContainer(
                    duration: const Duration(milliseconds: 150),
                    alignment: Alignment.center,
                    decoration: BoxDecoration(
                      color: selected == item.$1
                          ? Colors.white
                          : Colors.transparent,
                      borderRadius: BorderRadius.circular(999),
                    ),
                    child: Text(
                      item.$2,
                      style: TextStyle(
                        fontWeight: FontWeight.w700,
                        color: selected == item.$1
                            ? const Color(0xFF102033)
                            : const Color(0xFF6B7785),
                      ),
                    ),
                  ),
                ),
              ),
            )
            .toList(growable: false),
      ),
    );
  }
}

class _RetailStoreCard extends StatelessWidget {
  const _RetailStoreCard({
    required this.store,
    required this.onTap,
  });

  final Map<String, dynamic> store;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final pharmacy = store['theme_code'] == 'retail_pharmacy';
    final palette =
        pharmacy ? FoodexPalette.pharmacy : FoodexPalette.grocery;
    final id = intValue(store['id']);

    return Material(
      color: palette.soft,
      borderRadius: BorderRadius.circular(22),
      child: InkWell(
        key: ValueKey('b2c-store-$id'),
        borderRadius: BorderRadius.circular(22),
        onTap: onTap,
        child: Container(
          constraints: const BoxConstraints(minHeight: 112),
          padding: const EdgeInsets.all(18),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(22),
            border: Border.all(color: palette.primary.withOpacity(.14)),
          ),
          child: Row(
            children: [
              _StoreLogo(
                url: store['logo_url']?.toString(),
                palette: palette,
                pharmacy: pharmacy,
              ),
              const SizedBox(width: 16),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      store['name']?.toString() ?? '',
                      style: TextStyle(
                        color: palette.text,
                        fontSize: 18,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                    const SizedBox(height: 6),
                    Text(
                      store['address']?.toString().trim().isNotEmpty == true
                          ? store['address'].toString()
                          : pharmacy
                              ? 'صيدلية · توصيل سريع'
                              : 'بقالة وسوبرماركت · توصيل سريع',
                      style: TextStyle(
                        color: palette.muted,
                        fontSize: 12,
                      ),
                    ),
                    const SizedBox(height: 8),
                    Text(
                      'تسوق الآن',
                      style: TextStyle(
                        color: palette.primary,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                  ],
                ),
              ),
              Icon(
                Icons.arrow_back_ios_new_rounded,
                color: palette.primary,
                size: 18,
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _StoreLogo extends StatelessWidget {
  const _StoreLogo({
    required this.url,
    required this.palette,
    required this.pharmacy,
  });

  final String? url;
  final FoodexPalette palette;
  final bool pharmacy;

  @override
  Widget build(BuildContext context) => Container(
        width: 66,
        height: 66,
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(18),
        ),
        clipBehavior: Clip.antiAlias,
        child: url != null && url!.isNotEmpty
            ? Image.network(
                url!,
                fit: BoxFit.cover,
                errorBuilder: (_, __, ___) => Icon(
                  pharmacy
                      ? Icons.local_pharmacy_rounded
                      : Icons.storefront_rounded,
                  color: palette.primary,
                  size: 34,
                ),
              )
            : Icon(
                pharmacy
                    ? Icons.local_pharmacy_rounded
                    : Icons.storefront_rounded,
                color: palette.primary,
                size: 34,
              ),
      );
}

class _WholesaleStoreCard extends StatelessWidget {
  const _WholesaleStoreCard({
    required this.store,
    required this.onTap,
  });

  final Map<String, dynamic> store;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => Material(
        color: const Color(0xFFFFF6ED),
        borderRadius: BorderRadius.circular(22),
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(22),
          child: Container(
            constraints: const BoxConstraints(minHeight: 124),
            padding: const EdgeInsets.all(18),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(22),
              border: Border.all(color: const Color(0xFFE8D8F7)),
            ),
            child: const Row(
              children: [
                SizedBox(
                  width: 66,
                  height: 66,
                  child: DecoratedBox(
                    decoration: BoxDecoration(
                      color: Colors.white,
                      borderRadius: BorderRadius.all(Radius.circular(18)),
                    ),
                    child: Icon(
                      Icons.warehouse_rounded,
                      color: Color(0xFF5D2A91),
                      size: 34,
                    ),
                  ),
                ),
                SizedBox(width: 16),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      _B2bBadge(),
                      SizedBox(height: 7),
                      Text(
                        'متجر الجملة',
                        style: TextStyle(
                          fontSize: 18,
                          fontWeight: FontWeight.w800,
                        ),
                      ),
                      SizedBox(height: 5),
                      Text(
                        'للمسؤولين والمعتمدين فقط',
                        style: TextStyle(
                          color: Color(0xFF6F6A7D),
                          fontSize: 12,
                        ),
                      ),
                    ],
                  ),
                ),
                Icon(
                  Icons.arrow_back_ios_new_rounded,
                  color: Color(0xFF5D2A91),
                  size: 18,
                ),
              ],
            ),
          ),
        ),
      );
}

class _B2bBadge extends StatelessWidget {
  const _B2bBadge();

  @override
  Widget build(BuildContext context) => Align(
        alignment: AlignmentDirectional.centerStart,
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 4),
          decoration: BoxDecoration(
            color: Color(0xFF5D2A91),
            borderRadius: BorderRadius.circular(999),
          ),
          child: const Text(
            'B2B',
            style: TextStyle(
              color: Colors.white,
              fontSize: 10,
              fontWeight: FontWeight.w900,
            ),
          ),
        ),
      );
}

class RetailStorefrontDesignScreen extends StatefulWidget {
  const RetailStorefrontDesignScreen({
    required this.location,
    required this.session,
    required this.catalogApi,
    required this.storefrontApi,
    required this.actionApi,
    super.key,
  });

  final String location;
  final CustomerSession session;
  final B2cCatalogApi catalogApi;
  final StorefrontApi? storefrontApi;
  final CustomerActionApi actionApi;

  @override
  State<RetailStorefrontDesignScreen> createState() =>
      _RetailStorefrontDesignScreenState();
}

class _RetailStorefrontDesignScreenState
    extends State<RetailStorefrontDesignScreen> {
  late final int storeId = retailStoreId(widget.location);
  late Future<_RetailHomeData> future = _load();
  final search = TextEditingController();
  final campaignPopups = CustomerNotificationCampaignPopupService();
  final liveAds = CustomerLiveAdService();
  bool _liveAdScheduled = false;

  Future<_RetailHomeData> _load() async {
    final values = await Future.wait<Object?>([
      widget.catalogApi.categories(storeId),
      widget.catalogApi.products(storeId),
      widget.catalogApi.offers(storeId),
      widget.catalogApi.banners(storeId),
      if (widget.storefrontApi != null)
        widget.storefrontApi!.retailHome(storeId)
      else
        Future<Map<String, dynamic>>.value(const <String, dynamic>{}),
    ]);
    return _RetailHomeData(
      categories: values[0] as List<B2cCategory>,
      products: values[1] as List<B2cProduct>,
      offers: values[2] as List<B2cOffer>,
      banners: values[3] as List<B2cBanner>,
      config: values[4] as Map<String, dynamic>,
    );
  }

  @override
  void dispose() {
    search.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => FutureBuilder<_RetailHomeData>(
        future: future,
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done) {
            return Directionality(
              textDirection: Directionality.of(context),
              child: Scaffold(body: FoodexLoading()),
            );
          }
          if (snapshot.hasError || !snapshot.hasData) {
            return Directionality(
              textDirection: Directionality.of(context),
              child: Scaffold(
                body: FoodexErrorState(
                  message: 'تعذر تحميل المتجر.',
                  onRetry: () => setState(() => future = _load()),
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
                    channel: 'b2c',
                    storeId: storeId,
                    accessToken: widget.session.accessToken,
                  )
                  .catchError((_) {});
              if (!context.mounted) return;
              await liveAds
                  .showForContext(
                    context,
                    channel: 'b2c',
                    storeId: storeId,
                  )
                  .catchError((_) {});
            });
          }

          final data = snapshot.data!;
          final theme = data.config['theme'] is Map
              ? Map<String, dynamic>.from(data.config['theme'] as Map)
              : <String, dynamic>{};
          final isPharmacy = theme['code'] == 'retail_pharmacy';
          final base = isPharmacy
              ? FoodexPalette.pharmacy
              : FoodexPalette.grocery;
          final palette = FoodexPalette(
            primary: parseHexColor(theme['primary'], base.primary),
            primaryDark: parseHexColor(
              theme['primary_dark'],
              base.primaryDark,
            ),
            accent: parseHexColor(theme['accent'], base.accent),
            background: parseHexColor(
              theme['background'],
              base.background,
            ),
            soft: base.soft,
            text: base.text,
            muted: base.muted,
          );

          return Directionality(
            textDirection: Directionality.of(context),
            child: Scaffold(
              backgroundColor: palette.background,
              body: SafeArea(
                child: _RetailHomeBody(
                  storeId: storeId,
                  data: data,
                  palette: palette,
                  search: search,
                  actionApi: widget.actionApi,
                  isPharmacy: isPharmacy,
                ),
              ),
              bottomNavigationBar: NavigationBar(
                selectedIndex: 0,
                indicatorColor: palette.soft,
                onDestinationSelected: (index) {
                  if (index == 1) {
                    Navigator.of(context)
                        .pushNamed('/categories?store=$storeId');
                  } else if (index == 2) {
                    Navigator.of(context).pushNamed('/cart?store=$storeId');
                  } else if (index == 3) {
                    Navigator.of(context).pushNamed('/profile');
                  }
                },
                destinations: const [
                  NavigationDestination(
                    icon: Icon(Icons.home_outlined),
                    selectedIcon: Icon(Icons.home_rounded),
                    label: 'الرئيسية',
                  ),
                  NavigationDestination(
                    icon: Icon(Icons.grid_view_rounded),
                    label: 'التصنيفات',
                  ),
                  NavigationDestination(
                    icon: Icon(Icons.shopping_cart_outlined),
                    label: 'السلة',
                  ),
                  NavigationDestination(
                    icon: Icon(Icons.person_outline_rounded),
                    label: 'حسابي',
                  ),
                ],
              ),
            ),
          );
        },
      );
}

class _RetailHomeData {
  const _RetailHomeData({
    required this.categories,
    required this.products,
    required this.offers,
    required this.banners,
    required this.config,
  });

  final List<B2cCategory> categories;
  final List<B2cProduct> products;
  final List<B2cOffer> offers;
  final List<B2cBanner> banners;
  final Map<String, dynamic> config;
}

class _RetailHomeBody extends StatelessWidget {
  const _RetailHomeBody({
    required this.storeId,
    required this.data,
    required this.palette,
    required this.search,
    required this.actionApi,
    required this.isPharmacy,
  });

  final int storeId;
  final _RetailHomeData data;
  final FoodexPalette palette;
  final TextEditingController search;
  final CustomerActionApi actionApi;
  final bool isPharmacy;

  @override
  Widget build(BuildContext context) {
    final store = data.config['store'] is Map
        ? Map<String, dynamic>.from(data.config['store'] as Map)
        : <String, dynamic>{};
    final branding = data.config['branding'] is Map
        ? Map<String, dynamic>.from(data.config['branding'] as Map)
        : <String, dynamic>{};
    final configuredSections = mapRows(data.config['sections']);
    final sections = configuredSections.isEmpty
        ? <Map<String, dynamic>>[
            {'type': 'hero'},
            {'type': 'categories', 'title_ar': 'التصنيفات'},
            {
              'type': 'products',
              'title_ar':
                  isPharmacy ? 'منتجات مميزة' : 'منتجات وصلت حديثًا',
            },
            if (data.offers.isNotEmpty)
              {'type': 'offers', 'title_ar': 'العروض'},
          ]
        : configuredSections;

    return ListView(
      padding: EdgeInsets.zero,
      children: [
        Container(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 18),
          decoration: BoxDecoration(
            color: palette.primaryDark,
            borderRadius:
                const BorderRadius.vertical(bottom: Radius.circular(28)),
          ),
          child: Column(
            children: [
              Row(
                children: [
                  CircleAvatar(
                    radius: 24,
                    backgroundColor: Colors.white,
                    child: Icon(
                      isPharmacy
                          ? Icons.local_pharmacy_rounded
                          : Icons.storefront_rounded,
                      color: palette.primary,
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          store['name']?.toString() ?? 'FOODEX',
                          style: const TextStyle(
                            color: Colors.white,
                            fontSize: 17,
                            fontWeight: FontWeight.w800,
                          ),
                        ),
                        const SizedBox(height: 3),
                        Text(
                          branding['address']?.toString() ??
                              'اختر عنوان التوصيل',
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(
                            color: Colors.white70,
                            fontSize: 11,
                          ),
                        ),
                      ],
                    ),
                  ),
                  _HeaderButton(
                    icon: Icons.notifications_none_rounded,
                    palette: palette,
                  ),
                  const SizedBox(width: 8),
                  _HeaderButton(
                    icon: Icons.shopping_cart_outlined,
                    palette: palette,
                    onTap: () => Navigator.of(context)
                        .pushNamed('/cart?store=$storeId'),
                  ),
                ],
              ),
              const SizedBox(height: 14),
              TextField(
                key: const ValueKey('b2c-home-search'),
                controller: search,
                textInputAction: TextInputAction.search,
                onSubmitted: (value) {
                  var route = '/products?store=' + storeId.toString();
                  if (value.trim().isNotEmpty) {
                    route += '&q=' + Uri.encodeQueryComponent(value.trim());
                  }
                  Navigator.of(context).pushNamed(route);
                },
                decoration: InputDecoration(
                  hintText: 'ابحث عن المنتجات',
                  prefixIcon:
                      Icon(Icons.search_rounded, color: palette.primary),
                  filled: true,
                  fillColor: Colors.white,
                  border: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(16),
                    borderSide: BorderSide.none,
                  ),
                ),
              ),
            ],
          ),
        ),
        ...sections.expand<Widget>((section) {
          final type = section['type']?.toString();
          if (type == 'hero') {
            final hero = data.config['hero'] is Map
                ? Map<String, dynamic>.from(data.config['hero'] as Map)
                : null;
            return [
              _HeroBanner(
                palette: palette,
                isPharmacy: isPharmacy,
                hero: hero,
                fallback: data.banners.isEmpty ? null : data.banners.first,
              ),
            ];
          }
          if (type == 'categories' || type == 'departments') {
            return [
              FoodexSectionHeader(
                title: section['title_ar']?.toString() ?? (type == 'departments' ? 'الأقسام' : 'التصنيفات'),
                palette: palette,
              ),
              _CategoryRail(
                storeId: storeId,
                categories: data.categories,
                palette: palette,
              ),
            ];
          }
          if (type == 'offers') {
            if (data.offers.isEmpty) return const <Widget>[];
            return [
              FoodexSectionHeader(
                title: section['title_ar']?.toString() ?? 'العروض',
                palette: palette,
              ),
              _OfferRail(offers: data.offers, palette: palette),
            ];
          }
          return [
            FoodexSectionHeader(
              title: section['title_ar']?.toString() ?? 'منتجات مميزة',
              palette: palette,
            ),
            _RetailProductGrid(
              storeId: storeId,
              products: data.products,
              palette: palette,
              actionApi: actionApi,
            ),
          ];
        }),
        const SizedBox(height: 20),
      ],
    );
  }
}

class _HeaderButton extends StatelessWidget {
  const _HeaderButton({
    required this.icon,
    required this.palette,
    this.onTap,
  });

  final IconData icon;
  final FoodexPalette palette;
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

class _HeroBanner extends StatelessWidget {
  const _HeroBanner({
    required this.palette,
    required this.isPharmacy,
    required this.hero,
    required this.fallback,
  });

  final FoodexPalette palette;
  final bool isPharmacy;
  final Map<String, dynamic>? hero;
  final B2cBanner? fallback;

  @override
  Widget build(BuildContext context) {
    final title = hero?['title']?.toString() ??
        fallback?.title ??
        (isPharmacy ? 'صحتك أولويتنا' : 'خضروات طازجة بأفضل الأسعار');
    final image = hero?['image_url']?.toString() ?? fallback?.imageUrl;

    return Padding(
      padding: const EdgeInsets.fromLTRB(14, 12, 14, 5),
      child: Container(
        height: 154,
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(22),
          gradient: LinearGradient(
            colors: [palette.primary, palette.primaryDark],
          ),
        ),
        clipBehavior: Clip.antiAlias,
        child: Stack(
          fit: StackFit.expand,
          children: [
            if (image != null && image.isNotEmpty)
              Opacity(
                opacity: .4,
                child: Image.network(
                  image,
                  fit: BoxFit.cover,
                  errorBuilder: (_, __, ___) => const SizedBox.shrink(),
                ),
              ),
            Padding(
              padding: const EdgeInsets.all(20),
              child: Align(
                alignment: AlignmentDirectional.centerStart,
                child: SizedBox(
                  width: 220,
                  child: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        title,
                        style: const TextStyle(
                          color: Colors.white,
                          fontSize: 22,
                          height: 1.2,
                          fontWeight: FontWeight.w900,
                        ),
                      ),
                      const SizedBox(height: 12),
                      Container(
                        padding: const EdgeInsets.symmetric(
                          horizontal: 14,
                          vertical: 8,
                        ),
                        decoration: BoxDecoration(
                          color: Colors.white,
                          borderRadius: BorderRadius.circular(999),
                        ),
                        child: Text(
                          'تسوق الآن',
                          style: TextStyle(
                            color: palette.primaryDark,
                            fontSize: 12,
                            fontWeight: FontWeight.w800,
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _CategoryRail extends StatelessWidget {
  const _CategoryRail({
    required this.storeId,
    required this.categories,
    required this.palette,
  });

  final int storeId;
  final List<B2cCategory> categories;
  final FoodexPalette palette;

  @override
  Widget build(BuildContext context) => SizedBox(
        height: 104,
        child: ListView.separated(
          padding: const EdgeInsets.symmetric(horizontal: 14),
          scrollDirection: Axis.horizontal,
          itemCount: categories.length,
          separatorBuilder: (_, __) => const SizedBox(width: 12),
          itemBuilder: (context, index) {
            final category = categories[index];
            return SizedBox(
              width: 68,
              child: InkWell(
                key: ValueKey('b2c-home-category-${category.id}'),
                onTap: () => Navigator.of(context).pushNamed(
                  '/products?store=' +
                      storeId.toString() +
                      '&category=' +
                      category.id.toString(),
                ),
                borderRadius: BorderRadius.circular(18),
                child: Column(
                  children: [
                    Container(
                      key: ValueKey(
                        'b2c-home-category-image-${category.id}',
                      ),
                      width: 62,
                      height: 62,
                      decoration: BoxDecoration(
                        color: palette.soft,
                        shape: BoxShape.circle,
                      ),
                      clipBehavior: Clip.antiAlias,
                      child: category.imageUrl == null
                          ? Icon(
                              Icons.category_outlined,
                              color: palette.primary,
                            )
                          : Image.network(
                              category.imageUrl!,
                              fit: BoxFit.cover,
                              errorBuilder: (_, __, ___) => Icon(
                                Icons.category_outlined,
                                color: palette.primary,
                              ),
                            ),
                    ),
                    const SizedBox(height: 7),
                    Text(
                      category.name,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      textAlign: TextAlign.center,
                      style: const TextStyle(
                        fontSize: 11,
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

class _OfferRail extends StatelessWidget {
  const _OfferRail({
    required this.offers,
    required this.palette,
  });

  final List<B2cOffer> offers;
  final FoodexPalette palette;

  @override
  Widget build(BuildContext context) => SizedBox(
        height: 74,
        child: ListView.separated(
          padding: const EdgeInsets.symmetric(horizontal: 14),
          scrollDirection: Axis.horizontal,
          itemCount: offers.length,
          separatorBuilder: (_, __) => const SizedBox(width: 10),
          itemBuilder: (_, index) => Container(
            width: 170,
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: palette.soft,
              borderRadius: BorderRadius.circular(16),
            ),
            child: Text(
              offers[index].name,
              style: TextStyle(
                color: palette.primaryDark,
                fontWeight: FontWeight.w700,
              ),
            ),
          ),
        ),
      );
}

class _RetailProductGrid extends StatelessWidget {
  const _RetailProductGrid({
    required this.storeId,
    required this.products,
    required this.palette,
    required this.actionApi,
  });

  final int storeId;
  final List<B2cProduct> products;
  final FoodexPalette palette;
  final CustomerActionApi actionApi;

  @override
  Widget build(BuildContext context) {
    final count = MediaQuery.sizeOf(context).width < 350 ? 3 : 4;
    return GridView.builder(
      padding: const EdgeInsets.symmetric(horizontal: 14),
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      itemCount: products.length,
      gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
        crossAxisCount: count,
        crossAxisSpacing: 9,
        mainAxisSpacing: 10,
        childAspectRatio: count == 3 ? .56 : .48,
      ),
      itemBuilder: (context, index) {
        final product = products[index];
        return Material(
          key: ValueKey('b2c-home-product-${product.id}'),
          color: Colors.white,
          borderRadius: BorderRadius.circular(18),
          child: InkWell(
            onTap: () => Navigator.of(context).pushNamed(
              '/retail/' +
                  storeId.toString() +
                  '/products/' +
                  product.id.toString(),
            ),
            borderRadius: BorderRadius.circular(18),
            child: Container(
              padding: const EdgeInsets.all(9),
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(18),
                border: Border.all(color: const Color(0xFFE7EBE8)),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(
                    child: FoodexProductImage(
                      url: product.imageUrl,
                      palette: palette,
                    ),
                  ),
                  const SizedBox(height: 8),
                  Text(
                    product.name,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(
                      fontSize: 12,
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                  const SizedBox(height: 5),
                  Row(
                    children: [
                      Expanded(
                        child: Text(
                          product.price == null
                              ? '—'
                              : product.price!.toStringAsFixed(2) +
                                  ' ' +
                                  product.currency,
                          style: TextStyle(
                            color: palette.primary,
                            fontSize: 12,
                            fontWeight: FontWeight.w900,
                          ),
                        ),
                      ),
                      SizedBox(
                        width: 36,
                        height: 36,
                        child: FilledButton(
                          style: FilledButton.styleFrom(
                            padding: EdgeInsets.zero,
                            backgroundColor: palette.primary,
                            shape: const CircleBorder(),
                          ),
                          onPressed: () async {
                            try {
                              await actionApi.addCartItem(
                                storeId: storeId,
                                productId: product.id,
                                quantity: 1,
                              );
                            } catch (error) {
                              if (context.mounted) {
                                await showOperationalError(context, error);
                              }
                            }
                          },
                          child: const Icon(Icons.add_rounded, size: 20),
                        ),
                      ),
                    ],
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

class RetailProductDetailsDesignScreen extends StatefulWidget {
  const RetailProductDetailsDesignScreen({
    required this.location,
    required this.session,
    required this.catalogApi,
    required this.actionApi,
    this.favoritesApi,
    super.key,
  });

  final String location;
  final CustomerSession session;
  final B2cCatalogApi catalogApi;
  final CustomerActionApi actionApi;
  final B2cRetailFavoritesApi? favoritesApi;

  @override
  State<RetailProductDetailsDesignScreen> createState() =>
      _RetailProductDetailsDesignScreenState();
}

class _RetailProductDetailsDesignScreenState
    extends State<RetailProductDetailsDesignScreen> {
  late final int storeId = retailStoreId(widget.location);
  late final int productId = productIdFromLocation(widget.location);
  double quantity = 1;

  @override
  Widget build(BuildContext context) => Directionality(
        textDirection: Directionality.of(context),
        child: Scaffold(
          backgroundColor: Colors.white,
          body: SafeArea(
            child: FutureBuilder<B2cProduct>(
              future: widget.catalogApi.product(
                productId,
                storeId: storeId,
              ),
              builder: (context, snapshot) {
                if (snapshot.connectionState != ConnectionState.done) {
                  return const FoodexLoading();
                }
                if (snapshot.hasError || !snapshot.hasData) {
                  return const FoodexErrorState(
                    message: 'تعذر تحميل المنتج.',
                  );
                }
                final product = snapshot.data!;
                final images = product.images.isEmpty
                    ? <String>[
                        if (product.imageUrl != null) product.imageUrl!,
                      ]
                    : product.images;
                return ListView(
                  key: const ValueKey('b2c-product-detail-data'),
                  padding: const EdgeInsets.fromLTRB(16, 10, 16, 24),
                  children: [
                    FoodexTopBar(
                      title: 'تفاصيل المنتج',
                      actions: [
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
                    const SizedBox(height: 10),
                    FoodexGallery(
                      key: const ValueKey('b2c-product-gallery'),
                      urls: images,
                      palette: FoodexPalette.grocery,
                    ),
                    const SizedBox(height: 18),
                    Text(
                      product.name,
                      style: const TextStyle(
                        fontSize: 21,
                        fontWeight: FontWeight.w900,
                      ),
                    ),
                    const SizedBox(height: 5),
                    Text(
                      product.sku,
                      style: const TextStyle(
                        color: Color(0xFF6B7785),
                        fontSize: 12,
                      ),
                    ),
                    const SizedBox(height: 12),
                    Text(
                      product.price == null
                          ? 'السعر غير متاح'
                          : product.price!.toStringAsFixed(3) +
                              ' ' +
                              product.currency,
                      style: const TextStyle(
                        color: Color(0xFF078A43),
                        fontSize: 22,
                        fontWeight: FontWeight.w900,
                      ),
                    ),
                    const SizedBox(height: 4),
                    const Text(
                      'متوفر في المخزون',
                      style: TextStyle(
                        color: Color(0xFF078A43),
                        fontSize: 12,
                      ),
                    ),
                    const SizedBox(height: 18),
                    FoodexQuantityCta(
                      quantity: quantity,
                      increment: 1,
                      minimum: 1,
                      palette: FoodexPalette.grocery,
                      label: 'إضافة إلى السلة',
                      onChanged: (value) =>
                          setState(() => quantity = value),
                      onPressed: () async {
                        try {
                          await widget.actionApi.addCartItem(
                            storeId: storeId,
                            productId: productId,
                            quantity: quantity,
                          );
                        } catch (error) {
                          if (context.mounted) {
                            await showOperationalError(context, error);
                          }
                        }
                      },
                    ),
                    const SizedBox(height: 18),
                    FoodexDetailAccordion(
                      title: 'تفاصيل المنتج',
                      body: product.description ?? 'لا توجد تفاصيل إضافية.',
                    ),
                    const FoodexDetailAccordion(
                      title: 'معلومات التوصيل',
                      body:
                          'يتم احتساب التوفر ووقت التوصيل حسب المتجر والعنوان.',
                    ),
                    const SizedBox(height: 15),
                    const Text(
                      'منتجات قد تعجبك',
                      style: TextStyle(
                        fontSize: 17,
                        fontWeight: FontWeight.w800,
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
