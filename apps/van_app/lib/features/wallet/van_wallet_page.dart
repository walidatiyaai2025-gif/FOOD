import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/auth/van_session.dart';
import '../../core/theme/foodex_van_theme.dart';
import 'van_wallet_contract.dart';

class VanWalletPage extends StatefulWidget {
  const VanWalletPage({
    super.key,
    required this.repository,
    required this.onSessionExpired,
  });

  final VanWalletRepository repository;
  final Future<void> Function() onSessionExpired;

  @override
  State<VanWalletPage> createState() => _VanWalletPageState();
}

class _VanWalletPageState extends State<VanWalletPage>
    with WidgetsBindingObserver {
  bool _loading = true;
  bool _refreshing = false;
  bool _stale = false;
  bool _submitting = false;
  Object? _error;
  List<VanWalletAccount> _accounts = const [];
  List<VanCustomerScope> _customers = const [];
  final Map<String, String> _pendingIdempotencyKeys = {};

  bool get _arabic => Localizations.localeOf(context).languageCode == 'ar';

  String _t(String en, String ar) => _arabic ? ar : en;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _load();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed && !_loading && !_refreshing) {
      unawaited(_load(background: true));
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  Future<void> _load({bool background = false}) async {
    if (mounted) {
      setState(() {
        if (background && (_accounts.isNotEmpty || _customers.isNotEmpty)) {
          _refreshing = true;
        } else {
          _loading = true;
        }
        _error = null;
      });
    }

    try {
      final results = await Future.wait<Object>([
        widget.repository.wallet(),
        widget.repository.customers(),
      ]);
      if (!mounted) return;
      setState(() {
        _accounts = results[0] as List<VanWalletAccount>;
        _customers = results[1] as List<VanCustomerScope>;
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
        if (background && (_accounts.isNotEmpty || _customers.isNotEmpty)) {
          _stale = true;
        } else {
          _error = error;
        }
      });
    }
  }

  String _money(double value, String currency) =>
      '${value.toStringAsFixed(3)} $currency';

  String _idempotencyKey(String operation) {
    return _pendingIdempotencyKeys.putIfAbsent(
      operation,
      () => 'van-${DateTime.now().microsecondsSinceEpoch}',
    );
  }

  void _forgetIdempotencyKey(String operation) {
    _pendingIdempotencyKeys.remove(operation);
  }

  Future<void> _collect(VanCustomerScope customer) async {
    if (_submitting) return;
    setState(() => _submitting = true);

    try {
      final collectionContext =
          await widget.repository.collectionContext(customer);
      if (!mounted) return;

      if (collectionContext.invoices.isEmpty) {
        _message(
          _t(
            'No collectible outstanding invoices for this customer.',
            'لا توجد فواتير مستحقة قابلة للتحصيل لهذا العميل.',
          ),
        );
        return;
      }

      final draft = await showDialog<_CollectionDraft>(
        context: context,
        builder: (dialogContext) => _CollectionDialog(
          invoices: collectionContext.invoices,
          arabic: _arabic,
        ),
      );
      if (draft == null || !mounted) return;

      final operation =
          'collect:${customer.type}:${customer.id}:${customer.storeId}:'
          '${draft.invoiceId}:${draft.amount.toStringAsFixed(3)}';
      final result = await widget.repository.collect(
        customer: customer,
        invoiceId: draft.invoiceId,
        amount: draft.amount,
        idempotencyKey: _idempotencyKey(operation),
      );
      _forgetIdempotencyKey(operation);

      if (!mounted) return;
      _message(
        _t(
          'Receipt #${result.receipt.id} posted. Remaining: '
              '${_money(result.remainingOutstanding, result.receipt.currency)}',
          'تم تسجيل الإيصال #${result.receipt.id}. المتبقي: '
              '${_money(result.remainingOutstanding, result.receipt.currency)}',
        ),
      );
      await _load();
    } on VanSessionExpiredException {
      await widget.onSessionExpired();
    } catch (error) {
      if (!mounted) return;
      _message(_errorText(error));
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  Future<void> _remit(VanWalletAccount account) async {
    if (_submitting || account.availableToRemit <= 0) return;

    final draft = await showDialog<_RemittanceDraft>(
      context: context,
      builder: (dialogContext) => _RemittanceDialog(
        account: account,
        arabic: _arabic,
      ),
    );
    if (draft == null || !mounted) return;

    setState(() => _submitting = true);
    final operation =
        'remit:${account.id}:${draft.amount.toStringAsFixed(3)}:${draft.method}';

    try {
      await widget.repository.remit(
        collectionAccountId: account.id,
        amount: draft.amount,
        method: draft.method,
        reference: draft.reference,
        note: draft.note,
        idempotencyKey: _idempotencyKey(operation),
      );
      _forgetIdempotencyKey(operation);
      if (!mounted) return;
      _message(_t('Remittance submitted.', 'تم إرسال التوريد.'));
      await _load();
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
      return _t(
        'Network unavailable. Retry keeps the same operation key.',
        'لا يوجد اتصال بالشبكة. إعادة المحاولة تحتفظ بنفس مفتاح العملية.',
      );
    }
    if (error is VanAccessDeniedException) {
      return _t('Access denied.', 'غير مصرح بهذه العملية.');
    }
    return _t('Unable to complete the operation.', 'تعذر إتمام العملية.');
  }

  void _message(String value) {
    ScaffoldMessenger.of(context)
      ..hideCurrentSnackBar()
      ..showSnackBar(SnackBar(content: Text(value)));
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_error != null) {
      return Center(
        child: FilledButton.icon(
          key: const Key('van-wallet-retry'),
          onPressed: _load,
          icon: const Icon(Icons.refresh),
          label: Text(_t('Retry', 'إعادة المحاولة')),
        ),
      );
    }

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        key: const Key('van-wallet-page'),
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
        children: [
          if (_refreshing) ...[
            const LinearProgressIndicator(key: Key('van-wallet-refreshing')),
            const SizedBox(height: 6),
          ],
          if (_stale) ...[
            _InfoCard(
              key: const Key('van-wallet-stale-banner'),
              icon: Icons.cloud_off_outlined,
              text: _t(
                'Connection is unavailable. Showing the last server balances until refresh succeeds.',
                'الاتصال غير متاح. يتم عرض آخر أرصدة من الخادم حتى ينجح التحديث.',
              ),
            ),
            const SizedBox(height: 8),
          ],
          _SectionTitle(
            title: _t('Custody wallet', 'محفظة العهدة'),
            subtitle: _t(
              'Balances come from the shared custody ledger.',
              'الأرصدة تأتي من سجل العهدة المالي المشترك.',
            ),
          ),
          const SizedBox(height: 8)
          if (_accounts.isEmpty)
            _InfoCard(
              icon: Icons.account_balance_wallet_outlined,
              text: _t(
                'No custody balance yet.',
                'لا يوجد رصيد عهدة حتى الآن.',
              ),
            )
          else
            ..._accounts.map(
              (account) => Padding(
                padding: const EdgeInsets.only(bottom: 12),
                child: _WalletCard(
                  account: account,
                  arabic: _arabic,
                  disabled: _submitting,
                  onRemit: () => _remit(account),
                ),
              ),
            ),
          const SizedBox(height: 12),
          _SectionTitle(
            title: _t('Customer collection', 'تحصيل العملاء'),
            subtitle: _t(
              'Only customers already assigned to your Van visit scope appear here.',
              'يظهر فقط العملاء الموجودون في نطاق زيارات السيارة المسندة لك.',
            ),
          ),
          const SizedBox(height: 12),
          if (_customers.isEmpty)
            _InfoCard(
              icon: Icons.people_outline,
              text: _t(
                'No assigned customer collection context.',
                'لا يوجد سياق تحصيل لعملاء مسندين.',
              ),
            )
          else
            ..._customers.map(
              (customer) => Card(
                margin: const EdgeInsets.only(bottom: 10),
                child: ListTile(
                  key: Key(
                    'van-collect-customer-${customer.type}-${customer.id}-${customer.storeId}',
                  ),
                  leading: const CircleAvatar(
                    child: Icon(Icons.storefront_outlined),
                  ),
                  title: Text(
                    customer.name.isEmpty
                        ? '#${customer.id}'
                        : customer.name,
                  ),
                  subtitle: Text(
                    '${customer.type.toUpperCase()} · '
                    '${_t('Store', 'المتجر')} #${customer.storeId}',
                  ),
                  onTap: _submitting ? null : () => _collect(customer),
                  trailing: FilledButton(
                    onPressed: _submitting ? null : () => _collect(customer),
                    child: Text(_t('Collect', 'تحصيل')),
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }
}

class _SectionTitle extends StatelessWidget {
  const _SectionTitle({required this.title, required this.subtitle});

  final String title;
  final String subtitle;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          title,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: Theme.of(context).textTheme.titleMedium,
        ),
        const SizedBox(height: 2),
        Text(
          subtitle,
          maxLines: 2,
          overflow: TextOverflow.ellipsis,
          style: Theme.of(context).textTheme.bodySmall?.copyWith(
                color: FoodexVanTokens.muted,
              ),
        ),
      ],
    );
  }
}

class _InfoCard extends StatelessWidget {
  const _InfoCard({super.key, required this.icon, required this.text});

  final IconData icon;
  final String text;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Row(
          children: [
            Icon(icon, color: FoodexVanTokens.green),
            const SizedBox(width: 12),
            Expanded(child: Text(text)),
          ],
        ),
      ),
    );
  }
}

