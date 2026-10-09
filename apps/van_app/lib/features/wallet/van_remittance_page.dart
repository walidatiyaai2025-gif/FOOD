import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/auth/van_session.dart';
import '../../core/theme/foodex_van_theme.dart';
import '../../shared/van_action_button.dart';
import 'van_wallet_contract.dart';

class VanRemittancePage extends StatefulWidget {
  const VanRemittancePage({
    super.key,
    required this.repository,
    required this.onSessionExpired,
  });

  final VanWalletRepository repository;
  final Future<void> Function() onSessionExpired;

  @override
  State<VanRemittancePage> createState() => _VanRemittancePageState();
}

class _VanRemittancePageState extends State<VanRemittancePage>
    with WidgetsBindingObserver {
  final _amount = TextEditingController();
  final _reference = TextEditingController();
  final _note = TextEditingController();

  bool _loading = true;
  bool _refreshing = false;
  bool _submitting = false;
  bool _stale = false;
  Object? _error;
  List<VanWalletAccount> _accounts = const [];
  VanWalletAccount? _account;
  String _method = 'cash_deposit';
  String? _pendingOperation;
  String? _pendingIdempotencyKey;
  VanWalletAccount? _lastResult;

  bool get _arabic => Localizations.localeOf(context).languageCode == 'ar';

  String _text(String en, String ar) => _arabic ? ar : en;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _load();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed &&
        !_loading &&
        !_refreshing &&
        !_submitting) {
      unawaited(_load(background: true));
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _amount.dispose();
    _reference.dispose();
    _note.dispose();
    super.dispose();
  }

  Future<void> _load({bool background = false}) async {
    if (mounted) {
      setState(() {
        if (background && _accounts.isNotEmpty) {
          _refreshing = true;
        } else {
          _loading = true;
        }
        _error = null;
      });
    }

    try {
      final accounts = await widget.repository.wallet();
      if (!mounted) return;
      VanWalletAccount? selected;
      final currentId = _account?.id;
      for (final account in accounts) {
        if (account.id == currentId) {
          selected = account;
          break;
        }
      }
      if (selected == null) {
        for (final account in accounts) {
          if (account.availableToRemit > 0) {
            selected = account;
            break;
          }
        }
      }
      selected ??= accounts.isEmpty ? null : accounts.first;
      setState(() {
        _accounts = accounts;
        _account = selected;
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
        if (background && _accounts.isNotEmpty) {
          _stale = true;
        } else {
          _error = error;
        }
      });
    }
  }

  String _operationKey(VanWalletAccount account, double amount) =>
      'remit:${account.id}:${amount.toStringAsFixed(3)}:$_method';

  String _idempotencyKeyFor(String operation) {
    if (_pendingOperation == operation && _pendingIdempotencyKey != null) {
      return _pendingIdempotencyKey!;
    }
    final key = 'van-${DateTime.now().microsecondsSinceEpoch}';
    _pendingOperation = operation;
    _pendingIdempotencyKey = key;
    return key;
  }

  Future<void> _submit() async {
    if (_submitting) return;
    final account = _account;
    final amount = double.tryParse(_amount.text.trim());

    if (account == null || amount == null || amount <= 0) {
      _message(_text('Enter a valid remittance amount.', 'أدخل مبلغ توريد صحيح.'));
      return;
    }
    if (amount > account.availableToRemit) {
      _message(
        _text(
          'Amount cannot exceed the available remittance balance.',
          'لا يمكن أن يتجاوز المبلغ الرصيد المتاح للتوريد.',
        ),
      );
      return;
    }

    setState(() => _submitting = true);
    final operation = _operationKey(account, amount);

    try {
      final result = await widget.repository.remit(
        collectionAccountId: account.id,
        amount: amount,
        method: _method,
        reference:
            _reference.text.trim().isEmpty ? null : _reference.text.trim(),
        note: _note.text.trim().isEmpty ? null : _note.text.trim(),
        idempotencyKey: _idempotencyKeyFor(operation),
      );
      if (!mounted) return;
      setState(() {
        _lastResult = result;
        _pendingOperation = null;
        _pendingIdempotencyKey = null;
      });
      _amount.clear();
      _reference.clear();
      _note.clear();
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
      return _text(
        'Network unavailable. Retry preserves the same operation key.',
        'لا يوجد اتصال بالشبكة. إعادة المحاولة تحتفظ بنفس مفتاح العملية.',
      );
    }
    if (error is VanAccessDeniedException) {
      return _text('Access denied.', 'غير مصرح بهذه العملية.');
    }
    if (error is VanApiException) {
      return switch (error.statusCode) {
        404 => _text(
            'This customer or financial account is no longer in the current Van scope. Refresh and try again.',
            'العميل أو الحساب المالي لم يعد ضمن نطاق الفان الحالي. حدّث البيانات وحاول مرة أخرى.',
          ),
        409 => _text(
            'The financial state changed. Refresh the balance and retry the operation.',
            'تغيّرت حالة العملية المالية. حدّث الرصيد ثم أعد المحاولة.',
          ),
        422 => _text(
            'Check the amount and operation details, then try again.',
            'راجع المبلغ وبيانات العملية ثم حاول مرة أخرى.',
          ),
        _ => _text(
            'The server could not save this financial operation. Try again.',
            'تعذر على الخادم حفظ العملية المالية. حاول مرة أخرى.',
          ),
      };
    }
    return _text(
      'Unable to submit the remittance.',
      'تعذر إرسال عملية التوريد.',
    );
  }

  void _message(String value) {
    ScaffoldMessenger.of(context)
      ..hideCurrentSnackBar()
      ..showSnackBar(SnackBar(content: Text(value)));
  }

  @override
  Widget build(BuildContext context) {
    if (_loading && _accounts.isEmpty) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_error != null && _accounts.isEmpty) {
      return _StateCard(
        title: _text('Unable to load remittance balance', 'تعذر تحميل رصيد التوريد'),
        actionLabel: _text('Retry', 'إعادة المحاولة'),
        onAction: _load,
      );
    }

    if (_accounts.isEmpty) {
      return _StateCard(
        title: _text('No custody account available', 'لا توجد محفظة عهدة متاحة'),
        actionLabel: _text('Refresh', 'تحديث'),
        onAction: _load,
      );
    }

    final account = _account!;

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        key: const ValueKey('van-remittance-page'),
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
                        'Showing the last confirmed custody balance.',
                        'يتم عرض آخر رصيد عهدة مؤكد.',
                      )
                    : _text(
                        'Refreshing custody balance…',
                        'جارٍ تحديث رصيد العهدة…',
                      ),
              ),
            ),
          DropdownButtonFormField<VanWalletAccount>(
            key: const ValueKey('van-remittance-account'),
            initialValue: account,
            isExpanded: true,
            decoration: InputDecoration(
              labelText: _text('Custody account', 'محفظة العهدة'),
              border: const OutlineInputBorder(),
            ),
            items: [
              for (final item in _accounts)
                DropdownMenuItem(
                  value: item,
                  child: Text(
                    '#${item.id} · ${item.availableToRemit.toStringAsFixed(3)} '
                    '${item.currency}',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
            ],
            onChanged: _submitting
                ? null
                : (value) {
                    if (value == null) return;
                    setState(() {
                      _account = value;
                      _lastResult = null;
                      _pendingOperation = null;
                      _pendingIdempotencyKey = null;
                    });
                  },
          ),
          const SizedBox(height: 12),
          Card(
            elevation: 0,
            child: ListTile(
              title: Text(_text('Available to remit', 'المتاح للتوريد')),
              trailing: Text(
                '${account.availableToRemit.toStringAsFixed(3)} '
                '${account.currency}',
                key: const ValueKey('van-remittance-available'),
                style: const TextStyle(
                  color: FoodexVanTokens.green,
                  fontWeight: FontWeight.w900,
                ),
              ),
            ),
          ),
          const SizedBox(height: 12),
          TextField(
            key: const ValueKey('van-remittance-amount'),
            controller: _amount,
            enabled: !_submitting,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            decoration: InputDecoration(
              labelText: _text('Amount', 'المبلغ'),
              border: const OutlineInputBorder(),
            ),
          ),
          const SizedBox(height: 12),
          DropdownButtonFormField<String>(
            key: const ValueKey('van-remittance-method'),
            initialValue: _method,
            decoration: InputDecoration(
              labelText: _text('Method', 'طريقة التوريد'),
              border: const OutlineInputBorder(),
            ),
            items: [
              DropdownMenuItem(
                value: 'cash_deposit',
                child: Text(_text('Cash deposit', 'إيداع نقدي')),
              ),
              DropdownMenuItem(
                value: 'bank_transfer',
                child: Text(_text('Bank transfer', 'تحويل بنكي')),
              ),
            ],
            onChanged: _submitting
                ? null
                : (value) {
                    if (value != null) {
                      setState(() {
                        _method = value;
                        _pendingOperation = null;
                        _pendingIdempotencyKey = null;
                      });
                    }
                  },
          ),
          const SizedBox(height: 12),
          TextField(
            key: const ValueKey('van-remittance-reference'),
            controller: _reference,
            enabled: !_submitting,
            decoration: InputDecoration(
              labelText: _text('Reference (optional)', 'المرجع (اختياري)'),
              border: const OutlineInputBorder(),
            ),
          ),
          const SizedBox(height: 12),
          TextField(
            key: const ValueKey('van-remittance-note'),
            controller: _note,
            enabled: !_submitting,
            maxLines: 2,
            decoration: InputDecoration(
              labelText: _text('Note (optional)', 'ملاحظة (اختياري)'),
              border: const OutlineInputBorder(),
            ),
          ),
          const SizedBox(height: 12),
          VanActionButton.icon(
            key: const ValueKey('van-remittance-submit'),
            onPressed: _submitting ? null : _submit,
            icon: _submitting
                ? const SizedBox.square(
                    dimension: 18,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : const Icon(Icons.account_balance_outlined),
            label: Text(_text('Submit remittance', 'إرسال التوريد')),
          ),
          if (_lastResult != null) ...[
            const SizedBox(height: 14),
            Card(
              key: const ValueKey('van-remittance-result'),
              elevation: 0,
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Text(
                  _text(
                    'Remittance accepted. Available balance: '
                        '${_lastResult!.availableToRemit.toStringAsFixed(3)} '
                        '${_lastResult!.currency}',
                    'تم قبول التوريد. الرصيد المتاح: '
                        '${_lastResult!.availableToRemit.toStringAsFixed(3)} '
                        '${_lastResult!.currency}',
                  ),
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
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Card(
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
        ),
      ],
    );
  }
}
