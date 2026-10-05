import 'package:flutter/material.dart';

import '../../shared/customer_ui_v3/customer_ui_v3.dart';

/// Presentation only: callers supply the existing dashboard read model/actions.
class DashboardSingleScreen extends StatelessWidget {
  const DashboardSingleScreen({
    required this.identity,
    required this.balance,
    required this.finance,
    required this.monthly,
    required this.activity,
    required this.offers,
    super.key,
  });

  final Widget identity;
  final Widget balance;
  final Widget finance;
  final Widget monthly;
  final Widget activity;
  final Widget offers;

  @override
  Widget build(BuildContext context) => LayoutBuilder(
    builder: (context, constraints) {
      // Ordinary portrait phones use a bounded Column. Scrolling is reserved
      // for landscape/short windows or large accessibility text, where
      // preserving readable content takes precedence over the trial layout.
      final accessible = MediaQuery.textScalerOf(context).scale(14) > 19;
      final needsAccessibleLayout = constraints.maxHeight < 460 || accessible;
      final height = needsAccessibleLayout
          ? (accessible ? 1050.0 : 700.0)
          : constraints.maxHeight;
      final gap = height < 620 ? 8.0 : 10.0;
      final content = SizedBox(
        height: height,
        child: Column(
          key: const ValueKey('b2b-dashboard-data'),
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Expanded(
              flex: 25,
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Expanded(flex: 43, child: balance),
                  SizedBox(width: gap),
                  Expanded(flex: 57, child: identity),
                ],
              ),
            ),
            SizedBox(height: gap),
            Expanded(flex: 32, child: finance),
            SizedBox(height: gap),
            Expanded(
              flex: 32,
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Expanded(child: monthly),
                  SizedBox(width: gap),
                  Expanded(child: activity),
                ],
              ),
            ),
            SizedBox(height: gap),
            Expanded(flex: 11, child: offers),
          ],
        ),
      );
      return needsAccessibleLayout
          ? SingleChildScrollView(child: content)
          : content;
    },
  );
}

class DashboardPanel extends StatelessWidget {
  const DashboardPanel({
    required this.title,
    required this.icon,
    required this.child,
    super.key,
  });
  final String title;
  final IconData icon;
  final Widget child;

  @override
  Widget build(BuildContext context) => DecoratedBox(
    decoration: BoxDecoration(
      color: CustomerUiColors.mintStrong.withValues(alpha: .22),
      borderRadius: BorderRadius.circular(18),
    ),
    child: Padding(
      padding: const EdgeInsets.all(8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SizedBox(
            height: 34,
            child: Row(
              children: [
                Icon(icon, size: 22, color: CustomerUiColors.deepGreen),
                const SizedBox(width: 5),
                Expanded(
                  child: Text(
                    title,
                    maxLines: 2,
                    style: Theme.of(context).textTheme.titleSmall?.copyWith(
                      fontSize: 14,
                      height: 1.15,
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 4),
          Expanded(child: child),
        ],
      ),
    ),
  );
}

class DashboardMetricGrid extends StatelessWidget {
  const DashboardMetricGrid({required this.metrics, this.columns = 2, super.key});
  final List<Widget> metrics;
  final int columns;

  Widget _row(List<Widget> cells) => Row(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      for (var i = 0; i < cells.length; i++) ...[
        if (i > 0) const SizedBox(width: 8),
        Expanded(child: cells[i]),
      ],
    ],
  );

  @override
  Widget build(BuildContext context) => Column(
    children: [
      Expanded(child: _row(metrics.take(columns).toList())),
      if (metrics.length > columns) ...[
        const SizedBox(height: 8),
        Expanded(child: _row(metrics.skip(columns).toList())),
      ],
    ],
  );
}

class DashboardMetricCard extends StatelessWidget {
  const DashboardMetricCard({
    required this.label,
    required this.value,
    required this.icon,
    required this.onTap,
    this.compact = false,
    super.key,
  });
  final String label;
  final String value;
  final IconData icon;
  final VoidCallback onTap;
  final bool compact;

  @override
  Widget build(BuildContext context) => LayoutBuilder(
    builder: (context, constraints) {
      final tight = constraints.maxHeight < 56;
      final symbolSize = tight ? 18.0 : (compact ? 24.0 : 28.0);
      final iconSize = tight ? 14.0 : (compact ? 18.0 : 21.0);
      final amountSize = tight ? 11.0 : (compact ? 14.0 : 17.0);
      final labelSize = tight ? 9.0 : 12.0;

      final symbol = Container(
        width: symbolSize,
        height: symbolSize,
        decoration: BoxDecoration(
          color: CustomerUiColors.mint,
          borderRadius: BorderRadius.circular(tight ? 7 : 10),
        ),
        child: Icon(
          icon,
          size: iconSize,
          color: CustomerUiColors.deepGreen,
        ),
      );
      final amount = Text(
        value,
        textDirection: TextDirection.ltr,
        maxLines: 1,
        overflow: TextOverflow.ellipsis,
        style: Theme.of(context).textTheme.titleMedium?.copyWith(
          fontSize: amountSize,
          height: 1,
          fontWeight: FontWeight.w800,
        ),
      );
      final metricLabel = Text(
        label,
        maxLines: tight ? 1 : 2,
        overflow: TextOverflow.ellipsis,
        style: Theme.of(context).textTheme.bodySmall?.copyWith(
          fontSize: labelSize,
          height: 1,
          color: CustomerUiColors.muted,
        ),
      );

      return Material(
        color: CustomerUiColors.white,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(14),
          side: const BorderSide(color: CustomerUiColors.border, width: .5),
        ),
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: onTap,
          child: Padding(
            padding: tight
                ? const EdgeInsets.symmetric(horizontal: 5, vertical: 2)
                : EdgeInsets.all(compact ? 6 : 8),
            child: tight
                ? Row(
                    children: [
                      symbol,
                      const SizedBox(width: 4),
                      Expanded(
                        child: Column(
                          mainAxisSize: MainAxisSize.min,
                          crossAxisAlignment: CrossAxisAlignment.start,
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            amount,
                            const SizedBox(height: 1),
                            metricLabel,
                          ],
                        ),
                      ),
                    ],
                  )
                : Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      if (compact)
                        Row(
                          children: [
                            symbol,
                            const SizedBox(width: 4),
                            Expanded(
                              child: Align(
                                alignment: AlignmentDirectional.centerStart,
                                child: amount,
                              ),
                            ),
                          ],
                        )
                      else ...[
                        symbol,
                        const SizedBox(height: 4),
                        amount,
                      ],
                      const SizedBox(height: 3),
                      metricLabel,
                    ],
                  ),
          ),
        ),
      );
    },
  );
}