class _WalletCard extends StatelessWidget {
  const _WalletCard({
    required this.account,
    required this.arabic,
    required this.disabled,
    required this.onRemit,
  });

  final VanWalletAccount account;
  final bool arabic;
  final bool disabled;
  final VoidCallback onRemit;

  String _t(String en, String ar) => arabic ? ar : en;

  @override
  Widget build(BuildContext context) {
    final pending =
        account.remittances.where((item) => item.status == 'pending').length;

    return Card(
      key: Key('van-wallet-account-${account.id}'),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    '${_t('Store', 'المتجر')} #${account.storeId ?? '-'} · '
                    '${account.currency}',
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                ),
                Chip(label: Text(account.status)),
              ],
            ),
            const SizedBox(height: 12),
            Wrap(
              spacing: 20,
              runSpacing: 8,
              children: [
                _Metric(
                  label: _t('Custody', 'العهدة'),
                  value:
                      '${account.custodyBalance.toStringAsFixed(3)} ${account.currency}',
                ),
                _Metric(
                  label: _t('Available to remit', 'متاح للتوريد'),
                  value:
                      '${account.availableToRemit.toStringAsFixed(3)} ${account.currency}',
                ),
                _Metric(
                  label: _t('Pending remittances', 'توريدات معلقة'),
                  value: '$pending',
                ),
              ],
            ),
            const SizedBox(height: 14),
            Align(
              alignment: AlignmentDirectional.centerEnd,
              child: FilledButton.icon(
                key: Key('van-remit-${account.id}'),
                onPressed: disabled || account.availableToRemit <= 0
                    ? null
                    : onRemit,
                icon: const Icon(Icons.account_balance_outlined),
                label: Text(_t('Submit remittance', 'إرسال توريد')),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _Metric extends StatelessWidget {
  const _Metric({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return ConstrainedBox(
      constraints: const BoxConstraints(minWidth: 140),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            label,
            style: Theme.of(context).textTheme.bodySmall?.copyWith(
                  color: FoodexVanTokens.muted,
                ),
          ),
          const SizedBox(height: 2),
          Text(value, style: Theme.of(context).textTheme.titleMedium),
        ],
      ),
    );
  }
}

