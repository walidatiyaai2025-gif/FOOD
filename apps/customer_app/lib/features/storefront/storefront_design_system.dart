// ignore_for_file: prefer_interpolation_to_compose_strings, deprecated_member_use

import 'dart:async';
import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:foodex_customer_app/shared/foodex_wave_loading.dart';
import 'package:foodex_formatting/foodex_formatting.dart';

import '../../core/routing/customer_commerce_context.dart';
import '../../core/routing/customer_routes.dart';
import '../../core/theme/customer_ui_v3_tokens.dart';

final ValueNotifier<int> foodexWholesaleCartProductCount =
    ValueNotifier<int>(0);

void syncFoodexWholesaleCartProductCount(Object? payload) {
  if (payload is! Map) {
    foodexWholesaleCartProductCount.value = 0;
    return;
  }

  final rawItems = payload['items'];
  if (rawItems is! List) {
    foodexWholesaleCartProductCount.value = 0;
    return;
  }

  final count = rawItems.whereType<Map>().where((item) {
    final quantity = double.tryParse(item['quantity']?.toString() ?? '') ?? 0;
    final itemId = int.tryParse(item['id']?.toString() ?? '') ?? 0;
    final product = item['product'];
    final productId = product is Map
        ? int.tryParse(product['id']?.toString() ?? '') ?? 0
        : int.tryParse(item['product_id']?.toString() ?? '') ?? 0;
    return quantity > 0 && (itemId > 0 || productId > 0);
  }).length;

  foodexWholesaleCartProductCount.value = count;
}

OverlayEntry? _foodexWholesaleCartAddHoverEntry;
Timer? _foodexWholesaleCartAddHoverTimer;

void showFoodexWholesaleCartAddHover({
  required OverlayState overlay,
  required TextDirection textDirection,
  required double topInset,
  required String productName,
  String? imageUrl,
  Duration duration = const Duration(seconds: 5),
}) {
  _foodexWholesaleCartAddHoverTimer?.cancel();
  _foodexWholesaleCartAddHoverEntry?.remove();
  _foodexWholesaleCartAddHoverEntry = null;

  late final OverlayEntry entry;
  entry = OverlayEntry(
    builder: (context) => Positioned(
      top: topInset + 48,
      left: textDirection == TextDirection.rtl ? 10 : null,
      right: textDirection == TextDirection.ltr ? 10 : null,
      child: Material(
        color: Colors.transparent,
        child: IgnorePointer(
          child: Container(
            key: const ValueKey('wholesale-cart-add-hover'),
            constraints: const BoxConstraints(maxWidth: 250),
            padding: const EdgeInsets.fromLTRB(8, 7, 9, 7),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(16),
              border: Border.all(color: CustomerUiColors.storefrontBorderSoft),
              boxShadow: const <BoxShadow>[
                BoxShadow(
                  color: CustomerUiColors.storefrontShadowMedium,
                  blurRadius: 16,
                  offset: Offset(0, 6),
                ),
              ],
            ),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              textDirection: textDirection,
              children: [
                Container(
                  width: 38,
                  height: 38,
                  clipBehavior: Clip.antiAlias,
                  decoration: BoxDecoration(
                    color: CustomerUiColors.storefrontMintWash,
                    borderRadius: BorderRadius.circular(11),
                  ),
                  child: imageUrl != null && imageUrl.trim().isNotEmpty
                      ? Image.network(
                          imageUrl,
                          fit: BoxFit.cover,
                          errorBuilder: (_, __, ___) => const Icon(
                            Icons.inventory_2_outlined,
                            size: 19,
                            color: CustomerUiColors.wholesalePositiveValue,
                          ),
                        )
                      : const Icon(
                          Icons.inventory_2_outlined,
                          size: 19,
                          color: CustomerUiColors.wholesalePositiveValue,
                        ),
                ),
                const SizedBox(width: 8),
                Flexible(
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text(
                        'تمت الإضافة للسلة',
                        maxLines: 1,
                        style: TextStyle(
                          color: CustomerUiColors.wholesaleVehicleGreen,
                          fontSize: 10,
                          fontWeight: FontWeight.w900,
                        ),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        productName,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                          color: CustomerUiColors.wholesaleCommerceInk,
                          fontSize: 9.5,
                          fontWeight: FontWeight.w800,
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(width: 6),
                const Icon(
                  Icons.check_circle_rounded,
                  size: 18,
                  color: CustomerUiColors.wholesalePositiveValue,
                ),
              ],
            ),
          ),
        ),
      ),
    ),
  );

  _foodexWholesaleCartAddHoverEntry = entry;
  overlay.insert(entry);
  _foodexWholesaleCartAddHoverTimer = Timer(duration, () {
    if (_foodexWholesaleCartAddHoverEntry == entry) {
      entry.remove();
      _foodexWholesaleCartAddHoverEntry = null;
    }
  });
}

