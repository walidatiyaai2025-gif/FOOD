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
  )..repeat(reverse: true);

  @override
  void didUpdateWidget(covariant CustomerGentleMarquee oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.duration != widget.duration) {
      _controller.duration = widget.duration;
      if (!_controller.isAnimating) {
        _controller.repeat(reverse: true);
      }
    }
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final direction = Directionality.of(context);
    final scaler = MediaQuery.textScalerOf(context);

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
            style: widget.style,
          );
        }

        final overflow = painter.width - maxWidth;
        final height = painter.height;

        return SizedBox(
          height: height,
          width: maxWidth,
          child: ClipRect(
            child: AnimatedBuilder(
              animation: _controller,
              child: Text(
                widget.text,
                maxLines: 1,
                softWrap: false,
                overflow: TextOverflow.visible,
                style: widget.style,
              ),
              builder: (context, child) {
                final progress = Curves.easeInOutSine.transform(
                  _controller.value,
                );
                final dx = overflow * progress;
                return Align(
                  alignment: AlignmentDirectional.centerStart,
                  child: Transform.translate(
                    offset: Offset(
                      direction == TextDirection.rtl ? dx : -dx,
                      0,
                    ),
                    child: child,
                  ),
                );
              },
            ),
          ),
        );
      },
    );
  }
}
