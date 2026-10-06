import 'package:flutter/material.dart';

import '../../core/theme/customer_ui_v3_tokens.dart';

class CustomerCurvedHeaderSurface extends StatelessWidget {
  const CustomerCurvedHeaderSurface({
    required this.header,
    required this.child,
    this.backgroundColor = CustomerUiColors.mint,
    this.headerColor = CustomerUiColors.deepGreen,
    this.headerPadding = const EdgeInsetsDirectional.fromSTEB(12, 6, 12, 8),
    super.key,
  });

  final Widget header;
  final Widget child;
  final Color backgroundColor;
  final Color headerColor;
  final EdgeInsetsGeometry headerPadding;

  @override
  Widget build(BuildContext context) => ColoredBox(
        color: backgroundColor,
        child: Column(
          children: [
            DecoratedBox(
              decoration: BoxDecoration(
                color: headerColor,
                borderRadius: const BorderRadius.vertical(
                  bottom: Radius.circular(CustomerUiRadii.curvedHeader),
                ),
              ),
              child: SafeArea(
                bottom: false,
                child: Padding(
                  padding: headerPadding,
                  child: header,
                ),
              ),
            ),
            Expanded(child: child),
          ],
        ),
      );
}

class CustomerSearchPill extends StatelessWidget {
  const CustomerSearchPill({
    required this.hintText,
    this.controller,
    this.focusNode,
    this.onChanged,
    this.onSubmitted,
    this.trailing,
    this.enabled = true,
    this.semanticLabel,
    super.key,
  });

  final String hintText;
  final TextEditingController? controller;
  final FocusNode? focusNode;
  final ValueChanged<String>? onChanged;
  final ValueChanged<String>? onSubmitted;
  final Widget? trailing;
  final bool enabled;
  final String? semanticLabel;

  @override
  Widget build(BuildContext context) => Semantics(
        textField: true,
        label: semanticLabel ?? hintText,
        child: TextField(
          controller: controller,
          focusNode: focusNode,
          enabled: enabled,
          textInputAction: TextInputAction.search,
          onChanged: onChanged,
          onSubmitted: onSubmitted,
          decoration: InputDecoration(
            hintText: hintText,
            prefixIcon: const Icon(Icons.search_rounded),
            suffixIcon: trailing,
            filled: true,
            fillColor: CustomerUiColors.white,
            border: OutlineInputBorder(
              borderRadius: BorderRadius.circular(CustomerUiRadii.pill),
              borderSide: const BorderSide(color: CustomerUiColors.border),
            ),
          ),
        ),
      );
}

enum CustomerBadgeTone { accent, neutral, discount, success }

class CustomerBadge extends StatelessWidget {
  const CustomerBadge({
    required this.label,
    this.tone = CustomerBadgeTone.neutral,
    super.key,
  });

  final String label;
  final CustomerBadgeTone tone;

  @override
  Widget build(BuildContext context) {
    final (background, foreground) = switch (tone) {
      CustomerBadgeTone.accent => (
          CustomerUiColors.limeSoft,
          CustomerUiColors.deepGreenStrong,
        ),
      CustomerBadgeTone.discount => (
          const Color(0xFFFFECEC),
          CustomerUiColors.destructive,
        ),
      CustomerBadgeTone.success => (
          const Color(0xFFE8F7EE),
          CustomerUiColors.success,
        ),
      CustomerBadgeTone.neutral => (
          CustomerUiColors.mint,
          CustomerUiColors.inkSoft,
        ),
    };

    return ConstrainedBox(
      constraints: const BoxConstraints(maxWidth: 160),
      child: DecoratedBox(
        decoration: BoxDecoration(
          color: background,
          borderRadius: BorderRadius.circular(CustomerUiRadii.pill),
        ),
        child: Padding(
          padding: const EdgeInsetsDirectional.fromSTEB(9, 4, 9, 4),
          child: Text(
            label,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            textAlign: TextAlign.center,
            style: Theme.of(context).textTheme.labelMedium?.copyWith(
                  color: foreground,
                  fontWeight: FontWeight.w800,
                ),
          ),
        ),
      ),
    );
  }
}