class _CollectionDraft {
  const _CollectionDraft({required this.invoiceId, required this.amount});

  final int invoiceId;
  final double amount;
}

class _CollectionDialog extends StatefulWidget {
  const _CollectionDialog({
    required this.invoices,
    required this.arabic,
  });

  final List<VanInvoiceBalance> invoices;
  final bool arabic;

  @override
  State<_CollectionDialog> createState() => _CollectionDialogState();
}

class _CollectionDialogState extends State<_CollectionDialog> {
  late VanInvoiceBalance _invoice;
  late final TextEditingController _amount;

  String _t(String en, String ar) => widget.arabic ? ar : en;

  @override
  void initState() {
    super.initState();
    _invoice = widget.invoices.first;
    _amount =
        TextEditingController(text: _invoice.outstandingAmount.toStringAsFixed(3));
  }

  @override
  void dispose() {
    _amount.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: Text(_t('Collect customer payment', 'تحصيل دفعة من العميل')),
      content: SizedBox(
        width: 420,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            DropdownButtonFormField<int>(
              key: const Key('van-collection-invoice'),
              initialValue: _invoice.id,
              decoration: InputDecoration(
                labelText: _t('Invoice', 'الفاتورة'),
              ),
              items: widget.invoices
                  .map(
                    (invoice) => DropdownMenuItem<int>(
                      value: invoice.id,
                      child: Text(
                        '${invoice.number} · '
                        '${invoice.outstandingAmount.toStringAsFixed(3)} '
                        '${invoice.currency}',
                      ),
                    ),
                  )
                  .toList(growable: false),
              onChanged: (value) {
                if (value == null) return;
                final next =
                    widget.invoices.firstWhere((invoice) => invoice.id == value);
                setState(() {
                  _invoice = next;
                  _amount.text = next.outstandingAmount.toStringAsFixed(3);
                });
              },
            ),
            const SizedBox(height: 12),
            TextField(
              key: const Key('van-collection-amount'),
              controller: _amount,
              keyboardType:
                  const TextInputType.numberWithOptions(decimal: true),
              decoration: InputDecoration(
                labelText: _t('Amount received', 'المبلغ المستلم'),
                suffixText: _invoice.currency,
              ),
            ),
          ],
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: Text(_t('Cancel', 'إلغاء')),
        ),
        FilledButton(
          key: const Key('van-collection-submit'),
          onPressed: () {
            final value = double.tryParse(_amount.text.trim());
            if (value == null ||
                value <= 0 ||
                value > _invoice.outstandingAmount + 0.0005) {
              return;
            }
            Navigator.of(context).pop(
              _CollectionDraft(invoiceId: _invoice.id, amount: value),
            );
          },
          child: Text(_t('Post collection', 'تسجيل التحصيل')),
        ),
      ],
    );
  }
}

