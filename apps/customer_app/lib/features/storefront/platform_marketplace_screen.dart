import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;

import '../../core/auth/customer_session.dart';
import '../../core/config/foodex_environment.dart';
import '../../core/localization/app_translations.dart';
import '../../core/routing/customer_commerce_context.dart';
import '../../core/routing/customer_routes.dart';
import 'marketplace_barcode_scanner.dart';

class PlatformMarketplaceScreen extends StatefulWidget {
  const PlatformMarketplaceScreen({
    required this.session,
    required this.onPlatformRegistered,
    super.key,
    this.client,
    this.onLocaleChanged,
    this.barcodeScanner = showMarketplaceBarcodeScanner,
  });

  final CustomerSession session;
  final ValueChanged<String> onPlatformRegistered;
  final http.Client? client;
  final ValueChanged<Locale>? onLocaleChanged;
  final MarketplaceBarcodeScanner barcodeScanner;

  @override
  State<PlatformMarketplaceScreen> createState() => _PlatformMarketplaceScreenState();
}

class _PlatformMarketplaceScreenState extends State<PlatformMarketplaceScreen> {
  late final http.Client _client = widget.client ?? http.Client();
  late Future<Map<String, dynamic>> _future = _load();
  final PageController _bannerController = PageController();
  final TextEditingController _searchController = TextEditingController();
  final FocusNode _searchFocus = FocusNode();
  Timer? _bannerTimer;
  int _bannerIndex = 0;
  int _bannerCount = -1;
  int? _selectedCategoryId;
  String? _pendingAfterAuth;
  List<Map<String, dynamic>>? _guestRetailStoreFallback;

  Future<Map<String, dynamic>> _load() async {
    final baseUrl = FoodexEnvironment.apiBaseUrl;
    final query = _searchController.text.trim();
    final params = <String, String>{
      if (query.isNotEmpty) 'q': query,
      if (_selectedCategoryId != null)
        'category_id': _selectedCategoryId.toString(),
    };
    final uri = Uri.parse('$baseUrl/api/v1/platform/storefront').replace(
      queryParameters: params.isEmpty ? null : params,
    );
    final data = await _get(uri.toString());

    // Keep the approved home order: Wholesale hero first, then exactly one
    // Retail carousel. The carousel contains every active public Retail store:
    // Dashboard placements keep their configured order (the first placement is
    // the primary store), then stores missing from the placements are appended
    // in the public /stores order. Duplicate store placements never create
    // duplicate Retail slides.
    if (!widget.session.isAuthenticated) {
      final configuredRetail = _rows(data['retail_banners']);
      _guestRetailStoreFallback ??=
          await _loadGuestRetailStoreFallback(baseUrl);
      final retailCarousel = _mergeRetailStoreCarousel(
        configuredRetail,
        _guestRetailStoreFallback!,
      );
      if (retailCarousel.isNotEmpty) {
        data['retail_banners'] = retailCarousel;
      }
    }

    return data;
  }

  Future<List<Map<String, dynamic>>> _loadGuestRetailStoreFallback(
    String baseUrl,
  ) async {
    try {
      final payload = await _get('$baseUrl/api/v1/stores');
      return _rows(payload['data'])
          .where((store) => _int(store['id']) > 0)
          .map((store) {
            final id = _int(store['id']);
            final name = store['name']?.toString().trim() ?? '';
            return <String, dynamic>{
              ...store,
              'id': id,
              'store_id': id,
              'title': name,
              'banner_url': store['banner_url'] ?? store['logo_url'],
              'channel': 'b2c',
              'placement_scope': 'guest_store_fallback',
              'target_type': 'retail_store',
              'target_id': id,
              'target_url': '/retail/$id/home',
            };
          })
          .toList(growable: false);
    } catch (_) {
      return const <Map<String, dynamic>>[];
    }
  }

  List<Map<String, dynamic>> _mergeRetailStoreCarousel(
    List<Map<String, dynamic>> configuredRetail,
    List<Map<String, dynamic>> publicStores,
  ) {
    final byStoreId = <int, Map<String, dynamic>>{};

    for (final store in configuredRetail) {
      final id = _int(store['store_id'] ?? store['id']);
      if (id > 0 && !byStoreId.containsKey(id)) {
        byStoreId[id] = store;
      }
    }

    for (final store in publicStores) {
      final id = _int(store['store_id'] ?? store['id']);
      if (id > 0 && !byStoreId.containsKey(id)) {
        byStoreId[id] = store;
      }
    }

    return byStoreId.values.toList(growable: false);
  }

  void _submitSearch(String _) {
    setState(() {
      _future = _load();
    });
  }

  Future<void> _scanBarcode() async {
    String? scanned;
    try {
      scanned = await widget.barcodeScanner(context);
    } catch (_) {
      scanned = null;
    }

    if (!mounted) return;
    final value = scanned?.trim() ?? '';
    if (value.isEmpty) {
      _searchFocus.requestFocus();
      return;
    }

    _searchController.text = value;
    _searchFocus.unfocus();
    setState(() {
      _selectedCategoryId = null;
      _future = _load();
    });
  }

  void _toggleLocale() {
    final current = Localizations.localeOf(context).languageCode;
    widget.onLocaleChanged?.call(Locale(current == 'ar' ? 'en' : 'ar'));
  }

  void _selectCategory(int? categoryId) {
    setState(() {
      _selectedCategoryId = categoryId;
      _future = _load();
    });
  }

