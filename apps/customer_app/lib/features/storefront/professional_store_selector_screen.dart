import 'package:flutter/material.dart';

import '../../core/api/b2c_account_api.dart';
import '../../core/api/b2c_catalog_api.dart';
import '../../core/api/storefront_api.dart';
import '../../core/auth/customer_session.dart';
import '../../core/localization/app_translations.dart';

typedef StoreWholesaleContextCallback = void Function(int? retailStoreId);

enum StoreSelectorChannel { wholesale, retail }

class ProfessionalStoreSelectorScreen extends StatefulWidget {
  const ProfessionalStoreSelectorScreen({
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
  final StoreWholesaleContextCallback enterWholesale;

  @override
  State<ProfessionalStoreSelectorScreen> createState() => _ProfessionalStoreSelectorScreenState();
}

class _ProfessionalStoreSelectorScreenState extends State<ProfessionalStoreSelectorScreen> {
  late Future<Map<String, dynamic>> _future = _load();
  StoreSelectorChannel _channel = StoreSelectorChannel.wholesale;

  bool get _rtl => Directionality.of(context) == TextDirection.rtl;
  bool get _ar => Localizations.localeOf(context).languageCode == 'ar';
  String t(String ar, String en) => _ar ? ar : en;

  Future<Map<String, dynamic>> _load() async {
    if (widget.storefrontApi == null) {
      final stores = await widget.catalogApi.stores();
      return {
        'retail_stores': stores.map((s) => {
          'id': s.id, 'name': s.name, 'code': s.code, 'theme_code': s.themeCode,
          'address': s.address, 'logo_url': s.logoUrl, 'channel': 'b2c', 'is_active': true,
        }).toList(growable: false),
        'wholesale_stores': const <Object>[],
      };
    }

    String? countryCode;
    String? city;
    String? area;
    if (widget.session.isAuthenticated) {
      try {
        final raw = await widget.accountApi.addresses();
        final rows = _rows(raw is Map ? raw['data'] : raw);
        if (rows.isNotEmpty) {
          final address = rows.firstWhere(
            (e) => e['is_default'] == true || e['is_default'] == 1,
            orElse: () => rows.first,
          );
          countryCode = address['country_code']?.toString();
          city = address['city']?.toString();
          area = address['area']?.toString();
        }
      } catch (_) {}
    }
    return widget.storefrontApi!.selection(countryCode: countryCode, city: city, area: area);
  }

  void _retry() => setState(() => _future = _load());

  Future<int?> _chooseRetailContext(List<int> ids, List<Map<String, dynamic>> retail) async {
    if (ids.isEmpty) return null;
    if (ids.length == 1) return ids.first;
    final names = {for (final s in retail) _int(s['id']): s['name']?.toString() ?? ''};
    return showModalBottomSheet<int>(
      context: context,
      showDragHandle: true,
      builder: (ctx) => SafeArea(
        child: Directionality(
          textDirection: _rtl ? TextDirection.rtl : TextDirection.ltr,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(20, 0, 20, 20),
            child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              Text(t('اختر متجر التجزئة المستلم', 'Choose receiving retail store'),
                  style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
              const SizedBox(height: 8),
              Text(t('سيتم ربط طلب الجملة ومخزون الاستلام بهذا المتجر.',
                  'The wholesale order and receiving inventory will be linked to this store.')),
              const SizedBox(height: 12),
              ...ids.map((id) => ListTile(
                minTileHeight: 48,
                leading: const Icon(Icons.storefront_rounded),
                title: Text(names[id]?.isNotEmpty == true ? names[id]! : t('متجر #$id', 'Store #$id')),
                onTap: () => Navigator.pop(ctx, id),
              )),
            ]),
          ),
        ),
      ),
    );
  }

