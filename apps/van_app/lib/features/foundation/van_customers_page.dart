import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/auth/van_session.dart';
import '../../core/theme/foodex_van_theme.dart';
import '../wallet/van_wallet_contract.dart';

class VanCustomersPage extends StatefulWidget {
  const VanCustomersPage({
    super.key,
    required this.repository,
    required this.onSessionExpired,
  });

  final VanWalletRepository repository;
  final Future<void> Function() onSessionExpired;

  @override
  State<VanCustomersPage> createState() => _VanCustomersPageState();
}

class _VanCustomersPageState extends State<VanCustomersPage>
    with WidgetsBindingObserver {
  bool _loading = true;
  bool _refreshing = false;
  bool _stale = false;
  Object? _error;
  List<VanCustomerScope> _customers = const [];

  bool get _arabic => Localizations.localeOf(context).languageCode == 'ar';

  String _text(String en, String ar) => _arabic ? ar : en;

  String _customerTypeLabel(String value) => switch (value.trim().toLowerCase()) {
        'b2b' || 'wholesale' => _text('Wholesale', 'جملة'),
        'b2c' || 'retail' => _text('Retail', 'تجزئة'),
        _ => _text('Customer', 'عميل'),
      };

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _load();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed && !_loading && !_refreshing) {
      unawaited(_load(background: true));
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  Future<void> _load({bool background = false}) async {
    if (mounted) {
      setState(() {
        if (background && _customers.isNotEmpty) {
          _refreshing = true;
        } else {
          _loading = true;
        }
        _error = null;
      });
    }

    try {
      final customers = await widget.repository.customers();
      if (!mounted) return;
      setState(() {
        _customers = customers;
        _loading = false;
        _refreshing = false;
        _stale = false;
      });
    } on VanSessionExpiredException {
      await widget.onSessionExpired();
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _refreshing = false;
        if (background && _customers.isNotEmpty) {
          _stale = true;
        } else {
          _error = error;
        }
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_loading && _customers.isEmpty) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_error != null && _customers.isEmpty) {
      return _StateCard(
        icon: Icons.cloud_off_outlined,
        title: _text('Unable to load customers', 'تعذر تحميل العملاء'),
        body: _text(
          'Customer scope remains server-authoritative. Retry when connectivity is restored.',
          'يظل نطاق العملاء معتمدًا من الخادم. أعد المحاولة عند عودة الاتصال.',
        ),
        actionLabel: _text('Retry', 'إعادة المحاولة'),
        onAction: _load,
      );
    }

    if (_customers.isEmpty) {
      return _StateCard(
        icon: Icons.storefront_outlined,
        title: _text('No assigned customers', 'لا يوجد عملاء مسندون'),
        body: _text(
          'No customers are currently available in your authorized Van scope.',
          'لا يوجد عملاء متاحون حاليًا ضمن نطاق الفان المصرح لك به.',
        ),
        actionLabel: _text('Refresh', 'تحديث'),
        onAction: _load,
      );
    }

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        key: const ValueKey('van-customers-list'),
        padding: const EdgeInsets.fromLTRB(12, 10, 12, 24),
        children: [
          if (_refreshing || _stale)
            Container(
              margin: const EdgeInsets.only(bottom: 10),
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: FoodexVanTokens.surface,
                border: Border.all(color: FoodexVanTokens.border),
                borderRadius: BorderRadius.circular(14),
              ),
              child: Row(
                children: [
                  Icon(
                    _stale ? Icons.cloud_off_outlined : Icons.sync,
                    size: 18,
                    color: FoodexVanTokens.green,
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      _stale
                          ? _text(
                              'Showing the last confirmed customer scope.',
                              'يتم عرض آخر نطاق عملاء مؤكد.',
                            )
                          : _text(
                              'Refreshing customer scope…',
                              'جارٍ تحديث نطاق العملاء…',
                            ),
                    ),
                  ),
                ],
              ),
            ),
          for (final customer in _customers)
            Card(
              margin: const EdgeInsets.only(bottom: 8),
              elevation: 0,
              child: ListTile(
                contentPadding:
                    const EdgeInsets.symmetric(horizontal: 14, vertical: 6),
                leading: const CircleAvatar(
                  backgroundColor: FoodexVanTokens.mint,
                  child: Icon(
                    Icons.storefront_outlined,
                    color: FoodexVanTokens.green,
                  ),
                ),
                title: Text(
                  customer.name,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(fontWeight: FontWeight.w800),
                ),
                subtitle: Text(
                  _customerTypeLabel(customer.type),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
            ),
        ],
      ),
    );
  }
}

class _StateCard extends StatelessWidget {
  const _StateCard({
    required this.icon,
    required this.title,
    required this.body,
    required this.actionLabel,
    required this.onAction,
  });

  final IconData icon;
  final String title;
  final String body;
  final String actionLabel;
  final Future<void> Function() onAction;

  @override
  Widget build(BuildContext context) {
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Card(
          elevation: 0,
          child: Padding(
            padding: const EdgeInsets.all(20),
            child: Column(
              children: [
                Icon(icon, size: 36, color: FoodexVanTokens.green),
                const SizedBox(height: 10),
                Text(
                  title,
                  textAlign: TextAlign.center,
                  style: Theme.of(context).textTheme.titleMedium?.copyWith(
                        fontWeight: FontWeight.w800,
                      ),
                ),
                const SizedBox(height: 6),
                Text(body, textAlign: TextAlign.center),
                const SizedBox(height: 14),
                FilledButton(
                  onPressed: onAction,
                  child: Text(actionLabel),
                ),
              ],
            ),
          ),
        ),
      ],
    );
  }
}
