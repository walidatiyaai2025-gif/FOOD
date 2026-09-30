import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:math';

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';

import '../config/foodex_environment.dart';
import '../diagnostics/customer_diagnostics.dart';
import '../routing/customer_routes.dart';

const customerBundleId = 'com.fiftysolution.foodex.customer';
const _customerPushChannelId = 'foodex_customer_high_priority';
const _customerPushChannelName = 'FOODEX Customer';
const _customerPushChannelDescription = 'FOODEX customer alerts and promotional notifications.';
const _customerInstallIdPreferenceKey = 'foodex_customer_install_id_v1';

Future<String> _loadOrCreateCustomerInstallId() async {
  final preferences = await SharedPreferences.getInstance();
  final stored = preferences.getString(_customerInstallIdPreferenceKey)?.trim();
  if (stored != null && stored.isNotEmpty) return stored;

  final random = Random.secure();
  final entropy = List<int>.generate(16, (_) => random.nextInt(256))
      .map((value) => value.toRadixString(16).padLeft(2, '0'))
      .join();
  final generated =
      'customer-${DateTime.now().microsecondsSinceEpoch.toRadixString(36)}-$entropy';
  await preferences.setString(_customerInstallIdPreferenceKey, generated);
  return generated;
}

final FlutterLocalNotificationsPlugin _customerLocalNotifications =
    FlutterLocalNotificationsPlugin();

@pragma('vm:entry-point')
Future<void> customerFirebaseBackgroundHandler(RemoteMessage message) async {
  try {
    await Firebase.initializeApp();
  } catch (_) {
    // Firebase can already be initialized by the host app.
  }

  if (message.notification == null) {
    await initializeFoodexLocalNotifications();
    await showFoodexLocalNotification(message);
  }
}

Future<void> initializeFoodexLocalNotifications({
  void Function(NotificationResponse)? onTap,
}) async {
  const settings = InitializationSettings(
    android: AndroidInitializationSettings('@mipmap/ic_launcher'),
    iOS: DarwinInitializationSettings(),
  );

  await _customerLocalNotifications.initialize(
    settings,
    onDidReceiveNotificationResponse: onTap,
  );

  if (Platform.isAndroid) {
    const channel = AndroidNotificationChannel(
      _customerPushChannelId,
      _customerPushChannelName,
      description: _customerPushChannelDescription,
      importance: Importance.high,
    );
    await _customerLocalNotifications
        .resolvePlatformSpecificImplementation<
            AndroidFlutterLocalNotificationsPlugin>()
        ?.createNotificationChannel(channel);
  }
}

Future<void> showFoodexLocalNotification(RemoteMessage message) async {
  final title = message.notification?.title ??
      message.data['title']?.toString() ??
      'FOODEX';
  final body = message.notification?.body ??
      message.data['body']?.toString() ??
      '';
  final imageUrl = (message.notification?.android?.imageUrl ??
          message.notification?.apple?.imageUrl ??
          message.data['image_url']?.toString())
      ?.trim();

  BigPictureStyleInformation? bigPictureStyle;
  if (Platform.isAndroid && imageUrl != null && imageUrl.isNotEmpty) {
    try {
      final response = await http.get(Uri.parse(imageUrl));
      if (response.statusCode >= 200 &&
          response.statusCode < 300 &&
          response.bodyBytes.isNotEmpty) {
        bigPictureStyle = BigPictureStyleInformation(
          ByteArrayAndroidBitmap.fromBase64String(
            base64Encode(response.bodyBytes),
          ),
          contentTitle: title,
          summaryText: body,
          hideExpandedLargeIcon: true,
        );
      }
    } catch (_) {
      // The notification still renders without the remote image.
    }
  }

  final details = NotificationDetails(
    android: AndroidNotificationDetails(
      _customerPushChannelId,
      _customerPushChannelName,
      channelDescription: _customerPushChannelDescription,
      importance: Importance.high,
      priority: Priority.high,
      visibility: NotificationVisibility.public,
      styleInformation: bigPictureStyle,
    ),
    iOS: const DarwinNotificationDetails(
      presentAlert: true,
      presentBadge: true,
      presentSound: true,
    ),
  );

  await _customerLocalNotifications.show(
    message.messageId?.hashCode ??
        DateTime.now().millisecondsSinceEpoch.remainder(2147483647),
    title,
    body,
    details,
    payload: CustomerFirebasePushService.routeForData(message.data),
  );
}