class _RemittanceDraft {
  const _RemittanceDraft({
    required this.amount,
    required this.method,
    this.reference,
    this.note,
  });

  final double amount;
  final String method;
  final String? reference;
  final String? note;
}

class _RemittanceDialog extends StatefulWidget {
  const _RemittanceDialog({
    required this.account,
    required this.arabic,
  });

  final VanWalletAccount account;
  final bool arabic;

  @override
  State<_RemittanceDialog> createState() => _RemittanceDialogState();
}

class _RemittanceDialogState extends State<_RemittanceDialog> {
  late final TextEditingController _amount;
  final _method = TextEditingController();
  final _reference = TextEditingController();
  final _note = TextEditingController();

  String _t(String en, String ar) => widget.arabic ? ar : en;

  @override
  void initState() {
    super.initState();
    _amount = TextEditingController(
      text: widget.account.availableToRemit.toStringAsFixed(3),
    );
  }

  @override
  void dispose() {
    _amount.dispose();
    _method.dispose();
    _reference.dispose();
    _note.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: Text(_t('Submit remittance', 'إرسال توريد')),
      content: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            TextField(
              key: const Key('van-remittance-amount'),
              controller: _amount,
              keyboardType:
                  const TextInputType.numberWithOptions(decimal: true),
              decoration: InputDecoration(
                labelText: _t('Amount', 'المبلغ'),
                suffixText: widget.account.currency,
              ),
            ),
            const SizedBox(height: 12),
            TextField(
              key: const Key('van-remittance-method'),
              controller: _method,
              decoration: InputDecoration(
                labelText: _t('Configured method', 'طريقة التوريد المهيأة'),
              ),
            ),
            const SizedBox(height: 12),
            TextField(
              controller: _reference,
              decoration: InputDecoration(
                labelText: _t('Reference (optional)', 'المرجع (اختياري)'),
              ),
            ),
            const SizedBox(height: 12),
            TextField(
              controller: _note,
              maxLines: 2,
              decoration: InputDecoration(
                labelText: _t('Note (optional)', 'ملاحظة (اختياري)'),
              ),
            ),
          ],
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: Text(_t('Cancel', 'إلغاء')),
        ),
        FilledButton(
          key: const Key('van-remittance-submit'),
          onPressed: () {
            final amount = double.tryParse(_amount.text.trim());
            final method = _method.text.trim();
            if (amount == null ||
                amount <= 0 ||
                amount > widget.account.availableToRemit + 0.0005 ||
                method.isEmpty) {
              return;
            }
            Navigator.of(context).pop(
              _RemittanceDraft(
                amount: amount,
                method: method,
                reference: _reference.text.trim().isEmpty
                    ? null
                    : _reference.text.trim(),
                note: _note.text.trim().isEmpty ? null : _note.text.trim(),
              ),
            );
          },
          child: Text(_t('Submit', 'إرسال')),
        ),
      ],
    );
  }
}
