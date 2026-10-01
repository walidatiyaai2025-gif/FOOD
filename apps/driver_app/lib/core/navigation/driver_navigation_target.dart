enum DriverNavigationFailure {
  missingCoordinates,
  invalidCoordinates,
}

class DriverNavigationTarget {
  const DriverNavigationTarget({
    required this.latitude,
    required this.longitude,
  });

  final double latitude;
  final double longitude;
}

class DriverNavigationResolution {
  const DriverNavigationResolution._({
    this.target,
    this.failure,
  });

  const DriverNavigationResolution.ready(DriverNavigationTarget target)
      : this._(target: target);

  const DriverNavigationResolution.unavailable(DriverNavigationFailure failure)
      : this._(failure: failure);

  final DriverNavigationTarget? target;
  final DriverNavigationFailure? failure;

  bool get available => target != null && failure == null;
}

/// Resolves navigation strictly from the immutable order snapshot payload.
///
/// The mutable customer address payload is intentionally ignored. The backend
/// owns `order.navigation` and derives it from the order delivery snapshot.
DriverNavigationResolution resolveDriverNavigationTarget(
  Map<String, dynamic> assignmentPayload,
) {
  final order = assignmentPayload['order'];
  if (order is! Map) {
    return const DriverNavigationResolution.unavailable(
      DriverNavigationFailure.missingCoordinates,
    );
  }

  final navigation = order['navigation'];
  if (navigation is! Map || navigation['available'] != true) {
    return const DriverNavigationResolution.unavailable(
      DriverNavigationFailure.missingCoordinates,
    );
  }

  final latitude = _coordinate(navigation['latitude']);
  final longitude = _coordinate(navigation['longitude']);
  if (latitude == null ||
      longitude == null ||
      latitude < -90 ||
      latitude > 90 ||
      longitude < -180 ||
      longitude > 180) {
    return const DriverNavigationResolution.unavailable(
      DriverNavigationFailure.invalidCoordinates,
    );
  }

  return DriverNavigationResolution.ready(
    DriverNavigationTarget(
      latitude: latitude,
      longitude: longitude,
    ),
  );
}

double? _coordinate(Object? value) {
  final parsed = switch (value) {
    num number => number.toDouble(),
    String raw => double.tryParse(raw.trim()),
    _ => null,
  };

  return parsed != null && parsed.isFinite ? parsed : null;
}