class CustomerOutlineIconButton extends StatelessWidget {
  const CustomerOutlineIconButton({
    required this.icon,
    required this.onPressed,
    this.tooltip,
    this.selected = false,
    this.semanticLabel,
    super.key,
  });

  final IconData icon;
  final VoidCallback? onPressed;
  final String? tooltip;
  final bool selected;
  final String? semanticLabel;

  @override
  Widget build(BuildContext context) => Semantics(
        button: true,
        label: semanticLabel ?? tooltip,
        child: DecoratedBox(
          decoration: BoxDecoration(
            color: selected
                ? CustomerUiColors.limeSoft
                : CustomerUiColors.white,
            borderRadius: BorderRadius.circular(CustomerUiRadii.md),
            border: Border.all(color: CustomerUiColors.border),
          ),
          child: SizedBox.square(
            dimension: 44,
            child: IconButton(
              tooltip: tooltip,
              onPressed: onPressed,
              icon: Icon(
                icon,
                color: selected
                    ? CustomerUiColors.deepGreenStrong
                    : CustomerUiColors.inkSoft,
              ),
            ),
          ),
        ),
      );
}

class CustomerCategoryTile extends StatelessWidget {
  const CustomerCategoryTile({
    required this.label,
    this.imageUrl,
    this.fallbackIcon = Icons.category_outlined,
    this.onTap,
    this.selected = false,
    super.key,
  });

  final String label;
  final String? imageUrl;
  final IconData fallbackIcon;
  final VoidCallback? onTap;
  final bool selected;

  @override
  Widget build(BuildContext context) => SizedBox(
        width: 84,
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(CustomerUiRadii.lg),
          child: Padding(
            padding: const EdgeInsets.symmetric(vertical: CustomerUiSpacing.xs),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                AnimatedContainer(
                  duration: CustomerUiMotion.resolve(
                    context,
                    CustomerUiMotion.standard,
                  ),
                  curve: CustomerUiMotion.standardCurve,
                  width: 64,
                  height: 64,
                  padding: const EdgeInsets.all(3),
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    color: selected
                        ? CustomerUiColors.limeSoft
                        : CustomerUiColors.white,
                    border: Border.all(
                      color: selected
                          ? CustomerUiColors.lime
                          : CustomerUiColors.border,
                      width: selected
                          ? CustomerUiStroke.emphasis
                          : CustomerUiStroke.hairline,
                    ),
                  ),
                  child: ClipOval(
                    child: imageUrl?.trim().isNotEmpty == true
                        ? Image.network(
                            imageUrl!,
                            fit: BoxFit.cover,
                            errorBuilder: (_, __, ___) =>
                                _CategoryFallback(icon: fallbackIcon),
                          )
                        : _CategoryFallback(icon: fallbackIcon),
                  ),
                ),
                const SizedBox(height: CustomerUiSpacing.xs),
                Text(
                  label,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  textAlign: TextAlign.center,
                  style: Theme.of(context).textTheme.labelLarge,
                ),
              ],
            ),
          ),
        ),
      );
}

class _CategoryFallback extends StatelessWidget {
  const _CategoryFallback({required this.icon});

  final IconData icon;

  @override
  Widget build(BuildContext context) => ColoredBox(
        color: CustomerUiColors.mint,
        child: Center(
          child: Icon(
            icon,
            color: CustomerUiColors.deepGreenStrong,
          ),
        ),
      );
}

class CustomerProductImage extends StatelessWidget {
  const CustomerProductImage({
    this.imageUrl,
    this.fallbackAsset,
    this.aspectRatio = 1.35,
    super.key,
  });

  final String? imageUrl;
  final String? fallbackAsset;
  final double aspectRatio;

