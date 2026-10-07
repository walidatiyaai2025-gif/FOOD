import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/localization/driver_translations.dart';
import '../../core/navigation/driver_shell.dart';
import 'notification_feed.dart';

class DriverNotificationPage extends StatefulWidget {
  const DriverNotificationPage({
    super.key,
    required this.repository,
    required this.onOpenAssignment,
    this.onSessionExpired,
    this.homeRoute,
    this.deliveriesRoute,
    this.notificationsRoute,
    this.currentAssignmentId,
  });

  final DriverNotificationRepository repository;
  final ValueChanged<int> onOpenAssignment;
  final VoidCallback? onSessionExpired;
  final String? homeRoute;
  final String? deliveriesRoute;
  final String? notificationsRoute;
  final int? currentAssignmentId;

  @override
  State<DriverNotificationPage> createState() => _DriverNotificationPageState();
}

class _DriverNotificationPageState extends State<DriverNotificationPage>
    with WidgetsBindingObserver {
  static const Duration _refreshInterval = Duration(seconds: 15);

  bool _loading = true;
  bool _failed = false;
  bool _stale = false;
  bool _loadInFlight = false;
  DateTime? _lastSuccessfulAt;
  Timer? _refreshTimer;
  List<DriverNotification> _items = const [];

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
    _startLiveRefresh();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      unawaited(_load(silent: _items.isNotEmpty));
      _startLiveRefresh();
      return;
    }

    _stopLiveRefresh();
  }

  void _startLiveRefresh() {
    _refreshTimer?.cancel();
    _refreshTimer = Timer.periodic(_refreshInterval, (_) {
      if (!mounted || _loadInFlight || _loading) return;
      unawaited(_load(silent: true));
    });
  }

  void _stopLiveRefresh() {
    _refreshTimer?.cancel();
    _refreshTimer = null;
  }

  @override
  void dispose() {
    _stopLiveRefresh();
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  Future<void> _load({bool silent = false}) async {
    if (_loadInFlight) return;
    _loadInFlight = true;

    if (mounted && !silent) {
      setState(() {
        _loading = true;
        _failed = false;
      });
    }

    try {
      final locale = Localizations.localeOf(context).languageCode;
      final items = await widget.repository.list(locale: locale);
      if (!mounted) return;
      setState(() {
        _items = items;
        _loading = false;
        _failed = false;
        _stale = false;
        _lastSuccessfulAt = DateTime.now();
      });
    } on DriverNotificationException catch (error) {
      if (error.code == 'session_expired') {
        widget.onSessionExpired?.call();
      }
      if (!mounted) return;
      setState(() {
        _loading = false;
        if (silent && _items.isNotEmpty) {
          _stale = true;
          _failed = false;
        } else {
          _failed = true;
        }
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        if (silent && _items.isNotEmpty) {
          _stale = true;
          _failed = false;
        } else {
          _failed = true;
        }
      });
    } finally {
      _loadInFlight = false;
    }
  }

  Future<void> _open(DriverNotification notification) async {
    try {
      if (notification.readAt == null) {
        await widget.repository.markRead(notification.id);
      }

      if (!mounted) return;

      final assignmentId = notification.assignmentId;
      if (!notification.accessRevoked &&
          assignmentId != null &&
          assignmentId > 0) {
        if (widget.currentAssignmentId == assignmentId) {
          await _load();
          return;
        }
        widget.onOpenAssignment(assignmentId);
        return;
      }

      await _load();
    } on DriverNotificationException catch (error) {
      if (error.code == 'session_expired') {
        widget.onSessionExpired?.call();
      }
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
              context.tr('driver.notifications.action_error'),
            ),
          ),
        );
      }
    } catch (_) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
              context.tr('driver.notifications.action_error'),
            ),
          ),
        );
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return DriverShellScaffold(
      destination: DriverShellDestination.notifications,
      homeRoute: widget.homeRoute,
      deliveriesRoute: widget.deliveriesRoute,
      notificationsRoute: widget.notificationsRoute,
      appBar: AppBar(
        title: Text(context.tr('driver.notifications.title')),
        actions: [
          IconButton(
            key: const Key('driver-notifications-refresh'),
            onPressed: _loading ? null : _load,
            tooltip: context.tr('driver.notifications.refresh'),
            icon: const Icon(Icons.refresh_rounded),
          ),
        ],
      ),
      body: SafeArea(
        child: Column(
          children: [
            if (_stale && _items.isNotEmpty)
              _NotificationStaleBanner(
                lastSuccessfulAt: _lastSuccessfulAt,
              ),
            Expanded(
              child: _loading
            ? const Center(
                child: CircularProgressIndicator(
                  key: Key('driver-notifications-loading'),
                ),
              )
            : _failed
                ? Center(
                    key: const Key('driver-notifications-error'),
                    child: FilledButton.icon(
                      onPressed: _load,
                      icon: const Icon(Icons.refresh_rounded),
                      label: Text(context.tr('driver.retry')),
                    ),
                  )
                : _items.isEmpty
                    ? Center(
                        child: Text(
                          context.tr('driver.notifications.empty'),
                          key: const Key('driver-notifications-empty'),
                        ),
                      )
                    : RefreshIndicator(
                        onRefresh: _load,
                        child: ListView.builder(
                          key: const Key('driver-notifications-list'),
                          padding: const EdgeInsets.fromLTRB(12, 8, 12, 20),
                          itemCount: _items.length,
                          itemBuilder: (context, index) {
                            final notification = _items[index];
                            final unread = notification.readAt == null;
                            final actionable = !notification.accessRevoked &&
                                notification.assignmentId != null &&
                                notification.assignmentId! > 0;

                            return Card(
                              key: Key(
                                'driver-notification-${notification.id}',
                              ),
                              margin: const EdgeInsets.only(bottom: 10),
                              child: ListTile(
                                contentPadding: const EdgeInsets.symmetric(
                                  horizontal: 12,
                                  vertical: 4,
                                ),
                                leading: Icon(
                                  unread
                                      ? Icons.notifications_active_rounded
                                      : Icons.notifications_none_rounded,
                                ),
                                title: Text(
                                  notification.title,
                                  style: TextStyle(
                                    fontWeight: unread
                                        ? FontWeight.w800
                                        : FontWeight.w500,
                                  ),
                                ),
                                subtitle: Padding(
                                  padding: const EdgeInsets.only(top: 4),
                                  child: Text(notification.body),
                                ),
                                trailing: actionable
                                    ? const Icon(Icons.chevron_right_rounded)
                                    : notification.accessRevoked
                                        ? const Icon(Icons.lock_outline_rounded)
                                        : null,
                                onTap: () => _open(notification),
                              ),
                            );
                          },
                        ),
                      ),
            ),
          ],
        ),
      ),
    );
  }
}

class _NotificationStaleBanner extends StatelessWidget {
  const _NotificationStaleBanner({required this.lastSuccessfulAt});

  final DateTime? lastSuccessfulAt;

  @override
  Widget build(BuildContext context) {
    final last = lastSuccessfulAt;
    final time = last == null
        ? null
        : '${last.hour.toString().padLeft(2, '0')}:${last.minute.toString().padLeft(2, '0')}';

    return Container(
      key: const Key('driver-notifications-stale'),
      margin: const EdgeInsets.fromLTRB(12, 6, 12, 0),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 9),
      child: Text(
        [
          context.tr('driver.data.stale'),
          if (time != null)
            '${context.tr('driver.data.last_confirmed_update')}: $time',
        ].join(' · '),
        maxLines: 2,
        overflow: TextOverflow.ellipsis,
      ),
    );
  }
}