class FoodexPushAlert {
  const FoodexPushAlert({
    required this.title,
    required this.body,
    this.imageUrl,
  });

  final String title;
  final String body;
  final String? imageUrl;
}

class FoodexFirebaseConfig {
  const FoodexFirebaseConfig({
    required this.apiKey,
    required this.appId,
    required this.messagingSenderId,
    required this.projectId,
  });

  final String apiKey;
  final String appId;
  final String messagingSenderId;
  final String projectId;

  static const fromEnvironment = FoodexFirebaseConfig(
    apiKey: String.fromEnvironment('FOODEX_FIREBASE_API_KEY'),
    appId: String.fromEnvironment('FOODEX_FIREBASE_APP_ID'),
    messagingSenderId: String.fromEnvironment(
      'FOODEX_FIREBASE_MESSAGING_SENDER_ID',
    ),
    projectId: String.fromEnvironment('FOODEX_FIREBASE_PROJECT_ID'),
  );

  bool get isConfigured =>
      apiKey.isNotEmpty &&
      appId.isNotEmpty &&
      messagingSenderId.isNotEmpty &&
      projectId.isNotEmpty;

  FirebaseOptions toOptions() => FirebaseOptions(
        apiKey: apiKey,
        appId: appId,
        messagingSenderId: messagingSenderId,
        projectId: projectId,
        iosBundleId: Platform.isIOS ? customerBundleId : null,
      );
}

class CustomerPushDeviceRegistry {
  CustomerPushDeviceRegistry({
    required this.baseUrl,
    http.Client? client,
  }) : _client = client ?? http.Client();

  final String baseUrl;
  final http.Client _client;

  Future<int?> register({
    required String accessToken,
    required String firebaseToken,
    required String installId,
  }) =>
      _register(
        endpoint: '/api/v1/push/devices',
        firebaseToken: firebaseToken,
        installId: installId,
        accessToken: accessToken,
      );

  Future<int?> registerGuest({
    required String firebaseToken,
    required String installId,
  }) =>
      _register(
        endpoint: '/api/v1/push/devices/guest',
        firebaseToken: firebaseToken,
        installId: installId,
      );

  Future<int?> _register({
    required String endpoint,
    required String firebaseToken,
    required String installId,
    String? accessToken,
  }) async {
    final response = await _client.post(
      Uri.parse('$baseUrl$endpoint'),
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        if (accessToken != null && accessToken.isNotEmpty)
          'Authorization': 'Bearer $accessToken',
      },
      body: jsonEncode({
        'app': 'customer',
        'platform': Platform.isIOS ? 'ios' : 'android',
        'environment': FoodexEnvironment.pushEnvironment,
        'token': firebaseToken,
        'install_id': installId,
        'target_channel': 'all',
      }),
    );
    if (response.statusCode < 200 || response.statusCode >= 300) return null;

    final decoded = jsonDecode(response.body);
    if (decoded is! Map<String, dynamic> || decoded['data'] is! Map) {
      return null;
    }
    final data = Map<String, dynamic>.from(decoded['data'] as Map);
    return (data['id'] as num?)?.toInt();
  }

  Future<void> revoke({
    required String accessToken,
    required int deviceId,
  }) async {
    await _client.delete(
      Uri.parse('$baseUrl/api/v1/push/devices/$deviceId'),
      headers: {
        'Accept': 'application/json',
        'Authorization': 'Bearer $accessToken',
      },
    );
  }
}