  @override
  Widget build(BuildContext context) => AspectRatio(
        aspectRatio: aspectRatio,
        child: ClipRRect(
          borderRadius: BorderRadius.circular(CustomerUiRadii.lg),
          child: imageUrl?.trim().isNotEmpty == true
              ? Image.network(
                  imageUrl!,
                  fit: BoxFit.cover,
                  errorBuilder: (_, __, ___) => _fallback(),
                )
              : _fallback(),
        ),
      );

  Widget _fallback() {
    if (fallbackAsset?.trim().isNotEmpty == true) {
      return Image.asset(
        fallbackAsset!,
        fit: BoxFit.cover,
        errorBuilder: (_, __, ___) => const _ProductFallback(),
      );
    }
    return const _ProductFallback();
  }
}

class _ProductFallback extends StatelessWidget {
  const _ProductFallback();

  @override
  Widget build(BuildContext context) => const ColoredBox(
        color: CustomerUiColors.mint,
        child: Center(
          child: Icon(
            Icons.inventory_2_outlined,
            color: CustomerUiColors.deepGreenSoft,
            size: 34,
          ),
        ),
      );
}

class CustomerProductCard extends StatelessWidget {
  const CustomerProductCard({
    required this.title,
    required this.priceLabel,
    this.imageUrl,
    this.fallbackAsset,
    this.categoryLabel,
    this.brandLabel,
    this.brandImageUrl,
    this.oldPriceLabel,
    this.discountLabel,
    this.favorite = false,
    this.onTap,
    this.onFavorite,
    this.onAdd,
    this.addSemanticLabel,
    this.favoriteSemanticLabel,
    this.isAvailable = true,
    this.unavailableLabel,
    super.key,
  });

  final String title;
  final String priceLabel;
  final String? imageUrl;
  final String? fallbackAsset;
  final String? categoryLabel;
  final String? brandLabel;
  final String? brandImageUrl;
  final String? oldPriceLabel;
  final String? discountLabel;
  final bool favorite;
  final VoidCallback? onTap;
  final VoidCallback? onFavorite;
  final VoidCallback? onAdd;
  final String? addSemanticLabel;
  final String? favoriteSemanticLabel;
  final bool isAvailable;
  final String? unavailableLabel;

