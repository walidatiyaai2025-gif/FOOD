import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/localization/driver_translations.dart';
import 'driver_wallet_contract.dart';

class DriverWalletPage extends StatefulWidget {
  const DriverWalletPage({
    super.key,
    required this.repository,
  });

  final DriverWalletRepository repository;

  @override
  State<DriverWalletPage> createState() => _DriverWalletPageState();
}

class _DriverWalletPageState extends State<DriverWalletPage>
    with WidgetsBindingObserver {
  static const Duration _refreshInterval = Duration(seconds: 15);

  bool _loading = true;
  bool _submitting = false;
  bool _loadInFlight = false;
  bool _stale = false;
  Object? _error;
  DateTime? _lastSuccessfulAt;
  Timer? _refreshTimer;
  List<DriverWalletAccount> _accounts = const [];

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _load();
    _startLiveRefresh();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      unawaited(_load(silent: _accounts.isNotEmpty));
      _startLiveRefresh();
      return;
    }

    _stopLiveRefresh();
  }

  void _startLiveRefresh() {
    _refreshTimer?.cancel();
    _refreshTimer = Timer.periodic(_refreshInterval, (_) {
      if (!mounted || _loadInFlight || _loading || _submitting) return;
      unawaited(_load(silent: true));
    });
  }

  void _stopLiveRefresh() {
    _refreshTimer?.cancel();
    _refreshTimer = null;
  }

  @override
  void dispose() {
    _stopLiveRefresh();
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  Future<void> _load({bool silent = false}) async {
    if (_loadInFlight) return;
    _loadInFlight = true;

    if (mounted && !silent) {
      setState(() {
        _loading = true;
        _error = null;
      });
    }
    try {
      final accounts = await widget.repository.wallet();
      if (!mounted) return;
      setState(() {
        _accounts = accounts;
        _loading = false;
        _error = null;
        _stale = false;
        _lastSuccessfulAt = DateTime.now();
      });
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        if (silent && _accounts.isNotEmpty) {
          _stale = true;
          _error = null;
        } else {
          _error = error;
        }
      });
    } finally {
      _loadInFlight = false;
    }
  }

  Future<void> _submitRemittance(DriverWalletAccount account) async {
    if (_submitting || account.availableToRemit <= 0) return;
    final controller = TextEditingController(
      text: account.availableToRemit.toStringAsFixed(3),
    );
    final amount = await showDialog<double>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: Text(context.tr('driver.wallet.remit')),
        content: TextField(
          key: const Key('driver-wallet-remittance-amount'),
          controller: controller,
          autofocus: true,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          decoration: InputDecoration(
            labelText: context.tr('driver.wallet.amount'),
            suffixText: account.currency,
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(dialogContext).pop(),
            child: Text(context.tr('driver.action.cancel')),
          ),
          FilledButton(
            onPressed: () {
              final parsed = double.tryParse(controller.text.trim());
              Navigator.of(dialogContext).pop(parsed);
            },
            child: Text(context.tr('driver.wallet.submit')),
          ),
        ],
      ),
    );
    controller.dispose();
    if (amount == null || amount <= 0 || !mounted) return;

    setState(() {
      _submitting = true;
      _error = null;
    });
    try {
      await widget.repository.submitRemittance(
        collectionAccountId: account.id,
        amount: amount,
        method: 'cash_office',
        idempotencyKey:
            'driver-remit-${account.id}-${(account.availableToRemit * 1000).round()}-${(amount * 1000).round()}',
      );
      await _load();
    } catch (error) {
      if (mounted) setState(() => _error = error);
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      key: const Key('driver-wallet-page'),
      appBar: AppBar(
        title: Text(context.tr('driver.wallet.title')),
        actions: [
          IconButton(
            onPressed: _loading ? null : _load,
            icon: const Icon(Icons.refresh_rounded),
            tooltip: context.tr('driver.wallet.refresh'),
          ),
        ],
      ),
      body: Column(
        children: [
          if (_stale && _accounts.isNotEmpty)
            _WalletStaleBanner(lastSuccessfulAt: _lastSuccessfulAt),
          Expanded(
            child: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? Center(
                  child: FilledButton.icon(
                    onPressed: _load,
                    icon: const Icon(Icons.refresh_rounded),
                    label: Text(context.tr('driver.wallet.retry')),
                  ),
                )
              : _accounts.isEmpty
                  ? Center(child: Text(context.tr('driver.wallet.empty')))
                  : RefreshIndicator(
                      onRefresh: _load,
                      child: ListView.separated(
                        padding: const EdgeInsets.fromLTRB(12, 10, 12, 20),
                        itemCount: _accounts.length,
                        separatorBuilder: (_, __) => const SizedBox(height: 8),
                        itemBuilder: (context, index) {
                          final account = _accounts[index];
                          return Card(
                            child: Padding(
                              padding: const EdgeInsets.all(12),
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.stretch,
                                children: [
                                  Text(
                                    account.currency,
                                    style: Theme.of(context)
                                        .textTheme
                                        .titleMedium
                                        ?.copyWith(fontWeight: FontWeight.w800),
                                  ),
                                  const SizedBox(height: 12),
                                  _MoneyRow(
                                    label: context.tr('driver.wallet.custody'),
                                    amount: account.custodyBalance,
                                    currency: account.currency,
                                  ),
                                  _MoneyRow(
                                    label:
                                        context.tr('driver.wallet.available'),
                                    amount: account.availableToRemit,
                                    currency: account.currency,
                                  ),
                                  const SizedBox(height: 12),
                                  FilledButton.icon(
                                    key: Key(
                                      'driver-wallet-remit-${account.id}',
                                    ),
                                    onPressed: _submitting ||
                                            account.availableToRemit <= 0
                                        ? null
                                        : () => _submitRemittance(account),
                                    icon:
                                        const Icon(Icons.account_balance_rounded),
                                    label: Text(
                                      context.tr('driver.wallet.remit'),
                                    ),
                                  ),
                                  if (account.transactions.isNotEmpty) ...[
                                    const SizedBox(height: 16),
                                    Text(
                                      context.tr('driver.wallet.recent'),
                                      style: Theme.of(context)
                                          .textTheme
                                          .titleSmall
                                          ?.copyWith(
                                            fontWeight: FontWeight.w800,
                                          ),
                                    ),
                                    const SizedBox(height: 8),
                                    ...account.transactions.take(5).map(
                                          (row) => ListTile(
                                            dense: true,
                                            contentPadding: EdgeInsets.zero,
                                            leading: const Icon(
                                              Icons.receipt_long_outlined,
                                            ),
                                            title: Text(
                                              '${row.amount.toStringAsFixed(3)} ${row.currency}',
                                            ),
                                            subtitle: Text(row.status),
                                          ),
                                        ),
                                  ],
                                ],
                              ),
                            ),
                          );
                        },
                      ),
                    ),
          ),
        ],
      ),
    );
  }
}

