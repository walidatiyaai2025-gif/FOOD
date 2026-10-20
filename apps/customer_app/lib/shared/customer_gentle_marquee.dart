import 'dart:async';

import 'package:flutter/material.dart';

class CustomerGentleMarquee extends StatefulWidget {
  const CustomerGentleMarquee({
    required this.text,
    required this.style,
    this.duration = const Duration(seconds: 9),
    super.key,
  });

  final String text;
  final TextStyle? style;
  final Duration duration;

  @override
  State<CustomerGentleMarquee> createState() => _CustomerGentleMarqueeState();
}

class _CustomerGentleMarqueeState extends State<CustomerGentleMarquee>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller = AnimationController(
    vsync: this,
    duration: widget.duration,
    value: 0,
  );

  Timer? _startTimer;

  @override
  void initState() {
    super.initState();
    _restartFromBeginning();
  }

  void _restartFromBeginning() {
    _startTimer?.cancel();
    _controller
      ..stop()
      ..value = 0;

    // Keep the beginning of the product name fully visible before it moves.
    _startTimer = Timer(const Duration(milliseconds: 1800), () {
      if (!mounted) return;
      _controller.repeat(reverse: true);
    });
  }

  @override
  void didUpdateWidget(covariant CustomerGentleMarquee oldWidget) {
    super.didUpdateWidget(oldWidget);

    if (oldWidget.duration != widget.duration) {
      _controller.duration = widget.duration;
    }

    if (oldWidget.text != widget.text) {
      _restartFromBeginning();
    }
  }

  @override
  void dispose() {
    _startTimer?.cancel();
    _controller.dispose();
    super.dispose();
  }

  TextDirection _textDirectionFor(String text, TextDirection fallback) {
    for (final rune in text.runes) {
      final isArabic = (rune >= 0x0600 && rune <= 0x06FF) ||
          (rune >= 0x0750 && rune <= 0x077F) ||
          (rune >= 0x08A0 && rune <= 0x08FF);
      if (isArabic) return TextDirection.rtl;

      final isLatinUpper = rune >= 0x41 && rune <= 0x5A;
      final isLatinLower = rune >= 0x61 && rune <= 0x7A;
      if (isLatinUpper || isLatinLower) return TextDirection.ltr;
    }
    return fallback;
  }

  double _motionProgress(double value) {
    const hold = .14;
    if (value <= hold) return 0;
    if (value >= 1 - hold) return 1;
    final normalized = (value - hold) / (1 - (hold * 2));
    return Curves.easeInOutSine.transform(normalized);
  }

  @override
  Widget build(BuildContext context) {
    final fallbackDirection = Directionality.of(context);
    final direction = _textDirectionFor(widget.text, fallbackDirection);
    final scaler = MediaQuery.textScalerOf(context);
    final devicePixelRatio = MediaQuery.devicePixelRatioOf(context);

    return LayoutBuilder(
      builder: (context, constraints) {
        final painter = TextPainter(
          text: TextSpan(text: widget.text, style: widget.style),
          textDirection: direction,
          textScaler: scaler,
          maxLines: 1,
        )..layout();

        final maxWidth = constraints.maxWidth;
        if (!maxWidth.isFinite || painter.width <= maxWidth + .5) {
          return Text(
            widget.text,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            textDirection: direction,
            textAlign: TextAlign.start,
            style: widget.style,
          );
        }

        final textWidth = painter.width;
        final overflow = textWidth - maxWidth;
        final height = painter.height;

        return SizedBox(
          height: height,
          width: maxWidth,
          child: ClipRect(
            child: AnimatedBuilder(
              animation: _controller,
              builder: (context, _) {
                final rawOffset =
                    overflow * _motionProgress(_controller.value);
                final offset = (rawOffset * devicePixelRatio).round() /
                    devicePixelRatio;

                // Position the full line at its logical START edge first:
                // Arabic starts at the right edge, English at the left edge.
                // The movement only begins after the initial 1.8s hold.
                return Stack(
                  clipBehavior: Clip.hardEdge,
                  children: [
                    Positioned(
                      top: 0,
                      bottom: 0,
                      left: direction == TextDirection.ltr ? -offset : null,
                      right: direction == TextDirection.rtl ? -offset : null,
                      child: RepaintBoundary(
                        child: SizedBox(
                          width: textWidth,
                          height: height,
                          child: Text(
                            widget.text,
                            maxLines: 1,
                            softWrap: false,
                            overflow: TextOverflow.visible,
                            textDirection: direction,
                            textAlign: TextAlign.start,
                            style: widget.style,
                          ),
                        ),
                      ),
                    ),
                  ],
                );
              },
            ),
          ),
        );
      },
    );
  }
}
