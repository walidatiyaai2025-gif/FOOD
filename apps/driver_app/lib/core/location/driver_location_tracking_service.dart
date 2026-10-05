import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:geolocator/geolocator.dart';
import 'package:http/http.dart' as http;

import '../auth/driver_session.dart';
import '../diagnostics/driver_runtime_inspector.dart';


class DriverLocationSample {
  const DriverLocationSample({
    required this.latitude,
    required this.longitude,
    required this.capturedAt,
    this.accuracy,
    this.speed,
    this.heading,
    this.isMocked,
  });

  final double latitude;
  final double longitude;
  final double? accuracy;
  final double? speed;
  final double? heading;
  final DateTime capturedAt;
  final bool? isMocked;
}

class DriverHeartbeatReceipt {
  const DriverHeartbeatReceipt({
    this.activeAssignmentId,
    this.activeAssignmentStatus,
  });

  final int? activeAssignmentId;
  final String? activeAssignmentStatus;

  bool get trackingRequired {
    final status = DriverLocationTrackingLifecyclePolicy.normalize(
      activeAssignmentStatus,
    );
    if (status != null) {
      return DriverLocationTrackingLifecyclePolicy.requiresActiveTracking(
        status,
      );
    }
    return activeAssignmentId != null;
  }
}

abstract interface class DriverLocationSource {
  Future<DriverLocationSample> current();
}

abstract interface class DriverActiveDeliveryLocationSource {
  Stream<DriverLocationSample> watchActiveDelivery();
}

class GeolocatorDriverLocationSource
    implements DriverLocationSource, DriverActiveDeliveryLocationSource {
  const GeolocatorDriverLocationSource({this.locale = 'ar'});

  final String locale;

  @override
  Future<DriverLocationSample> current() async {
    final position = await Geolocator.getCurrentPosition(
      locationSettings: const LocationSettings(
        accuracy: LocationAccuracy.high,
      ),
    );

    return _sample(position);
  }

  @override
  Stream<DriverLocationSample> watchActiveDelivery() {
    final settings = switch (defaultTargetPlatform) {
      TargetPlatform.android => AndroidSettings(
          accuracy: LocationAccuracy.high,
          distanceFilter: 10,
          intervalDuration: const Duration(seconds: 7),
          foregroundNotificationConfig: ForegroundNotificationConfig(
            notificationTitle: locale == 'en'
                ? 'FOODEX Driver · active delivery'
                : 'FOODEX Driver · توصيل نشط',
            notificationText: locale == 'en'
                ? 'Location sharing is active only while this delivery is in progress.'
                : 'مشاركة الموقع تعمل فقط أثناء تنفيذ التوصيل الحالي.',
            enableWakeLock: true,
          ),
        ),
      TargetPlatform.iOS => AppleSettings(
          accuracy: LocationAccuracy.high,
          activityType: ActivityType.automotiveNavigation,
          distanceFilter: 10,
          pauseLocationUpdatesAutomatically: false,
          showBackgroundLocationIndicator: true,
        ),
      _ => const LocationSettings(
          accuracy: LocationAccuracy.high,
          distanceFilter: 10,
        ),
    };

    return Geolocator.getPositionStream(locationSettings: settings).map(_sample);
  }

  static DriverLocationSample _sample(Position position) {
    final accuracy = position.accuracy >= 0 ? position.accuracy : null;
    final speed = position.speed >= 0 && position.speed <= 500
        ? position.speed
        : null;
    final heading = position.heading >= 0 && position.heading <= 360
        ? position.heading
        : null;

    return DriverLocationSample(
      latitude: position.latitude,
      longitude: position.longitude,
      accuracy: accuracy,
      speed: speed,
      heading: heading,
      capturedAt: position.timestamp,
      isMocked: position.isMocked,
    );
  }
}

abstract interface class DriverLocationHeartbeatClient {
  Future<DriverHeartbeatReceipt> send(DriverLocationSample sample);
}

class HttpDriverLocationHeartbeatClient implements DriverLocationHeartbeatClient {
  HttpDriverLocationHeartbeatClient({
    required String baseUrl,
    required String token,
    http.Client? client,
    this.requestTimeout = const Duration(seconds: 12),
  })  : _endpoint = _heartbeatEndpoint(baseUrl),
        _token = token,
        _client = DriverDiagnosticHttpClient(client ?? http.Client());

  final Duration requestTimeout;

  final Uri _endpoint;
  final String _token;
  final http.Client _client;

