import 'package:flutter/material.dart';

import 'core/auth/driver_session.dart';
import 'core/diagnostics/driver_runtime_inspector.dart';
import 'core/localization/driver_translations.dart';
import 'core/preview/driver_preview_context.dart';
import 'core/theme/foodex_theme.dart';
import 'features/notifications/driver_notification_page.dart';
import 'features/notifications/notification_feed.dart';
import 'features/tasks/driver_journey.dart';

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
    this.notificationRepository,
    this.onSessionExpired,
    this.onLogout,
    this.previewContext,
  });

  final DriverChannel channel;
  final DriverAssignmentRepository repository;
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
          DriverJourneyPage(
            channel: channel,
            repository: repository,
            onSessionExpired: onSessionExpired,
            focusAssignmentId: focusAssignmentId,
            initialAssignmentStatus: initialAssignmentStatus,
            previewContext: previewContext,
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
    final route = channel == DriverChannel.b2c
        ? DriverRoutes.b2cHome
        : DriverRoutes.b2bHome;
    final deliveries = channel == DriverChannel.b2c
        ? DriverRoutes.b2cDeliveries
        : DriverRoutes.b2bDeliveries;
    final notifications = channel == DriverChannel.b2c
        ? DriverRoutes.b2cNotifications
        : DriverRoutes.b2bNotifications;

    return _DriverHomePage(
      routeName: route,
      deliveriesRoute: deliveries,
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
    required this.routeName,
    required this.deliveriesRoute,
    required this.notificationsRoute,
    required this.channel,
    required this.repository,
    this.onSessionExpired,
    this.onLogout,
    this.previewContext,
  });

  final String routeName;
  final String deliveriesRoute;
  final String notificationsRoute;
  final DriverChannel channel;
  final DriverAssignmentRepository repository;
  final VoidCallback? onSessionExpired;
  final VoidCallback? onLogout;
  final DriverPreviewContext? previewContext;

  @override
  State<_DriverHomePage> createState() => _DriverHomePageState();
}

class _DriverHomePageState extends State<_DriverHomePage> {
  bool _loading = true;
  bool _loadFailed = false;
  List<DriverAssignment> _assignments = const [];

  static const _statuses = [
    ('accepted', Icons.verified_rounded),
    ('picked_up', Icons.inventory_2_rounded),
    ('out_for_delivery', Icons.local_shipping_rounded),
    ('delivered', Icons.task_alt_rounded),
  ];

  @override
  void initState() {
    super.initState();
    _loadSummary();
  }

