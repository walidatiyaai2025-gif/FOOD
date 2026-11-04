import 'package:flutter/material.dart';

import '../../core/api/b2c_account_api.dart';
import '../../core/localization/app_translations.dart';

// Single-purpose controls live outside invoice rows and respect the compact
// list policy. Invoice rows themselves use tap-to-open, never inline buttons.
Widget _invoiceDialogClose(BuildContext context, bool arabic) => TextButton(
      onPressed: () => Navigator.of(context).pop(),
      child: Text(arabic ? 'إغلاق' : 'Close'),
    );

Widget _invoiceRetry(BuildContext context, VoidCallback retry, bool arabic) =>
    TextButton(
      onPressed: retry,
      child: Text(arabic ? 'إعادة المحاولة' : 'Retry'),
    );

/// Displays immutable retail invoice snapshots for the selected store.
class CustomerRetailInvoicesScreen extends StatefulWidget {
  const CustomerRetailInvoicesScreen({
    required this.api,
    required this.storeId,
    super.key,
  });

  final CustomerRetailInvoiceApi api;
  final int storeId;

  @override
  State<CustomerRetailInvoicesScreen> createState() =>
      _CustomerRetailInvoicesScreenState();
}

class _CustomerRetailInvoicesScreenState
    extends State<CustomerRetailInvoicesScreen> {
  late Future<Object?> invoices;

  @override
  void initState() {
    super.initState();
    invoices = widget.api.invoicesForStore(widget.storeId);
  }

  @override
  void didUpdateWidget(covariant CustomerRetailInvoicesScreen oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.api != widget.api || oldWidget.storeId != widget.storeId) {
      invoices = widget.api.invoicesForStore(widget.storeId);
    }
  }

  Future<void> refresh() async {
    setState(() => invoices = widget.api.invoicesForStore(widget.storeId));
    try {
      await invoices;
    } catch (_) {
      // FutureBuilder renders the error and a retry control.
    }
  }

  bool isArabic(BuildContext context) =>
      Localizations.localeOf(context).languageCode == 'ar';

  List<Map<String, dynamic>> rows(Object? response) {
    if (response is! Map || response['data'] is! List) return const [];
    return (response['data'] as List)
        .whereType<Map>()
        .map((entry) => Map<String, dynamic>.from(entry))
        .toList(growable: false);
  }

  Future<void> showInvoice(Map<String, dynamic> summary) async {
    final id = int.tryParse(summary['id']?.toString() ?? '');
    if (id == null || id <= 0) return;
    final details = widget.api.invoiceForStore(widget.storeId, id);
    await showDialog<void>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: Text(summary['invoice_number']?.toString() ?? '#$id'),
        content: SizedBox(
          width: 440,
          child: FutureBuilder<Object?>(
            future: details,
            builder: (context, snapshot) {
              if (snapshot.connectionState != ConnectionState.done) {
                return const Center(child: CircularProgressIndicator());
              }
              if (snapshot.hasError) {
                return Text(isArabic(context)
                    ? 'تعذر تحميل تفاصيل الفاتورة'
                    : 'Could not load invoice details');
              }
              final data =
                  snapshot.data is Map && (snapshot.data as Map)['data'] is Map
                      ? Map<String, dynamic>.from(
                          (snapshot.data as Map)['data'] as Map)
                      : const <String, dynamic>{};
              final items = data['items'] is List
                  ? (data['items'] as List).whereType<Map>().toList()
                  : const <Map>[];
              return SingleChildScrollView(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text((isArabic(context) ? 'الحالة: ' : 'Status: ') +
                        (data['status']?.toString() ?? '—')),
                    Text((isArabic(context) ? 'الإجمالي: ' : 'Total: ') +
                        (data['grand_total']?.toString() ?? '—') +
                        ' ' +
                        (data['currency']?.toString() ?? 'EGP')),
                    const Divider(),
                    for (final line in items)
                      ListTile(
                        dense: true,
                        contentPadding: EdgeInsets.zero,
                        title: Text(line['name']?.toString() ?? ''),
                        subtitle: Text(
                          (line['quantity']?.toString() ?? '—') +
                              ' × ' +
                              (line['unit_price']?.toString() ?? '—'),
                        ),
                        trailing: Text(line['line_total']?.toString() ?? ''),
                      ),
                  ],
                ),
              );
            },
          ),
        ),
        actions: [_invoiceDialogClose(dialogContext, isArabic(dialogContext))],
      ),
    );
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        key: const ValueKey('retail-invoices-screen'),
        appBar: AppBar(title: Text(context.tr('customer.nav.invoices'))),
        body: FutureBuilder<Object?>(
          future: invoices,
          builder: (context, snapshot) {
            if (snapshot.connectionState != ConnectionState.done) {
              return const Center(child: CircularProgressIndicator());
            }
            if (snapshot.hasError) {
              return Center(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(isArabic(context)
                        ? 'تعذر تحميل الفواتير'
                        : 'Could not load invoices'),
                    _invoiceRetry(context, refresh, isArabic(context)),
                  ],
                ),
              );
            }
            final list = rows(snapshot.data);
            if (list.isEmpty) {
              return Center(
                child: Text(isArabic(context)
                    ? 'لا توجد فواتير لهذا المتجر'
                    : 'No invoices for this store'),
              );
            }
            return RefreshIndicator(
              onRefresh: refresh,
              child: ListView.separated(
                padding: const EdgeInsets.all(16),
                itemCount: list.length,
                separatorBuilder: (_, __) => const SizedBox(height: 8),
                itemBuilder: (context, index) {
                  final invoice = list[index];
                  return Card(
                    child: ListTile(
                      key: ValueKey('retail-invoice-' +
                          (invoice['id']?.toString() ?? 'unknown')),
                      title: Text(
                        invoice['invoice_number']?.toString() ?? '—',
                      ),
                      subtitle: Text(
                        (invoice['issued_at']?.toString() ?? '') +
                            ' · ' +
                            (invoice['status']?.toString() ?? ''),
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                      ),
                      trailing: Text(
                        (invoice['grand_total']?.toString() ?? '—') +
                            ' ' +
                            (invoice['currency']?.toString() ?? 'EGP'),
                      ),
                      onTap: () => showInvoice(invoice),
                    ),
                  );
                },
              ),
            );
          },
        ),
      );
}
