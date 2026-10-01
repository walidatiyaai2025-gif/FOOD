// ignore_for_file: prefer_interpolation_to_compose_strings

import 'package:flutter/material.dart';

import '../../core/api/b2c_account_api.dart';
import '../../core/localization/app_translations.dart';
import 'customer_account_data.dart';

class CustomerAccountScreen extends StatefulWidget {
  const CustomerAccountScreen({
    required this.api,
    required this.favoritesApi,
    required this.retailStoreId,
    this.onOpenAddresses,
    this.onOpenFavorites,
    this.onOpenNotifications,
    this.onOpenOrders,
    super.key,
  });

  final B2cAccountApi api;
  final B2cRetailFavoritesApi favoritesApi;
  final int retailStoreId;
  final VoidCallback? onOpenAddresses;
  final VoidCallback? onOpenFavorites;
  final VoidCallback? onOpenNotifications;
  final VoidCallback? onOpenOrders;

  @override
  State<CustomerAccountScreen> createState() => _CustomerAccountScreenState();
}

class _CustomerAccountScreenState extends State<CustomerAccountScreen> {
  late Future<Object?> _profile;
  late Future<Object?> _addresses;
  late Future<Object?> _favorites;
  Future<Object?>? _notifications;
  String _locale = 'ar';

  @override
  void initState() {
    super.initState();
    _profile = widget.api.profile();
    _addresses = widget.api.addresses();
    _favorites = widget.favoritesApi.favoritesForStore(widget.retailStoreId);
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    final locale = Localizations.localeOf(context).languageCode;
    if (_notifications == null || locale != _locale) {
      _locale = locale;
      _notifications = widget.api.notifications(locale: locale);
    }
  }