  static Uri _heartbeatEndpoint(String raw) {
    final parsed = Uri.parse(raw);
    final path = parsed.path.endsWith('/') ? parsed.path : '${parsed.path}/';
    return parsed.replace(path: path).resolve('api/v1/driver/location/heartbeat');
  }

  @override
  Future<DriverHeartbeatReceipt> send(DriverLocationSample sample) async {
    final http.Response response;
    try {
      response = await _client
          .post(
            _endpoint,
            headers: {
              'Accept': 'application/json',
              'Content-Type': 'application/json',
              'Authorization': 'Bearer $_token',
            },
            body: jsonEncode({
              'latitude': sample.latitude,
              'longitude': sample.longitude,
              if (sample.accuracy != null) 'accuracy': sample.accuracy,
              if (sample.speed != null) 'speed': sample.speed,
              if (sample.heading != null) 'heading': sample.heading,
              'captured_at': sample.capturedAt.toUtc().toIso8601String(),
              'app_version': driverAppVersion,
              if (sample.isMocked != null) 'is_mocked': sample.isMocked,
            }),
          )
          .timeout(requestTimeout);
    } on SocketException {
      throw const DriverOfflineException();
    } on http.ClientException {
      throw const DriverOfflineException();
    }

    if (response.statusCode == 401) {
      throw const DriverSessionExpiredException();
    }
    if (response.statusCode == 403) {
      throw const DriverAccessDeniedException();
    }
    if (response.statusCode < 200 || response.statusCode >= 300) {
      throw const DriverApiException('Driver location heartbeat failed.');
    }

    final decoded = jsonDecode(response.body);
    if (decoded is! Map || decoded['data'] is! Map) {
      throw const DriverApiException('Invalid Driver heartbeat response.');
    }

    final data = Map<String, dynamic>.from(decoded['data'] as Map);
    final activeAssignment = data['active_assignment_id'];
    final activeAssignmentStatus = data['active_assignment_status'];

    return DriverHeartbeatReceipt(
      activeAssignmentId: activeAssignment is num
          ? activeAssignment.toInt()
          : null,
      activeAssignmentStatus: activeAssignmentStatus is String
          ? activeAssignmentStatus
          : null,
    );
  }

  void close() => _client.close();
}

abstract final class DriverLocationTrackingLifecyclePolicy {
  static const Set<String> activeStatuses = <String>{
    'accepted',
    'picked_up',
    'out_for_delivery',
  };

  static const Set<String> terminalStatuses = <String>{
    'delivered',
    'failed',
    'cancelled',
    'unassigned',
    'reassigned',
  };

  static String? normalize(String? status) {
    final value = status?.trim().toLowerCase();
    return value == null || value.isEmpty ? null : value;
  }

  static bool requiresActiveTracking(String? status) {
    final value = normalize(status);
    return value != null && activeStatuses.contains(value);
  }

  static bool stopsTracking(String? status) {
    final value = normalize(status);
    return value != null && terminalStatuses.contains(value);
  }
}

class DriverTrackingCadencePolicy {
  const DriverTrackingCadencePolicy({
    this.active = const Duration(seconds: 7),
    this.idle = const Duration(seconds: 20),
    this.retry = const Duration(seconds: 10),
  });

  final Duration active;
  final Duration idle;
  final Duration retry;

  Duration next({required bool activeDelivery}) =>
      activeDelivery ? active : idle;

  Duration retryDelay(int consecutiveFailures) {
    final failures = consecutiveFailures.clamp(1, 4);
    final multiplier = 1 << (failures - 1);
    final baseMs = retry.inMilliseconds * multiplier;
    final jitterMs = ((failures * 137) % 751);
    return Duration(milliseconds: baseMs + jitterMs);
  }
}

abstract interface class DriverTrackingScheduler {
  void schedule(Duration delay, Future<void> Function() callback);
  void cancel();
}

class TimerDriverTrackingScheduler implements DriverTrackingScheduler {
  Timer? _timer;

  @override
  void schedule(Duration delay, Future<void> Function() callback) {
    cancel();
    _timer = Timer(delay, () {
      unawaited(callback());
    });
  }

  @override
  void cancel() {
    _timer?.cancel();
    _timer = null;
  }
}

abstract interface class DriverLocationTrackingController {
  void start();
  void setGateReady(bool ready);
  void setAssignmentStatus(String? status);
  void setAppInForeground(bool foreground);
  void stop({bool clearQueue = true});
  void dispose();
}

