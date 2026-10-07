import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/auth/van_session.dart';
import '../../core/theme/foodex_van_theme.dart';
import 'van_visit_contract.dart';

class VanRouteDetailPage extends StatefulWidget {
  const VanRouteDetailPage({
    super.key,
    required this.repository,
    required this.onSessionExpired,
  });

  final VanVisitRepository repository;
  final Future<void> Function() onSessionExpired;

  @override
  State<VanRouteDetailPage> createState() => _VanRouteDetailPageState();
}

class _VanRouteDetailPageState extends State<VanRouteDetailPage>
    with WidgetsBindingObserver {
  bool _loading = true;
  bool _refreshing = false;
  bool _stale = false;
  Object? _error;
  List<VanVisitRecord> _visits = const [];
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
      final visits = await widget.repository.visits();
      final routes = _routeKeys(visits);
      final selected = routes.contains(_selectedRoute)
          ? _selectedRoute
          : (routes.isEmpty ? null : routes.first);

      if (!mounted) return;
      setState(() {
        _visits = visits;
        _selectedRoute = selected;
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
    final keys = visits
        .map((visit) => visit.routeKey?.trim())
        .whereType<String>()
        .where((value) => value.isNotEmpty)
        .toSet()
        .toList()
      ..sort((a, b) => a.toLowerCase().compareTo(b.toLowerCase()));
    return keys;
  }

  List<VanVisitRecord> get _selectedVisits {
    final route = _selectedRoute;
    if (route == null) return const [];
    return _visits.where((visit) => visit.routeKey?.trim() == route).toList();
  }

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
        return status;
    }
  }

  int _countStatus(Iterable<VanVisitRecord> visits, String status) =>
      visits.where((visit) => visit.status == status).length;

  @override
  Widget build(BuildContext context) {
    if (_loading && _visits.isEmpty) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_error != null && _visits.isEmpty) {
      return _StateCard(
        title: _text('Unable to load route detail', 'تعذر تحميل تفاصيل المسار'),
        body: _text(
          'Route detail is derived from the canonical assigned visit feed.',
          'يتم اشتقاق تفاصيل المسار من قائمة الزيارات المسندة المعتمدة.',
        ),
        actionLabel: _text('Retry', 'إعادة المحاولة'),
        onAction: _load,
      );
    }

    final routes = _routeKeys(_visits);
    final visits = _selectedVisits;

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        key: const ValueKey('van-route-detail-page'),
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
                        'Showing the last confirmed route detail.',
                        'يتم عرض آخر تفاصيل مسار مؤكدة.',
                      )
                    : _text(
                        'Refreshing route detail…',
                        'جارٍ تحديث تفاصيل المسار…',
                      ),
              ),
            ),
          if (routes.isEmpty)
            _StateCard(
              title: _text(
                'No route detail available',
                'لا توجد تفاصيل مسار متاحة',
              ),
              body: _text(
                'Assigned visits do not currently contain backend route context.',
                'الزيارات المسندة لا تحتوي حاليًا على سياق مسار من الخادم.',
              ),
              actionLabel: _text('Refresh', 'تحديث'),
              onAction: _load,
            )
          else ...[
            DropdownButtonFormField<String>(
              key: const ValueKey('van-route-detail-selector'),
              value: _selectedRoute,
              isExpanded: true,
              decoration: InputDecoration(
                labelText: _text('Route', 'المسار'),
                border: const OutlineInputBorder(),
              ),
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
              onChanged: (value) => setState(() => _selectedRoute = value),
            ),
            const SizedBox(height: 12),
            Card(
              elevation: 0,
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      _selectedRoute ?? '',
                      key: const ValueKey('van-route-detail-key'),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: Theme.of(context).textTheme.titleLarge?.copyWith(
                            fontWeight: FontWeight.w900,
                          ),
                    ),
                    const SizedBox(height: 10),
                    Wrap(
                      spacing: 8,
                      runSpacing: 8,
                      children: [
                        _MetricChip(
                          label: _text('Visits', 'الزيارات'),
                          value: visits.length,
                        ),
                        _MetricChip(
                          label: _text('Planned', 'مخطط'),
                          value: _countStatus(visits, 'planned'),
                        ),
                        _MetricChip(
                          label: _text('Started', 'بدأت'),
                          value: _countStatus(visits, 'started'),
                        ),
                        _MetricChip(
                          label: _text('Closed', 'مغلقة'),
                          value: _countStatus(visits, 'closed'),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 10),
            Text(
              _text('Route visits', 'زيارات المسار'),
              style: Theme.of(context).textTheme.titleMedium?.copyWith(
                    fontWeight: FontWeight.w900,
                  ),
            ),
            const SizedBox(height: 8),
            for (final visit in visits)
              Card(
                key: ValueKey('van-route-detail-visit-${visit.id}'),
                elevation: 0,
                margin: const EdgeInsets.only(bottom: 8),
                child: ListTile(
                  leading: const CircleAvatar(
                    backgroundColor: FoodexVanTokens.mint,
                    child: Icon(
                      Icons.storefront_outlined,
                      color: FoodexVanTokens.green,
                    ),
                  ),
                  title: Text(
                    _text('Assigned customer', 'عميل مسند'),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                  subtitle: Text(
                    visit.plannedAt == null
                        ? _statusLabel(visit.status)
                        : '${_statusLabel(visit.status)} · ${visit.plannedAt}',
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                  ),
                  trailing: const Icon(Icons.chevron_right),
                ),
              ),
          ],
        ],
      ),
    );
  }
}

class _MetricChip extends StatelessWidget {
  const _MetricChip({required this.label, required this.value});

  final String label;
  final int value;

  @override
  Widget build(BuildContext context) {
    return Chip(label: Text('$label · $value'));
  }
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
            OutlinedButton(
              onPressed: onAction,
              child: Text(actionLabel),
            ),
          ],
        ),
      ),
    );
  }
}
