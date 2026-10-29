import 'dart:async';

import 'package:flutter/material.dart';
import 'package:foodex_visualization/foodex_visualization.dart';

import 'core/auth/driver_session.dart';
import 'core/diagnostics/driver_runtime_inspector.dart';
import 'core/localization/driver_translations.dart';
import 'core/navigation/driver_shell.dart';
import 'core/preview/driver_preview_context.dart';
import 'core/push/firebase_push_service.dart';
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
  static const b2bHome = '/driver/b2b/home';
  static const b2bDeliveries = '/driver/b2b/deliveries';
  static const b2bNotifications = '/driver/b2b/notifications';
  static const b2bWallet = '/driver/b2b/wallet';

  static String home(DriverChannel channel) => channel == DriverChannel.b2b
      ? DriverRoutes.b2bHome
      : DriverRoutes.b2cHome;

  static String deliveries(DriverChannel channel) =>
      channel == DriverChannel.b2b
          ? DriverRoutes.b2bDeliveries
          : DriverRoutes.b2cDeliveries;

  static String notifications(DriverChannel channel) =>
      channel == DriverChannel.b2b
          ? DriverRoutes.b2bNotifications
          : DriverRoutes.b2cNotifications;

  static String wallet(DriverChannel channel) => channel == DriverChannel.b2b
      ? DriverRoutes.b2bWallet
      : DriverRoutes.b2cWallet;

  static bool belongsTo(String route, DriverChannel channel) {
    final prefix =
        channel == DriverChannel.b2b ? '/driver/b2b/' : '/driver/b2c/';
    return route.startsWith(prefix);
  }
}

class DriverNavigator {
  const DriverNavigator(
    this.channel, {
    required this.repository,
    this.driverName = '',
    this.notificationRepository,
    this.pushAlerts,
    this.onSessionExpired,
    this.onLogout,
    this.previewContext,
  });

  final DriverChannel channel;
  final DriverAssignmentRepository repository;
  final String driverName;
  final DriverNotificationRepository? notificationRepository;
  final Stream<DriverPushAlert>? pushAlerts;
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

    final homeRoute = DriverRoutes.home(channel);
    final deliveriesRoute = DriverRoutes.deliveries(channel);
    final notificationsRoute = DriverRoutes.notifications(channel);
    final walletRoute = repository is DriverWalletRepository
        ? DriverRoutes.wallet(channel)
        : null;

