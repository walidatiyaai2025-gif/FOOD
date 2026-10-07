import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/auth/van_session.dart';
import '../../core/theme/foodex_van_theme.dart';
import '../../shared/van_action_button.dart';
import 'van_wallet_contract.dart';

class VanCollectionPage extends StatefulWidget {
  const VanCollectionPage({
    super.key,
    required this.repository,
    required this.onSessionExpired,
  });

  final VanWalletRepository repository;
  final Future<void> Function() onSessionExpired;

  @override
  State<VanCollectionPage> createState() => _VanCollectionPageState();
}

class _VanCollectionPageState extends State<VanCollectionPage>
    with WidgetsBindingObserver {
  final _amount = TextEditingController();

  bool _loading = true;
  bool _refreshing = false;
  bool _submitting = false;
  bool _stale = false;
  Object? _error;
  List<VanCustomerScope> _customers = const [];
  VanCustomerScope? _customer;
  VanCollectionContext? _context;
  VanInvoiceBalance? _invoice;
  VanCollectionResult? _lastResult;
  String? _pendingOperation;
  String? _pendingIdempotencyKey;

  bool get _arabic => Localizations.localeOf(context).languageCode == 'ar';

  String _text(String en, String ar) => _arabic ? ar : en;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _loadCustomers();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed &&
        !_loading &&
        !_refreshing &&
        !_submitting) {
      unawaited(_refresh(background: true));
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _amount.dispose();
    super.dispose();
  }

  Future<void> _loadCustomers() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final customers = await widget.repository.customers();
      if (!mounted) return;
      final selected = customers.isEmpty ? null : customers.first;
      setState(() {
        _customers = customers;
        _customer = selected;
        _context = null;
        _invoice = null;
        _loading = false;
        _stale = false;
      });
      if (selected != null) {
        await _loadContext(selected);
      }
    } on VanSessionExpiredException {
      await widget.onSessionExpired();
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _error = error;
      });
    }
  }

  Future<void> _loadContext(
    VanCustomerScope customer, {
    bool background = false,
  }) async {
    if (mounted) {
      setState(() {
        if (background && _context != null) {
          _refreshing = true;
        } else {
          _loading = true;
        }
        _error = null;
      });
    }

    try {
      final context = await widget.repository.collectionContext(customer);
      if (!mounted) return;
      final currentInvoiceId = _invoice?.id;
      VanInvoiceBalance? selected;
      for (final invoice in context.invoices) {
        if (invoice.id == currentInvoiceId) {
          selected = invoice;
          break;
        }
      }
      selected ??= context.invoices.isEmpty ? null : context.invoices.first;
      setState(() {
        _context = context;
        _invoice = selected;
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
        if (background && _context != null) {
          _stale = true;
        } else {
          _error = error;
        }
      });
    }
  }

  Future<void> _refresh({bool background = false}) async {
    final customer = _customer;
    if (customer == null) {
      await _loadCustomers();
      return;
    }
    await _loadContext(customer, background: background);
  }

  Future<void> _selectCustomer(VanCustomerScope? customer) async {
    if (customer == null) return;
    setState(() {
      _customer = customer;
      _context = null;
      _invoice = null;
      _lastResult = null;
      _amount.clear();
      _pendingOperation = null;
      _pendingIdempotencyKey = null;
    });
    await _loadContext(customer);
  }

  void _selectInvoice(VanInvoiceBalance? invoice) {
    if (invoice == null) return;
    setState(() {
      _invoice = invoice;
      _lastResult = null;
      _amount.clear();
      _pendingOperation = null;
      _pendingIdempotencyKey = null;
    });
  }

  String _operationKey(
    VanCustomerScope customer,
    VanInvoiceBalance invoice,
    double amount,
  ) =>
      'collect:${customer.type}:${customer.id}:${customer.storeId}:'
      '${invoice.id}:${amount.toStringAsFixed(3)}';

  String _idempotencyKeyFor(String operation) {
    if (_pendingOperation == operation && _pendingIdempotencyKey != null) {
      return _pendingIdempotencyKey!;
    }
    final key = 'van-${DateTime.now().microsecondsSinceEpoch}';
    _pendingOperation = operation;
    _pendingIdempotencyKey = key;
    return key;
  }

  Future<void> _collect() async {
    if (_submitting) return;
    final customer = _customer;
    final invoice = _invoice;
    final amount = double.tryParse(_amount.text.trim());

    if (customer == null || invoice == null || amount == null || amount <= 0) {
      _message(
        _text(
          'Choose a customer and invoice, then enter a valid amount.',
          'اختر العميل والفاتورة ثم أدخل مبلغًا صحيحًا.',
        ),
      );
      return;
    }
    if (amount > invoice.outstandingAmount) {
      _message(
        _text(
          'Amount cannot exceed the outstanding invoice balance.',
          'لا يمكن أن يتجاوز المبلغ الرصيد المستحق للفاتورة.',
        ),
      );
      return;
    }

    setState(() => _submitting = true);
    final operation = _operationKey(customer, invoice, amount);

    try {
      final result = await widget.repository.collect(
        customer: customer,
        invoiceId: invoice.id,
        amount: amount,
        idempotencyKey: _idempotencyKeyFor(operation),
      );
      if (!mounted) return;
      setState(() {
        _lastResult = result;
        _pendingOperation = null;
        _pendingIdempotencyKey = null;
      });
      await _loadContext(customer);
    } on VanSessionExpiredException {
      await widget.onSessionExpired();
    } catch (error) {
      if (!mounted) return;
      _message(_errorText(error));
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  String _errorText(Object error) {
    if (error is VanOfflineException) {
      return _text(
        'Network unavailable. Retry preserves the same operation key.',
        'لا يوجد اتصال بالشبكة. إعادة المحاولة تحتفظ بنفس مفتاح العملية.',
      );
    }
    if (error is VanAccessDeniedException) {
      return _text('Access denied.', 'غير مصرح بهذه العملية.');
    }
    return _text(
      'Unable to complete the collection.',
      'تعذر إتمام عملية التحصيل.',
    );
  }

  void _message(String value) {
    ScaffoldMessenger.of(context)
      ..hideCurrentSnackBar()
      ..showSnackBar(SnackBar(content: Text(value)));
  }

  @override
  Widget build(BuildContext context) {
    if (_loading && _customers.isEmpty) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_error != null && _customers.isEmpty) {
      return _StateCard(
        title: _text('Unable to load collection scope', 'تعذر تحميل نطاق التحصيل'),
        actionLabel: _text('Retry', 'إعادة المحاولة'),
        onAction: _loadCustomers,
      );
    }

    if (_customers.isEmpty) {
      return _StateCard(
        title: _text(
          'No assigned customer collection context',
          'لا يوجد سياق تحصيل لعملاء مسندين',
        ),
        actionLabel: _text('Refresh', 'تحديث'),
        onAction: _loadCustomers,
      );
    }

    final invoices = _context?.invoices ?? const <VanInvoiceBalance>[];

    return RefreshIndicator(
      onRefresh: _refresh,
      child: ListView(
        key: const ValueKey('van-collection-page'),
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
                        'Showing last confirmed collection context.',
                        'يتم عرض آخر سياق تحصيل مؤكد.',
                      )
                    : _text(
                        'Refreshing collection context…',
                        'جارٍ تحديث سياق التحصيل…',
                      ),
              ),
            ),
          DropdownButtonFormField<VanCustomerScope>(
            key: const ValueKey('van-collection-customer'),
            initialValue: _customer,
            isExpanded: true,
            decoration: InputDecoration(
              labelText: _text('Customer', 'العميل'),
              border: const OutlineInputBorder(),
            ),
            items: [
              for (final customer in _customers)
                DropdownMenuItem(
                  value: customer,
                  child: Text(
                    customer.name,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
            ],
            onChanged: _submitting ? null : _selectCustomer,
          ),
          const SizedBox(height: 12),
          if (_loading)
            const Center(child: CircularProgressIndicator())
          else if (_error != null)
            _StateCard(
              title: _text(
                'Unable to load outstanding invoices',
                'تعذر تحميل الفواتير المستحقة',
              ),
              actionLabel: _text('Retry', 'إعادة المحاولة'),
              onAction: _refresh,
            )
          else if (invoices.isEmpty)
            Card(
              elevation: 0,
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Text(
                  _text(
                    'No collectible outstanding invoices for this customer.',
                    'لا توجد فواتير مستحقة قابلة للتحصيل لهذا العميل.',
                  ),
                  textAlign: TextAlign.center,
                ),
              ),
            )
          else ...[
            DropdownButtonFormField<VanInvoiceBalance>(
              key: const ValueKey('van-collection-invoice'),
              initialValue: _invoice,
              isExpanded: true,
              decoration: InputDecoration(
                labelText: _text('Invoice', 'الفاتورة'),
                border: const OutlineInputBorder(),
              ),
              items: [
                for (final invoice in invoices)
                  DropdownMenuItem(
                    value: invoice,
                    child: Text(
                      '${invoice.number} · '
                      '${invoice.outstandingAmount.toStringAsFixed(3)} '
                      '${invoice.currency}',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
              ],
              onChanged: _submitting ? null : _selectInvoice,
            ),
            const SizedBox(height: 12),
            TextField(
              key: const ValueKey('van-collection-amount'),
              controller: _amount,
              enabled: !_submitting,
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
              decoration: InputDecoration(
                labelText: _text('Collection amount', 'مبلغ التحصيل'),
                border: const OutlineInputBorder(),
              ),
            ),
            const SizedBox(height: 12),
            VanActionButton.icon(
              key: const ValueKey('van-collection-submit'),
              onPressed: _submitting ? null : _collect,
              icon: _submitting
                  ? const SizedBox.square(
                      dimension: 18,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : const Icon(Icons.payments_outlined),
              label: Text(_text('Post collection', 'تسجيل التحصيل')),
            ),
          ],
          if (_lastResult != null) ...[
            const SizedBox(height: 14),
            Card(
              key: const ValueKey('van-collection-receipt'),
              elevation: 0,
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      _text('Receipt posted', 'تم تسجيل الإيصال'),
                      style: Theme.of(context).textTheme.titleMedium?.copyWith(
                            fontWeight: FontWeight.w900,
                          ),
                    ),
                    const SizedBox(height: 6),
                    Text(
                      '#${_lastResult!.receipt.id} · '
                      '${_lastResult!.receipt.amount.toStringAsFixed(3)} '
                      '${_lastResult!.receipt.currency}',
                    ),
                    Text(
                      _text(
                        'Remaining: ${_lastResult!.remainingOutstanding.toStringAsFixed(3)} '
                            '${_lastResult!.receipt.currency}',
                        'المتبقي: ${_lastResult!.remainingOutstanding.toStringAsFixed(3)} '
                            '${_lastResult!.receipt.currency}',
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ],
        ],
      ),
    );
  }
}

class _StateCard extends StatelessWidget {
  const _StateCard({
    required this.title,
    required this.actionLabel,
    required this.onAction,
  });

  final String title;
  final String actionLabel;
  final Future<void> Function() onAction;

  @override
  Widget build(BuildContext context) {
    return Card(
      elevation: 0,
      child: Padding(
        padding: const EdgeInsets.all(18),
        child: Column(
          children: [
            Text(title, textAlign: TextAlign.center),
            const SizedBox(height: 12),
            VanActionButton.secondary(
              onPressed: onAction,
              child: Text(actionLabel),
            ),
          ],
        ),
      ),
    );
  }
}
