import 'package:flutter/material.dart';

import '../../core/auth/van_session.dart';
import '../../core/theme/foodex_van_theme.dart';
import 'van_order_contract.dart';

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
  late final String _idempotencyKey;

  bool get _arabic => Localizations.localeOf(context).languageCode == 'ar';
  String _text(String en, String ar) => _arabic ? ar : en;

  @override
  void initState() {
    super.initState();
    _note.text = widget.draft.customerNote ?? '';
    _idempotencyKey =
        'van-order-${DateTime.now().microsecondsSinceEpoch}-review';
    if (widget.draft.options == null || widget.draft.quote == null) {
      _reload();
    }
  }

  @override
  void dispose() {
    _note.dispose();
    super.dispose();
  }

  Future<void> _reload() async {
    final customer = widget.draft.customer;
    if (customer == null || widget.draft.lines.isEmpty || _loading) return;
    setState(() => _loading = true);
    try {
      var options = widget.draft.options;
      options ??= await widget.repository.options(customer);
      widget.draft.applyOptions(options);
      final payment = widget.draft.paymentMethod;
      if (payment == null) throw StateError('No payment method.');
      final quote = await widget.repository.quote(
        customer: customer,
        lines: widget.draft.lines,
        paymentMethod: payment,
        addressId: widget.draft.addressId,
        warehouseId: widget.draft.warehouseId,
        customerNote: _note.text,
      );
      widget.draft.setSelection(note: _note.text);
      widget.draft.setQuote(quote);
    } on VanSessionExpiredException {
      await widget.onSessionExpired();
    } catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            _text('Unable to refresh order quote.', 'تعذر تحديث تسعير الطلب.'),
          ),
        ),
      );
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _submit() async {
    final customer = widget.draft.customer;
    final payment = widget.draft.paymentMethod;
    final quote = widget.draft.quote;
    if (customer == null ||
        payment == null ||
        quote == null ||
        quote.hasUnavailableItems ||
        _submitting) {
      return;
    }

    setState(() => _submitting = true);
    try {
      final order = await widget.repository.createOrder(
        customer: customer,
        lines: widget.draft.lines,
        paymentMethod: payment,
        addressId: widget.draft.addressId,
        warehouseId: widget.draft.warehouseId,
        customerNote: _note.text,
        idempotencyKey: _idempotencyKey,
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
            if (options != null) ...[
              const SizedBox(height: 10),
              if (options.warehouses.isNotEmpty)
                DropdownButtonFormField<int>(
                  value: widget.draft.warehouseId,
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
                  onChanged: (value) {
                    widget.draft.setSelection(warehouse: value);
                    _reload();
                  },
                ),
              if (options.warehouses.isNotEmpty) const SizedBox(height: 10),
              if (options.addresses.isNotEmpty)
                DropdownButtonFormField<int>(
                  value: widget.draft.addressId,
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
                  onChanged: (value) {
                    widget.draft.setSelection(address: value);
                    _reload();
                  },
                ),
              if (options.addresses.isNotEmpty) const SizedBox(height: 10),
              DropdownButtonFormField<String>(
                key: const ValueKey('van-order-payment-method'),
                value: widget.draft.paymentMethod,
                decoration: InputDecoration(
                  labelText: _text('Payment method', 'طريقة الدفع'),
                  border: const OutlineInputBorder(),
                ),
                items: [
                  for (final method in options.paymentMethods)
                    DropdownMenuItem(
                      value: method,
                      child: Text(method.replaceAll('_', ' ')),
                    ),
                ],
                onChanged: (value) {
                  widget.draft.setSelection(payment: value);
                  _reload();
                },
              ),
              const SizedBox(height: 10),
              TextField(
                controller: _note,
                maxLines: 2,
                decoration: InputDecoration(
                  labelText: _text('Note (optional)', 'ملاحظة (اختياري)'),
                  border: const OutlineInputBorder(),
                ),
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
                      _row(_text('Subtotal', 'الإجمالي قبل الخصم'),
                          quote.subtotal, quote.currency),
                      _row(_text('Discount', 'الخصم'), -quote.discountTotal,
                          quote.currency),
                      _row(_text('Delivery', 'التوصيل'), quote.deliveryTotal,
                          quote.currency),
                      _row(_text('Tax', 'الضريبة'), quote.taxTotal,
                          quote.currency),
                      const Divider(),
                      _row(_text('Total', 'الإجمالي'), quote.grandTotal,
                          quote.currency,
                          strong: true),
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
                      quote == null ||
                      quote.hasUnavailableItems
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

  Widget _row(String label, double value, String currency,
          {bool strong = false}) =>
      Row(
        children: [
          Expanded(child: Text(label)),
          Text(
            '${value.toStringAsFixed(3)} $currency',
            style: TextStyle(
              fontWeight: strong ? FontWeight.w900 : FontWeight.w700,
            ),
          ),
        ],
      );
}