class FoodexPalette {
  const FoodexPalette({
    required this.primary,
    required this.primaryDark,
    required this.accent,
    required this.background,
    required this.soft,
    required this.text,
    required this.muted,
  });

  final Color primary;
  final Color primaryDark;
  final Color accent;
  final Color background;
  final Color soft;
  final Color text;
  final Color muted;

  static const grocery = FoodexPalette(
    primary: CustomerUiColors.wholesalePositiveValue,
    primaryDark: CustomerUiColors.wholesaleVehicleGreen,
    accent: CustomerUiColors.storefrontLimeStrong,
    background: Color(0xFFF8FBF9),
    soft: CustomerUiColors.storefrontMintWash,
    text: CustomerUiColors.wholesaleCommerceInk,
    muted: Color(0xFF6B7785),
  );

  static const pharmacy = FoodexPalette(
    primary: Color(0xFF0A8DDA),
    primaryDark: Color(0xFF0668A9),
    accent: Color(0xFF37CCFF),
    background: Color(0xFFF8FCFF),
    soft: Color(0xFFEEF8FE),
    text: CustomerUiColors.wholesaleCommerceInk,
    muted: Color(0xFF6B7785),
  );

  static const wholesale = FoodexPalette(
    primary: CustomerUiColors.wholesalePositiveValue,
    primaryDark: CustomerUiColors.wholesaleVehicleGreen,
    accent: Color(0xFF92D853),
    background: Color(0xFFF8FBF9),
    soft: CustomerUiColors.storefrontMintWash,
    text: CustomerUiColors.wholesaleCommerceInk,
    muted: Color(0xFF6B7785),
  );
}

class FoodexWholesaleHeader extends StatelessWidget {
  const FoodexWholesaleHeader({
    required this.title,
    required this.palette,
    this.searchController,
    this.searchHint,
    this.onSearchSubmitted,
    this.searchKey = const ValueKey('wholesale-header-search'),
    this.onSearchChanged,
    this.onClearSearch,
    this.onNotifications,
    this.onCart,
    this.notificationKey,
    this.cartKey,
    this.location,
    this.leading,
    this.trailingActions = const <Widget>[],
    this.logoUrl,
    this.address,
    super.key,
  });

  final String title;
  final String? logoUrl;
  final String? address;
  final VoidCallback? onNotifications;
  final VoidCallback? onCart;
  final Key? notificationKey;
  final Key? cartKey;
  final String? location;
  final Widget? leading;
  final List<Widget> trailingActions;
  final FoodexPalette palette;
  final TextEditingController? searchController;
  final String? searchHint;
  final ValueChanged<String>? onSearchSubmitted;
  final Key searchKey;
  final ValueChanged<String>? onSearchChanged;
  final VoidCallback? onClearSearch;

  String? _currentLocation(BuildContext context) =>
      location ?? ModalRoute.of(context)?.settings.name;

  CustomerCommerceContext? _scope(BuildContext context) =>
      CustomerCommerceContext.tryParseLocation(_currentLocation(context));

  void _defaultSearch(BuildContext context, String value) {
    final query = value.trim();
    if (query.isEmpty) return;
    final current = _currentLocation(context);
    final scope = _scope(context);
    if (scope?.isWholesale == true) {
      Navigator.of(context).pushNamed(
        Uri(
          path: CustomerRoutePaths.b2bProducts,
          queryParameters: <String, String>{
            ...scope!.toQueryParameters(),
            'q': query,
          },
        ).toString(),
      );
      return;
    }
    if (scope?.isRetail == true) {
      Navigator.of(context).pushNamed(
        CustomerRouteLocations.retailProducts(scope!, query: query),
      );
      return;
    }
    if (current != null &&
        Uri.tryParse(current)?.path.startsWith('/b2b') == true) {
      final uri = Uri.parse(current);
      final storeId =
          uri.queryParameters['store_id'] ?? uri.queryParameters['store'];
      Navigator.of(context).pushNamed(
        Uri(
          path: CustomerRoutePaths.b2bProducts,
          queryParameters: <String, String>{
            if (storeId != null && storeId.isNotEmpty) 'store_id': storeId,
            'channel': 'wholesale',
            'q': query,
          },
        ).toString(),
      );
    }
  }