    if (name == homeRoute) {
      return _page(_homeFor(channel), settings);
    }
    if (name == deliveriesRoute) {
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
          homeRoute: homeRoute,
          deliveriesRoute: deliveriesRoute,
          notificationsRoute: notificationsRoute,
          walletRoute: walletRoute,
        ),
        settings,
      );
    }
    if (name == notificationsRoute) {
      final notifications = notificationRepository;
      if (notifications == null) {
        return _page(const _DriverRouteNotFound(), settings);
      }
      return _page(
        Builder(
          builder: (context) => DriverNotificationPage(
            repository: notifications,
            onSessionExpired: onSessionExpired,
            homeRoute: homeRoute,
            deliveriesRoute: deliveriesRoute,
            notificationsRoute: notificationsRoute,
            walletRoute: walletRoute,
            onOpenAssignment: (assignmentId) {
              Navigator.of(context)
                  .pushNamed(deliveriesRoute, arguments: assignmentId);
            },
          ),
        ),
        settings,
      );
    }
    if (name == DriverRoutes.wallet(channel)) {
      if (walletRoute == null) {
        return _page(const _DriverRouteNotFound(), settings);
      }
      final walletRepository = repository as DriverWalletRepository;
      return _page(
        DriverWalletPage(
          repository: walletRepository,
          homeRoute: homeRoute,
          deliveriesRoute: deliveriesRoute,
          notificationsRoute: notificationsRoute,
          walletRoute: walletRoute,
        ),
        settings,
      );
    }

    return _page(const _DriverRouteNotFound(), settings);
  }

  Widget _homeFor(DriverChannel channel) {
    final home = DriverRoutes.home(channel);
    final deliveries = DriverRoutes.deliveries(channel);
    final notifications = DriverRoutes.notifications(channel);
    final wallet = repository is DriverWalletRepository
        ? DriverRoutes.wallet(channel)
        : null;

    return _DriverHomePage(
      homeRoute: home,
      deliveriesRoute: deliveries,
      driverName: driverName,
      notificationsRoute: notifications,
      walletRoute: wallet,
      channel: channel,
      repository: repository,
      pushAlerts: pushAlerts,
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
    this.pushAlerts,
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
  final Stream<DriverPushAlert>? pushAlerts;
  final VoidCallback? onSessionExpired;
  final VoidCallback? onLogout;
  final DriverPreviewContext? previewContext;

  @override
  State<_DriverHomePage> createState() => _DriverHomePageState();
}

enum _DriverHomeUpdateKind { assigned, updated, removed }

class _DriverHomeUpdateEvent {
  const _DriverHomeUpdateEvent({
    required this.kind,
    required this.assignment,
  });

  final _DriverHomeUpdateKind kind;
  final DriverAssignment assignment;
}

class _DriverHomePageState extends State<_DriverHomePage>
    with WidgetsBindingObserver {
  static const _summaryRefreshInterval = Duration(seconds: 5);

  bool _loading = true;
  bool _loadFailed = false;
  bool _showingStaleData = false;
  bool _refreshInFlight = false;
  bool _refreshPending = false;
  int _lifecycleEpoch = 0;
  Timer? _summaryRefreshTimer;
  Timer? _pollingFallbackTimer;
  StreamSubscription<DriverPushAlert>? _homePushSubscription;
  List<DriverAssignment> _assignments = const [];
  List<DriverWalletAccount> _walletAccounts = const [];
  bool _walletLoading = true;
  bool _walletLoadFailed = false;
  bool _walletRefreshInFlight = false;
  DateTime? _lastWalletRefreshAt;
  static const _walletRefreshInterval = Duration(seconds: 15);
  final Map<int, DriverAssignment> _assignmentSnapshot =
      <int, DriverAssignment>{};
  final Map<int, String> _assignmentFingerprints = <int, String>{};
  final Map<int, DateTime> _recentPushAssignments = <int, DateTime>{};
  final List<_DriverHomeUpdateEvent> _updateQueue = <_DriverHomeUpdateEvent>[];
  final List<_DriverHomeUpdateEvent> _pendingPollingFallback =
      <_DriverHomeUpdateEvent>[];
  bool _assignmentSnapshotInitialized = false;
  bool _updatePopupActive = false;

  static const _statuses = [
    ('accepted', Icons.verified_rounded),
    ('out_for_delivery', Icons.local_shipping_rounded),
    ('failed', Icons.report_problem_rounded),
    ('delivered', Icons.task_alt_rounded),
  ];

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _homePushSubscription = widget.pushAlerts?.listen(_recordPushAlert);
    unawaited(_loadSummary());
    unawaited(_loadWalletSummary(force: true));
    if (widget.previewContext == null) {
      _startLiveRefresh();
    }
  }

  @override
  void dispose() {
    _stopLiveRefresh();
    _pollingFallbackTimer?.cancel();
    _pollingFallbackTimer = null;
    _pendingPollingFallback.clear();
    unawaited(_homePushSubscription?.cancel());
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didUpdateWidget(covariant _DriverHomePage oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (!identical(oldWidget.pushAlerts, widget.pushAlerts)) {
      unawaited(_homePushSubscription?.cancel());
      _homePushSubscription = widget.pushAlerts?.listen(_recordPushAlert);
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      unawaited(_loadSummary(showLoading: false));
      unawaited(_loadWalletSummary(force: true));
      if (widget.previewContext == null) {
        _startLiveRefresh();
      }
      return;
    }

    _stopLiveRefresh();
  }

  void _startLiveRefresh() {
    _summaryRefreshTimer?.cancel();
    _summaryRefreshTimer = Timer.periodic(
      _summaryRefreshInterval,
      (_) {
        unawaited(_loadSummary(showLoading: false));
        unawaited(_loadWalletSummary());
      },
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
      final nextAssignments = rows
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

      final detectedUpdates = <_DriverHomeUpdateEvent>[];
      if (_assignmentSnapshotInitialized) {
        final nextIds = nextAssignments.map((row) => row.id).toSet();

        for (final assignment in nextAssignments) {
          final previous = _assignmentSnapshot[assignment.id];
          if (previous == null) {
            detectedUpdates.add(
              _DriverHomeUpdateEvent(
                kind: _DriverHomeUpdateKind.assigned,
                assignment: assignment,
              ),
            );
            continue;
          }

          final previousFingerprint = _assignmentFingerprints[assignment.id];
          final nextFingerprint = _assignmentFingerprint(assignment);
          if (previousFingerprint != nextFingerprint) {
            detectedUpdates.add(
              _DriverHomeUpdateEvent(
                kind: _DriverHomeUpdateKind.updated,
                assignment: assignment,
              ),
            );
          }
        }

        for (final previous in _assignmentSnapshot.values) {
          if (!nextIds.contains(previous.id)) {
            detectedUpdates.add(
              _DriverHomeUpdateEvent(
                kind: _DriverHomeUpdateKind.removed,
                assignment: previous,
              ),
            );
          }
        }
      }

      _assignmentSnapshot
        ..clear()
        ..addEntries(nextAssignments.map((row) => MapEntry(row.id, row)));
      _assignmentFingerprints
        ..clear()
        ..addEntries(
          nextAssignments.map(
            (row) => MapEntry(row.id, _assignmentFingerprint(row)),
          ),
        );
      _assignmentSnapshotInitialized = true;

      setState(() {
        _assignments = nextAssignments;
        _loading = false;
        _loadFailed = false;
        _showingStaleData = false;
      });

      if (detectedUpdates.isNotEmpty && preview == null) {
        _schedulePollingFallback(detectedUpdates);
      }
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

  Future<void> _loadWalletSummary({bool force = false}) async {
    final repository = widget.repository;
    if (repository is! DriverWalletRepository) {
      if (mounted && _walletLoading) {
        setState(() => _walletLoading = false);
      }
      return;
    }
    final walletRepository = repository as DriverWalletRepository;

    final now = DateTime.now();
    final lastRefresh = _lastWalletRefreshAt;
    if (!force &&
        lastRefresh != null &&
        now.difference(lastRefresh) < _walletRefreshInterval) {
      return;
    }
    if (_walletRefreshInFlight) return;

    _walletRefreshInFlight = true;
    try {
      final accounts = await walletRepository.wallet();
      if (!mounted) return;
      setState(() {
        _walletAccounts = accounts;
        _walletLoading = false;
        _walletLoadFailed = false;
        _lastWalletRefreshAt = DateTime.now();
      });
    } on DriverSessionExpiredException {
      widget.onSessionExpired?.call();
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _walletLoading = false;
        _walletLoadFailed = true;
      });
    } finally {
      _walletRefreshInFlight = false;
    }
  }

  String _unsettledCollectionsValue() {
    final totals = <String, double>{};
    for (final account in _walletAccounts) {
      final currency = account.currency.trim().toUpperCase();
      if (currency.isEmpty) continue;
      totals.update(
        currency,
        (value) => value + account.unsettledCollectionsBalance,
        ifAbsent: () => account.unsettledCollectionsBalance,
      );
    }
    final entries = totals.entries
        .where((entry) => entry.value.abs() > 0.0005)
        .toList(growable: false)
      ..sort((a, b) => a.key.compareTo(b.key));
    if (entries.isEmpty) return '0.000';
    return entries
        .map((entry) => '${entry.value.toStringAsFixed(3)} ${entry.key}')
        .join(' · ');
  }

  void _recordPushAlert(DriverPushAlert alert) {
    final assignmentId = alert.open.assignmentId;
    if (assignmentId != null) {
      _recentPushAssignments[assignmentId] = DateTime.now();
      return;
    }

    final orderId = alert.open.orderId;
    if (orderId == null) return;
    for (final assignment in _assignments) {
      if (assignment.orderId == orderId) {
        _recentPushAssignments[assignment.id] = DateTime.now();
      }
    }
  }

  bool _wasRecentlyPushed(int assignmentId) {
    final now = DateTime.now();
    _recentPushAssignments.removeWhere(
      (_, at) => now.difference(at) > const Duration(seconds: 12),
    );
    final at = _recentPushAssignments[assignmentId];
    return at != null && now.difference(at) <= const Duration(seconds: 12);
  }

  void _schedulePollingFallback(List<_DriverHomeUpdateEvent> updates) {
    _pendingPollingFallback.addAll(updates);
    if (_pollingFallbackTimer?.isActive ?? false) return;

    _pollingFallbackTimer = Timer(const Duration(milliseconds: 1200), () {
      _pollingFallbackTimer = null;
      if (!mounted) {
        _pendingPollingFallback.clear();
        return;
      }

      final pending = List<_DriverHomeUpdateEvent>.of(_pendingPollingFallback);
      _pendingPollingFallback.clear();

      final fallback = pending
          .where((event) => !_wasRecentlyPushed(event.assignment.id))
          .toList(growable: false);
      if (fallback.isEmpty) return;

      _updateQueue.addAll(fallback);
      unawaited(_showQueuedUpdates());
    });
  }

  String _assignmentFingerprint(DriverAssignment assignment) {
    final settlement = assignment.settlement;
    final invoice = assignment.invoice;
    final items = assignment.items
        .map(
          (item) => [
            item.name,
            item.sku,
            item.variant,
            item.unit,
            item.note,
            item.quantity,
            item.quantityConversionFactor,
            item.packSize,
            item.caseSize,
            item.unitPrice,
            item.lineTotal,
          ].join('~'),
        )
        .join('||');

    return [
      assignment.status,
      assignment.orderStatus,
      assignment.storeName,
      assignment.customerName,
      assignment.customerPhone,
      assignment.address,
      assignment.navigationLatitude,
      assignment.navigationLongitude,
      assignment.currency,
      assignment.grandTotal,
      assignment.paymentMethod,
      assignment.paymentStatus,
      assignment.customerNote,
      assignment.availableStatuses.join(','),
      assignment.assignedAt,
      assignment.completedAt,
      assignment.createdAt,
      settlement?.orderTotal,
      settlement?.balanceApplied,
      settlement?.paidAmount,
      settlement?.remainingAmount,
      settlement?.remainderMethod,
      settlement?.paymentState,
      settlement?.amountToCollectNow,
      settlement?.invoiceOutstandingAmount,
      invoice?.id,
      invoice?.number,
      invoice?.revision,
      invoice?.status,
      invoice?.grandTotal,
      invoice?.paymentMethod,
      invoice?.paymentStatus,
      invoice?.outstandingAmount,
      invoice?.issuedAt,
      items,
    ].join('|');
  }

  Future<void> _showQueuedUpdates() async {
    if (_updatePopupActive || !mounted) return;
    _updatePopupActive = true;

    try {
      while (mounted && _updateQueue.isNotEmpty) {
        if (!mounted) break;

        final popupContext = context;
        if (!popupContext.mounted) break;

        final event = _updateQueue.removeAt(0);
        final assignment = event.assignment;
        final removed = event.kind == _DriverHomeUpdateKind.removed;
        final titleKey = switch (event.kind) {
          _DriverHomeUpdateKind.assigned => 'driver.home.new_order_title',
          _DriverHomeUpdateKind.updated => 'driver.home.order_updated_title',
          _DriverHomeUpdateKind.removed => 'driver.home.order_removed_title',
        };

        final openOrder = await showDialog<bool>(
          context: popupContext,
          builder: (dialogContext) => AlertDialog(
            key: const Key('driver-home-update-popup'),
            icon: Icon(
              removed
                  ? Icons.assignment_return_outlined
                  : Icons.notifications_active_rounded,
              color: removed ? FoodexBrand.red : FoodexBrand.actionGreen,
              size: 34,
            ),
            title: Text(dialogContext.tr(titleKey)),
            content: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(
                  '${dialogContext.tr('driver.notifications.order_identity')} ${assignment.reference}',
                  key: const Key('driver-home-update-reference'),
                  maxLines: 1,
                  softWrap: false,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(fontWeight: FontWeight.w900),
                ),
                if (!removed) ...[
                  const SizedBox(height: 8),
                  Text(
                    '${dialogContext.tr('driver.home.current_status')}: '
                    '${dialogContext.tr('driver.status.${assignment.status}')}',
                  ),
                ],
                if (assignment.customerName.trim().isNotEmpty) ...[
                  const SizedBox(height: 6),
                  Text(
                    '${dialogContext.tr('driver.detail.customer')}: '
                    '${assignment.customerName.trim()}',
                  ),
                ],
                if (!removed && assignment.grandTotal > 0) ...[
                  const SizedBox(height: 6),
                  Text(
                    '${dialogContext.tr('driver.detail.total')}: '
                    '${assignment.grandTotal.toStringAsFixed(2)} '
                    '${assignment.currency}',
                    style: const TextStyle(fontWeight: FontWeight.w800),
                  ),
                ],
              ],
            ),
            actions: [
              TextButton(
                key: const Key('driver-home-update-dismiss'),
                onPressed: () => Navigator.of(dialogContext).pop(false),
                child: Text(dialogContext.tr('driver.dismiss')),
              ),
              if (!removed)
                FilledButton.icon(
                  key: const Key('driver-home-update-open'),
                  onPressed: () => Navigator.of(dialogContext).pop(true),
                  icon: const Icon(Icons.open_in_new_rounded),
                  label: Text(dialogContext.tr('driver.notifications.open')),
                ),
            ],
          ),
        );

        if (openOrder == true) {
          if (!mounted) return;

          await Navigator.of(context).pushNamed(
            widget.deliveriesRoute,
            arguments: assignment.id,
          );
          if (!mounted) return;

          await _loadSummary(showLoading: false);
        }
      }
    } finally {
      _updatePopupActive = false;
    }
  }

  int _count(String status) =>
      _assignments.where((assignment) => assignment.status == status).length;

  Future<void> _openStatus(String status) async {
    await Navigator.of(context)
        .pushNamed(widget.deliveriesRoute, arguments: status);
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
      walletRoute: widget.walletRoute,
      appBar: AppBar(
        titleSpacing: 12,
        toolbarHeight: 74,
        backgroundColor: Colors.transparent,
        surfaceTintColor: Colors.transparent,
        flexibleSpace: const DecoratedBox(
          decoration: BoxDecoration(
            gradient: LinearGradient(
              begin: Alignment.topEnd,
              end: Alignment.bottomStart,
              colors: [
                Color(0xFFF9FFFC),
                Color(0xFFDEF7EA),
                Color(0xFFF0FCF5),
              ],
            ),
          ),
        ),
        title: Row(
          children: [
            Container(
              width: 44,
              height: 44,
              decoration: BoxDecoration(
                color: FoodexBrand.greenSoft,
                borderRadius: BorderRadius.circular(15),
                border: Border.all(color: const Color(0xFFCBEEDB)),
              ),
              child: const Icon(
                Icons.local_shipping_rounded,
                color: FoodexBrand.greenDark,
                size: 24,
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
          _DriverHeaderAction(
            actionKey: const Key('driver-home-refresh'),
            onPressed: _loading ? null : _loadSummary,
            tooltip: context.tr('driver.refresh'),
            icon: Icons.refresh_rounded,
          ),
          if (widget.walletRoute != null)
            _DriverHeaderAction(
              actionKey: const Key('driver-open-wallet'),
              onPressed: () =>
                  Navigator.of(context).pushNamed(widget.walletRoute!),
              tooltip: context.tr('driver.home.open_wallet'),
              icon: Icons.account_balance_wallet_outlined,
            ),
          _DriverHeaderAction(
            actionKey: const Key('driver-open-notifications'),
            onPressed: () =>
                Navigator.of(context).pushNamed(widget.notificationsRoute),
            tooltip: context.tr('driver.notifications.title'),
            icon: Icons.notifications_none_rounded,
          ),
          if (widget.onLogout != null)
            _DriverHeaderAction(
              actionKey: const Key('driver-logout'),
              onPressed: widget.onLogout,
              tooltip: context.tr('driver.logout'),
              icon: Icons.logout_rounded,
            ),
        ],
      ),
      body: DecoratedBox(
        decoration: const BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
            colors: [
              Color(0xFFF0FAF5),
              Color(0xFFF7FBF8),
              Color(0xFFF1F9F5),
            ],
          ),
        ),
        child: SafeArea(
        child: LayoutBuilder(
          builder: (context, constraints) {
            final compact = constraints.maxHeight < 610;
            final ultraCompact = constraints.maxHeight < 510;
            final gap = ultraCompact
                ? 4.0
                : compact
                    ? 6.0
                    : 8.0;
            final horizontalPadding = ultraCompact ? 8.0 : 12.0;
            const columns = 2;
            final chartHeight = ultraCompact
                ? 118.0
                : compact
                    ? 158.0
                    : 212.0;
            final pendingHeight = ultraCompact
                ? 44.0
                : compact
                    ? 49.0
                    : 58.0;
            final statusCardHeight = ultraCompact
                ? 58.0
                : compact
                    ? 66.0
                    : 78.0;
            final unsettledCardHeight = ultraCompact
                ? 66.0
                : compact
                    ? 73.0
                    : 84.0;
            final showUnsettledCollections = widget.walletRoute != null &&
                widget.repository is DriverWalletRepository;
            final statusRows = (_statuses.length / columns).ceil();
            final statusGridHeight =
                (statusRows * statusCardHeight) + ((statusRows - 1) * gap);

            return SingleChildScrollView(
              key: const Key('driver-home-scroll'),
              padding: EdgeInsets.fromLTRB(
                horizontalPadding,
                gap + 6,
                horizontalPadding,
                gap + 14,
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  if (showUnsettledCollections) ...[
                    SizedBox(
                      height: unsettledCardHeight,
                      child: _DriverUnsettledCollectionsCard(
                        loading: _walletLoading && _walletAccounts.isEmpty,
                        failed: _walletLoadFailed && _walletAccounts.isEmpty,
                        value: _unsettledCollectionsValue(),
                        onTap: widget.walletRoute == null
                            ? null
                            : () => Navigator.of(context)
                                .pushNamed(widget.walletRoute!),
                      ),
                    ),
                    SizedBox(height: gap),
                  ],
                  _DriverDashboardSectionHeading(
                    title: context.tr('driver.home.status_summary'),
                  ),
                  SizedBox(height: gap),
                  SizedBox(
                    height: chartHeight,
                    child: _DriverWorkloadChart(
                      compact: compact,
                      ultraCompact: ultraCompact,
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
                  ),
                  SizedBox(height: gap),
                  SizedBox(
                    height: pendingHeight,
                    child: _DriverPendingCard(
                      key: const Key('driver-home-pending-orders'),
                      compact: compact,
                      ultraCompact: ultraCompact,
                      label: context.tr('driver.home.pending_orders'),
                      value: _loading ? null : _count('assigned'),
                      onTap: _loading ? null : () => _openStatus('assigned'),
                    ),
                  ),
                  SizedBox(height: gap),
                  SizedBox(
                    height: statusGridHeight,
                    child: _loadFailed
                        ? Card(
                            margin: EdgeInsets.zero,
                            child: Center(
                              child: Padding(
                                padding: EdgeInsets.all(compact ? 8 : 12),
                                child: Row(
                                  mainAxisSize: MainAxisSize.min,
                                  children: [
                                    const Icon(Icons.cloud_off_rounded),
                                    SizedBox(width: ultraCompact ? 4 : 6),
                                    Flexible(
                                      child: Text(
                                        context.tr('driver.error'),
                                        maxLines: 2,
                                        overflow: TextOverflow.ellipsis,
                                      ),
                                    ),
                                    const SizedBox(width: 8),
                                    TextButton(
                                      onPressed: _loadSummary,
                                      child: Text(context.tr('driver.retry')),
                                    ),
                                  ],
                                ),
                              ),
                            ),
                          )
                        : GridView.count(
                            key: const Key('driver-home-status-grid'),
                            padding: EdgeInsets.zero,
                            physics: const NeverScrollableScrollPhysics(),
                            crossAxisCount: columns,
                            crossAxisSpacing: gap,
                            mainAxisSpacing: gap,
                            mainAxisExtent: statusCardHeight,
                            children: [
                              for (final status in _statuses)
                                _DriverStatusCard(
                                  key: Key(
                                    'driver-home-status-${status.$1}',
                                  ),
                                  compact: compact,
                                  ultraCompact: ultraCompact,
                                  icon: status.$2,
                                  status: status.$1,
                                  label:
                                      context.tr('driver.status.${status.$1}'),
                                  value: _loading ? null : _count(status.$1),
                                  onTap: _loading
                                      ? null
                                      : () => _openStatus(status.$1),
                                ),
                            ],
                          ),
                  ),
                  SizedBox(height: gap),
                ],
              ),
            );
          },
        ),
        ),
      ),
    );
  }
}

