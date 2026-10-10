import 'dart:async';

import 'package:flutter/material.dart';
import 'package:foodex_visualization/foodex_visualization.dart';

import '../../core/auth/van_session.dart';
import '../../core/theme/foodex_van_theme.dart';
import 'van_visit_contract.dart';

class VanRoutesPage extends StatefulWidget {
  const VanRoutesPage({
    super.key,
    required this.repository,
    required this.onSessionExpired,
    required this.onOpenOrder,
  });

  final VanVisitRepository repository;
  final Future<void> Function() onSessionExpired;
  final ValueChanged<int> onOpenOrder;

  @override
  State<VanRoutesPage> createState() => _VanRoutesPageState();
}

class _VanRoutesPageState extends State<VanRoutesPage>
    with WidgetsBindingObserver {
  bool _loading = true;
  bool _refreshing = false;
  bool _stale = false;
  Object? _error;
  List<VanVisitRecord> _visits = const [];

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
      if (!mounted) return;
      setState(() {
        _visits = visits;
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

  Map<String, List<VanVisitRecord>> get _routes {
    final grouped = <String, List<VanVisitRecord>>{};
    for (final visit in _visits) {
      final route = visit.routeKey?.trim();
      final key = route == null || route.isEmpty
          ? _text('Unassigned route', 'بدون مسار مسند')
          : route;
      grouped.putIfAbsent(key, () => <VanVisitRecord>[]).add(visit);
    }

    final entries = grouped.entries.toList()
      ..sort((a, b) {
        final aUnassigned =
            a.key == _text('Unassigned route', 'بدون مسار مسند');
        final bUnassigned =
            b.key == _text('Unassigned route', 'بدون مسار مسند');
        if (aUnassigned != bUnassigned) return aUnassigned ? 1 : -1;
        return a.key.toLowerCase().compareTo(b.key.toLowerCase());
      });

    return Map<String, List<VanVisitRecord>>.fromEntries(entries);
  }

  int get _plannedCount =>
      _visits.where((visit) => visit.status == 'planned').length;

  int get _activeCount =>
      _visits.where((visit) => visit.status == 'started').length;

  int get _completedCount => _visits
      .where(
        (visit) => {
          'completed_with_order',
          'completed_no_order',
          'customer_unavailable',
          'closed',
        }.contains(visit.status),
      )
      .length;

  Widget _routeAnalytics(BuildContext context) => Card(
        key: const ValueKey('van-routes-analytics'),
        elevation: 0,
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(
                _text('Route productivity', 'إنتاجية المسارات'),
                style: Theme.of(context).textTheme.titleMedium?.copyWith(
                      fontWeight: FontWeight.w900,
                    ),
              ),
              const SizedBox(height: 4),
              Text(
                _text(
                  'How much of the assigned visit workload is complete?',
                  'ما نسبة الزيارات المسندة التي تم إنجازها؟',
                ),
                style: Theme.of(context).textTheme.bodySmall,
              ),
              const SizedBox(height: 12),
              FoodexDonutChart(
                key: const ValueKey('van-routes-status-chart'),
                values: [
                  _plannedCount.toDouble(),
                  _activeCount.toDouble(),
                  _completedCount.toDouble(),
                ],
                semanticLabel: _text(
                  'Planned, active and completed visit distribution',
                  'توزيع الزيارات المخططة والنشطة والمكتملة',
                ),
                size: 134,
                strokeWidth: 18,
              ),
              const SizedBox(height: 10),
              FoodexChartLegend(
                labels: [
                  _text('Planned', 'مخطط'),
                  _text('Active', 'نشط'),
                  _text('Completed', 'مكتمل'),
                ],
              ),
            ],
          ),
        ),
      );

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

  String _visitStatusLabel(VanVisitRecord visit) =>
      _statusLabel(visit.status);

  @override
  Widget build(BuildContext context) {
    if (_loading && _visits.isEmpty) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_error != null && _visits.isEmpty) {
      return _StateCard(
        title: _text('Unable to load routes', 'تعذر تحميل المسارات'),
        body: _text(
          'Routes are derived from the canonical assigned visit feed.',
          'يتم اشتقاق المسارات من قائمة الزيارات المسندة المعتمدة.',
        ),
        actionLabel: _text('Retry', 'إعادة المحاولة'),
        onAction: _load,
      );
    }

    final routes = _routes;

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        key: const ValueKey('van-routes-page'),
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
                        'Showing the last confirmed route assignment.',
                        'يتم عرض آخر إسناد مسارات مؤكد.',
                      )
                    : _text(
                        'Refreshing route assignments…',
                        'جارٍ تحديث إسنادات المسارات…',
                      ),
              ),
            ),
          if (routes.isEmpty)
            _StateCard(
              title: _text('No assigned routes', 'لا توجد مسارات مسندة'),
              body: _text(
                'Routes appear only when assigned visits include backend route context.',
                'تظهر المسارات فقط عندما تتضمن الزيارات المسندة سياق مسار من الخادم.',
              ),
              actionLabel: _text('Refresh', 'تحديث'),
              onAction: _load,
            )
          else ...[
            Text(
              _text('Assigned routes', 'المسارات المسندة'),
              style: Theme.of(context).textTheme.titleLarge?.copyWith(
                    fontWeight: FontWeight.w900,
                  ),
            ),
            const SizedBox(height: 10),
            _routeAnalytics(context),
            const SizedBox(height: 10),
            for (final entry in routes.entries)
              Card(
                key: ValueKey('van-route-${entry.key}'),
                elevation: 0,
                margin: const EdgeInsets.only(bottom: 10),
                child: ExpansionTile(
                  leading: const CircleAvatar(
                    backgroundColor: FoodexVanTokens.mint,
                    child: Icon(
                      Icons.route_outlined,
                      color: FoodexVanTokens.green,
                    ),
                  ),
                  title: Text(
                    entry.key,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(fontWeight: FontWeight.w900),
                  ),
                  subtitle: Text(
                    _text(
                      '${entry.value.length} assigned visits',
                      '${entry.value.length} زيارات مسندة',
                    ),
                  ),
                  children: [
                    for (final visit in entry.value)
                      ListTile(
                        dense: true,
                        onTap: visit.orderId == null
                            ? null
                            : () => widget.onOpenOrder(visit.orderId!),
                        leading: const Icon(Icons.storefront_outlined, size: 18),
                        title: Text(
                          _text('Assigned customer', 'عميل مسند'),
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                        ),
                        subtitle: visit.plannedAt == null
                            ? null
                            : Text(
                                _text(
                                  'Planned: ${visit.plannedAt}',
                                  'الموعد: ${visit.plannedAt}',
                                ),
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                              ),
                        trailing: Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            Chip(label: Text(_visitStatusLabel(visit))),
                            if (visit.orderId != null)
                              const Icon(Icons.chevron_right),
                          ],
                        ),
                      ),
                  ],
                ),
              ),
          ],
        ],
      ),
    );
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
