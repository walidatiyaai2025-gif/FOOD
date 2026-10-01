import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/localization/app_translations.dart';
import 'customer_order_models.dart';
import 'customer_orders_api.dart';

class CustomerOrdersScreen extends StatefulWidget {
  const CustomerOrdersScreen({
    required this.api,
    this.onOpenOrder,
    super.key,
  });

  final CustomerOrdersApi api;
  final ValueChanged<CustomerOrderSummary>? onOpenOrder;

  @override
  State<CustomerOrdersScreen> createState() => _CustomerOrdersScreenState();
}

class _CustomerOrdersScreenState extends State<CustomerOrdersScreen> {
  CustomerOrderPage? _page;
  Object? _error;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    unawaited(_load());
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });

    try {
      final page = await widget.api.orders();
      if (!mounted) return;
      setState(() {
        _page = page;
        _loading = false;
      });
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _error = error;
        _loading = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        key: const ValueKey('customer-orders-screen'),
        appBar: AppBar(
          title: Text(context.tr('customer.profile.orders')),
          actions: [
            IconButton(
              key: const ValueKey('customer-orders-refresh'),
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

  Widget _body(BuildContext context) {
    if (_loading && _page == null) {
      return const _ScrollableState(child: CircularProgressIndicator());
    }

    if (_error != null && _page == null) {
      return _ScrollableState(
        child: _ErrorState(
          message: _errorText(context, _error!),
          onRetry: _load,
        ),
      );
    }

    final orders = _page?.orders ?? const <CustomerOrderSummary>[];
    if (orders.isEmpty) {
      return _ScrollableState(
        child: _EmptyState(
          title: context.tr('customer.orders.empty'),
          subtitle: context.tr('customer.orders.subtitle'),
        ),
      );
    }

    return ListView.separated(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 28),
      itemCount: orders.length,
      separatorBuilder: (_, __) => const SizedBox(height: 10),
      itemBuilder: (context, index) {
        final order = orders[index];
        return _OrderCard(
          order: order,
          onTap: widget.onOpenOrder == null
              ? null
              : () => widget.onOpenOrder!(order),
        );
      },
    );
  }
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
      return const _ScrollableState(child: CircularProgressIndicator());
    }

    if (_error != null && _order == null) {
      return _ScrollableState(
        child: _ErrorState(
          message: _errorText(context, _error!),
          onRetry: _load,
        ),
      );
    }

    final order = _order;
    if (order == null) {
      return _ScrollableState(
        child: _EmptyState(
          title: context.tr('customer.empty'),
          subtitle: context.tr('customer.orders.subtitle'),
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
            textAlign: TextAlignDirectional.end,
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
  const _OrderCard({required this.order, required this.onTap});

  final CustomerOrderSummary order;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final channel = order.channel == 'b2b'
        ? context.tr('customer.orders.channel.wholesale')
        : context.tr('customer.orders.channel.retail');
    final store = order.storeName?.trim();

    return Card(
      key: ValueKey('customer-order-${order.id}'),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              CircleAvatar(
                radius: 23,
                backgroundImage: order.storeLogoUrl == null
                    ? null
                    : NetworkImage(order.storeLogoUrl!),
                child: order.storeLogoUrl == null
                    ? Icon(
                        order.channel == 'b2b'
                            ? Icons.warehouse_outlined
                            : Icons.storefront_outlined,
                      )
                    : null,
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      order.orderNumber.isEmpty
                          ? '#${order.id}'
                          : order.orderNumber,
                      style: const TextStyle(
                        fontSize: 16,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                    const SizedBox(height: 4),
                    Text(
                      '${store == null || store.isEmpty ? channel : store} · $channel',
                    ),
                    const SizedBox(height: 6),
                    Wrap(
                      spacing: 8,
                      runSpacing: 6,
                      crossAxisAlignment: WrapCrossAlignment.center,
                      children: [
                        _StatusChip(status: order.status),
                        Text(
                          '${order.grandTotal.toStringAsFixed(3)} ${order.currency}',
                          style: const TextStyle(fontWeight: FontWeight.w700),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
              if (onTap != null) const Icon(Icons.chevron_right_rounded),
            ],
          ),
        ),
      ),
    );
  }
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

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              order.orderNumber.isEmpty ? '#${order.id}' : order.orderNumber,
              style: Theme.of(context).textTheme.titleLarge?.copyWith(
                    fontWeight: FontWeight.w800,
                  ),
            ),
            const SizedBox(height: 8),
            Text('${order.storeName ?? channel} · $channel'),
            const SizedBox(height: 10),
            Row(
              children: [
                Expanded(child: _StatusChip(status: order.status)),
                Text(
                  '${order.grandTotal.toStringAsFixed(3)} ${order.currency}',
                  style: const TextStyle(fontWeight: FontWeight.w800),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _StatusChip extends StatelessWidget {
  const _StatusChip({required this.status});

  final String status;

  @override
  Widget build(BuildContext context) => Chip(
        avatar: Icon(
          CustomerOrderRefreshPolicy.isTerminal(status)
              ? Icons.check_circle_outline_rounded
              : Icons.timelapse_rounded,
          size: 18,
        ),
        label: Text(_statusText(context, status)),
      );
}

class _OrderTimeline extends StatelessWidget {
  const _OrderTimeline({required this.details});

  final CustomerOrderDetails details;

  @override
  Widget build(BuildContext context) {
    if (details.history.isEmpty) {
      return ListTile(
        contentPadding: EdgeInsets.zero,
        leading: const Icon(Icons.radio_button_checked_rounded),
        title: Text(_statusText(context, details.summary.status)),
      );
    }

    return Column(
      children: details.history
          .map(
            (entry) => ListTile(
              contentPadding: EdgeInsets.zero,
              leading: const Icon(Icons.radio_button_checked_rounded, size: 18),
              title: Text(_statusText(context, entry.toStatus)),
              subtitle: entry.note == null
                  ? null
                  : Text(
                      entry.note!,
                      maxLines: 3,
                      overflow: TextOverflow.ellipsis,
                    ),
              trailing: entry.createdAt == null
                  ? null
                  : Text(
                      _formatDateTime(entry.createdAt!),
                      style: Theme.of(context).textTheme.bodySmall,
                    ),
            ),
          )
          .toList(growable: false),
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
  Widget build(BuildContext context) => Card(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(
                title,
                style: Theme.of(context).textTheme.titleMedium?.copyWith(
                      fontWeight: FontWeight.w800,
                    ),
              ),
              const SizedBox(height: 10),
              child,
            ],
          ),
        ),
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

class _EmptyState extends StatelessWidget {
  const _EmptyState({required this.title, required this.subtitle});

  final String title;
  final String subtitle;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.receipt_long_outlined, size: 48),
            const SizedBox(height: 12),
            Text(
              title,
              textAlign: TextAlign.center,
              style: const TextStyle(fontWeight: FontWeight.w800),
            ),
            const SizedBox(height: 6),
            Text(subtitle, textAlign: TextAlign.center),
          ],
        ),
      );
}

class _ErrorState extends StatelessWidget {
  const _ErrorState({required this.message, required this.onRetry});

  final String message;
  final Future<void> Function() onRetry;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.error_outline_rounded, size: 48),
            const SizedBox(height: 12),
            Text(message, textAlign: TextAlign.center),
            const SizedBox(height: 12),
            OutlinedButton.icon(
              key: const ValueKey('customer-orders-retry'),
              onPressed: onRetry,
              icon: const Icon(Icons.refresh_rounded),
              label: Text(context.tr('customer.action.retry')),
            ),
          ],
        ),
      );
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
