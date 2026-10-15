import 'dart:async';

import 'package:flutter/material.dart';
import 'package:foodex_formatting/foodex_formatting.dart';

import '../../core/auth/van_session.dart';
import '../../core/theme/foodex_van_theme.dart';
import 'van_order_contract.dart';
import 'van_payment_method_labels.dart';

class VanOrderReviewPage extends StatefulWidget {
  const VanOrderReviewPage({
    super.key,
    required this.repository,
    required this.draft,
    required this.onSubmitted,
    required this.onSessionExpired,
  });

  final VanOrderRepository repository;
  final VanOrderDraftController draft;
  final ValueChanged<VanOrderRecord> onSubmitted;
  final Future<void> Function() onSessionExpired;

  @override
  State<VanOrderReviewPage> createState() => _VanOrderReviewPageState();
}

class _VanOrderReviewPageState extends State<VanOrderReviewPage> {
  final _note = TextEditingController();
  bool _loading = false;
  bool _submitting = false;
  bool _quoteFailed = false;
  int _quoteRequestSerial = 0;
  Timer? _noteReloadTimer;
  int? _idempotencyRevision;
  String? _idempotencyKey;

  bool get _arabic => Localizations.localeOf(context).languageCode == 'ar';
  String _text(String en, String ar) => _arabic ? ar : en;

  @override
  void initState() {
    super.initState();
    _note.text = widget.draft.customerNote ?? '';
    if (widget.draft.options == null || !widget.draft.hasCurrentQuote) {
      _reload();
    }
  }

  @override
  void dispose() {
    _quoteRequestSerial += 1;
    _noteReloadTimer?.cancel();
    _note.dispose();
    super.dispose();
  }

  Future<void> _reload() async {
    final customer = widget.draft.customer;
    if (customer == null || widget.draft.lines.isEmpty) return;

    final requestSerial = ++_quoteRequestSerial;
    _noteReloadTimer?.cancel();
    if (mounted) {
      setState(() {
        _loading = true;
        _quoteFailed = false;
      });
    }

    try {
      var options = widget.draft.options;
      options ??= await widget.repository.options(customer);
      if (!mounted || requestSerial != _quoteRequestSerial) return;

      final currentCustomer = widget.draft.customer;
      if (currentCustomer == null ||
          currentCustomer.type != customer.type ||
          currentCustomer.id != customer.id) {
        return;
      }

      widget.draft.applyOptions(options);
      final snapshot = widget.draft.createQuoteSnapshot(note: _note.text);
      if (snapshot == null) throw StateError('No payment method.');

      final quote = await widget.repository.quote(
        customer: snapshot.customer,
        lines: snapshot.lines,
        paymentMethod: snapshot.paymentMethod,
        addressId: snapshot.addressId,
        warehouseId: snapshot.warehouseId,
        customerNote: snapshot.customerNote,
      );
      if (!mounted || requestSerial != _quoteRequestSerial) return;

      if (!widget.draft.setQuoteForRevision(snapshot.revision, quote)) {
        _scheduleReload(immediate: true);
        return;
      }
    } on VanSessionExpiredException {
      if (requestSerial == _quoteRequestSerial) {
        await widget.onSessionExpired();
      }
    } catch (_) {
      if (!mounted || requestSerial != _quoteRequestSerial) return;
      setState(() => _quoteFailed = true);
    } finally {
      if (mounted && requestSerial == _quoteRequestSerial) {
        setState(() => _loading = false);
      }
    }
  }

  void _scheduleReload({bool immediate = false}) {
    _noteReloadTimer?.cancel();
    _noteReloadTimer = Timer(
      immediate ? Duration.zero : const Duration(milliseconds: 300),
      _reload,
    );
  }

  String _idempotencyKeyFor(int revision) {
    if (_idempotencyRevision != revision || _idempotencyKey == null) {
      _idempotencyRevision = revision;
      _idempotencyKey =
          'van-order-${DateTime.now().microsecondsSinceEpoch}-r$revision';
    }
    return _idempotencyKey!;
  }

