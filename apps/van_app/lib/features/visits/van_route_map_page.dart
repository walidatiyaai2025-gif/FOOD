import 'dart:async';
import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../../core/auth/van_session.dart';
import '../../core/theme/foodex_van_theme.dart';
import '../wallet/van_wallet_contract.dart';
import 'van_visit_contract.dart';

class VanRouteMapPage extends StatefulWidget {
  const VanRouteMapPage({
    super.key,
    required this.visitRepository,
    required this.customerRepository,
    required this.onSessionExpired,
  });

  final VanVisitRepository visitRepository;
  final VanWalletRepository customerRepository;
  final Future<void> Function() onSessionExpired;

  @override
  State<VanRouteMapPage> createState() => _VanRouteMapPageState();
}

class _VanRouteMapPageState extends State<VanRouteMapPage>
    with WidgetsBindingObserver {
  bool _loading = true;
  bool _refreshing = false;
  bool _stale = false;
  Object? _error;
  List<VanVisitRecord> _visits = const [];
  Map<String, String> _customerNames = const {};
  String? _selectedRoute;

  bool get _arabic => Localizations.localeOf(context).languageCode == 'ar';
  String _text(String en, String ar) => _arabic ? ar : en;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _load();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed && !_loading && !_refreshing) {
      unawaited(_load(background: true));
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  Future<void> _load({bool background = false}) async {
    if (mounted) {
      setState(() {
        if (background && _visits.isNotEmpty) {
          _refreshing = true;
        } else {
          _loading = true;
        }
        _error = null;
      });
    }

    try {
      final results = await Future.wait<Object>([
        widget.visitRepository.visits(),
        widget.customerRepository.customers(),
      ]);
      final visits = results[0] as List<VanVisitRecord>;
      final customers = results[1] as List<VanCustomerScope>;
      final routes = _routeKeys(visits);
      final selected = routes.contains(_selectedRoute)
          ? _selectedRoute
          : (routes.isEmpty ? null : routes.first);

      if (!mounted) return;
      setState(() {
        _visits = visits;
        _selectedRoute = selected;
        _customerNames = {
          for (final customer in customers)
            '${customer.type}:${customer.id}': customer.name,
        };
        _loading = false;
        _refreshing = false;
        _stale = false;
      });
    } on VanSessionExpiredException {
      await widget.onSessionExpired();
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _refreshing = false;
        if (background && _visits.isNotEmpty) {
          _stale = true;
        } else {
          _error = error;
        }
      });
    }
  }

  List<String> _routeKeys(List<VanVisitRecord> visits) {
    final values = visits
        .map((visit) => visit.routeKey?.trim())
        .whereType<String>()
        .where((value) => value.isNotEmpty)
        .toSet()
        .toList()
      ..sort((a, b) => a.toLowerCase().compareTo(b.toLowerCase()));
    return values;
  }

  List<VanVisitRecord> get _routeVisits {
    final route = _selectedRoute;
    if (route == null) return const [];
    return _visits.where((visit) => visit.routeKey?.trim() == route).toList();
  }

  List<VanVisitRecord> get _mappedVisits => _routeVisits
      .where((visit) => visit.latitude != null && visit.longitude != null)
      .toList();

  String _customerName(VanVisitRecord visit) =>
      _customerNames['${visit.customerType}:${visit.customerId}'] ??
      _text('Assigned customer', 'عميل مسند');

  bool _isDone(VanVisitRecord visit) => {
        'completed_with_order',
        'completed_no_order',
        'customer_unavailable',
        'closed',
      }.contains(visit.status);

  String _statusLabel(String status) {
    switch (status) {
      case 'planned':
        return _text('Planned', 'مخطط');
      case 'started':
        return _text('Started', 'بدأت');
      case 'completed_with_order':
        return _text('Completed with order', 'مكتملة بطلب');
      case 'completed_no_order':
        return _text('Completed without order', 'مكتملة بدون طلب');
      case 'customer_unavailable':
        return _text('Customer unavailable', 'العميل غير متاح');
      case 'closed':
        return _text('Closed', 'مغلقة');
      default:
        return _text('Status unavailable', 'الحالة غير متاحة');
    }
  }

  String _addressLabel(VanVisitRecord visit) {
    final address = visit.address?.trim();
    return address == null || address.isEmpty
        ? _text('Address unavailable', 'العنوان غير متاح')
        : address;
  }

  String _locationLabel(VanVisitRecord visit) {
    if (visit.latitude != null && visit.longitude != null) {
      return _text('Plotted on map', 'ظاهر على الخريطة');
    }

    return _text(
      'Not plotted — coordinates missing',
      'غير ظاهر على الخريطة — الإحداثيات غير متاحة',
    );
  }

  String _stopSemanticsLabel(
    VanVisitRecord visit,
    int index,
    int total,
  ) {
    return _text(
      'Stop ${index + 1} of $total. ${_customerName(visit)}. Status: ${_statusLabel(visit.status)}. Address: ${_addressLabel(visit)}. ${_locationLabel(visit)}.',
      'المحطة ${index + 1} من $total. ${_customerName(visit)}. الحالة: ${_statusLabel(visit.status)}. العنوان: ${_addressLabel(visit)}. ${_locationLabel(visit)}.',
    );
  }

  VanVisitRecord? get _nextVisit {
    for (final visit in _routeVisits) {
      if (!_isDone(visit)) return visit;
    }
    return null;
  }

  @override
  Widget build(BuildContext context) {
    if (_loading && _visits.isEmpty) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_error != null && _visits.isEmpty) {
      return _StateCard(
        title: _text('Unable to load route map', 'تعذر تحميل خريطة المسار'),
        body: _text(
          'Route stops remain authoritative from customer address coordinates.',
          'تظل نقاط المسار معتمدة على إحداثيات عناوين العملاء.',
        ),
        actionLabel: _text('Retry', 'إعادة المحاولة'),
        onAction: _load,
      );
    }

    final routes = _routeKeys(_visits);
    final routeVisits = _routeVisits;
    final mapped = _mappedVisits;
    final next = _nextVisit;
    final completed = routeVisits.where(_isDone).length;

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        key: const ValueKey('van-route-map-page'),
        padding: const EdgeInsets.fromLTRB(12, 10, 12, 24),
        children: [
          if (_refreshing || _stale)
            Container(
              margin: const EdgeInsets.only(bottom: 10),
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: FoodexVanTokens.surface,
                border: Border.all(color: FoodexVanTokens.border),
                borderRadius: BorderRadius.circular(14),
              ),
              child: Text(
                _stale
                    ? _text(
                        'Showing the last confirmed route coordinates.',
                        'يتم عرض آخر إحداثيات مسار مؤكدة.',
                      )
                    : _text(
                        'Refreshing route coordinates…',
                        'جارٍ تحديث إحداثيات المسار…',
                      ),
              ),
            ),
          if (routes.isEmpty)
            _StateCard(
              title: _text('No mapped route assigned', 'لا يوجد مسار مسند'),
              body: _text(
                'The assigned visit feed does not currently include a route.',
                'قائمة الزيارات المسندة لا تتضمن مسارًا حاليًا.',
              ),
              actionLabel: _text('Refresh', 'تحديث'),
              onAction: _load,
            )
          else ...[
            Card(
              elevation: 0,
              child: Padding(
                padding: const EdgeInsets.all(12),
                child: Row(
                  children: [
                    const Icon(Icons.route, color: FoodexVanTokens.green),
                    const SizedBox(width: 8),
                    Expanded(
                      child: DropdownButtonHideUnderline(
                        child: DropdownButton<String>(
                          key: const ValueKey('van-route-map-selector'),
                          value: _selectedRoute,
                          isExpanded: true,
                          items: [
                            for (final route in routes)
                              DropdownMenuItem(
                                value: route,
                                child: Text(
                                  route,
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                ),
                              ),
                          ],
                          onChanged: (value) =>
                              setState(() => _selectedRoute = value),
                        ),
                      ),
                    ),
                    const SizedBox(width: 8),
                    Text(
                      '$completed/${routeVisits.length}',
                      key: const ValueKey('van-route-map-progress'),
                      style: const TextStyle(fontWeight: FontWeight.w900),
                    ),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 10),
            SizedBox(
              height: 330,
              child: DecoratedBox(
                decoration: BoxDecoration(
                  color: const Color(0xFFF0F4F1),
                  border: Border.all(color: FoodexVanTokens.border),
                  borderRadius: BorderRadius.circular(20),
                ),
                child: mapped.isEmpty
                    ? Center(
                        child: Padding(
                          padding: const EdgeInsets.all(24),
                          child: Text(
                            _text(
                              'This route has assigned stops, but no customer address coordinates are available yet.',
                              'المسار يحتوي زيارات مسندة، لكن إحداثيات عناوين العملاء غير متاحة بعد.',
                            ),
                            textAlign: TextAlign.center,
                          ),
                        ),
                      )
                    : Semantics(
                        label: _text(
                          'Route map. Accessible stop details follow in route order.',
                          'خريطة المسار. تتبعها تفاصيل الزيارات بترتيب المسار بشكل ميسر.',
                        ),
                        image: true,
                        child: CustomPaint(
                          key: const ValueKey('van-route-map-canvas'),
                          painter: _RoutePainter(
                            visits: routeVisits,
                            done: _isDone,
                          ),
                          child: const SizedBox.expand(),
                        ),
                      ),
              ),
            ),
            if (mapped.length != routeVisits.length) ...[
              const SizedBox(height: 8),
              Text(
                _text(
                  '${routeVisits.length - mapped.length} stop(s) are omitted from the map because the authoritative address has no coordinates.',
                  'تم إخفاء ${routeVisits.length - mapped.length} زيارة من الخريطة لعدم وجود إحداثيات في العنوان المعتمد.',
                ),
                style: Theme.of(context).textTheme.bodySmall?.copyWith(
                      color: FoodexVanTokens.muted,
                    ),
              ),
            ],
            if (next != null) ...[
              const SizedBox(height: 12),
              Card(
                elevation: 0,
                child: ListTile(
                  leading: const CircleAvatar(
                    backgroundColor: FoodexVanTokens.mint,
                    child: Icon(
                      Icons.storefront_outlined,
                      color: FoodexVanTokens.green,
                    ),
                  ),
                  title: Text(
                    _text(
                      'Next: ${_customerName(next)}',
                      'التالي: ${_customerName(next)}',
                    ),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(fontWeight: FontWeight.w900),
                  ),
                  subtitle: Text(
                    next.address ??
                        _text(
                          'Address coordinates are not available.',
                          'إحداثيات العنوان غير متاحة.',
                        ),
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
              ),
            ],
            const SizedBox(height: 12),
            Card(
              key: const ValueKey('van-route-map-stop-list'),
              elevation: 0,
              child: Padding(
                padding: const EdgeInsets.symmetric(vertical: 6),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Padding(
                      padding: const EdgeInsets.fromLTRB(16, 8, 16, 4),
                      child: Semantics(
                        header: true,
                        child: Text(
                          _text('Route stops', 'زيارات المسار'),
                          style: Theme.of(
                            context,
                          ).textTheme.titleMedium?.copyWith(
                                fontWeight: FontWeight.w900,
                              ),
                        ),
                      ),
                    ),
                    Padding(
                      padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
                      child: Text(
                        _text(
                          'All assigned stops remain available here, including addresses without coordinates.',
                          'تظل جميع الزيارات المسندة متاحة هنا، بما فيها العناوين التي لا تحتوي على إحداثيات.',
                        ),
                        style: Theme.of(context).textTheme.bodySmall?.copyWith(
                              color: FoodexVanTokens.muted,
                            ),
                      ),
                    ),
                    for (var index = 0;
                        index < routeVisits.length;
                        index++) ...[
                      if (index > 0)
                        const Divider(height: 1, indent: 16, endIndent: 16),
                      Semantics(
                        key: ValueKey(
                          'van-route-map-stop-${routeVisits[index].id}',
                        ),
                        container: true,
                        label: _stopSemanticsLabel(
                          routeVisits[index],
                          index,
                          routeVisits.length,
                        ),
                        child: ExcludeSemantics(
                          child: ListTile(
                            contentPadding: const EdgeInsets.symmetric(
                              horizontal: 16,
                            ),
                            leading: CircleAvatar(
                              backgroundColor: _isDone(routeVisits[index])
                                  ? FoodexVanTokens.greenSoft
                                  : FoodexVanTokens.background,
                              foregroundColor: _isDone(routeVisits[index])
                                  ? FoodexVanTokens.greenDark
                                  : FoodexVanTokens.muted,
                              child: Text(
                                '${index + 1}',
                                style: const TextStyle(
                                  fontWeight: FontWeight.w900,
                                ),
                              ),
                            ),
                            title: Text(
                              _customerName(routeVisits[index]),
                              style:
                                  const TextStyle(fontWeight: FontWeight.w900),
                            ),
                            subtitle: Padding(
                              padding: const EdgeInsets.only(top: 4),
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(
                                    _statusLabel(routeVisits[index].status),
                                    style: const TextStyle(
                                      fontWeight: FontWeight.w700,
                                    ),
                                  ),
                                  const SizedBox(height: 2),
                                  Text(_addressLabel(routeVisits[index])),
                                  const SizedBox(height: 2),
                                  Text(
                                    _locationLabel(routeVisits[index]),
                                    style: Theme.of(context)
                                        .textTheme
                                        .bodySmall
                                        ?.copyWith(
                                          color: FoodexVanTokens.muted,
                                        ),
                                  ),
                                ],
                              ),
                            ),
                          ),
                        ),
                      ),
                    ],
                  ],
                ),
              ),
            ),
          ],
        ],
      ),
    );
  }
}

