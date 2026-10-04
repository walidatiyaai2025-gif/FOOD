import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:share_plus/share_plus.dart';

import '../../../core/auth/driver_session.dart';
import '../../../core/localization/driver_translations.dart';
import '../../../core/preview/driver_preview_context.dart';
import '../driver_assignment_contract.dart';

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
    this.focusAssignmentId,
    this.initialAssignmentStatus,
    this.previewContext,
  });

  final DriverChannel channel;
  final DriverAssignmentRepository repository;
  final DriverActiveAssignmentCallback onNavigationRequested;
  final DriverActiveFailureCallback onFailedDeliveryRequested;
  final DriverActiveAssignmentCallback onDeliveredRequested;
  final VoidCallback? onSessionExpired;
  final int? focusAssignmentId;
  final String? initialAssignmentStatus;
  final DriverPreviewContext? previewContext;

  @override
  State<DriverActiveJourneyPage> createState() =>
      _DriverActiveJourneyPageState();
}

enum _DriverActiveLoadState { loading, ready, empty, error, offline }

enum _DriverDeliveryPeriod { today, all, custom }

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
  bool _focusedAssignmentOpened = false;
  _DriverDeliveryPeriod _period = _DriverDeliveryPeriod.today;
  DateTimeRange? _dateRange;

  @override
  void initState() {
    super.initState();
    if (widget.focusAssignmentId != null) {
      _period = _DriverDeliveryPeriod.all;
    }
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

      final visible = rows
          .where((row) => row.channel == widget.channel)
          .where((row) {
            final preview = widget.previewContext;
            return preview == null ||
                preview.allowsAssignment(
                  assignmentChannel: row.channel,
                  assignmentStoreId: row.storeId,
                );
          })
          .where((row) {
            final focusAssignmentId = widget.focusAssignmentId;
            if (focusAssignmentId != null) {
              return row.id == focusAssignmentId;
            }

            final status = widget.initialAssignmentStatus;
            if (status != null && status.trim().isNotEmpty) {
              return row.status == status;
            }

            return !_terminalStatuses.contains(row.status);
          })
          .toList(growable: false);

      setState(() {
        _assignments = visible;
        _state = visible.isEmpty
            ? _DriverActiveLoadState.empty
            : _DriverActiveLoadState.ready;
      });
      _openFocusedAssignmentIfNeeded();
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

  void _openFocusedAssignmentIfNeeded() {
    final focusAssignmentId = widget.focusAssignmentId;
    if (focusAssignmentId == null ||
        _focusedAssignmentOpened ||
        _assignments.isEmpty) {
      return;
    }

    final assignment = _assignments.firstWhere(
      (row) => row.id == focusAssignmentId,
      orElse: () => _assignments.first,
    );
    _focusedAssignmentOpened = true;
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) {
        _showDetail(assignment);
      }
    });
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
    if (widget.previewContext != null &&
        !widget.previewContext!.mutationsAllowed) {
      setState(() {
        _actionError = context.tr('driver.preview.mutation_blocked');
      });
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

  Future<void> _receiveOrder(DriverAssignment assignment) async {
    if (!_allows(assignment, 'picked_up') ||
        _busyAssignments.contains(assignment.id)) {
      return;
    }
    if (widget.previewContext != null &&
        !widget.previewContext!.mutationsAllowed) {
      setState(() {
        _actionError = context.tr('driver.preview.mutation_blocked');
      });
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
        'picked_up',
      );
      await widget.repository.transition(
        assignment.id,
        widget.channel,
        'out_for_delivery',
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
        await _load();
        if (mounted) {
          setState(() => _actionError = context.tr('driver.error'));
        }
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
    if (widget.previewContext != null &&
        !widget.previewContext!.mutationsAllowed) {
      setState(() {
        _actionError = context.tr('driver.preview.mutation_blocked');
      });
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
    if (widget.previewContext != null &&
        !widget.previewContext!.mutationsAllowed) {
      setState(() {
        _actionError = context.tr('driver.preview.mutation_blocked');
      });
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

    var note = '';
    final decision = await showModalBottomSheet<_StartDeliveryDecision>(
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
                  onChanged: (value) => note = value,
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
                          onPressed: () => Navigator.of(sheetContext).pop(
                            _StartDeliveryDecision.failed(note),
                          ),
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
                        onPressed: () => Navigator.of(sheetContext).pop(
                          _StartDeliveryDecision.start(note),
                        ),
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

    if (decision == null || !mounted) return;
    if (decision.failed) {
      await _requestFailure(assignment, decision.note);
      return;
    }

    final normalizedNote = decision.note.trim();
    await _transition(
      assignment,
      'out_for_delivery',
      note: normalizedNote.isEmpty ? null : normalizedNote,
    );
  }

  Future<DriverAssignment?> _fetchAuthoritativeAssignment(int id) async {
    try {
      final rows = await widget.repository.list(widget.channel);
      for (final row in rows) {
        if (row.id != id || row.channel != widget.channel) continue;
        final preview = widget.previewContext;
        if (preview != null &&
            !preview.allowsAssignment(
              assignmentChannel: row.channel,
              assignmentStoreId: row.storeId,
            )) {
          continue;
        }
        return row;
      }
      return null;
    } on DriverSessionExpiredException {
      widget.onSessionExpired?.call();
      return null;
    } on DriverOfflineException {
      if (mounted) {
        setState(() => _actionError = context.tr('driver.offline'));
      }
      return null;
    } catch (_) {
      if (mounted) {
        setState(() => _actionError = context.tr('driver.error'));
      }
      return null;
    }
  }

  Future<void> _runDetailAction(
    BuildContext sheetContext,
    DriverAssignment assignment,
    Future<void> Function() action,
  ) async {
    Navigator.of(sheetContext).pop();
    await action();
    if (!mounted) return;

    final fresh = await _fetchAuthoritativeAssignment(assignment.id);
    if (fresh != null && mounted) {
      await _showDetail(fresh);
    }
  }

  Widget _detailActions(
    BuildContext sheetContext,
    DriverAssignment assignment,
  ) {
    final busy = _busyAssignments.contains(assignment.id);
    final buttons = <Widget>[];

    if (assignment.status == 'assigned' &&
        _allows(assignment, 'accepted')) {
      buttons.add(
        FilledButton(
          key: Key('driver-detail-accept-${assignment.id}'),
          onPressed: busy
              ? null
              : () => _runDetailAction(
                    sheetContext,
                    assignment,
                    () => _transition(assignment, 'accepted'),
                  ),
          child: Text(_statusLabel('accepted')),
        ),
      );
    }

    if (assignment.status == 'accepted' &&
        _allows(assignment, 'picked_up')) {
      buttons.add(
        FilledButton.tonal(
          key: Key('driver-detail-pickup-${assignment.id}'),
          onPressed: busy
              ? null
              : () => _runDetailAction(
                    sheetContext,
                    assignment,
                    () => _receiveOrder(assignment),
                  ),
          child: Text(_statusLabel('picked_up')),
        ),
      );
    }

    if (const {'accepted', 'picked_up'}.contains(assignment.status) &&
        _allows(assignment, 'out_for_delivery')) {
      buttons.add(
        FilledButton(
          key: Key('driver-detail-start-${assignment.id}'),
          onPressed: busy
              ? null
              : () => _runDetailAction(
                    sheetContext,
                    assignment,
                    () => _showStartDeliverySheet(assignment),
                  ),
          child: Text(context.tr('driver.action.start_delivery')),
        ),
      );
    }

    if (assignment.status == 'out_for_delivery' &&
        _allows(assignment, 'delivered')) {
      buttons.add(
        FilledButton(
          key: Key('driver-detail-delivered-${assignment.id}'),
          onPressed: busy
              ? null
              : () => _runDetailAction(
                    sheetContext,
                    assignment,
                    () => _requestDelivered(assignment),
                  ),
          child: Text(context.tr('driver.action.delivered')),
        ),
      );
    }

    if (_allows(assignment, 'failed')) {
      buttons.add(
        OutlinedButton(
          key: Key('driver-detail-failed-${assignment.id}'),
          onPressed: busy
              ? null
              : () => _runDetailAction(
                    sheetContext,
                    assignment,
                    () => _requestFailure(assignment, ''),
                  ),
          child: Text(context.tr('driver.action.delivery_failed')),
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

  Future<void> _shareInvoicePdf(DriverAssignment assignment) async {
    final repository = widget.repository;
    final invoice = assignment.invoice;
    if (invoice == null || repository is! DriverInvoiceDocumentRepository) {
      if (mounted) {
        setState(() {
          _actionError = context.tr('driver.invoice.download_unavailable');
        });
      }
      return;
    }
    final documentRepository = repository as DriverInvoiceDocumentRepository;
    if (_busyAssignments.contains(assignment.id)) return;

    setState(() {
      _busyAssignments.add(assignment.id);
      _actionError = null;
    });

    try {
      final locale =
          Localizations.localeOf(context).languageCode == 'ar' ? 'ar' : 'en';
      final bytes = await documentRepository.downloadInvoicePdf(
        assignment.id,
        locale: locale,
      );
      final name = '${invoice.number}.pdf';
      await SharePlus.instance.share(
        ShareParams(
          files: [
            XFile.fromData(
              Uint8List.fromList(bytes),
              mimeType: 'application/pdf',
              name: name,
            ),
          ],
          fileNameOverrides: [name],
          subject: invoice.number,
        ),
      );
    } on DriverSessionExpiredException {
      widget.onSessionExpired?.call();
    } on DriverOfflineException {
      if (mounted) {
        setState(() => _actionError = context.tr('driver.offline'));
      }
    } catch (_) {
      if (mounted) {
        setState(() {
          _actionError = context.tr('driver.invoice.download_failed');
        });
      }
    } finally {
      if (mounted) {
        setState(() => _busyAssignments.remove(assignment.id));
      }
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
            actions: _detailActions(sheetContext, assignment),
            onNavigationRequested: assignment.hasNavigation
                ? () async {
                    Navigator.of(sheetContext).pop();
                    await widget.onNavigationRequested(assignment);
                  }
                : null,
            onInvoicePdfRequested: assignment.invoice != null &&
                    assignment.invoice!.downloadPath.trim().isNotEmpty &&
                    widget.previewContext == null
                ? () => _shareInvoicePdf(assignment)
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

    if (_allows(assignment, 'failed')) {
      buttons.add(
        FilledButton.tonalIcon(
          key: Key('driver-active-card-failed-${assignment.id}'),
          onPressed: busy ? null : () => _requestFailure(assignment, ''),
          icon: const Icon(Icons.report_problem_outlined),
          label: Text(context.tr('driver.action.delivery_failed')),
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

  DateTime? _assignmentDate(DriverAssignment assignment) {
    for (final value in [assignment.assignedAt, assignment.createdAt]) {
      final parsed = DateTime.tryParse(value);
      if (parsed != null) return parsed.toLocal();
    }
    return null;
  }

  List<DriverAssignment> _filteredAssignments() {
    if (_period == _DriverDeliveryPeriod.all) return _assignments;
    final now = DateTime.now();
    final range = _period == _DriverDeliveryPeriod.today
        ? DateTimeRange(
            start: DateTime(now.year, now.month, now.day),
            end: DateTime(now.year, now.month, now.day, 23, 59, 59, 999),
          )
        : _dateRange;
    if (range == null) return _assignments;

    final start = DateTime(range.start.year, range.start.month, range.start.day);
    final end = DateTime(
      range.end.year,
      range.end.month,
      range.end.day,
      23,
      59,
      59,
      999,
    );
    return _assignments.where((assignment) {
      final date = _assignmentDate(assignment);
      if (date == null) {
        return _period != _DriverDeliveryPeriod.custom;
      }
      return !date.isBefore(start) && !date.isAfter(end);
    }).toList(growable: false);
  }

  Future<void> _pickDateRange() async {
    final now = DateTime.now();
    final initial = _dateRange ??
        DateTimeRange(
          start: now.subtract(const Duration(days: 7)),
          end: now,
        );
    final picked = await showDateRangePicker(
      context: context,
      firstDate: DateTime(now.year - 3),
      lastDate: DateTime(now.year + 1),
      initialDateRange: initial,
    );
    if (picked == null || !mounted) return;
    setState(() {
      _dateRange = picked;
      _period = _DriverDeliveryPeriod.custom;
    });
  }

  Widget _filterControls() {
    final locale = MaterialLocalizations.of(context);
    final range = _dateRange;
    final rangeLabel = range == null
        ? context.tr('driver.filter.date_range')
        : '${locale.formatShortDate(range.start)} — ${locale.formatShortDate(range.end)}';

    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 10, 16, 2),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          OutlinedButton.icon(
            key: const Key('driver-delivery-date-range'),
            onPressed: _pickDateRange,
            icon: const Icon(Icons.date_range_rounded),
            label: Text(rangeLabel),
          ),
          const SizedBox(height: 8),
          Row(
            children: [
              Expanded(
                child: ChoiceChip(
                  key: const Key('driver-filter-today'),
                  label: Text(context.tr('driver.filter.today')),
                  selected: _period == _DriverDeliveryPeriod.today,
                  onSelected: (_) => setState(() {
                    _period = _DriverDeliveryPeriod.today;
                  }),
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: ChoiceChip(
                  key: const Key('driver-filter-all'),
                  label: Text(context.tr('driver.filter.all')),
                  selected: _period == _DriverDeliveryPeriod.all,
                  onSelected: (_) => setState(() {
                    _period = _DriverDeliveryPeriod.all;
                  }),
                ),
              ),
              if (_period == _DriverDeliveryPeriod.custom) ...[
                const SizedBox(width: 8),
                IconButton(
                  key: const Key('driver-filter-clear-date'),
                  tooltip: context.tr('driver.filter.clear_date'),
                  onPressed: () => setState(() {
                    _dateRange = null;
                    _period = _DriverDeliveryPeriod.today;
                  }),
                  icon: const Icon(Icons.close_rounded),
                ),
              ],
            ],
          ),
        ],
      ),
    );
  }

  Widget _readyBody() {
    final rows = _filteredAssignments();
    return Column(
      children: [
        _filterControls(),
        Expanded(
          child: RefreshIndicator(
            onRefresh: _load,
            child: rows.isEmpty
                ? ListView(
                    key: const Key('driver-filter-empty'),
                    physics: const AlwaysScrollableScrollPhysics(),
                    padding: const EdgeInsets.all(28),
                    children: [
                      const SizedBox(height: 80),
                      Center(
                        child: Text(context.tr('driver.filter.period_empty')),
                      ),
                    ],
                  )
                : ListView.separated(
                    key: widget.initialAssignmentStatus != null
                        ? const Key('driver-active-status-filter')
                        : const Key('driver-active-assignment-list'),
                    padding: const EdgeInsets.fromLTRB(16, 12, 16, 28),
                    itemCount: rows.length,
                    separatorBuilder: (_, __) => const SizedBox(height: 10),
                    itemBuilder: (context, index) {
                      final assignment = rows[index];
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
                                            ?.copyWith(
                                              fontWeight: FontWeight.w800,
                                            ),
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
        ),
      ],
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
      _DriverActiveLoadState.ready => _readyBody(),
    };

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (_actionError != null)
          MaterialBanner(
            key: const Key('driver-action-error'),
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
    this.actions,
    this.onNavigationRequested,
    this.onInvoicePdfRequested,
  });

  final DriverAssignment assignment;
  final Widget? actions;
  final Future<void> Function()? onNavigationRequested;
  final Future<void> Function()? onInvoicePdfRequested;

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
    final statusKey = 'driver.status.${assignment.status}';
    final translatedStatus = context.tr(statusKey);
    final statusLabel =
        translatedStatus == statusKey ? assignment.status : translatedStatus;
    final orderStatusKey = 'driver.order_status.${assignment.orderStatus}';
    final translatedOrderStatus = context.tr(orderStatusKey);
    final orderStatusLabel = assignment.orderStatus.trim().isEmpty
        ? context.tr('driver.detail.unknown')
        : translatedOrderStatus == orderStatusKey
            ? assignment.orderStatus
            : translatedOrderStatus;
    final settlement = assignment.settlement;
    String settlementLabel(String namespace, String value) {
      if (value.trim().isEmpty) return context.tr('driver.detail.unknown');
      final key = '$namespace.${value.toLowerCase()}';
      final translated = context.tr(key);
      return translated == key ? value : translated;
    }
    String money(double amount, String currency) =>
        '${amount.toStringAsFixed(3)} $currency';

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
          label: context.tr('driver.detail.status'),
          value: statusLabel,
        ),
        if (assignment.orderStatus.trim().isNotEmpty)
          _DetailRow(
            label: context.tr('driver.detail.order_status'),
            value: orderStatusLabel,
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
        const SizedBox(height: 8),
        Container(
          key: Key('driver-detail-address-card-${assignment.id}'),
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
            color: const Color(0xFFF2F8F5),
            borderRadius: BorderRadius.circular(16),
            border: Border.all(color: const Color(0xFFD9E9E1)),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Icon(
                    Icons.location_on_outlined,
                    color: Color(0xFF087347),
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          context.tr('driver.detail.address'),
                          style: Theme.of(context)
                              .textTheme
                              .labelLarge
                              ?.copyWith(fontWeight: FontWeight.w800),
                        ),
                        const SizedBox(height: 4),
                        Text(
                          _value(context, assignment.address),
                          key: Key('driver-detail-address-${assignment.id}'),
                          style: Theme.of(context).textTheme.bodyMedium,
                        ),
                      ],
                    ),
                  ),
                ],
              ),
              if (onNavigationRequested != null) ...[
                const SizedBox(height: 10),
                FilledButton.icon(
                  key: Key('driver-active-navigate-${assignment.id}'),
                  onPressed: onNavigationRequested,
                  icon: const Icon(Icons.map_outlined),
                  label: Text(context.tr('driver.navigation.open')),
                ),
              ],
            ],
          ),
        ),
        if (actions != null) ...[
          const SizedBox(height: 14),
          Text(
            context.tr('driver.detail.actions'),
            style: Theme.of(context).textTheme.titleMedium?.copyWith(
                  fontWeight: FontWeight.w800,
                ),
          ),
          const SizedBox(height: 10),
          actions!,
        ],
        if (assignment.invoice != null) ...[
          const SizedBox(height: 12),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              OutlinedButton.icon(
                key: Key('driver-active-open-invoice-${assignment.id}'),
                onPressed: () => _showInvoice(context, assignment.invoice!),
                icon: const Icon(Icons.receipt_long_rounded),
                label: Text(
                  '${context.tr('driver.invoice.open')} · ${assignment.invoice!.number}',
                ),
              ),
              if (onInvoicePdfRequested != null)
                FilledButton.tonalIcon(
                  key: Key('driver-active-download-invoice-${assignment.id}'),
                  onPressed: onInvoicePdfRequested,
                  icon: const Icon(Icons.picture_as_pdf_outlined),
                  label: Text(context.tr('driver.invoice.download')),
                ),
            ],
          ),
        ],
        if (settlement != null) ...[
          const SizedBox(height: 14),
          Card(
            key: Key('driver-settlement-${assignment.id}'),
            child: Padding(
              padding: const EdgeInsets.all(14),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text(
                    context.tr('driver.settlement.title'),
                    style: Theme.of(context).textTheme.titleMedium?.copyWith(
                          fontWeight: FontWeight.w800,
                        ),
                  ),
                  const SizedBox(height: 8),
                  _DetailRow(
                    label: context.tr('driver.settlement.order_total'),
                    value: money(settlement.orderTotal, settlement.currency),
                  ),
                  _DetailRow(
                    label: context.tr('driver.settlement.balance_applied'),
                    value: money(settlement.balanceApplied, settlement.currency),
                  ),
                  _DetailRow(
                    label: context.tr('driver.settlement.remaining'),
                    value: money(settlement.remainingAmount, settlement.currency),
                  ),
                  _DetailRow(
                    label: context.tr('driver.settlement.remainder_method'),
                    value: settlementLabel(
                      'driver.settlement.remainder',
                      settlement.remainderMethod,
                    ),
                  ),
                  _DetailRow(
                    label: context.tr('driver.settlement.payment_state'),
                    value: settlementLabel(
                      'driver.settlement.state',
                      settlement.paymentState,
                    ),
                  ),
                  _DetailRow(
                    label: context.tr('driver.settlement.collect_now'),
                    value: settlement.amountToCollectNow <= 0.0001
                        ? context.tr('driver.settlement.no_collection')
                        : money(
                            settlement.amountToCollectNow,
                            settlement.currency,
                          ),
                  ),
                  _DetailRow(
                    label: context.tr('driver.settlement.invoice_outstanding'),
                    value: money(
                      settlement.invoiceOutstandingAmount,
                      settlement.currency,
                    ),
                  ),
                ],
              ),
            ),
          ),
        ],
        const SizedBox(height: 10),
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
          ...assignment.items.asMap().entries.map((entry) {
            final index = entry.key;
            final item = entry.value;
            final quantity = item.quantity.toStringAsFixed(
              item.quantity == item.quantity.roundToDouble() ? 0 : 2,
            );
            final image = item.imageUrl.trim();

            return Card(
              key: Key('driver-detail-item-${assignment.id}-$index'),
              margin: const EdgeInsets.only(bottom: 10),
              child: Padding(
                padding: const EdgeInsets.all(12),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    ClipRRect(
                      borderRadius: BorderRadius.circular(12),
                      child: SizedBox(
                        key: Key(
                          'driver-detail-item-image-${assignment.id}-$index',
                        ),
                        width: 64,
                        height: 64,
                        child: image.isEmpty
                            ? const ColoredBox(
                                color: Color(0xFFF2F5F3),
                                child: Icon(Icons.inventory_2_outlined),
                              )
                            : Image.network(
                                image,
                                fit: BoxFit.cover,
                                errorBuilder: (_, __, ___) => const ColoredBox(
                                  color: Color(0xFFF2F5F3),
                                  child: Icon(Icons.broken_image_outlined),
                                ),
                              ),
                      ),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            item.name,
                            style: Theme.of(context)
                                .textTheme
                                .titleSmall
                                ?.copyWith(fontWeight: FontWeight.w800),
                          ),
                          if (item.sku.trim().isNotEmpty) ...[
                            const SizedBox(height: 2),
                            Text(
                              item.sku,
                              style: Theme.of(context).textTheme.bodySmall,
                            ),
                          ],
                          const SizedBox(height: 6),
                          Text(
                            '${context.tr('driver.detail.item_variant')}: '
                            '${_value(context, item.variant)}',
                          ),
                          Text(
                            '${context.tr('driver.detail.item_quantity')}: '
                            '$quantity',
                          ),
                          Text(
                            '${context.tr('driver.detail.item_unit')}: '
                            '${_value(context, item.unit)}',
                          ),
                          if (item.packSize > 0)
                            Text(
                              '${context.tr('driver.detail.item_pack')}: '
                              '${item.packSize.toStringAsFixed(3)}',
                            ),
                          if (item.caseSize > 0)
                            Text(
                              '${context.tr('driver.detail.item_case')}: '
                              '${item.caseSize.toStringAsFixed(3)}',
                            ),
                          if ((item.quantityConversionFactor - 1).abs() > 0.0001)
                            Text(
                              '${context.tr('driver.detail.item_conversion')}: '
                              '${item.quantityConversionFactor.toStringAsFixed(3)}',
                            ),
                          Text(
                            '${context.tr('driver.detail.item_note')}: '
                            '${_value(context, item.note)}',
                          ),
                          if (item.lineTotal > 0) ...[
                            const SizedBox(height: 4),
                            Text(
                              '${context.tr('driver.detail.item_total')}: '
                              '${item.lineTotal.toStringAsFixed(3)} '
                              '${assignment.currency}',
                              style: const TextStyle(
                                fontWeight: FontWeight.w700,
                              ),
                            ),
                          ],
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            );
          }),
      ],
    );
  }
}