  void _defaultNotifications(BuildContext context) {
    final scope = _scope(context);
    if (scope?.isWholesale == true) {
      Navigator.of(context).pushNamed(
        CustomerRouteLocations.wholesaleNotifications(scope!),
      );
      return;
    }
    if (scope?.isRetail == true) {
      Navigator.of(context).pushNamed(
        CustomerRouteLocations.retailNotifications(scope!),
      );
      return;
    }
    final current = _currentLocation(context);
    if (current != null &&
        Uri.tryParse(current)?.path.startsWith('/b2b') == true) {
      final uri = Uri.parse(current);
      final storeId =
          uri.queryParameters['store_id'] ?? uri.queryParameters['store'];
      Navigator.of(context).pushNamed(
        Uri(
          path: CustomerRoutePaths.b2bNotifications,
          queryParameters: <String, String>{
            if (storeId != null && storeId.isNotEmpty) 'store_id': storeId,
            'channel': 'wholesale',
          },
        ).toString(),
      );
    }
  }

  void _defaultCart(BuildContext context) {
    final scope = _scope(context);
    if (scope?.isWholesale == true) {
      Navigator.of(context).pushNamed(
        CustomerRouteLocations.wholesaleCart(scope!),
      );
      return;
    }
    if (scope?.isRetail == true) {
      Navigator.of(context).pushNamed(
        CustomerRouteLocations.retailCart(scope!),
      );
      return;
    }
    final current = _currentLocation(context);
    if (current != null &&
        Uri.tryParse(current)?.path.startsWith('/b2b') == true) {
      final uri = Uri.parse(current);
      final storeId =
          uri.queryParameters['store_id'] ?? uri.queryParameters['store'];
      Navigator.of(context).pushNamed(
        Uri(
          path: CustomerRoutePaths.b2bCart,
          queryParameters: <String, String>{
            if (storeId != null && storeId.isNotEmpty) 'store_id': storeId,
            'channel': 'wholesale',
          },
        ).toString(),
      );
    }
  }

  @override
  Widget build(BuildContext context) => Container(
        key: const ValueKey('foodex-wholesale-shared-header'),
        padding: const EdgeInsets.fromLTRB(12, 8, 12, 8),
        color: palette.primaryDark,
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.center,
          children: [
            if (leading != null) ...[
              leading!,
              const SizedBox(width: 6),
            ],
            if (logoUrl != null && logoUrl!.trim().isNotEmpty) ...[
              ClipRRect(
                borderRadius: BorderRadius.circular(8),
                child: Image.network(
                  logoUrl!,
                  width: 28,
                  height: 28,
                  fit: BoxFit.cover,
                  errorBuilder: (_, __, ___) =>
                      const SizedBox(width: 28, height: 28),
                ),
              ),
              const SizedBox(width: 6),
            ],
            ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 72),
              child: Tooltip(
                message: address == null || address!.trim().isEmpty
                    ? title
                    : '$title\n$address',
                child: _FoodexAnimatedHeaderTitle(title: title),
              ),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: SizedBox(
                height: 36,
                child: TextField(
                  key: searchKey,
                  controller: searchController,
                  textInputAction: TextInputAction.search,
                  onSubmitted: onSearchSubmitted ??
                      (value) => _defaultSearch(context, value),
                  onChanged: onSearchChanged,
                  style: const TextStyle(fontSize: 12),
                  decoration: InputDecoration(
                    isDense: true,
                    filled: true,
                    fillColor: Colors.white,
                    hintText: searchHint,
                    hintStyle: const TextStyle(fontSize: 11),
                    prefixIcon: const Icon(Icons.search_rounded, size: 16),
                    prefixIconConstraints: const BoxConstraints(
                      minWidth: 34,
                      minHeight: 34,
                    ),
                    suffixIcon: onClearSearch == null
                        ? const Icon(
                            Icons.qr_code_scanner_rounded,
                            size: 16,
                          )
                        : IconButton(
                            padding: EdgeInsets.zero,
                            constraints: const BoxConstraints(
                              minWidth: 34,
                              minHeight: 34,
                            ),
                            onPressed:
                                searchController?.text.trim().isEmpty != false
                                    ? null
                                    : onClearSearch,
                            icon: const Icon(Icons.close_rounded, size: 16),
                          ),
                    suffixIconConstraints: const BoxConstraints(
                      minWidth: 34,
                      minHeight: 34,
                    ),
                    contentPadding: const EdgeInsets.symmetric(
                      horizontal: 8,
                      vertical: 8,
                    ),
                    border: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(999),
                      borderSide: BorderSide.none,
                    ),
                    enabledBorder: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(999),
                      borderSide: BorderSide.none,
                    ),
                    focusedBorder: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(999),
                      borderSide: BorderSide(
                        color: palette.accent,
                        width: 1.2,
                      ),
                    ),
                  ),
                ),
              ),
            ),
            const SizedBox(width: 6),
            ...trailingActions,
            if (trailingActions.isNotEmpty) const SizedBox(width: 4),
            _FoodexWholesaleHeaderIcon(
              key: notificationKey,
              icon: Icons.notifications_none_rounded,
              onTap: onNotifications ?? () => _defaultNotifications(context),
            ),
            const SizedBox(width: 4),
            ValueListenableBuilder<int>(
              valueListenable: foodexWholesaleCartProductCount,
              builder: (context, count, _) => _FoodexWholesaleHeaderIcon(
                key: cartKey,
                icon: Icons.shopping_cart_outlined,
                badgeCount: count,
                onTap: onCart ?? () => _defaultCart(context),
              ),
            ),
          ],
        ),
      );
}

