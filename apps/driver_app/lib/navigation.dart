import 'dart:async';

import 'package:flutter/material.dart';
import 'package:foodex_visualization/foodex_visualization.dart';

import 'core/auth/driver_session.dart';
import 'core/diagnostics/driver_runtime_inspector.dart';
import 'core/localization/driver_translations.dart';
import 'core/navigation/driver_shell.dart';
import 'core/preview/driver_preview_context.dart';
import 'core/theme/foodex_theme.dart';
import 'features/notifications/driver_notification_page.dart';
import 'features/delivery/driver_assignment_contract.dart';
import 'features/delivery/driver_journey_runtime.dart';
import 'features/notifications/notification_feed.dart';
import 'features/wallet/driver_wallet_contract.dart';
import 'features/wallet/driver_wallet_page.dart';

export 'core/auth/driver_session.dart' show DriverChannel;

abstract final class DriverRoutes {
  static const root = '/';
  static const b2cHome = '/driver/b2c/home';
  static const b2cDeliveries = '/driver/b2c/deliveries';
  static const b2cNotifications = '/driver/b2c/notifications';
  static const b2cWallet = '/driver/b2c/wallet';

  static bool belongsTo(String route, DriverChannel channel) {
    return channel == DriverChannel.b2c && route.startsWith('/driver/b2c/');
  }
}

class DriverNavigator {
  const DriverNavigator(
    this.channel, {
    required this.repository,
    this.driverName = '',
    this.notificationRepository,
    this.onSessionExpired,
    this.onLogout,
    this.previewContext,
  });

  final DriverChannel channel;
  final DriverAssignmentRepository repository;
  final String driverName;
  final DriverNotificationRepository? notificationRepository;
  final VoidCallback? onSessionExpired;
  final VoidCallback? onLogout;
  final DriverPreviewContext? previewContext;

  Route<dynamic> onGenerateRoute(RouteSettings settings) {
    final name = settings.name ?? DriverRoutes.root;
    DriverRuntimeInspector.instance.recordNavigation(name);

    if (channel != DriverChannel.b2c) {
      return _page(const _DriverRouteDenied(), settings);
    }

    if (name == DriverRoutes.root) return _page(_homeFor(channel), settings);

    if (!DriverRoutes.belongsTo(name, channel)) {
      return _page(const _DriverRouteDenied(), settings);
    }

    switch (name) {
      case DriverRoutes.b2cHome:
        return _page(_homeFor(channel), settings);
      case DriverRoutes.b2cDeliveries:
        final focusAssignmentId = settings.arguments is int
            ? settings.arguments as int
            : null;
        final initialAssignmentStatus = settings.arguments is String
            ? settings.arguments as String
            : null;
        return _page(
          DriverJourneyRuntimePage(
            channel: channel,
            repository: repository,
            onSessionExpired: onSessionExpired,
            focusAssignmentId: focusAssignmentId,
            initialAssignmentStatus: initialAssignmentStatus,
            previewContext: previewContext,
            homeRoute: DriverRoutes.b2cHome,
            deliveriesRoute: DriverRoutes.b2cDeliveries,
            notificationsRoute: DriverRoutes.b2cNotifications,
          ),
          settings,
        );
      case DriverRoutes.b2cWallet:
        if (repository is! DriverWalletRepository) {
          return _page(const _DriverRouteNotFound(), settings);
        }
        final walletRepository = repository as DriverWalletRepository;
        return _page(DriverWalletPage(repository: walletRepository), settings);
      case DriverRoutes.b2cNotifications:
        final notifications = notificationRepository;
        if (notifications == null) {
          return _page(const _DriverRouteNotFound(), settings);
        }
        const deliveriesRoute = DriverRoutes.b2cDeliveries;
        return _page(
          Builder(
            builder: (context) => DriverNotificationPage(
              repository: notifications,
              onSessionExpired: onSessionExpired,
              homeRoute: DriverRoutes.b2cHome,
              deliveriesRoute: deliveriesRoute,
              notificationsRoute: DriverRoutes.b2cNotifications,
              onOpenAssignment: (assignmentId) {
                Navigator.of(
                  context,
                ).pushNamed(deliveriesRoute, arguments: assignmentId);
              },
            ),
          ),
          settings,
        );
      default:
        return _page(const _DriverRouteNotFound(), settings);
    }
  }

