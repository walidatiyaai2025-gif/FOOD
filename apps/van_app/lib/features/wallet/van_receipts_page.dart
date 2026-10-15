import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/auth/van_session.dart';
import '../../core/theme/foodex_van_theme.dart';
import 'van_wallet_contract.dart';
import 'package:foodex_formatting/foodex_formatting.dart';

class VanReceiptsPage extends StatefulWidget {
  const VanReceiptsPage({
    super.key,
    required this.repository,
    required this.onSessionExpired,
    this.highlightReceiptId,
  });

  final VanWalletRepository repository;
  final Future<void> Function() onSessionExpired;
  final int? highlightReceiptId;

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

  String _receiptStatusLabel(String value) => switch (value.trim().toLowerCase()) {
        'posted' || 'completed' => _text('Completed', 'مكتمل'),
        'pending' || 'processing' => _text('Processing', 'قيد المعالجة'),
        'reversed' || 'voided' => _text('Reversed', 'معكوس'),
        'failed' => _text('Failed', 'فشل'),
        _ => _text('Receipt status', 'حالة الإيصال'),
      };

  String _receiptDateTimeLabel(_ReceiptRow row) {
    final timestamp = row.createdAt;
    if (timestamp == null) {
      return _text('Date unavailable', 'التاريخ غير متاح');
    }
    final localizations = MaterialLocalizations.of(context);
    final date = localizations.formatCompactDate(timestamp);
    final time = localizations.formatTimeOfDay(
      TimeOfDay.fromDateTime(timestamp),
      alwaysUse24HourFormat: MediaQuery.alwaysUse24HourFormatOf(context),
    );
    return '$date · $time';
  }

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
        rows.add(_ReceiptRow(receipt: receipt));
      }
    }
    rows.sort((a, b) {
      final aTimestamp = a.createdAt;
      final bTimestamp = b.createdAt;
      if (aTimestamp != null && bTimestamp != null) {
        final byTime = bTimestamp.compareTo(aTimestamp);
        if (byTime != 0) return byTime;
      } else if (aTimestamp != null) {
        return -1;
      } else if (bTimestamp != null) {
        return 1;
      }
      return b.receipt.id.compareTo(a.receipt.id);
    });
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
            if (widget.highlightReceiptId != null &&
                receipts.any(
                  (row) => row.receipt.id == widget.highlightReceiptId,
                )) ...[
              Card(
                key: ValueKey(
                  'van-receipt-focus-${widget.highlightReceiptId}',
                ),
                elevation: 0,
                color: FoodexVanTokens.mint,
                child: Padding(
                  padding: const EdgeInsets.all(12),
                  child: Row(
                    children: [
                      const Icon(
                        Icons.check_circle_outline,
                        color: FoodexVanTokens.green,
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: Text(
                          _text(
                            'Receipt #${widget.highlightReceiptId} from the current order collection.',
                            'الإيصال #${widget.highlightReceiptId} من تحصيل الطلب الحالي.',
                          ),
                          style: const TextStyle(fontWeight: FontWeight.w800),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
              const SizedBox(height: 8),
            ],
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
                    '${_receiptDateTimeLabel(row)} · '
                    '${_receiptStatusLabel(row.receipt.status)}',
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                  ),
                  trailing: Text(
                    foodexMoneyText(row.receipt.amount, row.receipt.currency),
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
  const _ReceiptRow({required this.receipt});

  final VanReceipt receipt;

  DateTime? get createdAt => DateTime.tryParse(receipt.createdAt.trim());
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
