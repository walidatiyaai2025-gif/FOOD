import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/localization/driver_translations.dart';
import '../../core/theme/foodex_theme.dart';
import '../../core/navigation/driver_shell.dart';
import 'driver_wallet_contract.dart';
import 'package:foodex_formatting/foodex_formatting.dart';

class DriverWalletPage extends StatefulWidget {
  const DriverWalletPage({
    super.key,
    required this.repository,
    this.homeRoute,
    this.deliveriesRoute,
    this.notificationsRoute,
    this.walletRoute,
  });

  final DriverWalletRepository repository;
  final String? homeRoute;
  final String? deliveriesRoute;
  final String? notificationsRoute;
  final String? walletRoute;

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

  String _transactionStatusLabel(String value) {
    final normalized = value.trim().toLowerCase();
    final ar = Localizations.localeOf(context).languageCode == 'ar';
    return switch (normalized) {
      'posted' || 'completed' || 'approved' => ar ? 'مكتمل' : 'Completed',
      'pending' || 'processing' => ar ? 'قيد المعالجة' : 'Processing',
      'rejected' => ar ? 'مرفوض' : 'Rejected',
      'failed' => ar ? 'فشل' : 'Failed',
      'cancelled' || 'canceled' => ar ? 'ملغي' : 'Cancelled',
      _ => ar ? 'حالة المعاملة' : 'Transaction status',
    };
  }

