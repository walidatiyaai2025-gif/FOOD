import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/api/customer_action_api.dart';
import '../../core/localization/app_translations.dart';
import '../../shared/customer_ui_v3/customer_ui_v3.dart';
import 'customer_order_models.dart';
import 'customer_orders_api.dart';

class CustomerOrdersScreen extends StatefulWidget {
  const CustomerOrdersScreen({
    required this.api,
    this.onOpenOrder,
    this.actionApi,
    this.onOpenCart,
    super.key,
  });

  final CustomerOrdersApi api;
  final ValueChanged<CustomerOrderSummary>? onOpenOrder;
  final CustomerActionApi? actionApi;
  final ValueChanged<CustomerOrderSummary>? onOpenCart;

  @override
  State<CustomerOrdersScreen> createState() => _CustomerOrdersScreenState();
}

class _CustomerOrdersScreenState extends State<CustomerOrdersScreen>
    with SingleTickerProviderStateMixin, WidgetsBindingObserver {
  static const _channels = <String>['b2b', 'b2c'];

  late final TabController _tabController;
  Timer? _refreshTimer;
  final Set<int> _reordering = <int>{};
  final Map<String, _OrdersTabState> _tabs = <String, _OrdersTabState>{
    'b2b': _OrdersTabState(),
    'b2c': _OrdersTabState(),
  };

  String get _activeChannel => _channels[_tabController.index];

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _tabController = TabController(length: _channels.length, vsync: this)
      ..addListener(_onTabChanged);
    for (final channel in _channels) {
      unawaited(_loadChannel(channel));
    }
    _refreshTimer = Timer.periodic(
      CustomerOrderRefreshPolicy.openOrderPollInterval,
      (_) => _refreshActiveOpenOrders(),
    );
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _refreshTimer?.cancel();
    _tabController
      ..removeListener(_onTabChanged)
      ..dispose();
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed && mounted) {
      unawaited(_loadChannel(_activeChannel));
    }
  }

  void _refreshActiveOpenOrders() {
    if (!mounted) return;
    final tab = _tabs[_activeChannel]!;
    if (tab.loading || tab.loadingMore || tab.orders.isEmpty) return;
    if (tab.orders.every((order) => order.isTerminal)) return;
    unawaited(_loadChannel(_activeChannel));
  }

  void _onTabChanged() {
    if (!_tabController.indexIsChanging && mounted) {
      setState(() {});
    }
  }

  Future<void> _loadChannel(
    String channel, {
    bool reset = true,
  }) async {
    final tab = _tabs[channel]!;
    if ((reset && tab.loading) || (!reset && tab.loadingMore)) return;

    setState(() {
      if (reset) {
        tab.loading = true;
      } else {
        tab.loadingMore = true;
      }
      tab.error = null;
    });

    final targetPage = reset ? 1 : tab.currentPage + 1;

    try {
      final page = await widget.api.orders(
        page: targetPage,
        channel: channel,
        status: tab.selectedStatus,
      );

      final mixedChannel = page.orders.any(
        (order) => order.channel.toLowerCase() != channel,
      );
      if (mixedChannel) {
        throw const CustomerOrdersException('mixed_order_channel');
      }

      if (!mounted) return;
      setState(() {
        tab.orders = reset
            ? List<CustomerOrderSummary>.of(page.orders)
            : _mergeOrders(tab.orders, page.orders);
        tab.currentPage = page.currentPage;
        tab.total = page.total;
        tab.allTotal = page.allTotal;
        tab.statusCodes = List<String>.of(page.statusCodes);
        tab.statusCounts = Map<String, int>.of(page.statusCounts);
        tab.loading = false;
        tab.loadingMore = false;
        tab.error = null;
      });
    } catch (error) {
      if (!mounted) return;
      setState(() {
        tab.loading = false;
        tab.loadingMore = false;
        tab.error = error;
      });
    }
  }

  List<CustomerOrderSummary> _mergeOrders(
    List<CustomerOrderSummary> current,
    List<CustomerOrderSummary> incoming,
  ) {
    final merged = <String, CustomerOrderSummary>{
      for (final order in current)
        '${order.channel}:${order.storeId}:${order.id}': order,
    };
    for (final order in incoming) {
      merged['${order.channel}:${order.storeId}:${order.id}'] = order;
    }
    return merged.values.toList(growable: false);
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        key: const ValueKey('customer-orders-screen'),
        backgroundColor: CustomerUiColors.mint,
        appBar: AppBar(
          title: Text(context.tr('customer.profile.orders')),
          bottom: TabBar(
            controller: _tabController,
            tabs: [
              Tab(
                key: const ValueKey('customer-orders-tab-b2b'),
                text: context.tr('customer.orders.tab.wholesale'),
              ),
              Tab(
                key: const ValueKey('customer-orders-tab-b2c'),
                text: context.tr('customer.orders.tab.retail'),
              ),
            ],
          ),
          actions: [
            IconButton(
              key: const ValueKey('customer-orders-refresh'),
              tooltip: context.tr('customer.orders.refresh'),
              onPressed: _tabs[_activeChannel]!.loading
                  ? null
                  : () => unawaited(_loadChannel(_activeChannel)),
              icon: const Icon(Icons.refresh_rounded),
            ),
          ],
        ),
        body: TabBarView(
          controller: _tabController,
          children: [
            _channelBody(context, 'b2b'),
            _channelBody(context, 'b2c'),
          ],
        ),
      );

  Widget _channelBody(BuildContext context, String channel) {
    final tab = _tabs[channel]!;

    return Column(
      children: [
        if (tab.statusCodes.isNotEmpty)
          _statusFilters(context, channel, tab),
        Expanded(
          child: RefreshIndicator(
            onRefresh: () => _loadChannel(channel),
            child: _channelContent(context, channel, tab),
          ),
        ),
      ],
    );
  }

  Widget _statusFilters(
    BuildContext context,
    String channel,
    _OrdersTabState tab,
  ) {
    return SizedBox(
      height: 58,
      child: ListView(
        key: ValueKey('customer-orders-status-filters-$channel'),
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsetsDirectional.fromSTEB(16, 8, 16, 6),
        children: [
          Padding(
            padding: const EdgeInsetsDirectional.only(end: 8),
            child: FilterChip(
              key: ValueKey('customer-orders-status-$channel-all'),
              label: Text(
                '${context.tr('customer.orders.status.all')} (${tab.allTotal})',
              ),
              selected: tab.selectedStatus == null,
              onSelected: (_) => _selectStatus(channel, null),
            ),
          ),
          for (final status in tab.statusCodes)
            Padding(
              padding: const EdgeInsetsDirectional.only(end: 8),
              child: FilterChip(
                key: ValueKey('customer-orders-status-$channel-$status'),
                label: Text(
                  '${_statusText(context, status)} '
                  '(${tab.statusCounts[status] ?? 0})',
                ),
                selected: tab.selectedStatus == status,
                onSelected: (_) => _selectStatus(channel, status),
              ),
            ),
        ],
      ),
    );
  }

  void _selectStatus(String channel, String? status) {
    final tab = _tabs[channel]!;
    if (tab.selectedStatus == status || tab.loading) return;
    setState(() {
      tab.selectedStatus = status;
    });
    unawaited(_loadChannel(channel));
  }

  Future<void> _reorder(CustomerOrderSummary order) async {
    final actionApi = widget.actionApi;
    if (actionApi == null ||
        order.reorderItems.isEmpty ||
        _reordering.contains(order.id)) {
      return;
    }

    setState(() => _reordering.add(order.id));
    final failed = <String>[];
    var added = 0;

    for (final item in order.reorderItems) {
      try {
        await actionApi.addCartItem(
          storeId: order.storeId,
          productId: item.productId,
          quantity: item.quantity,
        );
        added += 1;
      } catch (_) {
        failed.add(item.name.trim().isEmpty ? item.sku : item.name.trim());
      }
    }

    if (!mounted) return;
    setState(() => _reordering.remove(order.id));

    final messenger = ScaffoldMessenger.of(context);
    messenger.hideCurrentSnackBar();

    if (added == 0) {
      messenger.showSnackBar(
        SnackBar(
          content: Text(
            '${context.tr('customer.orders.reorder_failed')}'
            '${failed.isEmpty ? '' : ': ${failed.join(', ')}'}',
          ),
        ),
      );
      return;
    }

    final message = failed.isEmpty
        ? context.tr('customer.orders.reorder_success')
        : '${context.tr('customer.orders.reorder_partial')}: '
            '${failed.join(', ')}';
    messenger.showSnackBar(
      SnackBar(
        content: Text(message),
        action: widget.onOpenCart == null
            ? null
            : SnackBarAction(
                label: context.tr('customer.orders.open_cart'),
                onPressed: () => widget.onOpenCart!(order),
              ),
      ),
    );
  }

  Widget _channelContent(
    BuildContext context,
    String channel,
    _OrdersTabState tab,
  ) {
    if (tab.loading && tab.orders.isEmpty) {
      return const _OrdersListSkeleton();
    }

    if (tab.error != null && tab.orders.isEmpty) {
      return _ScrollableState(
        child: CustomerStateView(
          kind: CustomerStateKind.error,
          title: context.tr('customer.error.action_failed'),
          message: _errorText(context, tab.error!),
          actionLabel: context.tr('customer.action.retry'),
          onAction: () => unawaited(_loadChannel(channel)),
          icon: Icons.receipt_long_outlined,
        ),
      );
    }

    if (tab.orders.isEmpty) {
      return _ScrollableState(
        child: CustomerStateView(
          kind: CustomerStateKind.empty,
          title: context.tr('customer.orders.empty'),
          message: context.tr('customer.orders.subtitle'),
          icon: channel == 'b2b'
              ? Icons.warehouse_outlined
              : Icons.storefront_outlined,
        ),
      );
    }

    final hasFooter = tab.hasMore || tab.error != null;
    return ListView.separated(
      key: ValueKey('customer-orders-list-$channel'),
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 28),
      itemCount: tab.orders.length + (hasFooter ? 1 : 0),
      separatorBuilder: (_, __) => const SizedBox(height: 10),
      itemBuilder: (context, index) {
        if (index < tab.orders.length) {
          final order = tab.orders[index];
          return _OrderCard(
            order: order,
            onTap: widget.onOpenOrder == null
                ? null
                : () => widget.onOpenOrder!(order),
            onReorder: widget.actionApi != null && _canReorder(order)
                ? () => unawaited(_reorder(order))
                : null,
            reordering: _reordering.contains(order.id),
          );
        }

        if (tab.error != null) {
          return Padding(
            padding: const EdgeInsets.symmetric(vertical: 8),
            child: Column(
              children: [
                Text(
                  '${context.tr('customer.orders.stale')} '
                  '${_errorText(context, tab.error!)}',
                  textAlign: TextAlign.center,
                ),
                const SizedBox(height: 8),
                OutlinedButton(
                  key: ValueKey('customer-orders-retry-$channel'),
                  onPressed: tab.loadingMore
                      ? null
                      : () => unawaited(
                            _loadChannel(channel, reset: false),
                          ),
                  child: Text(context.tr('customer.action.retry')),
                ),
              ],
            ),
          );
        }

        return Padding(
          padding: const EdgeInsets.symmetric(vertical: 8),
          child: Center(
            child: OutlinedButton.icon(
              key: ValueKey('customer-orders-load-more-$channel'),
              onPressed: tab.loadingMore
                  ? null
                  : () => unawaited(
                        _loadChannel(channel, reset: false),
                      ),
              icon: const Icon(Icons.expand_more_rounded),
              label: Text(context.tr('customer.orders.load_more')),
            ),
          ),
        );
      },
    );
  }
}

