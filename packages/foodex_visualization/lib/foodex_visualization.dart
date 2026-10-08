import 'dart:math' as math;
import 'package:flutter/material.dart';

/// Canonical FOODEX visualization palette.
///
/// Business state must never be communicated by color alone. All public chart
/// widgets therefore require a non-empty semanticLabel.
abstract final class FoodexChartPalette {
  static const primary = Color(0xFF158A3A);
  static const primaryDark = Color(0xFF165D2D);
  static const primarySoft = Color(0xFFEAF7EF);
  static const accent = Color(0xFF9BEA4B);
  static const info = Color(0xFF4B8CF5);
  static const warning = Color(0xFFEE731C);
  static const danger = Color(0xFFEF5350);
  static const muted = Color(0xFF667085);
  static const border = Color(0xFFE6EAF0);
  static const surface = Color(0xFFFFFFFF);
  static const ink = Color(0xFF172033);

  static const series = <Color>[
    primary,
    info,
    warning,
    danger,
    muted,
  ];
}

class FoodexTrendChart extends StatelessWidget {
  const FoodexTrendChart({
    super.key,
    required this.values,
    required this.semanticLabel,
    this.height = 180,
    this.area = true,
    this.showGrid = true,
    this.mirrorInRtl = true,
    this.strokeWidth = 2.5,
  }) : assert(semanticLabel != '');

  final List<double> values;
  final String semanticLabel;
  final double height;
  final bool area;
  final bool showGrid;
  final bool mirrorInRtl;
  final double strokeWidth;

  @override
  Widget build(BuildContext context) {
    final rtl = Directionality.of(context) == TextDirection.rtl;
    return Semantics(
      label: semanticLabel,
      image: true,
      child: ExcludeSemantics(
        child: SizedBox(
          height: height,
          width: double.infinity,
          child: CustomPaint(
            painter: _TrendPainter(
              values: values,
              area: area,
              showGrid: showGrid,
              reverseX: mirrorInRtl && rtl,
              strokeWidth: strokeWidth,
            ),
          ),
        ),
      ),
    );
  }
}

class FoodexSparkline extends StatelessWidget {
  const FoodexSparkline({
    super.key,
    required this.values,
    required this.semanticLabel,
    this.height = 42,
    this.mirrorInRtl = true,
  }) : assert(semanticLabel != '');

  final List<double> values;
  final String semanticLabel;
  final double height;
  final bool mirrorInRtl;

  @override
  Widget build(BuildContext context) => FoodexTrendChart(
        values: values,
        semanticLabel: semanticLabel,
        height: height,
        area: false,
        showGrid: false,
        mirrorInRtl: mirrorInRtl,
        strokeWidth: 2,
      );
}

class FoodexBarChart extends StatelessWidget {
  const FoodexBarChart({
    super.key,
    required this.groups,
    required this.semanticLabel,
    this.height = 180,
    this.mirrorInRtl = true,
    this.gap = 8,
  }) : assert(semanticLabel != '');

  /// Each outer entry is one bar. Inner entries are stacked segments.
  final List<List<double>> groups;
  final String semanticLabel;
  final double height;
  final bool mirrorInRtl;
  final double gap;

  @override
  Widget build(BuildContext context) {
    final rtl = Directionality.of(context) == TextDirection.rtl;
    return Semantics(
      label: semanticLabel,
      image: true,
      child: ExcludeSemantics(
        child: SizedBox(
          height: height,
          width: double.infinity,
          child: CustomPaint(
            painter: _BarPainter(
              groups: groups,
              reverseX: mirrorInRtl && rtl,
              gap: gap,
            ),
          ),
        ),
      ),
    );
  }
}

class FoodexDonutChart extends StatelessWidget {
  const FoodexDonutChart({
    super.key,
    required this.values,
    required this.semanticLabel,
    this.size = 156,
    this.strokeWidth = 22,
  }) : assert(semanticLabel != '');

  final List<double> values;
  final String semanticLabel;
  final double size;
  final double strokeWidth;

  @override
  Widget build(BuildContext context) => Semantics(
        label: semanticLabel,
        image: true,
        child: ExcludeSemantics(
          child: SizedBox.square(
            dimension: size,
            child: CustomPaint(
              painter: _DonutPainter(
                values: values,
                strokeWidth: strokeWidth,
              ),
            ),
          ),
        ),
      );
}

class FoodexProgressRange extends StatelessWidget {
  const FoodexProgressRange({
    super.key,
    required this.value,
    required this.semanticLabel,
    this.reorderAt,
    this.criticalAt,
    this.height = 12,
  })  : assert(semanticLabel != ''),
        assert(value >= 0 && value <= 1),
        assert(reorderAt == null || (reorderAt >= 0 && reorderAt <= 1)),
        assert(criticalAt == null || (criticalAt >= 0 && criticalAt <= 1));

