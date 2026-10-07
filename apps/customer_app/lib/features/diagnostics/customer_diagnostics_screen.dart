import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:share_plus/share_plus.dart';

import '../../core/diagnostics/customer_diagnostics.dart';
import '../../core/localization/app_translations.dart';

class CustomerDiagnosticsScreen extends StatefulWidget {
  const CustomerDiagnosticsScreen({
    this.diagnostics,
    super.key,
  });

  final CustomerDiagnostics? diagnostics;

  @override
  State<CustomerDiagnosticsScreen> createState() =>
      _CustomerDiagnosticsScreenState();
}

class _CustomerDiagnosticsScreenState
    extends State<CustomerDiagnosticsScreen> {
  final TextEditingController _note = TextEditingController();
  bool _busy = false;

  CustomerDiagnostics get _diagnostics =>
      widget.diagnostics ?? CustomerDiagnostics.instance;

  @override
  void dispose() {
    _note.dispose();
    super.dispose();
  }

  Future<void> _export() async {
    final shareText = context.tr('customer.diagnostics.share_text');
    setState(() => _busy = true);
    try {
      final file = await _diagnostics.writeExportFile(note: _note.text);
      await SharePlus.instance.share(
        ShareParams(
          files: [XFile(file.path)],
          text: shareText,
        ),
      );
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(context.tr('customer.diagnostics.exported'))),
      );
    } catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(context.tr('customer.diagnostics.export_failed')),
        ),
      );
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _copySummary() async {
    await Clipboard.setData(
      ClipboardData(text: _diagnostics.summary(note: _note.text)),
    );
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(context.tr('customer.diagnostics.copied'))),
    );
  }

  Future<void> _clear() async {
    await _diagnostics.clear();
    if (!mounted) return;
    setState(() {});
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(context.tr('customer.diagnostics.cleared'))),
    );
  }

  @override
  Widget build(BuildContext context) {
    final payload = _diagnostics.exportPayload();
    final app = Map<String, dynamic>.from(payload['app'] as Map);
    final environment =
        Map<String, dynamic>.from(payload['environment'] as Map);
    final navigation =
        Map<String, dynamic>.from(payload['navigation'] as Map);
    final events = _diagnostics.events;
    final lastEvent = events.isEmpty ? null : events.last['timestamp'];

    return Scaffold(
      appBar: AppBar(
        title: Text(context.tr('customer.diagnostics.title')),
      ),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.fromLTRB(16, 16, 16, 32),
          children: [
            Text(
              context.tr('customer.diagnostics.subtitle'),
              style: Theme.of(context).textTheme.bodyMedium,
            ),
            const SizedBox(height: 16),
            Card(
              key: const ValueKey('customer-diagnostics-status'),
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    _row(
                      context.tr('customer.diagnostics.version'),
                      app['version']?.toString() ?? '—',
                    ),
                    _row(
                      context.tr('customer.diagnostics.environment'),
                      environment['api_base_url']?.toString() ?? '—',
                    ),
                    _row(
                      context.tr('customer.diagnostics.route'),
                      navigation['current_route']?.toString() ?? '—',
                    ),
                    _row(
                      context.tr('customer.diagnostics.network'),
                      environment['network_state']?.toString() ?? 'unknown',
                    ),
                    _row(
                      context.tr('customer.diagnostics.events'),
                      events.length.toString(),
                    ),
                    _row(
                      context.tr('customer.diagnostics.last_event'),
                      lastEvent?.toString() ?? '—',
                    ),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 16),
            TextField(
              key: const ValueKey('customer-diagnostics-note'),
              controller: _note,
              maxLines: 4,
              maxLength: 1000,
              decoration: InputDecoration(
                labelText: context.tr('customer.diagnostics.note'),
                hintText: context.tr('customer.diagnostics.note_hint'),
              ),
            ),
            const SizedBox(height: 12),
            FilledButton.icon(
              key: const ValueKey('customer-diagnostics-export'),
              onPressed: _busy ? null : _export,
              icon: const Icon(Icons.ios_share_rounded),
              label: Text(context.tr('customer.diagnostics.export')),
            ),
            const SizedBox(height: 8),
            OutlinedButton.icon(
              key: const ValueKey('customer-diagnostics-copy'),
              onPressed: _busy ? null : _copySummary,
              icon: const Icon(Icons.copy_rounded),
              label: Text(context.tr('customer.diagnostics.copy_summary')),
            ),
            const SizedBox(height: 8),
            TextButton.icon(
              key: const ValueKey('customer-diagnostics-clear'),
              onPressed: _busy ? null : _clear,
              icon: const Icon(Icons.delete_outline_rounded),
              label: Text(context.tr('customer.diagnostics.clear')),
            ),
            const SizedBox(height: 18),
            Text(
              context.tr('customer.diagnostics.privacy'),
              style: Theme.of(context).textTheme.bodySmall,
            ),
            if (events.isNotEmpty) ...[
              const SizedBox(height: 18),
              Text(
                context.tr('customer.diagnostics.recent_events'),
                style: Theme.of(context).textTheme.titleMedium,
              ),
              const SizedBox(height: 8),
              ...events.reversed.take(8).map(
                    (event) => Card(
                      child: ListTile(
                        dense: true,
                        leading: const Icon(Icons.bug_report_outlined),
                        title: Text(
                          _eventTypeLabel(event['type']),
                        ),
                        subtitle: Text(
                          event['timestamp']?.toString() ?? '',
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                        ),
                      ),
                    ),
                  ),
            ],
          ],
        ),
      ),
    );
  }

  String _eventTypeLabel(Object? value) {
    final type = value?.toString().trim().toLowerCase() ?? '';
    final ar = Localizations.localeOf(context).languageCode == 'ar';
    if (type.contains('http') || type.contains('network')) {
      return ar ? 'طلب شبكة' : 'Network request';
    }
    if (type.contains('auth') || type.contains('session')) {
      return ar ? 'جلسة وتسجيل دخول' : 'Session and sign-in';
    }
    if (type.contains('navigation') || type.contains('route')) {
      return ar ? 'تنقل داخل التطبيق' : 'App navigation';
    }
    if (type.contains('runtime') || type.contains('exception')) {
      return ar ? 'تشغيل التطبيق' : 'App runtime';
    }
    return ar ? 'حدث تشخيصي' : 'Diagnostic event';
  }

  Widget _row(String label, String value) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 4),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(
              flex: 2,
              child: Text(
                label,
                style: const TextStyle(fontWeight: FontWeight.w700),
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              flex: 3,
              child: Text(
                value,
                textAlign: TextAlign.end,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
              ),
            ),
          ],
        ),
      );
}
