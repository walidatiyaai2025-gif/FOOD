import 'package:flutter/material.dart';

import '../../core/theme/customer_ui_v3_tokens.dart';

abstract final class CustomerUiBreakpoints {
  static const double minimumPhone = 320;
  static const double compactPhone = 360;
  static const double standardPhone = 390;
  static const double largePhone = 430;
  static const double tablet = 768;

  static const int productColumns = 3;
}

class CustomerProductGrid extends StatelessWidget {
  const CustomerProductGrid({
    required this.itemCount,
    required this.itemBuilder,
    this.cardExtent = 258,
    this.padding = EdgeInsets.zero,
    this.physics = const NeverScrollableScrollPhysics(),
    this.shrinkWrap = true,
    super.key,
  });

  final int itemCount;
  final IndexedWidgetBuilder itemBuilder;
  final double cardExtent;
  final EdgeInsetsGeometry padding;
  final ScrollPhysics physics;
  final bool shrinkWrap;

  @override
  Widget build(BuildContext context) {
    final textScale = MediaQuery.textScalerOf(context).scale(1);
    final extraExtent = ((textScale - 1).clamp(0, 1) * 72).toDouble();

    return GridView.builder(
      padding: padding,
      shrinkWrap: shrinkWrap,
      physics: physics,
      itemCount: itemCount,
      gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
        crossAxisCount: CustomerUiBreakpoints.productColumns,
        crossAxisSpacing: CustomerUiSpacing.xs,
        mainAxisSpacing: CustomerUiSpacing.sm,
        mainAxisExtent: cardExtent + extraExtent,
      ),
      itemBuilder: itemBuilder,
    );
  }
}