  void _startBannerAutoSlide(int count) {
    if (_bannerCount == count) return;
    _bannerCount = count;
    _bannerTimer?.cancel();
    if (count <= 0) {
      _bannerIndex = 0;
      return;
    }
    if (_bannerIndex >= count) {
      _bannerIndex = 0;
      if (_bannerController.hasClients) {
        _bannerController.jumpToPage(0);
      }
    }
    if (count <= 1) return;
    _bannerTimer = Timer.periodic(const Duration(seconds: 5), (_) {
      if (!mounted || !_bannerController.hasClients) return;
      _bannerIndex = (_bannerIndex + 1) % count;
      _bannerController.animateToPage(
        _bannerIndex,
        duration: const Duration(milliseconds: 420),
        curve: Curves.easeOutCubic,
      );
    });
  }

  @override
  void dispose() {
    _bannerTimer?.cancel();
    _bannerController.dispose();
    _searchController.dispose();
    _searchFocus.dispose();
    if (widget.client == null) {
      _client.close();
    }
    super.dispose();
  }

  Future<Map<String, dynamic>> _get(String url) async {
    final token = widget.session.accessToken;
    final response = await _client.get(
      Uri.parse(url),
      headers: {
        'Accept': 'application/json',
        if (token != null && token.isNotEmpty)
          'Authorization': 'Bearer $token',
      },
    );
    if (response.statusCode < 200 || response.statusCode >= 300) {
      throw _MarketplaceException('http_${response.statusCode}');
    }
    final decoded = jsonDecode(response.body);
    if (decoded is! Map) {
      throw const _MarketplaceException('invalid_response');
    }
    return Map<String, dynamic>.from(decoded);
  }

  Future<void> _register() async {
    final result = await showModalBottomSheet<Map<String, String>>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (sheetContext) => const _RegistrationSheet(),
    );
    if (result == null || !mounted) return;

