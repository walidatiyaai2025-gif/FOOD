import 'package:flutter/widgets.dart';

import 'app.dart';
import 'core/push/firebase_push_service.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  final pushService = await DriverFirebasePushService.bootstrap();
  runApp(FoodexDriverApp(pushService: pushService));
}
