import 'dart:async';

import 'package:geolocator/geolocator.dart';

import '../api/http_van_api.dart';
import '../auth/van_session.dart';

class VanLocationSample {
  const VanLocationSample({
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

abstract interface class VanLocationSource {
  Future<VanLocationSample?> current();
}

class GeolocatorVanLocationSource implements VanLocationSource {
  const GeolocatorVanLocationSource();

  @override
  Future<VanLocationSample?> current() async {
    if (!await Geolocator.isLocationServiceEnabled()) {
      return null;
    }

    var permission = await Geolocator.checkPermission();
    if (permission == LocationPermission.denied) {
      permission = await Geolocator.requestPermission();
    }
    if (permission == LocationPermission.denied ||
        permission == LocationPermission.deniedForever) {
      return null;
    }

    final position = await Geolocator.getCurrentPosition(
      locationSettings: const LocationSettings(
        accuracy: LocationAccuracy.high,
        timeLimit: Duration(seconds: 12),
      ),
    );

    return VanLocationSample(
      latitude: position.latitude,
      longitude: position.longitude,
      accuracy: position.accuracy >= 0 ? position.accuracy : null,
      speed: position.speed >= 0 && position.speed <= 500 ? position.speed : null,
      heading: position.heading >= 0 && position.heading <= 360
          ? position.heading
          : null,
      capturedAt: position.timestamp,
      isMocked: position.isMocked,
    );
  }
}

abstract interface class VanLocationHeartbeatClient {
  Future<void> send(VanLocationSample sample);
}

class HttpVanLocationHeartbeatClient implements VanLocationHeartbeatClient {
  HttpVanLocationHeartbeatClient(this._api);

  final VanApiClient _api;

  @override
  Future<void> send(VanLocationSample sample) async {
    await _api.postJson(
      'van/location/heartbeat',
      body: {
        'latitude': sample.latitude,
        'longitude': sample.longitude,
        if (sample.accuracy != null) 'accuracy': sample.accuracy,
        if (sample.speed != null) 'speed': sample.speed,
        if (sample.heading != null) 'heading': sample.heading,
        'captured_at': sample.capturedAt.toUtc().toIso8601String(),
        if (sample.isMocked != null) 'is_mocked': sample.isMocked,
      },
    );
  }
}

class VanLocationTrackingController {
  VanLocationTrackingController({
    required this.source,
    required this.heartbeatClient,
    this.onSessionExpired,
    this.interval = const Duration(seconds: 20),
  });

  final VanLocationSource source;
  final VanLocationHeartbeatClient heartbeatClient;
  final Future<void> Function()? onSessionExpired;
  final Duration interval;

  Timer? _timer;
  bool _running = false;
  bool _syncing = false;

  bool get isRunning => _running;

  void start() {
    if (_running) return;
    _running = true;
    unawaited(syncNow());
    _timer = Timer.periodic(interval, (_) => unawaited(syncNow()));
  }

  Future<void> syncNow() async {
    if (!_running && _timer != null) return;
    if (_syncing) return;

    _syncing = true;
    try {
      final sample = await source.current();
      if (sample == null) return;
      await heartbeatClient.send(sample);
    } on VanSessionExpiredException {
      stop();
      await onSessionExpired?.call();
    } on VanAccessDeniedException {
      stop();
    } on VanOfflineException {
      // Keep the tracker alive; the next cadence will retry automatically.
    } on VanApiException {
      // Transient server failures must not terminate live tracking.
    } catch (_) {
      // Location services can be disabled or temporarily unavailable.
    } finally {
      _syncing = false;
    }
  }

  void stop() {
    _running = false;
    _timer?.cancel();
    _timer = null;
  }

  void dispose() => stop();
}