    try {
      final response = await _client.post(
        Uri.parse('${FoodexEnvironment.apiBaseUrl}/api/v1/auth/register'),
        headers: const {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
        },
        body: jsonEncode({
          ...result,
          'locale': Localizations.localeOf(context).languageCode,
        }),
      );
      final decoded = response.body.isEmpty ? null : jsonDecode(response.body);
      if (response.statusCode < 200 || response.statusCode >= 300 || decoded is! Map) {
        throw const _MarketplaceException('registration_failed');
      }
      final token = decoded['token']?.toString() ?? '';
      if (token.isEmpty) {
        throw const _MarketplaceException('missing_token');
      }

      widget.onPlatformRegistered(token);
      if (!mounted) return;
      final pending = _pendingAfterAuth;
      _pendingAfterAuth = null;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(context.tr('customer.marketplace.registration_success'))),
      );
      setState(() => _future = _load());
      if (pending != null && pending.isNotEmpty) {
        Navigator.of(context).pushNamed(pending);
      }
    } catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(context.tr('customer.marketplace.registration_failed'))),
      );
    }
  }

  void _openRetail(Map<String, dynamic> placement) {
    final storeId = _int(placement['store_id'] ?? placement['id']);
    if (storeId <= 0) return;

    final placementId = _int(placement['placement_id'] ?? placement['banner_id']);
    final commerceContext = CustomerCommerceContext(
      channel: CustomerCommerceChannel.retail,
      storeId: storeId,
      source: CustomerCommerceSource.retailBanner,
      entryPlacementId: placementId > 0 ? placementId : null,
    );
    Navigator.of(context).pushNamed(
      CustomerRouteLocations.retailHome(commerceContext),
    );
  }

  Future<void> _openWholesaleProduct(int storeId, int productId) async {
    if (productId <= 0) return;
    try {
      final product = await _get(
        '${FoodexEnvironment.apiBaseUrl}/api/v1/platform/products/$productId',
      );
      if (!mounted) return;
      await showModalBottomSheet<void>(
        context: context,
        isScrollControlled: true,
        showDragHandle: true,
        builder: (sheetContext) => SafeArea(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(20, 0, 20, 24),
            child: SingleChildScrollView(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text(
                    product['name']?.toString() ?? '',
                    key: const ValueKey('marketplace-wholesale-product-title'),
                    style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                          fontWeight: FontWeight.w900,
                        ),
                  ),
                  const SizedBox(height: 8),
                  if ((product['sku']?.toString() ?? '').isNotEmpty)
                    Text('SKU: ${product['sku']}'),
                  if ((product['barcode']?.toString() ?? '').isNotEmpty)
                    Text('Barcode: ${product['barcode']}'),
                  const SizedBox(height: 12),
                  Text(
                    '${(product['unit_price'] ?? product['account_price'] ?? 0)} ${product['currency'] ?? 'EGP'}',
                    style: Theme.of(context).textTheme.titleLarge?.copyWith(
                          fontWeight: FontWeight.w900,
                        ),
                  ),
                  if ((product['description']?.toString() ?? '').isNotEmpty) ...[
                    const SizedBox(height: 12),
                    Text(product['description'].toString()),
                  ],
                  const SizedBox(height: 18),
                  FilledButton.icon(
                    key: const ValueKey('marketplace-wholesale-buy'),
                    onPressed: () {
                      Navigator.pop(sheetContext);
                      if (!widget.session.isAuthenticated) {
                        _showAuthRequired(
                          next: '/b2b/products/$productId?store_id=$storeId',
                        );
                        return;
                      }
                      Navigator.of(context).pushNamed(
                        '/b2b/products/$productId?store_id=$storeId',
                      );
                    },
                    icon: const Icon(Icons.shopping_cart_checkout_rounded),
                    label: Text(
                      widget.session.isAuthenticated
                          ? context.tr('customer.action.add_cart')
                          : context.tr('customer.action.login'),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      );
    } catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(context.tr('customer.store.error.title'))),
      );
    }
  }

  void _showAuthRequired({String? next}) {
    _pendingAfterAuth = next;
    showModalBottomSheet<void>(
      context: context,
      showDragHandle: true,
      builder: (sheetContext) => SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(20, 0, 20, 24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(
                context.tr('customer.marketplace.auth_required_title'),
                style: Theme.of(context).textTheme.titleLarge?.copyWith(
                      fontWeight: FontWeight.w800,
                    ),
              ),
              const SizedBox(height: 8),
              Text(context.tr('customer.marketplace.auth_required_body')),
              const SizedBox(height: 16),
              FilledButton(
                onPressed: () {
                  Navigator.pop(sheetContext);
                  _register();
                },
                child: Text(context.tr('customer.marketplace.register')),
              ),
              const SizedBox(height: 8),
              OutlinedButton(
                onPressed: () {
                  Navigator.pop(sheetContext);
                  final target = next ?? '/marketplace';
                  Navigator.of(context).pushNamed(
                    Uri(
                      path: '/auth/checkout',
                      queryParameters: {'next': target},
                    ).toString(),
                  );
                },
                child: Text(context.tr('customer.action.login')),
              ),
            ],
          ),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        backgroundColor: const Color(0xFFF8FAF9),
        body: SafeArea(
          child: FutureBuilder<Map<String, dynamic>>(
            future: _future,
            builder: (context, snapshot) {
              if (snapshot.connectionState != ConnectionState.done) {
                return const Center(child: CircularProgressIndicator());
              }
              if (snapshot.hasError) {
                return Center(
                  child: Padding(
                    padding: const EdgeInsets.all(24),
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        const Icon(Icons.cloud_off_rounded, size: 48),
                        const SizedBox(height: 12),
                        Text(context.tr('customer.store.error.title')),
                        const SizedBox(height: 12),
                        FilledButton(
                          onPressed: () => setState(() => _future = _load()),
                          child: Text(context.tr('customer.action.retry')),
                        ),
                      ],
                    ),
                  ),
                );
              }

              final data = snapshot.data ?? const <String, dynamic>{};
              final wholesale = data['store'] is Map
                  ? Map<String, dynamic>.from(data['store'] as Map)
                  : <String, dynamic>{};
              final retail = _rows(data['retail_banners']);
              final categories = _rows(data['categories']);
              final offers = _rows(data['offers']);
              final productEnvelope = data['products'] is Map
                  ? Map<String, dynamic>.from(data['products'] as Map)
                  : <String, dynamic>{};
              final products = _rows(productEnvelope['data']);
              final hero = data['hero'] is Map
                  ? Map<String, dynamic>.from(data['hero'] as Map)
                  : <String, dynamic>{};
              final storeId = _int(wholesale['id']);
              final storeSlidesCount = 1 + retail.length;
              WidgetsBinding.instance.addPostFrameCallback((_) {
                _startBannerAutoSlide(storeSlidesCount);
              });

              return LayoutBuilder(
                builder: (context, constraints) {
                  final bannerHeight = (constraints.maxHeight * .22)
                       .clamp(176.0, 204.0)
                       .toDouble();

                  return CustomScrollView(
                    slivers: [
                      SliverToBoxAdapter(
                        child: _MarketplaceHeader(
                          authenticated: widget.session.isAuthenticated,
                          onRegister: _register,
                          onLogin: () => Navigator.of(context).pushNamed(
                            '/auth/checkout?next=/marketplace',
                          ),
                          onScan: _scanBarcode,
                          onLanguageToggle: _toggleLocale,
                          localeCode:
                              Localizations.localeOf(context).languageCode,
                          onCart: () =>
                              Navigator.of(context).pushNamed('/cart'),
                          onNotifications: () {
                            if (widget.session.isAuthenticated) {
                              Navigator.of(context).pushNamed('/notifications');
                            } else {
                              Navigator.of(context).pushNamed(
                                Uri(
                                  path: '/auth/checkout',
                                  queryParameters: {'next': '/notifications'},
                                ).toString(),
                              );
                            }
                          },
                          searchController: _searchController,
                          searchFocus: _searchFocus,
                          onSearchSubmitted: _submitSearch,
                          onSearchClear: () {
                            _searchController.clear();
                            _submitSearch('');
                          },
                        ),
                      ),
                      SliverToBoxAdapter(
                        child: SizedBox(
                          key: const ValueKey('marketplace-banner-carousel'),
                          height: bannerHeight,
                          child: Column(
                            children: [
                              Expanded(
                                child: PageView.builder(
                                  key: const ValueKey('marketplace-store-carousel'),
                                  controller: _bannerController,
                                  itemCount: storeSlidesCount,
                                  onPageChanged: (index) {
                                    if (_bannerIndex == index) return;
                                    setState(() => _bannerIndex = index);
                                  },
                                  itemBuilder: (_, index) {
                                    if (index == 0) {
                                      return SizedBox(
                                        key: const ValueKey(
                                          'marketplace-wholesale-entry',
                                        ),
                                        child: _WholesaleHero(
                                          title: (hero['title']
                                                          ?.toString()
                                                          .trim() ??
                                                      '')
                                                  .isNotEmpty
                                              ? hero['title'].toString().trim()
                                              : 'FOODEX Wholesale',
                                          imageUrl:
                                              hero['image_url']?.toString(),
                                        ),
                                      );
                                    }

                                    final store = retail[index - 1];
                                    return Padding(
                                      padding:
                                          const EdgeInsetsDirectional.fromSTEB(
                                        14,
                                        6,
                                        14,
                                        2,
                                      ),
                                      child: _RetailStoreBanner(
                                        store: store,
                                        width: double.infinity,
                                        onTap: () => _openRetail(store),
                                      ),
                                    );
                                  },
                                ),
                              ),
                              if (storeSlidesCount > 1)
                                Padding(
                                  padding: const EdgeInsets.only(top: 4),
                                  child: Row(
                                    key: const ValueKey(
                                      'marketplace-banner-indicators',
                                    ),
                                    mainAxisAlignment: MainAxisAlignment.center,
                                    children: List.generate(
                                      storeSlidesCount,
                                      (index) => AnimatedContainer(
                                        duration:
                                            const Duration(milliseconds: 180),
                                        width: _bannerIndex == index ? 18 : 6,
                                        height: 6,
                                        margin: const EdgeInsets.symmetric(
                                          horizontal: 3,
                                        ),
                                        decoration: BoxDecoration(
                                          color: _bannerIndex == index
                                              ? const Color(0xFF0B7A4B)
                                              : const Color(0xFFD7DDE1),
                                          borderRadius:
                                              BorderRadius.circular(999),
                                        ),
                                      ),
                                    ),
                                  ),
                                ),
                            ],
                          ),
                        ),
                      ),
                      if (categories.isNotEmpty)
                        SliverToBoxAdapter(
                          child: _MarketplaceCategoryRail(
                            categories: categories,
                            selectedCategoryId: _selectedCategoryId,
                            onSelect: _selectCategory,
                          ),
                        ),
                      if (offers.isNotEmpty)
                        SliverToBoxAdapter(
                          child: _MarketplaceOfferStrip(offers: offers),
                        ),
                      SliverPadding(
                        padding: const EdgeInsets.fromLTRB(14, 16, 14, 8),
                        sliver: SliverToBoxAdapter(
                          child: _MarketplaceSectionHeader(
                            title: context.tr(
                              'customer.marketplace.wholesale_products',
                            ),
                            actionLabel: context.tr(
                              'customer.marketplace.all_categories',
                            ),
                            onAction: () {
                              if (_selectedCategoryId != null ||
                                  _searchController.text.isNotEmpty) {
                                _searchController.clear();
                                _selectCategory(null);
                              }
                            },
                          ),
                        ),
                      ),
                      if (products.isEmpty)
                        SliverToBoxAdapter(
                          child: Padding(
                            padding: const EdgeInsets.all(24),
                            child: Text(
                              context.tr('b2b.empty.products'),
                              textAlign: TextAlign.center,
                            ),
                          ),
                        )
                      else
                        SliverPadding(
                          padding: const EdgeInsets.fromLTRB(14, 0, 14, 28),
                          sliver: SliverGrid(
                            gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
                              crossAxisCount: constraints.maxWidth >= 700 ? 3 : 2,
                              mainAxisSpacing: 12,
                              crossAxisSpacing: 12,
                              childAspectRatio:
                                  constraints.maxWidth >= 700 ? 1.02 : .86,
                            ),
                            delegate: SliverChildBuilderDelegate(
                              (context, index) {
                                final product = products[index];
                                return _WholesaleProductCard(
                                  product: product,
                                  onTap: () => _openWholesaleProduct(
                                    storeId,
                                    _int(product['id']),
                                  ),
                                );
                              },
                              childCount: products.length,
                            ),
                          ),
                        ),
                    ],
                  );
                },
              );
            },
          ),
        ),
      );
}