/// Shared presentation tokens for the Driver Home only; financial and routing
/// state continue to come from the existing authenticated repositories.
abstract final class _DriverDashboardStyle {
  static const mint = Color(0xFFE2F7EC);
  static const mintBorder = Color(0xFFCBEAD8);
  static const ink = FoodexBrand.ink;
  static const softShadow = Color(0x120F5132);

  static RoundedRectangleBorder panelShape({
    Color border = mintBorder,
  }) =>
      RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(22),
        side: BorderSide(color: border),
      );

  static Color statusColor(String status) => switch (status) {
        'accepted' => const Color(0xFF147A40),
        'out_for_delivery' => const Color(0xFF347FDE),
        'failed' => const Color(0xFFEB7916),
        'delivered' => const Color(0xFFDC4044),
        _ => FoodexBrand.muted,
      };

  static Color statusBackground(String status) => switch (status) {
        'accepted' => const Color(0xFFF1FBF5),
        'out_for_delivery' => const Color(0xFFF2F8FF),
        'failed' => const Color(0xFFFFF8EE),
        'delivered' => const Color(0xFFFFF5F5),
        _ => FoodexBrand.surface,
      };

  static Color statusBorder(String status) => switch (status) {
        'accepted' => const Color(0xFFCEEEDB),
        'out_for_delivery' => const Color(0xFFD1E4FF),
        'failed' => const Color(0xFFFFDEAC),
        'delivered' => const Color(0xFFFFD2D3),
        _ => FoodexBrand.border,
      };
}

