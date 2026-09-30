import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/localization/driver_translations.dart';
import '../../core/location/driver_location_gate_service.dart';
import '../../core/theme/foodex_theme.dart';

class DriverLocationGate extends StatefulWidget {
  const DriverLocationGate({
    super.key,
    required this.child,
    required this.onLogout,
    this.service = const GeolocatorDriverLocationGateService(),
    this.recheckInterval = const Duration(seconds: 5),
    this.onStatusChanged,
  });

  final Widget child;
  final Future<void> Function() onLogout;
  final DriverLocationGateService service;
  final Duration recheckInterval;
  final ValueChanged<DriverLocationGateStatus>? onStatusChanged;

  @override
  State<DriverLocationGate> createState() => _DriverLocationGateState();
}

class _DriverLocationGateState extends State<DriverLocationGate>
    with WidgetsBindingObserver {
  DriverLocationGateStatus? _status;
  bool _checking = true;
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    unawaited(_check(requestPermission: true));
    _timer = Timer.periodic(widget.recheckInterval, (_) {
      unawaited(_check());
    });
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      unawaited(_check());
    }
  }

  Future<void> _check({bool requestPermission = false}) async {
    try {
      final status = await widget.service.check(
        requestPermission: requestPermission,
      );
      if (!mounted) return;
      final changed = _status != status;
      setState(() {
        _status = status;
        _checking = false;
      });
      if (changed) {
        widget.onStatusChanged?.call(status);
      }
    } catch (_) {
      if (!mounted) return;
      const status = DriverLocationGateStatus.permissionDenied;
      final changed = _status != status;
      setState(() {
        _status = status;
        _checking = false;
      });
      if (changed) {
        widget.onStatusChanged?.call(status);
      }
    }
  }

  @override
  void dispose() {
    _timer?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (!_checking && _status == DriverLocationGateStatus.ready) {
      return widget.child;
    }

    final status = _status;
    final deniedForever =
        status == DriverLocationGateStatus.permissionDeniedForever;

    return Scaffold(
      key: const Key('driver-location-gate'),
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 460),
              child: Card(
                child: Padding(
                  padding: const EdgeInsets.all(24),
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      const Icon(
                        Icons.location_on_outlined,
                        size: 56,
                        color: FoodexBrand.green,
                      ),
                      const SizedBox(height: 16),
                      Text(
                        context.tr('driver.location.title'),
                        textAlign: TextAlign.center,
                        style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                              fontWeight: FontWeight.w900,
                            ),
                      ),
                      const SizedBox(height: 10),
                      Text(
                        context.tr(_messageKey(status)),
                        key: const Key('driver-location-gate-message'),
                        textAlign: TextAlign.center,
                      ),
                      const SizedBox(height: 20),
                      FilledButton.icon(
                        key: const Key('driver-location-retry'),
                        onPressed: _checking
                            ? null
                            : () => _check(requestPermission: true),
                        icon: _checking
                            ? const SizedBox.square(
                                dimension: 18,
                                child: CircularProgressIndicator(
                                  strokeWidth: 2,
                                  color: Colors.white,
                                ),
                              )
                            : const Icon(Icons.refresh_rounded),
                        label: Text(context.tr('driver.location.retry')),
                      ),
                      const SizedBox(height: 10),
                      OutlinedButton.icon(
                        key: const Key('driver-location-app-settings'),
                        onPressed: () async {
                          await widget.service.openAppSettings();
                        },
                        icon: const Icon(Icons.app_settings_alt_outlined),
                        label: Text(
                          context.tr('driver.location.open_app_settings'),
                        ),
                      ),
                      const SizedBox(height: 10),
                      OutlinedButton.icon(
                        key: const Key('driver-location-location-settings'),
                        onPressed: () async {
                          await widget.service.openLocationSettings();
                        },
                        icon: const Icon(Icons.location_searching_outlined),
                        label: Text(
                          context.tr('driver.location.open_location_settings'),
                        ),
                      ),
                      if (deniedForever) ...[
                        const SizedBox(height: 8),
                        Text(
                          context.tr('driver.location.denied_forever_hint'),
                          textAlign: TextAlign.center,
                          style: Theme.of(context).textTheme.bodySmall,
                        ),
                      ],
                      const SizedBox(height: 18),
                      TextButton.icon(
                        key: const Key('driver-location-logout'),
                        onPressed: widget.onLogout,
                        icon: const Icon(Icons.logout_rounded),
                        label: Text(context.tr('driver.logout')),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }

  static String _messageKey(DriverLocationGateStatus? status) {
    return switch (status) {
      DriverLocationGateStatus.serviceDisabled =>
        'driver.location.service_disabled',
      DriverLocationGateStatus.permissionDenied =>
        'driver.location.permission_denied',
      DriverLocationGateStatus.permissionDeniedForever =>
        'driver.location.permission_denied_forever',
      DriverLocationGateStatus.reducedAccuracy =>
        'driver.location.precise_required',
      DriverLocationGateStatus.ready => 'driver.location.ready',
      null => 'driver.location.checking',
    };
  }
}
