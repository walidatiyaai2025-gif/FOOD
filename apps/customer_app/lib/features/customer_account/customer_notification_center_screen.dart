import 'package:flutter/material.dart';

import '../../core/api/b2c_account_api.dart';
import '../../core/localization/app_translations.dart';
import 'customer_account_data.dart';

class CustomerNotificationCenterScreen extends StatefulWidget {
  const CustomerNotificationCenterScreen({
    required this.api,
    this.onOpenOrder,
    super.key,
  });

  final B2cAccountApi api;
  final ValueChanged<CustomerNotificationTarget>? onOpenOrder;

  @override
  State<CustomerNotificationCenterScreen> createState() =>
      _CustomerNotificationCenterScreenState();
}

class _CustomerNotificationCenterScreenState
    extends State<CustomerNotificationCenterScreen> {
  Future<Object?>? _future;
  String _locale = 'ar';

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    final locale = Localizations.localeOf(context).languageCode;
    if (_future == null || locale != _locale) {
      _locale = locale;
      _future = widget.api.notifications(locale: locale);
    }
  }

  @override
  void didUpdateWidget(covariant CustomerNotificationCenterScreen oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.api != widget.api) _reload();
  }

  void _reload() {
    setState(() {
      _future = widget.api.notifications(locale: _locale);
    });
  }

  Future<void> _open(Map<String, dynamic> notification) async {
    final id = (notification['id'] as num?)?.toInt();
    final unread = notification['read_at'] == null;

    try {
      if (id != null && unread) {
        await widget.api.markNotificationRead(id);
      }

      if (!mounted) return;

      final payload = notification['data'] is Map
          ? Map<String, dynamic>.from(notification['data'] as Map)
          : notification;
      final orderId = int.tryParse((payload['order_id'] ?? '').toString());
      final channel = (payload['channel'] ?? '').toString().toLowerCase();
      final storeId = int.tryParse(
        (payload['store_id'] ?? payload['store'] ?? '').toString(),
      );

      if (orderId != null &&
          orderId > 0 &&
          (channel == 'b2b' || channel == 'b2c') &&
          widget.onOpenOrder != null) {
        widget.onOpenOrder!(
          CustomerNotificationTarget(
            orderId: orderId,
            channel: channel,
            storeId: storeId,
          ),
        );
        return;
      }

      _reload();
    } catch (error) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(context.tr(customerAccountErrorKey(error)))),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final future = _future;
    return Scaffold(
      key: const ValueKey('customer-notification-center-screen'),
      appBar: AppBar(
        title: Text(context.tr('customer.notifications.title')),
      ),
      body: future == null
          ? const Center(child: CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: () async {
                _reload();
                try {
                  await (_future ?? Future<Object?>.value(null));
                } catch (_) {}
              },
              child: FutureBuilder<Object?>(
                future: future,
                builder: (context, snapshot) {
                  if (snapshot.connectionState != ConnectionState.done) {
                    return const _NotificationScrollableState(
                      child: CircularProgressIndicator(),
                    );
                  }
                  if (snapshot.hasError) {
                    return _NotificationScrollableState(
                      key: const ValueKey('customer-notifications-error'),
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Text(
                            context.tr(
                              customerAccountErrorKey(snapshot.error),
                            ),
                          ),
                          const SizedBox(height: 8),
                          FilledButton.tonalIcon(
                            onPressed: _reload,
                            icon: const Icon(Icons.refresh_rounded),
                            label: Text(context.tr('customer.action.retry')),
                          ),
                        ],
                      ),
                    );
                  }

                  final rows = customerAccountRows(snapshot.data);
                  if (rows.isEmpty) {
                    return _NotificationScrollableState(
                      key: const ValueKey('customer-notifications-empty'),
                      child: Text(
                        context.tr('customer.notifications.empty'),
                      ),
                    );
                  }

                  return ListView.separated(
                    key: const ValueKey('customer-notifications-list'),
                    physics: const AlwaysScrollableScrollPhysics(),
                    padding: const EdgeInsets.fromLTRB(16, 16, 16, 28),
                    itemCount: rows.length,
                    separatorBuilder: (_, __) => const SizedBox(height: 8),
                    itemBuilder: (context, index) {
                      final item = rows[index];
                      final id = (item['id'] as num?)?.toInt();
                      final unread = item['read_at'] == null;
                      final title = item['title']?.toString() ?? '';
                      final body = item['body']?.toString() ?? '';

                      return Card(
                        child: ListTile(
                          key: ValueKey(
                            'customer-notification-' +
                                (id?.toString() ?? index.toString()),
                          ),
                          leading: Icon(
                            unread
                                ? Icons.notifications_active_outlined
                                : Icons.notifications_none_rounded,
                          ),
                          title: Text(
                            title,
                            style: unread
                                ? const TextStyle(fontWeight: FontWeight.w700)
                                : null,
                          ),
                          subtitle: body.isEmpty ? null : Text(body),
                          trailing: widget.onOpenOrder == null
                              ? null
                              : const Icon(Icons.chevron_right_rounded),
                          onTap: id == null ? null : () => _open(item),
                        ),
                      );
                    },
                  );
                },
              ),
            ),
    );
  }
}

class _NotificationScrollableState extends StatelessWidget {
  const _NotificationScrollableState({
    required this.child,
    super.key,
  });

  final Widget child;

  @override
  Widget build(BuildContext context) {
    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.all(24),
      children: [
        const SizedBox(height: 80),
        Center(child: child),
      ],
    );
  }
}