class _OrdersTabState {
  List<CustomerOrderSummary> orders = <CustomerOrderSummary>[];
  int currentPage = 0;
  int total = 0;
  int allTotal = 0;
  List<String> statusCodes = <String>[];
  Map<String, int> statusCounts = <String, int>{};
  String? selectedStatus;
  bool loading = false;
  bool loadingMore = false;
  Object? error;

  bool get hasMore => orders.length < total;
}

class CustomerOrderDetailsScreen extends StatefulWidget {
  const CustomerOrderDetailsScreen({
    required this.api,
    required this.orderId,
    this.orderContext,
    this.lifecycleNotifications,
    this.trackingOnly = false,
    super.key,
  });

  final CustomerOrdersApi api;
  final int orderId;
  final CustomerOrderContext? orderContext;
  final Stream<Map<String, dynamic>>? lifecycleNotifications;
  final bool trackingOnly;

  @override
  State<CustomerOrderDetailsScreen> createState() =>
      _CustomerOrderDetailsScreenState();
}

class CustomerOrderTrackingScreen extends StatelessWidget {
  const CustomerOrderTrackingScreen({
    required this.api,
    required this.orderId,
    this.orderContext,
    this.lifecycleNotifications,
    super.key,
  });

  final CustomerOrdersApi api;
  final int orderId;
  final CustomerOrderContext? orderContext;
  final Stream<Map<String, dynamic>>? lifecycleNotifications;

