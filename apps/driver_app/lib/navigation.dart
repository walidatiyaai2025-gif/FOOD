import 'dart:async';

import 'package:flutter/material.dart';

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

export 'core/auth/driver_session.dart' show DriverChannel;

abstract final class DriverRoutes {
  static const root = '/';
  static const b2cHome = '/driver/b2c/home';
  static const b2cDeliveries = '/driver/b2c/deliveries';
  static const b2cNotifications = '/driver/b2c/notifications';
  static const b2bHome = '/driver/b2b/home';
  static const b2bDeliveries = '/driver/b2b/deliveries';
  static const b2bNotifications = '/driver/b2b/notifications';

  static bool belongsTo(String route, DriverChannel channel) {
    final prefix =
        channel == DriverChannel.b2c ? '/driver/b2c/' : '/driver/b2b/';
    return route.startsWith(prefix);
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

    if (name == DriverRoutes.root) return _page(_homeFor(channel), settings);

    if (!DriverRoutes.belongsTo(name, channel)) {
      return _page(const _DriverRouteDenied(), settings);
    }

    switch (name) {
      case DriverRoutes.b2cHome:
      case DriverRoutes.b2bHome:
        return _page(_homeFor(channel), settings);
      case DriverRoutes.b2cDeliveries:
      case DriverRoutes.b2bDeliveries:
        final focusAssignmentId =
            settings.arguments is int ? settings.arguments as int : null;
        final initialAssignmentStatus =
            settings.arguments is String ? settings.arguments as String : null;
        return _page(
          DriverJourneyRuntimePage(
            channel: channel,
            repository: repository,
            onSessionExpired: onSessionExpired,
            focusAssignmentId: focusAssignmentId,
            initialAssignmentStatus: initialAssignmentStatus,
            previewContext: previewContext,
            homeRoute: channel == DriverChannel.b2c
                ? DriverRoutes.b2cHome
                : DriverRoutes.b2bHome,
            deliveriesRoute: channel == DriverChannel.b2c
                ? DriverRoutes.b2cDeliveries
                : DriverRoutes.b2bDeliveries,
            notificationsRoute: channel == DriverChannel.b2c
                ? DriverRoutes.b2cNotifications
                : DriverRoutes.b2bNotifications,
          ),
          settings,
        );
      case DriverRoutes.b2cNotifications:
      case DriverRoutes.b2bNotifications:
        final notifications = notificationRepository;
        if (notifications == null) {
          return _page(const _DriverRouteNotFound(), settings);
        }
        final deliveriesRoute = channel == DriverChannel.b2c
            ? DriverRoutes.b2cDeliveries
            : DriverRoutes.b2bDeliveries;
        return _page(
          Builder(
            builder: (context) => DriverNotificationPage(
              repository: notifications,
              onSessionExpired: onSessionExpired,
              homeRoute: channel == DriverChannel.b2c
                  ? DriverRoutes.b2cHome
                  : DriverRoutes.b2bHome,
              deliveriesRoute: deliveriesRoute,
              notificationsRoute: channel == DriverChannel.b2c
                  ? DriverRoutes.b2cNotifications
                  : DriverRoutes.b2bNotifications,
              onOpenAssignment: (assignmentId) {
                Navigator.of(context).pushNamed(
                  deliveriesRoute,
                  arguments: assignmentId,
                );
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
    final home = channel == DriverChannel.b2c
        ? DriverRoutes.b2cHome
        : DriverRoutes.b2bHome;
    final deliveries = channel == DriverChannel.b2c
        ? DriverRoutes.b2cDeliveries
        : DriverRoutes.b2bDeliveries;
    final notifications = channel == DriverChannel.b2c
        ? DriverRoutes.b2cNotifications
        : DriverRoutes.b2bNotifications;

    return _DriverHomePage(
      homeRoute: home,
      deliveriesRoute: deliveries,
      driverName: driverName,
      notificationsRoute: notifications,
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
  bool _refreshInFlight = false;
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
    _loadSummary();
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
      _loadSummary(showLoading: false);
      _startLiveRefresh();
      return;
    }

    _stopLiveRefresh();
  }

  void _startLiveRefresh() {
    _summaryRefreshTimer?.cancel();
    _summaryRefreshTimer = Timer.periodic(
      _summaryRefreshInterval,
      (_) => _loadSummary(showLoading: false),
    );
  }

  void _stopLiveRefresh() {
    _summaryRefreshTimer?.cancel();
    _summaryRefreshTimer = null;
  }

  Future<void> _loadSummary({bool showLoading = true}) async {
    if (_refreshInFlight) return;
    _refreshInFlight = true;

    if (mounted && showLoading) {
      setState(() {
        _loading = true;
        _loadFailed = false;
      });
    }

    try {
      final rows = await widget.repository.list(widget.channel);
      if (!mounted) return;
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
      });
    } on DriverSessionExpiredException {
      widget.onSessionExpired?.call();
    } catch (_) {
      if (mounted && (showLoading || _assignments.isEmpty)) {
        setState(() {
          _loading = false;
          _loadFailed = true;
        });
      }
    } finally {
      _refreshInFlight = false;
    }
  }

  int _count(String status) =>
      _assignments.where((assignment) => assignment.status == status).length;

  Future<void> _openStatus(String status) async {
    await Navigator.of(context).pushNamed(
      widget.deliveriesRoute,
      arguments: status,
    );
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
                            onPressed: () => Navigator.of(context)
                                .pushNamed(widget.deliveriesRoute),
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
                            onPressed: () => Navigator.of(context)
                                .pushNamed(widget.notificationsRoute),
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
                  ],
                ),
              ),
              const SizedBox(height: 16),
              Text(
                context.tr('driver.home.status_summary'),
                key: const Key('driver-home-status-summary-title'),
                style: Theme.of(context).textTheme.titleMedium?.copyWith(
                      fontWeight: FontWeight.w900,
                    ),
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
