import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:http/http.dart' as http;

import '../config/foodex_environment.dart';

const driverBundleId = 'com.fiftysolution.foodex.driver';

class DriverPushAlert {
  const DriverPushAlert({required this.title, required this.body});
  final String title;
  final String body;
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
  DriverPushDeviceRegistry({required this.baseUrl, http.Client? client}) : _client = client ?? http.Client();
  final String baseUrl;
  final http.Client _client;

  Future<int?> register({required String accessToken, required String firebaseToken}) async {
    final response = await _client.post(
      Uri.parse(baseUrl + '/api/v1/push/devices'),
      headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + accessToken},
      body: jsonEncode({
        'app': 'driver',
        'platform': Platform.isIOS ? 'ios' : 'android',
        'environment': FoodexEnvironment.pushEnvironment,
        'token': firebaseToken,
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
      Uri.parse(baseUrl + '/api/v1/push/devices/' + deviceId.toString()),
      headers: {'Accept': 'application/json', 'Authorization': 'Bearer ' + accessToken},
    );
  }
}

class DriverFirebasePushService {
  DriverFirebasePushService._({required this.registry, required FirebaseMessaging? messaging}) : _messaging = messaging;
  final DriverPushDeviceRegistry registry;
  final FirebaseMessaging? _messaging;
  final StreamController<void> _opens = StreamController<void>.broadcast();
  final StreamController<DriverPushAlert> _alerts = StreamController<DriverPushAlert>.broadcast();
  StreamSubscription<String>? _tokenSubscription;
  StreamSubscription<RemoteMessage>? _openedSubscription;
  StreamSubscription<RemoteMessage>? _foregroundSubscription;
  String? _accessToken;
  int? _deviceId;
  bool _hasPendingOpen = false;

  Stream<void> get opens => _opens.stream;
  Stream<DriverPushAlert> get alerts => _alerts.stream;
  bool get enabled => _messaging != null;

  static Future<DriverFirebasePushService> bootstrap({DriverFirebaseConfig config = DriverFirebaseConfig.fromEnvironment, http.Client? client}) async {
    final registry = DriverPushDeviceRegistry(baseUrl: FoodexEnvironment.apiBaseUrl, client: client);
    if (!config.isConfigured) return DriverFirebasePushService._(registry: registry, messaging: null);
    try {
      await Firebase.initializeApp(options: config.toOptions());
      final messaging = FirebaseMessaging.instance;
      await messaging.requestPermission(alert: true, badge: true, sound: true);
      await messaging.setForegroundNotificationPresentationOptions(alert: true, badge: true, sound: true);
      final service = DriverFirebasePushService._(registry: registry, messaging: messaging);
      service._hasPendingOpen = await messaging.getInitialMessage() != null;
      service._openedSubscription = FirebaseMessaging.onMessageOpenedApp.listen((_) { service._opens.add(null); });
      service._foregroundSubscription = FirebaseMessaging.onMessage.listen((message) {
        final notification = message.notification;
        if (notification == null) return;
        service._alerts.add(DriverPushAlert(title: notification.title ?? 'FOODEX Driver', body: notification.body ?? ''));
      });
      return service;
    } catch (_) {
      return DriverFirebasePushService._(registry: registry, messaging: null);
    }
  }

  bool takePendingOpen() { final value = _hasPendingOpen; _hasPendingOpen = false; return value; }

  Future<void> bindSession(String accessToken) async {
    _accessToken = accessToken;
    final messaging = _messaging;
    if (messaging == null) return;
    final token = await messaging.getToken();
    if (token != null && token.isNotEmpty) _deviceId = await registry.register(accessToken: accessToken, firebaseToken: token);
    await _tokenSubscription?.cancel();
    _tokenSubscription = messaging.onTokenRefresh.listen((newToken) async {
      final currentToken = _accessToken;
      if (currentToken == null || currentToken.isEmpty) return;
      _deviceId = await registry.register(accessToken: currentToken, firebaseToken: newToken);
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