class FoodexUnifiedHeaderSurface extends StatelessWidget {
  const FoodexUnifiedHeaderSurface({
    required this.title,
    required this.child,
    this.palette = FoodexPalette.wholesale,
    this.location,
    this.searchController,
    this.searchHint,
    this.onSearchSubmitted,
    this.searchKey = const ValueKey('wholesale-header-search'),
    this.onSearchChanged,
    this.onClearSearch,
    this.onNotifications,
    this.onCart,
    this.notificationKey,
    this.cartKey,
    this.leading,
    this.trailingActions = const <Widget>[],
    this.logoUrl,
    this.address,
    super.key,
  });

  final String title;
  final Widget child;
  final FoodexPalette palette;
  final String? location;
  final TextEditingController? searchController;
  final String? searchHint;
  final ValueChanged<String>? onSearchSubmitted;
  final Key searchKey;
  final ValueChanged<String>? onSearchChanged;
  final VoidCallback? onClearSearch;
  final VoidCallback? onNotifications;
  final VoidCallback? onCart;
  final Key? notificationKey;
  final Key? cartKey;
  final Widget? leading;
  final List<Widget> trailingActions;
  final String? logoUrl;
  final String? address;

  @override
  Widget build(BuildContext context) => ColoredBox(
        color: palette.background,
        child: SafeArea(
          bottom: false,
          child: Column(
            children: [
              FoodexWholesaleHeader(
                title: title,
                palette: palette,
                location: location,
                searchController: searchController,
                searchHint: searchHint,
                onSearchSubmitted: onSearchSubmitted,
                searchKey: searchKey,
                onSearchChanged: onSearchChanged,
                onClearSearch: onClearSearch,
                onNotifications: onNotifications,
                onCart: onCart,
                notificationKey: notificationKey,
                cartKey: cartKey,
                leading: leading,
                trailingActions: trailingActions,
                logoUrl: logoUrl,
                address: address,
              ),
              Expanded(child: child),
            ],
          ),
        ),
      );
}

class _FoodexAnimatedHeaderTitle extends StatefulWidget {
  const _FoodexAnimatedHeaderTitle({required this.title});

  final String title;

  @override
  State<_FoodexAnimatedHeaderTitle> createState() =>
      _FoodexAnimatedHeaderTitleState();
}

class _FoodexAnimatedHeaderTitleState extends State<_FoodexAnimatedHeaderTitle>
    with SingleTickerProviderStateMixin {
  static const _style = TextStyle(
    color: Colors.white,
    fontSize: 13.3,
    fontWeight: FontWeight.w800,
  );

  late final AnimationController _controller = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 1650),
  )..forward();

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final title = widget.title.trim();
    final arabicBrand = title.startsWith('فوديكس');
    final englishMatch =
        RegExp(r'^foodex', caseSensitive: false).firstMatch(title);

    if (!arabicBrand && englishMatch == null) {
      return Text(
        widget.title,
        maxLines: 1,
        overflow: TextOverflow.ellipsis,
        style: _style,
      );
    }

    final brand =
        arabicBrand ? 'فوديكس' : title.substring(0, englishMatch!.end);
    final rest = title.substring(brand.length);

    final animatedBrand = AnimatedBuilder(
      animation: _controller,
      builder: (context, child) {
        final progress = _controller.value;
        final wave = math.sin(progress * math.pi * 2);
        final travel = progress * 2.5;

        return Transform.translate(
          offset: Offset(0, wave * .8),
          child: ShaderMask(
            blendMode: BlendMode.srcIn,
            shaderCallback: (bounds) => LinearGradient(
              begin: Alignment(-1.8 + travel, -.8 + wave * .15),
              end: Alignment(.2 + travel, .8 - wave * .15),
              colors: const <Color>[
                CustomerUiColors.storefrontLimeWash,
                CustomerUiColors.storefrontLimeStrong,
                CustomerUiColors.storefrontGreenGlow,
                CustomerUiColors.white,
                CustomerUiColors.storefrontGreenHighlight,
              ],
              stops: const <double>[0, .22, .48, .7, 1],
            ).createShader(bounds),
            child: Text(
              brand,
              maxLines: 1,
              softWrap: false,
              style: _style.copyWith(
                color: Colors.white,
                letterSpacing: arabicBrand ? 0 : .25,
              ),
            ),
          ),
        );
      },
    );

    if (rest.isEmpty) {
      return FittedBox(
        fit: BoxFit.scaleDown,
        alignment:
            arabicBrand ? Alignment.centerRight : Alignment.centerLeft,
        child: animatedBrand,
      );
    }

    return Row(
      mainAxisSize: MainAxisSize.min,
      textDirection: arabicBrand ? TextDirection.rtl : TextDirection.ltr,
      children: [
        Flexible(
          child: FittedBox(
            fit: BoxFit.scaleDown,
            alignment:
                arabicBrand ? Alignment.centerRight : Alignment.centerLeft,
            child: animatedBrand,
          ),
        ),
        Flexible(
          flex: 2,
          child: Text(
            rest,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: _style,
          ),
        ),
      ],
    );
  }
}

