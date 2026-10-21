import 'package:flutter/material.dart';

import '../../shared/customer_ui_v3/customer_ui_v3.dart';

@immutable
class CustomerCheckoutStep {
  const CustomerCheckoutStep({
    required this.label,
    required this.icon,
  });

  final String label;
  final IconData icon;
}

class CustomerCheckoutProgress extends StatelessWidget {
  const CustomerCheckoutProgress({
    required this.steps,
    super.key,
  });

  final List<CustomerCheckoutStep> steps;

  @override
  Widget build(BuildContext context) => Semantics(
        container: true,
        child: SingleChildScrollView(
          scrollDirection: Axis.horizontal,
          child: Row(
            children: [
              for (var index = 0; index < steps.length; index++) ...[
                if (index > 0)
                  Container(
                    width: 22,
                    height: CustomerUiStroke.hairline,
                    color: CustomerUiColors.border,
                  ),
                _CheckoutProgressStep(step: steps[index]),
              ],
            ],
          ),
        ),
      );
}

class _CheckoutProgressStep extends StatelessWidget {
  const _CheckoutProgressStep({required this.step});

  final CustomerCheckoutStep step;

  @override
  Widget build(BuildContext context) => Container(
        constraints: const BoxConstraints(minWidth: 92),
        padding: const EdgeInsetsDirectional.fromSTEB(
          CustomerUiSpacing.xs,
          CustomerUiSpacing.xs,
          CustomerUiSpacing.xs,
          CustomerUiSpacing.xs,
        ),
        decoration: BoxDecoration(
          color: CustomerUiColors.white,
          borderRadius: BorderRadius.circular(CustomerUiRadii.lg),
          border: Border.all(color: CustomerUiColors.border),
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            DecoratedBox(
              decoration: const BoxDecoration(
                color: CustomerUiColors.limeSoft,
                shape: BoxShape.circle,
              ),
              child: SizedBox.square(
                dimension: 30,
                child: Center(
                  child: Icon(
                    step.icon,
                    size: 17,
                    color: CustomerUiColors.deepGreenStrong,
                  ),
                ),
              ),
            ),
            const SizedBox(width: CustomerUiSpacing.xs),
            Flexible(
              child: Text(
                step.label,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: Theme.of(context).textTheme.labelMedium?.copyWith(
                      color: CustomerUiColors.inkSoft,
                      fontWeight: FontWeight.w800,
                    ),
              ),
            ),
          ],
        ),
      );
}

class CustomerQuantityStepper extends StatelessWidget {
  const CustomerQuantityStepper({
    required this.valueText,
    required this.onDecrease,
    required this.onIncrease,
    this.decreaseKey,
    this.increaseKey,
    this.busy = false,
    this.iconSize = 24,
    super.key,
  });

  final String valueText;
  final VoidCallback? onDecrease;
  final VoidCallback? onIncrease;
  final Key? decreaseKey;
  final Key? increaseKey;
  final bool busy;
  final double iconSize;

  @override
  Widget build(BuildContext context) => Semantics(
        container: true,
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            CustomerOutlineIconButton(
              key: decreaseKey,
              icon: Icons.remove_rounded,
              iconSize: iconSize,
              onPressed: busy ? null : onDecrease,
            ),
            ConstrainedBox(
              constraints: const BoxConstraints(minWidth: 44),
              child: Padding(
                padding: const EdgeInsets.symmetric(
                  horizontal: CustomerUiSpacing.xs,
                ),
                child: Text(
                  valueText,
                  textAlign: TextAlign.center,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: Theme.of(context).textTheme.titleMedium?.copyWith(
                        fontWeight: FontWeight.w800,
                      ),
                ),
              ),
            ),
            CustomerOutlineIconButton(
              key: increaseKey,
              icon: Icons.add_rounded,
              iconSize: iconSize,
              selected: true,
              onPressed: busy ? null : onIncrease,
            ),
          ],
        ),
      );
}

@immutable
class CustomerCheckoutTotalRow {
  const CustomerCheckoutTotalRow({
    required this.label,
    required this.value,
    this.emphasized = false,
    this.valueColor,
  });

  final String label;
  final String value;
  final bool emphasized;
  final Color? valueColor;
}

class CustomerCheckoutTotalsCard extends StatelessWidget {
  const CustomerCheckoutTotalsCard({
    required this.rows,
    super.key,
  });

  final List<CustomerCheckoutTotalRow> rows;

