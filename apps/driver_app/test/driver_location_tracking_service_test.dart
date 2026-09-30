import 'dart:collection';
import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_driver_app/core/auth/driver_session.dart';
import 'package:foodex_driver_app/core/diagnostics/driver_runtime_inspector.dart';
import 'package:foodex_driver_app/core/location/driver_location_tracking_service.dart';

class _FakeScheduler implements DriverTrackingScheduler {
  Duration? delay;
  Future<void> Function()? callback;
  int cancellations = 0;

  @override
  void schedule(Duration delay, Future<void> Function() callback) {
    this.delay = delay;
    this.callback = callback;
  }

  @override
  void cancel() {
    cancellations++;
    delay = null;
    callback = null;
  }

  Future<void> fire() async {
    final pending = callback;
    callback = null;
    delay = null;
    if (pending != null) {
      await pending();
    }
  }
}

class _FakeLocationSource implements DriverLocationSource {
  _FakeLocationSource(Iterable<DriverLocationSample> samples)
      : samples = Queue<DriverLocationSample>.of(samples);

  final Queue<DriverLocationSample> samples;

  @override
  Future<DriverLocationSample> current() async {
    if (samples.isEmpty) {
      throw StateError('No fake location sample available.');
    }
    return samples.removeFirst();
  }
}

class _FakeHeartbeatClient implements DriverLocationHeartbeatClient {
  _FakeHeartbeatClient({
    this.failuresRemaining = 0,
    Iterable<int?> activeAssignments = const <int?>[],
    this.failure,
  }) : activeAssignments = Queue<int?>.of(activeAssignments);

  int failuresRemaining;
  final Object? failure;
  final Queue<int?> activeAssignments;
  final List<DriverLocationSample> attempts = <DriverLocationSample>[];
  final List<DriverLocationSample> successful = <DriverLocationSample>[];

  @override
  Future<DriverHeartbeatReceipt> send(DriverLocationSample sample) async {
    attempts.add(sample);
    if (failuresRemaining > 0) {
      failuresRemaining--;
      throw failure ?? const DriverOfflineException();
    }

    successful.add(sample);
    return DriverHeartbeatReceipt(
      activeAssignmentId:
          activeAssignments.isEmpty ? null : activeAssignments.removeFirst(),
    );
  }
}

DriverLocationSample _sample(int second) => DriverLocationSample(
      latitude: 29.3759 + (second / 100000),
      longitude: 47.9774 + (second / 100000),
      accuracy: 5,
      speed: 2,
      heading: 90,
      capturedAt: DateTime.utc(2026, 9, 30, 6, 0, second),
      isMocked: false,
    );

void main() {
  test('tracking waits for gate readiness and switches active/idle cadence',
      () async {
    final scheduler = _FakeScheduler();
    final heartbeat = _FakeHeartbeatClient(
      activeAssignments: <int?>[44, null],
    );
    final service = DriverLocationTrackingService(
      locationSource: _FakeLocationSource([_sample(1), _sample(2)]),
      heartbeatClient: heartbeat,
      scheduler: scheduler,
      inspector: DriverRuntimeInspector(maxEvents: 20),
    );

    service.start();
    expect(scheduler.callback, isNull);
    expect(heartbeat.attempts, isEmpty);

    service.setGateReady(true);
    expect(scheduler.delay, Duration.zero);

    await scheduler.fire();
    expect(heartbeat.successful.map((sample) => sample.capturedAt.second), [1]);
    expect(service.activeDelivery, isTrue);
    expect(scheduler.delay, const Duration(seconds: 7));

    await scheduler.fire();
    expect(
      heartbeat.successful.map((sample) => sample.capturedAt.second),
      [1, 2],
    );
    expect(service.activeDelivery, isFalse);
    expect(scheduler.delay, const Duration(seconds: 20));

    service.dispose();
  });

  test('offline samples flush oldest to newest after connectivity returns',
      () async {
    final scheduler = _FakeScheduler();
    final heartbeat = _FakeHeartbeatClient(failuresRemaining: 2);
    final service = DriverLocationTrackingService(
      locationSource:
          _FakeLocationSource([_sample(1), _sample(2), _sample(3)]),
      heartbeatClient: heartbeat,
      scheduler: scheduler,
      inspector: DriverRuntimeInspector(maxEvents: 20),
    );

    service.start();
    service.setGateReady(true);

    await scheduler.fire();
    expect(service.queuedSamples, 1);
    expect(scheduler.delay, const Duration(seconds: 10));

    await scheduler.fire();
    expect(service.queuedSamples, 2);
    expect(scheduler.delay, const Duration(seconds: 10));

    await scheduler.fire();

    expect(
      heartbeat.successful.map((sample) => sample.capturedAt.second),
      [1, 2, 3],
    );
    expect(service.queuedSamples, 0);
    expect(scheduler.delay, const Duration(seconds: 20));

    service.dispose();
  });

  test('bounded queue keeps recent samples and stop clears session state',
      () async {
    final scheduler = _FakeScheduler();
    final heartbeat = _FakeHeartbeatClient(failuresRemaining: 20);
    final service = DriverLocationTrackingService(
      locationSource: _FakeLocationSource(
        [_sample(1), _sample(2), _sample(3), _sample(4)],
      ),
      heartbeatClient: heartbeat,
      scheduler: scheduler,
      inspector: DriverRuntimeInspector(maxEvents: 20),
      queueLimit: 2,
    );

    service.start();
    service.setGateReady(true);

    await scheduler.fire();
    await scheduler.fire();
    await scheduler.fire();
    await scheduler.fire();

    expect(service.queuedSamples, 2);

    service.stop(clearQueue: true);
    expect(service.isStarted, isFalse);
    expect(service.gateReady, isFalse);
    expect(service.queuedSamples, 0);
    expect(scheduler.callback, isNull);

    service.dispose();
  });

  test('session invalid heartbeat stops tracking and requests local logout',
      () async {
    final scheduler = _FakeScheduler();
    final heartbeat = _FakeHeartbeatClient(
      failuresRemaining: 1,
      failure: const DriverSessionExpiredException(),
    );
    var invalidations = 0;
    final service = DriverLocationTrackingService(
      locationSource: _FakeLocationSource([_sample(1)]),
      heartbeatClient: heartbeat,
      scheduler: scheduler,
      inspector: DriverRuntimeInspector(maxEvents: 20),
      onSessionInvalid: () => invalidations++,
    );

    service.start();
    service.setGateReady(true);
    await scheduler.fire();

    expect(invalidations, 1);
    expect(service.isStarted, isFalse);
    expect(service.queuedSamples, 0);
    expect(scheduler.callback, isNull);

    service.dispose();
  });

  test('tracking diagnostics never export coordinates or auth-like secrets',
      () async {
    final scheduler = _FakeScheduler();
    final inspector = DriverRuntimeInspector(maxEvents: 20);
    final heartbeat = _FakeHeartbeatClient(
      failuresRemaining: 1,
      failure: Exception(
        'latitude=29.3759000 longitude=47.9774000 token=secret-token',
      ),
    );
    final service = DriverLocationTrackingService(
      locationSource: _FakeLocationSource([_sample(1)]),
      heartbeatClient: heartbeat,
      scheduler: scheduler,
      inspector: inspector,
    );

    service.start();
    service.setGateReady(true);
    await scheduler.fire();

    final exported = jsonEncode(inspector.snapshot());
    expect(exported, isNot(contains('29.3759')));
    expect(exported, isNot(contains('47.9774')));
    expect(exported, isNot(contains('secret-token')));
    expect(exported, contains('driver_tracking_failure'));

    service.dispose();
  });
}
