import 'package:flutter/material.dart';

import '../../../core/auth/driver_session.dart';
import '../../../core/localization/driver_translations.dart';
import '../../tasks/driver_journey.dart';

typedef DriverActiveAssignmentCallback = Future<void> Function(
  DriverAssignment assignment,
);

typedef DriverActiveFailureCallback = Future<void> Function(
  DriverAssignment assignment, {
  String? note,
});

class DriverActiveJourneyPage extends StatefulWidget {
  const DriverActiveJourneyPage({
    super.key,
    required this.channel,
    required this.repository,
    required this.onNavigationRequested,
    required this.onFailedDeliveryRequested,
    required this.onDeliveredRequested,
    this.onSessionExpired,
  });

  final DriverChannel channel;
  final DriverAssignmentRepository repository;
  final DriverActiveAssignmentCallback onNavigationRequested;
  final DriverActiveFailureCallback onFailedDeliveryRequested;
  final DriverActiveAssignmentCallback onDeliveredRequested;
  final VoidCallback? onSessionExpired;

  @override
  State<DriverActiveJourneyPage> createState() =>
      _DriverActiveJourneyPageState();
}

enum _DriverActiveLoadState { loading, ready, empty, error, offline }

class _DriverActiveJourneyPageState extends State<DriverActiveJourneyPage> {
  static const Set<String> _terminalStatuses = {
    'delivered',
    'failed',
    'cancelled',
    'unassigned',
    'reassigned',
  };

  _DriverActiveLoadState _state = _DriverActiveLoadState.loading;
  List<DriverAssignment> _assignments = const [];
  final Set<int> _busyAssignments = <int>{};
  String? _actionError;

  @override
  void initState() {
    super.initState();
    _load();
  }

  bool _allows(DriverAssignment assignment, String status) =>
      assignment.availableStatuses.contains(status);

  Future<void> _load() async {
    if (mounted) {
      setState(() {
        _state = _DriverActiveLoadState.loading;
        _actionError = null;
      });
    }

    try {
      final rows = await widget.repository.list(widget.channel);
      if (!mounted) return;

      final active = rows
          .where(
            (row) =>
                row.channel == widget.channel &&
                !_terminalStatuses.contains(row.status),
          )
          .toList(growable: false);

      setState(() {
        _assignments = active;
        _state = active.isEmpty
            ? _DriverActiveLoadState.empty
            : _DriverActiveLoadState.ready;
      });
    } on DriverSessionExpiredException {
      widget.onSessionExpired?.call();
      if (mounted && widget.onSessionExpired == null) {
        setState(() => _state = _DriverActiveLoadState.error);
      }
    } on DriverOfflineException {
      if (mounted) {
        setState(() => _state = _DriverActiveLoadState.offline);
      }
    } catch (_) {
      if (mounted) {
        setState(() => _state = _DriverActiveLoadState.error);
      }
    }
  }

