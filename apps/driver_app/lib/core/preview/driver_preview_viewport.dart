import 'package:flutter/material.dart';

import 'driver_preview_bootstrap.dart';

class DriverPreviewViewport extends StatelessWidget {
  const DriverPreviewViewport({
    super.key,
    required this.bootstrap,
    required this.child,
  });

  final DriverPreviewBootstrap bootstrap;
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