class _DriverHeaderAction extends StatelessWidget {
  const _DriverHeaderAction({
    required this.actionKey,
    required this.onPressed,
    required this.tooltip,
    required this.icon,
  });

  final Key actionKey;
  final VoidCallback? onPressed;
  final String tooltip;
  final IconData icon;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsetsDirectional.only(end: 3),
        child: IconButton(
          key: actionKey,
          onPressed: onPressed,
          tooltip: tooltip,
          icon: Icon(icon),
          iconSize: 22,
          color: _DriverDashboardStyle.ink,
          padding: EdgeInsets.zero,
          constraints: const BoxConstraints(minWidth: 42, minHeight: 44),
          style: IconButton.styleFrom(
            backgroundColor: const Color(0xDFFFFFFF),
            disabledBackgroundColor: const Color(0x88FFFFFF),
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(14),
            ),
          ),
        ),
      );
}

class _DriverDashboardSectionHeading extends StatelessWidget {
  const _DriverDashboardSectionHeading({required this.title});

  final String title;

  @override
  Widget build(BuildContext context) => Row(
        children: [
          Container(
            height: 23,
            width: 4,
            decoration: BoxDecoration(
              color: FoodexBrand.actionGreen,
              borderRadius: BorderRadius.circular(8),
            ),
          ),
          const SizedBox(width: 9),
          Expanded(
            child: Text(
              title,
              key: const Key('driver-home-status-summary-title'),
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: Theme.of(context).textTheme.titleLarge?.copyWith(
                    fontWeight: FontWeight.w900,
                    color: FoodexBrand.ink,
                    fontSize: 19,
                  ),
            ),
          ),
        ],
      );
}