  Future<void> _transition(
    DriverAssignment assignment,
    String status, {
    String? note,
  }) async {
    if (!_allows(assignment, status) ||
        _busyAssignments.contains(assignment.id)) {
      return;
    }

    setState(() {
      _busyAssignments.add(assignment.id);
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
      if (mounted) {
        setState(() => _actionError = context.tr('driver.offline'));
      }
    } catch (_) {
      if (mounted) {
        setState(() => _actionError = context.tr('driver.error'));
      }
    } finally {
      if (mounted) {
        setState(() => _busyAssignments.remove(assignment.id));
      }
    }
  }

  Future<void> _requestFailure(
    DriverAssignment assignment,
    String note,
  ) async {
    if (!_allows(assignment, 'failed') ||
        _busyAssignments.contains(assignment.id)) {
      return;
    }

    setState(() {
      _busyAssignments.add(assignment.id);
      _actionError = null;
    });

    try {
      await widget.onFailedDeliveryRequested(
        assignment,
        note: note.trim().isEmpty ? null : note.trim(),
      );
      await _load();
    } on DriverSessionExpiredException {
      widget.onSessionExpired?.call();
    } on DriverOfflineException {
      if (mounted) {
        setState(() => _actionError = context.tr('driver.offline'));
      }
    } catch (_) {
      if (mounted) {
        setState(() => _actionError = context.tr('driver.error'));
      }
    } finally {
      if (mounted) {
        setState(() => _busyAssignments.remove(assignment.id));
      }
    }
  }

  Future<void> _requestDelivered(DriverAssignment assignment) async {
    if (!_allows(assignment, 'delivered') ||
        _busyAssignments.contains(assignment.id)) {
      return;
    }

    setState(() {
      _busyAssignments.add(assignment.id);
      _actionError = null;
    });

    try {
      await widget.onDeliveredRequested(assignment);
      await _load();
    } on DriverSessionExpiredException {
      widget.onSessionExpired?.call();
    } on DriverOfflineException {
      if (mounted) {
        setState(() => _actionError = context.tr('driver.offline'));
      }
    } catch (_) {
      if (mounted) {
        setState(() => _actionError = context.tr('driver.error'));
      }
    } finally {
      if (mounted) {
        setState(() => _busyAssignments.remove(assignment.id));
      }
    }
  }

  Future<void> _showStartDeliverySheet(DriverAssignment assignment) async {
    if (!_allows(assignment, 'out_for_delivery') ||
        _busyAssignments.contains(assignment.id)) {
      return;
    }

    final noteController = TextEditingController();
    try {
      await showModalBottomSheet<void>(
        context: context,
        showDragHandle: true,
        isScrollControlled: true,
        builder: (sheetContext) {
          return SafeArea(
            top: false,
            child: Padding(
              padding: EdgeInsets.fromLTRB(
                20,
                4,
                20,
                20 + MediaQuery.of(sheetContext).viewInsets.bottom,
              ),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text(
                    context.tr('driver.action.start_delivery'),
                    style: Theme.of(context).textTheme.titleLarge?.copyWith(
                          fontWeight: FontWeight.w800,
                        ),
                  ),
                  const SizedBox(height: 6),
                  Text(
                    assignment.reference,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                  const SizedBox(height: 16),
                  TextField(
                    key: const Key('driver-active-start-note'),
                    controller: noteController,
                    minLines: 2,
                    maxLines: 4,
                    decoration: InputDecoration(
                      labelText: context.tr('driver.action.note_optional'),
                      border: const OutlineInputBorder(),
                    ),
                  ),
                  const SizedBox(height: 16),
                  Row(
                    children: [
                      if (_allows(assignment, 'failed')) ...[
                        Expanded(
                          child: OutlinedButton(
                            key: Key(
                              'driver-active-failed-${assignment.id}',
                            ),
                            onPressed: () {
                              final note = noteController.text;
                              Navigator.of(sheetContext).pop();
                              _requestFailure(assignment, note);
                            },
                            child: Text(
                              context.tr('driver.action.delivery_failed'),
                            ),
                          ),
                        ),
                        const SizedBox(width: 12),
                      ],
                      Expanded(
                        child: FilledButton(
                          key: Key(
                            'driver-active-confirm-start-${assignment.id}',
                          ),
                          onPressed: () {
                            final note = noteController.text.trim();
                            Navigator.of(sheetContext).pop();
                            _transition(
                              assignment,
                              'out_for_delivery',
                              note: note.isEmpty ? null : note,
                            );
                          },
                          child: Text(
                            context.tr(
                              'driver.action.confirm_start_delivery',
                            ),
                          ),
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),
          );
        },
      );
    } finally {
      noteController.dispose();
    }
  }

  Future<void> _showDetail(DriverAssignment assignment) async {
    await showModalBottomSheet<void>(
      context: context,
      showDragHandle: true,
      isScrollControlled: true,
      builder: (sheetContext) => SafeArea(
        child: FractionallySizedBox(
          heightFactor: .9,
          child: DriverActiveAssignmentDetail(
            assignment: assignment,
            onNavigationRequested: assignment.hasNavigation
                ? () async {
                    Navigator.of(sheetContext).pop();
                    await widget.onNavigationRequested(assignment);
                  }
                : null,
          ),
        ),
      ),
    );
  }

  String _statusLabel(String status) {
    final key = 'driver.status.$status';
    final translated = context.tr(key);
    return translated == key ? status : translated;
  }

  Widget _actions(DriverAssignment assignment) {
    final busy = _busyAssignments.contains(assignment.id);
    final buttons = <Widget>[];

    if (assignment.status == 'assigned' &&
        _allows(assignment, 'accepted')) {
      buttons.add(
        FilledButton(
          key: Key('driver-active-accept-${assignment.id}'),
          onPressed:
              busy ? null : () => _transition(assignment, 'accepted'),
          child: Text(_statusLabel('accepted')),
        ),
      );
    }

    if (assignment.status == 'accepted' &&
        _allows(assignment, 'picked_up')) {
      buttons.add(
        FilledButton.tonal(
          key: Key('driver-active-pickup-${assignment.id}'),
          onPressed:
              busy ? null : () => _transition(assignment, 'picked_up'),
          child: Text(_statusLabel('picked_up')),
        ),
      );
    }

    if (const {'accepted', 'picked_up'}.contains(assignment.status) &&
        _allows(assignment, 'out_for_delivery')) {
      buttons.add(
        FilledButton(
          key: Key('driver-active-start-${assignment.id}'),
          onPressed:
              busy ? null : () => _showStartDeliverySheet(assignment),
          child: Text(context.tr('driver.action.start_delivery')),
        ),
      );
    }

    if (assignment.status == 'out_for_delivery' &&
        _allows(assignment, 'delivered')) {
      buttons.add(
        FilledButton(
          key: Key('driver-active-delivered-${assignment.id}'),
          onPressed: busy ? null : () => _requestDelivered(assignment),
          child: Text(context.tr('driver.action.delivered')),
        ),
      );
    }

    if (buttons.isEmpty) {
      return Text(context.tr('driver.action.none'));
    }

    return Wrap(
      spacing: 8,
      runSpacing: 8,
      children: buttons,
    );
  }

  @override
  Widget build(BuildContext context) {
    final body = switch (_state) {
      _DriverActiveLoadState.loading => const Center(
          child: CircularProgressIndicator(),
        ),
      _DriverActiveLoadState.empty => Center(
          child: Text(context.tr('driver.empty')),
        ),
      _DriverActiveLoadState.error => _RetryState(
          message: context.tr('driver.error'),
          onRetry: _load,
        ),
      _DriverActiveLoadState.offline => _RetryState(
          message: context.tr('driver.offline'),
          onRetry: _load,
        ),
      _DriverActiveLoadState.ready => RefreshIndicator(
          onRefresh: _load,
          child: ListView.separated(
            key: const Key('driver-active-assignment-list'),
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 28),
            itemCount: _assignments.length,
            separatorBuilder: (_, __) => const SizedBox(height: 10),
            itemBuilder: (context, index) {
              final assignment = _assignments[index];
              return Card(
                key: Key('driver-active-assignment-${assignment.id}'),
                clipBehavior: Clip.antiAlias,
                child: InkWell(
                  onTap: () => _showDetail(assignment),
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        Row(
                          children: [
                            Expanded(
                              child: Text(
                                assignment.reference,
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: Theme.of(context)
                                    .textTheme
                                    .titleMedium
                                    ?.copyWith(fontWeight: FontWeight.w800),
                              ),
                            ),
                            const SizedBox(width: 8),
                            Chip(
                              label: Text(
                                _statusLabel(assignment.status),
                              ),
                            ),
                          ],
                        ),
                        if (assignment.storeName.isNotEmpty) ...[
                          const SizedBox(height: 6),
                          Text(assignment.storeName),
                        ],
                        if (assignment.customerName.isNotEmpty) ...[
                          const SizedBox(height: 4),
                          Text(assignment.customerName),
                        ],
                        if (assignment.address.isNotEmpty) ...[
                          const SizedBox(height: 4),
                          Text(
                            assignment.address,
                            maxLines: 2,
                            overflow: TextOverflow.ellipsis,
                          ),
                        ],
                        const SizedBox(height: 14),
                        _actions(assignment),
                      ],
                    ),
                  ),
                ),
              );
            },
          ),
        ),
    };

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (_actionError != null)
          MaterialBanner(
            content: Text(_actionError!),
            actions: [
              TextButton(
                onPressed: () => setState(() => _actionError = null),
                child: const Icon(Icons.close),
              ),
            ],
          ),
        Expanded(child: body),
      ],
    );
  }
}

