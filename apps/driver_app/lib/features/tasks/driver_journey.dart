import 'package:flutter/material.dart';

import '../../core/localization/driver_translations.dart';
import '../../navigation.dart';

enum DriverLoadState { loading, ready, empty, error, offline }

class DriverAssignment {
  const DriverAssignment({required this.id, required this.channel, required this.reference, required this.status});
  final int id;
  final DriverChannel channel;
  final String reference;
  final String status;
}

abstract interface class DriverAssignmentRepository {
  Future<List<DriverAssignment>> list(DriverChannel channel);
  Future<void> transition(int id, DriverChannel channel, String action);
}

class DriverJourneyPage extends StatefulWidget {
  const DriverJourneyPage({super.key, required this.channel, required this.repository});
  final DriverChannel channel;
  final DriverAssignmentRepository repository;

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
    setState(() => state = DriverLoadState.loading);
    try {
      final rows = await widget.repository.list(widget.channel);
      if (!mounted) {
        return;
      }

      assignments = rows.where((row) => row.channel == widget.channel).toList(growable: false);
      state = assignments.isEmpty ? DriverLoadState.empty : DriverLoadState.ready;
    } on DriverOfflineException {
      if (mounted) {
        setState(() => state = DriverLoadState.offline);
      }
    } catch (_) {
      if (mounted) {
        setState(() => state = DriverLoadState.error);
      }
    }

    if (mounted) {
      setState(() {});
    }
  }

  Future<void> _transition(DriverAssignment assignment, String action) async {
    if (_transitioning.contains(assignment.id)) return;
    setState(() { _transitioning.add(assignment.id); _actionError = null; });
    try {
      await widget.repository.transition(assignment.id, widget.channel, action);
      await _load();
    } catch (_) {
      if (mounted) setState(() => _actionError = context.tr('driver.error'));
    } finally {
      if (mounted) setState(() => _transitioning.remove(assignment.id));
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(
          context.tr(widget.channel == DriverChannel.b2c ? 'driver.b2c.title' : 'driver.b2b.title'),
        ),
      ),
      body: Column(children: [
        if (_actionError != null) MaterialBanner(content: Text(_actionError!, key: const Key('driver-action-error')), actions: [TextButton(onPressed: () => setState(() => _actionError = null), child: Text(context.tr('driver.retry')))]),
        Expanded(child: switch (state) {
        DriverLoadState.loading => const Center(child: CircularProgressIndicator(key: Key('driver-loading'))),
        DriverLoadState.empty => Center(child: Text(context.tr('driver.empty'), key: const Key('driver-empty'))),
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
                      key: Key('assignment-${assignment.id}'),
                      title: Text(assignment.reference),
                      subtitle: Text(assignment.status),
                      trailing: FilledButton(
                        key: Key('assignment-action-${assignment.id}'),
                        onPressed: _transitioning.contains(assignment.id) ? null : () => _transition(assignment, 'advance'),
                        child: _transitioning.contains(assignment.id) ? const SizedBox.square(dimension: 16, child: CircularProgressIndicator(strokeWidth: 2)) : Text(context.tr('driver.action.advance')),
                      ),
                    ),
                  )
                  .toList(),
            ),
          ),
      }),
      ]),
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

class DriverOfflineException implements Exception {
  const DriverOfflineException();
}
