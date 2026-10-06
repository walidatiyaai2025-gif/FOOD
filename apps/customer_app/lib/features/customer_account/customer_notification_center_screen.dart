// ignore_for_file: prefer_interpolation_to_compose_strings

import 'package:flutter/material.dart';

import '../../core/api/b2c_account_api.dart';
import '../../core/localization/app_translations.dart';
import '../../shared/customer_ui_v3/customer_ui_v3.dart';
import 'customer_account_data.dart';
import 'customer_account_v3_widgets.dart';

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
    extends State<CustomerNotificationCenterScreen>
    with WidgetsBindingObserver {
  Future<Object?>? _future;
  String _locale = 'ar';

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed && mounted && _future != null) {
      _reload();
    }
  }

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
      body: CustomerCurvedHeaderSurface(
        header: CustomerAccountHeader(
          title: context.tr('customer.notifications.title'),
          subtitle: context.tr('customer.notifications.subtitle'),
        ),
        child: future == null
            ? const _NotificationScrollableState(
                child: CustomerAccountListSkeleton(),
              )
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
                        child: CustomerAccountListSkeleton(),
                      );
                    }

                    if (snapshot.hasError) {
                      return _NotificationScrollableState(
                        key: const ValueKey('customer-notifications-error'),
                        child: CustomerStateView(
                          kind: CustomerStateKind.error,
                          title: context.tr(
                            customerAccountErrorKey(snapshot.error),
                          ),
                          actionLabel: context.tr('customer.action.retry'),
                          onAction: _reload,
                        ),
                      );
                    }

                    final rows = customerAccountRows(snapshot.data);
                    if (rows.isEmpty) {
                      return _NotificationScrollableState(
                        key: const ValueKey('customer-notifications-empty'),
                        child: CustomerStateView(
                          kind: CustomerStateKind.empty,
                          title: context.tr('customer.notifications.empty'),
                          message:
                              context.tr('customer.notifications.subtitle'),
                          icon: Icons.notifications_none_rounded,
                        ),
                      );
                    }

                    return ListView.separated(
                      key: const ValueKey('customer-notifications-list'),
                      physics: const AlwaysScrollableScrollPhysics(),
                      padding: const EdgeInsetsDirectional.fromSTEB(
                        CustomerUiSpacing.page,
                        CustomerUiSpacing.lg,
                        CustomerUiSpacing.page,
                        CustomerUiSpacing.xxl,
                      ),
                      itemCount: rows.length,
                      separatorBuilder: (_, __) =>
                          const SizedBox(height: CustomerUiSpacing.sm),
                      itemBuilder: (context, index) {
                        final item = rows[index];
                        final id = (item['id'] as num?)?.toInt();
                        final unread = item['read_at'] == null;
                        final title = item['title']?.toString() ?? '';
                        final body = item['body']?.toString() ?? '';

                        return CustomerAccountSurfaceCard(
                          key: ValueKey(
                            'customer-notification-' +
                                (id?.toString() ?? index.toString()),
                          ),
                          onTap: id == null ? null : () => _open(item),
                          child: Row(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              DecoratedBox(
                                decoration: BoxDecoration(
                                  color: unread
                                      ? CustomerUiColors.limeSoft
                                      : CustomerUiColors.mint,
                                  shape: BoxShape.circle,
                                ),
                                child: SizedBox.square(
                                  dimension: 48,
                                  child: Icon(
                                    unread
                                        ? Icons.notifications_active_outlined
                                        : Icons.notifications_none_rounded,
                                    color: CustomerUiColors.deepGreenStrong,
                                  ),
                                ),
                              ),
                              const SizedBox(width: CustomerUiSpacing.sm),
                              Expanded(
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Row(
                                      crossAxisAlignment:
                                          CrossAxisAlignment.start,
                                      children: [
                                        Expanded(
                                          child: Text(
                                            title,
                                            style: Theme.of(context)
                                                .textTheme
                                                .titleMedium
                                                ?.copyWith(
                                                  fontWeight: unread
                                                      ? FontWeight.w800
                                                      : FontWeight.w600,
                                                ),
                                          ),
                                        ),
                                        if (unread) ...[
                                          const SizedBox(
                                            width: CustomerUiSpacing.xs,
                                          ),
                                          const DecoratedBox(
                                            decoration: BoxDecoration(
                                              color: CustomerUiColors.lime,
                                              shape: BoxShape.circle,
                                            ),
                                            child: SizedBox.square(
                                              dimension: 10,
                                            ),
                                          ),
                                        ],
                                      ],
                                    ),
                                    if (body.isNotEmpty) ...[
                                      const SizedBox(
                                        height: CustomerUiSpacing.xs,
                                      ),
                                      Text(
                                        body,
                                        style: Theme.of(context)
                                            .textTheme
                                            .bodyMedium
                                            ?.copyWith(
                                              color: CustomerUiColors.muted,
                                            ),
                                      ),
                                    ],
                                  ],
                                ),
                              ),
                              if (widget.onOpenOrder != null) ...[
                                const SizedBox(width: CustomerUiSpacing.xs),
                                const Icon(
                                  Icons.chevron_right_rounded,
                                  color: CustomerUiColors.muted,
                                ),
                              ],
                            ],
                          ),
                        );
                      },
                    );
                  },
                ),
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
      padding: const EdgeInsetsDirectional.fromSTEB(
        CustomerUiSpacing.page,
        CustomerUiSpacing.lg,
        CustomerUiSpacing.page,
        CustomerUiSpacing.xxl,
      ),
      children: [child],
    );
  }
}