  @override
  Widget build(BuildContext context) => CustomerOrderDetailsScreen(
        api: api,
        orderId: orderId,
        orderContext: orderContext,
        lifecycleNotifications: lifecycleNotifications,
        trackingOnly: true,
      );
}

class _CustomerOrderDetailsScreenState
    extends State<CustomerOrderDetailsScreen> {
  CustomerOrderDetails? _order;
  Object? _error;
  bool _loading = true;
  DateTime? _lastUpdatedAt;
  Timer? _pollTimer;
  StreamSubscription<Map<String, dynamic>>? _notificationSubscription;

  @override
  void initState() {
    super.initState();
    _listenForLifecycleNotifications();
    unawaited(_load());
  }

  @override
  void didUpdateWidget(covariant CustomerOrderDetailsScreen oldWidget) {
    super.didUpdateWidget(oldWidget);

    if (oldWidget.lifecycleNotifications != widget.lifecycleNotifications) {
      unawaited(_notificationSubscription?.cancel());
      _listenForLifecycleNotifications();
    }

    if (oldWidget.orderId != widget.orderId ||
        oldWidget.orderContext?.storeId != widget.orderContext?.storeId ||
        oldWidget.orderContext?.normalizedChannel !=
            widget.orderContext?.normalizedChannel) {
      _pollTimer?.cancel();
      _order = null;
      unawaited(_load());
    }
  }

  @override
  void dispose() {
    _pollTimer?.cancel();
    unawaited(_notificationSubscription?.cancel());
    super.dispose();
  }

  void _listenForLifecycleNotifications() {
    _notificationSubscription = widget.lifecycleNotifications?.listen((data) {
      final intent = CustomerOrderNotificationIntent.fromData(data);
      if (intent?.orderId != widget.orderId) return;

      final eventContext = intent?.context;
      final screenContext = widget.orderContext;
      if (eventContext != null &&
          screenContext != null &&
          (eventContext.storeId != screenContext.storeId ||
              eventContext.normalizedChannel !=
                  screenContext.normalizedChannel)) {
        return;
      }

      // A push is only a refresh signal. We never render its status as truth.
      unawaited(_load(silent: true));
    });
  }

  Future<void> _load({bool silent = false}) async {
    if (!silent) {
      setState(() {
        _loading = true;
        _error = null;
      });
    }

    try {
      final order = await widget.api.order(
        orderId: widget.orderId,
        context: widget.orderContext,
      );
      if (!mounted) return;

      setState(() {
        _order = order;
        _error = null;
        _loading = false;
        _lastUpdatedAt = DateTime.now();
      });
      _schedulePolling(order.summary.status);
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _error = error;
        _loading = false;
      });
      if (_order == null) _pollTimer?.cancel();
    }
  }

  void _schedulePolling(String status) {
    _pollTimer?.cancel();
    if (!CustomerOrderRefreshPolicy.shouldPoll(status)) return;

    _pollTimer = Timer(
      CustomerOrderRefreshPolicy.openOrderPollInterval,
      () => _load(silent: true),
    );
  }

  @override
  Widget build(BuildContext context) {
    final title = widget.trackingOnly
        ? context.tr('customer.tracking.title')
        : context.tr('customer.order.details_title');

    return Scaffold(
      key: ValueKey(
        widget.trackingOnly
            ? 'customer-order-tracking-${widget.orderId}'
            : 'customer-order-details-${widget.orderId}',
      ),
      backgroundColor: CustomerUiColors.mint,
      appBar: AppBar(
        title: Text(title),
        actions: [
          IconButton(
            key: const ValueKey('customer-order-refresh'),
            tooltip: context.tr('customer.orders.refresh'),
            onPressed: _loading ? null : _load,
            icon: const Icon(Icons.refresh_rounded),
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: _load,
        child: _body(context),
      ),
    );
  }

  Widget _body(BuildContext context) {
    if (_loading && _order == null) {
      return const _OrderDetailsSkeleton();
    }

    if (_error != null && _order == null) {
      return _ScrollableState(
        child: CustomerStateView(
          kind: CustomerStateKind.error,
          title: context.tr('customer.error.action_failed'),
          message: _errorText(context, _error!),
          actionLabel: context.tr('customer.action.retry'),
          onAction: () => unawaited(_load()),
          icon: Icons.location_searching_rounded,
        ),
      );
    }

    final order = _order;
    if (order == null) {
      return _ScrollableState(
        child: CustomerStateView(
          kind: CustomerStateKind.empty,
          title: context.tr('customer.empty'),
          message: context.tr('customer.orders.subtitle'),
          icon: Icons.location_searching_rounded,
        ),
      );
    }

    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 28),
      children: [
        _OrderHeader(details: order),
        if (_lastUpdatedAt != null) ...[
          const SizedBox(height: 8),
          Text(
            '${context.tr('customer.order.last_updated')}: '
            '${_formatClock(_lastUpdatedAt!)}',
            textAlign: TextAlign.end,
            style: Theme.of(context).textTheme.bodySmall,
          ),
        ],
        const SizedBox(height: 14),
        _Section(
          title: context.tr('customer.order.timeline'),
          child: _OrderTimeline(details: order),
        ),
        const SizedBox(height: 14),
        _Section(
          title: context.tr('customer.order.tracking'),
          child: Text(context.tr('customer.order.no_live_tracking')),
        ),
        if (!widget.trackingOnly) ...[
          const SizedBox(height: 14),
          _Section(
            title: context.tr('customer.order.items'),
            child: _OrderItems(details: order),
          ),
          const SizedBox(height: 14),
          _Section(
            title: context.tr('customer.order.address'),
            child: _AddressSnapshot(details: order),
          ),
          const SizedBox(height: 14),
          _Section(
            title: context.tr('customer.order.payment'),
            child: _PaymentSummary(details: order),
          ),
        ],
      ],
    );
  }
}