class DriverLocationTrackingService implements DriverLocationTrackingController {
  DriverLocationTrackingService({
    required this.locationSource,
    required this.heartbeatClient,
    this.onSessionInvalid,
    DriverTrackingScheduler? scheduler,
    DriverTrackingCadencePolicy? cadence,
    DriverRuntimeInspector? inspector,
    this.queueLimit = 8,
  })  : assert(queueLimit > 0),
        scheduler = scheduler ?? TimerDriverTrackingScheduler(),
        cadence = cadence ?? const DriverTrackingCadencePolicy(),
        inspector = inspector ?? DriverRuntimeInspector.instance;

  final DriverLocationSource locationSource;
  final DriverLocationHeartbeatClient heartbeatClient;
  final void Function()? onSessionInvalid;
  final DriverTrackingScheduler scheduler;
  final DriverTrackingCadencePolicy cadence;
  final DriverRuntimeInspector inspector;
  final int queueLimit;

  final List<DriverLocationSample> _queue = <DriverLocationSample>[];

  bool _started = false;
  bool _gateReady = false;
  bool _cycleRunning = false;
  bool _backgroundFlushRunning = false;
  bool _disposed = false;
  bool _activeDelivery = false;
  bool _terminalAssignment = false;
  String? _assignmentStatus;
  bool _appInForeground = true;
  int _consecutiveFailures = 0;
  StreamSubscription<DriverLocationSample>? _backgroundSubscription;

  bool get isStarted => _started;
  bool get gateReady => _gateReady;
  bool get activeDelivery => _activeDelivery;
  bool get terminalAssignment => _terminalAssignment;
  String? get assignmentStatus => _assignmentStatus;
  bool get appInForeground => _appInForeground;
  bool get backgroundTracking => _backgroundSubscription != null;
  int get queuedSamples => _queue.length;

  @override
  void start() {
    if (_disposed || _started) return;
    _started = true;
    _reconcileTrackingMode();
  }

  @override
  void setGateReady(bool ready) {
    if (_disposed || _gateReady == ready) return;
    _gateReady = ready;
    _reconcileTrackingMode();
  }

  @override
  void setAssignmentStatus(String? status) {
    if (_disposed) return;

    final normalized = DriverLocationTrackingLifecyclePolicy.normalize(status);
    final nextTerminal =
        DriverLocationTrackingLifecyclePolicy.stopsTracking(normalized);
    final nextActive =
        DriverLocationTrackingLifecyclePolicy.requiresActiveTracking(
      normalized,
    );

    if (_assignmentStatus == normalized &&
        _terminalAssignment == nextTerminal &&
        _activeDelivery == nextActive) {
      return;
    }

    _assignmentStatus = normalized;
    _terminalAssignment = nextTerminal;
    _activeDelivery = nextActive;

    if (_terminalAssignment) {
      _queue.clear();
    }

    _reconcileTrackingMode();
  }

  @override
  void setAppInForeground(bool foreground) {
    if (_disposed || _appInForeground == foreground) return;
    _appInForeground = foreground;
    _reconcileTrackingMode();
  }

  @override
  void stop({bool clearQueue = true}) {
    _started = false;
    _gateReady = false;
    _activeDelivery = false;
    _terminalAssignment = false;
    _assignmentStatus = null;
    _consecutiveFailures = 0;
    scheduler.cancel();
    _stopBackgroundStream();
    if (clearQueue) {
      _queue.clear();
    }
  }

  @override
  void dispose() {
    if (_disposed) return;
    stop(clearQueue: true);
    _disposed = true;
    if (heartbeatClient is HttpDriverLocationHeartbeatClient) {
      (heartbeatClient as HttpDriverLocationHeartbeatClient).close();
    }
  }

  Future<void> runCycle() async {
    if (!_canRun || !_appInForeground || _cycleRunning) return;
    _cycleRunning = true;
    var nextDelay = cadence.retry;

    try {
      final sample = await locationSource.current();
      if (!_canRun || !_appInForeground) return;

      _enqueue(sample);
      final receipt = await _flush();
      if (!_canRun) return;

      _consecutiveFailures = 0;
      _applyReceipt(receipt);
      nextDelay = cadence.next(activeDelivery: _activeDelivery);
    } on DriverSessionExpiredException catch (error, stack) {
      _handleSessionFailure('session_expired', error, stack);
      return;
    } on DriverAccessDeniedException catch (error, stack) {
      _handleSessionFailure('access_denied', error, stack);
      return;
    } catch (error, stack) {
      inspector.recordTrackingFailure(
        code: 'heartbeat_unavailable',
        error: error,
        stack: stack,
      );
      _consecutiveFailures++;
      nextDelay = cadence.retryDelay(_consecutiveFailures);
    } finally {
      _cycleRunning = false;
      if (_canRun && _appInForeground) {
        _schedule(nextDelay);
      } else {
        _reconcileTrackingMode();
      }
    }
  }