  Widget _homeFor(DriverChannel channel) {
    if (channel != DriverChannel.b2c) {
      return const _DriverRouteDenied();
    }
    const home = DriverRoutes.b2cHome;
    const deliveries = DriverRoutes.b2cDeliveries;
    const notifications = DriverRoutes.b2cNotifications;
    final wallet = repository is DriverWalletRepository
        ? DriverRoutes.b2cWallet
        : null;

    return _DriverHomePage(
      homeRoute: home,
      deliveriesRoute: deliveries,
      driverName: driverName,
      notificationsRoute: notifications,
      walletRoute: wallet,
      channel: channel,
      repository: repository,
      onSessionExpired: onSessionExpired,
      onLogout: onLogout,
      previewContext: previewContext,
    );
  }

  MaterialPageRoute<void> _page(Widget child, RouteSettings settings) {
    return MaterialPageRoute<void>(settings: settings, builder: (_) => child);
  }
}

class _DriverHomePage extends StatefulWidget {
  const _DriverHomePage({
    required this.homeRoute,
    required this.deliveriesRoute,
    required this.driverName,
    required this.notificationsRoute,
    this.walletRoute,
    required this.channel,
    required this.repository,
    this.onSessionExpired,
    this.onLogout,
    this.previewContext,
  });

  final String homeRoute;
  final String deliveriesRoute;
  final String driverName;
  final String notificationsRoute;
  final String? walletRoute;
  final DriverChannel channel;
  final DriverAssignmentRepository repository;
  final VoidCallback? onSessionExpired;
  final VoidCallback? onLogout;
  final DriverPreviewContext? previewContext;

  @override
  State<_DriverHomePage> createState() => _DriverHomePageState();
}

