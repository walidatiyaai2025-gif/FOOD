import 'package:flutter/material.dart';

import '../core/theme/foodex_van_theme.dart';

enum VanActionVariant { primary, secondary }

class VanActionButton extends StatelessWidget {
  const VanActionButton({
    super.key,
    required this.onPressed,
    required this.child,
    this.variant = VanActionVariant.primary,
    this.style,
  })  : icon = null,
        label = null;

  const VanActionButton.icon({
    super.key,
    required this.onPressed,
    required Widget this.icon,
    required Widget this.label,
    this.variant = VanActionVariant.primary,
    this.style,
  }) : child = null;

  const VanActionButton.secondary({
    super.key,
    required this.onPressed,
    required this.child,
    this.style,
  })  : variant = VanActionVariant.secondary,
        icon = null,
        label = null;

  const VanActionButton.secondaryIcon({
    super.key,
    required this.onPressed,
    required Widget this.icon,
    required Widget this.label,
    this.style,
  })  : variant = VanActionVariant.secondary,
        child = null;

  final VoidCallback? onPressed;
  final Widget? child;
  final Widget? icon;
  final Widget? label;
  final VanActionVariant variant;
  final ButtonStyle? style;

  @override
  Widget build(BuildContext context) {
    final secondary = variant == VanActionVariant.secondary;
    final resolvedStyle = FilledButton.styleFrom(
      backgroundColor: secondary ? Colors.transparent : FoodexVanTokens.green,
      foregroundColor:
          secondary ? FoodexVanTokens.green : Colors.white,
      side: secondary
          ? const BorderSide(color: FoodexVanTokens.border)
          : BorderSide.none,
      elevation: 0,
    ).merge(style);

    final content = child ??
        Row(
          mainAxisSize: MainAxisSize.min,
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            if (icon != null) ...[
              icon!,
              const SizedBox(width: 8),
            ],
            if (label != null) label!,
          ],
        );

    return FilledButton(
      onPressed: onPressed,
      style: resolvedStyle,
      child: content,
    );
  }
}

class VanIconAction extends StatelessWidget {
  const VanIconAction({
    super.key,
    required this.onPressed,
    required this.icon,
    this.tooltip,
  });

  final VoidCallback? onPressed;
  final Widget icon;
  final String? tooltip;

  @override
  Widget build(BuildContext context) {
    return IconButton(
      onPressed: onPressed,
      icon: icon,
      tooltip: tooltip,
    );
  }
}