  final double value;
  final String semanticLabel;
  final double? reorderAt;
  final double? criticalAt;
  final double height;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      label: semanticLabel,
      value: ((value * 100).round()).toString() + '%',
      child: ExcludeSemantics(
        child: LayoutBuilder(
          builder: (context, constraints) {
            final width = constraints.maxWidth;
            return SizedBox(
              height: math.max(height, 18),
              child: Stack(
                alignment: Alignment.centerLeft,
                children: [
                  ClipRRect(
                    borderRadius: BorderRadius.circular(999),
                    child: LinearProgressIndicator(
                      minHeight: height,
                      value: value,
                      color: FoodexChartPalette.primary,
                      backgroundColor: FoodexChartPalette.primarySoft,
                    ),
                  ),
                  if (reorderAt != null)
                    _RangeMarker(
                      logicalOffset: width * reorderAt!,
                      color: FoodexChartPalette.warning,
                      height: height + 6,
                    ),
                  if (criticalAt != null)
                    _RangeMarker(
                      logicalOffset: width * criticalAt!,
                      color: FoodexChartPalette.danger,
                      height: height + 6,
                    ),
                ],
              ),
            );
          },
        ),
      ),
    );
  }
}

class FoodexHeatmap extends StatelessWidget {
  const FoodexHeatmap({
    super.key,
    required this.values,
    required this.semanticLabel,
    this.cellGap = 4,
    this.cellRadius = 6,
    this.minCellSize = 22,
  }) : assert(semanticLabel != '');

  final List<List<double>> values;
  final String semanticLabel;
  final double cellGap;
  final double cellRadius;
  final double minCellSize;

  @override
  Widget build(BuildContext context) {
    final flat = values.expand((row) => row).toList(growable: false);
    final maxValue = flat.isEmpty ? 0.0 : flat.reduce((a, b) => a > b ? a : b);
    return Semantics(
      label: semanticLabel,
      image: true,
      child: ExcludeSemantics(
        child: SingleChildScrollView(
          scrollDirection: Axis.horizontal,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              for (final row in values)
                Padding(
                  padding: EdgeInsets.only(bottom: cellGap),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      for (final value in row)
                        Padding(
                          padding: EdgeInsetsDirectional.only(end: cellGap),
                          child: Container(
                            width: minCellSize,
                            height: minCellSize,
                            decoration: BoxDecoration(
                              color: _heatColor(value, maxValue),
                              borderRadius: BorderRadius.circular(cellRadius),
                              border: Border.all(
                                color: FoodexChartPalette.border,
                                width: .5,
                              ),
                            ),
                          ),
                        ),
                    ],
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }

  static Color _heatColor(double value, double maxValue) {
    if (maxValue <= 0 || value <= 0) {
      return FoodexChartPalette.primarySoft.withValues(alpha: .34);
    }
    final normalized = (value / maxValue).clamp(0.0, 1.0);
    return Color.lerp(
      FoodexChartPalette.primarySoft,
      FoodexChartPalette.primaryDark,
      normalized,
    )!;
  }
}

class FoodexChartLegend extends StatelessWidget {
  const FoodexChartLegend({
    super.key,
    required this.labels,
    this.colors = FoodexChartPalette.series,
    this.spacing = 12,
  }) : assert(colors.length > 0);

  final List<String> labels;
  final List<Color> colors;
  final double spacing;

  @override
  Widget build(BuildContext context) {
    return Wrap(
      spacing: spacing,
      runSpacing: 8,
      children: [
        for (var index = 0; index < labels.length; index++)
          Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Container(
                width: 10,
                height: 10,
                decoration: BoxDecoration(
                  color: colors[index % colors.length],
                  borderRadius: BorderRadius.circular(3),
                ),
              ),
              const SizedBox(width: 6),
              Text(labels[index]),
            ],
          ),
      ],
    );
  }
}

class _RangeMarker extends StatelessWidget {
  const _RangeMarker({
    required this.logicalOffset,
    required this.color,
    required this.height,
  });

  final double logicalOffset;
  final Color color;
  final double height;

  @override
  Widget build(BuildContext context) => PositionedDirectional(
        start: (logicalOffset - 1).clamp(0.0, double.infinity).toDouble(),
        child: Container(width: 2, height: height, color: color),
      );
}

class _TrendPainter extends CustomPainter {
  const _TrendPainter({
    required this.values,
    required this.area,
    required this.showGrid,
    required this.reverseX,
    required this.strokeWidth,
  });

  final List<double> values;
  final bool area;
  final bool showGrid;
  final bool reverseX;
  final double strokeWidth;

