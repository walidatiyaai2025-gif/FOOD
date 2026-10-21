import 'dart:math' as math;

import 'package:flutter/material.dart';

/// FOODEX-branded replacement for circular loading spinners.
///
/// In roomy layouts it renders the FOODEX wordmark with an animated green
/// gradient and flowing waves. In tight controls (buttons/icons) it
/// automatically collapses to the animated green wave mark, so no circular
/// spinner remains in the customer app.
class FoodexWaveLoading extends StatefulWidget {
  const FoodexWaveLoading({super.key});

  @override
  State<FoodexWaveLoading> createState() => _FoodexWaveLoadingState();
}

class _FoodexWaveLoadingState extends State<FoodexWaveLoading>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 1800),
  )..repeat();

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final width = constraints.maxWidth;
        final height = constraints.maxHeight;
        final compact = (width.isFinite && width <= 72) ||
            (height.isFinite && height <= 72);

        return AnimatedBuilder(
          animation: _controller,
          builder: (context, child) {
            if (compact) {
              return CustomPaint(
                painter: _FoodexCompactWavePainter(_controller.value),
                child: const SizedBox.expand(),
              );
            }

            final progress = _controller.value;
            final bob = math.sin(progress * math.pi * 2) * 2.5;

            return Center(
              child: SizedBox(
                width: 230,
                height: 118,
                child: Stack(
                  alignment: Alignment.center,
                  children: <Widget>[
                    Positioned(
                      left: 18,
                      right: 18,
                      bottom: 14,
                      height: 50,
                      child: CustomPaint(
                        painter: _FoodexWavePainter(progress),
                      ),
                    ),
                    Transform.translate(
                      offset: Offset(0, bob - 15),
                      child: ShaderMask(
                        blendMode: BlendMode.srcIn,
                        shaderCallback: (bounds) {
                          final travel = progress * 2.8;
                          return LinearGradient(
                            begin: Alignment(-1.7 + travel, -0.5),
                            end: Alignment(0.3 + travel, 0.7),
                            colors: const <Color>[
                              Color(0xFF063B29),
                              Color(0xFF087044),
                              Color(0xFF13A662),
                              Color(0xFF7CD259),
                              Color(0xFF0B7448),
                            ],
                            stops: const <double>[
                              0.00,
                              0.24,
                              0.48,
                              0.72,
                              1.00,
                            ],
                          ).createShader(bounds);
                        },
                        child: const Text(
                          'FOODEX',
                          textAlign: TextAlign.center,
                          style: TextStyle(
                            color: Colors.white,
                            fontSize: 38,
                            height: 1,
                            fontWeight: FontWeight.w900,
                            letterSpacing: 4.2,
                          ),
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            );
          },
        );
      },
    );
  }
}

class _FoodexWavePainter extends CustomPainter {
  const _FoodexWavePainter(this.progress);

  final double progress;

  static const _colors = <Color>[
    Color(0xFF0A6643),
    Color(0xFF18A968),
    Color(0xFF79CF59),
  ];

  @override
  void paint(Canvas canvas, Size size) {
    for (var index = 0; index < _colors.length; index++) {
      final path = Path();
      final phase = (progress * math.pi * 2) + (index * math.pi / 2.4);
      final amplitude = 6.0 - (index * 1.4);
      final baseline = (size.height * 0.38) + (index * 7.0);

      for (double x = 0; x <= size.width; x += 2) {
        final normalized = x / size.width;
        final y = baseline +
            math.sin((normalized * math.pi * 2.4) + phase) * amplitude;

        if (x == 0) {
          path.moveTo(x, y);
        } else {
          path.lineTo(x, y);
        }
      }

      canvas.drawPath(
        path,
        Paint()
          ..style = PaintingStyle.stroke
          ..strokeWidth = 2.3 - (index * 0.35)
          ..strokeCap = StrokeCap.round
          ..color = _colors[index].withValues(
            alpha: 0.36 - (index * 0.07),
          ),
      );
    }
  }

  @override
  bool shouldRepaint(covariant _FoodexWavePainter oldDelegate) =>
      oldDelegate.progress != progress;
}

class _FoodexCompactWavePainter extends CustomPainter {
  const _FoodexCompactWavePainter(this.progress);

  final double progress;

  @override
  void paint(Canvas canvas, Size size) {
    if (size.width <= 0 || size.height <= 0) return;

    final stroke = math.max(1.2, math.min(size.width, size.height) * 0.09);
    final rect = Offset.zero & size;
    final shader = const LinearGradient(
      begin: Alignment.centerLeft,
      end: Alignment.centerRight,
      colors: <Color>[
        Color(0xFF075B3C),
        Color(0xFF13A662),
        Color(0xFF7CD259),
      ],
    ).createShader(rect);

    for (var row = 0; row < 3; row++) {
      final path = Path();
      final phase = (progress * math.pi * 2) + (row * math.pi / 2.2);
      final baseline = size.height * (0.34 + row * 0.16);
      final amplitude = math.max(1.0, size.height * (0.08 - row * 0.012));

      for (double x = 0; x <= size.width; x += 1) {
        final normalized = x / size.width;
        final y = baseline +
            math.sin((normalized * math.pi * 2.2) + phase) * amplitude;
        if (x == 0) {
          path.moveTo(x, y);
        } else {
          path.lineTo(x, y);
        }
      }

      canvas.drawPath(
        path,
        Paint()
          ..style = PaintingStyle.stroke
          ..strokeWidth = stroke
          ..strokeCap = StrokeCap.round
          ..shader = shader
          ..color = Colors.white.withValues(alpha: 0.95 - row * 0.12),
      );
    }
  }

  @override
  bool shouldRepaint(covariant _FoodexCompactWavePainter oldDelegate) =>
      oldDelegate.progress != progress;
}
