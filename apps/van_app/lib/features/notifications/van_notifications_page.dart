import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/auth/van_session.dart';
import '../../core/theme/foodex_van_theme.dart';
import 'van_notification_contract.dart';

class VanNotificationsPage extends StatefulWidget {
  const VanNotificationsPage({
    super.key,
    required this.repository,
    required this.onSessionExpired,
  });

  final VanNotificationRepository repository;
  final Future<void> Function() onSessionExpired;

  @override
  State<VanNotificationsPage> createState() => _VanNotificationsPageState();
}

class _VanNotificationsPageState extends State<VanNotificationsPage>
    with WidgetsBindingObserver {
  bool _loading = true;
  bool _refreshing = false;
  bool _stale = false;
  bool _unreadOnly = false;
  Object? _error;
  List<VanNotificationRecord> _items = const [];

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
        if (background && _items.isNotEmpty) {
          _refreshing = true;
        } else {
          _loading = true;
        }
        _error = null;
      });
    }

    try {
      final items = await widget.repository.notifications(
        locale: _arabic ? 'ar' : 'en',
      );
      if (!mounted) return;
      setState(() {
        _items = items;
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
        if (background && _items.isNotEmpty) {
          _stale = true;
        } else {
          _error = error;
        }
      });
    }
  }

  Future<void> _markRead(VanNotificationRecord item) async {
    if (item.read) return;
    try {
      await widget.repository.markRead(item.id);
      if (!mounted) return;
      setState(() {
        _items = [
          for (final current in _items)
            if (current.id == item.id)
              VanNotificationRecord(
                id: current.id,
                type: current.type,
                title: current.title,
                body: current.body,
                publishedAt: current.publishedAt,
                read: true,
              )
            else
              current,
        ];
      });
    } on VanSessionExpiredException {
      await widget.onSessionExpired();
    } catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(context)
        ..hideCurrentSnackBar()
        ..showSnackBar(
          SnackBar(
            content: Text(
              _text(
                'Unable to update notification state.',
                'تعذر تحديث حالة الإشعار.',
              ),
            ),
          ),
        );
    }
  }

  IconData _icon(String type) {
    final normalized = type.toLowerCase();
    if (normalized.contains('route')) return Icons.route_outlined;
    if (normalized.contains('offer')) return Icons.local_offer_outlined;
    if (normalized.contains('collection') || normalized.contains('payment')) {
      return Icons.payments_outlined;
    }
    if (normalized.contains('order')) return Icons.receipt_long_outlined;
    return Icons.notifications_none_outlined;
  }

  @override
  Widget build(BuildContext context) {
    if (_loading && _items.isEmpty) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_error != null && _items.isEmpty) {
      return _StateCard(
        title: _text('Unable to load notifications', 'تعذر تحميل الإشعارات'),
        actionLabel: _text('Retry', 'إعادة المحاولة'),
        onAction: _load,
      );
    }

    final visible =
        _unreadOnly ? _items.where((item) => !item.read).toList() : _items;

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        key: const ValueKey('van-notifications-page'),
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
                        'Showing the last confirmed notification feed.',
                        'يتم عرض آخر قائمة إشعارات مؤكدة.',
                      )
                    : _text(
                        'Refreshing notifications…',
                        'جارٍ تحديث الإشعارات…',
                      ),
              ),
            ),
          Row(
            children: [
              Expanded(
                child: FilterChip(
                  selected: !_unreadOnly,
                  onSelected: (_) => setState(() => _unreadOnly = false),
                  label: Text(_text('All', 'الكل')),
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: FilterChip(
                  selected: _unreadOnly,
                  onSelected: (_) => setState(() => _unreadOnly = true),
                  label: Text(_text('Unread', 'غير مقروء')),
                ),
              ),
            ],
          ),
          const SizedBox(height: 10),
          if (visible.isEmpty)
            _StateCard(
              title: _unreadOnly
                  ? _text('No unread notifications', 'لا توجد إشعارات غير مقروءة')
                  : _text('No notifications', 'لا توجد إشعارات'),
              actionLabel: _text('Refresh', 'تحديث'),
              onAction: _load,
            )
          else
            for (final item in visible)
              Card(
                key: ValueKey('van-notification-${item.id}'),
                elevation: 0,
                margin: const EdgeInsets.only(bottom: 8),
                child: InkWell(
                  onTap: () => _markRead(item),
                  borderRadius: BorderRadius.circular(16),
                  child: Padding(
                    padding: const EdgeInsets.all(14),
                    child: Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        CircleAvatar(
                          backgroundColor: FoodexVanTokens.mint,
                          child: Icon(
                            _icon(item.type),
                            color: FoodexVanTokens.green,
                          ),
                        ),
                        const SizedBox(width: 10),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Row(
                                children: [
                                  Expanded(
                                    child: Text(
                                      item.title,
                                      maxLines: 1,
                                      overflow: TextOverflow.ellipsis,
                                      style: const TextStyle(
                                        fontWeight: FontWeight.w900,
                                      ),
                                    ),
                                  ),
                                  if (!item.read)
                                    Container(
                                      width: 8,
                                      height: 8,
                                      decoration: const BoxDecoration(
                                        color: FoodexVanTokens.green,
                                        shape: BoxShape.circle,
                                      ),
                                    ),
                                ],
                              ),
                              const SizedBox(height: 4),
                              Text(
                                item.body,
                                maxLines: 3,
                                overflow: TextOverflow.ellipsis,
                              ),
                              if (item.publishedAt != null) ...[
                                const SizedBox(height: 6),
                                Text(
                                  item.publishedAt!,
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                  style: Theme.of(context)
                                      .textTheme
                                      .bodySmall
                                      ?.copyWith(
                                        color: FoodexVanTokens.muted,
                                      ),
                                ),
                              ],
                            ],
                          ),
                        ),
                      ],
                    ),
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
    required this.actionLabel,
    required this.onAction,
  });

  final String title;
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
            Text(title, textAlign: TextAlign.center),
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
