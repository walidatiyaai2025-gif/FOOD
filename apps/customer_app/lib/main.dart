import 'dart:async';
import 'dart:math' as math;

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';

import 'app.dart';
import 'core/auth/customer_auth_persistence.dart';
import 'core/auth/customer_session.dart';
import 'core/auth/customer_session_store.dart';
import 'core/config/foodex_environment.dart';
import 'core/config/mobile_runtime_visibility.dart';
import 'core/diagnostics/customer_diagnostics.dart';
import 'core/network/customer_data_mode.dart';
import 'core/push/firebase_push_service.dart';
import 'core/routing/customer_routes.dart';
import 'core/theme/customer_ui_v3_tokens.dart';

const _startupTaskTimeout = Duration(seconds: 3);
const _pushBootstrapTimeout = Duration(seconds: 10);

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  final startup = Stopwatch()..start();

  final diagnostics = CustomerDiagnostics.instance;

  final previousFlutterHandler = FlutterError.onError;
  FlutterError.onError = (details) {
    diagnostics.recordFlutterError(details);
    if (previousFlutterHandler != null) {
      previousFlutterHandler(details);
    } else {
      FlutterError.presentError(details);
    }
  };

  final previousPlatformHandler = PlatformDispatcher.instance.onError;
  PlatformDispatcher.instance.onError = (error, stack) {
    diagnostics.recordDartError(error, stack);
    return previousPlatformHandler?.call(error, stack) ?? false;
  };

  final sessionStore = SecureCustomerSessionStore();
  final authPreferenceStore = SecureCustomerAuthPreferenceStore();

  // The first Flutter frame must never wait for plugin/platform storage.
  // Android keeps the native launch splash visible until Flutter renders.
  runApp(
    _CustomerRuntimeBootstrap(
      bootstrap: const CustomerAuthBootstrap(
        session: CustomerSession.guest(),
        preferences: CustomerAuthPreferences(),
      ),
      sessionStore: sessionStore,
      authPreferenceStore: authPreferenceStore,
      diagnostics: diagnostics,
      startup: startup,
    ),
  );
}

class _CustomerRuntimeBootstrap extends StatefulWidget {
  const _CustomerRuntimeBootstrap({
    required this.bootstrap,
    required this.sessionStore,
    required this.authPreferenceStore,
    required this.diagnostics,
    required this.startup,
  });

  final CustomerAuthBootstrap bootstrap;
  final CustomerSessionStore sessionStore;
  final CustomerAuthPreferenceStore authPreferenceStore;
  final CustomerDiagnostics diagnostics;
  final Stopwatch startup;

  @override
  State<_CustomerRuntimeBootstrap> createState() =>
      _CustomerRuntimeBootstrapState();
}

class _CustomerRuntimeBootstrapState extends State<_CustomerRuntimeBootstrap> {
  late CustomerAuthBootstrap _bootstrap;
  CustomerFirebasePushService? _pushService;
  bool _runtimeReady = false;
  bool _startupMarkedUsable = false;
  bool _showPersistentFooter = true;

  @override
  void initState() {
    super.initState();
    _bootstrap = widget.bootstrap;
    unawaited(_initializeDeferredRuntime());
  }

