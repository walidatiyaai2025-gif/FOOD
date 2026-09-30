import 'dart:async';
import 'dart:ui';

import 'package:flutter/material.dart';

import 'app.dart';
import 'core/diagnostics/driver_runtime_inspector.dart';
import 'core/location/driver_location_gate_service.dart';
import 'core/push/firebase_push_service.dart';

Future<void> main() async {
  await runZonedGuarded(
    () async {
      WidgetsFlutterBinding.ensureInitialized();
      await DriverRuntimeInspector.instance.initialize();

      final previousFlutterError = FlutterError.onError;
      FlutterError.onError = (details) {
        DriverRuntimeInspector.instance.recordException(
          details.exception,
          details.stack ?? StackTrace.current,
          source: 'flutter',
        );
        if (previousFlutterError != null) {
          previousFlutterError(details);
        } else {
          FlutterError.presentError(details);
        }
      };

      final dispatcher = PlatformDispatcher.instance;
      final previousPlatformError = dispatcher.onError;
      dispatcher.onError = (error, stack) {
        DriverRuntimeInspector.instance.recordException(
          error,
          stack,
          source: 'platform',
        );
        return previousPlatformError?.call(error, stack) ?? false;
      };

      runApp(const _FoodexDriverBootstrap());
    },
    (error, stack) {
      DriverRuntimeInspector.instance.recordException(
        error,
        stack,
        source: 'zone',
      );
    },
  );
}

class _FoodexDriverBootstrap extends StatefulWidget {
  const _FoodexDriverBootstrap();

  @override
  State<_FoodexDriverBootstrap> createState() => _FoodexDriverBootstrapState();
}

class _FoodexDriverBootstrapState extends State<_FoodexDriverBootstrap> {
  DriverFirebasePushService? _pushService;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      unawaited(_bootstrapPush());
    });
  }

  Future<void> _bootstrapPush() async {
    final service = await DriverFirebasePushService.bootstrap();
    if (!mounted) {
      await service.dispose();
      return;
    }
    setState(() => _pushService = service);
  }

  @override
  void dispose() {
    unawaited(_pushService?.dispose());
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return FoodexDriverApp(\n      pushService: _pushService,\n      locationGateService: const GeolocatorDriverLocationGateService(),\n    );
  }
}