class _DriverUnsettledCollectionsCard extends StatelessWidget {
  const _DriverUnsettledCollectionsCard({
    required this.loading,
    required this.failed,
    required this.value,
    required this.onTap,
  });

  final bool loading;
  final bool failed;
  final String value;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) => Card(
        key: const Key('driver-home-unsettled-collections'),
        margin: EdgeInsets.zero,
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(16),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 7),
            child: Row(
              children: [
                const Icon(
                  Icons.account_balance_wallet_rounded,
                  color: FoodexBrand.actionGreen,
                  size: 23,
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        context.tr('driver.home.unsettled_collections'),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(fontWeight: FontWeight.w900),
                      ),
                      Text(
                        context.tr('driver.home.unsettled_collections_help'),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: Theme.of(context).textTheme.bodySmall?.copyWith(
                              color: FoodexBrand.muted,
                              fontWeight: FontWeight.w600,
                            ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(width: 10),
                if (loading)
                  const SizedBox.square(
                    dimension: 20,
                    child: CircularProgressIndicator(strokeWidth: 2.1),
                  )
                else
                  Text(
                    failed ? '—' : value,
                    key: const Key('driver-home-unsettled-collections-value'),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(
                      color: FoodexBrand.greenDark,
                      fontWeight: FontWeight.w900,
                    ),
                  ),
              ],
            ),
          ),
        ),
      );
}

