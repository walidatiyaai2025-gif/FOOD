import 'dart:async';
import 'dart:convert';
import 'dart:io';

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
  const DriverHeartbeatReceipt({this.activeAssignmentId});

  final int? activeAssignmentId;
}

abstract interface class DriverLocationSource {
  Future<DriverLocationSample> current();
}

class GeolocatorDriverLocationSource implements DriverLocationSource {
  const GeolocatorDriverLocationSource();

  @override
  Future<DriverLocationSample> current() async {
    final position = await Geolocator.getCurrentPosition(
      locationSettings: const LocationSettings(
        accuracy: LocationAccuracy.high,
      ),
    );

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
  })  : _endpoint = _heartbeatEndpoint(baseUrl),
        _token = token,
        _client = DriverDiagnosticHttpClient(client ?? http.Client());

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
      response = await _client.post(
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
      );
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

    return DriverHeartbeatReceipt(
      activeAssignmentId: activeAssignment is num
          ? activeAssignment.toInt()
          : null,
    );
  }

  void close() => _client.close();
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
  bool _disposed = false;
  bool _activeDelivery = false;

  bool get isStarted => _started;
  bool get gateReady => _gateReady;
  bool get activeDelivery => _activeDelivery;
  int get queuedSamples => _queue.length;

  @override
  void start() {
    if (_disposed || _started) return;
    _started = true;
    if (_gateReady) {
      _schedule(Duration.zero);
    }
  }

  @override
  void setGateReady(bool ready) {
    if (_disposed || _gateReady == ready) return;
    _gateReady = ready;

    if (!ready) {
      scheduler.cancel();
      return;
    }

    if (_started && !_cycleRunning) {
      _schedule(Duration.zero);
    }
  }

  @override
  void stop({bool clearQueue = true}) {
    _started = false;
    _gateReady = false;
    _activeDelivery = false;
    scheduler.cancel();
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
    if (!_canRun || _cycleRunning) return;
    _cycleRunning = true;
    var nextDelay = cadence.retry;

    try {
      final sample = await locationSource.current();
      if (!_canRun) return;

      _enqueue(sample);
      final receipt = await _flush();
      if (!_canRun) return;

      if (receipt != null) {
        _activeDelivery = receipt.activeAssignmentId != null;
      }
      nextDelay = cadence.next(activeDelivery: _activeDelivery);
    } on DriverSessionExpiredException catch (error, stack) {
      inspector.recordTrackingFailure(
        code: 'session_expired',
        error: error,
        stack: stack,
      );
      stop(clearQueue: true);
      onSessionInvalid?.call();
      return;
    } on DriverAccessDeniedException catch (error, stack) {
      inspector.recordTrackingFailure(
        code: 'access_denied',
        error: error,
        stack: stack,
      );
      stop(clearQueue: true);
      onSessionInvalid?.call();
      return;
    } catch (error, stack) {
      inspector.recordTrackingFailure(
        code: 'heartbeat_unavailable',
        error: error,
        stack: stack,
      );
      nextDelay = cadence.retry;
    } finally {
      _cycleRunning = false;
      if (_canRun) {
        _schedule(nextDelay);
      }
    }
  }

  bool get _canRun => _started && _gateReady && !_disposed;

  void _schedule(Duration delay) {
    scheduler.schedule(delay, runCycle);
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
