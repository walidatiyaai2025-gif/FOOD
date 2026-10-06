import 'dart:async';
import 'dart:io';
import 'dart:ui';

import 'package:flutter/material.dart';

import 'app.dart';
import 'core/config/foodex_environment.dart';
import 'core/config/mobile_runtime_visibility.dart';
import 'core/diagnostics/driver_runtime_inspector.dart';
import 'core/location/driver_location_gate_service.dart';
import 'core/push/firebase_push_service.dart';
import 'core/version/driver_version_policy_client.dart';

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
  bool _showPersistentFooter = true;
  late final HttpDriverVersionPolicyClient _versionPolicyClient =
      HttpDriverVersionPolicyClient(
        baseUrl: FoodexEnvironment.apiBaseUrl,
        platform: Platform.isIOS ? 'ios' : 'android',
      );

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      unawaited(_bootstrapPush());
      unawaited(_loadRuntimeVisibility());
    });
  }

  Future<void> _loadRuntimeVisibility() async {
    final visibility = await MobileRuntimeVisibility.fetch(
      baseUrl: FoodexEnvironment.apiBaseUrl,
      locale: 'ar',
    );
    if (!mounted) return;
    setState(() {
      _showPersistentFooter = visibility.showPersistentFooter;
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
    _versionPolicyClient.close();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return FoodexDriverApp(
      pushService: _pushService,
      locationGateService: const GeolocatorDriverLocationGateService(),
      versionPolicyClient: _versionPolicyClient,
      showPersistentFooter: _showPersistentFooter,
    );
  }
}
