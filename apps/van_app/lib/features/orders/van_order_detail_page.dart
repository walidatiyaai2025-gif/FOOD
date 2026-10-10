import 'dart:async';

import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/auth/van_session.dart';
import '../../core/theme/foodex_van_theme.dart';
import '../../shared/van_action_button.dart';
import 'van_order_contract.dart';

typedef VanOrderNavigationLauncher = Future<bool> Function(
  double latitude,
  double longitude,
);

class VanOrderDetailPage extends StatefulWidget {
  const VanOrderDetailPage({
    super.key,
    required this.orderId,
    required this.repository,
    required this.onSessionExpired,
    this.navigationLauncher,
  });

  final int orderId;
  final VanOrderRepository repository;
  final Future<void> Function() onSessionExpired;
  final VanOrderNavigationLauncher? navigationLauncher;

  @override
  State<VanOrderDetailPage> createState() => _VanOrderDetailPageState();
}

class _VanOrderDetailPageState extends State<VanOrderDetailPage>
    with WidgetsBindingObserver {
  bool _loading = true;
  bool _refreshing = false;
  bool _submitting = false;
  bool _stale = false;
  Object? _error;
  VanOrderDetail? _detail;
  VanOrderExecutionState? _execution;

  bool get _arabic => Localizations.localeOf(context).languageCode == 'ar';
  String _text(String en, String ar) => _arabic ? ar : en;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    unawaited(_load());
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed &&
        !_loading &&
        !_refreshing &&
        !_submitting) {
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
        if (background && _detail != null) {
          _refreshing = true;
        } else {
          _loading = true;
        }
        _error = null;
      });
    }

    try {
      final results = await Future.wait<Object>([
        widget.repository.order(widget.orderId),
        widget.repository.execution(widget.orderId),
      ]);
      if (!mounted) return;
      setState(() {
        _detail = results[0] as VanOrderDetail;
        _execution = results[1] as VanOrderExecutionState;
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
        if (background && _detail != null) {
          _stale = true;
        } else {
          _error = error;
        }
      });
    }
  }

  Future<void> _transition(String status) async {
    if (_submitting) return;
    setState(() => _submitting = true);

    try {
      final execution = await widget.repository.transitionOrder(
        orderId: widget.orderId,
        status: status,
        idempotencyKey:
            'van-ui-${widget.orderId}-$status-${DateTime.now().microsecondsSinceEpoch}',
      );
      final detail = await widget.repository.order(widget.orderId);
      if (!mounted) return;
      setState(() {
        _execution = execution;
        _detail = detail;
        _stale = false;
      });
    } on VanSessionExpiredException {
      await widget.onSessionExpired();
    } catch (error) {
      if (!mounted) return;
      final message = error is VanOfflineException
          ? _text(
              'Network unavailable. The order was not changed locally.',
              'لا يوجد اتصال بالشبكة. لم يتم تغيير الطلب محليًا.',
            )
          : error is VanAccessDeniedException
              ? _text('Access denied.', 'غير مصرح بهذه العملية.')
              : _text(
                  'The server rejected this delivery action.',
                  'رفض الخادم إجراء التسليم هذا.',
                );
      ScaffoldMessenger.of(context)
        ..hideCurrentSnackBar()
        ..showSnackBar(SnackBar(content: Text(message)));
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  Future<void> _openMap(VanOrderDeliveryAddress address) async {
    final latitude = address.latitude;
    final longitude = address.longitude;
    if (!address.hasCoordinates || latitude == null || longitude == null) {
      return;
    }

    final launcher = widget.navigationLauncher ??
        (lat, lng) => launchUrl(
              Uri.parse(
                'https://www.google.com/maps/search/?api=1&query=$lat,$lng',
              ),
              mode: LaunchMode.externalApplication,
            );
    final opened = await launcher(latitude, longitude);
    if (!opened && mounted) {
      ScaffoldMessenger.of(context)
        ..hideCurrentSnackBar()
        ..showSnackBar(
          SnackBar(
            content: Text(
              _text(
                'Unable to open navigation.',
                'تعذر فتح تطبيق الملاحة.',
              ),
            ),
          ),
        );
    }
  }

  String _statusLabel(String value) {
    switch (value) {
      case 'assigned':
        return _text('Assigned', 'مسند');
      case 'accepted':
        return _text('Accepted', 'مقبول');
      case 'picked_up':
        return _text('Picked up', 'تم الاستلام');
      case 'out_for_delivery':
        return _text('Out for delivery', 'خرج للتسليم');
      case 'delivered':
        return _text('Delivered', 'تم التسليم');
      case 'failed':
        return _text('Failed', 'تعذر التسليم');
      default:
        return value.replaceAll('_', ' ');
    }
  }

  String _actionLabel(String value) {
    switch (value) {
      case 'accepted':
        return _text('Accept order', 'قبول الطلب');
      case 'picked_up':
        return _text('Confirm pickup', 'تأكيد الاستلام');
      case 'out_for_delivery':
        return _text('Start delivery', 'بدء التوصيل');
      case 'delivered':
        return _text('Mark delivered', 'تأكيد التسليم');
      default:
        return _statusLabel(value);
    }
  }

  IconData _actionIcon(String value) {
    switch (value) {
      case 'accepted':
        return Icons.check_circle_outline;
      case 'picked_up':
        return Icons.inventory_2_outlined;
      case 'out_for_delivery':
        return Icons.local_shipping_outlined;
      case 'delivered':
        return Icons.task_alt;
      default:
        return Icons.arrow_forward;
    }
  }

  List<String> get _deliveryActions {
    final allowed = _execution?.allowedActions ?? const <String>[];
    return allowed
        .where(
          (value) => const {
            'accepted',
            'picked_up',
            'out_for_delivery',
            'delivered',
          }.contains(value),
        )
        .toList(growable: false);
  }

  Widget _section({
    required String title,
    required Widget child,
    Key? key,
  }) =>
      Card(
        key: key,
        elevation: 0,
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(
                title,
                style: Theme.of(context).textTheme.titleMedium?.copyWith(
                      fontWeight: FontWeight.w900,
                    ),
              ),
              const SizedBox(height: 10),
              child,
            ],
          ),
        ),
      );

  Widget _money(String currency, double amount) => Text(
        '${amount.toStringAsFixed(3)} $currency',
        maxLines: 1,
        overflow: TextOverflow.ellipsis,
      );

  @override
  Widget build(BuildContext context) {
    if (_loading && _detail == null) {
      return const Scaffold(
        body: Center(child: CircularProgressIndicator()),
      );
    }

    if (_error != null && _detail == null) {
      return Scaffold(
        appBar: AppBar(title: Text(_text('Order detail', 'تفاصيل الطلب'))),
        body: Center(
          child: Padding(
            padding: const EdgeInsets.all(20),
            child: VanActionButton.secondary(
              onPressed: _load,
              child: Text(_text('Retry', 'إعادة المحاولة')),
            ),
          ),
        ),
      );
    }

    final detail = _detail!;
    final order = detail.summary;
    final execution = _execution;
    final address = detail.deliveryAddress;
    final customer = detail.customer;
    final invoice = detail.invoice;

    return Scaffold(
      appBar: AppBar(
        title: Text(
          order.orderNumber,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
        ),
      ),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          key: const ValueKey('van-order-detail-page'),
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
                          'Showing the last confirmed order detail.',
                          'يتم عرض آخر تفاصيل طلب مؤكدة.',
                        )
                      : _text(
                          'Refreshing order detail…',
                          'جارٍ تحديث تفاصيل الطلب…',
                        ),
                ),
              ),
            _section(
              key: const ValueKey('van-order-detail-summary'),
              title: _text('Delivery status', 'حالة التسليم'),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Row(
                    children: [
                      Expanded(
                        child: Text(
                          _statusLabel(
                            execution?.status ??
                                order.vanExecutionStatus ??
                                order.status,
                          ),
                          style:
                              Theme.of(context).textTheme.titleLarge?.copyWith(
                                    fontWeight: FontWeight.w900,
                                  ),
                        ),
                      ),
                      const SizedBox(width: 8),
                      _money(order.currency, order.grandTotal),
                    ],
                  ),
                  if (execution?.proofRequiredForDelivered == true) ...[
                    const SizedBox(height: 8),
                    Text(
                      _text(
                        'Delivery proof is required before Delivered can be accepted by the server.',
                        'إثبات التسليم مطلوب قبل أن يقبل الخادم حالة تم التسليم.',
                      ),
                      style: Theme.of(context).textTheme.bodySmall?.copyWith(
                            color: FoodexVanTokens.muted,
                          ),
                    ),
                  ],
                  for (final action in _deliveryActions) ...[
                    const SizedBox(height: 10),
                    VanActionButton.icon(
                      key: ValueKey('van-order-action-$action'),
                      onPressed: _submitting ? null : () => _transition(action),
                      icon: Icon(_actionIcon(action)),
                      label: Text(_actionLabel(action)),
                    ),
                  ],
                ],
              ),
            ),
            _section(
              title: _text('Customer', 'العميل'),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    customer?.name ?? _text('Assigned customer', 'عميل مسند'),
                    style: const TextStyle(fontWeight: FontWeight.w800),
                  ),
                  if (customer?.phone != null) Text(customer!.phone!),
                  if (customer?.email != null) Text(customer!.email!),
                ],
              ),
            ),
            _section(
              key: const ValueKey('van-order-detail-address'),
              title: _text('Delivery address', 'عنوان التسليم'),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text(
                    address != null && address.formatted.trim().isNotEmpty
                        ? address.formatted
                        : _text(
                            'No delivery address snapshot.',
                            'لا توجد نسخة محفوظة لعنوان التسليم.',
                          ),
                  ),
                  if (address?.hasCoordinates == true) ...[
                    const SizedBox(height: 8),
                    VanActionButton.secondaryIcon(
                      key: const ValueKey('van-order-open-map'),
                      onPressed: () => _openMap(address!),
                      icon: const Icon(Icons.navigation_outlined),
                      label: Text(_text('Open navigation', 'فتح الملاحة')),
                    ),
                  ],
                ],
              ),
            ),
            _section(
              title: _text('Items', 'الأصناف'),
              child: detail.items.isEmpty
                  ? Text(_text('No items.', 'لا توجد أصناف.'))
                  : Column(
                      children: [
                        for (final item in detail.items)
                          ListTile(
                            dense: true,
                            contentPadding: EdgeInsets.zero,
                            title: Text(
                              item.name,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                            ),
                            subtitle: Text(
                              '${item.sku} · ${item.quantity.toStringAsFixed(3)}',
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                            ),
                            trailing: _money(order.currency, item.lineTotal),
                          ),
                      ],
                    ),
            ),
            _section(
              title: _text('Invoice & collection', 'الفاتورة والتحصيل'),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  if (invoice != null) ...[
                    Text(
                      invoice.number,
                      style: const TextStyle(fontWeight: FontWeight.w800),
                    ),
                    Text(
                      '${_text('Outstanding', 'المتبقي')}: '
                      '${invoice.outstandingAmount.toStringAsFixed(3)} '
                      '${invoice.currency}',
                    ),
                  ] else
                    Text(_text('No invoice yet.', 'لا توجد فاتورة بعد.')),
                  const SizedBox(height: 8),
                  Text(
                    '${_text('Payments', 'المدفوعات')}: '
                    '${detail.payments.length} · '
                    '${_text('Collections', 'التحصيلات')}: '
                    '${detail.collections.length}',
                  ),
                  for (final collection in detail.collections)
                    Text(
                      '${collection.amount.toStringAsFixed(3)} '
                      '${collection.currency} · ${collection.status}',
                    ),
                ],
              ),
            ),
            _section(
              key: const ValueKey('van-order-detail-timeline'),
              title: _text('Timeline', 'الخط الزمني'),
              child: detail.timeline.isEmpty
                  ? Text(_text('No timeline events.', 'لا توجد أحداث.'))
                  : Column(
                      children: [
                        for (final event in detail.timeline)
                          ListTile(
                            dense: true,
                            contentPadding: EdgeInsets.zero,
                            leading: const Icon(
                              Icons.circle,
                              size: 10,
                              color: FoodexVanTokens.green,
                            ),
                            title: Text(_statusLabel(event.stage)),
                            subtitle: Text(
                              event.occurredAt == null
                                  ? event.source
                                  : '${event.source} · ${event.occurredAt}',
                              maxLines: 2,
                              overflow: TextOverflow.ellipsis,
                            ),
                          ),
                      ],
                    ),
            ),
          ],
        ),
      ),
    );
  }
}
