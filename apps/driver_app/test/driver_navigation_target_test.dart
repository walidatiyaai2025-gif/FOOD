import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_driver_app/core/navigation/driver_navigation_target.dart';

void main() {
  test('navigation target uses immutable order navigation snapshot only', () {
    final resolution = resolveDriverNavigationTarget(<String, dynamic>{
      'order': <String, dynamic>{
        'navigation': <String, dynamic>{
          'available': true,
          'latitude': 29.3759,
          'longitude': 47.9774,
        },
        'address': <String, dynamic>{
          'latitude': 1.0,
          'longitude': 2.0,
        },
      },
    });

    expect(resolution.available, isTrue);
    expect(resolution.target!.latitude, 29.3759);
    expect(resolution.target!.longitude, 47.9774);
  });

  test('mutable address coordinates never become a navigation fallback', () {
    final resolution = resolveDriverNavigationTarget(<String, dynamic>{
      'order': <String, dynamic>{
        'navigation': <String, dynamic>{
          'available': false,
          'latitude': null,
          'longitude': null,
        },
        'address': <String, dynamic>{
          'latitude': 29.3759,
          'longitude': 47.9774,
        },
      },
    });

    expect(resolution.available, isFalse);
    expect(
      resolution.failure,
      DriverNavigationFailure.missingCoordinates,
    );
  });

  test('invalid authoritative coordinates fail closed', () {
    final resolution = resolveDriverNavigationTarget(<String, dynamic>{
      'order': <String, dynamic>{
        'navigation': <String, dynamic>{
          'available': true,
          'latitude': 91,
          'longitude': 47.9774,
        },
      },
    });

    expect(resolution.available, isFalse);
    expect(
      resolution.failure,
      DriverNavigationFailure.invalidCoordinates,
    );
  });
}
