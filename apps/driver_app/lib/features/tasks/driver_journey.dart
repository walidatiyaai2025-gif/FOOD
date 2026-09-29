import 'package:flutter/material.dart';

import '../../core/auth/driver_session.dart';
import '../../core/localization/driver_translations.dart';
import '../../core/theme/foodex_theme.dart';

enum DriverLoadState { loading, ready, empty, error, offline }

enum DriverOrderFilter { active, completed, failed, all }

class DriverOrderItem {
  const DriverOrderItem({
    required this.name,
    required this.quantity,
    required this.lineTotal,
    this.sku = '',
  });

  final String name;
  final String sku;
  final double quantity;
  final double lineTotal;
}

class DriverInvoice {
  const DriverInvoice({
    required this.id,
    required this.number,
    required this.status,
    required this.currency,
    required this.grandTotal,
    this.revision = 1,
    this.subtotal = 0,
    this.discountTotal = 0,
    this.deliveryTotal = 0,
    this.taxTotal = 0,
    this.paymentMethod = '',
    this.paymentStatus = '',
    this.issuedAt = '',
    this.items = const [],
  });

  final int id;
  final String number;
  final int revision;
  final String status;
  final String currency;
  final double subtotal;
  final double discountTotal;
  final double deliveryTotal;
  final double taxTotal;
  final double grandTotal;
  final String paymentMethod;
  final String paymentStatus;
  final String issuedAt;
  final List<DriverOrderItem> items;
}

class DriverAssignment {
  const DriverAssignment({
    required this.id,
    required this.channel,
    required this.reference,
    required this.status,
    this.orderId = 0,
    this.storeId = 0,
    this.storeName = '',
    this.orderStatus = '',
    this.customerName = '',
    this.customerPhone = '',
    this.address = '',
    this.currency = 'KWD',
    this.grandTotal = 0,
    this.paymentMethod = '',
    this.paymentStatus = '',
    this.customerNote = '',
    this.items = const [],
    this.availableStatuses = const [],
    this.invoice,
  });

  final int id;
  final int orderId;
  final int storeId;
  final DriverChannel channel;
  final String reference;
  final String status;
  final String storeName;
  final String orderStatus;
  final String customerName;
  final String customerPhone;
  final String address;
  final String currency;
  final double grandTotal;
  final String paymentMethod;
  final String paymentStatus;
  final String customerNote;
  final List<DriverOrderItem> items;
  final List<String> availableStatuses;
  final DriverInvoice? invoice;
}

abstract interface class DriverAssignmentRepository {
  Future<List<DriverAssignment>> list(DriverChannel channel);

  Future<void> transition(
    int id,
    DriverChannel channel,
    String status, {
    String? note,
  });
}

class DriverJourneyPage extends StatefulWidget {
  const DriverJourneyPage({
    super.key,
    required this.channel,
    required this.repository,
    this.onSessionExpired,
  });

  final DriverChannel channel;
  final DriverAssignmentRepository repository;
  final VoidCallback? onSessionExpired;

  @override
  State<DriverJourneyPage> createState() => _DriverJourneyPageState();
}

class _DriverJourneyPageState extends State<DriverJourneyPage> {
  DriverLoadState state = DriverLoadState.loading;
  List<DriverAssignment> assignments = const [];
  DriverOrderFilter _filter = DriverOrderFilter.active;
  final TextEditingController _searchController = TextEditingController();
  final Set<int> _transitioning = <int>{};
  String? _actionError;

  @override
  void initState() {
    super.initState();
    _searchController.addListener(_refresh);
    _load();
  }

  @override
  void dispose() {
    _searchController
      ..removeListener(_refresh)
      ..dispose();
    super.dispose();
  }

  void _refresh() {
    if (mounted) setState(() {});
  }

  Future<void> _load() async {
    if (mounted) setState(() => state = DriverLoadState.loading);
    try {
      final rows = await widget.repository.list(widget.channel);
      if (!mounted) return;
      final filtered = rows
          .where((row) => row.channel == widget.channel)
          .toList(growable: false);
      setState(() {
        assignments = filtered;
        state = filtered.isEmpty ? DriverLoadState.empty : DriverLoadState.ready;
      });
    } on DriverSessionExpiredException {
      widget.onSessionExpired?.call();
      if (mounted && widget.onSessionExpired == null) {
        setState(() => state = DriverLoadState.error);
      }
    } on DriverOfflineException {
      if (mounted) setState(() => state = DriverLoadState.offline);
    } catch (_) {
      if (mounted) setState(() => state = DriverLoadState.error);
    }
  }