class _DriverHomePageState extends State<_DriverHomePage>
    with WidgetsBindingObserver {
  static const _summaryRefreshInterval = Duration(seconds: 15);

  bool _loading = true;
  bool _loadFailed = false;
  bool _showingStaleData = false;
  bool _refreshInFlight = false;
  bool _refreshPending = false;
  int _lifecycleEpoch = 0;
  Timer? _summaryRefreshTimer;
  List<DriverAssignment> _assignments = const [];

  static const _statuses = [
    ('accepted', Icons.verified_rounded),
    ('picked_up', Icons.inventory_2_rounded),
    ('out_for_delivery', Icons.local_shipping_rounded),
    ('failed', Icons.report_problem_rounded),
    ('delivered', Icons.task_alt_rounded),
  ];

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    unawaited(_loadSummary());
    _startLiveRefresh();
  }

  @override
  void dispose() {
    _stopLiveRefresh();
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      unawaited(_loadSummary(showLoading: false));
      _startLiveRefresh();
      return;
    }

    _stopLiveRefresh();
  }

  void _startLiveRefresh() {
    _summaryRefreshTimer?.cancel();
    _summaryRefreshTimer = Timer.periodic(
      _summaryRefreshInterval,
      (_) => unawaited(_loadSummary(showLoading: false)),
    );
  }

  void _stopLiveRefresh() {
    _summaryRefreshTimer?.cancel();
    _summaryRefreshTimer = null;
    _lifecycleEpoch++;
  }

  Future<void> _loadSummary({bool showLoading = true}) async {
    if (_refreshInFlight) {
      _refreshPending = true;
      return;
    }
    _refreshInFlight = true;
    final requestEpoch = _lifecycleEpoch;

    if (mounted && showLoading) {
      setState(() {
        _loading = true;
        _loadFailed = false;
      });
    }

    try {
      final rows = await widget.repository.list(widget.channel);
      if (!mounted || requestEpoch != _lifecycleEpoch) return;
      final preview = widget.previewContext;
      setState(() {
        _assignments = rows
            .where(
              (row) =>
                  row.channel == widget.channel &&
                  (preview == null ||
                      preview.allowsAssignment(
                        assignmentChannel: row.channel,
                        assignmentStoreId: row.storeId,
                      )),
            )
            .toList(growable: false);
        _loading = false;
        _loadFailed = false;
        _showingStaleData = false;
      });
    } on DriverSessionExpiredException {
      widget.onSessionExpired?.call();
    } catch (_) {
      if (mounted) {
        if (showLoading || _assignments.isEmpty) {
          setState(() {
            _loading = false;
            _loadFailed = true;
            _showingStaleData = false;
          });
        } else {
          setState(() => _showingStaleData = true);
        }
      }
    } finally {
      _refreshInFlight = false;
      if (_refreshPending && mounted) {
        _refreshPending = false;
        unawaited(_loadSummary(showLoading: false));
      }
    }
  }

  int _count(String status) =>
      _assignments.where((assignment) => assignment.status == status).length;

  Future<void> _openStatus(String status) async {
    await Navigator.of(
      context,
    ).pushNamed(widget.deliveriesRoute, arguments: status);
    if (mounted) await _loadSummary();
  }

  @override
  Widget build(BuildContext context) {
    final driverName = widget.driverName.trim();

    return DriverShellScaffold(
      destination: DriverShellDestination.home,
      homeRoute: widget.homeRoute,
      deliveriesRoute: widget.deliveriesRoute,
      notificationsRoute: widget.notificationsRoute,
      appBar: AppBar(
        titleSpacing: 16,
        title: Row(
          children: [
            Container(
              width: 38,
              height: 38,
              decoration: BoxDecoration(
                color: FoodexBrand.greenSoft,
                borderRadius: BorderRadius.circular(12),
              ),
              child: const Icon(
                Icons.local_shipping_rounded,
                color: FoodexBrand.greenDark,
                size: 21,
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    context.tr('driver.app.title'),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                  if (driverName.isNotEmpty)
                    Text(
                      driverName,
                      key: const Key('driver-home-driver-name'),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: Theme.of(context).textTheme.labelMedium?.copyWith(
                        color: FoodexBrand.muted,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                ],
              ),
            ),
          ],
        ),
        actions: [
          IconButton(
            key: const Key('driver-home-refresh'),
            onPressed: _loading ? null : _loadSummary,
            tooltip: context.tr('driver.refresh'),
            icon: const Icon(Icons.refresh_rounded),
          ),
          if (widget.onLogout != null)
            IconButton(
              key: const Key('driver-logout'),
              onPressed: widget.onLogout,
              tooltip: context.tr('driver.logout'),
              icon: const Icon(Icons.logout_rounded),
            ),
        ],
      ),
      body: SafeArea(
        child: RefreshIndicator(
          onRefresh: _loadSummary,
          child: ListView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 20),
            children: [
              Container(
                padding: const EdgeInsets.all(18),
                decoration: BoxDecoration(
                  gradient: const LinearGradient(
                    begin: AlignmentDirectional.topStart,
                    end: AlignmentDirectional.bottomEnd,
                    colors: [FoodexBrand.greenDark, FoodexBrand.green],
                  ),
                  borderRadius: BorderRadius.circular(24),
                  boxShadow: const [
                    BoxShadow(
                      color: Color(0x24165D2D),
                      blurRadius: 22,
                      offset: Offset(0, 10),
                    ),
                  ],
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Row(
                      children: [
                        Container(
                          width: 42,
                          height: 42,
                          decoration: BoxDecoration(
                            color: const Color(0x29FFFFFF),
                            borderRadius: BorderRadius.circular(14),
                          ),
                          child: const Icon(
                            Icons.route_rounded,
                            color: Colors.white,
                          ),
                        ),
                        const SizedBox(width: 12),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                driverName.isEmpty
                                    ? context.tr('driver.home.title')
                                    : driverName,
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: const TextStyle(
                                  color: Colors.white,
                                  fontSize: 22,
                                  fontWeight: FontWeight.w900,
                                ),
                              ),
                              const SizedBox(height: 3),
                              Text(
                                context.tr('driver.home.subtitle'),
                                maxLines: 2,
                                overflow: TextOverflow.ellipsis,
                                style: const TextStyle(
                                  color: Color(0xFFE3F5E9),
                                  fontSize: 13,
                                  fontWeight: FontWeight.w600,
                                ),
                              ),
                            ],
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 16),
                    Row(
                      children: [
                        Expanded(
                          child: FilledButton.icon(
                            key: const Key('driver-open-deliveries'),
                            onPressed: () => Navigator.of(
                              context,
                            ).pushNamed(widget.deliveriesRoute),
                            style: FilledButton.styleFrom(
                              backgroundColor: Colors.white,
                              foregroundColor: FoodexBrand.greenDark,
                              minimumSize: const Size(0, 48),
                            ),
                            icon: const Icon(Icons.route_rounded, size: 19),
                            label: Text(
                              context.tr('driver.home.open_deliveries'),
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                            ),
                          ),
                        ),
                        const SizedBox(width: 10),
                        Expanded(
                          child: OutlinedButton.icon(
                            key: const Key('driver-open-notifications'),
                            onPressed: () => Navigator.of(
                              context,
                            ).pushNamed(widget.notificationsRoute),
                            style: OutlinedButton.styleFrom(
                              foregroundColor: Colors.white,
                              side: const BorderSide(color: Colors.white54),
                              minimumSize: const Size(0, 48),
                            ),
                            icon: const Icon(
                              Icons.notifications_none_rounded,
                              size: 19,
                            ),
                            label: Text(
                              context.tr('driver.notifications.title'),
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                            ),
                          ),
                        ),
                      ],
                    ),
                    if (widget.walletRoute != null) ...[
                      const SizedBox(height: 10),
                      SizedBox(
                        width: double.infinity,
                        child: OutlinedButton.icon(
                          key: const Key('driver-open-wallet'),
                          onPressed: () => Navigator.of(
                            context,
                          ).pushNamed(widget.walletRoute!),
                          style: OutlinedButton.styleFrom(
                            foregroundColor: Colors.white,
                            side: const BorderSide(color: Colors.white54),
                            minimumSize: const Size(0, 48),
                          ),
                          icon: const Icon(
                            Icons.account_balance_wallet_outlined,
                            size: 19,
                          ),
                          label: Text(
                            context.tr('driver.home.open_wallet'),
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                          ),
                        ),
                      ),
                    ],
                  ],
                ),
              ),
              const SizedBox(height: 16),
              Text(
                context.tr('driver.home.status_summary'),
                key: const Key('driver-home-status-summary-title'),
                style: Theme.of(
                  context,
                ).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w900),
              ),
              const SizedBox(height: 10),
              _DriverWorkloadChart(
                loading: _loading && _assignments.isEmpty,
                failed: _loadFailed,
                stale: _showingStaleData,
                statuses: [
                  for (final status in _statuses)
                    _DriverWorkloadSlice(
                      label: context.tr('driver.status.${status.$1}'),
                      value: _count(status.$1),
                    ),
                ],
              ),
              const SizedBox(height: 10),
              if (_loadFailed)
                Card(
                  child: ListTile(
                    leading: const Icon(Icons.cloud_off_rounded),
                    title: Text(context.tr('driver.error')),
                    trailing: TextButton(
                      onPressed: _loadSummary,
                      child: Text(context.tr('driver.retry')),
                    ),
                  ),
                )
              else
                GridView.count(
                  key: const Key('driver-home-status-grid'),
                  shrinkWrap: true,
                  physics: const NeverScrollableScrollPhysics(),
                  crossAxisCount: 2,
                  crossAxisSpacing: 10,
                  mainAxisSpacing: 10,
                  childAspectRatio: 1.9,
                  children: [
                    for (final status in _statuses)
                      _DriverStatusCard(
                        key: Key('driver-home-status-${status.$1}'),
                        icon: status.$2,
                        label: context.tr('driver.status.${status.$1}'),
                        value: _loading ? null : _count(status.$1),
                        onTap: _loading ? null : () => _openStatus(status.$1),
                      ),
                  ],
                ),
            ],
          ),
        ),
      ),
    );
  }
}

