import 'package:flutter/material.dart';

import '../../shared/customer_ui_v3/customer_ui_v3.dart';

/// Presentation only: callers supply the existing dashboard read model/actions.
class DashboardSingleScreen extends StatelessWidget {
  const DashboardSingleScreen({
    required this.hero,
    required this.finance,
    required this.monthly,
    required this.activity,
    required this.offers,
    super.key,
  });

  final Widget hero;
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
      final needsAccessibleLayout =
          constraints.maxHeight < 480 ||
          MediaQuery.sizeOf(context).width >
              MediaQuery.sizeOf(context).height ||
          accessible;
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
              flex: 29,
              child: hero,
            ),
            SizedBox(height: gap),
            Expanded(flex: 28, child: finance),
            SizedBox(height: gap),
            Expanded(
              flex: 33,
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
            Expanded(flex: 10, child: offers),
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
      padding: const EdgeInsets.all(6),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SizedBox(
            height: 24,
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
          const SizedBox(height: 2),
          Expanded(child: child),
        ],
      ),
    ),
  );
}

class DashboardMetricGrid extends StatelessWidget {
  const DashboardMetricGrid({
    required this.metrics,
    this.columns = 2,
    super.key,
  });
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
      for (var i = 0; i < metrics.length; i += columns) ...[
        if (i > 0) const SizedBox(height: 8),
        Expanded(child: _row(metrics.skip(i).take(columns).toList())),
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
      final dense = compact || constraints.maxHeight < 90;
      final inline =
          dense && constraints.maxHeight < 48 && !value.contains('.');
      final symbol = Container(
        width: dense ? 18 : 28,
        height: dense ? 18 : 28,
        decoration: BoxDecoration(
          color: CustomerUiColors.mint,
          borderRadius: BorderRadius.circular(10),
        ),
        child: Icon(
          icon,
          size: dense ? 16 : 21,
          color: CustomerUiColors.deepGreen,
        ),
      );
      final amount = Text(
        value,
        textDirection: TextDirection.ltr,
        maxLines: 1,
        style: Theme.of(context).textTheme.titleMedium?.copyWith(
          fontSize: dense ? 14 : 17,
          height: 1.2,
          fontWeight: FontWeight.w800,
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
            padding: EdgeInsets.all(dense ? 2 : 8),
            child: inline
                ? Row(
                    children: [
                      symbol,
                      const SizedBox(width: 4),
                      Expanded(
                        child: Text(
                          label,
                          maxLines: 2,
                          style: Theme.of(context).textTheme.bodySmall
                              ?.copyWith(
                                fontSize: 12,
                                height: 1.1,
                                color: CustomerUiColors.muted,
                              ),
                        ),
                      ),
                      const SizedBox(width: 4),
                      amount,
                    ],
                  )
                : Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      if (dense) ...[
                        amount,
                        const SizedBox(height: 2),
                        Row(
                          children: [
                            symbol,
                            const SizedBox(width: 4),
                            Expanded(
                              child: Text(
                                label,
                                maxLines: 2,
                                style: Theme.of(context).textTheme.bodySmall
                                    ?.copyWith(
                                      fontSize: 12,
                                      height: 1.15,
                                      color: CustomerUiColors.muted,
                                    ),
                              ),
                            ),
                          ],
                        ),
                      ] else ...[
                        symbol,
                        const SizedBox(height: 4),
                        amount,
                        const SizedBox(height: 3),
                        Text(
                          label,
                          maxLines: 2,
                          style: Theme.of(context).textTheme.bodySmall
                              ?.copyWith(
                                fontSize: 12,
                                height: 1.15,
                                color: CustomerUiColors.muted,
                              ),
                        ),
                      ],
                    ],
                  ),
          ),
        ),
      );
    },
  );
}