class _OrderCard extends StatelessWidget {
  const _OrderCard({
    required this.order,
    required this.onTap,
    required this.onReorder,
    required this.reordering,
  });

  final CustomerOrderSummary order;
  final VoidCallback? onTap;
  final VoidCallback? onReorder;
  final bool reordering;

  @override
  Widget build(BuildContext context) {
    final channel = order.channel == 'b2b'
        ? context.tr('customer.orders.channel.wholesale')
        : context.tr('customer.orders.channel.retail');
    final store = order.storeName?.trim();
    final title = order.orderNumber.isEmpty ? '#${order.id}' : order.orderNumber;

    return Material(
      key: ValueKey('customer-order-${order.id}'),
      color: CustomerUiColors.white,
      borderRadius: BorderRadius.circular(CustomerUiRadii.xl),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: onTap,
        child: DecoratedBox(
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(CustomerUiRadii.xl),
            border: Border.all(color: CustomerUiColors.border),
            boxShadow: CustomerUiElevation.cardShadow,
          ),
          child: Padding(
            padding: const EdgeInsets.all(CustomerUiSpacing.md),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                DecoratedBox(
                  decoration: const BoxDecoration(
                    color: CustomerUiColors.mint,
                    shape: BoxShape.circle,
                  ),
                  child: SizedBox.square(
                    dimension: 54,
                    child: ClipOval(
                      child: order.storeLogoUrl == null
                          ? Icon(
                              order.channel == 'b2b'
                                  ? Icons.warehouse_outlined
                                  : Icons.storefront_outlined,
                              color: CustomerUiColors.deepGreenStrong,
                            )
                          : Image.network(
                              order.storeLogoUrl!,
                              fit: BoxFit.cover,
                              errorBuilder: (_, __, ___) => Icon(
                                order.channel == 'b2b'
                                    ? Icons.warehouse_outlined
                                    : Icons.storefront_outlined,
                                color: CustomerUiColors.deepGreenStrong,
                              ),
                            ),
                    ),
                  ),
                ),
                const SizedBox(width: CustomerUiSpacing.sm),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Expanded(
                            child: Text(
                              title,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: Theme.of(context).textTheme.titleMedium,
                            ),
                          ),
                          const SizedBox(width: CustomerUiSpacing.xs),
                          _StatusChip(status: order.status),
                        ],
                      ),
                      const SizedBox(height: CustomerUiSpacing.xs),
                      Text(
                        store == null || store.isEmpty ? channel : store,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                              color: CustomerUiColors.muted,
                            ),
                      ),
                      const SizedBox(height: CustomerUiSpacing.sm),
                      Wrap(
                        spacing: CustomerUiSpacing.xs,
                        runSpacing: CustomerUiSpacing.xs,
                        crossAxisAlignment: WrapCrossAlignment.center,
                        children: [
                          CustomerBadge(label: channel),
                          Text(
                            '${order.grandTotal.toStringAsFixed(3)} ${order.currency}',
                            style: Theme.of(context).textTheme.titleMedium?.copyWith(
                                  color: CustomerUiColors.deepGreenStrong,
                                  fontWeight: FontWeight.w800,
                                ),
                          ),
                        ],
                      ),
                      const SizedBox(height: CustomerUiSpacing.xs),
                      Wrap(
                        spacing: CustomerUiSpacing.sm,
                        runSpacing: CustomerUiSpacing.xxs,
                        children: [
                          if (order.createdAt != null)
                            Text(
                              _formatDateTime(order.createdAt!),
                              style: Theme.of(context).textTheme.bodySmall?.copyWith(
                                    color: CustomerUiColors.muted,
                                  ),
                            ),
                          Text(
                            '${context.tr('customer.orders.items_count')}: '
                            '${order.itemCount}',
                            style: Theme.of(context).textTheme.bodySmall?.copyWith(
                                  color: CustomerUiColors.muted,
                                ),
                          ),
                        ],
                      ),
                      const SizedBox(height: CustomerUiSpacing.sm),
                      _OrderDualStatus(order: order),
                      if (_nextMeaningfulStatus(order) != null)
                        Padding(
                          padding: const EdgeInsets.only(top: CustomerUiSpacing.xxs),
                          child: Text(
                            '${context.tr('customer.orders.next')}: '
                            '${_statusText(context, _nextMeaningfulStatus(order)!)}',
                            style: Theme.of(context).textTheme.bodySmall?.copyWith(
                                  color: CustomerUiColors.deepGreenSoft,
                                  fontWeight: FontWeight.w700,
                                ),
                          ),
                        ),
                      if (onReorder != null) ...[
                        const SizedBox(height: CustomerUiSpacing.sm),
                        OutlinedButton.icon(
                          key: ValueKey('customer-order-reorder-${order.id}'),
                          onPressed: reordering ? null : onReorder,
                          icon: reordering
                              ? const SizedBox.square(
                                  dimension: 16,
                                  child: CircularProgressIndicator(strokeWidth: 2),
                                )
                              : const Icon(Icons.replay_rounded),
                          label: Text(context.tr('customer.orders.reorder')),
                        ),
                      ],
                    ],
                  ),
                ),
                if (onTap != null) ...[
                  const SizedBox(width: CustomerUiSpacing.xs),
                  const Padding(
                    padding: EdgeInsets.only(top: 2),
                    child: Icon(
                      Icons.arrow_forward_ios_rounded,
                      size: 18,
                      color: CustomerUiColors.deepGreenStrong,
                    ),
                  ),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _OrderDualStatus extends StatelessWidget {
  const _OrderDualStatus({required this.order});

  final CustomerOrderSummary order;

  @override
  Widget build(BuildContext context) {
    final approval = _approvalText(context, order);
    final financial = _financialText(context, order);
    final appliedCredit = order.appliedCustomerCreditAmount;

    return DecoratedBox(
      decoration: BoxDecoration(
        color: CustomerUiColors.mint,
        borderRadius: BorderRadius.circular(CustomerUiRadii.lg),
        border: Border.all(color: CustomerUiColors.border),
      ),
      child: Padding(
        padding: const EdgeInsets.all(CustomerUiSpacing.sm),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            _OrderStatusLine(
              key: ValueKey('customer-order-approval-${order.id}'),
              icon: Icons.verified_outlined,
              label: context.tr('customer.orders.approval.label'),
              value: approval,
            ),
            const SizedBox(height: CustomerUiSpacing.xs),
            _OrderStatusLine(
              key: ValueKey('customer-order-financial-${order.id}'),
              icon: Icons.account_balance_wallet_outlined,
              label: context.tr('customer.orders.financial.label'),
              value: financial,
            ),
            if (appliedCredit != null && appliedCredit > 0) ...[
              const SizedBox(height: CustomerUiSpacing.xs),
              _OrderStatusLine(
                key: ValueKey('customer-order-balance-applied-${order.id}'),
                icon: Icons.savings_outlined,
                label: context.tr('customer.orders.financial.balance_applied'),
                value:
                    '${appliedCredit.toStringAsFixed(3)} ${order.currency}',
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class _OrderStatusLine extends StatelessWidget {
  const _OrderStatusLine({
    required this.icon,
    required this.label,
    required this.value,
    super.key,
  });

  final IconData icon;
  final String label;
  final String value;

  @override
  Widget build(BuildContext context) => Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(
            icon,
            size: 18,
            color: CustomerUiColors.deepGreenStrong,
          ),
          const SizedBox(width: CustomerUiSpacing.xs),
          Expanded(
            child: Text.rich(
              TextSpan(
                children: [
                  TextSpan(
                    text: '$label: ',
                    style: Theme.of(context).textTheme.bodySmall?.copyWith(
                          color: CustomerUiColors.muted,
                          fontWeight: FontWeight.w700,
                        ),
                  ),
                  TextSpan(
                    text: value,
                    style: Theme.of(context).textTheme.bodySmall?.copyWith(
                          color: CustomerUiColors.deepGreenStrong,
                          fontWeight: FontWeight.w800,
                        ),
                  ),
                ],
              ),
            ),
          ),
        ],
      );
}

class _OrderHeader extends StatelessWidget {
  const _OrderHeader({required this.details});

  final CustomerOrderDetails details;

  @override
  Widget build(BuildContext context) {
    final order = details.summary;
    final channel = order.channel == 'b2b'
        ? context.tr('customer.orders.channel.wholesale')
        : context.tr('customer.orders.channel.retail');

    return DecoratedBox(
      decoration: BoxDecoration(
        color: CustomerUiColors.deepGreen,
        borderRadius: BorderRadius.circular(CustomerUiRadii.xl),
      ),
      child: Padding(
        padding: const EdgeInsets.all(CustomerUiSpacing.lg),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    order.orderNumber.isEmpty ? '#${order.id}' : order.orderNumber,
                    style: Theme.of(context).textTheme.titleLarge?.copyWith(
                          color: CustomerUiColors.white,
                        ),
                  ),
                ),
                _StatusChip(status: order.status, inverted: true),
              ],
            ),
            const SizedBox(height: CustomerUiSpacing.xs),
            Text(
              '${order.storeName ?? channel} · $channel',
              style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                    color: CustomerUiColors.white.withValues(alpha: .82),
                  ),
            ),
            const SizedBox(height: CustomerUiSpacing.lg),
            Text(
              '${order.grandTotal.toStringAsFixed(3)} ${order.currency}',
              style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                    color: CustomerUiColors.lime,
                    fontWeight: FontWeight.w800,
                  ),
            ),
          ],
        ),
      ),
    );
  }
}

