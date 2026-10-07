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
import '../diagnostics/driver_runtime_inspector.dart';

const driverBundleId = 'com.fiftysolution.foodex.driver';
const _driverPushChannelId = 'foodex_driver_high_priority';
const _driverPushChannelName = 'FOODEX Driver';
const _driverPushChannelDescription = 'FOODEX driver order and delivery alerts.';
const _driverInstallIdPreferenceKey = 'foodex_driver_install_id_v1';

Future<String> _loadOrCreateDriverInstallId() async {
  final preferences = await SharedPreferences.getInstance();
  final stored = preferences.getString(_driverInstallIdPreferenceKey)?.trim();
  if (stored != null && stored.isNotEmpty) return stored;

  final random = Random.secure();
  final entropy = List<int>.generate(16, (_) => random.nextInt(256))
      .map((value) => value.toRadixString(16).padLeft(2, '0'))
      .join();
  final generated =
      'driver-${DateTime.now().microsecondsSinceEpoch.toRadixString(36)}-$entropy';
  await preferences.setString(_driverInstallIdPreferenceKey, generated);
  return generated;
}

final FlutterLocalNotificationsPlugin _driverLocalNotifications =
    FlutterLocalNotificationsPlugin();

Future<void> initializeDriverLocalNotifications({
  void Function(NotificationResponse)? onTap,
}) async {
  const settings = InitializationSettings(
    android: AndroidInitializationSettings('@mipmap/ic_launcher'),
    iOS: DarwinInitializationSettings(),
  );
  await _driverLocalNotifications.initialize(
    settings,
    onDidReceiveNotificationResponse: onTap,
  );

  if (Platform.isAndroid) {
    const channel = AndroidNotificationChannel(
      _driverPushChannelId,
      _driverPushChannelName,
      description: _driverPushChannelDescription,
      importance: Importance.high,
    );
    await _driverLocalNotifications
        .resolvePlatformSpecificImplementation<
            AndroidFlutterLocalNotificationsPlugin>()
        ?.createNotificationChannel(channel);
  }
}

Future<void> showDriverLocalNotification(RemoteMessage message) async {
  final title = message.notification?.title ??
      message.data['title']?.toString() ??
      'FOODEX Driver';
  final body = message.notification?.body ??
      message.data['body']?.toString() ??
      '';

  const details = NotificationDetails(
    android: AndroidNotificationDetails(
      _driverPushChannelId,
      _driverPushChannelName,
      channelDescription: _driverPushChannelDescription,
      importance: Importance.high,
      priority: Priority.high,
      visibility: NotificationVisibility.public,
    ),
    iOS: DarwinNotificationDetails(
      presentAlert: true,
      presentBadge: true,
      presentSound: true,
    ),
  );

  await _driverLocalNotifications.show(
    message.messageId?.hashCode ??
        DateTime.now().millisecondsSinceEpoch.remainder(2147483647),
    title,
    body,
    details,
    payload: jsonEncode(message.data),
  );
}

class DriverPushAlert {
  const DriverPushAlert({
    required this.title,
    required this.body,
    required this.open,
  });

  final String title;
  final String body;
  final DriverPushOpen open;

  String get eventKey {
    final assignmentId = open.assignmentId;
    if (assignmentId != null) return 'assignment:$assignmentId';
    final orderId = open.orderId;
    if (orderId != null) return 'order:$orderId';
    return 'message:$title|$body';
  }
}

String? driverPushIdentityLabel(
  DriverPushOpen open, {
  required String orderLabel,
  required String assignmentLabel,
}) {
  final orderId = open.orderId;
  if (orderId != null) return '$orderLabel #$orderId';

  final assignmentId = open.assignmentId;
  if (assignmentId != null) return '$assignmentLabel #$assignmentId';

  return null;
}

class DriverPushOpen {
  const DriverPushOpen({
    this.assignmentId,
    this.orderId,
    this.accessRevoked = false,
  });

  final int? assignmentId;
  final int? orderId;
  final bool accessRevoked;
}