  Future<void> _open(Map<String, dynamic> store, List<Map<String, dynamic>> retail) async {
    if (!_isOpen(store)) return;
    final id = _int(store['id']);
    if (id <= 0) return;

    if (_channel == StoreSelectorChannel.retail) {
      await Navigator.of(context).pushNamed('/retail/$id/home');
      return;
    }

    final contexts = (store['retail_context_ids'] as List? ?? const [])
        .map(_int).where((v) => v > 0).toList(growable: false);
    final retailContext = await _chooseRetailContext(contexts, retail);
    if (contexts.isNotEmpty && retailContext == null) return;
    widget.enterWholesale(retailContext);
    var route = '/b2b/home?store_id=$id';
    if (retailContext != null) route += '&retail_store_id=$retailContext';
    if (mounted) Navigator.of(context).pushNamed(route);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFFF9FCFA),
      body: SafeArea(
        child: FutureBuilder<Map<String, dynamic>>(
          future: _future,
          builder: (context, snapshot) {
            if (snapshot.connectionState != ConnectionState.done) {
              return const _SelectorSkeleton();
            }
            if (snapshot.hasError) {
              return _SelectorState(
                icon: Icons.cloud_off_rounded,
                title: t('تعذر تحميل المتاجر', 'Could not load stores'),
                subtitle: t('يرجى المحاولة مرة أخرى', 'Please try again'),
                button: context.tr('customer.action.retry'),
                onPressed: _retry,
              );
            }

            final data = snapshot.data ?? const <String, dynamic>{};
            final retail = _rows(data['retail_stores']);
            final wholesale = _rows(data['wholesale_stores']);
            final visible = _channel == StoreSelectorChannel.wholesale ? wholesale : retail;

            return LayoutBuilder(builder: (context, c) {
              final side = c.maxWidth < 380 ? 16.0 : 20.0;
              return Stack(children: [
                PositionedDirectional(
                  top: 4, end: -44,
                  child: Container(width: 170, height: 170,
                    decoration: const BoxDecoration(shape: BoxShape.circle, color: Color(0xFFE9F8EF))),
                ),
                CustomScrollView(
                  physics: const BouncingScrollPhysics(),
                  slivers: [
                    SliverPadding(
                      padding: EdgeInsets.fromLTRB(side, 18, side, 12),
                      sliver: SliverToBoxAdapter(child: Column(children: [
                        const StoreSelectorHeader(),
                        const SizedBox(height: 24),
                        StoreTypeSegmentedControl(
                          selected: _channel,
                          onChanged: (value) => setState(() => _channel = value),
                        ),
                      ])),
                    ),
                    if (visible.isEmpty)
                      SliverFillRemaining(
                        hasScrollBody: false,
                        child: _SelectorState(
                          icon: Icons.storefront_outlined,
                          title: t('لا توجد متاجر متاحة حاليًا', 'No stores are currently available'),
                          subtitle: t('جرّب مرة أخرى بعد قليل.', 'Please try again shortly.'),
                          button: context.tr('customer.action.retry'),
                          onPressed: _retry,
                        ),
                      )
                    else
                      SliverPadding(
                        padding: EdgeInsets.fromLTRB(side, 8, side, 36),
                        sliver: SliverList.separated(
                          itemCount: visible.length,
                          separatorBuilder: (_, __) => const SizedBox(height: 18),
                          itemBuilder: (_, index) => StoreCard(
                            store: visible[index],
                            wholesale: _channel == StoreSelectorChannel.wholesale,
                            onTap: () => _open(visible[index], retail),
                          ),
                        ),
                      ),
                  ],
                ),
              ]);
            });
          },
        ),
      ),
    );
  }
}

class StoreSelectorHeader extends StatelessWidget {
  const StoreSelectorHeader({super.key});

  @override
  Widget build(BuildContext context) {
    final ar = Localizations.localeOf(context).languageCode == 'ar';
    return Column(children: [
      Row(mainAxisAlignment: MainAxisAlignment.center, children: [
        Container(
          width: 50, height: 50,
          decoration: BoxDecoration(color: const Color(0xFF009B4D), borderRadius: BorderRadius.circular(16)),
          child: const Icon(Icons.shopping_bag_rounded, color: Colors.white, size: 28),
        ),
        const SizedBox(width: 11),
        const Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text('FOODEX', style: TextStyle(color: Color(0xFF071B37), fontSize: 25, fontWeight: FontWeight.w900, letterSpacing: .5)),
          Text('MULTI STORE', style: TextStyle(color: Color(0xFF778393), fontSize: 9, fontWeight: FontWeight.w700, letterSpacing: 2.1)),
        ]),
      ]),
      const SizedBox(height: 25),
      Text(context.tr('customer.store.title'), textAlign: TextAlign.center,
        style: const TextStyle(color: Color(0xFF071B37), fontSize: 32, height: 1.2, fontWeight: FontWeight.w900)),
      const SizedBox(height: 8),
      Text(ar ? 'تسوق من المتاجر المتاحة حسب نوع الخدمة' : 'Shop available stores by service type',
        textAlign: TextAlign.center,
        style: const TextStyle(color: Color(0xFF778393), fontSize: 15, height: 1.5, fontWeight: FontWeight.w500)),
    ]);
  }
}