  bool get _canRun =>
      _started && _gateReady && !_disposed && !_terminalAssignment;

  void _schedule(Duration delay) {
    if (!_canRun || !_appInForeground) return;
    scheduler.schedule(delay, runCycle);
  }

  void _reconcileTrackingMode() {
    scheduler.cancel();

    if (!_canRun) {
      _stopBackgroundStream();
      return;
    }

    if (_appInForeground) {
      _stopBackgroundStream();
      if (!_cycleRunning) {
        _schedule(Duration.zero);
      }
      return;
    }

    if (_activeDelivery) {
      _startBackgroundStream();
    } else {
      _stopBackgroundStream();
    }
  }

  void _applyReceipt(DriverHeartbeatReceipt? receipt) {
    if (receipt == null) return;

    final normalizedStatus = DriverLocationTrackingLifecyclePolicy.normalize(
      receipt.activeAssignmentStatus,
    );
    final wasActive = _activeDelivery;

    if (normalizedStatus != null) {
      _assignmentStatus = normalizedStatus;
      _terminalAssignment =
          DriverLocationTrackingLifecyclePolicy.stopsTracking(normalizedStatus);
      _activeDelivery =
          DriverLocationTrackingLifecyclePolicy.requiresActiveTracking(
        normalizedStatus,
      );
    } else {
      _activeDelivery = receipt.trackingRequired;
      if (receipt.activeAssignmentId == null) {
        _assignmentStatus = null;
      }
    }

    if (wasActive != _activeDelivery || !_appInForeground) {
      _reconcileTrackingMode();
    }
  }

  void _startBackgroundStream() {
    if (_backgroundSubscription != null || !_canRun || _appInForeground || !_activeDelivery) {
      return;
    }
    final source = locationSource;
    if (source is! DriverActiveDeliveryLocationSource) {
      return;
    }
    final backgroundSource = source as DriverActiveDeliveryLocationSource;

    _backgroundSubscription = backgroundSource.watchActiveDelivery().listen(
      (sample) => unawaited(_handleBackgroundSample(sample)),
      onError: (Object error, StackTrace stack) {
        inspector.recordTrackingFailure(
          code: 'background_location_unavailable',
          error: error,
          stack: stack,
        );
      },
    );
  }

  void _stopBackgroundStream() {
    final subscription = _backgroundSubscription;
    _backgroundSubscription = null;
    if (subscription != null) {
      unawaited(subscription.cancel());
    }
  }

  Future<void> _handleBackgroundSample(DriverLocationSample sample) async {
    if (!_canRun || _appInForeground || !_activeDelivery) return;
    _enqueue(sample);
    if (_backgroundFlushRunning) return;

    _backgroundFlushRunning = true;
    try {
      final receipt = await _flush();
      if (!_canRun) return;
      _consecutiveFailures = 0;
      _applyReceipt(receipt);
    } on DriverSessionExpiredException catch (error, stack) {
      _handleSessionFailure('session_expired', error, stack);
    } on DriverAccessDeniedException catch (error, stack) {
      _handleSessionFailure('access_denied', error, stack);
    } catch (error, stack) {
      inspector.recordTrackingFailure(
        code: 'background_heartbeat_unavailable',
        error: error,
        stack: stack,
      );
      _consecutiveFailures++;
    } finally {
      _backgroundFlushRunning = false;
    }
  }

  void _handleSessionFailure(String code, Object error, StackTrace stack) {
    inspector.recordTrackingFailure(
      code: code,
      error: error,
      stack: stack,
    );
    stop(clearQueue: true);
    onSessionInvalid?.call();
  }

  void _enqueue(DriverLocationSample sample) {
    _queue.add(sample);
    _queue.sort((left, right) => left.capturedAt.compareTo(right.capturedAt));

    while (_queue.length > queueLimit) {
      _queue.removeAt(0);
    }
  }

  Future<DriverHeartbeatReceipt?> _flush() async {
    DriverHeartbeatReceipt? lastReceipt;

    while (_queue.isNotEmpty && _canRun) {
      final sample = _queue.first;
      lastReceipt = await heartbeatClient.send(sample);
      if (!_canRun) return lastReceipt;
      _queue.removeAt(0);
    }

    return lastReceipt;
  }
}
