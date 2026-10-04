// ignore_for_file: prefer_interpolation_to_compose_strings, deprecated_member_use

import 'dart:math' as math;

import 'package:flutter/material.dart';

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
    primary: Color(0xFF078A43),
    primaryDark: Color(0xFF006736),
    accent: Color(0xFFB5F23E),
    background: Color(0xFFF8FBF9),
    soft: Color(0xFFF1F8F4),
    text: Color(0xFF102033),
    muted: Color(0xFF6B7785),
  );

  static const pharmacy = FoodexPalette(
    primary: Color(0xFF0A8DDA),
    primaryDark: Color(0xFF0668A9),
    accent: Color(0xFF37CCFF),
    background: Color(0xFFF8FCFF),
    soft: Color(0xFFEEF8FE),
    text: Color(0xFF102033),
    muted: Color(0xFF6B7785),
  );

  static const wholesale = FoodexPalette(
    primary: Color(0xFF078A43),
    primaryDark: Color(0xFF006736),
    accent: Color(0xFF92D853),
    background: Color(0xFFF8FBF9),
    soft: Color(0xFFF1F8F4),
    text: Color(0xFF102033),
    muted: Color(0xFF6B7785),
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
                style: const TextStyle(
                  fontSize: 18,
                  fontWeight: FontWeight.w800,
                ),
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
    super.key,
  });

  final String title;
  final String action;
  final FoodexPalette palette;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.fromLTRB(16, 14, 16, 9),
        child: Row(
          children: [
            Expanded(
              child: Text(
                title,
                style: TextStyle(
                  color: palette.text,
                  fontSize: 18,
                  fontWeight: FontWeight.w800,
                ),
              ),
            ),
            Text(
              action,
              style: TextStyle(
                color: palette.primary,
                fontSize: 12,
                fontWeight: FontWeight.w700,
              ),
            ),
          ],
        ),
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
  Widget build(BuildContext context) => Container(
        decoration: BoxDecoration(
          color: palette.soft,
          borderRadius: BorderRadius.circular(18),
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
                loadingBuilder: (_, child, progress) =>
                    progress == null ? child : const Center(child: CircularProgressIndicator()),
                errorBuilder: (_, __, ___) => Center(
                  child: Icon(
                    Icons.inventory_2_outlined,
                    color: palette.primary,
                    size: 52,
                  ),
                ),
              ),
      );
}

class FoodexGallery extends StatelessWidget {
  const FoodexGallery({
    required this.urls,
    required this.palette,
    super.key,
  });

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
                  onPressed: onChanged == null ? null : () => onChanged!(quantity + increment),
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
                style: FilledButton.styleFrom(
                  backgroundColor: palette.primary,
                ),
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
      const Center(child: CircularProgressIndicator());
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
              const Icon(
                Icons.inbox_outlined,
                size: 40,
                color: Color(0xFF6B7785),
              ),
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
              style: const TextStyle(
                color: Color(0xFF6B7785),
                height: 1.6,
              ),
            ),
          ),
        ],
      );
}

Future<void> showOperationalError(
  BuildContext context,
  Object error,
) =>
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
          content: Text(
            error.toString().replaceFirst('Exception: ', ''),
          ),
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

double doubleValue(Object? value, [double fallback = 0]) =>
    value is num ? value.toDouble() : double.tryParse(value.toString()) ?? fallback;

String compactNumber(double value) => value == value.roundToDouble()
    ? value.toInt().toString()
    : value.toStringAsFixed(2);

String money(Object? value, {String currency = 'EGP'}) {
  if (value == null) return '—';
  return doubleValue(value).toStringAsFixed(2) + ' ' + currency;
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
