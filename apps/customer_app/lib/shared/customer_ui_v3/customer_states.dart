import 'package:flutter/material.dart';

import '../../core/theme/customer_ui_v3_tokens.dart';

class CustomerSkeletonBox extends StatelessWidget {
  const CustomerSkeletonBox({
    required this.height,
    this.width,
    this.radius = CustomerUiRadii.md,
    super.key,
  });

  final double height;
  final double? width;
  final double radius;

  @override
  Widget build(BuildContext context) => AnimatedContainer(
        duration: CustomerUiMotion.resolve(
          context,
          CustomerUiMotion.standard,
        ),
        curve: CustomerUiMotion.standardCurve,
        width: width,
        height: height,
        decoration: BoxDecoration(
          color: CustomerUiColors.mintStrong.withValues(alpha: 0.58),
          borderRadius: BorderRadius.circular(radius),
        ),
      );
}

class CustomerCategorySkeleton extends StatelessWidget {
  const CustomerCategorySkeleton({super.key});

  @override
  Widget build(BuildContext context) => const SizedBox(
        width: 84,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            CustomerSkeletonBox(height: 64, width: 64, radius: 32),
            SizedBox(height: CustomerUiSpacing.xs),
            CustomerSkeletonBox(height: 12, width: 62, radius: 6),
          ],
        ),
      );
}

class CustomerProductCardSkeleton extends StatelessWidget {
  const CustomerProductCardSkeleton({super.key});

  @override
  Widget build(BuildContext context) => DecoratedBox(
        decoration: BoxDecoration(
          color: CustomerUiColors.white,
          borderRadius: BorderRadius.circular(CustomerUiRadii.xl),
          border: Border.all(color: CustomerUiColors.border),
        ),
        child: const Padding(
          padding: EdgeInsets.all(CustomerUiSpacing.sm),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              AspectRatio(
                aspectRatio: 1.35,
                child: CustomerSkeletonBox(
                  height: 180,
                  radius: CustomerUiRadii.lg,
                ),
              ),
              SizedBox(height: CustomerUiSpacing.sm),
              CustomerSkeletonBox(height: 18, width: 90, radius: 9),
              SizedBox(height: CustomerUiSpacing.xs),
              CustomerSkeletonBox(height: 18, radius: 9),
              SizedBox(height: CustomerUiSpacing.xs),
              CustomerSkeletonBox(height: 18, width: 150, radius: 9),
              SizedBox(height: CustomerUiSpacing.md),
              CustomerSkeletonBox(height: 24, width: 110, radius: 12),
            ],
          ),
        ),
      );
}

enum CustomerStateKind { empty, error, success }

class CustomerStateView extends StatelessWidget {
  const CustomerStateView({
    required this.kind,
    required this.title,
    this.message,
    this.actionLabel,
    this.onAction,
    this.icon,
    super.key,
  });

  final CustomerStateKind kind;
  final String title;
  final String? message;
  final String? actionLabel;
  final VoidCallback? onAction;
  final IconData? icon;

  @override
  Widget build(BuildContext context) {
    final stateColor = switch (kind) {
      CustomerStateKind.error => CustomerUiColors.destructive,
      CustomerStateKind.success => CustomerUiColors.success,
      CustomerStateKind.empty => CustomerUiColors.deepGreenSoft,
    };
    final stateIcon = icon ??
        switch (kind) {
          CustomerStateKind.error => Icons.error_outline_rounded,
          CustomerStateKind.success => Icons.check_circle_outline_rounded,
          CustomerStateKind.empty => Icons.inbox_outlined,
        };

    return Center(
      child: Padding(
        padding: const EdgeInsets.all(CustomerUiSpacing.md),
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 420),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              DecoratedBox(
                decoration: BoxDecoration(
                  color: stateColor.withValues(alpha: 0.10),
                  shape: BoxShape.circle,
                ),
                child: SizedBox.square(
                  dimension: 52,
                  child: Icon(stateIcon, color: stateColor, size: 24),
                ),
              ),
              const SizedBox(height: CustomerUiSpacing.sm),
              Text(
                title,
                textAlign: TextAlign.center,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: Theme.of(context).textTheme.titleMedium,
              ),
              if (message?.trim().isNotEmpty == true) ...[
                const SizedBox(height: CustomerUiSpacing.xs),
                Text(
                  message!,
                  textAlign: TextAlign.center,
                  style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                        color: CustomerUiColors.muted,
                      ),
                ),
              ],
              if (actionLabel?.trim().isNotEmpty == true && onAction != null) ...[
                const SizedBox(height: CustomerUiSpacing.md),
                OutlinedButton(
                  onPressed: onAction,
                  child: Text(actionLabel!),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}
