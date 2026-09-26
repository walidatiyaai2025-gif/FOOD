import 'package:flutter/material.dart';

import '../../core/auth/driver_session.dart';
import '../../core/localization/driver_translations.dart';
import '../../core/theme/foodex_theme.dart';

enum DriverLoadState { loading, ready, empty, error, offline }

class DriverAssignment {
  const DriverAssignment({
    required this.id,
    required this.channel,
    required this.reference,
    required this.status,
    this.orderId = 0,
    this.availableStatuses = const [],
  });

  final int id;
  final int orderId;
  final DriverChannel channel;
  final String reference;
  final String status;
  final List<String> availableStatuses;
}

abstract interface class DriverAssignmentRepository {
  Future<List<DriverAssignment>> list(DriverChannel channel);
  Future<void> transition(int id, DriverChannel channel, String status);
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
  final Set<int> _transitioning = <int>{};
  String? _actionError;

  @override
  void initState() {
    super.initState();
    _load();
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

  Future<void> _transition(DriverAssignment assignment, String status) async {
    if (_transitioning.contains(assignment.id)) return;
    setState(() {
      _transitioning.add(assignment.id);
      _actionError = null;
    });
    try {
      await widget.repository.transition(assignment.id, widget.channel, status);
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

  Future<void> _showDetail(DriverAssignment assignment) async {
    await showModalBottomSheet<void>(
      context: context,
      showDragHandle: true,
      builder: (sheetContext) => SafeArea(
        child: Padding(
          key: Key('assignment-detail-' + assignment.id.toString()),
          padding: const EdgeInsets.fromLTRB(20, 8, 20, 24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(
                context.tr('driver.detail.title'),
                style: Theme.of(context).textTheme.titleLarge,
              ),
              const SizedBox(height: 12),
              Text(context.tr('driver.detail.order') + ': ' + assignment.reference),
              const SizedBox(height: 8),
              Text(
                context.tr('driver.detail.status') +
                    ': ' +
                    context.tr('driver.status.' + assignment.status),
              ),
              const SizedBox(height: 16),
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
                            'assignment-status-' +
                                assignment.id.toString() +
                                '-' +
                                status,
                          ),
                          onPressed: _transitioning.contains(assignment.id)
                              ? null
                              : () {
                                  Navigator.of(sheetContext).pop();
                                  _transition(assignment, status);
                                },
                          child: Text(context.tr('driver.status.' + status)),
                        ),
                      )
                      .toList(growable: false),
                ),
            ],
          ),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(
          context.tr(
            widget.channel == DriverChannel.b2c
                ? 'driver.b2c.title'
                : 'driver.b2b.title',
          ),
        ),
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
              DriverLoadState.ready => RefreshIndicator(
                  onRefresh: _load,
                  child: ListView(
                    children: assignments
                        .map(
                          (assignment) => ListTile(
                            key: Key('assignment-' + assignment.id.toString()),
                            title: Text(assignment.reference),
                            subtitle: Align(
                              alignment: AlignmentDirectional.centerStart,
                              child: DecoratedBox(
                                decoration: BoxDecoration(
                                  color: FoodexBrand.statusSurface(assignment.status),
                                  borderRadius: BorderRadius.circular(999),
                                ),
                                child: Padding(
                                  padding: const EdgeInsets.symmetric(
                                    horizontal: 8,
                                    vertical: 4,
                                  ),
                                  child: Text(
                                    context.tr('driver.status.' + assignment.status),
                                    style: TextStyle(
                                      color: FoodexBrand.statusColor(assignment.status),
                                      fontWeight: FontWeight.w700,
                                    ),
                                  ),
                                ),
                              ),
                            ),
                            trailing: const Icon(Icons.chevron_right),
                            onTap: () => _showDetail(assignment),
                          ),
                        )
                        .toList(growable: false),
                  ),
                ),
            },
          ),
        ],
      ),
    );
  }
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