class DriverFirebaseConfig {
  const DriverFirebaseConfig({required this.apiKey, required this.appId, required this.messagingSenderId, required this.projectId});
  final String apiKey;
  final String appId;
  final String messagingSenderId;
  final String projectId;

  static const fromEnvironment = DriverFirebaseConfig(
    apiKey: String.fromEnvironment('FOODEX_FIREBASE_API_KEY'),
    appId: String.fromEnvironment('FOODEX_FIREBASE_APP_ID'),
    messagingSenderId: String.fromEnvironment('FOODEX_FIREBASE_MESSAGING_SENDER_ID'),
    projectId: String.fromEnvironment('FOODEX_FIREBASE_PROJECT_ID'),
  );

  bool get isConfigured => apiKey.isNotEmpty && appId.isNotEmpty && messagingSenderId.isNotEmpty && projectId.isNotEmpty;

  FirebaseOptions toOptions() => FirebaseOptions(
    apiKey: apiKey,
    appId: appId,
    messagingSenderId: messagingSenderId,
    projectId: projectId,
    iosBundleId: Platform.isIOS ? driverBundleId : null,
  );
}

class DriverPushDeviceRegistry {
  DriverPushDeviceRegistry({required this.baseUrl, http.Client? client}) : _client = DriverDiagnosticHttpClient(client ?? http.Client());
  final String baseUrl;
  final http.Client _client;

  Future<int?> register({
    required String accessToken,
    required String firebaseToken,
    required String installId,
  }) async {
    final response = await _client.post(
      Uri.parse('$baseUrl/api/v1/push/devices'),
      headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'Authorization': 'Bearer $accessToken'},
      body: jsonEncode({
        'app': 'driver',
        'platform': Platform.isIOS ? 'ios' : 'android',
        'environment': FoodexEnvironment.pushEnvironment,
        'token': firebaseToken,
        'install_id': installId,
      }),
    );
    if (response.statusCode < 200 || response.statusCode >= 300) return null;
    final decoded = jsonDecode(response.body);
    if (decoded is! Map<String, dynamic> || decoded['data'] is! Map) return null;
    final data = Map<String, dynamic>.from(decoded['data'] as Map);
    return (data['id'] as num?)?.toInt();
  }

  Future<void> revoke({required String accessToken, required int deviceId}) async {
    await _client.delete(
      Uri.parse('$baseUrl/api/v1/push/devices/$deviceId'),
      headers: {'Accept': 'application/json', 'Authorization': 'Bearer $accessToken'},
    );
  }
}

abstract interface class DriverPushService {
  Stream<DriverPushOpen> get opens;
  Stream<DriverPushAlert> get alerts;
  DriverPushOpen? takePendingOpen();
  Future<void> bindSession(String accessToken);
  Future<void> revokeSession();
}

class DriverFirebasePushService implements DriverPushService {
  DriverFirebasePushService._({required this.registry, required FirebaseMessaging? messaging}) : _messaging = messaging;
  final DriverPushDeviceRegistry registry;
  final FirebaseMessaging? _messaging;
  final StreamController<DriverPushOpen> _opens =
      StreamController<DriverPushOpen>.broadcast();
  final StreamController<DriverPushAlert> _alerts = StreamController<DriverPushAlert>.broadcast();
  StreamSubscription<String>? _tokenSubscription;
  StreamSubscription<RemoteMessage>? _openedSubscription;
  StreamSubscription<RemoteMessage>? _foregroundSubscription;
  String? _accessToken;
  int? _deviceId;
  Future<String>? _installIdFuture;
  DriverPushOpen? _pendingOpen;

  Stream<DriverPushOpen> get opens => _opens.stream;
  Stream<DriverPushAlert> get alerts => _alerts.stream;
  bool get enabled => _messaging != null;