  @override
  void paint(Canvas canvas, Size size) {
    if (showGrid) {
      final grid = Paint()
        ..color = FoodexChartPalette.border
        ..strokeWidth = 1;
      for (var i = 1; i <= 3; i++) {
        final y = size.height * i / 4;
        canvas.drawLine(Offset(0, y), Offset(size.width, y), grid);
      }
    }
    if (values.isEmpty) return;

    final minValue = values.reduce((a, b) => a < b ? a : b);
    final maxValue = values.reduce((a, b) => a > b ? a : b);
    final range = maxValue == minValue ? 1.0 : maxValue - minValue;
    final path = Path();

    for (var i = 0; i < values.length; i++) {
      final logicalX = values.length == 1 ? .5 : i / (values.length - 1);
      final x = size.width * (reverseX ? 1 - logicalX : logicalX);
      final normalized = (values[i] - minValue) / range;
      final y = size.height - (normalized * (size.height - 8)) - 4;
      if (i == 0) {
        path.moveTo(x, y);
      } else {
        path.lineTo(x, y);
      }
    }

    if (area && values.length > 1) {
      final areaPath = Path.from(path);
      final firstX = reverseX ? size.width : 0.0;
      final lastX = reverseX ? 0.0 : size.width;
      areaPath
        ..lineTo(lastX, size.height)
        ..lineTo(firstX, size.height)
        ..close();
      canvas.drawPath(
        areaPath,
        Paint()
          ..color = FoodexChartPalette.primary.withValues(alpha: .12)
          ..style = PaintingStyle.fill,
      );
    }

    canvas.drawPath(
      path,
      Paint()
        ..color = FoodexChartPalette.primary
        ..style = PaintingStyle.stroke
        ..strokeCap = StrokeCap.round
        ..strokeJoin = StrokeJoin.round
        ..strokeWidth = strokeWidth,
    );
  }

  @override
  bool shouldRepaint(covariant _TrendPainter oldDelegate) =>
      oldDelegate.values != values ||
      oldDelegate.area != area ||
      oldDelegate.showGrid != showGrid ||
      oldDelegate.reverseX != reverseX ||
      oldDelegate.strokeWidth != strokeWidth;
}

class _BarPainter extends CustomPainter {
  const _BarPainter({
    required this.groups,
    required this.reverseX,
    required this.gap,
  });

  final List<List<double>> groups;
  final bool reverseX;
  final double gap;

  @override
  void paint(Canvas canvas, Size size) {
    if (groups.isEmpty) return;
    final totals = groups
        .map((group) => group.fold<double>(
            0, (sum, value) => sum + (value > 0 ? value : 0.0)))
        .toList(growable: false);
    final maxTotal = totals.isEmpty ? 0.0 : totals.reduce((a, b) => a > b ? a : b);
    if (maxTotal <= 0) return;

    final count = groups.length;
    final available = (size.width - gap * (count - 1)).clamp(0.0, double.infinity).toDouble();
    final barWidth = (available / count).clamp(2.0, double.infinity).toDouble();

    for (var logicalIndex = 0; logicalIndex < count; logicalIndex++) {
      final index = reverseX ? count - 1 - logicalIndex : logicalIndex;
      final x = logicalIndex * (barWidth + gap);
      var bottom = size.height;

      for (var segmentIndex = 0;
          segmentIndex < groups[index].length;
          segmentIndex++) {
        final rawValue = groups[index][segmentIndex];
        final value = rawValue > 0 ? rawValue : 0.0;
        final segmentHeight = size.height * value / maxTotal;
        final rect = RRect.fromRectAndRadius(
          Rect.fromLTWH(x, bottom - segmentHeight, barWidth, segmentHeight),
          const Radius.circular(3),
        );
        canvas.drawRRect(
          rect,
          Paint()
            ..color = FoodexChartPalette
                .series[segmentIndex % FoodexChartPalette.series.length],
        );
        bottom -= segmentHeight;
      }
    }
  }

  @override
  bool shouldRepaint(covariant _BarPainter oldDelegate) =>
      oldDelegate.groups != groups ||
      oldDelegate.reverseX != reverseX ||
      oldDelegate.gap != gap;
}

class _DonutPainter extends CustomPainter {
  const _DonutPainter({
    required this.values,
    required this.strokeWidth,
  });

  final List<double> values;
  final double strokeWidth;

  @override
  void paint(Canvas canvas, Size size) {
    final positive = values.map((value) => value > 0 ? value : 0.0).toList();
    final total = positive.fold<double>(0, (sum, value) => sum + value);
    final center = size.center(Offset.zero);
    final radius = (math.min(size.width, size.height) / 2 - strokeWidth / 2)
        .clamp(0.0, double.infinity)
        .toDouble();
    final rect = Rect.fromCircle(center: center, radius: radius);

    if (total <= 0) {
      canvas.drawArc(
        rect,
        0,
        math.pi * 2,
        false,
        Paint()
          ..color = FoodexChartPalette.border
          ..style = PaintingStyle.stroke
          ..strokeWidth = strokeWidth,
      );
      return;
    }

    var start = -math.pi / 2;
    for (var index = 0; index < positive.length; index++) {
      final sweep = math.pi * 2 * positive[index] / total;
      canvas.drawArc(
        rect,
        start,
        sweep,
        false,
        Paint()
          ..color = FoodexChartPalette
              .series[index % FoodexChartPalette.series.length]
          ..style = PaintingStyle.stroke
          ..strokeCap = StrokeCap.butt
          ..strokeWidth = strokeWidth,
      );
      start += sweep;
    }
  }

  @override
  bool shouldRepaint(covariant _DonutPainter oldDelegate) =>
      oldDelegate.values != values || oldDelegate.strokeWidth != strokeWidth;
}