class _DriverWorkloadSlice {
  const _DriverWorkloadSlice({required this.label, required this.value});

  final String label;
  final int value;
}

class _DriverWorkloadChart extends StatelessWidget {
  const _DriverWorkloadChart({
    required this.compact,
    required this.ultraCompact,
    required this.loading,
    required this.failed,
    required this.stale,
    required this.statuses,
  });

  final bool compact;
  final bool ultraCompact;
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
      final chartSize = ultraCompact
          ? 50.0
          : compact
              ? 60.0
              : 70.0;
      final chart = FoodexDonutChart(
        values: [for (final item in statuses) item.value.toDouble()],
        semanticLabel: semanticLabel,
        size: chartSize,
        strokeWidth: ultraCompact
            ? 8
            : compact
                ? 10
                : 12,
      );
      final legend = FoodexChartLegend(labels: labels);

      body = Row(
        crossAxisAlignment: CrossAxisAlignment.center,
        children: [
          chart,
          SizedBox(
              width: ultraCompact
                  ? 8
                  : compact
                      ? 10
                      : 14),
          Expanded(child: legend),
        ],
      );
    }

    return Card(
      key: const Key('driver-home-workload-chart'),
      margin: EdgeInsets.zero,
      child: Padding(
        padding: EdgeInsets.all(ultraCompact
            ? 7
            : compact
                ? 9
                : 12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                Icon(
                  Icons.donut_large_rounded,
                  size: ultraCompact
                      ? 16
                      : compact
                          ? 18
                          : 20,
                  color: FoodexChartPalette.primaryDark,
                ),
                SizedBox(width: ultraCompact ? 5 : 8),
                Expanded(
                  child: Text(
                    context.tr('driver.home.analytics.title'),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: Theme.of(context).textTheme.titleSmall?.copyWith(
                          fontSize: ultraCompact
                              ? 11
                              : compact
                                  ? 12
                                  : null,
                          fontWeight: FontWeight.w900,
                        ),
                  ),
                ),
              ],
            ),
            if (!compact) ...[
              const SizedBox(height: 4),
              Text(
                context.tr('driver.home.analytics.summary'),
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: Theme.of(context).textTheme.bodySmall?.copyWith(
                      color: FoodexBrand.muted,
                      fontWeight: FontWeight.w600,
                    ),
              ),
            ],
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
                  SizedBox(width: ultraCompact ? 3 : 5),
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
            SizedBox(
                height: ultraCompact
                    ? 4
                    : compact
                        ? 6
                        : 10),
            Expanded(child: Center(child: body)),
          ],
        ),
      ),
    );
  }
}