class _FoodexWholesaleHeaderIcon extends StatelessWidget {
  const _FoodexWholesaleHeaderIcon({
    required this.icon,
    this.onTap,
    this.badgeCount = 0,
    super.key,
  });

  final IconData icon;
  final VoidCallback? onTap;
  final int badgeCount;

  @override
  Widget build(BuildContext context) => Stack(
        clipBehavior: Clip.none,
        children: [
          Material(
            color: Colors.white.withOpacity(.14),
            shape: const CircleBorder(),
            child: InkWell(
              onTap: onTap,
              customBorder: const CircleBorder(),
              child: SizedBox(
                width: 28,
                height: 28,
                child: Icon(
                  icon,
                  color: Colors.white,
                  size: 12,
                ),
              ),
            ),
          ),
          if (badgeCount > 0)
            Positioned(
              right: -5,
              top: -6,
              child: Container(
                key: const ValueKey('wholesale-header-cart-badge'),
                constraints: const BoxConstraints(
                  minWidth: 16,
                  minHeight: 16,
                ),
                padding: const EdgeInsets.symmetric(horizontal: 4),
                alignment: Alignment.center,
                decoration: BoxDecoration(
                  color: CustomerUiColors.wholesaleNegativeValue,
                  borderRadius: BorderRadius.circular(999),
                  border: Border.all(color: Colors.white, width: 1.2),
                ),
                child: Text(
                  badgeCount > 99 ? '99+' : badgeCount.toString(),
                  style: const TextStyle(
                    color: Colors.white,
                    fontSize: 8,
                    height: 1,
                    fontWeight: FontWeight.w900,
                  ),
                ),
              ),
            ),
        ],
      );
}

class FoodexTopBar extends StatelessWidget {
  const FoodexTopBar({
    required this.title,
    this.actions = const <Widget>[],
    super.key,
  });

  final String title;
  final List<Widget> actions;

  @override
  Widget build(BuildContext context) => SizedBox(
        height: 52,
        child: Row(
          children: [
            IconButton(
              onPressed: () => Navigator.of(context).maybePop(),
              icon: const Icon(Icons.arrow_forward_ios_rounded, size: 18),
            ),
            Expanded(
              child: Text(
                title,
                textAlign: TextAlign.center,
                style:
                    const TextStyle(fontSize: 18, fontWeight: FontWeight.w800),
              ),
            ),
            ...actions,
            if (actions.isEmpty) const SizedBox(width: 48),
          ],
        ),
      );
}

class FoodexSectionHeader extends StatelessWidget {
  const FoodexSectionHeader({
    required this.title,
    required this.palette,
    this.action = 'عرض الكل',
    this.onAction,
    this.textScale = 1,
    this.spacingScale = 1,
    this.animatedGoldTitle = false,
    super.key,
  });

  final String title;
  final String action;
  final FoodexPalette palette;
  final VoidCallback? onAction;
  final double textScale;
  final double spacingScale;
  final bool animatedGoldTitle;

