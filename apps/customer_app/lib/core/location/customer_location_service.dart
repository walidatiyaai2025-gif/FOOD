import 'package:geolocator/geolocator.dart';

class CustomerLocationPoint {
  const CustomerLocationPoint({
    required this.latitude,
    required this.longitude,
    required this.accuracyMeters,
  });

  final double latitude;
  final double longitude;
  final double accuracyMeters;
}

class CustomerLocationException implements Exception {
  const CustomerLocationException(this.code);

  final String code;
}

abstract interface class CustomerLocationService {
  Future<CustomerLocationPoint> currentLocation();
}

class GeolocatorCustomerLocationService implements CustomerLocationService {
  const GeolocatorCustomerLocationService();

  @override
  Future<CustomerLocationPoint> currentLocation() async {
    final enabled = await Geolocator.isLocationServiceEnabled();
    if (!enabled) {
      throw const CustomerLocationException('location_services_disabled');
    }

    var permission = await Geolocator.checkPermission();
    if (permission == LocationPermission.denied) {
      permission = await Geolocator.requestPermission();
    }

    if (permission == LocationPermission.denied) {
      throw const CustomerLocationException('location_permission_denied');
    }
    if (permission == LocationPermission.deniedForever) {
      throw const CustomerLocationException('location_permission_denied_forever');
    }

    final position = await Geolocator.getCurrentPosition(
      locationSettings: const LocationSettings(
        accuracy: LocationAccuracy.high,
      ),
    );

    return CustomerLocationPoint(
      latitude: position.latitude,
      longitude: position.longitude,
      accuracyMeters: position.accuracy,
    );
  }
}