  @override
  Widget build(BuildContext context) => DecoratedBox(
        decoration: BoxDecoration(
          color: CustomerUiColors.white,
          borderRadius: BorderRadius.circular(CustomerUiRadii.xl),
          border: Border.all(color: CustomerUiColors.border),
        ),
        child: Padding(
          padding: const EdgeInsets.all(CustomerUiSpacing.lg),
          child: Column(
            children: [
              for (var index = 0; index < rows.length; index++) ...[
                if (index > 0) const SizedBox(height: CustomerUiSpacing.xs),
                Wrap(
                  alignment: WrapAlignment.spaceBetween,
                  crossAxisAlignment: WrapCrossAlignment.center,
                  spacing: CustomerUiSpacing.sm,
                  runSpacing: CustomerUiSpacing.xxs,
                  children: [
                    Text(
                      rows[index].label,
                      style: rows[index].emphasized
                          ? Theme.of(context).textTheme.titleMedium?.copyWith(
                                fontWeight: FontWeight.w900,
                              )
                          : Theme.of(context).textTheme.bodyMedium,
                    ),
                    Directionality(
                      textDirection: TextDirection.ltr,
                      child: Text(
                        rows[index].value,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: rows[index].emphasized
                            ? Theme.of(context).textTheme.titleLarge?.copyWith(
                                  color: rows[index].valueColor ??
                                      CustomerUiColors.deepGreenStrong,
                                  fontWeight: FontWeight.w900,
                                )
                            : Theme.of(context).textTheme.bodyMedium?.copyWith(
                                  color: rows[index].valueColor ??
                                      CustomerUiColors.inkSoft,
                                  fontWeight: FontWeight.w700,
                                ),
                      ),
                    ),
                  ],
                ),
              ],
            ],
          ),
        ),
      );
}

class CustomerOrderSuccessPanel extends StatelessWidget {
  const CustomerOrderSuccessPanel({
    required this.title,
    required this.message,
    required this.orderReferenceLabel,
    required this.orderReference,
    required this.trackLabel,
    required this.continueLabel,
    required this.onTrack,
    required this.onContinue,
    this.summary,
    super.key,
  });

  final String title;
  final String message;
  final String orderReferenceLabel;
  final String orderReference;
  final String trackLabel;
  final String continueLabel;
  final VoidCallback onTrack;
  final VoidCallback onContinue;
  final Widget? summary;

  @override
  Widget build(BuildContext context) => ListView(
        key: const ValueKey('customer-order-success'),
        padding: const EdgeInsets.fromLTRB(
          CustomerUiSpacing.page,
          CustomerUiSpacing.xxl,
          CustomerUiSpacing.page,
          CustomerUiSpacing.xxl,
        ),
        children: [
          Center(
            child: DecoratedBox(
              decoration: const BoxDecoration(
                color: CustomerUiColors.limeSoft,
                shape: BoxShape.circle,
              ),
              child: const SizedBox.square(
                dimension: 88,
                child: Icon(
                  Icons.check_rounded,
                  size: 48,
                  color: CustomerUiColors.success,
                ),
              ),
            ),
          ),
          const SizedBox(height: CustomerUiSpacing.lg),
          Text(
            title,
            textAlign: TextAlign.center,
            style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                  color: CustomerUiColors.deepGreenStrong,
                  fontWeight: FontWeight.w900,
                ),
          ),
          const SizedBox(height: CustomerUiSpacing.xs),
          Text(
            message,
            textAlign: TextAlign.center,
            style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                  color: CustomerUiColors.muted,
                ),
          ),
          const SizedBox(height: CustomerUiSpacing.lg),
          DecoratedBox(
            decoration: BoxDecoration(
              color: CustomerUiColors.white,
              borderRadius: BorderRadius.circular(CustomerUiRadii.xl),
              border: Border.all(color: CustomerUiColors.border),
            ),
            child: Padding(
              padding: const EdgeInsets.all(CustomerUiSpacing.lg),
              child: Column(
                children: [
                  Text(
                    orderReferenceLabel,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: Theme.of(context).textTheme.labelLarge?.copyWith(
                          color: CustomerUiColors.muted,
                        ),
                  ),
                  const SizedBox(height: CustomerUiSpacing.xxs),
                  Directionality(
                    textDirection: TextDirection.ltr,
                    child: SelectableText(
                      orderReference,
                      maxLines: 1,
                      textAlign: TextAlign.center,
                      style: Theme.of(context).textTheme.titleLarge?.copyWith(
                            color: CustomerUiColors.deepGreenStrong,
                            fontWeight: FontWeight.w900,
                          ),
                    ),
                  ),
                ],
              ),
            ),
          ),
          if (summary != null) ...[
            const SizedBox(height: CustomerUiSpacing.md),
            summary!,
          ],
          const SizedBox(height: CustomerUiSpacing.lg),
          FilledButton.icon(
            key: const ValueKey('customer-order-success-track'),
            onPressed: onTrack,
            icon: const Icon(Icons.local_shipping_outlined),
            label: Text(trackLabel),
          ),
          const SizedBox(height: CustomerUiSpacing.sm),
          OutlinedButton.icon(
            key: const ValueKey('customer-order-success-continue'),
            onPressed: onContinue,
            icon: const Icon(Icons.storefront_outlined),
            label: Text(continueLabel),
          ),
        ],
      );
}
