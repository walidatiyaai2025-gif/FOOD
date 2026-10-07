import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/auth/van_session.dart';
import '../../core/theme/foodex_van_theme.dart';
import '../wallet/van_wallet_contract.dart';

class VanCustomer360Page extends StatefulWidget {
  const VanCustomer360Page({
    super.key,
    required this.repository,
    required this.onSessionExpired,
  });

  final VanWalletRepository repository;
  final Future<void> Function() onSessionExpired;

  @override
  State<VanCustomer360Page> createState() => _VanCustomer360PageState();
}

class _VanCustomer360PageState extends State<VanCustomer360Page>
    with WidgetsBindingObserver {
  bool _loadingCustomers = true;
  bool _loadingContext = false;
  bool _refreshing = false;
  bool _stale = false;
  Object? _error;
  List<VanCustomerScope> _customers = const [];
  VanCustomerScope? _selected;
  VanCollectionContext? _context;

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
        !_loadingCustomers &&
        !_loadingContext &&
        !_refreshing) {
      unawaited(_refresh(background: true));
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  Future<void> _loadCustomers() async {
    setState(() {
      _loadingCustomers = true;
      _error = null;
    });

    try {
      final customers = await widget.repository.customers();
      if (!mounted) return;
      final selected = customers.isEmpty ? null : customers.first;
      setState(() {
        _customers = customers;
        _selected = selected;
        _loadingCustomers = false;
      });
      if (selected != null) {
        await _loadContext(selected);
      }
    } on VanSessionExpiredException {
      await widget.onSessionExpired();
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _loadingCustomers = false;
        _error = error;
      });
    }
  }

  Future<void> _loadContext(
    VanCustomerScope customer, {
    bool background = false,
  }) async {
    setState(() {
      if (background && _context != null) {
        _refreshing = true;
      } else {
        _loadingContext = true;
      }
      _error = null;
    });

    try {
      final context = await widget.repository.collectionContext(customer);
      if (!mounted) return;
      setState(() {
        _context = context;
        _loadingContext = false;
        _refreshing = false;
        _stale = false;
      });
    } on VanSessionExpiredException {
      await widget.onSessionExpired();
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _loadingContext = false;
        _refreshing = false;
        if (background && _context != null) {
          _stale = true;
        } else {
          _context = null;
          _error = error;
        }
      });
    }
  }

  Future<void> _refresh({bool background = false}) async {
    final selected = _selected;
    if (selected == null) {
      await _loadCustomers();
      return;
    }
    await _loadContext(selected, background: background);
  }

  Future<void> _selectCustomer(VanCustomerScope? customer) async {
    if (customer == null ||
        customer.id == _selected?.id && customer.type == _selected?.type) {
      return;
    }
    setState(() {
      _selected = customer;
      _context = null;
      _stale = false;
    });
    await _loadContext(customer);
  }

  @override
  Widget build(BuildContext context) {
    if (_loadingCustomers) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_error != null && _customers.isEmpty) {
      return _StateCard(
        icon: Icons.cloud_off_outlined,
        title: _text('Unable to load customer scope', 'تعذر تحميل نطاق العملاء'),
        body: _text(
          'Customer 360 only uses server-authorized customer data.',
          'ملف العميل يستخدم فقط بيانات العملاء المصرح بها من الخادم.',
        ),
        actionLabel: _text('Retry', 'إعادة المحاولة'),
        onAction: _loadCustomers,
      );
    }

    if (_customers.isEmpty) {
      return _StateCard(
        icon: Icons.account_circle_outlined,
        title: _text('No assigned customers', 'لا يوجد عملاء مسندون'),
        body: _text(
          'Customer 360 becomes available when the backend assigns a customer to this Van scope.',
          'يتاح ملف العميل عندما يقوم الخادم بإسناد عميل إلى نطاق هذا الفان.',
        ),
        actionLabel: _text('Refresh', 'تحديث'),
        onAction: _loadCustomers,
      );
    }

    final selected = _selected!;
    final collectionContext = _context;
    final invoices = collectionContext?.invoices ?? const <VanInvoiceBalance>[];
    final totalOutstanding = invoices.fold<double>(
      0,
      (sum, invoice) => sum + invoice.outstandingAmount,
    );

    return RefreshIndicator(
      onRefresh: _refresh,
      child: ListView(
        key: const ValueKey('van-customer-360'),
        padding: const EdgeInsets.fromLTRB(12, 10, 12, 24),
        children: [
          DropdownButtonFormField<VanCustomerScope>(
            key: const ValueKey('van-customer-360-selector'),
            value: selected,
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
            onChanged: _loadingContext ? null : _selectCustomer,
          ),
          const SizedBox(height: 12),
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
                        'Showing last confirmed customer financial context.',
                        'يتم عرض آخر سياق مالي مؤكد للعميل.',
                      )
                    : _text(
                        'Refreshing customer context…',
                        'جارٍ تحديث بيانات العميل…',
                      ),
              ),
            ),
          Card(
            elevation: 0,
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    selected.name,
                    key: const ValueKey('van-customer-360-name'),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: Theme.of(context).textTheme.titleLarge?.copyWith(
                          fontWeight: FontWeight.w900,
                        ),
                  ),
                  const SizedBox(height: 6),
                  Text(
                    selected.type.toUpperCase(),
                    style: Theme.of(context).textTheme.bodySmall?.copyWith(
                          color: FoodexVanTokens.muted,
                        ),
                  ),
                  if (collectionContext != null) ...[
                    const SizedBox(height: 10),
                    Text(
                      _text('Outstanding balance', 'الرصيد المستحق'),
                      style: Theme.of(context).textTheme.labelLarge,
                    ),
                    Text(
                      totalOutstanding.toStringAsFixed(3),
                      key: const ValueKey('van-customer-360-outstanding'),
                      style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                            color: FoodexVanTokens.green,
                            fontWeight: FontWeight.w900,
                          ),
                    ),
                  ],
                ],
              ),
            ),
          ),
          const SizedBox(height: 10),
          if (_loadingContext)
            const Center(child: CircularProgressIndicator())
          else if (_error != null)
            _InlineError(
              text: _text(
                'Unable to load current customer financial context.',
                'تعذر تحميل السياق المالي الحالي للعميل.',
              ),
              actionLabel: _text('Retry', 'إعادة المحاولة'),
              onAction: () => _loadContext(selected),
            )
          else if (invoices.isEmpty)
            _InlineEmpty(
              text: _text(
                'No outstanding invoices are available for this customer.',
                'لا توجد فواتير مستحقة متاحة لهذا العميل.',
              ),
            )
          else
            ...[
              Text(
                _text('Outstanding invoices', 'الفواتير المستحقة'),
                style: Theme.of(context).textTheme.titleMedium?.copyWith(
                      fontWeight: FontWeight.w900,
                    ),
              ),
              const SizedBox(height: 8),
              for (final invoice in invoices)
                Card(
                  elevation: 0,
                  margin: const EdgeInsets.only(bottom: 8),
                  child: ListTile(
                    title: Text(
                      invoice.number,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                    subtitle: Text(
                      _text('Invoice balance', 'رصيد الفاتورة'),
                    ),
                    trailing: Text(
                      '${invoice.outstandingAmount.toStringAsFixed(3)} ${invoice.currency}',
                      style: const TextStyle(fontWeight: FontWeight.w800),
                    ),
                  ),
                ),
            ],
        ],
      ),
    );
  }
}

