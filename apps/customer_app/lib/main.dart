import 'package:flutter/foundation.dart';
import 'package:flutter/widgets.dart';

import 'app.dart';
import 'core/auth/customer_auth_persistence.dart';
import 'core/auth/customer_session.dart';
import 'core/auth/customer_session_store.dart';
import 'core/diagnostics/customer_diagnostics.dart';
import 'core/config/foodex_environment.dart';
import 'core/config/mobile_runtime_visibility.dart';
import 'core/push/firebase_push_service.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  final diagnostics = CustomerDiagnostics.instance;
  await diagnostics.initialize();

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

  final runtimeVisibility = await MobileRuntimeVisibility.fetch(
    baseUrl: FoodexEnvironment.apiBaseUrl,
    locale: 'ar',
  );

  CustomerFirebasePushService? pushService;
  try {
    pushService = await CustomerFirebasePushService.bootstrap();
  } catch (error, stack) {
    diagnostics.record('push_bootstrap_error', {
      'error': error.toString(),
      'stack': stack.toString(),
    });
  }

  runApp(
    FoodexCustomerApp(
      session: bootstrap.session,
      sessionStore: sessionStore,
      authPreferences: bootstrap.preferences,
      authPreferenceStore: authPreferenceStore,
      biometricAuthenticator: LocalAuthCustomerBiometricAuthenticator(),
      pushService: pushService,
      showPersistentFooter: runtimeVisibility.showPersistentFooter,
    ),
  );
}