class _StatusChip extends StatelessWidget {
  const _StatusChip({required this.status, this.inverted = false});

  final String status;
  final bool inverted;

  @override
  Widget build(BuildContext context) {
    final tone = _statusTone(status);
    if (!inverted) {
      return CustomerBadge(
        label: _statusText(context, status),
        tone: tone,
      );
    }

    return DecoratedBox(
      decoration: BoxDecoration(
        color: CustomerUiColors.white.withValues(alpha: .12),
        borderRadius: BorderRadius.circular(CustomerUiRadii.pill),
        border: Border.all(
          color: CustomerUiColors.white.withValues(alpha: .18),
        ),
      ),
      child: Padding(
        padding: const EdgeInsetsDirectional.fromSTEB(10, 6, 10, 6),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(_statusIcon(status), size: 16, color: CustomerUiColors.lime),
            const SizedBox(width: CustomerUiSpacing.xxs),
            Text(
              _statusText(context, status),
              style: Theme.of(context).textTheme.labelLarge?.copyWith(
                    color: CustomerUiColors.white,
                    fontSize: 12,
                  ),
            ),
          ],
        ),
      ),
    );
  }
}

class _OrderTimeline extends StatelessWidget {
  const _OrderTimeline({required this.details});

  final CustomerOrderDetails details;

