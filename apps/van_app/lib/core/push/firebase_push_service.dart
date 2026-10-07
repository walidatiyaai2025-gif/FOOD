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

const vanBundleId = 'com.foodex.van';
const _vanPushChannelId = 'foodex_van_high_priority';
const _vanPushChannelName = 'FOODEX Van';
const _vanPushChannelDescription = 'FOODEX Van operational alerts.';
const _vanInstallIdPreferenceKey = 'foodex_van_install_id_v1';

final FlutterLocalNotificationsPlugin _vanLocalNotifications =
    FlutterLocalNotificationsPlugin();

@pragma('vm:entry-point')
Future<void> foodexVanFirebaseBackgroundHandler(RemoteMessage message) async {
  await Firebase.initializeApp();
}

Future<String> _loadOrCreateVanInstallId() async {
  final preferences = await SharedPreferences.getInstance();
  final stored = preferences.getString(_vanInstallIdPreferenceKey)?.trim();
  if (stored != null && stored.isNotEmpty) return stored;

  final random = Random.secure();
  final entropy = List<int>.generate(16, (_) => random.nextInt(256))
      .map((value) => value.toRadixString(16).padLeft(2, '0'))
      .join();
  final generated =
      'van-${DateTime.now().microsecondsSinceEpoch.toRadixString(36)}-$entropy';
  await preferences.setString(_vanInstallIdPreferenceKey, generated);
  return generated;
}

Future<void> _initializeVanLocalNotifications() async {
  const settings = InitializationSettings(
    android: AndroidInitializationSettings('@mipmap/ic_launcher'),
    iOS: DarwinInitializationSettings(),
  );
  await _vanLocalNotifications.initialize(settings);

  if (Platform.isAndroid) {
    const channel = AndroidNotificationChannel(
      _vanPushChannelId,
      _vanPushChannelName,
      description: _vanPushChannelDescription,
      importance: Importance.high,
    );
    await _vanLocalNotifications
        .resolvePlatformSpecificImplementation<
            AndroidFlutterLocalNotificationsPlugin>()
        ?.createNotificationChannel(channel);
  }
}

Future<void> _showVanLocalNotification(RemoteMessage message) async {
  final title = message.notification?.title ??
      message.data['title']?.toString() ??
      'FOODEX Van';
  final body =
      message.notification?.body ?? message.data['body']?.toString() ?? '';

  const details = NotificationDetails(
    android: AndroidNotificationDetails(
      _vanPushChannelId,
      _vanPushChannelName,
      channelDescription: _vanPushChannelDescription,
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

  await _vanLocalNotifications.show(
    message.messageId?.hashCode ??
        DateTime.now().millisecondsSinceEpoch.remainder(2147483647),
    title,
    body,
    details,
    payload: jsonEncode(message.data),
  );
}

class VanPushAlert {
  const VanPushAlert({required this.title, required this.body});

  final String title;
  final String body;
}

class VanPushDeviceRegistry {
  VanPushDeviceRegistry({required this.baseUrl, http.Client? client})
      : _client = client ?? http.Client();

  final String baseUrl;
  final http.Client _client;

  Future<int?> register({
    required String accessToken,
    required String firebaseToken,
    required String installId,
  }) async {
    final response = await _client.post(
      Uri.parse('$baseUrl/api/v1/push/devices'),
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'Authorization': 'Bearer $accessToken',
      },
      body: jsonEncode({
        'app': 'van',
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

class VanFirebasePushService {
  VanFirebasePushService._({
    required this.registry,
    required FirebaseMessaging? messaging,
  }) : _messaging = messaging;

  final VanPushDeviceRegistry registry;
  final FirebaseMessaging? _messaging;
  final StreamController<VanPushAlert> _alerts =
      StreamController<VanPushAlert>.broadcast();

  StreamSubscription<String>? _tokenSubscription;
  StreamSubscription<RemoteMessage>? _foregroundSubscription;
  String? _accessToken;
  int? _deviceId;
  Future<String>? _installIdFuture;

  Stream<VanPushAlert> get alerts => _alerts.stream;
  bool get enabled => _messaging != null;

  static Future<VanFirebasePushService> bootstrap({
    http.Client? client,
  }) async {
    final registry = VanPushDeviceRegistry(
      baseUrl: FoodexEnvironment.apiBaseUrl,
      client: client,
    );

    try {
      await Firebase.initializeApp();
      FirebaseMessaging.onBackgroundMessage(
        foodexVanFirebaseBackgroundHandler,
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
      await _initializeVanLocalNotifications();

      final service = VanFirebasePushService._(
        registry: registry,
        messaging: messaging,
      );
      service._foregroundSubscription =
          FirebaseMessaging.onMessage.listen((message) {
        if (Platform.isAndroid) {
          unawaited(_showVanLocalNotification(message));
        }
        final title = message.notification?.title ??
            message.data['title']?.toString() ??
            'FOODEX Van';
        final body =
            message.notification?.body ?? message.data['body']?.toString() ?? '';
        service._alerts.add(VanPushAlert(title: title, body: body));
      });
      return service;
    } catch (_) {
      return VanFirebasePushService._(registry: registry, messaging: null);
    }
  }

  Future<String> _installId() =>
      _installIdFuture ??= _loadOrCreateVanInstallId();

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
    final accessToken = _accessToken;
    final deviceId = _deviceId;
    _accessToken = null;
    _deviceId = null;
    await _tokenSubscription?.cancel();
    _tokenSubscription = null;

    if (accessToken == null || deviceId == null) return;
    try {
      await registry.revoke(
        accessToken: accessToken,
        deviceId: deviceId,
      );
    } catch (_) {
      // Logout must not fail because push-token revocation is unavailable.
    }
  }

  Future<void> dispose() async {
    await _tokenSubscription?.cancel();
    await _foregroundSubscription?.cancel();
    await _alerts.close();
  }
}