class StoreTypeSegmentedControl extends StatelessWidget {
  const StoreTypeSegmentedControl({required this.selected, required this.onChanged, super.key});
  final StoreSelectorChannel selected;
  final ValueChanged<StoreSelectorChannel> onChanged;

  @override
  Widget build(BuildContext context) {
    final ar = Localizations.localeOf(context).languageCode == 'ar';
    Widget item(StoreSelectorChannel value, String arText, String enText, IconData icon) {
      final active = selected == value;
      return Expanded(child: Semantics(
        button: true, selected: active, label: ar ? arText : enText,
        child: InkWell(
          onTap: () => onChanged(value),
          borderRadius: BorderRadius.circular(34),
          child: AnimatedContainer(
            duration: const Duration(milliseconds: 190),
            curve: Curves.easeOutCubic,
            height: 50,
            decoration: BoxDecoration(
              color: active ? const Color(0xFF009B4D) : Colors.transparent,
              borderRadius: BorderRadius.circular(34),
              boxShadow: active ? const [BoxShadow(color: Color(0x22009B4D), blurRadius: 18, offset: Offset(0, 6))] : null,
            ),
            child: Row(mainAxisAlignment: MainAxisAlignment.center, children: [
              Icon(icon, size: 20, color: active ? Colors.white : const Color(0xFF071B37)),
              const SizedBox(width: 8),
              Text(ar ? arText : enText, maxLines: 1,
                style: TextStyle(color: active ? Colors.white : const Color(0xFF071B37), fontSize: 16, fontWeight: FontWeight.w800)),
            ]),
          ),
        ),
      ));
    }

    return Container(
      height: 58, padding: const EdgeInsets.all(4),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(34),
        border: Border.all(color: const Color(0xFFD7EAE0)),
        boxShadow: const [BoxShadow(color: Color(0x10071B37), blurRadius: 18, offset: Offset(0, 6))]),
      child: Row(children: [
        item(StoreSelectorChannel.wholesale, 'جملة', 'Wholesale', Icons.store_rounded),
        const SizedBox(width: 4),
        item(StoreSelectorChannel.retail, 'التجزئة', 'Retail', Icons.shopping_cart_rounded),
      ]),
    );
  }
}

class StoreCard extends StatelessWidget {
  const StoreCard({required this.store, required this.wholesale, required this.onTap, super.key});
  final Map<String, dynamic> store;
  final bool wholesale;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final ar = Localizations.localeOf(context).languageCode == 'ar';
    final pharmacy = _isPharmacy(store);
    final open = _isOpen(store);
    final status = _status(store, ar);
    final address = _location(store, ar);
    final description = _description(store, wholesale: wholesale, pharmacy: pharmacy, ar: ar);
    final logo = store['logo_url']?.toString();