  @override
  Widget build(BuildContext context) {
    final entries = details.history;
    if (entries.isEmpty) {
      return _TimelineRow(
        status: details.summary.status,
        isLast: true,
      );
    }

    return Column(
      children: [
        for (var index = 0; index < entries.length; index++)
          _TimelineRow(
            status: entries[index].toStatus,
            note: entries[index].note,
            createdAt: entries[index].createdAt,
            isLast: index == entries.length - 1,
          ),
      ],
    );
  }
}

class _TimelineRow extends StatelessWidget {
  const _TimelineRow({
    required this.status,
    required this.isLast,
    this.note,
    this.createdAt,
  });

  final String status;
  final String? note;
  final DateTime? createdAt;
  final bool isLast;

  @override
  Widget build(BuildContext context) {
    final color = _statusColor(status);
    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SizedBox(
            width: 30,
            child: Column(
              children: [
                DecoratedBox(
                  decoration: BoxDecoration(
                    color: color.withValues(alpha: .12),
                    shape: BoxShape.circle,
                    border: Border.all(color: color),
                  ),
                  child: SizedBox.square(
                    dimension: 26,
                    child: Icon(_statusIcon(status), size: 15, color: color),
                  ),
                ),
                if (!isLast)
                  Expanded(
                    child: Container(
                      width: 2,
                      margin: const EdgeInsets.symmetric(vertical: 4),
                      color: CustomerUiColors.border,
                    ),
                  ),
              ],
            ),
          ),
          const SizedBox(width: CustomerUiSpacing.sm),
          Expanded(
            child: Padding(
              padding: EdgeInsets.only(
                bottom: isLast ? 0 : CustomerUiSpacing.md,
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Wrap(
                    spacing: CustomerUiSpacing.xs,
                    runSpacing: CustomerUiSpacing.xxs,
                    crossAxisAlignment: WrapCrossAlignment.center,
                    children: [
                      Text(
                        _statusText(context, status),
                        style: Theme.of(context).textTheme.titleMedium,
                      ),
                      if (createdAt != null)
                        Text(
                          _formatDateTime(createdAt!),
                          style: Theme.of(context).textTheme.bodySmall?.copyWith(
                                color: CustomerUiColors.muted,
                              ),
                        ),
                    ],
                  ),
                  if (note?.trim().isNotEmpty == true) ...[
                    const SizedBox(height: CustomerUiSpacing.xxs),
                    Text(
                      note!,
                      maxLines: 3,
                      overflow: TextOverflow.ellipsis,
                      style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                            color: CustomerUiColors.muted,
                          ),
                    ),
                  ],
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _OrderItems extends StatelessWidget {
  const _OrderItems({required this.details});

  final CustomerOrderDetails details;

  @override
  Widget build(BuildContext context) {
    if (details.items.isEmpty) return Text(context.tr('customer.empty'));

    return Column(
      children: details.items
          .map(
            (item) => ListTile(
              contentPadding: EdgeInsets.zero,
              title: Text(item.name.isEmpty ? item.sku : item.name),
              subtitle: Text(
                '${context.tr('customer.cart.quantity')}: ${_quantity(item.quantity)}',
              ),
              trailing: Text(
                '${item.lineTotal.toStringAsFixed(3)} ${details.summary.currency}',
                style: const TextStyle(fontWeight: FontWeight.w700),
              ),
            ),
          )
          .toList(growable: false),
    );
  }
}

class _AddressSnapshot extends StatelessWidget {
  const _AddressSnapshot({required this.details});

  final CustomerOrderDetails details;

  @override
  Widget build(BuildContext context) {
    final address = details.deliveryAddress;
    if (address == null || address.isEmpty) {
      return Text(context.tr('customer.empty'));
    }

    final values = <String>[
      for (final key in const [
        'label',
        'building',
        'street',
        'block',
        'area',
        'city',
        'governorate',
        'country',
        'phone',
      ])
        if (address[key]?.toString().trim().isNotEmpty == true)
          address[key].toString().trim(),
    ];

    return Text(
      values.isEmpty ? context.tr('customer.empty') : values.join(' · '),
    );
  }
}

class _PaymentSummary extends StatelessWidget {
  const _PaymentSummary({required this.details});

  final CustomerOrderDetails details;

  @override
  Widget build(BuildContext context) {
    final payment = details.payment;
    if (payment == null) {
      return Text(
        details.paymentMethod?.trim().isNotEmpty == true
            ? details.paymentMethod!
            : context.tr('customer.empty'),
      );
    }

    return ListTile(
      contentPadding: EdgeInsets.zero,
      leading: const Icon(Icons.payments_outlined),
      title: Text(payment.provider),
      subtitle: Text(payment.status),
      trailing: Text(
        '${payment.amount.toStringAsFixed(3)} ${payment.currency}',
        style: const TextStyle(fontWeight: FontWeight.w700),
      ),
    );
  }
}

class _Section extends StatelessWidget {
  const _Section({required this.title, required this.child});

  final String title;
  final Widget child;

  @override
  Widget build(BuildContext context) => DecoratedBox(
        decoration: BoxDecoration(
          color: CustomerUiColors.white,
          borderRadius: BorderRadius.circular(CustomerUiRadii.xl),
          border: Border.all(color: CustomerUiColors.border),
        ),
        child: Padding(
          padding: const EdgeInsets.all(CustomerUiSpacing.md),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(
                title,
                style: Theme.of(context).textTheme.titleMedium?.copyWith(
                      color: CustomerUiColors.deepGreenStrong,
                    ),
              ),
              const SizedBox(height: CustomerUiSpacing.sm),
              child,
            ],
          ),
        ),
      );
}

class _OrdersListSkeleton extends StatelessWidget {
  const _OrdersListSkeleton();

  @override
  Widget build(BuildContext context) => ListView.separated(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.fromLTRB(
          CustomerUiSpacing.page,
          CustomerUiSpacing.sm,
          CustomerUiSpacing.page,
          CustomerUiSpacing.xl,
        ),
        itemCount: 4,
        separatorBuilder: (_, __) =>
            const SizedBox(height: CustomerUiSpacing.sm),
        itemBuilder: (_, __) => const _OrderCardSkeleton(),
      );
}

class _OrderCardSkeleton extends StatelessWidget {
  const _OrderCardSkeleton();

  @override
  Widget build(BuildContext context) => DecoratedBox(
        decoration: BoxDecoration(
          color: CustomerUiColors.white,
          borderRadius: BorderRadius.circular(CustomerUiRadii.xl),
          border: Border.all(color: CustomerUiColors.border),
        ),
        child: const Padding(
          padding: EdgeInsets.all(CustomerUiSpacing.md),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              CustomerSkeletonBox(height: 54, width: 54, radius: 27),
              SizedBox(width: CustomerUiSpacing.sm),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    CustomerSkeletonBox(height: 18, width: 120, radius: 9),
                    SizedBox(height: CustomerUiSpacing.xs),
                    CustomerSkeletonBox(height: 14, width: 170, radius: 7),
                    SizedBox(height: CustomerUiSpacing.sm),
                    CustomerSkeletonBox(height: 24, width: 145, radius: 12),
                  ],
                ),
              ),
            ],
          ),
        ),
      );
}