  void _showCollectedOrders(DriverWalletAccount account) {
    final isArabic = Localizations.localeOf(context).languageCode == 'ar';
    showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (sheetContext) => SafeArea(
        child: SizedBox(
          height: MediaQuery.sizeOf(sheetContext).height * .72,
          child: Column(
            children: [
              ListTile(
                title: Text(
                  isArabic ? 'الطلبات والمبالغ المحصّلة' : 'Collected orders',
                  style: Theme.of(sheetContext).textTheme.titleMedium,
                ),
                subtitle: Text(
                  foodexMoneyText(account.totalCollected, account.currency),
                ),
              ),
              Expanded(
                child: account.collectedOrders.isEmpty
                    ? Center(
                        child: Text(isArabic
                            ? 'لا توجد مبالغ محصّلة'
                            : 'No collected payments'),
                      )
                    : ListView.separated(
                        itemCount: account.collectedOrders.length,
                        separatorBuilder: (_, __) => const Divider(height: 1),
                        itemBuilder: (_, index) {
                          final order = account.collectedOrders[index];
                          final isPaid = order.paymentState == 'fully_settled';
                          final tone = isPaid
                              ? Colors.green.shade700
                              : Colors.orange.shade800;
                          return ListTile(
                            key: ValueKey(
                                'driver-collected-order-${order.orderId}'),
                            isThreeLine: true,
                            title: Text(
                              order.orderNumber.isEmpty
                                  ? '#${order.orderId}'
                                  : order.orderNumber,
                              maxLines: 1,
                              softWrap: false,
                              overflow: TextOverflow.ellipsis,
                            ),
                            subtitle: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  '${isArabic ? 'المحصّل' : 'Collected'}: '
                                  '${foodexMoneyText(order.collectedAmount, order.currency)}',
                                ),
                                Text(
                                  '${isArabic ? 'المتبقي' : 'Remaining'}: '
                                  '${foodexMoneyText(order.remainingAmount, order.currency)}',
                                ),
                                Text(
                                  isPaid
                                      ? (isArabic
                                          ? 'مدفوع بالكامل'
                                          : 'Fully paid')
                                      : (isArabic
                                          ? 'مدفوع جزئياً'
                                          : 'Partially paid'),
                                  style: TextStyle(
                                      color: tone, fontWeight: FontWeight.w700),
                                ),
                              ],
                            ),
                            trailing: Icon(
                              isPaid
                                  ? Icons.check_circle_rounded
                                  : Icons.timelapse_rounded,
                              color: tone,
                            ),
                          );
                        },
                      ),
              ),
              if (account.hasMoreCollectedOrders)
                Padding(
                  padding: const EdgeInsets.all(8),
                  child: Text(
                    isArabic
                        ? 'يتم عرض أحدث 100 طلب. الإجمالي يشمل كل التحصيلات.'
                        : 'Showing latest 100 orders. The total includes all collections.',
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }

  Future<void> _submitRemittance(DriverWalletAccount account) async {
    if (_submitting || account.availableToRemit <= 0) return;
    // The dialog owns its controller until its exit animation is finished.
    final amount = await showDialog<double>(
      context: context,
      builder: (_) => _DriverRemittanceAmountDialog(
        availableToRemit: account.availableToRemit,
        currency: account.currency,
      ),
    );
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
      backgroundColor: const Color(0xFFF5FCF8),
      appBar: AppBar(
        backgroundColor: const Color(0xFFE9F8EF),
        surfaceTintColor: Colors.transparent,
        title: Text(
          context.tr('driver.wallet.title'),
          style: Theme.of(context).textTheme.titleLarge?.copyWith(
                color: FoodexBrand.ink,
                fontWeight: FontWeight.w900,
              ),
        ),
        actions: [
          Padding(
            padding: const EdgeInsetsDirectional.only(end: 12),
            child: IconButton.filledTonal(
              key: const Key('driver-wallet-refresh'),
              onPressed: _loading ? null : _load,
              icon: _loading
                  ? const SizedBox.square(
                      dimension: 18,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : const Icon(Icons.refresh_rounded),
              tooltip: context.tr('driver.wallet.refresh'),
              style: IconButton.styleFrom(
                backgroundColor: Colors.white,
                foregroundColor: FoodexBrand.greenDark,
              ),
            ),
          ),
        ],
      ),
      bottomNavigationBar: widget.walletRoute == null
          ? null
          : DriverShellNavigation(
              destination: DriverShellDestination.wallet,
              homeRoute: widget.homeRoute,
              deliveriesRoute: widget.deliveriesRoute,
              notificationsRoute: widget.notificationsRoute,
              walletRoute: widget.walletRoute,
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
                              padding:
                                  const EdgeInsets.fromLTRB(14, 16, 14, 24),
                              itemCount: _accounts.length,
                              separatorBuilder: (_, __) =>
                                  const SizedBox(height: 8),
                              itemBuilder: (context, index) {
                                final account = _accounts[index];
                                return Card(
                                  margin: EdgeInsets.zero,
                                  color: FoodexBrand.surface,
                                  elevation: 4,
                                  shadowColor: const Color(0x130F5132),
                                  shape: RoundedRectangleBorder(
                                    borderRadius: BorderRadius.circular(22),
                                    side: const BorderSide(
                                      color: Color(0xFFE1EEE5),
                                    ),
                                  ),
                                  child: Padding(
                                    padding: const EdgeInsets.all(16),
                                    child: Column(
                                      crossAxisAlignment:
                                          CrossAxisAlignment.stretch,
                                      children: [
                                        Text(
                                          account.currency,
                                          style: Theme.of(context)
                                              .textTheme
                                              .titleMedium
                                              ?.copyWith(
                                                  fontWeight: FontWeight.w800),
                                        ),
                                        const SizedBox(height: 12),
                                        Container(
                                          key: Key(
                                              'driver-collected-summary-${account.id}'),
                                          decoration: BoxDecoration(
                                            borderRadius:
                                                BorderRadius.circular(20),
                                            gradient: const LinearGradient(
                                              begin:
                                                  AlignmentDirectional.topStart,
                                              end: AlignmentDirectional
                                                  .bottomEnd,
                                              colors: [
                                                FoodexBrand.greenDark,
                                                FoodexBrand.green,
                                                FoodexBrand.greenBright,
                                              ],
                                            ),
                                            boxShadow: const [
                                              BoxShadow(
                                                color: Color(0x22165D2D),
                                                blurRadius: 14,
                                                offset: Offset(0, 5),
                                              ),
                                            ],
                                          ),
                                          child: InkWell(
                                            onTap: () =>
                                                _showCollectedOrders(account),
                                            child: Padding(
                                              padding: const EdgeInsets.all(17),
                                              child: Row(
                                                children: [
                                                  Container(
                                                    height: 48,
                                                    width: 48,
                                                    decoration: BoxDecoration(
                                                      color: Colors.white
                                                          .withValues(
                                                              alpha: 0.16),
                                                      borderRadius:
                                                          BorderRadius.circular(
                                                              15),
                                                    ),
                                                    child: const Icon(
                                                      Icons
                                                          .account_balance_wallet_rounded,
                                                      size: 27,
                                                      color: Colors.white,
                                                    ),
                                                  ),
                                                  const SizedBox(width: 10),
                                                  Expanded(
                                                    child: Column(
                                                      crossAxisAlignment:
                                                          CrossAxisAlignment
                                                              .start,
                                                      children: [
                                                        Text(
                                                          Localizations.localeOf(
                                                                          context)
                                                                      .languageCode ==
                                                                  'ar'
                                                              ? 'إجمالي المبالغ المحصّلة'
                                                              : 'Total collected',
                                                          style:
                                                              const TextStyle(
                                                            color: Colors.white,
                                                            fontWeight:
                                                                FontWeight.w700,
                                                          ),
                                                        ),
                                                        FittedBox(
                                                          alignment:
                                                              AlignmentDirectional
                                                                  .centerStart,
                                                          fit: BoxFit.scaleDown,
                                                          child: Text(
                                                            foodexMoneyText(
                                                              account
                                                                  .totalCollected,
                                                              account.currency,
                                                            ),
                                                            style:
                                                                const TextStyle(
                                                              color:
                                                                  Colors.white,
                                                              fontSize: 25,
                                                              fontWeight:
                                                                  FontWeight
                                                                      .w900,
                                                            ),
                                                          ),
                                                        ),
                                                      ],
                                                    ),
                                                  ),
                                                  const Icon(
                                                    Icons.chevron_right_rounded,
                                                    color: Colors.white,
                                                  ),
                                                ],
                                              ),
                                            ),
                                          ),
                                        ),
                                        const SizedBox(height: 10),
                                        _MoneyRow(
                                          label: context
                                              .tr('driver.wallet.custody'),
                                          amount: account.custodyBalance,
                                          currency: account.currency,
                                        ),
                                        const Divider(
                                          color: Color(0xFFE7F0EA),
                                          height: 16,
                                        ),
                                        _MoneyRow(
                                          label: context
                                              .tr('driver.wallet.available'),
                                          amount: account.availableToRemit,
                                          currency: account.currency,
                                        ),
                                        const SizedBox(height: 12),
                                        DecoratedBox(
                                          decoration: BoxDecoration(
                                            borderRadius:
                                                BorderRadius.circular(16),
                                            gradient: !_submitting &&
                                                    account.availableToRemit > 0
                                                ? const LinearGradient(
                                                    colors: [
                                                      FoodexBrand.actionGreen,
                                                      FoodexBrand.greenBright,
                                                    ],
                                                  )
                                                : null,
                                            color: _submitting ||
                                                    account.availableToRemit <=
                                                        0
                                                ? const Color(0xFFE5EAE7)
                                                : null,
                                          ),
                                          child: FilledButton.icon(
                                            style: FilledButton.styleFrom(
                                              minimumSize:
                                                  const Size.fromHeight(52),
                                              backgroundColor:
                                                  Colors.transparent,
                                              disabledBackgroundColor:
                                                  Colors.transparent,
                                              foregroundColor: Colors.white,
                                              disabledForegroundColor:
                                                  FoodexBrand.muted,
                                              elevation: 0,
                                              shape: RoundedRectangleBorder(
                                                borderRadius:
                                                    BorderRadius.circular(16),
                                              ),
                                            ),
                                            key: Key('driver-wallet-remit-${account.id}'),
                                            onPressed: _submitting ||
                                                    account.availableToRemit <=
                                                        0
                                                ? null
                                                : () =>
                                                    _submitRemittance(account),
                                            icon: _submitting
                                                ? const SizedBox.square(
                                                    dimension: 18,
                                                    child:
                                                        CircularProgressIndicator(
                                                      strokeWidth: 2,
                                                      color: Colors.white,
                                                    ),
                                                  )
                                                : const Icon(Icons
                                                    .account_balance_rounded),
                                            label: Text(
                                              context.tr('driver.wallet.remit'),
                                              style: const TextStyle(
                                                fontWeight: FontWeight.w800,
                                              ),
                                            ),
                                          ),
                                        ),
                                        if (account
                                            .transactions.isNotEmpty) ...[
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
                                                (row) => Card(
                                                  margin: const EdgeInsets.only(
                                                      bottom: 8),
                                                  color:
                                                      const Color(0xFFFBFDFC),
                                                  elevation: 0,
                                                  shape: RoundedRectangleBorder(
                                                    borderRadius:
                                                        BorderRadius.circular(
                                                            15),
                                                    side: const BorderSide(
                                                      color: Color(0xFFE5EEE8),
                                                    ),
                                                  ),
                                                  child: ListTile(
                                                    dense: true,
                                                    contentPadding:
                                                        const EdgeInsets
                                                            .symmetric(
                                                      horizontal: 12,
                                                      vertical: 4,
                                                    ),
                                                    leading: Container(
                                                      height: 42,
                                                      width: 42,
                                                      decoration: BoxDecoration(
                                                        color: FoodexBrand
                                                            .greenSoft,
                                                        borderRadius:
                                                            BorderRadius
                                                                .circular(13),
                                                      ),
                                                      child: const Icon(
                                                        Icons
                                                            .receipt_long_outlined,
                                                        color: FoodexBrand
                                                            .greenDark,
                                                      ),
                                                    ),
                                                    title: Text(
                                                      foodexMoneyText(
                                                          row.amount,
                                                          row.currency),
                                                    ),
                                                    subtitle: Text(
                                                      _transactionStatusLabel(
                                                          row.status),
                                                    ),
                                                  ),
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

/// The route, not the awaiting caller, owns the text controller. showDialog's
/// Future completes on pop, before the closing route is necessarily unmounted.
class _DriverRemittanceAmountDialog extends StatefulWidget {
  const _DriverRemittanceAmountDialog({
    required this.availableToRemit,
    required this.currency,
  });

  final double availableToRemit;
  final String currency;

  @override
  State<_DriverRemittanceAmountDialog> createState() =>
      _DriverRemittanceAmountDialogState();
}

class _DriverRemittanceAmountDialogState
    extends State<_DriverRemittanceAmountDialog> {
  late final TextEditingController _controller;

  @override
  void initState() {
    super.initState();
    _controller = TextEditingController(
      text: foodexMoneyAmount(widget.availableToRemit, widget.currency),
    );
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
        title: Text(context.tr('driver.wallet.remit')),
        content: TextField(
          key: const Key('driver-wallet-remittance-amount'),
          controller: _controller,
          autofocus: true,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          decoration: InputDecoration(
            labelText: context.tr('driver.wallet.amount'),
            suffixText: widget.currency,
          ),
        ),
        actions: [
          TextButton(
            key: const Key('driver-wallet-remittance-cancel'),
            onPressed: () => Navigator.of(context).pop<double>(),
            child: Text(context.tr('driver.action.cancel')),
          ),
          FilledButton(
            key: const Key('driver-wallet-remittance-submit'),
            onPressed: () {
              final amount = double.tryParse(_controller.text.trim());
              Navigator.of(context).pop<double>(amount);
            },
            child: Text(context.tr('driver.wallet.submit')),
          ),
        ],
      );
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
            Flexible(
              child: FittedBox(
                fit: BoxFit.scaleDown,
                alignment: AlignmentDirectional.centerEnd,
                child: Text(
                  foodexMoneyText(amount, currency),
                  style: const TextStyle(fontWeight: FontWeight.w800),
                ),
              ),
            ),
          ],
        ),
      );
}
