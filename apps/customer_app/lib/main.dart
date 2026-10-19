import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/widgets.dart';

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
      return const Directionality(
        textDirection: TextDirection.rtl,
        child: ColoredBox(
          color: CustomerUiColors.deepGreenStrong,
          child: Center(
            child: SizedBox(
              width: 190,
              child: Image(
                image: AssetImage(
                  'assets/branding/foodex-economical-group.webp',
                ),
                fit: BoxFit.contain,
              ),
            ),
          ),
        ),
      );
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
