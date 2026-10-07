import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/auth/van_session.dart';
import '../../core/theme/foodex_van_theme.dart';
import 'van_wallet_contract.dart';

class VanReceiptsPage extends StatefulWidget {
  const VanReceiptsPage({
    super.key,
    required this.repository,
    required this.onSessionExpired,
  });

  final VanWalletRepository repository;
  final Future<void> Function() onSessionExpired;

  @override
  State<VanReceiptsPage> createState() => _VanReceiptsPageState();
}

class _VanReceiptsPageState extends State<VanReceiptsPage>
    with WidgetsBindingObserver {
  bool _loading = true;
  bool _refreshing = false;
  bool _stale = false;
  Object? _error;
  List<VanWalletAccount> _accounts = const [];

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
        if (background && _accounts.isNotEmpty) {
          _refreshing = true;
        } else {
          _loading = true;
        }
        _error = null;
      });
    }

    try {
      final accounts = await widget.repository.wallet();
      if (!mounted) return;
      setState(() {
        _accounts = accounts;
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
        if (background && _accounts.isNotEmpty) {
          _stale = true;
        } else {
          _error = error;
        }
      });
    }
  }

  List<_ReceiptRow> get _receipts {
    final rows = <_ReceiptRow>[];
    for (final account in _accounts) {
      for (final receipt in account.receipts) {
        rows.add(_ReceiptRow(account: account, receipt: receipt));
      }
    }
    rows.sort(
      (a, b) => b.receipt.createdAt.compareTo(a.receipt.createdAt),
    );
    return rows;
  }

  @override
  Widget build(BuildContext context) {
    if (_loading && _accounts.isEmpty) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_error != null && _accounts.isEmpty) {
      return _StateCard(
        icon: Icons.cloud_off_outlined,
        title: _text('Unable to load receipts', 'تعذر تحميل الإيصالات'),
        body: _text(
          'Receipt history remains authoritative from the custody ledger.',
          'يظل سجل الإيصالات معتمدًا من سجل العهدة المالي.',
        ),
        actionLabel: _text('Retry', 'إعادة المحاولة'),
        onAction: _load,
      );
    }

    final receipts = _receipts;

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        key: const ValueKey('van-receipts-page'),
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
                              'Showing the last confirmed receipt history.',
                              'يتم عرض آخر سجل إيصالات مؤكد.',
                            )
                          : _text(
                              'Refreshing receipt history…',
                              'جارٍ تحديث سجل الإيصالات…',
                            ),
                    ),
                  ),
                ],
              ),
            ),
          if (receipts.isEmpty)
            _StateCard(
              icon: Icons.receipt_outlined,
              title: _text('No receipts yet', 'لا توجد إيصالات حتى الآن'),
              body: _text(
                'Posted customer collections will appear here from the custody ledger.',
                'ستظهر هنا تحصيلات العملاء المسجلة من سجل العهدة.',
              ),
              actionLabel: _text('Refresh', 'تحديث'),
              onAction: _load,
            )
          else ...[
            Text(
              _text('Posted receipts', 'الإيصالات المسجلة'),
              style: Theme.of(context).textTheme.titleMedium?.copyWith(
                    fontWeight: FontWeight.w900,
                  ),
            ),
            const SizedBox(height: 8),
            for (final row in receipts)
              Card(
                key: ValueKey('van-receipt-${row.receipt.id}'),
                elevation: 0,
                margin: const EdgeInsets.only(bottom: 8),
                child: ListTile(
                  leading: const CircleAvatar(
                    backgroundColor: FoodexVanTokens.mint,
                    child: Icon(
                      Icons.receipt_long_outlined,
                      color: FoodexVanTokens.green,
                    ),
                  ),
                  title: Text(
                    '#${row.receipt.id}',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(fontWeight: FontWeight.w800),
                  ),
                  subtitle: Text(
                    '${row.receipt.createdAt} · ${row.receipt.status}',
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                  ),
                  trailing: Text(
                    '${row.receipt.amount.toStringAsFixed(3)} '
                    '${row.receipt.currency}',
                    style: const TextStyle(fontWeight: FontWeight.w900),
                  ),
                ),
              ),
          ],
        ],
      ),
    );
  }
}

class _ReceiptRow {
  const _ReceiptRow({
    required this.account,
    required this.receipt,
  });

  final VanWalletAccount account;
  final VanReceipt receipt;
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
    return Card(
      elevation: 0,
      child: Padding(
        padding: const EdgeInsets.all(18),
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
            const SizedBox(height: 12),
            OutlinedButton(
              onPressed: onAction,
              child: Text(actionLabel),
            ),
          ],
        ),
      ),
    );
  }
}