class _MarketplaceHeader extends StatelessWidget {
  const _MarketplaceHeader({
    required this.authenticated,
    required this.onRegister,
    required this.onLogin,
    required this.onScan,
    required this.onLanguageToggle,
    required this.localeCode,
    required this.onCart,
    required this.onNotifications,
    required this.searchController,
    required this.searchFocus,
    required this.onSearchSubmitted,
    required this.onSearchClear,
  });

  final bool authenticated;
  final VoidCallback onRegister;
  final VoidCallback onLogin;
  final VoidCallback onScan;
  final VoidCallback onLanguageToggle;
  final String localeCode;
  final VoidCallback onCart;
  final VoidCallback onNotifications;
  final TextEditingController searchController;
  final FocusNode searchFocus;
  final ValueChanged<String> onSearchSubmitted;
  final VoidCallback onSearchClear;

  @override
  Widget build(BuildContext context) {
    Widget roundAction({
      required Key key,
      required IconData icon,
      required VoidCallback onPressed,
      String? tooltip,
    }) => Material(
      color: Colors.white,
      shape: const CircleBorder(),
      child: InkWell(
        key: key,
        customBorder: const CircleBorder(),
        onTap: onPressed,
        child: Tooltip(
          message: tooltip ?? '',
          child: SizedBox(
            width: 42, height: 42,
            child: Icon(icon, size: 22, color: const Color(0xFF17212F)),
          ),
        ),
      ),
    );

    Widget accountAction() {
      if (authenticated) {
        return roundAction(
          key: const ValueKey('marketplace-profile'),
          icon: Icons.person_outline_rounded,
          onPressed: () => Navigator.of(context).pushNamed('/profile'),
        );
      }
      return PopupMenuButton<String>(
        key: const ValueKey('marketplace-auth-menu'),
        padding: EdgeInsets.zero,
        tooltip: context.tr('customer.action.login'),
        icon: const CircleAvatar(
          radius: 21, backgroundColor: Colors.white,
          child: Icon(Icons.person_outline_rounded, color: Color(0xFF17212F)),
        ),
        onSelected: (value) => value == 'login' ? onLogin() : onRegister(),
        itemBuilder: (_) => [
          PopupMenuItem(value: 'login', child: Text(context.tr('customer.action.login'))),
          PopupMenuItem(value: 'register', child: Text(context.tr('customer.marketplace.register'))),
        ],
      );
    }

    return Padding(
      padding: const EdgeInsets.fromLTRB(10, 9, 10, 5),
      child: Row(
        key: const ValueKey('marketplace-compact-header'),
        children: [
          accountAction(),
          const SizedBox(width: 2),
          roundAction(
            key: const ValueKey('marketplace-notifications'),
            icon: Icons.notifications_none_rounded,
            tooltip: context.tr('customer.nav.notifications'),
            onPressed: onNotifications,
          ),
          const SizedBox(width: 2),
          SizedBox(
            width: 32, height: 42,
            child: TextButton(
              key: const ValueKey('marketplace-language'),
              onPressed: onLanguageToggle,
              style: TextButton.styleFrom(padding: EdgeInsets.zero, minimumSize: const Size(32, 42)),
              child: Text(
                localeCode.toUpperCase(),
                style: const TextStyle(color: Color(0xFF087347), fontSize: 10, fontWeight: FontWeight.w900),
              ),
            ),
          ),
          const SizedBox(width: 5),
          Expanded(
            child: SizedBox(
              height: 46,
              child: TextField(
                key: const ValueKey('marketplace-search'),
                controller: searchController,
                focusNode: searchFocus,
                textInputAction: TextInputAction.search,
                onSubmitted: onSearchSubmitted,
                decoration: InputDecoration(
                  hintText: context.tr('customer.marketplace.search_hint'),
                  prefixIcon: const Icon(Icons.search_rounded, size: 21),
                  suffixIcon: searchController.text.isEmpty
                      ? IconButton(
                          key: const ValueKey('marketplace-scan'),
                          tooltip: context.tr('customer.marketplace.scan'),
                          onPressed: onScan,
                          icon: const Icon(Icons.qr_code_scanner_rounded, size: 19),
                        )
                      : IconButton(onPressed: onSearchClear, icon: const Icon(Icons.close_rounded, size: 19)),
                  filled: true, fillColor: Colors.white,
                  contentPadding: const EdgeInsets.symmetric(horizontal: 6, vertical: 9),
                  border: OutlineInputBorder(borderRadius: BorderRadius.circular(999), borderSide: const BorderSide(color: Color(0xFFE6E9EC))),
                  enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(999), borderSide: const BorderSide(color: Color(0xFFE6E9EC))),
                  focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(999), borderSide: const BorderSide(color: Color(0xFF087347), width: 1.3)),
                ),
              ),
            ),
          ),
          const SizedBox(width: 5),
          Container(
            width: 42, height: 42,
            decoration: BoxDecoration(color: const Color(0xFFEAF7F0), borderRadius: BorderRadius.circular(14)),
            child: const Icon(Icons.storefront_rounded, color: Color(0xFF087347), size: 24),
          ),
          const SizedBox(width: 4),
          roundAction(
            key: const ValueKey('marketplace-cart'),
            icon: Icons.shopping_cart_outlined,
            tooltip: context.tr('customer.nav.cart'),
            onPressed: onCart,
          ),
          const SizedBox.shrink(key: ValueKey('marketplace-brand-title')),
        ],
      ),
    );
  }
}
class _RetailStoreBanner extends StatelessWidget {
  const _RetailStoreBanner({
    required this.store,
    required this.width,
    required this.onTap,
  });