class CustomerFirebasePushService {
  CustomerFirebasePushService._({
    required this.registry,
    required FirebaseMessaging? messaging,
  }) : _messaging = messaging;

  final CustomerPushDeviceRegistry registry;
  final FirebaseMessaging? _messaging;
  final StreamController<String> _routes = StreamController<String>.broadcast();
  final StreamController<FoodexPushAlert> _alerts =
      StreamController<FoodexPushAlert>.broadcast();

  StreamSubscription<String>? _tokenSubscription;
  StreamSubscription<RemoteMessage>? _openedSubscription;
  StreamSubscription<RemoteMessage>? _foregroundSubscription;
  String? _accessToken;
  int? _deviceId;
  Future<String>? _installIdFuture;
  String? _pendingRoute;

  Stream<String> get routes => _routes.stream;
  Stream<FoodexPushAlert> get alerts => _alerts.stream;
  bool get enabled => _messaging != null;

  static Future<CustomerFirebasePushService> bootstrap({
    FoodexFirebaseConfig config = FoodexFirebaseConfig.fromEnvironment,
    http.Client? client,
  }) async {
    final registry = CustomerPushDeviceRegistry(
      baseUrl: FoodexEnvironment.apiBaseUrl,
      client: client,
    );

    if (!config.isConfigured && !Platform.isAndroid) {
      return CustomerFirebasePushService._(
        registry: registry,
        messaging: null,
      );
    }

    try {
      if (config.isConfigured) {
        await Firebase.initializeApp(options: config.toOptions());
      } else {
        await Firebase.initializeApp();
      }

      FirebaseMessaging.onBackgroundMessage(
        customerFirebaseBackgroundHandler,
      );

      final messaging = FirebaseMessaging.instance;
      await messaging.requestPermission(
        alert: true,
        badge: true,
        sound: true,
      );
      await messaging.setForegroundNotificationPresentationOptions(
        alert: true,
        badge: true,
        sound: true,
      );

      final service = CustomerFirebasePushService._(
        registry: registry,
        messaging: messaging,
      );
      await initializeFoodexLocalNotifications(
        onTap: (response) {
          final route = response.payload;
          if (route != null && route.isNotEmpty) {
            service._routes.add(route);
          }
        },
      );

      final initialMessage = await messaging.getInitialMessage();
      final launchDetails =
          await _customerLocalNotifications.getNotificationAppLaunchDetails();
      service._pendingRoute = routeForData(initialMessage?.data) ??
          (launchDetails?.didNotificationLaunchApp == true
              ? launchDetails?.notificationResponse?.payload
              : null);

      service._openedSubscription =
          FirebaseMessaging.onMessageOpenedApp.listen((message) {
        final route = routeForData(message.data);
        if (route != null) service._routes.add(route);
      });

      service._foregroundSubscription =
          FirebaseMessaging.onMessage.listen((message) async {
        await showFoodexLocalNotification(message);

        final title = message.notification?.title ??
            message.data['title']?.toString() ??
            'FOODEX';
        final body = message.notification?.body ??
            message.data['body']?.toString() ??
            '';
        final imageUrl = message.notification?.android?.imageUrl ??
            message.notification?.apple?.imageUrl ??
            message.data['image_url']?.toString();

        service._alerts.add(
          FoodexPushAlert(
            title: title,
            body: body,
            imageUrl: imageUrl,
          ),
        );
      });

      await service.bindGuest();

      return service;
    } catch (error) {
      CustomerDiagnostics.instance.record(
        'push_bootstrap_failure',
        {'error': error.toString()},
      );
      return CustomerFirebasePushService._(
        registry: registry,
        messaging: null,
      );
    }
  }

