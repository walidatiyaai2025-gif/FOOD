import 'package:flutter/material.dart';

import '../../core/auth/van_session.dart';
import '../../core/theme/foodex_van_theme.dart';
import 'van_order_contract.dart';

class VanOrderBuilderPage extends StatefulWidget {
  const VanOrderBuilderPage({
    super.key,
    required this.repository,
    required this.draft,
    required this.onReview,
    required this.onSessionExpired,
  });

  final VanOrderRepository repository;
  final VanOrderDraftController draft;
  final VoidCallback onReview;
  final Future<void> Function() onSessionExpired;

  @override
  State<VanOrderBuilderPage> createState() => _VanOrderBuilderPageState();
}

class _VanOrderBuilderPageState extends State<VanOrderBuilderPage> {
  bool _quoting = false;

  bool get _arabic => Localizations.localeOf(context).languageCode == 'ar';
  String _text(String en, String ar) => _arabic ? ar : en;

  Future<void> _review() async {
    final customer = widget.draft.customer;
    if (customer == null || widget.draft.lines.isEmpty || _quoting) return;
    setState(() => _quoting = true);
    try {
      var options = widget.draft.options;
      options ??= await widget.repository.options(customer);
      widget.draft.applyOptions(options);
      final payment = widget.draft.paymentMethod;
      if (payment == null) {
        throw StateError('No configured payment method.');
      }
      final quote = await widget.repository.quote(
        customer: customer,
        lines: widget.draft.lines,
        paymentMethod: payment,
        addressId: widget.draft.addressId,
        warehouseId: widget.draft.warehouseId,
        customerNote: widget.draft.customerNote,
      );
      widget.draft.setQuote(quote);
      if (!mounted) return;
      widget.onReview();
    } on VanSessionExpiredException {
      await widget.onSessionExpired();
    } catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(context)
        ..hideCurrentSnackBar()
        ..showSnackBar(
          SnackBar(
            content: Text(
              _text(
                'Unable to prepare an authoritative order quote.',
                'تعذر تجهيز تسعير معتمد للطلب.',
              ),
            ),
          ),
        );
    } finally {
      if (mounted) setState(() => _quoting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: widget.draft,
      builder: (context, _) {
        final customer = widget.draft.customer;
        final lines = widget.draft.lines;
        final quote = widget.draft.quote;
        return ListView(
          key: const ValueKey('van-order-builder-page'),
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
                  subtitle: Text(customer.type.toUpperCase()),
                ),
              ),
            const SizedBox(height: 8),
            if (lines.isEmpty)
              Card(
                elevation: 0,
                child: Padding(
                  padding: const EdgeInsets.all(18),
                  child: Text(
                    _text(
                      'Add products from Product Catalog first.',
                      'أضف منتجات من كتالوج المنتجات أولًا.',
                    ),
                    textAlign: TextAlign.center,
                  ),
                ),
              )
            else
              for (final line in lines)
                Card(
                  key: ValueKey('van-order-line-${line.product.id}'),
                  elevation: 0,
                  margin: const EdgeInsets.only(bottom: 8),
                  child: Padding(
                    padding: const EdgeInsets.all(12),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          line.product.name,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(fontWeight: FontWeight.w900),
                        ),
                        const SizedBox(height: 8),
                        Row(
                          children: [
                            Text(
                              '${line.product.unitPrice.toStringAsFixed(3)} '
                              '${line.product.currency}',
                            ),
                            const Spacer(),
                            IconButton.filledTonal(
                              onPressed: () => widget.draft.changeQuantity(
                                line.product,
                                line.quantity - line.product.orderingIncrement,
                              ),
                              icon: const Icon(Icons.remove, size: 18),
                            ),
                            SizedBox(
                              width: 62,
                              child: Text(
                                line.quantity.toStringAsFixed(
                                  line.quantity == line.quantity.roundToDouble()
                                      ? 0
                                      : 3,
                                ),
                                textAlign: TextAlign.center,
                                style:
                                    const TextStyle(fontWeight: FontWeight.w900),
                              ),
                            ),
                            IconButton.filledTonal(
                              onPressed: () => widget.draft.changeQuantity(
                                line.product,
                                line.quantity + line.product.orderingIncrement,
                              ),
                              icon: const Icon(Icons.add, size: 18),
                            ),
                          ],
                        ),
                      ],
                    ),
                  ),
                ),
            if (quote != null) ...[
              const SizedBox(height: 4),
              _Totals(quote: quote, arabic: _arabic),
            ],
            const SizedBox(height: 12),
            FilledButton.icon(
              key: const ValueKey('van-order-builder-review'),
              onPressed: lines.isEmpty || _quoting ? null : _review,
              icon: _quoting
                  ? const SizedBox.square(
                      dimension: 18,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : const Icon(Icons.arrow_forward),
              label: Text(_text('Review order', 'مراجعة الطلب')),
            ),
          ],
        );
      },
    );
  }
}

class _Totals extends StatelessWidget {
  const _Totals({required this.quote, required this.arabic});
  final VanOrderQuote quote;
  final bool arabic;

  @override
  Widget build(BuildContext context) => Card(
        elevation: 0,
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(
            children: [
              _row(arabic ? 'الإجمالي قبل الخصم' : 'Subtotal', quote.subtotal),
              _row(arabic ? 'الخصم' : 'Discount', -quote.discountTotal),
              _row(arabic ? 'التوصيل' : 'Delivery', quote.deliveryTotal),
              _row(arabic ? 'الضريبة' : 'Tax', quote.taxTotal),
              const Divider(),
              _row(arabic ? 'الإجمالي' : 'Total', quote.grandTotal, strong: true),
            ],
          ),
        ),
      );

  Widget _row(String label, double value, {bool strong = false}) => Row(
        children: [
          Expanded(child: Text(label)),
          Text(
            '${value.toStringAsFixed(3)} ${quote.currency}',
            style: TextStyle(
              fontWeight: strong ? FontWeight.w900 : FontWeight.w700,
            ),
          ),
        ],
      );
}
