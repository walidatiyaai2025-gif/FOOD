import 'package:flutter/material.dart';
import 'package:share_plus/share_plus.dart';

import '../../core/diagnostics/driver_runtime_inspector.dart';
import '../../core/localization/driver_translations.dart';
import '../../core/theme/foodex_theme.dart';

class DriverInspectorPanel extends StatefulWidget {
  const DriverInspectorPanel({
    super.key,
    required this.authenticated,
    required this.onClose,
    this.inspector,
  });

  final bool authenticated;
  final VoidCallback onClose;
  final DriverRuntimeInspector? inspector;

  @override
  State<DriverInspectorPanel> createState() => _DriverInspectorPanelState();
}

class _DriverInspectorPanelState extends State<DriverInspectorPanel> {
  bool _busy = false;

  DriverRuntimeInspector get _inspector =>
      widget.inspector ?? DriverRuntimeInspector.instance;

  Future<void> _export() async {
    if (_busy) return;
    setState(() => _busy = true);
    try {
      final locale =
          Localizations.maybeLocaleOf(context)?.languageCode ?? 'unknown';
      final shareText = context.tr('driver.inspector.share_text');
      final file = await _inspector.writeExportFile(
        locale: locale,
        authenticated: widget.authenticated,
      );
      if (!mounted) return;
      await Share.shareXFiles(
        [XFile(file.path, mimeType: 'application/json')],
        subject: 'FOODEX Driver diagnostics',
        text: shareText,
      );
      if (!mounted) return;
      _show(context.tr('driver.inspector.export_ready'));
    } catch (error, stack) {
      _inspector.recordException(
        error,
        stack,
        source: 'inspector.export',
      );
      if (!mounted) return;
      _show(context.tr('driver.inspector.export_failed'));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _clear() async {
    if (_busy) return;
    setState(() => _busy = true);
    try {
      await _inspector.clear();
      if (!mounted) return;
      setState(() {});
      _show(context.tr('driver.inspector.clear_done'));
    } catch (error, stack) {
      _inspector.recordException(
        error,
        stack,
        source: 'inspector.clear',
      );
      if (!mounted) return;
      _show(context.tr('driver.inspector.clear_failed'));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _show(String message) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(message)),
    );
  }

  @override
  Widget build(BuildContext context) {
    final events = _inspector.snapshot().reversed.take(20).toList(growable: false);
    final lastEvent = _inspector.lastEventAt;

    return Material(
      color: Theme.of(context).scaffoldBackgroundColor,
      child: SafeArea(
        child: Scaffold(
          appBar: AppBar(
            leading: IconButton(
              key: const Key('driver-inspector-close'),
              onPressed: widget.onClose,
              tooltip: context.tr('driver.dismiss'),
              icon: const Icon(Icons.close_rounded),
            ),
            title: Text(context.tr('driver.inspector.title')),
          ),
          body: ListView(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 28),
            children: [
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Row(
                        children: [
                          const Icon(
                            Icons.bug_report_outlined,
                            color: FoodexBrand.greenDark,
                          ),
                          const SizedBox(width: 10),
                          Expanded(
                            child: Text(
                              context.tr('driver.inspector.summary'),
                              style: Theme.of(context)
                                  .textTheme
                                  .titleMedium
                                  ?.copyWith(fontWeight: FontWeight.w900),
                            ),
                          ),
                        ],
                      ),
                      const SizedBox(height: 14),
                      _InfoRow(
                        label: context.tr('driver.version'),
                        value: '$driverAppVersion+$driverAppBuild',
                      ),
                      _InfoRow(
                        label: context.tr('driver.inspector.events'),
                        value: _inspector.eventCount.toString(),
                        valueKey: const Key('driver-inspector-event-count'),
                      ),
                      _InfoRow(
                        label: context.tr('driver.inspector.last_event'),
                        value: lastEvent ?? context.tr('driver.inspector.none'),
                      ),
                      _InfoRow(
                        label: context.tr('driver.inspector.current_route'),
                        value: _inspector.lastRoute ??
                            context.tr('driver.inspector.none'),
                      ),
                    ],
                  ),
                ),
              ),
              const SizedBox(height: 12),
              DecoratedBox(
                decoration: BoxDecoration(
                  color: FoodexBrand.greenSoft,
                  borderRadius: BorderRadius.circular(16),
                ),
                child: Padding(
                  padding: const EdgeInsets.all(14),
                  child: Text(
                    context.tr('driver.inspector.privacy'),
                    style: const TextStyle(fontWeight: FontWeight.w700),
                  ),
                ),
              ),
              const SizedBox(height: 16),
              FilledButton.icon(
                key: const Key('driver-inspector-export'),
                onPressed: _busy ? null : _export,
                icon: const Icon(Icons.ios_share_rounded),
                label: Text(context.tr('driver.inspector.export')),
              ),
              const SizedBox(height: 10),
              OutlinedButton.icon(
                key: const Key('driver-inspector-clear'),
                onPressed: _busy ? null : _clear,
                icon: const Icon(Icons.delete_sweep_outlined),
                label: Text(context.tr('driver.inspector.clear')),
              ),
              const SizedBox(height: 22),
              Text(
                context.tr('driver.inspector.recent'),
                style: Theme.of(context)
                    .textTheme
                    .titleMedium
                    ?.copyWith(fontWeight: FontWeight.w900),
              ),
              const SizedBox(height: 8),
              if (events.isEmpty)
                Card(
                  child: Padding(
                    padding: const EdgeInsets.all(18),
                    child: Text(context.tr('driver.inspector.none')),
                  ),
                )
              else
                for (final event in events)
                  Card(
                    child: ListTile(
                      dense: true,
                      leading: Icon(_iconFor(event['type']?.toString())),
                      title: Text(
                        _eventTitle(event),
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                      ),
                      subtitle: Text(
                        event['timestamp']?.toString() ?? '',
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                  ),
            ],
          ),
        ),
      ),
    );
  }

  IconData _iconFor(String? type) {
    return switch (type) {
      'http_failure' => Icons.cloud_off_outlined,
      'navigation' => Icons.route_outlined,
      _ => Icons.error_outline_rounded,
    };
  }

  String _eventTitle(Map<String, dynamic> event) {
    switch (event['type']) {
      case 'navigation':
        return '${context.tr('driver.inspector.navigation')}: ${event['route'] ?? ''}';
      case 'http_failure':
        final status = event['status_code'] == null
            ? context.tr('driver.inspector.network_error')
            : 'HTTP ${event['status_code']}';
        return '$status · ${event['method'] ?? ''} ${event['endpoint'] ?? ''}';
      default:
        return '${event['source'] ?? 'error'} · ${event['error_type'] ?? ''}';
    }
  }
}

class _InfoRow extends StatelessWidget {
  const _InfoRow({
    required this.label,
    required this.value,
    this.valueKey,
  });

  final String label;
  final String value;
  final Key? valueKey;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 5),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 118,
            child: Text(
              label,
              style: const TextStyle(fontWeight: FontWeight.w800),
            ),
          ),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              value,
              key: valueKey,
              textDirection: TextDirection.ltr,
            ),
          ),
        ],
      ),
    );
  }
}
