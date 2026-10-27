import 'package:flutter/foundation.dart';
import 'package:flutter/widgets.dart';

import 'app.dart';
import 'core/api/http_van_api.dart';
import 'core/config/foodex_environment.dart';
import 'core/location/van_location_tracking_service.dart';
import 'core/push/firebase_push_service.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  final pushService = await VanFirebasePushService.bootstrap();
  runApp(
    FoodexVanApp(
      pushService: pushService,
      locationTrackingFactory: kDebugMode
          ? null
          : (session) {
              final api =
                  VanApiClient(FoodexEnvironment.apiBaseUrl, session.token);
              return VanLocationTrackingController(
                source: const GeolocatorVanLocationSource(),
                heartbeatClient: HttpVanLocationHeartbeatClient(api),
              );
            },
    ),
  );
}