class _DriverPendingCard extends StatelessWidget {
  const _DriverPendingCard({
    super.key,
    required this.compact,
    required this.ultraCompact,
    required this.label,
    required this.value,
    required this.onTap,
  });

  final bool compact;
  final bool ultraCompact;
  final String label;
  final int? value;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) => Material(
        color: FoodexBrand.statusSurface('error'),
        borderRadius: BorderRadius.circular(18),
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(18),
          child: Container(
            padding: EdgeInsets.symmetric(
              horizontal: ultraCompact
                  ? 8
                  : compact
                      ? 10
                      : 14,
              vertical: ultraCompact
                  ? 3
                  : compact
                      ? 4
                      : 5,
            ),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(18),
              border: Border.all(
                color: FoodexBrand.red,
                width: 1.4,
              ),
            ),
            child: Row(
              children: [
                Container(
                  width: ultraCompact
                      ? 24
                      : compact
                          ? 28
                          : 32,
                  height: ultraCompact
                      ? 24
                      : compact
                          ? 28
                          : 32,
                  decoration: BoxDecoration(
                    color: FoodexBrand.red,
                    borderRadius: BorderRadius.circular(compact ? 10 : 14),
                  ),
                  child: Icon(
                    Icons.notifications_active_rounded,
                    color: Colors.white,
                    size: ultraCompact
                        ? 14
                        : compact
                            ? 16
                            : 18,
                  ),
                ),
                SizedBox(
                    width: ultraCompact
                        ? 6
                        : compact
                            ? 8
                            : 10),
                Expanded(
                  child: Text(
                    label,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(
                      color: FoodexBrand.dangerText,
                      fontSize: ultraCompact
                          ? 10
                          : compact
                              ? 11
                              : 13,
                      fontWeight: FontWeight.w900,
                    ),
                  ),
                ),
                const SizedBox(width: 8),
                if (value == null)
                  const SizedBox(
                    width: 22,
                    height: 22,
                    child: CircularProgressIndicator(
                      strokeWidth: 2.2,
                      color: FoodexBrand.red,
                    ),
                  )
                else
                  Container(
                    constraints: BoxConstraints(
                      minWidth: ultraCompact
                          ? 30
                          : compact
                              ? 34
                              : 40,
                    ),
                    padding: EdgeInsets.symmetric(
                      horizontal: ultraCompact ? 7 : 9,
                      vertical: ultraCompact
                          ? 3
                          : compact
                              ? 4
                              : 5,
                    ),
                    decoration: BoxDecoration(
                      color: FoodexBrand.red,
                      borderRadius: BorderRadius.circular(999),
                    ),
                    child: Text(
                      value.toString(),
                      textAlign: TextAlign.center,
                      style: TextStyle(
                        color: Colors.white,
                        fontSize: ultraCompact
                            ? 14
                            : compact
                                ? 16
                                : 19,
                        fontWeight: FontWeight.w900,
                      ),
                    ),
                  ),
              ],
            ),
          ),
        ),
      );
}