  Future<void> _initializeDeferredRuntime() async {
    final authFuture = _restoreAuthBootstrap();
    await Future.wait<void>([
      _initializeDiagnostics(),
      _initializeDataMode(),
    ]);
    final restoredBootstrap = await authFuture;

    if (!mounted) return;
    setState(() {
      _bootstrap = restoredBootstrap;
      _runtimeReady = true;
    });

    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      _markStartupUsable();
      unawaited(_loadRuntimeVisibility());
      unawaited(_bootstrapPush());
    });
  }

  Future<void> _initializeDiagnostics() async {
    try {
      await widget.diagnostics.initialize().timeout(_startupTaskTimeout);
    } on TimeoutException {
      widget.diagnostics.record('diagnostics_startup_timeout', {
        'timeout_ms': _startupTaskTimeout.inMilliseconds,
      });
    } catch (error) {
      widget.diagnostics.record('diagnostics_startup_error', {
        'error_type': error.runtimeType.toString(),
      });
    }
  }

  Future<void> _initializeDataMode() async {
    try {
      await CustomerDataModeController.instance
          .initialize()
          .timeout(_startupTaskTimeout);
    } on TimeoutException {
      widget.diagnostics.record('data_mode_startup_timeout', {
        'timeout_ms': _startupTaskTimeout.inMilliseconds,
      });
    } catch (error) {
      widget.diagnostics.record('data_mode_startup_error', {
        'error_type': error.runtimeType.toString(),
      });
    }
  }

  Future<CustomerAuthBootstrap> _restoreAuthBootstrap() async {
    try {
      return await restoreCustomerAuthBootstrap(
        sessionStore: widget.sessionStore,
        preferenceStore: widget.authPreferenceStore,
      ).timeout(_startupTaskTimeout);
    } on TimeoutException {
      widget.diagnostics.record('session_restore_timeout', {
        'timeout_ms': _startupTaskTimeout.inMilliseconds,
      });
    } catch (error) {
      widget.diagnostics.record('session_restore_error', {
        'error_type': error.runtimeType.toString(),
      });
    }
    return widget.bootstrap;
  }

  void _markStartupUsable() {
    if (_startupMarkedUsable) return;
    _startupMarkedUsable = true;
    widget.startup.stop();
    widget.diagnostics.markStartupUsable(widget.startup.elapsed);
    widget.diagnostics.record('startup_usable', {
      'elapsed_ms': widget.startup.elapsedMilliseconds,
    });
  }

  Future<void> _loadRuntimeVisibility() async {
    try {
      final visibility = await MobileRuntimeVisibility.fetch(
        baseUrl: FoodexEnvironment.apiBaseUrl,
        locale: 'ar',
      );
      if (!mounted) return;
      setState(() => _showPersistentFooter = visibility.showPersistentFooter);
    } catch (error) {
      widget.diagnostics.record('runtime_visibility_deferred_error', {
        'error_type': error.runtimeType.toString(),
      });
    }
  }

  Future<void> _bootstrapPush() async {
    try {
      final service = await CustomerFirebasePushService.bootstrap()
          .timeout(_pushBootstrapTimeout);
      if (!mounted) {
        await service.dispose();
        return;
      }
      setState(() => _pushService = service);
    } on TimeoutException {
      widget.diagnostics.record('push_bootstrap_timeout', {
        'timeout_ms': _pushBootstrapTimeout.inMilliseconds,
      });
    } catch (error, stack) {
      widget.diagnostics.record('push_bootstrap_error', {
        'error': error.toString(),
        'stack': stack.toString(),
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    if (!_runtimeReady) {
      return const _FoodexWaveLoading();
    }

    return FoodexCustomerApp(
      session: _bootstrap.session,
      initialRoute: CustomerRoutePaths.entry,
      sessionStore: widget.sessionStore,
      authPreferences: _bootstrap.preferences,
      authPreferenceStore: widget.authPreferenceStore,
      biometricAuthenticator: LocalAuthCustomerBiometricAuthenticator(),
      pushService: _pushService,
      showPersistentFooter: _showPersistentFooter,
    );
  }
}


class _FoodexWaveLoading extends StatefulWidget {
  const _FoodexWaveLoading();

  @override
  State<_FoodexWaveLoading> createState() => _FoodexWaveLoadingState();
}

class _FoodexWaveLoadingState extends State<_FoodexWaveLoading>
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
    return Directionality(
      textDirection: TextDirection.ltr,
      child: DecoratedBox(
        decoration: const BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
            colors: <Color>[
              Color(0xFFF8FCF9),
              Color(0xFFEAF7EF),
            ],
          ),
        ),
        child: Center(
          child: AnimatedBuilder(
            animation: _controller,
            builder: (context, child) {
              final progress = _controller.value;
              final bob =
                  math.sin(progress * math.pi * 2) * 2.5;

              return SizedBox(
                width: 300,
                height: 170,
                child: Stack(
                  alignment: Alignment.center,
                  children: <Widget>[
                    Positioned(
                      left: 26,
                      right: 26,
                      bottom: 30,
                      height: 72,
                      child: CustomPaint(
                        painter: _FoodexWavePainter(progress),
                      ),
                    ),
                    Transform.translate(
                      offset: Offset(0, bob - 18),
                      child: ShaderMask(
                        blendMode: BlendMode.srcIn,
                        shaderCallback: (bounds) {
                          final travel = progress * 2.8;
                          return LinearGradient(
                            begin: Alignment(-1.7 + travel, -0.5),
                            end: Alignment(0.3 + travel, 0.7),
                            colors: const <Color>[
                              Color(0xFF083F2D),
                              Color(0xFF0B6B46),
                              Color(0xFF16A765),
                              Color(0xFF87D85D),
                              Color(0xFF0C7A4C),
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
                          key: ValueKey('foodex-wave-loading-wordmark'),
                          textAlign: TextAlign.center,
                          style: TextStyle(
                            color: Colors.white,
                            fontSize: 48,
                            height: 1,
                            fontWeight: FontWeight.w900,
                            letterSpacing: 5.2,
                          ),
                        ),
                      ),
                    ),
                  ],
                ),
              );
            },
          ),
        ),
      ),
    );
  }
}

class _FoodexWavePainter extends CustomPainter {
  const _FoodexWavePainter(this.progress);

  final double progress;

  static const _colors = <Color>[
    Color(0xFF0C6F49),
    Color(0xFF1AA86A),
    Color(0xFF73CD5B),
  ];

  @override
  void paint(Canvas canvas, Size size) {
    for (var index = 0; index < _colors.length; index++) {
      final path = Path();
      final phase =
          (progress * math.pi * 2) + (index * math.pi / 2.4);
      final amplitude = 7.0 - (index * 1.6);
      final baseline = (size.height * 0.44) + (index * 8.0);

      for (double x = 0; x <= size.width; x += 2) {
        final normalized = x / size.width;
        final y = baseline +
            math.sin((normalized * math.pi * 2.4) + phase) *
                amplitude;

        if (x == 0) {
          path.moveTo(x, y);
        } else {
          path.lineTo(x, y);
        }
      }

      final paint = Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = 2.4 - (index * 0.35)
        ..strokeCap = StrokeCap.round
        ..color = _colors[index].withValues(
          alpha: 0.34 - (index * 0.07),
        );

      canvas.drawPath(path, paint);
    }
  }

  @override
  bool shouldRepaint(covariant _FoodexWavePainter oldDelegate) {
    return oldDelegate.progress != progress;
  }
}