  List<DriverAssignment> get _visibleAssignments {
    final query = _searchController.text.trim().toLowerCase();

    return assignments.where((assignment) {
      final matchesFilter = switch (_filter) {
        DriverOrderFilter.active =>
          !const ['delivered', 'failed', 'cancelled', 'unassigned', 'reassigned']
              .contains(assignment.status),
        DriverOrderFilter.completed => assignment.status == 'delivered',
        DriverOrderFilter.failed => assignment.status == 'failed',
        DriverOrderFilter.all => true,
      };
      if (!matchesFilter) return false;
      if (query.isEmpty) return true;

      return [
        assignment.reference,
        assignment.customerName,
        assignment.customerPhone,
        assignment.address,
        assignment.storeName,
      ].any((value) => value.toLowerCase().contains(query));
    }).toList(growable: false);
  }

  Future<void> _transition(
    DriverAssignment assignment,
    String status, {
    String? note,
  }) async {
    if (_transitioning.contains(assignment.id)) return;
    setState(() {
      _transitioning.add(assignment.id);
      _actionError = null;
    });
    try {
      await widget.repository.transition(
        assignment.id,
        widget.channel,
        status,
        note: note,
      );
      await _load();
    } on DriverSessionExpiredException {
      widget.onSessionExpired?.call();
    } on DriverOfflineException {
      if (mounted) setState(() => _actionError = context.tr('driver.offline'));
    } catch (_) {
      if (mounted) setState(() => _actionError = context.tr('driver.error'));
    } finally {
      if (mounted) setState(() => _transitioning.remove(assignment.id));
    }
  }

