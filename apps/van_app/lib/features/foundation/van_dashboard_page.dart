import 'dart:async';

import 'package:flutter/material.dart';
import 'package:foodex_visualization/foodex_visualization.dart';

import '../../core/auth/van_session.dart';
import '../../core/theme/foodex_van_theme.dart';
import '../orders/van_order_contract.dart';
import '../wallet/van_wallet_contract.dart';

class VanDashboardPage extends StatefulWidget {
  const VanDashboardPage({
    super.key,
    required this.repository,
    required this.orderRepository,
    required this.onSessionExpired,
    required this.onOpenOrder,
    required this.onOpenCustomers,
    required this.onOpenWallet,
    required this.onOpenReceipts,
    required this.onOpenRemittance,
  });

  final VanWalletRepository repository;
  final VanOrderRepository orderRepository;
  final Future<void> Function() onSessionExpired;
  final ValueChanged<int> onOpenOrder;
  final VoidCallback onOpenCustomers;
  final VoidCallback onOpenWallet;
  final VoidCallback onOpenReceipts;
  final VoidCallback onOpenRemittance;

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
  List<VanOrderRecord> _orders = const [];

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
        if (background &&
            (_accounts.isNotEmpty || _customers.isNotEmpty || _orders.isNotEmpty)) {
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
        widget.orderRepository.orders(),
      ]);
      if (!mounted) return;
      setState(() {
        _accounts = results[0] as List<VanWalletAccount>;
        _customers = results[1] as List<VanCustomerScope>;
        _orders = results[2] as List<VanOrderRecord>;
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
        if (background &&
            (_accounts.isNotEmpty || _customers.isNotEmpty || _orders.isNotEmpty)) {
          _stale = true;
        } else {
          _error = error;
        }
      });
    }
  }

  List<VanOrderRecord> get _attentionOrders => _orders
      .where(
        (order) => !{
          'delivered',
          'completed',
          'cancelled',
          'refunded',
        }.contains(order.vanExecutionStatus ?? order.status),
      )
      .take(3)
      .toList(growable: false);

  String _orderStatus(VanOrderRecord order) {
    final status = order.vanExecutionStatus ?? order.status;
    switch (status) {
      case 'assigned':
        return _text('Assigned', 'مسند');
      case 'accepted':
        return _text('Accepted', 'مقبول');
      case 'picked_up':
        return _text('Picked up', 'تم الاستلام');
      case 'out_for_delivery':
        return _text('Out for delivery', 'خرج للتسليم');
      case 'failed':
        return _text('Failed', 'متعذر');
      default:
        return status.replaceAll('_', ' ');
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

  double get _receiptTotal => _accounts.fold(
        0,
        (sum, account) =>
            sum +
            account.receipts.fold<double>(
              0,
              (receiptSum, receipt) => receiptSum + receipt.amount,
            ),
      );

  double get _remittanceTotal => _accounts.fold(
        0,
        (sum, account) =>
            sum +
            account.remittances.fold<double>(
              0,
              (remittanceSum, remittance) =>
                  remittanceSum + remittance.amount,
            ),
      );

  String get _currency {
    if (_accounts.isEmpty) return 'KWD';
    final currencies = _accounts.map((a) => a.currency).toSet();
    return currencies.length == 1 ? currencies.first : '';
  }

  String _money(double value) =>
      _currency.isEmpty ? value.toStringAsFixed(3) : '${value.toStringAsFixed(3)} $_currency';

  @override
  Widget build(BuildContext context) {
    if (_loading && _accounts.isEmpty && _customers.isEmpty && _orders.isEmpty) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_error != null &&
        _accounts.isEmpty &&
        _customers.isEmpty &&
        _orders.isEmpty) {
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
                onTap: widget.onOpenCustomers,
              ),
              _KpiCard(
                key: const ValueKey('van-dashboard-custody'),
                icon: Icons.account_balance_wallet_outlined,
                label: _text('Custody balance', 'رصيد العهدة'),
                value: _money(_custodyBalance),
                onTap: widget.onOpenWallet,
              ),
              _KpiCard(
                key: const ValueKey('van-dashboard-remittable'),
                icon: Icons.account_balance_outlined,
                label: _text('Available to remit', 'المتاح للتوريد'),
                value: _money(_availableToRemit),
                onTap: widget.onOpenRemittance,
              ),
              _KpiCard(
                key: const ValueKey('van-dashboard-receipts'),
                icon: Icons.receipt_long_outlined,
                label: _text('Posted receipts', 'الإيصالات المسجلة'),
                value: '$_receiptCount',
                onTap: widget.onOpenReceipts,
              ),
            ],
          ),
          const SizedBox(height: 12),
          Card(
            key: const ValueKey('van-dashboard-orders'),
            elevation: 0,
            child: Padding(
              padding: const EdgeInsets.all(14),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text(
                    _text('Orders needing attention', 'طلبات تحتاج متابعة'),
                    style: Theme.of(context).textTheme.titleMedium?.copyWith(
                          fontWeight: FontWeight.w900,
                        ),
                  ),
                  const SizedBox(height: 4),
                  Text(
                    _text(
                      'Open the exact server-owned order and continue delivery.',
                      'افتح الطلب الفعلي من الخادم وأكمل دورة التسليم.',
                    ),
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                  const SizedBox(height: 8),
                  if (_attentionOrders.isEmpty)
                    Text(_text('No active orders.', 'لا توجد طلبات نشطة.'))
                  else
                    for (final order in _attentionOrders)
                      ListTile(
                        key: ValueKey('van-dashboard-order-${order.id}'),
                        dense: true,
                        contentPadding: EdgeInsets.zero,
                        onTap: () => widget.onOpenOrder(order.id),
                        leading: const Icon(
                          Icons.receipt_long_outlined,
                          color: FoodexVanTokens.green,
                        ),
                        title: Text(
                          order.orderNumber,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(fontWeight: FontWeight.w800),
                        ),
                        subtitle: Text(_orderStatus(order)),
                        trailing: const Icon(Icons.chevron_right),
                      ),
                ],
              ),
            ),
          ),
          const SizedBox(height: 12),
          Card(
            key: const ValueKey('van-dashboard-cash-flow-analytics'),
            elevation: 0,
            child: Padding(
              padding: const EdgeInsets.all(14),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text(
                    _text(
                      'Collections vs remittances',
                      'التحصيلات مقابل التوريدات',
                    ),
                    style: Theme.of(context).textTheme.titleMedium?.copyWith(
                          fontWeight: FontWeight.w900,
                        ),
                  ),
                  const SizedBox(height: 4),
                  Text(
                    _text(
                      'What share of collected cash is still awaiting remittance?',
                      'ما نسبة التحصيلات التي ما زالت بانتظار التوريد؟',
                    ),
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                  const SizedBox(height: 12),
                  FoodexBarChart(
                    key: const ValueKey('van-dashboard-cash-flow-chart'),
                    groups: [
                      [_receiptTotal],
                      [_remittanceTotal],
                    ],
                    semanticLabel: _text(
                      'Collected and remitted cash comparison',
                      'مقارنة مبالغ التحصيل والتوريد',
                    ),
                    height: 116,
                    gap: 18,
                  ),
                  const SizedBox(height: 10),
                  FoodexChartLegend(
                    labels: [
                      _text('Collected', 'المحصل'),
                      _text('Remitted', 'المورد'),
                    ],
                  ),
                  const SizedBox(height: 8),
                  Row(
                    children: [
                      Expanded(
                        child: Text(
                          '${_text('Collected', 'المحصل')}: ${_money(_receiptTotal)} · '
                          '${_text('Remitted', 'المورد')}: ${_money(_remittanceTotal)}',
                          style: Theme.of(context).textTheme.bodySmall,
                        ),
                      ),
                      PopupMenuButton<String>(
                        key: const ValueKey('van-dashboard-cash-flow-menu'),
                        tooltip: _text('Open details', 'فتح التفاصيل'),
                        onSelected: (value) {
                          if (value == 'receipts') {
                            widget.onOpenReceipts();
                          } else if (value == 'remittance') {
                            widget.onOpenRemittance();
                          }
                        },
                        itemBuilder: (context) => [
                          PopupMenuItem(
                            value: 'receipts',
                            child: Text(
                              _text('Open receipts', 'فتح الإيصالات'),
                            ),
                          ),
                          PopupMenuItem(
                            value: 'remittance',
                            child: Text(
                              _text('Open remittance', 'فتح التوريد'),
                            ),
                          ),
                        ],
                      ),
                    ],
                  ),
                ],
              ),
            ),
          ),
          const SizedBox(height: 12),
          Card(
            elevation: 0,
            child: ListTile(
              onTap: widget.onOpenRemittance,
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
    required this.onTap,
  });

  final IconData icon;
  final String label;
  final String value;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Card(
      elevation: 0,
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: onTap,
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