  static String? routeForData(Map<String, dynamic>? data) {
    if (data == null || data.isEmpty) return null;

    final orderId = int.tryParse((data['order_id'] ?? '').toString());
    if (orderId != null && orderId > 0) {
      final channel = (data['channel'] ?? '').toString().toLowerCase();
      return channel == 'b2b'
          ? '/b2b/orders/$orderId'
          : '/orders/$orderId/track';
    }

    final route = data['route']?.toString();
    const safeRoutes = <String>{
      CustomerRoutePaths.marketplace,
      CustomerRoutePaths.home,
      CustomerRoutePaths.offers,
      CustomerRoutePaths.products,
      CustomerRoutePaths.favorites,
      CustomerRoutePaths.orders,
      CustomerRoutePaths.notifications,
      CustomerRoutePaths.profile,
      CustomerRoutePaths.b2bHome,
      CustomerRoutePaths.b2bDashboard,
      CustomerRoutePaths.b2bOrders,
      CustomerRoutePaths.b2bProfile,
    };
    if (route != null && safeRoutes.contains(route)) return route;

    return CustomerRoutePaths.notifications;
  }

  String? takePendingRoute() {
    final route = _pendingRoute;
    _pendingRoute = null;
    return route;
  }

  Future<String> _installId() =>
      _installIdFuture ??= _loadOrCreateCustomerInstallId();

  Future<void> bindGuest() async {
    _deviceId = null;
    final messaging = _messaging;
    if (messaging == null) return;

    final installId = await _installId();
    final token = await messaging.getToken();
    if (token != null && token.isNotEmpty) {
      await registry.registerGuest(
        firebaseToken: token,
        installId: installId,
      );
    }

    await _tokenSubscription?.cancel();
    _tokenSubscription = messaging.onTokenRefresh.listen((newToken) async {
      try {
        final currentAccessToken = _accessToken;
        if (currentAccessToken == null || currentAccessToken.isEmpty) {
          await registry.registerGuest(
            firebaseToken: newToken,
            installId: installId,
          );
          return;
        }

        await registry.register(
          accessToken: currentAccessToken,
          firebaseToken: newToken,
          installId: installId,
        );
      } catch (error) {
        CustomerDiagnostics.instance.record(
          'push_token_refresh_failure',
          {'mode': 'guest', 'error': error.toString()},
        );
      }
    });
  }

  Future<void> bindSession(String accessToken) async {
    _accessToken = accessToken;
    final messaging = _messaging;
    if (messaging == null) return;

    final installId = await _installId();
    final token = await messaging.getToken();
    if (token != null && token.isNotEmpty) {
      _deviceId = await registry.register(
        accessToken: accessToken,
        firebaseToken: token,
        installId: installId,
      );
    }

    await _tokenSubscription?.cancel();
    _tokenSubscription = messaging.onTokenRefresh.listen((newToken) async {
      try {
        final currentToken = _accessToken;
        if (currentToken == null || currentToken.isEmpty) {
          await registry.registerGuest(
            firebaseToken: newToken,
            installId: installId,
          );
          return;
        }

        _deviceId = await registry.register(
          accessToken: currentToken,
          firebaseToken: newToken,
          installId: installId,
        );
      } catch (error) {
        CustomerDiagnostics.instance.record(
          'push_token_refresh_failure',
          {'mode': 'session', 'error': error.toString()},
        );
      }
    });
  }

  Future<void> revokeSession() async {
    final accessToken = _accessToken;
    final deviceId = _deviceId;
    _accessToken = null;
    _deviceId = null;

    await _tokenSubscription?.cancel();
    _tokenSubscription = null;

    if (accessToken != null && accessToken.isNotEmpty && deviceId != null) {
      try {
        await registry.revoke(
          accessToken: accessToken,
          deviceId: deviceId,
        );
      } catch (error) {
        CustomerDiagnostics.instance.record(
          'push_revoke_failure',
          {'error': error.toString()},
        );
        // Local logout must continue even if device revocation is unavailable.
      }
    }

    await bindGuest();
  }

  Future<void> dispose() async {
    await _tokenSubscription?.cancel();
    await _openedSubscription?.cancel();
    await _foregroundSubscription?.cancel();
    await _routes.close();
    await _alerts.close();
  }
}
