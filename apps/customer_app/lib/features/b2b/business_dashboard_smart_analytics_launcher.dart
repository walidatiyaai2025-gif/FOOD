import 'package:flutter/material.dart';

import '../../core/theme/customer_ui_v3_tokens.dart';

class BusinessDashboardSmartAnalyticsLauncher extends StatefulWidget {
  const BusinessDashboardSmartAnalyticsLauncher({
    required this.semanticLabel,
    required this.hasNewInsights,
    required this.onTap,
    super.key,
  });

  final String semanticLabel;
  final bool hasNewInsights;
  final VoidCallback onTap;

  @override
  State<BusinessDashboardSmartAnalyticsLauncher> createState() =>
      _BusinessDashboardSmartAnalyticsLauncherState();
}

class _BusinessDashboardSmartAnalyticsLauncherState
    extends State<BusinessDashboardSmartAnalyticsLauncher>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 1400),
  );
  late final Animation<double> _pulse = CurvedAnimation(
    parent: _controller,
    curve: CustomerUiMotion.emphasisCurve,
  );
  bool _animating = false;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    _syncMotion();
  }

  @override
  void didUpdateWidget(
    covariant BusinessDashboardSmartAnalyticsLauncher oldWidget,
  ) {
    super.didUpdateWidget(oldWidget);
    _syncMotion();
  }

  void _syncMotion() {
    final shouldAnimate =
        widget.hasNewInsights && !CustomerUiMotion.isReduced(context);
    if (_animating == shouldAnimate) {
      if (!shouldAnimate) {
        _controller.value = widget.hasNewInsights ? 1 : 0;
      }
      return;
    }

    _animating = shouldAnimate;
    if (shouldAnimate) {
      _controller.repeat(reverse: true);
    } else {
      _controller
        ..stop()
        ..value = widget.hasNewInsights ? 1 : 0;
    }
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final compact = MediaQuery.sizeOf(context).width <= 340;
    final orbSize = compact ? 44.0 : 48.0;
    final reducedMotion = CustomerUiMotion.isReduced(context);

    return Semantics(
      button: true,
      label: widget.semanticLabel,
      child: Tooltip(
        message: widget.semanticLabel,
        child: SizedBox(
          key: const ValueKey('business-smart-analytics-launcher-target'),
          width: 52,
          height: 52,
          child: Stack(
            clipBehavior: Clip.none,
            alignment: Alignment.center,
            children: [
              AnimatedBuilder(
                animation: _pulse,
                builder: (context, child) {
                  final emphasis = widget.hasNewInsights
                      ? (reducedMotion ? 1.0 : _pulse.value)
                      : 0.0;
                  final scale = reducedMotion ? 1.0 : 1.0 + emphasis * 0.025;
                  return Transform.scale(
                    key: const ValueKey(
                      'business-smart-analytics-launcher-pulse',
                    ),
                    scale: scale,
                    child: DecoratedBox(
                      decoration: BoxDecoration(
                        shape: BoxShape.circle,
                        boxShadow: [
                          BoxShadow(
                            color: CustomerUiColors.deepGreen.withValues(
                              alpha: 0.18 + (emphasis * 0.18),
                            ),
                            blurRadius: 8 + (emphasis * 10),
                            spreadRadius: emphasis * 2,
                            offset: const Offset(0, 5),
                          ),
                        ],
                      ),
                      child: child,
                    ),
                  );
                },
                child: Material(
                  color: Colors.transparent,
                  shape: const CircleBorder(),
                  clipBehavior: Clip.antiAlias,
                  child: InkWell(
                    key: const ValueKey('business-smart-analytics-launcher'),
                    customBorder: const CircleBorder(),
                    onTap: widget.onTap,
                    child: SizedBox(
                      width: orbSize,
                      height: orbSize,
                      child: DecoratedBox(
                        decoration: const BoxDecoration(
                          shape: BoxShape.circle,
                          gradient: LinearGradient(
                            begin: Alignment.topLeft,
                            end: Alignment.bottomRight,
                            colors: [
                              CustomerUiColors.lime,
                              CustomerUiColors.deepGreenSoft,
                              CustomerUiColors.deepGreen,
                            ],
                            stops: [0, 0.46, 1],
                          ),
                        ),
                        child: Stack(
                          alignment: Alignment.center,
                          children: [
                            Positioned(
                              top: 6,
                              left: 9,
                              right: 9,
                              child: Container(
                                height: 9,
                                decoration: BoxDecoration(
                                  borderRadius: BorderRadius.circular(999),
                                  gradient: LinearGradient(
                                    colors: [
                                      CustomerUiColors.white.withValues(
                                        alpha: 0.62,
                                      ),
                                      CustomerUiColors.white.withValues(
                                        alpha: 0.04,
                                      ),
                                    ],
                                  ),
                                ),
                              ),
                            ),
                            const Icon(
                              Icons.bar_chart_rounded,
                              size: 25,
                              color: CustomerUiColors.white,
                            ),
                            const Positioned(
                              top: 9,
                              right: 8,
                              child: Icon(
                                Icons.auto_awesome_rounded,
                                size: 10,
                                color: CustomerUiColors.white,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
                  ),
                ),
              ),
              if (widget.hasNewInsights)
                PositionedDirectional(
                  top: 1,
                  end: 0,
                  child: Container(
                    key: const ValueKey(
                      'business-smart-analytics-launcher-badge',
                    ),
                    width: 11,
                    height: 11,
                    decoration: BoxDecoration(
                      color: CustomerUiColors.destructive,
                      shape: BoxShape.circle,
                      border: Border.all(
                        color: CustomerUiColors.white,
                        width: CustomerUiStroke.emphasis,
                      ),
                    ),
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}


class BusinessDashboardStoreLogo extends StatelessWidget {
  const BusinessDashboardStoreLogo({
    required this.storeName,
    this.logoUrl,
    this.imageProvider,
    super.key,
  });

  final String storeName;
  final String? logoUrl;
  final ImageProvider<Object>? imageProvider;

  Widget _fallback() {
    final normalizedName = storeName.trim();
    final initial =
        normalizedName.isEmpty ? 'F' : normalizedName.substring(0, 1).toUpperCase();
    return Container(
      key: const ValueKey('b2b-dashboard-store-logo-fallback'),
      width: 64,
      height: 64,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        color: CustomerUiColors.mint,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: CustomerUiColors.border),
      ),
      child: Text(
        initial,
        style: const TextStyle(
          color: CustomerUiColors.deepGreen,
          fontSize: 24,
          fontWeight: FontWeight.w900,
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final normalizedUrl = logoUrl?.trim() ?? '';
    final provider =
        imageProvider ?? (normalizedUrl.isEmpty ? null : NetworkImage(normalizedUrl));
    if (provider == null) return _fallback();

    return Container(
      key: const ValueKey('b2b-dashboard-store-logo'),
      width: 64,
      height: 64,
      padding: const EdgeInsets.all(4),
      decoration: BoxDecoration(
        color: CustomerUiColors.white,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: CustomerUiColors.border),
      ),
      child: ClipRRect(
        borderRadius: BorderRadius.circular(12),
        child: Image(
          image: provider,
          fit: BoxFit.contain,
          errorBuilder: (_, __, ___) => _fallback(),
        ),
      ),
    );
  }
}
