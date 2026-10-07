import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/auth/van_session.dart';
import '../../core/theme/foodex_van_theme.dart';
import '../wallet/van_wallet_contract.dart';
import 'van_visit_contract.dart';

class VanVisitWorkspacePage extends StatefulWidget {
  const VanVisitWorkspacePage({
    super.key,
    required this.visitRepository,
    required this.customerRepository,
    required this.onSessionExpired,
  });

  final VanVisitRepository visitRepository;
  final VanWalletRepository customerRepository;
  final Future<void> Function() onSessionExpired;

  @override
  State<VanVisitWorkspacePage> createState() => _VanVisitWorkspacePageState();
}

class _VanVisitWorkspacePageState extends State<VanVisitWorkspacePage>
    with WidgetsBindingObserver {
  bool _loading = true;
  bool _refreshing = false;
  bool _submitting = false;
  bool _stale = false;
  Object? _error;
  List<VanVisitRecord> _visits = const [];
  Map<String, String> _customerNames = const {};

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
    if (state == AppLifecycleState.resumed &&
        !_loading &&
        !_refreshing &&
        !_submitting) {
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
      final names = <String, String>{
        for (final customer in customers)
          '${customer.type}:${customer.id}': customer.name,
      };

      if (!mounted) return;
      setState(() {
        _visits = visits;
        _customerNames = names;
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

  Future<void> _transition(VanVisitRecord visit, String status) async {
    if (_submitting) return;
    setState(() => _submitting = true);

    try {
      final updated = await widget.visitRepository.transition(
        visitId: visit.id,
        status: status,
      );
      if (!mounted) return;
      setState(() {
        _visits = [
          for (final item in _visits)
            if (item.id == updated.id) updated else item,
        ];
      });
    } on VanSessionExpiredException {
      await widget.onSessionExpired();
    } catch (error) {
      if (!mounted) return;
      final message = error is VanOfflineException
          ? _text(
              'Network unavailable. The visit was not changed locally.',
              'لا يوجد اتصال بالشبكة. لم يتم تغيير الزيارة محليًا.',
            )
          : error is VanAccessDeniedException
              ? _text('Access denied.', 'غير مصرح بهذه العملية.')
              : _text(
                  'Unable to update the visit.',
                  'تعذر تحديث الزيارة.',
                );
      ScaffoldMessenger.of(context)
        ..hideCurrentSnackBar()
        ..showSnackBar(SnackBar(content: Text(message)));
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
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

  String _customerLabel(VanVisitRecord visit) {
    return _customerNames['${visit.customerType}:${visit.customerId}'] ??
        _text('Assigned customer', 'عميل مسند');
  }

  @override
  Widget build(BuildContext context) {
    if (_loading && _visits.isEmpty) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_error != null && _visits.isEmpty) {
      return _StateCard(
        title: _text('Unable to load visits', 'تعذر تحميل الزيارات'),
        body: _text(
          'Visit lifecycle remains server-authoritative.',
          'تظل دورة حياة الزيارة معتمدة من الخادم.',
        ),
        actionLabel: _text('Retry', 'إعادة المحاولة'),
        onAction: _load,
      );
    }

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        key: const ValueKey('van-visit-workspace-page'),
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
                        'Showing the last confirmed visit state.',
                        'يتم عرض آخر حالة زيارة مؤكدة.',
                      )
                    : _text(
                        'Refreshing visit state…',
                        'جارٍ تحديث حالة الزيارات…',
                      ),
              ),
            ),
          if (_visits.isEmpty)
            _StateCard(
              title: _text('No assigned visits', 'لا توجد زيارات مسندة'),
              body: _text(
                'Visits appear only when they are assigned by the backend.',
                'تظهر الزيارات فقط عند إسنادها من الخادم.',
              ),
              actionLabel: _text('Refresh', 'تحديث'),
              onAction: _load,
            )
          else
            for (final visit in _visits)
              Card(
                key: ValueKey('van-visit-${visit.id}'),
                elevation: 0,
                margin: const EdgeInsets.only(bottom: 10),
                child: Padding(
                  padding: const EdgeInsets.all(14),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          const Icon(
                            Icons.fact_check_outlined,
                            color: FoodexVanTokens.green,
                          ),
                          const SizedBox(width: 8),
                          Expanded(
                            child: Text(
                              _customerLabel(visit),
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: Theme.of(context)
                                  .textTheme
                                  .titleMedium
                                  ?.copyWith(fontWeight: FontWeight.w900),
                            ),
                          ),
                          const SizedBox(width: 8),
                          Chip(label: Text(_statusLabel(visit.status))),
                        ],
                      ),
                      if (visit.plannedAt != null) ...[
                        const SizedBox(height: 8),
                        Text(
                          _text(
                            'Planned: ${visit.plannedAt}',
                            'الموعد: ${visit.plannedAt}',
                          ),
                        ),
                      ],
                      if (visit.allowedTransitions.isNotEmpty) ...[
                        const SizedBox(height: 12),
                        Wrap(
                          spacing: 8,
                          runSpacing: 8,
                          children: [
                            if (visit.allowedTransitions.contains('started'))
                              FilledButton.icon(
                                key: ValueKey('van-visit-start-${visit.id}'),
                                onPressed: _submitting
                                    ? null
                                    : () => _transition(visit, 'started'),
                                icon: const Icon(Icons.play_arrow),
                                label: Text(_text('Start visit', 'بدء الزيارة')),
                              ),
                            if (visit.allowedTransitions
                                .contains('customer_unavailable'))
                              OutlinedButton.icon(
                                key: ValueKey(
                                  'van-visit-unavailable-${visit.id}',
                                ),
                                onPressed: _submitting
                                    ? null
                                    : () => _transition(
                                          visit,
                                          'customer_unavailable',
                                        ),
                                icon: const Icon(Icons.person_off_outlined),
                                label: Text(
                                  _text(
                                    'Customer unavailable',
                                    'العميل غير متاح',
                                  ),
                                ),
                              ),
                            if (visit.allowedTransitions.contains('closed'))
                              FilledButton.icon(
                                key: ValueKey('van-visit-close-${visit.id}'),
                                onPressed: _submitting
                                    ? null
                                    : () => _transition(visit, 'closed'),
                                icon: const Icon(Icons.check_circle_outline),
                                label: Text(_text('Close visit', 'إغلاق الزيارة')),
                              ),
                          ],
                        ),
                      ],
                      if (visit.status == 'started' &&
                          (visit.allowedTransitions
                                  .contains('completed_with_order') ||
                              visit.allowedTransitions
                                  .contains('completed_no_order'))) ...[
                        const SizedBox(height: 10),
                        Text(
                          _text(
                            'Order/no-order completion remains tied to authoritative order and reason selection; this workspace does not fabricate either.',
                            'إكمال الزيارة بطلب أو بدون طلب مرتبط باختيار طلب أو سبب معتمد؛ هذه الشاشة لا تنشئ أيًا منهما بشكل وهمي.',
                          ),
                          style: Theme.of(context).textTheme.bodySmall?.copyWith(
                                color: FoodexVanTokens.muted,
                              ),
                        ),
                      ],
                    ],
                  ),
                ),
              ),
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