  @override
  Widget build(BuildContext context) => Padding(
        padding: EdgeInsets.fromLTRB(
          16,
          14 * spacingScale,
          16,
          9 * spacingScale,
        ),
        child: Row(
          children: [
            Expanded(
              child: animatedGoldTitle
                  ? _FoodexAnimatedGoldSectionTitle(
                      title: title,
                      textScale: textScale,
                    )
                  : Text(
                      title,
                      style: TextStyle(
                        color: palette.text,
                        fontSize: 18 * textScale,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
            ),
            if (onAction == null)
              Text(
                action,
                style: TextStyle(
                  color: palette.primary,
                  fontSize: 12 * textScale,
                  fontWeight: FontWeight.w700,
                ),
              )
            else
              TextButton(
                key: ValueKey('section-action-' + title),
                onPressed: onAction,
                child: Text(
                  action,
                  style: TextStyle(
                    color: palette.primary,
                    fontSize: 12 * textScale,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ),
          ],
        ),
      );
}

class _FoodexAnimatedGoldSectionTitle extends StatefulWidget {
  const _FoodexAnimatedGoldSectionTitle({
    required this.title,
    required this.textScale,
  });

  final String title;
  final double textScale;

  @override
  State<_FoodexAnimatedGoldSectionTitle> createState() =>
      _FoodexAnimatedGoldSectionTitleState();
}

class _FoodexAnimatedGoldSectionTitleState
    extends State<_FoodexAnimatedGoldSectionTitle>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 1750),
  )..repeat();

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AnimatedBuilder(
        animation: _controller,
        builder: (context, child) {
          final progress = _controller.value;
          final wave = math.sin(progress * math.pi * 2);
          final travel = progress * 2.6;

          return Transform.translate(
            offset: Offset(0, wave * 1.15),
            child: ShaderMask(
              blendMode: BlendMode.srcIn,
              shaderCallback: (bounds) => LinearGradient(
                begin: Alignment(-1.9 + travel, -.7 + wave * .12),
                end: Alignment(.1 + travel, .8 - wave * .12),
                colors: const <Color>[
                  CustomerUiColors.storefrontGoldDeep,
                  CustomerUiColors.storefrontGoldMid,
                  CustomerUiColors.storefrontGoldLight,
                  CustomerUiColors.storefrontGoldBright,
                  CustomerUiColors.storefrontGoldDark,
                ],
                stops: const <double>[0, .22, .48, .72, 1],
              ).createShader(bounds),
              child: Text(
                widget.title,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: TextStyle(
                  color: Colors.white,
                  fontSize: 18 * widget.textScale,
                  fontWeight: FontWeight.w900,
                  letterSpacing: .15,
                  shadows: const <Shadow>[
                    Shadow(
                      color: CustomerUiColors.storefrontGoldShadow,
                      blurRadius: 4,
                      offset: Offset(0, 1),
                    ),
                  ],
                ),
              ),
            ),
          );
        },
      );
}

class FoodexAnimatedOxbloodTitle extends StatefulWidget {
  const FoodexAnimatedOxbloodTitle({
    required this.title,
    this.fontSize = 18,
    this.fontWeight = FontWeight.w900,
    super.key,
  });

  final String title;
  final double fontSize;
  final FontWeight fontWeight;

  @override
  State<FoodexAnimatedOxbloodTitle> createState() =>
      _FoodexAnimatedOxbloodTitleState();
}

class _FoodexAnimatedOxbloodTitleState extends State<FoodexAnimatedOxbloodTitle>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 1650),
  )..repeat();

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AnimatedBuilder(
        animation: _controller,
        builder: (context, child) {
          final progress = _controller.value;
          final wave = math.sin(progress * math.pi * 2);
          final travel = progress * 2.7;

          return Transform.translate(
            offset: Offset(0, wave * 1.05),
            child: ShaderMask(
              blendMode: BlendMode.srcIn,
              shaderCallback: (bounds) => LinearGradient(
                begin: Alignment(-1.9 + travel, -.75 + wave * .12),
                end: Alignment(.1 + travel, .8 - wave * .12),
                colors: const <Color>[
                  CustomerUiColors.storefrontOxbloodDeep,
                  CustomerUiColors.storefrontOxblood,
                  CustomerUiColors.storefrontOxbloodMid,
                  CustomerUiColors.storefrontOxbloodLight,
                  CustomerUiColors.storefrontOxbloodDark,
                ],
                stops: const <double>[0, .22, .48, .72, 1],
              ).createShader(bounds),
              child: Text(
                widget.title,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: TextStyle(
                  color: Colors.white,
                  fontSize: widget.fontSize,
                  fontWeight: widget.fontWeight,
                  letterSpacing: .12,
                  shadows: const <Shadow>[
                    Shadow(
                      color: CustomerUiColors.storefrontOxbloodShadow,
                      blurRadius: 4,
                      offset: Offset(0, 1),
                    ),
                  ],
                ),
              ),
            ),
          );
        },
      );
}

class FoodexProductImage extends StatelessWidget {
  const FoodexProductImage({
    required this.url,
    required this.palette,
    super.key,
  });

  final String? url;
  final FoodexPalette palette;

