import 'dart:io';

import 'package:url_launcher/url_launcher.dart';

typedef DriverNavigationLauncher = Future<bool> Function(
  double latitude,
  double longitude,
);

List<Uri> driverNavigationCandidates(
  double latitude,
  double longitude, {
  bool? ios,
}) {
  final lat = latitude.toStringAsFixed(7);
  final lng = longitude.toStringAsFixed(7);
  final isIos = ios ?? Platform.isIOS;

  if (isIos) {
    return [
      Uri.parse('https://maps.apple.com/?daddr=$lat,$lng&dirflg=d'),
      Uri.parse(
        'https://www.google.com/maps/dir/?api=1&destination=$lat,$lng',
      ),
    ];
  }

  return [
    Uri.parse('google.navigation:q=$lat,$lng'),
    Uri.parse('geo:$lat,$lng?q=$lat,$lng'),
    Uri.parse(
      'https://www.google.com/maps/dir/?api=1&destination=$lat,$lng',
    ),
  ];
}

Future<bool> launchDriverNavigation(
  double latitude,
  double longitude,
) async {
  for (final uri in driverNavigationCandidates(latitude, longitude)) {
    if (!await canLaunchUrl(uri)) continue;

    if (await launchUrl(
      uri,
      mode: LaunchMode.externalApplication,
    )) {
      return true;
    }
  }

  return false;
}