    return Semantics(
      button: open,
      label: store['name']?.toString() ?? '',
      child: Container(
        constraints: const BoxConstraints(minHeight: 176),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(28),
          border: Border.all(color: const Color(0xFFD7EAE0)),
          boxShadow: const [BoxShadow(color: Color(0x12071B37), blurRadius: 24, offset: Offset(0, 10))],
        ),
        clipBehavior: Clip.antiAlias,
        child: Material(
          color: Colors.transparent,
          child: InkWell(
            onTap: open ? onTap : null,
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: LayoutBuilder(builder: (context, c) {
                final compact = c.maxWidth < 330;
                return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                  Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    StoreArtwork(logoUrl: logo, pharmacy: pharmacy, wholesale: wholesale),
                    const SizedBox(width: 14),
                    Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(store['name']?.toString() ?? '',
                        maxLines: 2, overflow: TextOverflow.ellipsis,
                        style: const TextStyle(color: Color(0xFF071B37), fontSize: 20, height: 1.2, fontWeight: FontWeight.w900)),
                      const SizedBox(height: 6),
                      Text(description, maxLines: 2, overflow: TextOverflow.ellipsis,
                        style: const TextStyle(color: Color(0xFF778393), fontSize: 13, height: 1.45, fontWeight: FontWeight.w500)),
                      const SizedBox(height: 10),
                      Wrap(spacing: 12, runSpacing: 7, children: [
                        _Meta(icon: Icons.location_on_outlined, text: address),
                        _StoreStatusBadge(label: status.$1, kind: status.$2),
                      ]),
                    ])),
                  ]),
                  const SizedBox(height: 14),
                  Align(
                    alignment: AlignmentDirectional.centerEnd,
                    child: StoreCTA(enabled: open, compact: compact, onTap: onTap),
                  ),
                ]);
              }),
            ),
          ),
        ),
      ),
    );
  }
}

class StoreArtwork extends StatelessWidget {
  const StoreArtwork({required this.logoUrl, required this.pharmacy, required this.wholesale, super.key});
  final String? logoUrl;
  final bool pharmacy;
  final bool wholesale;

  @override
  Widget build(BuildContext context) {
    final fallback = Center(
      child: Icon(
        wholesale ? Icons.warehouse_rounded : (pharmacy ? Icons.local_pharmacy_rounded : Icons.local_grocery_store_rounded),
        size: 54,
        color: const Color(0xFF009B4D),
      ),
    );
    return Container(
      width: 112, height: 112,
      decoration: BoxDecoration(color: const Color(0xFFF2FBF6), borderRadius: BorderRadius.circular(22)),
      clipBehavior: Clip.antiAlias,
      child: logoUrl != null && logoUrl!.trim().isNotEmpty
          ? Image.network(logoUrl!, fit: BoxFit.cover, cacheWidth: 280, cacheHeight: 280,
              errorBuilder: (_, __, ___) => fallback)
          : fallback,
    );
  }
}

enum _StatusKind { open, closed, soon }

class _StoreStatusBadge extends StatelessWidget {
  const _StoreStatusBadge({required this.label, required this.kind});
  final String label;
  final _StatusKind kind;

  @override
  Widget build(BuildContext context) {
    final color = switch (kind) {
      _StatusKind.open => const Color(0xFF009B4D),
      _StatusKind.closed => const Color(0xFFD64545),
      _StatusKind.soon => const Color(0xFFC87513),
    };
    return Row(mainAxisSize: MainAxisSize.min, children: [
      Container(width: 8, height: 8, decoration: BoxDecoration(color: color, shape: BoxShape.circle)),
      const SizedBox(width: 6),
      Text(label, style: TextStyle(color: color, fontSize: 12, fontWeight: FontWeight.w700)),
    ]);
  }
}

class StoreCTA extends StatelessWidget {
  const StoreCTA({required this.enabled, required this.compact, required this.onTap, super.key});
  final bool enabled;
  final bool compact;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final ar = Localizations.localeOf(context).languageCode == 'ar';
    return SizedBox(
      height: 50, width: compact ? double.infinity : 190,
      child: FilledButton(
        onPressed: enabled ? onTap : null,
        style: FilledButton.styleFrom(
          backgroundColor: const Color(0xFF009B4D), disabledBackgroundColor: const Color(0xFFE5E8EB),
          disabledForegroundColor: const Color(0xFF9AA4B1), shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(24)),
        ),
        child: Text(enabled ? (ar ? 'تسوق الآن' : 'Shop now') : (ar ? 'مغلق الآن' : 'Closed'),
          style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800)),
      ),
    );
  }
}

class _Meta extends StatelessWidget {
  const _Meta({required this.icon, required this.text});
  final IconData icon;
  final String text;
  @override
  Widget build(BuildContext context) => Row(mainAxisSize: MainAxisSize.min, children: [
    Icon(icon, size: 16, color: const Color(0xFF778393)),
    const SizedBox(width: 4),
    Text(text, style: const TextStyle(color: Color(0xFF778393), fontSize: 12, fontWeight: FontWeight.w600)),
  ]);
}

