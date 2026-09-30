import 'package:flutter/material.dart';

import 'customer_preview_bootstrap.dart';

class CustomerPreviewViewport extends StatelessWidget {
  const CustomerPreviewViewport({
    super.key,
    required this.bootstrap,
    required this.child,
  });

  final CustomerPreviewBootstrap bootstrap;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    final inherited = MediaQuery.maybeOf(context) ?? const MediaQueryData();
    final safeArea = EdgeInsets.fromLTRB(
      bootstrap.safeAreaLeft,
      bootstrap.safeAreaTop,
      bootstrap.safeAreaRight,
      bootstrap.safeAreaBottom,
    );

    return MediaQuery(
      data: inherited.copyWith(
        size: Size(
          bootstrap.deviceWidth.toDouble(),
          bootstrap.deviceHeight.toDouble(),
        ),
        padding: safeArea,
        viewPadding: safeArea,
        viewInsets: EdgeInsets.only(bottom: bootstrap.viewInsetBottom),
        textScaler: TextScaler.linear(bootstrap.textScale),
      ),
      child: child,
    );
  }
}