  Future<void> _submit() async {
    final revision = widget.draft.revision;
    final customer = widget.draft.customer;
    final payment = widget.draft.paymentMethod;
    final quote = widget.draft.quote;
    if (customer == null ||
        payment == null ||
        quote == null ||
        quote.hasUnavailableItems ||
        _loading ||
        _quoteFailed ||
        _submitting ||
        !widget.draft.ownsCurrentQuote(revision)) {
      return;
    }

    final lines = List<VanOrderLine>.unmodifiable(widget.draft.lines);
    final addressId = widget.draft.addressId;
    final warehouseId = widget.draft.warehouseId;
    final customerNote = widget.draft.customerNote;
    final idempotencyKey = _idempotencyKeyFor(revision);

    setState(() => _submitting = true);
    try {
      if (!widget.draft.ownsCurrentQuote(revision)) return;
      final order = await widget.repository.createOrder(
        customer: customer,
        lines: lines,
        paymentMethod: payment,
        addressId: addressId,
        warehouseId: warehouseId,
        customerNote: customerNote,
        idempotencyKey: idempotencyKey,
      );
      if (!mounted) return;
      widget.onSubmitted(order);
    } on VanSessionExpiredException {
      await widget.onSessionExpired();
    } catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            _text(
              'Unable to submit the order. Retry will reuse the same operation key.',
              'تعذر إرسال الطلب. إعادة المحاولة ستستخدم نفس مفتاح العملية.',
            ),
          ),
        ),
      );
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: widget.draft,
      builder: (context, _) {
        final customer = widget.draft.customer;
        final options = widget.draft.options;
        final quote = widget.draft.quote;
        return ListView(
          key: const ValueKey('van-order-review-page'),
          padding: const EdgeInsets.fromLTRB(12, 10, 12, 24),
          children: [
            if (customer != null)
              Card(
                elevation: 0,
                child: ListTile(
                  leading: const Icon(
                    Icons.storefront_outlined,
                    color: FoodexVanTokens.green,
                  ),
                  title: Text(
                    customer.name,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(fontWeight: FontWeight.w900),
                  ),
                  subtitle: Text(
                    _text(
                      '${widget.draft.lines.length} order items',
                      '${widget.draft.lines.length} أصناف',
                    ),
                  ),
                ),
              ),
            if (_loading)
              const Padding(
                padding: EdgeInsets.all(20),
                child: Center(child: CircularProgressIndicator()),
              ),
            if (_quoteFailed)
              Card(
                elevation: 0,
                child: Padding(
                  padding: const EdgeInsets.all(12),
                  child: Row(
                    children: [
                      Expanded(
                        child: Text(
                          _text(
                            'The latest order quote could not be refreshed. Retry before submitting.',
                            'تعذر تحديث أحدث تسعير للطلب. أعد المحاولة قبل الإرسال.',
                          ),
                        ),
                      ),
                      TextButton(
                        key: const ValueKey('van-order-quote-retry'),
                        onPressed: _loading ? null : _reload,
                        child: Text(_text('Retry', 'إعادة المحاولة')),
                      ),
                    ],
                  ),
                ),
              ),
            if (options != null) ...[
              const SizedBox(height: 10),
              if (options.warehouses.isNotEmpty)
                DropdownButtonFormField<int>(
                  initialValue: widget.draft.warehouseId,
                  decoration: InputDecoration(
                    labelText: _text('Warehouse', 'المخزن'),
                    border: const OutlineInputBorder(),
                  ),
                  items: [
                    for (final warehouse in options.warehouses)
                      DropdownMenuItem(
                        value: warehouse.id,
                        child: Text(
                          '${warehouse.name} · ${warehouse.code}',
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                        ),
                      ),
                  ],
                  onChanged: _submitting
                      ? null
                      : (value) {
                          widget.draft.setSelection(warehouse: value);
                          _reload();
                        },
                ),
              if (options.warehouses.isNotEmpty) const SizedBox(height: 10),
              if (options.addresses.isNotEmpty)
                DropdownButtonFormField<int>(
                  initialValue: widget.draft.addressId,
                  decoration: InputDecoration(
                    labelText: _text('Delivery address', 'عنوان التسليم'),
                    border: const OutlineInputBorder(),
                  ),
                  items: [
                    for (final address in options.addresses)
                      DropdownMenuItem(
                        value: address.id,
                        child: Text(
                          address.label.isEmpty
                              ? address.address
                              : '${address.label} · ${address.address}',
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                        ),
                      ),
                  ],
                  onChanged: _submitting
                      ? null
                      : (value) {
                          widget.draft.setSelection(address: value);
                          _reload();
                        },
                ),
              if (options.addresses.isNotEmpty) const SizedBox(height: 10),
              DropdownButtonFormField<String>(
                key: const ValueKey('van-order-payment-method'),
                initialValue: widget.draft.paymentMethod,
                decoration: InputDecoration(
                  labelText: _text('Payment method', 'طريقة الدفع'),
                  border: const OutlineInputBorder(),
                ),
                items: [
                  for (final method in options.paymentMethods)
                    DropdownMenuItem(
                      value: method,
                      child: Text(
                        vanPaymentMethodLabel(method, arabic: _arabic),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                ],
                onChanged: _submitting
                    ? null
                    : (value) {
                        widget.draft.setSelection(payment: value);
                        _reload();
                      },
              ),
              const SizedBox(height: 10),
              TextField(
                controller: _note,
                maxLines: 2,
                readOnly: _submitting,
                decoration: InputDecoration(
                  labelText: _text('Note (optional)', 'ملاحظة (اختياري)'),
                  border: const OutlineInputBorder(),
                ),
                onChanged: (value) {
                  widget.draft.setSelection(note: value);
                  _scheduleReload();
                },
                onEditingComplete: _reload,
              ),
            ],
            if (quote != null) ...[
              const SizedBox(height: 12),
              Card(
                elevation: 0,
                child: Padding(
                  padding: const EdgeInsets.all(14),
                  child: Column(
                    children: [
                      _row(
                        _text('Subtotal', 'الإجمالي قبل الخصم'),
                        quote.subtotal,
                        quote.currency,
                      ),
                      _row(
                        _text('Discount', 'الخصم'),
                        -quote.discountTotal,
                        quote.currency,
                      ),
                      _row(
                        _text('Delivery', 'التوصيل'),
                        quote.deliveryTotal,
                        quote.currency,
                      ),
                      _row(
                        _text('Tax', 'الضريبة'),
                        quote.taxTotal,
                        quote.currency,
                      ),
                      const Divider(),
                      _row(
                        _text('Total', 'الإجمالي'),
                        quote.grandTotal,
                        quote.currency,
                        strong: true,
                      ),
                    ],
                  ),
                ),
              ),
              if (quote.hasUnavailableItems)
                Padding(
                  padding: const EdgeInsets.only(top: 8),
                  child: Text(
                    _text(
                      'One or more items are unavailable. Refresh the cart before submitting.',
                      'صنف واحد أو أكثر غير متاح. حدّث السلة قبل الإرسال.',
                    ),
                    style: const TextStyle(color: Colors.red),
                  ),
                ),
            ],
            const SizedBox(height: 14),
            FilledButton.icon(
              key: const ValueKey('van-order-submit'),
              onPressed: _submitting ||
                      _loading ||
                      _quoteFailed ||
                      quote == null ||
                      quote.hasUnavailableItems ||
                      !widget.draft.hasCurrentQuote
                  ? null
                  : _submit,
              icon: _submitting
                  ? const SizedBox.square(
                      dimension: 18,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : const Icon(Icons.check_circle_outline),
              label: Text(_text('Submit order', 'تأكيد الطلب')),
            ),
          ],
        );
      },
    );
  }

  Widget _row(
    String label,
    double value,
    String currency, {
    bool strong = false,
  }) {
    return Row(
      children: [
        Expanded(child: Text(label)),
        Text(
          foodexMoneyText(value, currency),
          style: TextStyle(
            fontWeight: strong ? FontWeight.w900 : FontWeight.w700,
          ),
        ),
      ],
    );
  }
}