class _SelectorState extends StatelessWidget {
  const _SelectorState({required this.icon, required this.title, required this.subtitle, required this.button, required this.onPressed});
  final IconData icon;
  final String title;
  final String subtitle;
  final String button;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) => Center(child: Padding(
    padding: const EdgeInsets.all(28),
    child: Column(mainAxisSize: MainAxisSize.min, children: [
      Icon(icon, size: 52, color: const Color(0xFF009B4D)),
      const SizedBox(height: 14),
      Text(title, textAlign: TextAlign.center, style: const TextStyle(fontSize: 19, fontWeight: FontWeight.w800, color: Color(0xFF071B37))),
      const SizedBox(height: 7),
      Text(subtitle, textAlign: TextAlign.center, style: const TextStyle(color: Color(0xFF778393))),
      const SizedBox(height: 18),
      SizedBox(width: 190, child: FilledButton(onPressed: onPressed, child: Text(button))),
    ]),
  ));
}

class _SelectorSkeleton extends StatelessWidget {
  const _SelectorSkeleton();
  @override
  Widget build(BuildContext context) => ListView(
    padding: const EdgeInsets.fromLTRB(20, 26, 20, 30),
    children: [
      const SizedBox(height: 70),
      _box(210, 34), const SizedBox(height: 12), _box(270, 18), const SizedBox(height: 26),
      _box(double.infinity, 58, radius: 34), const SizedBox(height: 20),
      _box(double.infinity, 190, radius: 28), const SizedBox(height: 18), _box(double.infinity, 190, radius: 28),
    ],
  );
  static Widget _box(double width, double height, {double radius = 14}) => Align(
    child: Container(width: width, height: height, decoration: BoxDecoration(
      color: const Color(0xFFEAF2ED), borderRadius: BorderRadius.circular(radius))),
  );
}

List<Map<String, dynamic>> _rows(Object? value) => (value as List? ?? const [])
    .whereType<Map>().map((e) => Map<String, dynamic>.from(e)).toList(growable: false);
int _int(Object? value) => value is int ? value : int.tryParse(value?.toString() ?? '') ?? 0;

bool _isPharmacy(Map<String, dynamic> store) {
  final hay = [store['theme_code'], store['category'], store['type'], store['name']]
      .whereType<Object>().join(' ').toLowerCase();
  return hay.contains('pharmacy') || hay.contains('صيدل');
}

bool _isOpen(Map<String, dynamic> store) {
  if (store['is_active'] == false || store['is_active'] == 0) return false;
  final status = (store['status'] ?? store['availability'] ?? '').toString().toLowerCase();
  return !{'closed', 'inactive', 'unavailable'}.contains(status);
}

(String, _StatusKind) _status(Map<String, dynamic> store, bool ar) {
  final raw = (store['status'] ?? store['availability'] ?? '').toString().toLowerCase();
  if (raw.contains('soon') || raw.contains('coming')) return (ar ? 'قريبًا' : 'Coming soon', _StatusKind.soon);
  if (!_isOpen(store)) return (ar ? 'مغلق الآن' : 'Closed now', _StatusKind.closed);
  return (ar ? 'يعمل الآن' : 'Open now', _StatusKind.open);
}

String _location(Map<String, dynamic> store, bool ar) {
  for (final key in ['location', 'address', 'city', 'area']) {
    final v = store[key]?.toString().trim();
    if (v != null && v.isNotEmpty) return v;
  }
  return ar ? 'الكويت' : 'Kuwait';
}

String _description(Map<String, dynamic> store, {required bool wholesale, required bool pharmacy, required bool ar}) {
  for (final key in ['subtitle', 'category_name', 'category', 'description']) {
    final v = store[key]?.toString().trim();
    if (v != null && v.isNotEmpty) return v;
  }
  if (wholesale) return ar ? 'توريد بالجملة · خدمة أعمال' : 'Wholesale supply · Business service';
  if (pharmacy) return ar ? 'أدوية ومستلزمات صحية · توصيل سريع' : 'Pharmacy & health · Fast delivery';
  return ar ? 'بقالة وسوبرماركت · توصيل سريع' : 'Grocery & supermarket · Fast delivery';
}