class _DriverStatusCard extends StatelessWidget {
  const _DriverStatusCard({
    super.key,
    required this.compact,
    required this.ultraCompact,
    required this.icon,
    required this.label,
    required this.value,
    required this.onTap,
  });

  final bool compact;
  final bool ultraCompact;
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
            padding: EdgeInsets.symmetric(
              horizontal: ultraCompact
                  ? 6
                  : compact
                      ? 8
                      : 10,
              vertical: ultraCompact
                  ? 5
                  : compact
                      ? 7
                      : 9,
            ),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(18),
              border: Border.all(color: FoodexBrand.border),
            ),
            child: Row(
              children: [
                Container(
                  width: ultraCompact
                      ? 26
                      : compact
                          ? 30
                          : 34,
                  height: ultraCompact
                      ? 26
                      : compact
                          ? 30
                          : 34,
                  decoration: BoxDecoration(
                    color: FoodexBrand.greenSoft,
                    borderRadius: BorderRadius.circular(compact ? 9 : 12),
                  ),
                  child: Icon(
                    icon,
                    color: FoodexBrand.greenDark,
                    size: ultraCompact
                        ? 14
                        : compact
                            ? 16
                            : 18,
                  ),
                ),
                SizedBox(
                    width: ultraCompact
                        ? 5
                        : compact
                            ? 6
                            : 8),
                Expanded(
                  child: Text(
                    label,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(
                      fontSize: ultraCompact
                          ? 9
                          : compact
                              ? 10
                              : 11,
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
                    style: TextStyle(
                      fontSize: ultraCompact
                          ? 14
                          : compact
                              ? 16
                              : 19,
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
