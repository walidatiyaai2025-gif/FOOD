import 'package:flutter/material.dart';

import '../../shared/customer_ui_v3/customer_ui_v3.dart';

class CustomerAccountHeader extends StatelessWidget {
  const CustomerAccountHeader({
    required this.title,
    required this.subtitle,
    this.trailing,
    super.key,
  });

  final String title;
  final String subtitle;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    final canPop = Navigator.of(context).canPop();

    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (canPop) ...[
          CustomerOutlineIconButton(
            icon: Icons.arrow_back_rounded,
            tooltip: MaterialLocalizations.of(context).backButtonTooltip,
            onPressed: () => Navigator.of(context).maybePop(),
          ),
          const SizedBox(width: CustomerUiSpacing.sm),
        ],
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                title,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                      color: CustomerUiColors.white,
                      fontSize: 18,
                      height: 1.05,
                      fontWeight: FontWeight.w800,
                    ),
              ),
              const SizedBox(height: 2),
              Text(
                subtitle,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                      color: CustomerUiColors.white.withValues(alpha: 0.82),
                      fontSize: 12,
                      height: 1.1,
                    ),
              ),
            ],
          ),
        ),
        if (trailing != null) ...[
          const SizedBox(width: CustomerUiSpacing.sm),
          trailing!,
        ],
      ],
    );
  }
}

class CustomerAccountSurfaceCard extends StatelessWidget {
  const CustomerAccountSurfaceCard({
    required this.child,
    this.padding = const EdgeInsets.all(CustomerUiSpacing.md),
    this.onTap,
    super.key,
  });

  final Widget child;
  final EdgeInsetsGeometry padding;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final decoration = BoxDecoration(
      color: CustomerUiColors.white,
      borderRadius: BorderRadius.circular(CustomerUiRadii.lg),
      border: Border.all(color: CustomerUiColors.border),
      boxShadow: CustomerUiElevation.cardShadow,
    );

    if (onTap == null) {
      return DecoratedBox(
        decoration: decoration,
        child: Padding(
          padding: padding,
          child: child,
        ),
      );
    }

    return Material(
      color: Colors.transparent,
      child: Ink(
        decoration: decoration,
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(CustomerUiRadii.lg),
          child: Padding(
            padding: padding,
            child: child,
          ),
        ),
      ),
    );
  }
}

class CustomerAccountAvatar extends StatelessWidget {
  const CustomerAccountAvatar({
    this.name,
    this.size = 72,
    super.key,
  });

  final String? name;
  final double size;

  @override
  Widget build(BuildContext context) {
    final trimmed = name?.trim() ?? '';
    final initial = trimmed.isEmpty ? null : trimmed.substring(0, 1);

    return Semantics(
      image: true,
      child: DecoratedBox(
        decoration: const BoxDecoration(
          color: CustomerUiColors.limeSoft,
          shape: BoxShape.circle,
        ),
        child: SizedBox.square(
          dimension: size,
          child: Center(
            child: initial == null
                ? Icon(
                    Icons.person_outline_rounded,
                    size: size * 0.46,
                    color: CustomerUiColors.deepGreenStrong,
                  )
                : Text(
                    initial.toUpperCase(),
                    style: Theme.of(context).textTheme.headlineMedium?.copyWith(
                          color: CustomerUiColors.deepGreenStrong,
                        ),
                  ),
          ),
        ),
      ),
    );
  }
}

class CustomerAccountShortcutCard extends StatelessWidget {
  const CustomerAccountShortcutCard({
    required this.icon,
    required this.title,
    this.value,
    this.onTap,
    super.key,
  });

  final IconData icon;
  final String title;
  final String? value;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) => CustomerAccountSurfaceCard(
        onTap: onTap,
        child: Row(
          children: [
            DecoratedBox(
              decoration: const BoxDecoration(
                color: CustomerUiColors.mint,
                shape: BoxShape.circle,
              ),
              child: SizedBox.square(
                dimension: 48,
                child: Icon(
                  icon,
                  color: CustomerUiColors.deepGreenStrong,
                ),
              ),
            ),
            const SizedBox(width: CustomerUiSpacing.sm),
            Expanded(
              child: Text(
                title,
                style: Theme.of(context).textTheme.titleMedium,
              ),
            ),
            if (value != null) ...[
              CustomerBadge(
                label: value!,
                tone: CustomerBadgeTone.accent,
              ),
              const SizedBox(width: CustomerUiSpacing.xs),
            ],
            if (onTap != null)
              const Icon(
                Icons.chevron_right_rounded,
                color: CustomerUiColors.muted,
              ),
          ],
        ),
      );
}

class CustomerAccountListSkeleton extends StatelessWidget {
  const CustomerAccountListSkeleton({
    this.itemCount = 3,
    super.key,
  });

  final int itemCount;

  @override
  Widget build(BuildContext context) => Column(
        children: List.generate(
          itemCount,
          (index) => Padding(
            padding: EdgeInsets.only(
              bottom: index == itemCount - 1 ? 0 : CustomerUiSpacing.sm,
            ),
            child: const CustomerAccountSurfaceCard(
              child: Row(
                children: [
                  CustomerSkeletonBox(
                    height: 48,
                    width: 48,
                    radius: 24,
                  ),
                  SizedBox(width: CustomerUiSpacing.sm),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        CustomerSkeletonBox(height: 16, radius: 8),
                        SizedBox(height: CustomerUiSpacing.xs),
                        CustomerSkeletonBox(
                          height: 12,
                          width: 150,
                          radius: 6,
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      );
}
