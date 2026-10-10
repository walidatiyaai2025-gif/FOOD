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

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  final startup = Stopwatch()..start();

  final diagnostics = CustomerDiagnostics.instance;
  await diagnostics.initialize();
  await CustomerDataModeController.instance.initialize();

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
  var bootstrap = const CustomerAuthBootstrap(
    session: CustomerSession.guest(),
    preferences: CustomerAuthPreferences(),
  );
  try {
    bootstrap = await restoreCustomerAuthBootstrap(
      sessionStore: sessionStore,
      preferenceStore: authPreferenceStore,
    );
  } catch (error) {
    diagnostics.record('session_restore_error', {
      'error_type': error.runtimeType.toString(),
    });
  }

  runApp(
    _CustomerRuntimeBootstrap(
      bootstrap: bootstrap,
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
  CustomerFirebasePushService? _pushService;
  bool _showPersistentFooter = true;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      widget.startup.stop();
      widget.diagnostics.markStartupUsable(widget.startup.elapsed);
      widget.diagnostics.record('startup_usable', {
        'elapsed_ms': widget.startup.elapsedMilliseconds,
      });
    });
    unawaited(_loadRuntimeVisibility());
    unawaited(_bootstrapPush());
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
      final service = await CustomerFirebasePushService.bootstrap();
      if (!mounted) {
        await service.dispose();
        return;
      }
      setState(() => _pushService = service);
    } catch (error, stack) {
      widget.diagnostics.record('push_bootstrap_error', {
        'error': error.toString(),
        'stack': stack.toString(),
      });
    }
  }

  @override
  Widget build(BuildContext context) => FoodexCustomerApp(
        session: widget.bootstrap.session,
        sessionStore: widget.sessionStore,
        authPreferences: widget.bootstrap.preferences,
        authPreferenceStore: widget.authPreferenceStore,
        biometricAuthenticator: LocalAuthCustomerBiometricAuthenticator(),
        pushService: _pushService,
        showPersistentFooter: _showPersistentFooter,
      );
}
