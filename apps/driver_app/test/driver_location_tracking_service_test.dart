import 'dart:async';
import 'dart:collection';
import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_driver_app/core/auth/driver_session.dart';
import 'package:foodex_driver_app/core/diagnostics/driver_runtime_inspector.dart';
import 'package:foodex_driver_app/core/location/driver_location_tracking_service.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

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

class _FakeBackgroundLocationSource
    implements DriverLocationSource, DriverActiveDeliveryLocationSource {
  _FakeBackgroundLocationSource(Iterable<DriverLocationSample> foreground)
      : foreground = Queue<DriverLocationSample>.of(foreground);

  final Queue<DriverLocationSample> foreground;
  final StreamController<DriverLocationSample> background =
      StreamController<DriverLocationSample>.broadcast();
  int watches = 0;

  @override
  Future<DriverLocationSample> current() async {
    if (foreground.isEmpty) {
      throw StateError('No fake foreground sample available.');
    }
    return foreground.removeFirst();
  }

  @override
  Stream<DriverLocationSample> watchActiveDelivery() {
    watches++;
    return background.stream;
  }

  void emit(DriverLocationSample sample) => background.add(sample);

  Future<void> close() => background.close();
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
  test('tracking lifecycle policy is active only for delivery execution states', () {
    for (final status in ['accepted', 'picked_up', 'out_for_delivery']) {
      expect(
        DriverLocationTrackingLifecyclePolicy.requiresActiveTracking(status),
        isTrue,
        reason: status,
      );
      expect(
        DriverLocationTrackingLifecyclePolicy.stopsTracking(status),
        isFalse,
        reason: status,
      );
    }

    expect(
      DriverLocationTrackingLifecyclePolicy.requiresActiveTracking('assigned'),
      isFalse,
    );

    for (final status in [
      'delivered',
      'failed',
      'cancelled',
      'unassigned',
      'reassigned',
    ]) {
      expect(
        DriverLocationTrackingLifecyclePolicy.stopsTracking(status),
        isTrue,
        reason: status,
      );
    }
  });

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

  test('background stream runs only for active delivery and stops when assignment ends',
      () async {
    final scheduler = _FakeScheduler();
    final source = _FakeBackgroundLocationSource([_sample(1)]);
    final heartbeat = _FakeHeartbeatClient(
      activeAssignments: <int?>[44, 44, null],
    );
    final service = DriverLocationTrackingService(
      locationSource: source,
      heartbeatClient: heartbeat,
      scheduler: scheduler,
      inspector: DriverRuntimeInspector(maxEvents: 20),
    );

    service.start();
    service.setGateReady(true);
    await scheduler.fire();

    expect(service.activeDelivery, isTrue);
    expect(service.backgroundTracking, isFalse);

    service.setAppInForeground(false);
    expect(service.appInForeground, isFalse);
    expect(service.backgroundTracking, isTrue);
    expect(source.watches, 1);
    expect(scheduler.callback, isNull);

    source.emit(_sample(2));
    await Future<void>.delayed(Duration.zero);
    expect(heartbeat.successful.map((sample) => sample.capturedAt.second), [1, 2]);
    expect(service.backgroundTracking, isTrue);

    source.emit(_sample(3));
    await Future<void>.delayed(Duration.zero);
    expect(heartbeat.successful.map((sample) => sample.capturedAt.second), [1, 2, 3]);
    expect(service.activeDelivery, isFalse);
    expect(service.backgroundTracking, isFalse);

    service.dispose();
    await source.close();
  });

  test('background heartbeat backs off and keeps only the newest outage sample',
      () async {
    final scheduler = _FakeScheduler();
    final source = _FakeBackgroundLocationSource([_sample(1)]);
    final heartbeat = _FakeHeartbeatClient(
      activeAssignments: <int?>[44, 44],
    );
    final service = DriverLocationTrackingService(
      locationSource: source,
      heartbeatClient: heartbeat,
      scheduler: scheduler,
      cadence: const DriverTrackingCadencePolicy(
        retry: Duration(milliseconds: 1),
      ),
      inspector: DriverRuntimeInspector(maxEvents: 20),
    );

    service.start();
    service.setGateReady(true);
    await scheduler.fire();
    service.setAppInForeground(false);
    heartbeat.failuresRemaining = 1;

    source.emit(_sample(2));
    await Future<void>.delayed(Duration.zero);
    expect(heartbeat.attempts.length, 2);
    expect(service.queuedSamples, 1);

    source.emit(_sample(3));
    source.emit(_sample(4));
    await Future<void>.delayed(Duration.zero);
    expect(heartbeat.attempts.length, 2);
    expect(service.queuedSamples, 1);

    await Future<void>.delayed(const Duration(milliseconds: 160));
    source.emit(_sample(5));
    await Future<void>.delayed(Duration.zero);

    expect(heartbeat.successful.last.capturedAt.second, 5);
    expect(service.queuedSamples, 0);

    service.dispose();
    await source.close();
  });

  test('terminal assignment status stops active tracking until new work arrives',
      () async {
    final scheduler = _FakeScheduler();
    final source = _FakeBackgroundLocationSource([_sample(1), _sample(2)]);
    final service = DriverLocationTrackingService(
      locationSource: source,
      heartbeatClient: _FakeHeartbeatClient(),
      scheduler: scheduler,
      inspector: DriverRuntimeInspector(maxEvents: 20),
    );

    service.start();
    service.setGateReady(true);
    service.setAssignmentStatus('accepted');
    expect(service.activeDelivery, isTrue);
    expect(service.terminalAssignment, isFalse);

    service.setAppInForeground(false);
    expect(service.backgroundTracking, isTrue);

    service.setAssignmentStatus('delivered');
    expect(service.activeDelivery, isFalse);
    expect(service.terminalAssignment, isTrue);
    expect(service.backgroundTracking, isFalse);
    expect(scheduler.callback, isNull);
    expect(service.queuedSamples, 0);

    service.setAppInForeground(true);
    expect(scheduler.callback, isNull);

    service.setAssignmentStatus('assigned');
    expect(service.terminalAssignment, isFalse);
    expect(service.activeDelivery, isFalse);
    expect(scheduler.delay, Duration.zero);

    service.dispose();
    await source.close();
  });

  test('background idle and revoked gate never keep high frequency tracking alive',
      () async {
    final scheduler = _FakeScheduler();
    final source = _FakeBackgroundLocationSource([_sample(1), _sample(2)]);
    final heartbeat = _FakeHeartbeatClient(
      activeAssignments: <int?>[null, 88],
    );
    final service = DriverLocationTrackingService(
      locationSource: source,
      heartbeatClient: heartbeat,
      scheduler: scheduler,
      inspector: DriverRuntimeInspector(maxEvents: 20),
    );

    service.start();
    service.setGateReady(true);
    await scheduler.fire();
    expect(service.activeDelivery, isFalse);

    service.setAppInForeground(false);
    expect(service.backgroundTracking, isFalse);
    expect(scheduler.callback, isNull);

    service.setAppInForeground(true);
    await scheduler.fire();
    expect(service.activeDelivery, isTrue);

    service.setAppInForeground(false);
    expect(service.backgroundTracking, isTrue);

    service.setGateReady(false);
    expect(service.backgroundTracking, isFalse);
    expect(service.gateReady, isFalse);

    service.dispose();
    await source.close();
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
    expect(scheduler.delay, const Duration(milliseconds: 10137));

    await scheduler.fire();
    expect(service.queuedSamples, 2);
    expect(scheduler.delay, const Duration(milliseconds: 20274));

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

  test('HTTP heartbeat client matches backend contract and active assignment',
      () async {
    const token = 'driver-secret-token';
    late Map<String, dynamic> payload;

    final client = HttpDriverLocationHeartbeatClient(
      baseUrl: 'https://foodex.example/base',
      token: token,
      client: MockClient((request) async {
        expect(request.method, 'POST');
        expect(request.url.path, '/base/api/v1/driver/location/heartbeat');
        expect(request.headers['authorization'], 'Bearer $token');
        expect(request.headers['accept'], 'application/json');

        payload = Map<String, dynamic>.from(
          jsonDecode(request.body) as Map,
        );
        return http.Response(
          jsonEncode({
            'data': {
              'driver_id': 3,
              'store_id': 5,
              'channel': 'b2c',
              'active_assignment_id': 77,
              'active_assignment_status': 'out_for_delivery',
              'tracking_required': true,
            },
          }),
          200,
          headers: const {'content-type': 'application/json'},
        );
      }),
    );

    final receipt = await client.send(_sample(1));

    expect(receipt.activeAssignmentId, 77);
    expect(receipt.activeAssignmentStatus, 'out_for_delivery');
    expect(receipt.trackingRequired, isTrue);
    expect(payload['captured_at'], '2026-09-30T06:00:01.000Z');
    expect(payload['app_version'], driverAppVersion);
    expect(payload['accuracy'], 5);
    expect(payload['speed'], 2);
    expect(payload['heading'], 90);
    expect(payload['is_mocked'], isFalse);

    client.close();
  });

  test('HTTP heartbeat client times out stalled requests', () async {
    final client = HttpDriverLocationHeartbeatClient(
      baseUrl: 'https://foodex.example',
      token: 'driver-secret-token',
      requestTimeout: const Duration(milliseconds: 5),
      client: MockClient((_) async {
        await Future<void>.delayed(const Duration(milliseconds: 50));
        return http.Response('{}', 200);
      }),
    );

    await expectLater(
      client.send(_sample(1)),
      throwsA(isA<TimeoutException>()),
    );
    client.close();
  });

  test('HTTP heartbeat client maps authorization and API failures', () async {
    Future<void> expectStatus(
      int status,
      Matcher matcher,
    ) async {
      final client = HttpDriverLocationHeartbeatClient(
        baseUrl: 'https://foodex.example',
        token: 'driver-secret-token',
        client: MockClient(
          (_) async => http.Response(
            jsonEncode({'message': 'failed'}),
            status,
            headers: const {'content-type': 'application/json'},
          ),
        ),
      );

      await expectLater(client.send(_sample(1)), throwsA(matcher));
      client.close();
    }

    await expectStatus(401, isA<DriverSessionExpiredException>());
    await expectStatus(403, isA<DriverAccessDeniedException>());
    await expectStatus(500, isA<DriverApiException>());
  });

  test('HTTP heartbeat failure diagnostics exclude bearer and coordinates',
      () async {
    const token = 'driver-private-bearer-token';
    final inspector = DriverRuntimeInspector.instance;
    await inspector.clear();

    final client = HttpDriverLocationHeartbeatClient(
      baseUrl: 'https://foodex.example',
      token: token,
      client: MockClient(
        (_) async => http.Response(
          jsonEncode({
            'latitude': 29.37591,
            'longitude': 47.97741,
            'token': token,
          }),
          503,
          headers: const {'content-type': 'application/json'},
        ),
      ),
    );

    await expectLater(
      client.send(_sample(1)),
      throwsA(isA<DriverApiException>()),
    );

    final exported = jsonEncode(
      inspector.exportPayload(locale: 'en', authenticated: true),
    );
    expect(exported, isNot(contains(token)));
    expect(exported, isNot(contains('29.37591')));
    expect(exported, isNot(contains('47.97741')));
    expect(exported, contains('driver/location/heartbeat'));
    expect(exported, contains('"status_code":503'));

    client.close();
    await inspector.clear();
  });

}