class _OrderDetailsSkeleton extends StatelessWidget {
  const _OrderDetailsSkeleton();

  @override
  Widget build(BuildContext context) => ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.fromLTRB(
          CustomerUiSpacing.page,
          CustomerUiSpacing.sm,
          CustomerUiSpacing.page,
          CustomerUiSpacing.xl,
        ),
        children: const [
          CustomerSkeletonBox(height: 152, radius: CustomerUiRadii.xl),
          SizedBox(height: CustomerUiSpacing.md),
          CustomerSkeletonBox(height: 178, radius: CustomerUiRadii.xl),
          SizedBox(height: CustomerUiSpacing.md),
          CustomerSkeletonBox(height: 108, radius: CustomerUiRadii.xl),
        ],
      );
}

class _ScrollableState extends StatelessWidget {
  const _ScrollableState({required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) => ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        children: [
          SizedBox(
            height: MediaQuery.sizeOf(context).height * .62,
            child: Center(child: child),
          ),
        ],
      );
}



CustomerBadgeTone _statusTone(String status) {
  switch (status.toLowerCase()) {
    case 'delivered':
    case 'completed':
      return CustomerBadgeTone.success;
    case 'cancelled':
    case 'refunded':
    case 'failed':
      return CustomerBadgeTone.discount;
    case 'pending':
    case 'confirmed':
    case 'processing':
    case 'paid':
    case 'accepted':
      return CustomerBadgeTone.accent;
    default:
      return CustomerBadgeTone.neutral;
  }
}