  @override
  Widget build(BuildContext context) => TweenAnimationBuilder<double>(
        tween: Tween<double>(begin: 1.055, end: 1),
        duration: const Duration(milliseconds: 720),
        curve: Curves.easeOutCubic,
        builder: (context, scale, child) => Transform.scale(
          scale: scale,
          child: child,
        ),
        child: Container(
          margin: const EdgeInsets.all(2),
          decoration: BoxDecoration(
            color: palette.soft,
            borderRadius: BorderRadius.circular(20),
            boxShadow: const <BoxShadow>[
              BoxShadow(
                color: CustomerUiColors.marketplaceImageShadow,
                blurRadius: 13,
                spreadRadius: 1,
                offset: Offset(0, 4),
              ),
            ],
          ),
          clipBehavior: Clip.antiAlias,
          child: url == null || url!.isEmpty
              ? Center(
                  child: Icon(
                    Icons.inventory_2_outlined,
                    color: palette.primary,
                    size: 52,
                  ),
                )
              : Image.network(
                  url!,
                  fit: BoxFit.cover,
                  loadingBuilder: (_, child, progress) => progress == null
                      ? child
                      : const Center(child: FoodexWaveLoading()),
                  errorBuilder: (_, __, ___) => Center(
                    child: Icon(
                      Icons.inventory_2_outlined,
                      color: palette.primary,
                      size: 52,
                    ),
                  ),
                ),
        ),
      );
}

class FoodexGallery extends StatelessWidget {
  const FoodexGallery({required this.urls, required this.palette, super.key});

  final List<String> urls;
  final FoodexPalette palette;

  @override
  Widget build(BuildContext context) {
    final available = math.max(0.0, MediaQuery.sizeOf(context).width - 30);
    final width = math.min(320.0, math.max(280.0, available * .86));
    return Align(
      alignment: Alignment.center,
      child: SizedBox(
        width: math.min(width, available),
        height: math.min(width, available) * .78,
        child: PageView.builder(
          itemCount: math.max(1, urls.length),
          itemBuilder: (_, index) => Padding(
            padding: const EdgeInsets.symmetric(horizontal: 4),
            child: FoodexProductImage(
              url: urls.isEmpty ? null : urls[index],
              palette: palette,
            ),
          ),
        ),
      ),
    );
  }
}

class FoodexQuantityCta extends StatelessWidget {
  const FoodexQuantityCta({
    required this.quantity,
    required this.increment,
    required this.minimum,
    required this.palette,
    required this.label,
    required this.onChanged,
    required this.onPressed,
    super.key,
  });

  final double quantity;
  final double increment;
  final double minimum;
  final FoodexPalette palette;
  final String label;
  final ValueChanged<double>? onChanged;
  final VoidCallback? onPressed;

  @override
  Widget build(BuildContext context) => Row(
        children: [
          Container(
            height: 52,
            decoration: BoxDecoration(
              color: palette.soft,
              borderRadius: BorderRadius.circular(16),
            ),
            child: Row(
              children: [
                IconButton(
                  onPressed: onChanged != null &&
                          quantity - increment + .0001 >= minimum
                      ? () => onChanged!(quantity - increment)
                      : null,
                  icon: const Icon(Icons.remove_rounded),
                ),
                SizedBox(
                  width: 44,
                  child: Text(
                    compactNumber(quantity),
                    textAlign: TextAlign.center,
                    style: const TextStyle(fontWeight: FontWeight.w800),
                  ),
                ),
                IconButton(
                  onPressed: onChanged == null
                      ? null
                      : () => onChanged!(quantity + increment),
                  icon: const Icon(Icons.add_rounded),
                ),
              ],
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: SizedBox(
              height: 52,
              child: FilledButton(
                style: FilledButton.styleFrom(backgroundColor: palette.primary),
                onPressed: onPressed,
                child: Text(label),
              ),
            ),
          ),
        ],
      );
}

class FoodexLoading extends StatelessWidget {
  const FoodexLoading({super.key});

  @override
  Widget build(BuildContext context) =>
      const Center(child: FoodexWaveLoading());
}

class FoodexErrorState extends StatelessWidget {
  const FoodexErrorState({
    required this.message,
    this.onRetry,
    this.retryLabel = 'إعادة المحاولة',
    this.retryKey,
    super.key,
  });

  final String message;
  final VoidCallback? onRetry;
  final String retryLabel;
  final Key? retryKey;

  @override
  Widget build(BuildContext context) => Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Icon(
                Icons.error_outline_rounded,
                size: 46,
                color: Color(0xFFB42318),
              ),
              const SizedBox(height: 12),
              Text(message, textAlign: TextAlign.center),
              if (onRetry != null) ...[
                const SizedBox(height: 12),
                OutlinedButton.icon(
                  key: retryKey,
                  onPressed: onRetry,
                  icon: const Icon(Icons.refresh_rounded),
                  label: Text(retryLabel),
                ),
              ],
            ],
          ),
        ),
      );
}