  static Future<DriverFirebasePushService> bootstrap({DriverFirebaseConfig config = DriverFirebaseConfig.fromEnvironment, http.Client? client}) async {
    final registry = DriverPushDeviceRegistry(baseUrl: FoodexEnvironment.apiBaseUrl, client: client);
    if (!config.isConfigured && !Platform.isAndroid) return DriverFirebasePushService._(registry: registry, messaging: null);
    try {
      if (config.isConfigured) {
        await Firebase.initializeApp(options: config.toOptions());
      } else {
        await Firebase.initializeApp();
      }
      final messaging = FirebaseMessaging.instance;
      await messaging.requestPermission(alert: true, badge: true, sound: true);
      await messaging.setForegroundNotificationPresentationOptions(alert: true, badge: true, sound: true);
      final service = DriverFirebasePushService._(registry: registry, messaging: messaging);
      await initializeDriverLocalNotifications(
        onTap: (response) {
          final open = DriverFirebasePushService.openForPayload(response.payload);
          if (open != null) service._opens.add(open);
        },
      );
      final initialMessage = await messaging.getInitialMessage();
      final launchDetails =
          await _driverLocalNotifications.getNotificationAppLaunchDetails();
      service._pendingOpen = initialMessage != null
          ? DriverFirebasePushService.openForData(initialMessage.data)
          : DriverFirebasePushService.openForPayload(
              launchDetails?.didNotificationLaunchApp == true
                  ? launchDetails?.notificationResponse?.payload
                  : null,
            );
      service._openedSubscription = FirebaseMessaging.onMessageOpenedApp.listen((message) {
        service._opens.add(DriverFirebasePushService.openForData(message.data));
      });
      service._foregroundSubscription = FirebaseMessaging.onMessage.listen((message) {
        if (Platform.isAndroid) {
          showDriverLocalNotification(message);
        }
        final title = message.notification?.title ??
            message.data['title']?.toString() ??
            'FOODEX Driver';
        final body = message.notification?.body ??
            message.data['body']?.toString() ??
            '';
        service._alerts.add(
          DriverPushAlert(
            title: title,
            body: body,
            open: DriverFirebasePushService.openForData(message.data),
          ),
        );
      });
      return service;
    } catch (_) {
      return DriverFirebasePushService._(registry: registry, messaging: null);
    }
  }

  static DriverPushOpen? openForPayload(String? payload) {
    if (payload == null || payload.trim().isEmpty) return null;

    try {
      final decoded = jsonDecode(payload);
      if (decoded is! Map) return null;
      return openForData(Map<String, dynamic>.from(decoded));
    } catch (_) {
      return null;
    }
  }

  static DriverPushOpen openForData(Map<String, dynamic>? data) {
    if (data == null || data.isEmpty) return const DriverPushOpen();

    final assignmentId =
        int.tryParse((data['assignment_id'] ?? '').toString());
    final orderId = int.tryParse((data['order_id'] ?? '').toString());
    final revoked = const {'1', 'true', 'yes'}
        .contains((data['access_revoked'] ?? '').toString().toLowerCase());

    return DriverPushOpen(
      assignmentId:
          assignmentId != null && assignmentId > 0 ? assignmentId : null,
      orderId: orderId != null && orderId > 0 ? orderId : null,
      accessRevoked: revoked,
    );
  }

  DriverPushOpen? takePendingOpen() {
    final value = _pendingOpen;
    _pendingOpen = null;
    return value;
  }

  Future<String> _installId() =>
      _installIdFuture ??= _loadOrCreateDriverInstallId();

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
      final currentToken = _accessToken;
      if (currentToken == null || currentToken.isEmpty) return;
      _deviceId = await registry.register(
        accessToken: currentToken,
        firebaseToken: newToken,
        installId: installId,
      );
    });
  }

  Future<void> revokeSession() async {
    final accessToken = _accessToken; final deviceId = _deviceId;
    _accessToken = null; _deviceId = null;
    await _tokenSubscription?.cancel(); _tokenSubscription = null;
    if (accessToken == null || deviceId == null) return;
    try { await registry.revoke(accessToken: accessToken, deviceId: deviceId); } catch (_) {}
  }

  Future<void> dispose() async {
    await _tokenSubscription?.cancel(); await _openedSubscription?.cancel(); await _foregroundSubscription?.cancel();
    await _opens.close(); await _alerts.close();
  }
}