Color _statusColor(String status) {
  switch (status.toLowerCase()) {
    case 'delivered':
    case 'completed':
      return CustomerUiColors.success;
    case 'cancelled':
    case 'refunded':
    case 'failed':
      return CustomerUiColors.destructive;
    case 'out_for_delivery':
    case 'picked_up':
    case 'assigned':
      return CustomerUiColors.info;
    case 'pending':
    case 'confirmed':
    case 'processing':
    case 'paid':
    case 'accepted':
      return CustomerUiColors.warning;
    default:
      return CustomerUiColors.deepGreenSoft;
  }
}

IconData _statusIcon(String status) {
  switch (status.toLowerCase()) {
    case 'delivered':
    case 'completed':
      return Icons.check_rounded;
    case 'cancelled':
    case 'refunded':
    case 'failed':
      return Icons.close_rounded;
    case 'out_for_delivery':
      return Icons.local_shipping_outlined;
    case 'picked_up':
      return Icons.inventory_2_outlined;
    case 'assigned':
      return Icons.assignment_ind_outlined;
    default:
      return Icons.schedule_rounded;
  }
}

bool _canReorder(CustomerOrderSummary order) =>
    order.reorderItems.isNotEmpty &&
    const <String>{'delivered', 'failed', 'cancelled'}
        .contains(order.status.toLowerCase());

String? _nextMeaningfulStatus(CustomerOrderSummary order) {
  for (final status in order.nextStatuses) {
    if (status.toLowerCase() != 'cancelled') return status;
  }
  return null;
}

String _approvalText(
  BuildContext context,
  CustomerOrderSummary order,
) {
  final explicit = order.approvalStatus?.trim().toLowerCase();
  switch (explicit) {
    case 'pending':
    case 'pending_approval':
    case 'awaiting_approval':
      return context.tr('customer.orders.approval.pending');
    case 'approved':
    case 'confirmed':
      return context.tr('customer.orders.approval.approved');
    case 'rejected':
    case 'declined':
      return context.tr('customer.orders.approval.rejected');
    case 'cancelled':
    case 'canceled':
      return context.tr('customer.orders.approval.cancelled');
  }

  switch (order.status.toLowerCase()) {
    case 'pending':
      return context.tr('customer.orders.approval.pending');
    case 'confirmed':
    case 'accepted':
    case 'preparing':
    case 'ready':
    case 'assigned':
    case 'picked_up':
    case 'out_for_delivery':
    case 'delivered':
    case 'failed':
      return context.tr('customer.orders.approval.approved');
    case 'cancelled':
      return context.tr('customer.orders.approval.cancelled');
    default:
      return _statusText(context, order.status);
  }
}

String _financialText(
  BuildContext context,
  CustomerOrderSummary order,
) {
  final outstanding = order.invoiceOutstandingAmount;
  if (order.fullySettled == true ||
      (outstanding != null && outstanding <= 0.0000001)) {
    return context.tr('customer.orders.financial.settled');
  }

  if (outstanding == null) {
    return context.tr('customer.orders.financial.unavailable');
  }

  final amount = '${outstanding.toStringAsFixed(3)} ${order.currency}';
  switch (order.remainderMethod?.trim().toLowerCase()) {
    case 'account_debt':
    case 'account':
    case 'debt':
    case 'credit_account':
      return '${context.tr('customer.orders.financial.debt')} $amount';
    case 'cash_on_delivery':
    case 'cod':
      return '${context.tr('customer.orders.financial.cod')} $amount';
    default:
      return '${context.tr('customer.orders.financial.amount_due')} $amount';
  }
}

String _statusText(BuildContext context, String status) {
  final normalized = status.toLowerCase();
  final key = 'customer.order.status.$normalized';
  final translated = context.tr(key);
  return translated == key ? normalized.replaceAll('_', ' ') : translated;
}

String _errorText(BuildContext context, Object error) {
  if (error is CustomerOrdersException) {
    switch (error.code) {
      case 'network_unavailable':
        return context.tr('customer.error.offline');
      case 'session_expired':
      case 'authentication_required':
        return context.tr('customer.error.session_expired');
      case 'forbidden':
        return context.tr('customer.error.forbidden');
    }
  }
  return context.tr('customer.error.action_failed');
}

String _quantity(double value) => value == value.roundToDouble()
    ? value.toInt().toString()
    : value.toStringAsFixed(2);

String _formatClock(DateTime value) =>
    '${value.hour.toString().padLeft(2, '0')}:'
    '${value.minute.toString().padLeft(2, '0')}';

String _formatDateTime(DateTime value) =>
    '${value.year.toString().padLeft(4, '0')}-'
    '${value.month.toString().padLeft(2, '0')}-'
    '${value.day.toString().padLeft(2, '0')} '
    '${_formatClock(value)}';