class _DriverWorkloadSlice {
  const _DriverWorkloadSlice({required this.label, required this.value});

  final String label;
  final int value;
}

class _DriverWorkloadChart extends StatelessWidget {
  const _DriverWorkloadChart({
    required this.loading,
    required this.failed,
    required this.stale,
    required this.statuses,
  });

  final bool loading;
  final bool failed;
  final bool stale;
  final List<_DriverWorkloadSlice> statuses;

  @override
  Widget build(BuildContext context) {
    final total = statuses.fold<int>(0, (sum, item) => sum + item.value);
    final labels = [
      for (final item in statuses) '${item.label} · ${item.value}',
    ];
    final semanticBreakdown = labels.join(', ');
    final semanticLabel = semanticBreakdown.isEmpty
        ? context.tr('driver.home.analytics.semantic')
        : '${context.tr('driver.home.analytics.semantic')}: $semanticBreakdown';

    Widget body;
    if (loading) {
      body = Row(
        children: [
          const SizedBox(
            width: 22,
            height: 22,
            child: CircularProgressIndicator(strokeWidth: 2.2),
          ),
          const SizedBox(width: 10),
          Expanded(child: Text(context.tr('driver.home.analytics.loading'))),
        ],
      );
    } else if (failed) {
      body = Text(context.tr('driver.home.analytics.error'));
    } else if (total == 0) {
      body = Text(context.tr('driver.home.analytics.empty'));
    } else {
      final chart = FoodexDonutChart(
        values: [for (final item in statuses) item.value.toDouble()],
        semanticLabel: semanticLabel,
        size: 132,
        strokeWidth: 19,
      );
      final legend = FoodexChartLegend(labels: labels);

      body = LayoutBuilder(
        builder: (context, constraints) {
          if (constraints.maxWidth < 430) {
            return Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Align(alignment: Alignment.center, child: chart),
                const SizedBox(height: 14),
                legend,
              ],
            );
          }
          return Row(
            crossAxisAlignment: CrossAxisAlignment.center,
            children: [
              chart,
              const SizedBox(width: 18),
              Expanded(child: legend),
            ],
          );
        },
      );
    }

    return Card(
      key: const Key('driver-home-workload-chart'),
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                const Icon(
                  Icons.donut_large_rounded,
                  size: 20,
                  color: FoodexChartPalette.primaryDark,
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    context.tr('driver.home.analytics.title'),
                    style: Theme.of(context).textTheme.titleSmall?.copyWith(
                      fontWeight: FontWeight.w900,
                    ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 5),
            Text(
              context.tr('driver.home.analytics.summary'),
              style: Theme.of(context).textTheme.bodySmall?.copyWith(
                color: FoodexBrand.muted,
                fontWeight: FontWeight.w600,
              ),
            ),
            if (stale) ...[
              const SizedBox(height: 8),
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Icon(
                    Icons.cloud_off_rounded,
                    size: 17,
                    color: FoodexChartPalette.warning,
                  ),
                  const SizedBox(width: 6),
                  Expanded(
                    child: Text(
                      context.tr('driver.home.analytics.stale'),
                      style: Theme.of(context).textTheme.bodySmall?.copyWith(
                        color: FoodexChartPalette.warning,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                  ),
                ],
              ),
            ],
            const SizedBox(height: 14),
            body,
          ],
        ),
      ),
    );
  }
}

