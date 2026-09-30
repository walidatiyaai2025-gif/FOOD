import 'dart:ui';

import 'package:flutter/widgets.dart';

import 'app.dart';
import 'core/config/foodex_environment.dart';
import 'core/diagnostics/customer_diagnostics.dart';
import 'core/push/firebase_push_service.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  final diagnostics = CustomerDiagnostics.instance;
  await diagnostics.initialize(
    apiBaseUrl: FoodexEnvironment.apiBaseUrl,
    environment: FoodexEnvironment.pushEnvironment,
  );

  final previousFlutterError = FlutterError.onError;
  FlutterError.onError = (details) {
    diagnostics.recordError(
      'flutter_error',
      details.exception,
      details.stack,
    );
    if (previousFlutterError != null) {
      previousFlutterError(details);
    } else {
      FlutterError.presentError(details);
    }
  };

  final previousPlatformError = PlatformDispatcher.instance.onError;
  PlatformDispatcher.instance.onError = (error, stackTrace) {
    diagnostics.recordError('dart_uncaught_error', error, stackTrace);
    return previousPlatformError?.call(error, stackTrace) ?? false;
  };

  final pushService = await CustomerFirebasePushService.bootstrap();
  runApp(FoodexCustomerApp(pushService: pushService));
}