  Future<void> _performAction(
    DriverAssignment assignment,
    String status,
  ) async {
    final controller = TextEditingController();
    final note = await showDialog<String>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: Text(
          status == 'failed'
              ? context.tr('driver.failure.title')
              : context.tr('driver.action.confirm_title'),
        ),
        content: TextField(
          key: Key('driver-status-note-$status'),
          controller: controller,
          maxLength: 1000,
          maxLines: 3,
          decoration: InputDecoration(
            labelText: status == 'failed'
                ? context.tr('driver.failure.reason')
                : context.tr('driver.action.note_optional'),
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(dialogContext).pop(),
            child: Text(context.tr('driver.dismiss')),
          ),
          FilledButton(
            key: Key('driver-status-confirm-$status'),
            onPressed: () {
              final value = controller.text.trim();
              if (status == 'failed' && value.isEmpty) return;
              Navigator.of(dialogContext).pop(value);
            },
            child: Text(
              status == 'failed'
                  ? context.tr('driver.failure.submit')
                  : context.tr('driver.action.confirm'),
            ),
          ),
        ],
      ),
    );
    controller.dispose();
    if (note == null) return;
    await _transition(
      assignment,
      status,
      note: note.trim().isEmpty ? null : note.trim(),
    );
  }

  Future<void> _showInvoice(DriverAssignment assignment) async {
    final invoice = assignment.invoice;
    if (invoice == null) return;

    await showDialog<void>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: Text('${context.tr('driver.invoice.title')} ${invoice.number}'),
        content: SizedBox(
          width: 520,
          child: SingleChildScrollView(
            key: Key('driver-invoice-detail-${invoice.id}'),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                _DetailLine(
                  label: context.tr('driver.invoice.status'),
                  value: invoice.status,
                ),
                _DetailLine(
                  label: context.tr('driver.invoice.revision'),
                  value: invoice.revision.toString(),
                ),
                if (invoice.issuedAt.isNotEmpty)
                  _DetailLine(
                    label: context.tr('driver.invoice.issued_at'),
                    value: invoice.issuedAt,
                  ),
                const Divider(height: 24),
                ...invoice.items.map(
                  (item) => Padding(
                    padding: const EdgeInsets.symmetric(vertical: 4),
                    child: Row(
                      children: [
                        Expanded(
                          child: Text(
                            item.sku.isEmpty
                                ? item.name
                                : '${item.sku} · ${item.name}',
                          ),
                        ),
                        Text(
                          '${item.quantity.toStringAsFixed(3)} · ${item.lineTotal.toStringAsFixed(3)} ${invoice.currency}',
                        ),
                      ],
                    ),
                  ),
                ),
                const Divider(height: 24),
                _DetailLine(
                  label: context.tr('driver.invoice.subtotal'),
                  value:
                      '${invoice.subtotal.toStringAsFixed(3)} ${invoice.currency}',
                ),
                _DetailLine(
                  label: context.tr('driver.invoice.discount'),
                  value:
                      '${invoice.discountTotal.toStringAsFixed(3)} ${invoice.currency}',
                ),
                _DetailLine(
                  label: context.tr('driver.invoice.delivery'),
                  value:
                      '${invoice.deliveryTotal.toStringAsFixed(3)} ${invoice.currency}',
                ),
                _DetailLine(
                  label: context.tr('driver.invoice.tax'),
                  value:
                      '${invoice.taxTotal.toStringAsFixed(3)} ${invoice.currency}',
                ),
                _DetailLine(
                  label: context.tr('driver.invoice.total'),
                  value:
                      '${invoice.grandTotal.toStringAsFixed(3)} ${invoice.currency}',
                ),
                _DetailLine(
                  label: context.tr('driver.invoice.payment'),
                  value:
                      '${invoice.paymentMethod} · ${invoice.paymentStatus}',
                ),
              ],
            ),
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(dialogContext).pop(),
            child: Text(context.tr('driver.dismiss')),
          ),
        ],
      ),
    );
  }

  Future<void> _showDetail(DriverAssignment assignment) async {
    await showModalBottomSheet<void>(
      context: context,
      showDragHandle: true,
      isScrollControlled: true,
      builder: (sheetContext) => SafeArea(
        child: FractionallySizedBox(
          heightFactor: .88,
          child: SingleChildScrollView(
            key: Key('assignment-detail-${assignment.id}'),
            padding: const EdgeInsets.fromLTRB(20, 8, 20, 28),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(
                  context.tr('driver.detail.title'),
                  style: Theme.of(context).textTheme.headlineSmall,
                ),
                const SizedBox(height: 12),
                _DetailLine(
                  label: context.tr('driver.detail.order'),
                  value: assignment.reference,
                ),
                _DetailLine(
                  label: context.tr('driver.detail.order_status'),
                  value: context.tr(
                    'driver.order_status.${assignment.orderStatus}',
                  ),
                ),
                _DetailLine(
                  label: context.tr('driver.detail.store'),
                  value: assignment.storeName,
                ),
                _DetailLine(
                  label: context.tr('driver.detail.customer'),
                  value: assignment.customerName,
                ),
                if (assignment.customerPhone.isNotEmpty)
                  _DetailLine(
                    label: context.tr('driver.detail.phone'),
                    value: assignment.customerPhone,
                  ),
                if (assignment.address.isNotEmpty)
                  _DetailLine(
                    label: context.tr('driver.detail.address'),
                    value: assignment.address,
                  ),
                if (assignment.customerNote.isNotEmpty)
                  _DetailLine(
                    label: context.tr('driver.detail.note'),
                    value: assignment.customerNote,
                  ),
                const Divider(height: 28),
                Text(
                  context.tr('driver.detail.items'),
                  style: Theme.of(context).textTheme.titleMedium,
                ),
                const SizedBox(height: 8),
                if (assignment.items.isEmpty)
                  Text(context.tr('driver.detail.no_items'))
                else
                  ...assignment.items.map(
                    (item) => Padding(
                      padding: const EdgeInsets.symmetric(vertical: 5),
                      child: Row(
                        children: [
                          Expanded(
                            child: Text(
                              item.sku.isEmpty
                                  ? item.name
                                  : '${item.sku} · ${item.name}',
                            ),
                          ),
                          Text(
                            '${item.quantity.toStringAsFixed(3)} · ${item.lineTotal.toStringAsFixed(3)} ${assignment.currency}',
                          ),
                        ],
                      ),
                    ),
                  ),
                const Divider(height: 28),
                _DetailLine(
                  label: context.tr('driver.detail.total'),
                  value:
                      '${assignment.grandTotal.toStringAsFixed(3)} ${assignment.currency}',
                ),
                _DetailLine(
                  label: context.tr('driver.detail.payment'),
                  value: assignment.paymentMethod.isEmpty
                      ? context.tr('driver.detail.unknown')
                      : '${assignment.paymentMethod} · ${assignment.paymentStatus}',
                ),
                if (assignment.invoice != null) ...[
                  const SizedBox(height: 10),
                  OutlinedButton.icon(
                    key: Key('driver-open-invoice-${assignment.id}'),
                    onPressed: () => _showInvoice(assignment),
                    icon: const Icon(Icons.receipt_long_rounded),
                    label: Text(
                      '${context.tr('driver.invoice.open')} · ${assignment.invoice!.number}',
                    ),
                  ),
                ],
                const SizedBox(height: 18),
                if (assignment.availableStatuses.isEmpty)
                  Text(context.tr('driver.action.none'))
                else
                  Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    children: assignment.availableStatuses
                        .map(
                          (status) => FilledButton(
                            key: Key(
                              'assignment-status-${assignment.id}-$status',
                            ),
                            onPressed: _transitioning.contains(assignment.id)
                                ? null
                                : () {
                                    Navigator.of(sheetContext).pop();
                                    _performAction(assignment, status);
                                  },
                            child: Text(context.tr('driver.status.$status')),
                          ),
                        )
                        .toList(growable: false),
                  ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final visible = _visibleAssignments;

    return Scaffold(
      appBar: AppBar(
        title: Text(
          context.tr(
            widget.channel == DriverChannel.b2c
                ? 'driver.b2c.title'
                : 'driver.b2b.title',
          ),
        ),
        actions: [
          IconButton(
            key: const Key('driver-refresh-assignments'),
            tooltip: context.tr('driver.refresh'),
            onPressed: state == DriverLoadState.loading ? null : _load,
            icon: const Icon(Icons.refresh_rounded),
          ),
        ],
      ),
      body: Column(
        children: [
          if (_actionError != null)
            MaterialBanner(
              content: Text(
                _actionError!,
                key: const Key('driver-action-error'),
              ),
              actions: [
                TextButton(
                  onPressed: () => setState(() => _actionError = null),
                  child: Text(context.tr('driver.dismiss')),
                ),
              ],
            ),
          if (state == DriverLoadState.ready)
            Container(
              margin: const EdgeInsets.fromLTRB(16, 12, 16, 8),
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                color: FoodexBrand.surface,
                borderRadius: BorderRadius.circular(20),
                border: Border.all(color: FoodexBrand.border),
                boxShadow: const [
                  BoxShadow(
                    color: Color(0x10172033),
                    blurRadius: 18,
                    offset: Offset(0, 8),
                  ),
                ],
              ),
              child: Column(
                children: [
                  TextField(
                    key: const Key('driver-order-search'),
                    controller: _searchController,
                    decoration: InputDecoration(
                      prefixIcon: const Icon(Icons.search),
                      hintText: context.tr('driver.search'),
                    ),
                  ),
                  const SizedBox(height: 10),
                  SingleChildScrollView(
                    scrollDirection: Axis.horizontal,
                    child: SegmentedButton<DriverOrderFilter>(
                      segments: [
                        ButtonSegment(
                          value: DriverOrderFilter.active,
                          label: Text(context.tr('driver.filter.active')),
                        ),
                        ButtonSegment(
                          value: DriverOrderFilter.completed,
                          label: Text(context.tr('driver.filter.completed')),
                        ),
                        ButtonSegment(
                          value: DriverOrderFilter.failed,
                          label: Text(context.tr('driver.filter.failed')),
                        ),
                        ButtonSegment(
                          value: DriverOrderFilter.all,
                          label: Text(context.tr('driver.filter.all')),
                        ),
                      ],
                      selected: {_filter},
                      onSelectionChanged: (value) {
                        setState(() => _filter = value.first);
                      },
                    ),
                  ),
                ],
              ),
            ),
          Expanded(
            child: switch (state) {
              DriverLoadState.loading => const Center(
                  child: CircularProgressIndicator(key: Key('driver-loading')),
                ),
              DriverLoadState.empty => Center(
                  child: Text(
                    context.tr('driver.empty'),
                    key: const Key('driver-empty'),
                  ),
                ),
              DriverLoadState.error => _Retry(
                  message: context.tr('driver.error'),
                  retryLabel: context.tr('driver.retry'),
                  onRetry: _load,
                  keyName: 'driver-error',
                ),
              DriverLoadState.offline => _Retry(
                  message: context.tr('driver.offline'),
                  retryLabel: context.tr('driver.retry'),
                  onRetry: _load,
                  keyName: 'driver-offline',
                ),
              DriverLoadState.ready => visible.isEmpty
                  ? Center(
                      child: Text(
                        context.tr('driver.filter.empty'),
                        key: const Key('driver-filter-empty'),
                      ),
                    )
                  : RefreshIndicator(
                      onRefresh: _load,
                      child: ListView.builder(
                        padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
                        itemCount: visible.length,
                        itemBuilder: (context, index) {
                          final assignment = visible[index];
                          return Card(
                            key: Key('assignment-${assignment.id}'),
                            margin: const EdgeInsets.only(bottom: 12),
                            elevation: 0,
                            child: ListTile(
                              contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                              leading: Container(
                                width: 46,
                                height: 46,
                                decoration: BoxDecoration(
                                  color: FoodexBrand.greenSoft,
                                  borderRadius: BorderRadius.circular(15),
                                ),
                                child: const Icon(
                                  Icons.local_shipping_rounded,
                                  color: FoodexBrand.greenDark,
                                ),
                              ),
                              title: Row(
                                children: [
                                  Expanded(
                                    child: Text(
                                      assignment.reference,
                                      style: const TextStyle(
                                        fontWeight: FontWeight.w800,
                                      ),
                                    ),
                                  ),
                                  Text(
                                    '${assignment.grandTotal.toStringAsFixed(3)} ${assignment.currency}',
                                    style: const TextStyle(
                                      fontWeight: FontWeight.w700,
                                    ),
                                  ),
                                ],
                              ),
                              subtitle: Padding(
                                padding: const EdgeInsets.only(top: 8),
                                child: Column(
                                  crossAxisAlignment:
                                      CrossAxisAlignment.stretch,
                                  children: [
                                    if (assignment.customerName.isNotEmpty)
                                      Text(assignment.customerName),
                                    if (assignment.address.isNotEmpty)
                                      Text(
                                        assignment.address,
                                        maxLines: 2,
                                        overflow: TextOverflow.ellipsis,
                                      ),
                                    const SizedBox(height: 8),
                                    Align(
                                      alignment:
                                          AlignmentDirectional.centerStart,
                                      child: DecoratedBox(
                                        decoration: BoxDecoration(
                                          color: FoodexBrand.statusSurface(
                                            assignment.status,
                                          ),
                                          borderRadius:
                                              BorderRadius.circular(999),
                                        ),
                                        child: Padding(
                                          padding: const EdgeInsets.symmetric(
                                            horizontal: 8,
                                            vertical: 4,
                                          ),
                                          child: Text(
                                            context.tr(
                                              'driver.status.${assignment.status}',
                                            ),
                                            style: TextStyle(
                                              color: FoodexBrand.statusColor(
                                                assignment.status,
                                              ),
                                              fontWeight: FontWeight.w700,
                                            ),
                                          ),
                                        ),
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                              trailing: Container(
                                width: 38,
                                height: 38,
                                decoration: BoxDecoration(
                                  color: FoodexBrand.surfaceMuted,
                                  borderRadius: BorderRadius.circular(12),
                                ),
                                child: const Icon(Icons.chevron_right_rounded),
                              ),
                              onTap: () => _showDetail(assignment),
                            ),
                          );
                        },
                      ),
                    ),
            },
          ),
        ],
      ),
    );
  }
}

class _DetailLine extends StatelessWidget {
  const _DetailLine({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 5),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            SizedBox(
              width: 112,
              child: Text(
                label,
                style: const TextStyle(fontWeight: FontWeight.w700),
              ),
            ),
            Expanded(child: Text(value.isEmpty ? '—' : value)),
          ],
        ),
      );
}

class _Retry extends StatelessWidget {
  const _Retry({
    required this.message,
    required this.retryLabel,
    required this.onRetry,
    required this.keyName,
  });

  final String message;
  final String retryLabel;
  final VoidCallback onRetry;
  final String keyName;

  @override
  Widget build(BuildContext context) => Center(
        key: Key(keyName),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(message),
            const SizedBox(height: 12),
            FilledButton(onPressed: onRetry, child: Text(retryLabel)),
          ],
        ),
      );
}