class _WalletStaleBanner extends StatelessWidget {
  const _WalletStaleBanner({required this.lastSuccessfulAt});

  final DateTime? lastSuccessfulAt;

  @override
  Widget build(BuildContext context) {
    final last = lastSuccessfulAt;
    final time = last == null
        ? null
        : '${last.hour.toString().padLeft(2, '0')}:${last.minute.toString().padLeft(2, '0')}';

    return Container(
      key: const Key('driver-wallet-stale'),
      margin: const EdgeInsets.fromLTRB(12, 6, 12, 0),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 9),
      child: Text(
        [
          context.tr('driver.data.stale'),
          if (time != null)
            '${context.tr('driver.data.last_confirmed_update')}: $time',
        ].join(' · '),
        maxLines: 2,
        overflow: TextOverflow.ellipsis,
      ),
    );
  }
}

class _MoneyRow extends StatelessWidget {
  const _MoneyRow({
    required this.label,
    required this.amount,
    required this.currency,
  });

  final String label;
  final double amount;
  final String currency;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 4),
        child: Row(
          children: [
            Expanded(child: Text(label)),
            Text(
              '${amount.toStringAsFixed(3)} $currency',
              style: const TextStyle(fontWeight: FontWeight.w800),
            ),
          ],
        ),
      );
}