class _InlineError extends StatelessWidget {
  const _InlineError({
    required this.text,
    required this.actionLabel,
    required this.onAction,
  });

  final String text;
  final String actionLabel;
  final Future<void> Function() onAction;

  @override
  Widget build(BuildContext context) {
    return Card(
      elevation: 0,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          children: [
            Text(text, textAlign: TextAlign.center),
            const SizedBox(height: 10),
            OutlinedButton(
              onPressed: onAction,
              child: Text(actionLabel),
            ),
          ],
        ),
      ),
    );
  }
}

class _InlineEmpty extends StatelessWidget {
  const _InlineEmpty({required this.text});

  final String text;

  @override
  Widget build(BuildContext context) {
    return Card(
      elevation: 0,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Text(text, textAlign: TextAlign.center),
      ),
    );
  }
}

class _StateCard extends StatelessWidget {
  const _StateCard({
    required this.icon,
    required this.title,
    required this.body,
    required this.actionLabel,
    required this.onAction,
  });

  final IconData icon;
  final String title;
  final String body;
  final String actionLabel;
  final Future<void> Function() onAction;

  @override
  Widget build(BuildContext context) {
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Card(
          elevation: 0,
          child: Padding(
            padding: const EdgeInsets.all(20),
            child: Column(
              children: [
                Icon(icon, size: 36, color: FoodexVanTokens.green),
                const SizedBox(height: 10),
                Text(
                  title,
                  textAlign: TextAlign.center,
                  style: Theme.of(context).textTheme.titleMedium?.copyWith(
                        fontWeight: FontWeight.w800,
                      ),
                ),
                const SizedBox(height: 6),
                Text(body, textAlign: TextAlign.center),
                const SizedBox(height: 14),
                FilledButton(
                  onPressed: onAction,
                  child: Text(actionLabel),
                ),
              ],
            ),
          ),
        ),
      ],
    );
  }
}
