import 'dart:convert';

import 'package:geolocator/geolocator.dart';
import 'package:http/http.dart' as http;

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


class CustomerGeocodedAddress {
  const CustomerGeocodedAddress({
    this.line1,
    this.area,
    this.governorate,
    this.city,
    this.country,
    this.countryCode,
    this.building,
  });

  final String? line1;
  final String? area;
  final String? governorate;
  final String? city;
  final String? country;
  final String? countryCode;
  final String? building;
}

abstract interface class CustomerReverseGeocoder {
  Future<CustomerGeocodedAddress?> reverse({
    required double latitude,
    required double longitude,
    String? languageCode,
  });
}

class NominatimCustomerReverseGeocoder implements CustomerReverseGeocoder {
  const NominatimCustomerReverseGeocoder();

  @override
  Future<CustomerGeocodedAddress?> reverse({
    required double latitude,
    required double longitude,
    String? languageCode,
  }) async {
    final uri = Uri.https(
      'nominatim.openstreetmap.org',
      '/reverse',
      <String, String>{
        'format': 'jsonv2',
        'addressdetails': '1',
        'zoom': '18',
        'lat': latitude.toStringAsFixed(7),
        'lon': longitude.toStringAsFixed(7),
        if (languageCode != null && languageCode.trim().isNotEmpty)
          'accept-language': languageCode.trim(),
      },
    );

    final response = await http.get(
      uri,
      headers: const <String, String>{
        'User-Agent': 'FOODEX-Customer/1.0.75',
        'Accept': 'application/json',
      },
    ).timeout(const Duration(seconds: 8));

    if (response.statusCode != 200) return null;

    final decoded = jsonDecode(response.body);
    if (decoded is! Map) return null;
    final rawAddress = decoded['address'];
    if (rawAddress is! Map) return null;
    final address = Map<String, dynamic>.from(rawAddress);

    String? first(List<String> keys) {
      for (final key in keys) {
        final value = address[key]?.toString().trim();
        if (value != null && value.isNotEmpty) return value;
      }
      return null;
    }

    final road = first(<String>[
      'road',
      'pedestrian',
      'footway',
      'path',
      'residential',
    ]);
    final houseNumber = first(<String>['house_number']);
    final displayName = decoded['display_name']?.toString().trim();
    final fallbackLine = displayName == null || displayName.isEmpty
        ? null
        : displayName.split(',').first.trim();
    final line1 = <String>[
      if (road != null) road,
      if (houseNumber != null) houseNumber,
    ].join(' ').trim();

    final countryCode = first(<String>['country_code'])?.toUpperCase();

    return CustomerGeocodedAddress(
      line1: line1.isNotEmpty ? line1 : fallbackLine,
      area: first(<String>[
        'suburb',
        'neighbourhood',
        'quarter',
        'city_district',
        'borough',
      ]),
      governorate: first(<String>[
        'state',
        'region',
        'state_district',
      ]),
      city: first(<String>[
        'city',
        'town',
        'village',
        'municipality',
        'county',
      ]),
      country: first(<String>['country']),
      countryCode: countryCode,
      building: houseNumber,
    );
  }
}
