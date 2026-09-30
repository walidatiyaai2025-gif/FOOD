import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:cross_file/cross_file.dart';
import 'package:share_plus/share_plus.dart';

import '../../core/diagnostics/customer_diagnostics.dart';
import '../../core/localization/app_translations.dart';

class CustomerDiagnosticsScreen extends StatefulWidget {
  const CustomerDiagnosticsScreen({super.key});

  @override
  State<CustomerDiagnosticsScreen> createState() =>
      _CustomerDiagnosticsScreenState();
}

class _CustomerDiagnosticsScreenState extends State<CustomerDiagnosticsScreen> {
  final TextEditingController _note = TextEditingController();
  bool _exporting = false;

  CustomerDiagnostics get diagnostics => CustomerDiagnostics.instance;

  @override
  void dispose() {
    _note.dispose();
    super.dispose();
  }

  Future<void> _export() async {
    if (_exporting) return;
    setState(() => _exporting = true);
    try {
      final stamp = DateTime.now()
          .toUtc()
          .toIso8601String()
          .replaceAll(RegExp(r'[^0-9A-Za-z]'), '-');
      final file = File(
        '${Directory.systemTemp.path}/foodex-customer-diagnostics-$stamp.json',
      );
      const encoder = JsonEncoder.withIndent('  ');
      await file.writeAsString(
        encoder.convert(diagnostics.buildExport(note: _note.text)),
        flush: true,
      );
      await SharePlus.instance.share(
        ShareParams(
          files: [XFile(file.path, mimeType: 'application/json')],
          text: 'FOODEX Customer diagnostics',
        ),
      );
    } catch (error, stackTrace) {
      diagnostics.recordError('diagnostics_export_failure', error, stackTrace);
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(context.tr('customer.diagnostics.export_failed'))),
        );
      }
    } finally {
      if (mounted) setState(() => _exporting = false);
    }
  }

  Future<void> _copySummary() async {
    await Clipboard.setData(ClipboardData(text: diagnostics.copySummary()));
    if (mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(context.tr('customer.diagnostics.summary_copied'))),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      key: const ValueKey('customer-diagnostics-screen'),
      appBar: AppBar(title: Text(context.tr('customer.diagnostics.title'))),
      body: SafeArea(
        child: AnimatedBuilder(
          animation: diagnostics,
          builder: (context, _) {
            final events = diagnostics.events;
            final last = events.isEmpty ? null : events.last;
            return ListView(
              padding: const EdgeInsets.all(16),
              children: [
                Text(
                  context.tr('customer.diagnostics.subtitle'),
                  style: Theme.of(context).textTheme.bodyMedium,
                ),
                const SizedBox(height: 16),
                Card(
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        _row(context.tr('customer.diagnostics.version'), diagnostics.appVersion),
                        _row(context.tr('customer.diagnostics.environment'), diagnostics.environment),
                        _row(context.tr('customer.diagnostics.route'), diagnostics.route),
                        _row(context.tr('customer.diagnostics.connectivity'), diagnostics.connectivity),
                        _row(context.tr('customer.diagnostics.events'), '${events.length}'),
                        _row(
                          context.tr('customer.diagnostics.last_event'),
                          last?.timestamp.toLocal().toIso8601String() ?? '—',
                        ),
                      ],
                    ),
                  ),
                ),
                const SizedBox(height: 12),
                TextField(
                  key: const ValueKey('customer-diagnostics-note'),
                  controller: _note,
                  maxLines: 4,
                  maxLength: 500,
                  decoration: InputDecoration(
                    labelText: context.tr('customer.diagnostics.note'),
                    hintText: context.tr('customer.diagnostics.note_hint'),
                    border: const OutlineInputBorder(),
                  ),
                ),
                const SizedBox(height: 12),
                FilledButton.icon(
                  key: const ValueKey('customer-diagnostics-export'),
                  onPressed: _exporting ? null : _export,
                  icon: _exporting
                      ? const SizedBox.square(
                          dimension: 18,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        )
                      : const Icon(Icons.file_download_outlined),
                  label: Text(context.tr('customer.diagnostics.export')),
                ),
                const SizedBox(height: 8),
                OutlinedButton.icon(
                  key: const ValueKey('customer-diagnostics-copy-summary'),
                  onPressed: _copySummary,
                  icon: const Icon(Icons.copy_all_outlined),
                  label: Text(context.tr('customer.diagnostics.copy_summary')),
                ),
                const SizedBox(height: 8),
                TextButton.icon(
                  key: const ValueKey('customer-diagnostics-clear'),
                  onPressed: () => diagnostics.clear(),
                  icon: const Icon(Icons.delete_outline),
                  label: Text(context.tr('customer.diagnostics.clear')),
                ),
              ],
            );
          },
        ),
      ),
    );
  }

  Widget _row(String label, String value) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(child: Text(label)),
          const SizedBox(width: 12),
          Flexible(
            child: Text(
              value,
              textAlign: TextAlign.end,
              style: const TextStyle(fontWeight: FontWeight.w700),
            ),
          ),
        ],
      ),
    );
  }
}