class FoodexEmptyState extends StatelessWidget {
  const FoodexEmptyState({
    required this.title,
    required this.subtitle,
    super.key,
  });

  final String title;
  final String subtitle;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.all(16),
        child: Container(
          padding: const EdgeInsets.all(20),
          decoration: BoxDecoration(
            color: Colors.white,
            borderRadius: BorderRadius.circular(18),
            border: Border.all(color: const Color(0xFFE7E8EA)),
          ),
          child: Column(
            children: [
              const Icon(Icons.inbox_outlined,
                  size: 40, color: Color(0xFF6B7785)),
              const SizedBox(height: 10),
              Text(title, style: const TextStyle(fontWeight: FontWeight.w800)),
              const SizedBox(height: 4),
              Text(
                subtitle,
                textAlign: TextAlign.center,
                style: const TextStyle(color: Color(0xFF6B7785)),
              ),
            ],
          ),
        ),
      );
}

class FoodexDetailAccordion extends StatelessWidget {
  const FoodexDetailAccordion({
    required this.title,
    required this.body,
    super.key,
  });

  final String title;
  final String body;

  @override
  Widget build(BuildContext context) => ExpansionTile(
        tilePadding: EdgeInsets.zero,
        childrenPadding: const EdgeInsets.only(bottom: 12),
        title: Text(
          title,
          style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w800),
        ),
        children: [
          Align(
            alignment: AlignmentDirectional.centerStart,
            child: Text(
              body,
              style: const TextStyle(color: Color(0xFF6B7785), height: 1.6),
            ),
          ),
        ],
      );
}

Future<void> showOperationalError(BuildContext context, Object error) =>
    showDialog<void>(
      context: context,
      builder: (dialogContext) => Directionality(
        textDirection: TextDirection.rtl,
        child: AlertDialog(
          icon: const Icon(
            Icons.error_outline_rounded,
            color: Color(0xFFB42318),
          ),
          title: const Text('تعذر تنفيذ العملية'),
          content: Text(error.toString().replaceFirst('Exception: ', '')),
          actions: [
            FilledButton(
              onPressed: () => Navigator.of(dialogContext).pop(),
              child: const Text('حسنًا'),
            ),
          ],
        ),
      ),
    );

List<Map<String, dynamic>> mapRows(Object? value) =>
    (value as List? ?? const <Object>[])
        .whereType<Map>()
        .map((row) => Map<String, dynamic>.from(row))
        .toList(growable: false);

List<Map<String, dynamic>> dataRows(Object? value) {
  if (value is Map && value['data'] is List) {
    return mapRows(value['data']);
  }
  if (value is List) return mapRows(value);
  return const <Map<String, dynamic>>[];
}

int intValue(Object? value) =>
    value is num ? value.toInt() : int.tryParse(value.toString()) ?? 0;

double doubleValue(Object? value, [double fallback = 0]) => value is num
    ? value.toDouble()
    : double.tryParse(value.toString()) ?? fallback;

String compactNumber(double value) => value == value.roundToDouble()
    ? value.toInt().toString()
    : value.toStringAsFixed(2);

String money(Object? value, {String currency = 'EGP'}) {
  if (value == null) return '—';
  return foodexMoneyText(doubleValue(value), currency, fractionDigits: 2);
}

int retailStoreId(String location) {
  final uri = Uri.parse(location);
  final parts = uri.pathSegments;
  final index = parts.indexOf('retail');
  if (index >= 0 && parts.length > index + 1) {
    return int.tryParse(parts[index + 1]) ?? 0;
  }
  return int.tryParse(uri.queryParameters['store'] ?? '') ?? 0;
}

int wholesaleStoreId(String location) {
  final uri = Uri.parse(location);
  return int.tryParse(
        uri.queryParameters['store_id'] ?? uri.queryParameters['store'] ?? '',
      ) ??
      0;
}

int productIdFromLocation(String location) {
  for (final segment in Uri.parse(location).pathSegments.reversed) {
    final id = int.tryParse(segment);
    if (id != null) return id;
  }
  return 0;
}

Color parseHexColor(Object? value, Color fallback) {
  if (value is! String) return fallback;
  final raw = value.replaceAll('#', '').trim();
  final parsed = int.tryParse(raw, radix: 16);
  if (parsed == null) return fallback;
  if (raw.length == 6) return Color(0xFF000000 | parsed);
  if (raw.length == 8) return Color(parsed);
  return fallback;
}
