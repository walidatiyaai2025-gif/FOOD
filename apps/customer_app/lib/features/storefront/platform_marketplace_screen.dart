import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;

import '../../core/auth/customer_session.dart';
import '../../core/config/foodex_environment.dart';
import '../../core/localization/app_translations.dart';
import '../../shared/customer_action_widgets.dart';

class PlatformMarketplaceScreen extends StatefulWidget {
  const PlatformMarketplaceScreen({
    required this.session,
    required this.onAuthenticated,
    super.key,
    this.client,
  });

  final CustomerSession session;
  final CustomerAuthenticated onAuthenticated;
  final http.Client? client;

  @override
  State<PlatformMarketplaceScreen> createState() => _PlatformMarketplaceScreenState();
}

class _PlatformMarketplaceScreenState extends State<PlatformMarketplaceScreen> {
  late final http.Client _client = widget.client ?? http.Client();
  late Future<Map<String, dynamic>> _future = _load();

  Future<Map<String, dynamic>> _load() async {
    final baseUrl = FoodexEnvironment.apiBaseUrl;
    final marketplace = await _get('${baseUrl}/api/v1/marketplace');
    final wholesale = marketplace['main_wholesale_store'] is Map
        ? Map<String, dynamic>.from(marketplace['main_wholesale_store'] as Map)
        : <String, dynamic>{};
    final storeId = _int(wholesale['id']);
    if (storeId <= 0) {
      throw const _MarketplaceException('missing_wholesale_store');
    }

    final storefront = await _get(
      '${baseUrl}/api/v1/wholesale/stores/$storeId/storefront',
    );

    return {
      ...marketplace,
      'wholesale_storefront': storefront,
    };
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

      widget.onAuthenticated(CustomerChannel.b2b, token);
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(context.tr('customer.marketplace.registration_success'))),
      );
      setState(() => _future = _load());
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

  void _openWholesaleProduct(int storeId, int productId) {
    if (!widget.session.isAuthenticated) {
      _showAuthRequired();
      return;
    }
    Navigator.of(context).pushNamed(
      '/b2b/products/$productId?store_id=$storeId',
    );
  }

  void _showAuthRequired() {
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
                  Navigator.of(context).pushNamed('/b2b/login');
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
              final wholesale = data['main_wholesale_store'] is Map
                  ? Map<String, dynamic>.from(data['main_wholesale_store'] as Map)
                  : <String, dynamic>{};
              final storefront = data['wholesale_storefront'] is Map
                  ? Map<String, dynamic>.from(data['wholesale_storefront'] as Map)
                  : <String, dynamic>{};
              final retail = _rows(data['retail_stores']);
              final products = _rows(storefront['products']);
              final hero = storefront['hero'] is Map
                  ? Map<String, dynamic>.from(storefront['hero'] as Map)
                  : <String, dynamic>{};
              final storeId = _int(wholesale['id']);

              return LayoutBuilder(
                builder: (context, constraints) {
                  final retailHeight = (constraints.maxHeight * .20)
                      .clamp(118.0, 176.0)
                      .toDouble();

                  return CustomScrollView(
                    slivers: [
                      SliverToBoxAdapter(
                        child: _MarketplaceHeader(
                          storeName: wholesale['name']?.toString() ?? 'FOODEX',
                          authenticated: widget.session.isAuthenticated,
                          onRegister: _register,
                          onLogin: () => Navigator.of(context).pushNamed('/b2b/login'),
                        ),
                      ),
                      if (retail.isNotEmpty)
                        SliverToBoxAdapter(
                          child: SizedBox(
                            height: retailHeight,
                            child: ListView.separated(
                              padding: const EdgeInsets.fromLTRB(14, 8, 14, 10),
                              scrollDirection: Axis.horizontal,
                              itemCount: retail.length,
                              separatorBuilder: (_, __) => const SizedBox(width: 10),
                              itemBuilder: (_, index) {
                                final store = retail[index];
                                return _RetailStoreBanner(
                                  store: store,
                                  width: (constraints.maxWidth * .72)
                                      .clamp(240.0, 330.0)
                                      .toDouble(),
                                  onTap: () => _openRetail(store),
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
    required this.storeName,
    required this.authenticated,
    required this.onRegister,
    required this.onLogin,
  });

  final String storeName;
  final bool authenticated;
  final VoidCallback onRegister;
  final VoidCallback onLogin;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.fromLTRB(16, 14, 16, 4),
        child: Row(
          children: [
            const CircleAvatar(
              radius: 22,
              backgroundColor: Color(0xFFE9F8EF),
              child: Icon(Icons.storefront_rounded, color: Color(0xFF087347)),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    storeName,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w900),
                  ),
                  Text(
                    context.tr('customer.marketplace.main_wholesale'),
                    style: const TextStyle(color: Color(0xFF667085), fontSize: 12),
                  ),
                ],
              ),
            ),
            if (!authenticated) ...[
              TextButton(onPressed: onLogin, child: Text(context.tr('customer.action.login'))),
              FilledButton(onPressed: onRegister, child: Text(context.tr('customer.marketplace.register'))),
            ] else
              IconButton(
                onPressed: () => Navigator.of(context).pushNamed('/profile'),
                icon: const Icon(Icons.person_outline_rounded),
              ),
          ],
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
    final image = store['banner_image_url']?.toString();
    final logo = store['logo_url']?.toString();
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
                      child: Text(
                        store['name']?.toString() ?? '',
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                          color: Colors.white,
                          fontWeight: FontWeight.w900,
                          fontSize: 16,
                        ),
                      ),
                    ),
                    const Icon(Icons.chevron_right_rounded, color: Colors.white),
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
