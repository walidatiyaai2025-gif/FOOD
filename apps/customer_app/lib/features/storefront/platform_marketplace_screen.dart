import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;

import '../../core/auth/customer_session.dart';
import '../../core/config/foodex_environment.dart';
import '../../core/localization/app_translations.dart';

class PlatformMarketplaceScreen extends StatefulWidget {
  const PlatformMarketplaceScreen({
    required this.session,
    required this.onPlatformRegistered,
    super.key,
    this.client,
    this.onLocaleChanged,
  });

  final CustomerSession session;
  final ValueChanged<String> onPlatformRegistered;
  final http.Client? client;
  final ValueChanged<Locale>? onLocaleChanged;

  @override
  State<PlatformMarketplaceScreen> createState() => _PlatformMarketplaceScreenState();
}

class _PlatformMarketplaceScreenState extends State<PlatformMarketplaceScreen> {
  late final http.Client _client = widget.client ?? http.Client();
  late Future<Map<String, dynamic>> _future = _load();
  final PageController _retailController = PageController(viewportFraction: .88);
  final TextEditingController _searchController = TextEditingController();
  final FocusNode _searchFocus = FocusNode();
  Timer? _retailTimer;
  int _retailIndex = 0;
  int _retailCount = -1;
  int? _selectedCategoryId;
  String? _pendingAfterAuth;

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
    return _get(uri.toString());
  }

  void _submitSearch(String _) {
    setState(() {
      _future = _load();
    });
  }

  void _focusBarcodeSearch() {
    _searchFocus.requestFocus();
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

  void _startRetailAutoSlide(int count) {
    if (_retailCount == count) return;
    _retailCount = count;
    _retailTimer?.cancel();
    if (count <= 1) return;
    _retailTimer = Timer.periodic(const Duration(seconds: 5), (_) {
      if (!mounted || !_retailController.hasClients) return;
      _retailIndex = (_retailIndex + 1) % count;
      _retailController.animateToPage(
        _retailIndex,
        duration: const Duration(milliseconds: 420),
        curve: Curves.easeOutCubic,
      );
    });
  }

  @override
  void dispose() {
    _retailTimer?.cancel();
    _retailController.dispose();
    _searchController.dispose();
    _searchFocus.dispose();
    if (widget.client == null) {
      _client.close();
    }
    super.dispose();
  }

  Future<Map<String, dynamic>> _get(String url) async {
    final response = await _client.get(
      Uri.parse(url),
      headers: const {'Accept': 'application/json'},
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

  void _openRetail(Map<String, dynamic> store) {
    final id = _int(store['id']);
    if (id <= 0) return;
    Navigator.of(context).pushNamed('/retail/$id/home');
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
        backgroundColor: const Color(0xFFF8FAF8),
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
              WidgetsBinding.instance.addPostFrameCallback((_) {
                _startRetailAutoSlide(retail.length);
              });

              return LayoutBuilder(
                builder: (context, constraints) {
                  final retailHeight = (constraints.maxHeight * .20)
                      .clamp(118.0, 176.0)
                      .toDouble();

                  return CustomScrollView(
                    slivers: [
                      SliverToBoxAdapter(
                        child: _MarketplaceHeader(
                          authenticated: widget.session.isAuthenticated,
                          onRegister: _register,
                          onLogin: () => Navigator.of(context).pushNamed('/auth/checkout?next=/marketplace'),
                          onScan: _focusBarcodeSearch,
                          onLanguageToggle: _toggleLocale,
                          localeCode: Localizations.localeOf(context).languageCode,
                          onCart: () => Navigator.of(context).pushNamed('/cart'),
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
                        ),
                      ),
                      SliverToBoxAdapter(
                        child: Padding(
                          padding: const EdgeInsets.fromLTRB(14, 8, 14, 4),
                          child: TextField(
                            key: const ValueKey('marketplace-search'),
                            controller: _searchController,
                            focusNode: _searchFocus,
                            textInputAction: TextInputAction.search,
                            onSubmitted: _submitSearch,
                            decoration: InputDecoration(
                              hintText: context.tr('customer.marketplace.search_hint'),
                              prefixIcon: const Icon(Icons.search_rounded),
                              suffixIcon: IconButton(
                                onPressed: () {
                                  _searchController.clear();
                                  _submitSearch('');
                                },
                                icon: const Icon(Icons.close_rounded),
                              ),
                            ),
                          ),
                        ),
                      ),
                      if (retail.isNotEmpty)
                        SliverToBoxAdapter(
                          child: SizedBox(
                            height: retailHeight,
                            child: PageView.builder(
                              key: const ValueKey('marketplace-retail-carousel'),
                              controller: _retailController,
                              itemCount: retail.length,
                              onPageChanged: (index) => _retailIndex = index,
                              padEnds: false,
                              itemBuilder: (_, index) {
                                final store = retail[index];
                                return Padding(
                                  padding: EdgeInsetsDirectional.only(
                                    start: index == 0 ? 14 : 5,
                                    end: 5,
                                    top: 8,
                                    bottom: 10,
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
                        ),
                      SliverToBoxAdapter(
                        child: _WholesaleHero(
                          title: wholesale['name']?.toString() ?? 'متجر الجملة',
                          imageUrl: hero['image_url']?.toString(),
                        ),
                      ),
                      if (offers.isNotEmpty)
                        SliverToBoxAdapter(
                          child: Padding(
                            padding: const EdgeInsets.fromLTRB(14, 12, 14, 2),
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  context.tr('customer.home.offers'),
                                  style: Theme.of(context)
                                      .textTheme
                                      .titleMedium
                                      ?.copyWith(fontWeight: FontWeight.w900),
                                ),
                                const SizedBox(height: 8),
                                SizedBox(
                                  height: 76,
                                  child: ListView.separated(
                                    key: const ValueKey(
                                      'marketplace-wholesale-offers',
                                    ),
                                    scrollDirection: Axis.horizontal,
                                    itemCount: offers.length,
                                    separatorBuilder: (_, __) =>
                                        const SizedBox(width: 8),
                                    itemBuilder: (_, index) {
                                      final offer = offers[index];
                                      final value = offer['value'];
                                      final type = offer['type']?.toString();
                                      final valueText = value == null
                                          ? ''
                                          : type == 'percentage'
                                              ? '$value%'
                                              : type == 'fixed'
                                                  ? 'EGP $value'
                                                  : '$value';
                                      return Container(
                                        constraints:
                                            const BoxConstraints(minWidth: 170),
                                        padding: const EdgeInsets.all(12),
                                        decoration: BoxDecoration(
                                          color: const Color(0xFFF4ECFB),
                                          borderRadius:
                                              BorderRadius.circular(16),
                                        ),
                                        child: Column(
                                          crossAxisAlignment:
                                              CrossAxisAlignment.start,
                                          mainAxisAlignment:
                                              MainAxisAlignment.center,
                                          children: [
                                            Text(
                                              offer['name']?.toString() ?? '',
                                              maxLines: 1,
                                              overflow: TextOverflow.ellipsis,
                                              style: const TextStyle(
                                                fontWeight: FontWeight.w900,
                                              ),
                                            ),
                                            if (valueText.isNotEmpty)
                                              Text(
                                                valueText,
                                                style: const TextStyle(
                                                  color: Color(0xFF6B3A8E),
                                                  fontSize: 11,
                                                  fontWeight: FontWeight.w700,
                                                ),
                                              ),
                                          ],
                                        ),
                                      );
                                    },
                                  ),
                                ),
                              ],
                            ),
                          ),
                        ),
                      if (categories.isNotEmpty)
                        SliverToBoxAdapter(
                          child: SizedBox(
                            height: 54,
                            child: ListView(
                              scrollDirection: Axis.horizontal,
                              padding: const EdgeInsets.symmetric(
                                horizontal: 14,
                                vertical: 7,
                              ),
                              children: [
                                ChoiceChip(
                                  key: const ValueKey('marketplace-category-all'),
                                  label: Text(
                                    context.tr('customer.marketplace.all_categories'),
                                  ),
                                  selected: _selectedCategoryId == null,
                                  onSelected: (_) => _selectCategory(null),
                                ),
                                const SizedBox(width: 8),
                                ...categories.map(
                                  (category) => Padding(
                                    padding: const EdgeInsetsDirectional.only(
                                      end: 8,
                                    ),
                                    child: ChoiceChip(
                                      key: ValueKey(
                                        'marketplace-category-${category['id']}',
                                      ),
                                      label: Text(
                                        category['name']?.toString() ?? '',
                                      ),
                                      selected: _selectedCategoryId ==
                                          _int(category['id']),
                                      onSelected: (_) => _selectCategory(
                                        _int(category['id']),
                                      ),
                                    ),
                                  ),
                                ),
                              ],
                            ),
                          ),
                        ),
                      SliverPadding(
                        padding: const EdgeInsets.fromLTRB(14, 14, 14, 8),
                        sliver: SliverToBoxAdapter(
                          child: Row(
                            children: [
                              Expanded(
                                child: Text(
                                  context.tr('customer.marketplace.wholesale_products'),
                                  style: Theme.of(context).textTheme.titleLarge?.copyWith(
                                        fontWeight: FontWeight.w900,
                                      ),
                                ),
                              ),
                              if (!widget.session.isAuthenticated)
                                TextButton(
                                  onPressed: _register,
                                  child: Text(context.tr('customer.marketplace.register')),
                                ),
                            ],
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
                              childAspectRatio: constraints.maxWidth >= 700 ? 1.05 : .78,
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
  });

  final bool authenticated;
  final VoidCallback onRegister;
  final VoidCallback onLogin;
  final VoidCallback onScan;
  final VoidCallback onLanguageToggle;
  final String localeCode;
  final VoidCallback onCart;
  final VoidCallback onNotifications;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.fromLTRB(16, 14, 16, 4),
        child: LayoutBuilder(
          builder: (context, constraints) {
            final compact = constraints.maxWidth < 520;
            return Row(
              children: [
                CircleAvatar(
                  radius: compact ? 18 : 22,
                  backgroundColor: const Color(0xFFE9F8EF),
                  child: const Icon(
                    Icons.storefront_rounded,
                    color: Color(0xFF087347),
                  ),
                ),
                SizedBox(width: compact ? 6 : 10),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text(
                        'FOODEX',
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: TextStyle(
                          fontSize: 18,
                          fontWeight: FontWeight.w900,
                        ),
                      ),
                      Text(
                        context.tr('customer.marketplace.browse_guest'),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                          color: Color(0xFF667085),
                          fontSize: 12,
                        ),
                      ),
                    ],
                  ),
                ),
                IconButton(
                  key: const ValueKey('marketplace-scan'),
                  visualDensity:
                      compact ? VisualDensity.compact : VisualDensity.standard,
                  constraints: compact
                      ? const BoxConstraints.tightFor(width: 38, height: 38)
                      : null,
                  padding: compact ? const EdgeInsets.all(6) : null,
                  tooltip: context.tr('customer.marketplace.scan'),
                  onPressed: onScan,
                  icon: const Icon(Icons.qr_code_scanner_rounded),
                ),
                TextButton(
                  key: const ValueKey('marketplace-language'),
                  onPressed: onLanguageToggle,
                  style: compact
                      ? TextButton.styleFrom(
                          minimumSize: const Size(38, 38),
                          padding: EdgeInsets.zero,
                          tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                        )
                      : null,
                  child: Text(localeCode.toUpperCase()),
                ),
                IconButton(
                  key: const ValueKey('marketplace-cart'),
                  visualDensity:
                      compact ? VisualDensity.compact : VisualDensity.standard,
                  constraints: compact
                      ? const BoxConstraints.tightFor(width: 38, height: 38)
                      : null,
                  padding: compact ? const EdgeInsets.all(6) : null,
                  tooltip: context.tr('customer.nav.cart'),
                  onPressed: onCart,
                  icon: const Icon(Icons.shopping_cart_outlined),
                ),
                IconButton(
                  key: const ValueKey('marketplace-notifications'),
                  visualDensity:
                      compact ? VisualDensity.compact : VisualDensity.standard,
                  constraints: compact
                      ? const BoxConstraints.tightFor(width: 38, height: 38)
                      : null,
                  padding: compact ? const EdgeInsets.all(6) : null,
                  tooltip: context.tr('customer.nav.notifications'),
                  onPressed: onNotifications,
                  icon: const Icon(Icons.notifications_none_rounded),
                ),
                if (authenticated)
                  IconButton(
                    key: const ValueKey('marketplace-profile'),
                    onPressed: () =>
                        Navigator.of(context).pushNamed('/profile'),
                    icon: const Icon(Icons.person_outline_rounded),
                  )
                else if (compact)
                  SizedBox(
                    width: 38,
                    height: 38,
                    child: PopupMenuButton<String>(
                      key: const ValueKey('marketplace-auth-menu'),
                      padding: EdgeInsets.zero,
                      iconSize: 22,
                      icon: const Icon(Icons.account_circle_outlined),
                      onSelected: (value) {
                        if (value == 'login') {
                          onLogin();
                        } else {
                          onRegister();
                        }
                      },
                      itemBuilder: (_) => [
                        PopupMenuItem(
                          value: 'login',
                          child: Text(context.tr('customer.action.login')),
                        ),
                        PopupMenuItem(
                          value: 'register',
                          child: Text(
                            context.tr('customer.marketplace.register'),
                          ),
                        ),
                      ],
                    ),
                  )
                else ...[
                  TextButton(
                    onPressed: onLogin,
                    child: Text(context.tr('customer.action.login')),
                  ),
                  FilledButton(
                    onPressed: onRegister,
                    child: Text(context.tr('customer.marketplace.register')),
                  ),
                ],
              ],
            );
          },
        ),
      );
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
    final image = store['banner_url']?.toString();
    final logo = store['logo_url']?.toString();
    final address = store['address']?.toString().trim() ?? '';
    return SizedBox(
      width: width,
      child: Material(
        color: Colors.white,
        borderRadius: BorderRadius.circular(18),
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: onTap,
          child: Stack(
            fit: StackFit.expand,
            children: [
              if (image != null && image.isNotEmpty)
                Image.network(image, fit: BoxFit.cover)
              else
                const DecoratedBox(
                  decoration: BoxDecoration(
                    gradient: LinearGradient(
                      colors: [Color(0xFF087347), Color(0xFF13A66A)],
                    ),
                  ),
                ),
              DecoratedBox(
                decoration: BoxDecoration(
                  gradient: LinearGradient(
                    begin: Alignment.bottomCenter,
                    end: Alignment.topCenter,
                    colors: [Colors.black54, Colors.transparent],
                  ),
                ),
              ),
              PositionedDirectional(
                start: 14,
                end: 14,
                bottom: 12,
                child: Row(
                  children: [
                    if (logo != null && logo.isNotEmpty) ...[
                      CircleAvatar(backgroundImage: NetworkImage(logo), radius: 18),
                      const SizedBox(width: 9),
                    ],
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Text(
                            store['title']?.toString() ??
                                store['name']?.toString() ??
                                '',
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: const TextStyle(
                              color: Colors.white,
                              fontWeight: FontWeight.w900,
                              fontSize: 16,
                            ),
                          ),
                          if (address.isNotEmpty)
                            Text(
                              address,
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
                    const SizedBox(width: 8),
                    Container(
                      padding: const EdgeInsets.symmetric(
                        horizontal: 10,
                        vertical: 6,
                      ),
                      decoration: BoxDecoration(
                        color: Colors.white,
                        borderRadius: BorderRadius.circular(999),
                      ),
                      child: Text(
                        context.tr('customer.marketplace.shop_now'),
                        style: const TextStyle(
                          color: Color(0xFF087347),
                          fontWeight: FontWeight.w800,
                          fontSize: 11,
                        ),
                      ),
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

class _WholesaleHero extends StatelessWidget {
  const _WholesaleHero({required this.title, this.imageUrl});
  final String title;
  final String? imageUrl;

  @override
  Widget build(BuildContext context) => Container(
        height: 150,
        margin: const EdgeInsets.fromLTRB(14, 6, 14, 2),
        padding: const EdgeInsets.all(18),
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(22),
          gradient: const LinearGradient(
            colors: [Color(0xFF5D2A91), Color(0xFF35195E)],
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
                      fontWeight: FontWeight.w900,
                      fontSize: 22,
                    ),
                  ),
                  const SizedBox(height: 8),
                  Text(
                    context.tr('customer.marketplace.browse_guest'),
                    style: const TextStyle(color: Colors.white70),
                  ),
                ],
              ),
            ),
            if (imageUrl != null && imageUrl!.isNotEmpty)
              ClipRRect(
                borderRadius: BorderRadius.circular(16),
                child: Image.network(
                  imageUrl!,
                  width: 110,
                  height: 110,
                  fit: BoxFit.cover,
                ),
              )
            else
              const Icon(Icons.warehouse_rounded, color: Colors.white38, size: 72),
          ],
        ),
      );
}

class _WholesaleProductCard extends StatelessWidget {
  const _WholesaleProductCard({required this.product, required this.onTap});
  final Map<String, dynamic> product;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final image = product['image_url']?.toString();
    return Card(
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: onTap,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Expanded(
              child: ColoredBox(
                color: const Color(0xFFF2F4F7),
                child: image != null && image.isNotEmpty
                    ? Image.network(image, fit: BoxFit.cover)
                    : const Icon(Icons.inventory_2_outlined, size: 48),
              ),
            ),
            Padding(
              padding: const EdgeInsets.all(10),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    product['name']?.toString() ?? '',
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(fontWeight: FontWeight.w800),
                  ),
                  const SizedBox(height: 5),
                  Text(
                    '${product['price'] ?? ''} ${product['currency'] ?? 'EGP'}',
                    style: const TextStyle(
                      color: Color(0xFF087347),
                      fontWeight: FontWeight.w900,
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
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
