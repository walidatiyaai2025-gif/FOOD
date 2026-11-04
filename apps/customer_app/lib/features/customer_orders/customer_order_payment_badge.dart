import 'package:flutter/material.dart';
import 'package:foodex_formatting/foodex_formatting.dart';

import '../../core/theme/customer_ui_v3_tokens.dart';

/// Financial settlement only; this does not represent delivery/order status.
/// Missing authoritative settlement data is deliberately not represented as paid.
class CustomerOrderPaymentBadge extends StatelessWidget {
  const CustomerOrderPaymentBadge({
    required this.orderId,
    required this.currency,
    required this.paymentState,
    this.paidAmount,
    this.remainingAmount,
    this.compact = false,
    super.key,
  });

  final int orderId;
  final String currency;
  final String? paymentState;
  final double? paidAmount;
  final double? remainingAmount;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final state = paymentState?.trim().toLowerCase();
    if (state != 'fully_settled' && state != 'partially_settled' &&
        state != 'unpaid') {
      return const SizedBox.shrink();
    }
    final ar = Localizations.localeOf(context).languageCode == 'ar';
    final fully = state == 'fully_settled';
    final partial = state == 'partially_settled';
    final green = CustomerUiColors.success;
    final red = CustomerUiColors.destructive;
    final label = fully
        ? (ar ? 'مدفوع بالكامل' : 'Fully paid')
        : partial
            ? (ar ? 'مدفوع جزئياً' : 'Partially paid')
            : (ar ? 'غير مدفوع' : 'Unpaid');

    Widget amountLine(String key, String title, double amount, Color color) {
      return Semantics(
        label: '$title: ${foodexMoneyText(amount, currency)}',
        child: Text(
          '$title: ${foodexMoneyText(amount, currency)}',
          key: ValueKey('customer-order-$key-$orderId'),
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: TextStyle(
            color: color,
            fontSize: compact ? 10.5 : 12,
            fontWeight: FontWeight.w800,
          ),
        ),
      );
    }

    return Column(
      key: ValueKey('customer-order-payment-badge-$orderId'),
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: [
        Text(
          label,
          key: ValueKey('customer-order-payment-label-$orderId'),
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: TextStyle(
            color: fully ? green : red,
            fontSize: compact ? 10.5 : 12,
            fontWeight: FontWeight.w800,
          ),
        ),
        if (paidAmount != null)
          amountLine(
            'paid',
            ar ? 'المدفوع' : 'Paid',
            paidAmount!,
            green,
          ),
        if (!fully && remainingAmount != null && remainingAmount! > 0.0001)
          amountLine(
            'remaining',
            ar ? 'المتبقي' : 'Remaining',
            remainingAmount!,
            red,
          ),
      ],
    );
  }
}
