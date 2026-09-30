import 'package:geolocator/geolocator.dart';

enum DriverLocationGateStatus {
  ready,
  serviceDisabled,
  permissionDenied,
  permissionDeniedForever,
  reducedAccuracy,
}

abstract interface class DriverLocationGateService {
  Future<DriverLocationGateStatus> check({bool requestPermission = false});
  Future<bool> openAppSettings();
  Future<bool> openLocationSettings();
}

class GeolocatorDriverLocationGateService implements DriverLocationGateService {
  const GeolocatorDriverLocationGateService();

  @override
  Future<DriverLocationGateStatus> check({bool requestPermission = false}) async {
    if (!await Geolocator.isLocationServiceEnabled()) {
      return DriverLocationGateStatus.serviceDisabled;
    }

    var permission = await Geolocator.checkPermission();
    if (permission == LocationPermission.denied && requestPermission) {
      permission = await Geolocator.requestPermission();
    }

    if (permission == LocationPermission.deniedForever) {
      return DriverLocationGateStatus.permissionDeniedForever;
    }
    if (permission == LocationPermission.denied ||
        permission == LocationPermission.unableToDetermine) {
      return DriverLocationGateStatus.permissionDenied;
    }

    final accuracy = await Geolocator.getLocationAccuracy();
    if (accuracy == LocationAccuracyStatus.reduced) {
      return DriverLocationGateStatus.reducedAccuracy;
    }

    return DriverLocationGateStatus.ready;
  }

  @override
  Future<bool> openAppSettings() => Geolocator.openAppSettings();

  @override
  Future<bool> openLocationSettings() => Geolocator.openLocationSettings();
}