  Future<void> _loadSummary() async {
    if (mounted) {
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
      });
    } on DriverSessionExpiredException {
      widget.onSessionExpired?.call();
    } catch (_) {
      if (mounted) {
        setState(() {
          _loading = false;
          _loadFailed = true;
        });
      }
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
    return Scaffold(
      appBar: AppBar(
        title: Text(context.tr('driver.app.title')),
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
              icon: const Icon(Icons.logout),
            ),
        ],
      ),
      body: SafeArea(
        child: RefreshIndicator(
          onRefresh: _loadSummary,
          child: ListView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.fromLTRB(16, 14, 16, 28),
            children: [
              Container(
                padding: const EdgeInsets.all(22),
                decoration: BoxDecoration(
                  gradient: const LinearGradient(
                    begin: AlignmentDirectional.topStart,
                    end: AlignmentDirectional.bottomEnd,
                    colors: [FoodexBrand.greenDark, FoodexBrand.green],
                  ),
                  borderRadius: BorderRadius.circular(26),
                  boxShadow: const [
                    BoxShadow(
                      color: Color(0x24165D2D),
                      blurRadius: 24,
                      offset: Offset(0, 12),
                    ),
                  ],
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    const Row(
                      children: [
                        CircleAvatar(
                          radius: 24,
                          backgroundColor: Colors.white24,
                          foregroundColor: Colors.white,
                          child: Icon(Icons.local_shipping_rounded),
                        ),
                        SizedBox(width: 12),
                        Expanded(
                          child: Text(
                            'FOODEX DRIVER',
                            style: TextStyle(
                              color: Colors.white,
                              fontWeight: FontWeight.w900,
                              letterSpacing: .8,
                            ),
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 22),
                    Text(
                      context.tr('driver.home.title'),
                      style: const TextStyle(
                        color: Colors.white,
                        fontSize: 28,
                        fontWeight: FontWeight.w900,
                      ),
                    ),
                    const SizedBox(height: 6),
                    Text(
                      context.tr('driver.home.subtitle'),
                      style: const TextStyle(color: Color(0xFFE3F5E9)),
                    ),
                    const SizedBox(height: 18),
                    FilledButton.icon(
                      key: const Key('driver-open-deliveries'),
                      onPressed: () => Navigator.of(context).pushNamed(widget.deliveriesRoute),
                      style: FilledButton.styleFrom(
                        backgroundColor: Colors.white,
                        foregroundColor: FoodexBrand.greenDark,
                      ),
                      icon: const Icon(Icons.route_rounded),
                      label: Text(context.tr('driver.home.open_deliveries')),
                    ),
                    const SizedBox(height: 10),
                    OutlinedButton.icon(
                      key: const Key('driver-open-notifications'),
                      onPressed: () => Navigator.of(context).pushNamed(
                        widget.notificationsRoute,
                      ),
                      style: OutlinedButton.styleFrom(
                        foregroundColor: Colors.white,
                        side: const BorderSide(color: Colors.white54),
                      ),
                      icon: const Icon(Icons.notifications_none_rounded),
                      label: Text(context.tr('driver.notifications.title')),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 16),
              Text(
                context.tr('driver.home.status_summary'),
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
                SingleChildScrollView(
                  key: const Key('driver-home-status-cards'),
                  scrollDirection: Axis.horizontal,
                  child: Row(
                    children: [
                      for (var i = 0; i < _statuses.length; i++) ...[
                        SizedBox(
                          width: 150,
                          child: _DriverStatusCard(
                            key: Key('driver-home-status-${_statuses[i].$1}'),
                            icon: _statuses[i].$2,
                            label: context.tr('driver.status.${_statuses[i].$1}'),
                            value: _loading ? null : _count(_statuses[i].$1),
                            onTap: _loading ? null : () => _openStatus(_statuses[i].$1),
                          ),
                        ),
                        if (i != _statuses.length - 1) const SizedBox(width: 10),
                      ],
                    ],
                  ),
                ),
              const SizedBox(height: 12),
              Card(
                child: ListTile(
                  leading: const Icon(Icons.hub_rounded),
                  title: Text(context.tr('driver.home.channel')),
                  subtitle: Text(
                    widget.routeName,
                    key: const Key('driver-route'),
                  ),
                  trailing: Text(
                    widget.routeName.contains('/b2b/') ? 'B2B' : 'Retail',
                    style: const TextStyle(fontWeight: FontWeight.w900),
                  ),
                ),
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
        borderRadius: BorderRadius.circular(20),
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(20),
          child: Container(
            constraints: const BoxConstraints(minHeight: 128),
            padding: const EdgeInsets.all(15),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(20),
              border: Border.all(color: FoodexBrand.border),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Container(
                  width: 38,
                  height: 38,
                  decoration: BoxDecoration(
                    color: FoodexBrand.greenSoft,
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: Icon(icon, color: FoodexBrand.greenDark, size: 21),
                ),
                const SizedBox(height: 16),
                if (value == null)
                  const SizedBox(
                    width: 22,
                    height: 22,
                    child: CircularProgressIndicator(strokeWidth: 2.2),
                  )
                else
                  Text(
                    value.toString(),
                    style: const TextStyle(
                      fontSize: 24,
                      fontWeight: FontWeight.w900,
                    ),
                  ),
                const SizedBox(height: 4),
                Text(
                  label,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(fontWeight: FontWeight.w700),
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