class _RoutePainter extends CustomPainter {
  const _RoutePainter({required this.visits, required this.done});

  final List<VanVisitRecord> visits;
  final bool Function(VanVisitRecord visit) done;

  @override
  void paint(Canvas canvas, Size size) {
    final gridPaint = Paint()
      ..color = const Color(0xFFDDE6E0)
      ..strokeWidth = 1;
    for (double x = 24; x < size.width; x += 42) {
      canvas.drawLine(Offset(x, 0), Offset(x, size.height), gridPaint);
    }
    for (double y = 24; y < size.height; y += 42) {
      canvas.drawLine(Offset(0, y), Offset(size.width, y), gridPaint);
    }

    final mappedStops = [
      for (final entry in visits.asMap().entries)
        if (entry.value.latitude != null && entry.value.longitude != null)
          (stopNumber: entry.key + 1, visit: entry.value),
    ];
    final lats = mappedStops.map((stop) => stop.visit.latitude!).toList();
    final lngs = mappedStops.map((stop) => stop.visit.longitude!).toList();
    var minLat = lats.reduce(math.min);
    var maxLat = lats.reduce(math.max);
    var minLng = lngs.reduce(math.min);
    var maxLng = lngs.reduce(math.max);
    if ((maxLat - minLat).abs() < 0.000001) {
      minLat -= 0.0005;
      maxLat += 0.0005;
    }
    if ((maxLng - minLng).abs() < 0.000001) {
      minLng -= 0.0005;
      maxLng += 0.0005;
    }

    const padding = 34.0;
    Offset point(VanVisitRecord visit) {
      final x = padding +
          ((visit.longitude! - minLng) / (maxLng - minLng)) *
              (size.width - padding * 2);
      final y = padding +
          (1 - (visit.latitude! - minLat) / (maxLat - minLat)) *
              (size.height - padding * 2);
      return Offset(x, y);
    }

    final routePaint = Paint()
      ..color = FoodexVanTokens.green
      ..strokeWidth = 4
      ..style = PaintingStyle.stroke
      ..strokeCap = StrokeCap.round;
    final path = Path();
    for (var i = 0; i < mappedStops.length; i++) {
      final p = point(mappedStops[i].visit);
      if (i == 0) {
        path.moveTo(p.dx, p.dy);
      } else {
        path.lineTo(p.dx, p.dy);
      }
    }
    canvas.drawPath(path, routePaint);

    for (final stop in mappedStops) {
      final visit = stop.visit;
      final p = point(visit);
      final fill = Paint()
        ..color = done(visit) ? FoodexVanTokens.green : const Color(0xFF2F6FED);
      canvas.drawCircle(p, 13, Paint()..color = Colors.white);
      canvas.drawCircle(p, 10, fill);
      final text = TextPainter(
        text: TextSpan(
          text: '${stop.stopNumber}',
          style: const TextStyle(
            color: Colors.white,
            fontSize: 10,
            fontWeight: FontWeight.w900,
          ),
        ),
        textDirection: TextDirection.ltr,
      )..layout();
      text.paint(canvas, p - Offset(text.width / 2, text.height / 2));
    }
  }

  @override
  bool shouldRepaint(covariant _RoutePainter oldDelegate) =>
      oldDelegate.visits != visits;
}

class _StateCard extends StatelessWidget {
  const _StateCard({
    required this.title,
    required this.body,
    required this.actionLabel,
    required this.onAction,
  });

  final String title;
  final String body;
  final String actionLabel;
  final Future<void> Function() onAction;

  @override
  Widget build(BuildContext context) {
    return Card(
      elevation: 0,
      child: Padding(
        padding: const EdgeInsets.all(18),
        child: Column(
          children: [
            Text(
              title,
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.titleMedium?.copyWith(
                    fontWeight: FontWeight.w800,
                  ),
            ),
            const SizedBox(height: 6),
            Text(body, textAlign: TextAlign.center),
            const SizedBox(height: 12),
            OutlinedButton(onPressed: onAction, child: Text(actionLabel)),
          ],
        ),
      ),
    );
  }
}