class DriverActiveAssignmentDetail extends StatelessWidget {
  const DriverActiveAssignmentDetail({
    super.key,
    required this.assignment,
    this.onNavigationRequested,
  });

  final DriverAssignment assignment;
  final Future<void> Function()? onNavigationRequested;

  String _value(BuildContext context, String value) {
    if (value.trim().isNotEmpty) return value;
    return context.tr('driver.detail.unknown');
  }

  @override
  Widget build(BuildContext context) {
    final payment = [
      assignment.paymentMethod,
      assignment.paymentStatus,
    ].where((value) => value.trim().isNotEmpty).join(' · ');

    return ListView(
      key: Key('driver-active-detail-${assignment.id}'),
      padding: const EdgeInsets.fromLTRB(20, 8, 20, 28),
      children: [
        Text(
          context.tr('driver.detail.title'),
          style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                fontWeight: FontWeight.w800,
              ),
        ),
        const SizedBox(height: 18),
        _DetailRow(
          label: context.tr('driver.detail.order'),
          value: assignment.reference,
        ),
        _DetailRow(
          label: context.tr('driver.detail.store'),
          value: _value(context, assignment.storeName),
        ),
        _DetailRow(
          label: context.tr('driver.detail.customer'),
          value: _value(context, assignment.customerName),
        ),
        _DetailRow(
          label: context.tr('driver.detail.phone'),
          value: _value(context, assignment.customerPhone),
        ),
        _DetailRow(
          label: context.tr('driver.detail.address'),
          value: _value(context, assignment.address),
        ),
        _DetailRow(
          label: context.tr('driver.detail.payment'),
          value: _value(context, payment),
        ),
        _DetailRow(
          label: context.tr('driver.detail.total'),
          value: '${assignment.currency} '
              '${assignment.grandTotal.toStringAsFixed(3)}',
        ),
        _DetailRow(
          label: context.tr('driver.detail.note'),
          value: _value(context, assignment.customerNote),
        ),
        const SizedBox(height: 12),
        Text(
          context.tr('driver.detail.items'),
          style: Theme.of(context).textTheme.titleMedium?.copyWith(
                fontWeight: FontWeight.w800,
              ),
        ),
        const SizedBox(height: 8),
        if (assignment.items.isEmpty)
          Text(context.tr('driver.detail.no_items'))
        else
          ...assignment.items.map(
            (item) => ListTile(
              dense: true,
              contentPadding: EdgeInsets.zero,
              title: Text(item.name),
              subtitle: item.sku.isEmpty ? null : Text(item.sku),
              trailing: Text(
                item.quantity.toStringAsFixed(
                  item.quantity == item.quantity.roundToDouble() ? 0 : 2,
                ),
              ),
            ),
          ),
        if (onNavigationRequested != null) ...[
          const SizedBox(height: 16),
          FilledButton.icon(
            key: Key('driver-active-navigate-${assignment.id}'),
            onPressed: onNavigationRequested,
            icon: const Icon(Icons.navigation_rounded),
            label: Text(context.tr('driver.navigation.open')),
          ),
        ],
      ],
    );
  }
}

class _DetailRow extends StatelessWidget {
  const _DetailRow({
    required this.label,
    required this.value,
  });

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(bottom: 10),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            SizedBox(
              width: 110,
              child: Text(
                label,
                style: Theme.of(context).textTheme.bodySmall,
              ),
            ),
            const SizedBox(width: 8),
            Expanded(child: Text(value)),
          ],
        ),
      );
}

class _RetryState extends StatelessWidget {
  const _RetryState({
    required this.message,
    required this.onRetry,
  });

  final String message;
  final Future<void> Function() onRetry;

  @override
  Widget build(BuildContext context) => Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(message, textAlign: TextAlign.center),
              const SizedBox(height: 12),
              FilledButton.tonal(
                onPressed: onRetry,
                child: Text(context.tr('driver.retry')),
              ),
            ],
          ),
        ),
      );
}