  @override
  void didUpdateWidget(covariant CustomerAccountScreen oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.api != widget.api ||
        oldWidget.favoritesApi != widget.favoritesApi ||
        oldWidget.retailStoreId != widget.retailStoreId) {
      _reloadAll();
    }
  }

  void _reloadAll() {
    setState(() {
      _profile = widget.api.profile();
      _addresses = widget.api.addresses();
      _favorites = widget.favoritesApi.favoritesForStore(widget.retailStoreId);
      _notifications = widget.api.notifications(locale: _locale);
    });
  }

  Future<void> _editProfile(Map<String, dynamic> profile) async {
    final name = TextEditingController(text: profile['name']?.toString() ?? '');
    final email =
        TextEditingController(text: profile['email']?.toString() ?? '');
    final accepted = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: Text(context.tr('customer.settings.edit_profile')),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            TextField(
              key: const ValueKey('customer-account-name'),
              controller: name,
              decoration: InputDecoration(
                labelText: context.tr('customer.settings.name'),
              ),
            ),
            const SizedBox(height: 10),
            TextField(
              key: const ValueKey('customer-account-email'),
              controller: email,
              keyboardType: TextInputType.emailAddress,
              decoration: InputDecoration(
                labelText: context.tr('customer.settings.email'),
              ),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(dialogContext).pop(false),
            child: Text(context.tr('customer.action.cancel')),
          ),
          FilledButton(
            key: const ValueKey('customer-account-save-profile'),
            onPressed: () => Navigator.of(dialogContext).pop(true),
            child: Text(context.tr('customer.action.save')),
          ),
        ],
      ),
    );

    if (accepted == true) {
      try {
        await widget.api.updateProfile({
          'name': name.text.trim(),
          'email': email.text.trim(),
          'locale': _locale,
        });
        if (mounted) {
          setState(() => _profile = widget.api.profile());
        }
      } catch (error) {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text(context.tr(customerAccountErrorKey(error)))),
          );
        }
      }
    }

    name.dispose();
    email.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      key: const ValueKey('customer-account-screen'),
      appBar: AppBar(
        title: Text(context.tr('customer.profile.title')),
      ),
      body: RefreshIndicator(
        onRefresh: () async {
          _reloadAll();
          await Future.wait<Object?>([
            _profile.catchError((_) => null),
            _addresses.catchError((_) => null),
            _favorites.catchError((_) => null),
            (_notifications ?? Future<Object?>.value(null))
                .catchError((_) => null),
          ]);
        },
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.fromLTRB(16, 16, 16, 28),
          children: [
            Text(
              context.tr('customer.profile.subtitle'),
              style: Theme.of(context).textTheme.bodyLarge,
            ),
            const SizedBox(height: 14),
            _sectionCard(
              key: const ValueKey('customer-account-profile-section'),
              future: _profile,
              title: context.tr('customer.settings.title'),
              icon: Icons.person_outline_rounded,
              builder: (data) {
                final profile = customerAccountMap(data);
                final name = profile['name']?.toString().trim();
                final email = profile['email']?.toString().trim();
                return Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    if (name != null && name.isNotEmpty)
                      Text(
                        name,
                        style: Theme.of(context).textTheme.titleMedium,
                      ),
                    if (email != null && email.isNotEmpty) Text(email),
                    const SizedBox(height: 10),
                    Align(
                      alignment: AlignmentDirectional.centerStart,
                      child: FilledButton.tonalIcon(
                        key: const ValueKey('customer-account-edit-profile'),
                        onPressed: () => _editProfile(profile),
                        icon: const Icon(Icons.edit_outlined),
                        label:
                            Text(context.tr('customer.settings.edit_profile')),
                      ),
                    ),
                  ],
                );
              },
              onRetry: () => setState(() => _profile = widget.api.profile()),
            ),
            const SizedBox(height: 12),
            _countCard(
              key: const ValueKey('customer-account-addresses-section'),
              future: _addresses,
              title: context.tr('customer.profile.addresses'),
              icon: Icons.location_on_outlined,
              onTap: widget.onOpenAddresses,
              onRetry: () =>
                  setState(() => _addresses = widget.api.addresses()),
            ),
            const SizedBox(height: 12),
            _countCard(
              key: const ValueKey('customer-account-favorites-section'),
              future: _favorites,
              title: context.tr('customer.profile.favorites'),
              icon: Icons.favorite_border_rounded,
              onTap: widget.onOpenFavorites,
              onRetry: () => setState(
                () => _favorites =
                    widget.favoritesApi.favoritesForStore(widget.retailStoreId),
              ),
            ),
            const SizedBox(height: 12),
            _countCard(
              key: const ValueKey('customer-account-notifications-section'),
              future: _notifications ?? Future<Object?>.value(null),
              title: context.tr('customer.notifications.title'),
              icon: Icons.notifications_none_rounded,
              onTap: widget.onOpenNotifications,
              onRetry: () => setState(
                () => _notifications = widget.api.notifications(locale: _locale),
              ),
            ),
            if (widget.onOpenOrders != null) ...[
              const SizedBox(height: 12),
              _navCard(
                title: context.tr('customer.profile.orders'),
                icon: Icons.receipt_long_outlined,
                onTap: widget.onOpenOrders!,
              ),
            ],
          ],
        ),
      ),
    );
  }

  Widget _countCard({
    required Key key,
    required Future<Object?> future,
    required String title,
    required IconData icon,
    required VoidCallback onRetry,
    VoidCallback? onTap,
  }) {
    return _sectionCard(
      key: key,
      future: future,
      title: title,
      icon: icon,
      onRetry: onRetry,
      builder: (data) {
        final count = customerAccountRows(data).length;
        final body = Row(
          children: [
            Text(
              count.toString(),
              key: ValueKey(key.toString() + '-count'),
              style: Theme.of(context).textTheme.headlineSmall,
            ),
            const Spacer(),
            if (onTap != null) const Icon(Icons.chevron_right_rounded),
          ],
        );
        return onTap == null
            ? body
            : InkWell(
                onTap: onTap,
                borderRadius: BorderRadius.circular(14),
                child: Padding(
                  padding: const EdgeInsets.symmetric(vertical: 6),
                  child: body,
                ),
              );
      },
    );
  }

  Widget _navCard({
    required String title,
    required IconData icon,
    required VoidCallback onTap,
  }) {
    return Card(
      child: ListTile(
        leading: Icon(icon),
        title: Text(title),
        trailing: const Icon(Icons.chevron_right_rounded),
        onTap: onTap,
      ),
    );
  }

  Widget _sectionCard({
    required Key key,
    required Future<Object?> future,
    required String title,
    required IconData icon,
    required Widget Function(Object? data) builder,
    required VoidCallback onRetry,
  }) {
    return Card(
      key: key,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: FutureBuilder<Object?>(
          future: future,
          builder: (context, snapshot) {
            return Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Row(
                  children: [
                    Icon(icon),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        title,
                        style: Theme.of(context).textTheme.titleMedium,
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 12),
                if (snapshot.connectionState != ConnectionState.done)
                  const Center(
                    child: Padding(
                      padding: EdgeInsets.all(10),
                      child: CircularProgressIndicator(),
                    ),
                  )
                else if (snapshot.hasError)
                  Column(
                    key: ValueKey(key.toString() + '-error'),
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Text(
                        context.tr(customerAccountErrorKey(snapshot.error)),
                      ),
                      Align(
                        alignment: AlignmentDirectional.centerStart,
                        child: TextButton.icon(
                          onPressed: onRetry,
                          icon: const Icon(Icons.refresh_rounded),
                          label: Text(context.tr('customer.action.retry')),
                        ),
                      ),
                    ],
                  )
                else
                  builder(snapshot.data),
              ],
            );
          },
        ),
      ),
    );
  }
}