class _DriverStatusCard extends StatelessWidget {
  const _DriverStatusCard({
    super.key,
    required this.icon,
    required this.label,
    required this.value,
    required this.onTap,
  });

  final IconData icon;
  final String label;
  final int? value;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) => Material(
    color: FoodexBrand.surface,
    borderRadius: BorderRadius.circular(18),
    child: InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(18),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 11),
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(18),
          border: Border.all(color: FoodexBrand.border),
        ),
        child: Row(
          children: [
            Container(
              width: 38,
              height: 38,
              decoration: BoxDecoration(
                color: FoodexBrand.greenSoft,
                borderRadius: BorderRadius.circular(12),
              ),
              child: Icon(icon, color: FoodexBrand.greenDark, size: 20),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: Text(
                label,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(
                  fontSize: 12,
                  fontWeight: FontWeight.w800,
                ),
              ),
            ),
            const SizedBox(width: 6),
            if (value == null)
              const SizedBox(
                width: 20,
                height: 20,
                child: CircularProgressIndicator(strokeWidth: 2.1),
              )
            else
              Text(
                value.toString(),
                style: const TextStyle(
                  fontSize: 21,
                  fontWeight: FontWeight.w900,
                ),
              ),
          ],
        ),
      ),
    ),
  );
}

class _DriverRouteDenied extends StatelessWidget {
  const _DriverRouteDenied();

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Center(
        child: Text(
          context.tr('driver.route.denied'),
          key: const Key('driver-route-denied'),
        ),
      ),
    );
  }
}

class _DriverRouteNotFound extends StatelessWidget {
  const _DriverRouteNotFound();

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Center(
        child: Text(
          context.tr('driver.route.not_found'),
          key: const Key('driver-route-not-found'),
        ),
      ),
    );
  }
}
