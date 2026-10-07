import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';

import '../localization/app_translations.dart';

const double customerMapDefaultLatitude = 26.8206;
const double customerMapDefaultLongitude = 30.8025;
const double customerMapDefaultZoom = 6;
const double customerMapSavedPointZoom = 14;

({LatLng center, double zoom}) customerMapInitialViewport({
  double? initialLatitude,
  double? initialLongitude,
}) {
  final hasSavedPoint = initialLatitude != null && initialLongitude != null;
  return (
    center: LatLng(
      hasSavedPoint ? initialLatitude! : customerMapDefaultLatitude,
      hasSavedPoint ? initialLongitude! : customerMapDefaultLongitude,
    ),
    zoom: hasSavedPoint ? customerMapSavedPointZoom : customerMapDefaultZoom,
  );
}

class CustomerMapPinSelection {
  const CustomerMapPinSelection({
    required this.latitude,
    required this.longitude,
  });

  final double latitude;
  final double longitude;
}

typedef CustomerMapPinPicker = Future<CustomerMapPinSelection?> Function(
  BuildContext context, {
  double? initialLatitude,
  double? initialLongitude,
});

Future<CustomerMapPinSelection?> showCustomerMapPinSelector(
  BuildContext context, {
  double? initialLatitude,
  double? initialLongitude,
}) {
  final viewport = customerMapInitialViewport(
    initialLatitude: initialLatitude,
    initialLongitude: initialLongitude,
  );

  return showModalBottomSheet<CustomerMapPinSelection>(
    context: context,
    isScrollControlled: true,
    useSafeArea: true,
    builder: (_) => _CustomerMapPinSelector(
      initial: viewport.center,
      initialZoom: viewport.zoom,
    ),
  );
}

class _CustomerMapPinSelector extends StatefulWidget {
  const _CustomerMapPinSelector({
    required this.initial,
    required this.initialZoom,
  });

  final LatLng initial;
  final double initialZoom;

  @override
  State<_CustomerMapPinSelector> createState() =>
      _CustomerMapPinSelectorState();
}

class _CustomerMapPinSelectorState extends State<_CustomerMapPinSelector> {
  late LatLng _selected;

  @override
  void initState() {
    super.initState();
    _selected = widget.initial;
  }

  @override
  Widget build(BuildContext context) {
    return FractionallySizedBox(
      heightFactor: .88,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(18, 14, 18, 10),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(
                  context.tr('customer.addresses.choose_map'),
                  style: Theme.of(context).textTheme.titleLarge?.copyWith(
                        fontWeight: FontWeight.w800,
                      ),
                ),
                const SizedBox(height: 4),
                Text(
                  context.tr('customer.addresses.map_instruction'),
                ),
              ],
            ),
          ),
          Expanded(
            child: ClipRRect(
              borderRadius: BorderRadius.circular(20),
              child: Stack(
                children: [
                  FlutterMap(
                    options: MapOptions(
                      initialCenter: widget.initial,
                      initialZoom: widget.initialZoom,
                      minZoom: 3,
                      maxZoom: 19,
                      onTap: (_, point) {
                        setState(() => _selected = point);
                      },
                    ),
                    children: [
                      TileLayer(
                        urlTemplate:
                            'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
                        userAgentPackageName:
                            'com.fiftysolution.foodex.customer',
                      ),
                      MarkerLayer(
                        markers: [
                          Marker(
                            point: _selected,
                            width: 56,
                            height: 56,
                            child: const Icon(
                              Icons.location_pin,
                              size: 48,
                              color: Color(0xFF087347),
                            ),
                          ),
                        ],
                      ),
                    ],
                  ),
                  PositionedDirectional(
                    start: 10,
                    bottom: 10,
                    child: DecoratedBox(
                      decoration: BoxDecoration(
                        color: Colors.white.withAlpha(235),
                        borderRadius: BorderRadius.circular(8),
                      ),
                      child: const Padding(
                        padding:
                            EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                        child: Text(
                          '© OpenStreetMap contributors',
                          style: TextStyle(fontSize: 10),
                        ),
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(18, 12, 18, 18),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(
                  '${_selected.latitude.toStringAsFixed(7)}, '
                  '${_selected.longitude.toStringAsFixed(7)}',
                  key: const ValueKey('customer-map-pin-coordinates'),
                  textAlign: TextAlign.center,
                ),
                const SizedBox(height: 10),
                FilledButton.icon(
                  key: const ValueKey('customer-map-pin-confirm'),
                  onPressed: () => Navigator.of(context).pop(
                    CustomerMapPinSelection(
                      latitude: _selected.latitude,
                      longitude: _selected.longitude,
                    ),
                  ),
                  icon: const Icon(Icons.check_circle_outline_rounded),
                  label: Text(
                    context.tr('customer.addresses.confirm_map'),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