  final Map<String, dynamic> store;
  final double width;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final id = _int(store['id']);
    final image = store['banner_url']?.toString().trim();
    final logo = store['logo_url']?.toString().trim();
    final address = store['address']?.toString().trim() ?? '';
    final title = store['title']?.toString().trim();
    final name = store['name']?.toString().trim() ?? '';

    Widget backgroundFallback() => const DecoratedBox(
          decoration: BoxDecoration(
            gradient: LinearGradient(
              begin: AlignmentDirectional.topStart,
              end: AlignmentDirectional.bottomEnd,
              colors: [Color(0xFF087347), Color(0xFF16A66C)],
            ),
          ),
          child: Align(
            alignment: AlignmentDirectional.topEnd,
            child: Padding(
              padding: EdgeInsets.all(18),
              child: Icon(
                Icons.storefront_rounded,
                color: Color(0x55FFFFFF),
                size: 64,
              ),
            ),
          ),
        );

    return SizedBox(
      width: width,
      child: Material(
        color: Colors.white,
        borderRadius: BorderRadius.circular(22),
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: onTap,
          child: Stack(
            fit: StackFit.expand,
            children: [
              if (image != null && image.isNotEmpty)
                Image.network(
                  image,
                  key: ValueKey('marketplace-retail-banner-image-$id'),
                  fit: BoxFit.cover,
                  errorBuilder: (_, __, ___) => backgroundFallback(),
                )
              else
                backgroundFallback(),
              const DecoratedBox(
                decoration: BoxDecoration(
                  gradient: LinearGradient(
                    begin: Alignment.bottomCenter,
                    end: Alignment.topCenter,
                    stops: [0, .62, 1],
                    colors: [
                      Color(0xCC0B1720),
                      Color(0x330B1720),
                      Colors.transparent,
                    ],
                  ),
                ),
              ),
              PositionedDirectional(
                start: 16,
                end: 16,
                bottom: 14,
                child: LayoutBuilder(
                  builder: (context, constraints) {
                    final compact = constraints.maxWidth < 280;
                    return Row(
                      children: [
                        if (logo != null && logo.isNotEmpty) ...[
                          ClipOval(
                            child: SizedBox(
                              width: compact ? 34 : 38,
                              height: compact ? 34 : 38,
                              child: Image.network(
                                logo,
                                fit: BoxFit.cover,
                                errorBuilder: (_, __, ___) =>
                                    const ColoredBox(
                                  color: Colors.white,
                                  child: Icon(
                                    Icons.storefront_rounded,
                                    color: Color(0xFF087347),
                                    size: 21,
                                  ),
                                ),
                              ),
                            ),
                          ),
                          SizedBox(width: compact ? 7 : 10),
                        ],
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              Text(
                                title != null && title.isNotEmpty
                                    ? title
                                    : name,
                                key: ValueKey(
                                  'marketplace-retail-banner-title-$id',
                                ),
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: TextStyle(
                                  color: Colors.white,
                                  fontWeight: FontWeight.w900,
                                  fontSize: compact ? 15 : 17,
                                  height: 1.2,
                                ),
                              ),
                              if (address.isNotEmpty && !compact) ...[
                                const SizedBox(height: 3),
                                Text(
                                  address,
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                  style: const TextStyle(
                                    color: Color(0xFFDCE5E1),
                                    fontSize: 11,
                                    fontWeight: FontWeight.w600,
                                  ),
                                ),
                              ],
                            ],
                          ),
                        ),
                        const SizedBox(width: 8),
                        if (compact)
                          Container(
                            width: 34,
                            height: 34,
                            decoration: BoxDecoration(
                              color: Colors.white,
                              borderRadius: BorderRadius.circular(999),
                            ),
                            child: const Icon(
                              Icons.arrow_forward_rounded,
                              color: Color(0xFF087347),
                              size: 18,
                            ),
                          )
                        else
                          Container(
                            constraints: const BoxConstraints(maxWidth: 110),
                            padding: const EdgeInsets.symmetric(
                              horizontal: 12,
                              vertical: 8,
                            ),
                            decoration: BoxDecoration(
                              color: Colors.white,
                              borderRadius: BorderRadius.circular(999),
                            ),
                            child: Text(
                              context.tr('customer.marketplace.shop_now'),
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: const TextStyle(
                                color: Color(0xFF087347),
                                fontWeight: FontWeight.w900,
                                fontSize: 11,
                              ),
                            ),
                          ),
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
}

class _WholesaleHero extends StatelessWidget {
  const _WholesaleHero({required this.title, this.imageUrl});

  final String title;
  final String? imageUrl;

  @override
  Widget build(BuildContext context) {
    final image = imageUrl?.trim();

    Widget fallback() => const DecoratedBox(
          decoration: BoxDecoration(
            gradient: LinearGradient(
              begin: AlignmentDirectional.topStart,
              end: AlignmentDirectional.bottomEnd,
              colors: [Color(0xFF5D2A91), Color(0xFF35195E)],
            ),
          ),
          child: Align(
            alignment: AlignmentDirectional.topEnd,
            child: Padding(
              padding: EdgeInsets.all(20),
              child: Icon(
                Icons.warehouse_rounded,
                color: Color(0x33FFFFFF),
                size: 80,
              ),
            ),
          ),
        );

    return Container(
      margin: const EdgeInsetsDirectional.fromSTEB(14, 6, 14, 2),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(22),
        boxShadow: const [
          BoxShadow(
            color: Color(0x140F172A),
            blurRadius: 18,
            offset: Offset(0, 7),
          ),
        ],
      ),
      clipBehavior: Clip.antiAlias,
      child: Stack(
        fit: StackFit.expand,
        children: [
          if (image != null && image.isNotEmpty)
            Image.network(
              image,
              key: const ValueKey('marketplace-wholesale-hero-image'),
              fit: BoxFit.cover,
              errorBuilder: (_, __, ___) => fallback(),
            )
          else
            fallback(),
          const DecoratedBox(
            decoration: BoxDecoration(
              gradient: LinearGradient(
                begin: AlignmentDirectional.centerStart,
                end: AlignmentDirectional.centerEnd,
                colors: [Color(0xE835195E), Color(0x7A35195E)],
              ),
            ),
          ),
          Padding(
            padding: const EdgeInsetsDirectional.fromSTEB(18, 18, 120, 18),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 9, vertical: 5),
                  decoration: BoxDecoration(
                    color: const Color(0x2EFFFFFF),
                    borderRadius: BorderRadius.circular(999),
                  ),
                  child: const Text(
                    'B2B',
                    style: TextStyle(
                      color: Colors.white,
                      fontSize: 10,
                      fontWeight: FontWeight.w900,
                      letterSpacing: .8,
                    ),
                  ),
                ),
                const SizedBox(height: 8),
                Text(
                  title,
                  key: const ValueKey('marketplace-wholesale-hero-title'),
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                    color: Colors.white,
                    fontWeight: FontWeight.w900,
                    fontSize: 21,
                    height: 1.12,
                  ),
                ),
                const SizedBox(height: 6),
                Text(
                  context.tr('customer.marketplace.browse_guest'),
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                    color: Color(0xFFE9DFF5),
                    fontSize: 11.5,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _MarketplaceCategoryRail extends StatelessWidget {
  const _MarketplaceCategoryRail({required this.categories, required this.selectedCategoryId, required this.onSelect});
  final List<Map<String, dynamic>> categories;
  final int? selectedCategoryId;
  final ValueChanged<int?> onSelect;
  @override
  Widget build(BuildContext context) {
    final rows = <Map<String, dynamic>>[
      {'id': null, 'name': context.tr('customer.marketplace.all_categories'), 'icon': Icons.apps_rounded},
      ...categories,
    ];
    return SizedBox(
      key: const ValueKey('marketplace-categories'),
      height: 112,
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.fromLTRB(12, 10, 12, 6),
        itemCount: rows.length,
        separatorBuilder: (_, __) => const SizedBox(width: 8),
        itemBuilder: (context, index) {
          final row = rows[index];
          final id = row['id'] == null ? null : _int(row['id']);
          final selected = selectedCategoryId == id;
          final name = row['name']?.toString() ?? '';
          final image = row['image_url']?.toString().trim();
          return SizedBox(
            width: 72,
            child: InkWell(
              key: id == null ? const ValueKey('marketplace-category-all') : ValueKey('marketplace-category-' + id.toString()),
              borderRadius: BorderRadius.circular(20),
              onTap: () => onSelect(id),
              child: Column(children: [
                AnimatedContainer(
                  duration: const Duration(milliseconds: 180),
                  width: 66, height: 66,
                  decoration: BoxDecoration(
                    color: selected ? const Color(0xFFE5F7D2) : Colors.white,
                    shape: BoxShape.circle,
                    border: Border.all(color: selected ? const Color(0xFF87C842) : const Color(0xFFE6E9EC), width: selected ? 2 : 1),
                  ),
                  clipBehavior: Clip.antiAlias,
                  child: image != null && image.isNotEmpty
                      ? Image.network(image, fit: BoxFit.cover, errorBuilder: (_, __, ___) => Icon(_marketplaceCategoryIcon(name), color: const Color(0xFF087347), size: 29))
                      : Icon(row['icon'] is IconData ? row['icon'] as IconData : _marketplaceCategoryIcon(name), color: const Color(0xFF087347), size: 29),
                ),
                const SizedBox(height: 6),
                Text(name, maxLines: 1, overflow: TextOverflow.ellipsis, textAlign: TextAlign.center, style: TextStyle(color: const Color(0xFF17212F), fontSize: 11, fontWeight: selected ? FontWeight.w900 : FontWeight.w700)),
              ]),
            ),
          );
        },
      ),
    );
  }
}

class _MarketplaceOfferStrip extends StatelessWidget {
  const _MarketplaceOfferStrip({required this.offers});
  final List<Map<String, dynamic>> offers;
  @override
  Widget build(BuildContext context) => Container(
    key: const ValueKey('marketplace-wholesale-offers'),
    margin: const EdgeInsets.fromLTRB(14, 10, 14, 2),
    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 10),
    decoration: BoxDecoration(gradient: const LinearGradient(colors: [Color(0xFF0B6F43), Color(0xFF0E8C55)]), borderRadius: BorderRadius.circular(18)),
    child: SizedBox(height: 48, child: ListView.separated(
      scrollDirection: Axis.horizontal,
      itemCount: offers.length,
      separatorBuilder: (_, __) => Container(width: 1, margin: const EdgeInsets.symmetric(horizontal: 10), color: const Color(0x55FFFFFF)),
      itemBuilder: (_, index) {
        final offer = offers[index];
        final value = offer['value'];
        final type = offer['type']?.toString();
        final suffix = value == null ? '' : type == 'percentage' ? ' • ' + value.toString() + '%' : ' • ' + value.toString();
        return Row(mainAxisSize: MainAxisSize.min, children: [
          const Icon(Icons.local_offer_outlined, color: Colors.white, size: 22),
          const SizedBox(width: 7),
          ConstrainedBox(constraints: const BoxConstraints(maxWidth: 150), child: Text((offer['name']?.toString() ?? '') + suffix, maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(color: Colors.white, fontSize: 10.5, height: 1.25, fontWeight: FontWeight.w800))),
        ]);
      },
    )),
  );
}

class _MarketplaceSectionHeader extends StatelessWidget {
  const _MarketplaceSectionHeader({required this.title, required this.actionLabel, required this.onAction});
  final String title;
  final String actionLabel;
  final VoidCallback onAction;
  @override
  Widget build(BuildContext context) => Row(children: [
    Expanded(child: Text(title, style: Theme.of(context).textTheme.titleLarge?.copyWith(color: const Color(0xFF17212F), fontWeight: FontWeight.w900))),
    TextButton.icon(onPressed: onAction, icon: const Icon(Icons.arrow_back_rounded, size: 18, color: Color(0xFF087347)), label: Text(actionLabel, style: const TextStyle(color: Color(0xFF087347), fontWeight: FontWeight.w800))),
  ]);
}

IconData _marketplaceCategoryIcon(String rawName) {
  final name = rawName.toLowerCase();
  if (name.contains('لبن') || name.contains('حليب') || name.contains('ألبان') || name.contains('البان') || name.contains('dairy') || name.contains('milk')) return Icons.local_drink_outlined;
  if (name.contains('جبن') || name.contains('cheese')) return Icons.breakfast_dining_outlined;
  if (name.contains('خض') || name.contains('فاكه') || name.contains('veget') || name.contains('fruit')) return Icons.eco_outlined;
  if (name.contains('مجمد') || name.contains('frozen')) return Icons.ac_unit_rounded;
  if (name.contains('لحوم') || name.contains('دواجن') || name.contains('meat') || name.contains('chicken')) return Icons.set_meal_outlined;
  if (name.contains('مشروب') || name.contains('عصير') || name.contains('beverage') || name.contains('drink')) return Icons.local_cafe_outlined;
  if (name.contains('مخب') || name.contains('خبز') || name.contains('bakery') || name.contains('bread')) return Icons.bakery_dining_outlined;
  return Icons.shopping_basket_outlined;
}

IconData _marketplaceProductIcon(String rawName) {
  final name = rawName.toLowerCase();
  if (name.contains('لبن') || name.contains('حليب') || name.contains('milk')) return Icons.local_drink_outlined;
  if (name.contains('جبن') || name.contains('cheese')) return Icons.breakfast_dining_outlined;
  if (name.contains('مياه') || name.contains('ماء') || name.contains('water')) return Icons.water_drop_outlined;
  if (name.contains('زيت') || name.contains('oil')) return Icons.opacity_rounded;
  if (name.contains('بيض') || name.contains('egg')) return Icons.egg_outlined;
  if (name.contains('بطاط') || name.contains('خض') || name.contains('فاكه') || name.contains('veget') || name.contains('fruit')) return Icons.eco_outlined;
  return Icons.inventory_2_outlined;
}

class _WholesaleProductCard extends StatelessWidget {
  const _WholesaleProductCard({required this.product, required this.onTap});

  final Map<String, dynamic> product;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final id = _int(product['id']);
    final image = product['image_url']?.toString().trim();
    final rawPrice = product['unit_price'] ??
        product['account_price'] ??
        product['price'] ??
        '';
    final currency = product['currency']?.toString().trim();
    final sku = product['sku']?.toString().trim() ?? '';
    final name = product['name']?.toString().trim() ?? '';
    final packLabel = product['pack_label']?.toString().trim() ?? '';

    return Material(
      key: ValueKey('marketplace-product-card-$id'),
      color: Colors.white,
      borderRadius: BorderRadius.circular(18),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: onTap,
        child: DecoratedBox(
          decoration: BoxDecoration(
            border: Border.all(color: const Color(0xFFE7EAED)),
            borderRadius: BorderRadius.circular(18),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Expanded(
                child: ColoredBox(
                  color: Colors.white,
                  child: image != null && image.isNotEmpty
                      ? Padding(
                          padding: const EdgeInsets.all(8),
                          child: Image.network(
                            image,
                            key: ValueKey('marketplace-product-image-$id'),
                            fit: BoxFit.contain,
                            errorBuilder: (_, __, ___) =>
                                _MarketplaceProductFallback(name: name),
                          ),
                        )
                      : _MarketplaceProductFallback(name: name),
                ),
              ),
              Padding(
                padding: const EdgeInsetsDirectional.fromSTEB(11, 10, 9, 10),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      name,
                      key: ValueKey('marketplace-product-name-$id'),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                        color: Color(0xFF17212F),
                        fontWeight: FontWeight.w800,
                        fontSize: 13,
                        height: 1.25,
                      ),
                    ),
                    if (packLabel.isNotEmpty || sku.isNotEmpty) ...[
                      const SizedBox(height: 3),
                      Text(
                        packLabel.isNotEmpty ? packLabel : sku,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                          color: Color(0xFF89939E),
                          fontSize: 9.5,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                    ],
                    const SizedBox(height: 7),
                    Row(
                      children: [
                        Expanded(
                          child: Text(
                            '$rawPrice ${currency == null || currency.isEmpty ? 'EGP' : currency}',
                            key: ValueKey('marketplace-product-price-$id'),
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: const TextStyle(
                              color: Color(0xFF087347),
                              fontWeight: FontWeight.w900,
                              fontSize: 13,
                            ),
                          ),
                        ),
                        Container(
                          width: 32,
                          height: 32,
                          decoration: BoxDecoration(
                            color: Colors.white,
                            shape: BoxShape.circle,
                            border: Border.all(
                              color: const Color(0xFF0A7047),
                              width: 1.4,
                            ),
                          ),
                          child: const Icon(
                            Icons.add_shopping_cart_rounded,
                            color: Color(0xFF0A7047),
                            size: 17,
                          ),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _MarketplaceProductFallback extends StatelessWidget {
  const _MarketplaceProductFallback({required this.name});

  final String name;

  @override
  Widget build(BuildContext context) => Center(
        child: Container(
          width: 74,
          height: 74,
          decoration: const BoxDecoration(
            color: Color(0xFFEAF7F0),
            shape: BoxShape.circle,
          ),
          child: Icon(
            _marketplaceProductIcon(name),
            color: const Color(0xFF0A7047),
            size: 36,
          ),
        ),
      );
}

class _RegistrationSheet extends StatefulWidget {
  const _RegistrationSheet();

  @override
  State<_RegistrationSheet> createState() => _RegistrationSheetState();
}

class _RegistrationSheetState extends State<_RegistrationSheet> {
  final name = TextEditingController();
  final email = TextEditingController();
  final phone = TextEditingController();
  final password = TextEditingController();
  final confirmation = TextEditingController();

  @override
  void dispose() {
    name.dispose();
    email.dispose();
    phone.dispose();
    password.dispose();
    confirmation.dispose();
    super.dispose();
  }

  void _submit() {
    if (name.text.trim().isEmpty ||
        email.text.trim().isEmpty ||
        phone.text.trim().isEmpty ||
        password.text.length < 8 ||
        password.text != confirmation.text) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(context.tr('customer.marketplace.registration_validation'))),
      );
      return;
    }
    Navigator.pop(context, {
      'name': name.text.trim(),
      'email': email.text.trim(),
      'phone': phone.text.trim(),
      'password': password.text,
      'password_confirmation': confirmation.text,
    });
  }

  @override
  Widget build(BuildContext context) => SafeArea(
        child: Padding(
          padding: EdgeInsets.fromLTRB(
            20,
            0,
            20,
            20 + MediaQuery.viewInsetsOf(context).bottom,
          ),
          child: SingleChildScrollView(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(
                  context.tr('customer.marketplace.register_title'),
                  style: Theme.of(context).textTheme.titleLarge?.copyWith(
                        fontWeight: FontWeight.w900,
                      ),
                ),
                const SizedBox(height: 6),
                Text(context.tr('customer.marketplace.register_subtitle')),
                const SizedBox(height: 16),
                TextField(controller: name, decoration: InputDecoration(labelText: context.tr('customer.settings.name'))),
                const SizedBox(height: 10),
                TextField(controller: email, keyboardType: TextInputType.emailAddress, decoration: InputDecoration(labelText: context.tr('customer.login.email'))),
                const SizedBox(height: 10),
                TextField(controller: phone, keyboardType: TextInputType.phone, decoration: InputDecoration(labelText: context.tr('customer.marketplace.phone'))),
                const SizedBox(height: 10),
                TextField(controller: password, obscureText: true, decoration: InputDecoration(labelText: context.tr('customer.login.password'))),
                const SizedBox(height: 10),
                TextField(controller: confirmation, obscureText: true, decoration: InputDecoration(labelText: context.tr('customer.marketplace.password_confirmation'))),
                const SizedBox(height: 18),
                FilledButton(
                  onPressed: _submit,
                  child: Text(context.tr('customer.marketplace.create_account')),
                ),
              ],
            ),
          ),
        ),
      );
}

List<Map<String, dynamic>> _rows(Object? value) {
  if (value is! List) return const [];
  return value
      .whereType<Map>()
      .map((row) => Map<String, dynamic>.from(row))
      .toList(growable: false);
}

int _int(Object? value) => value is num ? value.toInt() : int.tryParse('$value') ?? 0;

class _MarketplaceException implements Exception {
  const _MarketplaceException(this.code);
  final String code;
}
