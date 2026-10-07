import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/auth/van_session.dart';
import '../../core/theme/foodex_van_theme.dart';
import '../wallet/van_wallet_contract.dart';

class VanDashboardPage extends StatefulWidget {
  const VanDashboardPage({
    super.key,
    required this.repository,
    required this.onSessionExpired,
  });

  final VanWalletRepository repository;
  final Future<void> Function() onSessionExpired;

  @override
  State<VanDashboardPage> createState() => _VanDashboardPageState();
}

class _VanDashboardPageState extends State<VanDashboardPage>
    with WidgetsBindingObserver {
  bool _loading = true;
  bool _refreshing = false;
  bool _stale = false;
  Object? _error;
  List<VanWalletAccount> _accounts = const [];
  List<VanCustomerScope> _customers = const [];

  bool get _arabic => Localizations.localeOf(context).languageCode == 'ar';

  String _text(String en, String ar) => _arabic ? ar : en;

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
        if (background && (_accounts.isNotEmpty || _customers.isNotEmpty)) {
          _refreshing = true;
        } else {
          _loading = true;
        }
        _error = null;
      });
    }

    try {
      final results = await Future.wait<Object>([
        widget.repository.wallet(),
        widget.repository.customers(),
      ]);
      if (!mounted) return;
      setState(() {
        _accounts = results[0] as List<VanWalletAccount>;
        _customers = results[1] as List<VanCustomerScope>;
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
        if (background && (_accounts.isNotEmpty || _customers.isNotEmpty)) {
          _stale = true;
        } else {
          _error = error;
        }
      });
    }
  }

  double get _custodyBalance =>
      _accounts.fold(0, (sum, account) => sum + account.custodyBalance);

  double get _availableToRemit =>
      _accounts.fold(0, (sum, account) => sum + account.availableToRemit);

  int get _receiptCount =>
      _accounts.fold(0, (sum, account) => sum + account.receipts.length);

  int get _remittanceCount =>
      _accounts.fold(0, (sum, account) => sum + account.remittances.length);

  String get _currency {
    if (_accounts.isEmpty) return 'KWD';
    final currencies = _accounts.map((a) => a.currency).toSet();
    return currencies.length == 1 ? currencies.first : '';
  }

  String _money(double value) =>
      _currency.isEmpty ? value.toStringAsFixed(3) : '${value.toStringAsFixed(3)} $_currency';

  @override
  Widget build(BuildContext context) {
    if (_loading && _accounts.isEmpty && _customers.isEmpty) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_error != null && _accounts.isEmpty && _customers.isEmpty) {
      return _StateCard(
        title: _text('Unable to load Van dashboard', 'تعذر تحميل لوحة الفان'),
        body: _text(
          'Operational totals remain server-authoritative. Retry when connectivity is restored.',
          'تظل الإجماليات التشغيلية معتمدة من الخادم. أعد المحاولة عند عودة الاتصال.',
        ),
        actionLabel: _text('Retry', 'إعادة المحاولة'),
        onAction: _load,
      );
    }

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        key: const ValueKey('van-dashboard-page'),
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
              child: Text(
                _stale
                    ? _text(
                        'Showing the last confirmed dashboard totals.',
                        'يتم عرض آخر إجماليات مؤكدة للوحة الفان.',
                      )
                    : _text(
                        'Refreshing operational totals…',
                        'جارٍ تحديث الإجماليات التشغيلية…',
                      ),
              ),
            ),
          Text(
            _text('Today at a glance', 'ملخص التشغيل'),
            style: Theme.of(context).textTheme.titleLarge?.copyWith(
                  fontWeight: FontWeight.w900,
                ),
          ),
          const SizedBox(height: 10),
          GridView.count(
            crossAxisCount: 2,
            childAspectRatio: 1.55,
            mainAxisSpacing: 10,
            crossAxisSpacing: 10,
            shrinkWrap: true,
            physics: const NeverScrollableScrollPhysics(),
            children: [
              _KpiCard(
                key: const ValueKey('van-dashboard-customers'),
                icon: Icons.storefront_outlined,
                label: _text('Assigned customers', 'العملاء المسندون'),
                value: '${_customers.length}',
              ),
              _KpiCard(
                key: const ValueKey('van-dashboard-custody'),
                icon: Icons.account_balance_wallet_outlined,
                label: _text('Custody balance', 'رصيد العهدة'),
                value: _money(_custodyBalance),
              ),
              _KpiCard(
                key: const ValueKey('van-dashboard-remittable'),
                icon: Icons.account_balance_outlined,
                label: _text('Available to remit', 'المتاح للتوريد'),
                value: _money(_availableToRemit),
              ),
              _KpiCard(
                key: const ValueKey('van-dashboard-receipts'),
                icon: Icons.receipt_long_outlined,
                label: _text('Posted receipts', 'الإيصالات المسجلة'),
                value: '$_receiptCount',
              ),
            ],
          ),
          const SizedBox(height: 12),
          Card(
            elevation: 0,
            child: ListTile(
              leading: const CircleAvatar(
                backgroundColor: FoodexVanTokens.mint,
                child: Icon(
                  Icons.swap_vert_circle_outlined,
                  color: FoodexVanTokens.green,
                ),
              ),
              title: Text(_text('Remittance activity', 'نشاط التوريد')),
              subtitle: Text(
                _text(
                  'Server-recorded remittance entries in the current custody scope.',
                  'عمليات التوريد المسجلة على الخادم ضمن نطاق العهدة الحالي.',
                ),
              ),
              trailing: Text(
                '$_remittanceCount',
                key: const ValueKey('van-dashboard-remittances'),
                style: const TextStyle(fontWeight: FontWeight.w900),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _KpiCard extends StatelessWidget {
  const _KpiCard({
    super.key,
    required this.icon,
    required this.label,
    required this.value,
  });

  final IconData icon;
  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Card(
      elevation: 0,
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(icon, color: FoodexVanTokens.green),
            const Spacer(),
            Text(
              value,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: Theme.of(context).textTheme.titleLarge?.copyWith(
                    fontWeight: FontWeight.w900,
                  ),
            ),
            const SizedBox(height: 2),
            Text(
              label,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: Theme.of(context).textTheme.bodySmall,
            ),
          ],
        ),
      ),
    );
  }
}

class _StateCard extends StatelessWidget {
  const _StateCard({
    required this.title,
    required this.body,
    required this.actionLabel,
    required this.onAction,
  });

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
            padding: const EdgeInsets.all(18),
            child: Column(
              children: [
                Text(
                  title,
                  textAlign: TextAlign.center,
                  style: Theme.of(context).textTheme.titleMedium?.copyWith(
                        fontWeight: FontWeight.w800,
                      ),
                ),
                const SizedBox(height: 6),
                Text(body, textAlign: TextAlign.center),
                const SizedBox(height: 12),
                OutlinedButton(
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