Future<void> _showInvoice(
  BuildContext context,
  DriverInvoice invoice,
) async {
  String label(String namespace, String value) {
    if (value.trim().isEmpty) return context.tr('driver.detail.unknown');
    final key = '$namespace.${value.toLowerCase()}';
    final translated = context.tr(key);
    return translated == key ? value : translated;
  }

  await showDialog<void>(
    context: context,
    builder: (dialogContext) => AlertDialog(
      title: Text('${context.tr('driver.invoice.title')} ${invoice.number}'),
      content: SizedBox(
        width: 520,
        child: SingleChildScrollView(
          key: Key('driver-active-invoice-detail-${invoice.id}'),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              _DetailRow(
                label: context.tr('driver.invoice.status'),
                value: label('driver.invoice_status', invoice.status),
              ),
              _DetailRow(
                label: context.tr('driver.invoice.revision'),
                value: invoice.revision.toString(),
              ),
              if (invoice.issuedAt.isNotEmpty)
                _DetailRow(
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
                        '${item.quantity.toStringAsFixed(3)} · '
                        '${item.lineTotal.toStringAsFixed(3)} '
                        '${invoice.currency}',
                      ),
                    ],
                  ),
                ),
              ),
              const Divider(height: 24),
              _DetailRow(
                label: context.tr('driver.invoice.subtotal'),
                value:
                    '${invoice.subtotal.toStringAsFixed(3)} ${invoice.currency}',
              ),
              _DetailRow(
                label: context.tr('driver.invoice.discount'),
                value:
                    '${invoice.discountTotal.toStringAsFixed(3)} ${invoice.currency}',
              ),
              _DetailRow(
                label: context.tr('driver.invoice.delivery'),
                value:
                    '${invoice.deliveryTotal.toStringAsFixed(3)} ${invoice.currency}',
              ),
              _DetailRow(
                label: context.tr('driver.invoice.tax'),
                value:
                    '${invoice.taxTotal.toStringAsFixed(3)} ${invoice.currency}',
              ),
              _DetailRow(
                label: context.tr('driver.invoice.total'),
                value:
                    '${invoice.grandTotal.toStringAsFixed(3)} ${invoice.currency}',
              ),
              _DetailRow(
                label: context.tr('driver.invoice.payment'),
                value:
                    '${label('driver.payment_method', invoice.paymentMethod)} · '
                    '${label('driver.payment_status', invoice.paymentStatus)}',
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

class _StartDeliveryDecision {
  const _StartDeliveryDecision._({
    required this.failed,
    required this.note,
  });

  factory _StartDeliveryDecision.start(String note) =>
      _StartDeliveryDecision._(failed: false, note: note);

  factory _StartDeliveryDecision.failed(String note) =>
      _StartDeliveryDecision._(failed: true, note: note);

  final bool failed;
  final String note;
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
