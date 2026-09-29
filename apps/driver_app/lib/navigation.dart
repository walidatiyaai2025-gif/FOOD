import 'package:flutter/material.dart';

import 'core/auth/driver_session.dart';
import 'core/localization/driver_translations.dart';
import 'core/theme/foodex_theme.dart';
import 'features/tasks/driver_journey.dart';

export 'core/auth/driver_session.dart' show DriverChannel;

abstract final class DriverRoutes {
  static const root = '/';
  static const b2cHome = '/driver/b2c/home';
  static const b2cDeliveries = '/driver/b2c/deliveries';
  static const b2bHome = '/driver/b2b/home';
  static const b2bDeliveries = '/driver/b2b/deliveries';

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
    this.onSessionExpired,
    this.onLogout,
  });

  final DriverChannel channel;
  final DriverAssignmentRepository repository;
  final VoidCallback? onSessionExpired;
  final VoidCallback? onLogout;

  Route<dynamic> onGenerateRoute(RouteSettings settings) {
    final name = settings.name ?? DriverRoutes.root;

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
        return _page(
          DriverJourneyPage(
            channel: channel,
            repository: repository,
            initialStatusFilter: settings.arguments is String
                ? settings.arguments as String
                : null,
            onSessionExpired: onSessionExpired,
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

    return _DriverHomePage(
      routeName: route,
      deliveriesRoute: deliveries,
      channel: channel,
      repository: repository,
      onSessionExpired: onSessionExpired,
      onLogout: onLogout,
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
    required this.channel,
    required this.repository,
    this.onSessionExpired,
    this.onLogout,
  });

  final String routeName;
  final String deliveriesRoute;
  final DriverChannel channel;
  final DriverAssignmentRepository repository;
  final VoidCallback? onSessionExpired;
  final VoidCallback? onLogout;

  @override
  State<_DriverHomePage> createState() => _DriverHomePageState();
}

class _DriverHomePageState extends State<_DriverHomePage> {
  bool _loading = true;
  bool _loadFailed = false;
  List<DriverAssignment> _assignments = const [];

  @override
  void initState() {
    super.initState();
    _loadCounts();
  }

  Future<void> _loadCounts() async {
    if (mounted) {
      setState(() {
        _loading = true;
        _loadFailed = false;
      });
    }
    try {
      final rows = await widget.repository.list(widget.channel);
      if (!mounted) return;
      setState(() {
        _assignments = rows
            .where((row) => row.channel == widget.channel)
            .toList(growable: false);
        _loading = false;
      });
    } on DriverSessionExpiredException {
      widget.onSessionExpired?.call();
      if (mounted) {
        setState(() {
          _loading = false;
          _loadFailed = true;
        });
      }
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

  void _openStatus(String status) {
    Navigator.of(context).pushNamed(widget.deliveriesRoute, arguments: status);
  }

  @override
  Widget build(BuildContext context) {
    final metrics = [
      ('accepted', Icons.task_alt_rounded, FoodexBrand.greenSoft, FoodexBrand.greenDark),
      ('picked_up', Icons.inventory_2_rounded, FoodexBrand.orangeSoft, FoodexBrand.orange),
      ('out_for_delivery', Icons.local_shipping_rounded, FoodexBrand.surfaceMuted, FoodexBrand.greenDark),
      ('delivered', Icons.verified_rounded, FoodexBrand.greenSoft, FoodexBrand.greenDark),
    ];

    return Scaffold(
      appBar: AppBar(
        title: Text(context.tr('driver.app.title')),
        actions: [
          IconButton(
            key: const Key('driver-home-refresh'),
            onPressed: _loading ? null : _loadCounts,
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
        child: ListView(
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
                ],
              ),
            ),
            const SizedBox(height: 16),
            if (_loadFailed)
              Card(
                child: ListTile(
                  leading: const Icon(Icons.error_outline_rounded),
                  title: Text(context.tr('driver.error')),
                  trailing: TextButton(
                    onPressed: _loadCounts,
                    child: Text(context.tr('driver.retry')),
                  ),
                ),
              )
            else
              LayoutBuilder(
                builder: (context, constraints) {
                  final itemWidth = (constraints.maxWidth - 24) / 4;
                  return Row(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      for (var index = 0; index < metrics.length; index++) ...[
                        if (index > 0) const SizedBox(width: 8),
                        SizedBox(
                          width: itemWidth,
                          child: _DriverHomeMetric(
                            key: Key('driver-home-status-${metrics[index].$1}'),
                            icon: metrics[index].$2,
                            label: context.tr('driver.status.${metrics[index].$1}'),
                            value: _loading ? '…' : _count(metrics[index].$1).toString(),
                            tone: metrics[index].$3,
                            foreground: metrics[index].$4,
                            onTap: _loading ? null : () => _openStatus(metrics[index].$1),
                          ),
                        ),
                      ],
                    ],
                  );
                },
              ),
            const SizedBox(height: 12),
            Card(
              child: ListTile(
                leading: const Icon(Icons.shield_outlined),
                title: Text(context.tr('driver.app.title')),
                subtitle: Text(widget.routeName, key: const Key('driver-route')),
                trailing: const Icon(Icons.lock_outline_rounded),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _DriverHomeMetric extends StatelessWidget {
  const _DriverHomeMetric({
    super.key,
    required this.icon,
    required this.label,
    required this.value,
    required this.tone,
    required this.foreground,
    this.onTap,
  });

  final IconData icon;
  final String label;
  final String value;
  final Color tone;
  final Color foreground;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) => InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(20),
        child: Container(
          constraints: const BoxConstraints(minHeight: 122),
          padding: const EdgeInsets.all(16),
          decoration: BoxDecoration(
            color: tone,
            borderRadius: BorderRadius.circular(20),
            border: Border.all(color: foreground.withAlpha(31)),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(icon, color: foreground),
              const SizedBox(height: 18),
              Text(
                value,
                style: TextStyle(
                  color: foreground,
                  fontSize: 22,
                  fontWeight: FontWeight.w900,
                ),
              ),
              const SizedBox(height: 3),
              Text(label, maxLines: 2, overflow: TextOverflow.ellipsis),
            ],
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
