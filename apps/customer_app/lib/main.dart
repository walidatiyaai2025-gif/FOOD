import 'package:flutter/foundation.dart';
import 'package:flutter/widgets.dart';

import 'app.dart';
import 'core/diagnostics/customer_diagnostics.dart';
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

  CustomerFirebasePushService? pushService;
  try {
    pushService = await CustomerFirebasePushService.bootstrap();
  } catch (error, stack) {
    diagnostics.record('push_bootstrap_error', {
      'error': error.toString(),
      'stack': stack.toString(),
    });
  }

  runApp(FoodexCustomerApp(pushService: pushService));
}