  @override
  Widget build(BuildContext context) => Material(
        color: CustomerUiColors.white,
        borderRadius: BorderRadius.circular(CustomerUiRadii.xl),
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: onTap,
          child: DecoratedBox(
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(CustomerUiRadii.xl),
              border: Border.all(color: CustomerUiColors.border),
            ),
            child: Padding(
              padding: const EdgeInsets.all(CustomerUiSpacing.sm),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Stack(
                    children: [
                      Opacity(
                        opacity: isAvailable ? 1 : 0.42,
                        child: CustomerProductImage(
                          imageUrl: imageUrl,
                          fallbackAsset: fallbackAsset,
                        ),
                      ),
                      if (!isAvailable && unavailableLabel?.trim().isNotEmpty == true)
                        PositionedDirectional(
                          bottom: CustomerUiSpacing.xs,
                          start: CustomerUiSpacing.xs,
                          child: DecoratedBox(
                            key: const ValueKey('customer-product-out-of-stock'),
                            decoration: BoxDecoration(
                              color: CustomerUiColors.white,
                              borderRadius: BorderRadius.circular(CustomerUiRadii.pill),
                              border: Border.all(color: CustomerUiColors.border),
                            ),
                            child: Padding(
                              padding: const EdgeInsets.symmetric(
                                horizontal: CustomerUiSpacing.sm,
                                vertical: CustomerUiSpacing.xxs,
                              ),
                              child: Text(
                                unavailableLabel!,
                                style: Theme.of(context).textTheme.labelSmall?.copyWith(
                                      color: CustomerUiColors.muted,
                                      fontWeight: FontWeight.w800,
                                    ),
                              ),
                            ),
                          ),
                        ),
                      if (discountLabel?.trim().isNotEmpty == true)
                        PositionedDirectional(
                          top: CustomerUiSpacing.xs,
                          start: CustomerUiSpacing.xs,
                          child: CustomerBadge(
                            label: discountLabel!,
                            tone: CustomerBadgeTone.discount,
                          ),
                        ),
                      if (onFavorite != null)
                        PositionedDirectional(
                          top: CustomerUiSpacing.xs,
                          end: CustomerUiSpacing.xs,
                          child: CustomerOutlineIconButton(
                            icon: favorite
                                ? Icons.favorite_rounded
                                : Icons.favorite_border_rounded,
                            selected: favorite,
                            semanticLabel: favoriteSemanticLabel,
                            onPressed: onFavorite,
                          ),
                        ),
                      if (brandImageUrl?.trim().isNotEmpty == true)
                        PositionedDirectional(
                          bottom: CustomerUiSpacing.xs,
                          end: CustomerUiSpacing.xs,
                          child: Tooltip(
                            message: brandLabel?.trim() ?? '',
                            child: Container(
                              width: 34,
                              height: 34,
                              padding: const EdgeInsets.all(3),
                              clipBehavior: Clip.antiAlias,
                              decoration: BoxDecoration(
                                color: CustomerUiColors.white,
                                shape: BoxShape.circle,
                                border: Border.all(
                                  color: CustomerUiColors.border,
                                ),
                              ),
                              child: ClipOval(
                                child: Image.network(
                                  brandImageUrl!,
                                  fit: BoxFit.contain,
                                  errorBuilder: (_, __, ___) => const Icon(
                                    Icons.sell_outlined,
                                    size: 18,
                                    color: CustomerUiColors.deepGreenSoft,
                                  ),
                                ),
                              ),
                            ),
                          ),
                        ),
                    ],
                  ),
                  const SizedBox(height: CustomerUiSpacing.sm),
                  if (categoryLabel?.trim().isNotEmpty == true) ...[
                    CustomerBadge(
                      label: categoryLabel!,
                      tone: CustomerBadgeTone.accent,
                    ),
                    const SizedBox(height: CustomerUiSpacing.xs),
                  ],
                  Text(
                    title,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                  const SizedBox(height: CustomerUiSpacing.sm),
                  Row(
                    crossAxisAlignment: CrossAxisAlignment.end,
                    children: [
                      Expanded(
                        child: Wrap(
                          spacing: CustomerUiSpacing.xs,
                          crossAxisAlignment: WrapCrossAlignment.center,
                          children: [
                            Text(
                              priceLabel,
                              style: Theme.of(context)
                                  .textTheme
                                  .titleLarge
                                  ?.copyWith(
                                    color: CustomerUiColors.deepGreenStrong,
                                  ),
                            ),
                            if (oldPriceLabel?.trim().isNotEmpty == true)
                              Text(
                                oldPriceLabel!,
                                style: Theme.of(context)
                                    .textTheme
                                    .bodyMedium
                                    ?.copyWith(
                                      color: CustomerUiColors.muted,
                                      decoration: TextDecoration.lineThrough,
                                    ),
                              ),
                          ],
                        ),
                      ),
                      if (isAvailable && onAdd != null)
                        Semantics(
                          button: true,
                          label: addSemanticLabel,
                          child: InkResponse(
                            onTap: onAdd,
                            radius: 28,
                            child: const DecoratedBox(
                              decoration: BoxDecoration(
                                color: CustomerUiColors.lime,
                                shape: BoxShape.circle,
                              ),
                              child: SizedBox.square(
                                dimension: 44,
                                child: Icon(
                                  Icons.add_rounded,
                                  color: CustomerUiColors.deepGreenStrong,
                                ),
                              ),
                            ),
                          ),
                        ),
                    ],
                  ),
                ],
              ),
            ),
          ),
        ),
      );
}
