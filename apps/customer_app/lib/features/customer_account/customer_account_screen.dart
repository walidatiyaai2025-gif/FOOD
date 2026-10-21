import '../storefront/storefront_design_system.dart';
// ignore_for_file: prefer_interpolation_to_compose_strings

import 'package:flutter/material.dart';

import '../../core/api/b2c_account_api.dart';
import '../../core/diagnostics/customer_diagnostics.dart';
import '../../core/localization/app_translations.dart';
import '../../core/network/customer_data_mode.dart';
import '../../core/theme/customer_display_preferences.dart';
import '../../shared/customer_ui_v3/customer_ui_v3.dart';
import 'customer_account_data.dart';
import 'customer_account_v3_widgets.dart';

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

class _CustomerAccountScreenState extends State<CustomerAccountScreen>
    with WidgetsBindingObserver {
  late Future<Object?> _profile;
  late Future<Object?> _addresses;
  late Future<Object?> _favorites;
  Future<Object?>? _notifications;
  String _locale = 'ar';
  final CustomerDataModeController _dataMode =
      CustomerDataModeController.instance;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _dataMode.addListener(_onDataModeChanged);
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
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _dataMode.removeListener(_onDataModeChanged);
    super.dispose();
  }

  void _onDataModeChanged() {
    if (mounted) setState(() {});
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed && mounted) {
      _reloadAll();
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

  Future<void> _requestAccountDeletion() async {
    final api = widget.api;
    if (api is! HttpB2cAccountApi) return;

    final password = TextEditingController();
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: Text(
          _locale == 'ar' ? 'طلب حذف الحساب' : 'Request account deletion',
        ),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              _locale == 'ar'
                  ? 'سيتم التحقق من هويتك. الطلبات النشطة أو الالتزامات المالية قد تؤخر إخفاء بيانات الحساب، مع الاحتفاظ بسجلات الطلبات والفواتير المطلوبة.'
                  : 'Your identity will be verified. Active orders or financial obligations can delay anonymization, while required order and invoice records are retained.',
            ),
            const SizedBox(height: CustomerUiSpacing.sm),
            TextField(
              key: const ValueKey('customer-account-deletion-password'),
              controller: password,
              obscureText: true,
              decoration: InputDecoration(
                labelText: _locale == 'ar' ? 'كلمة المرور' : 'Password',
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
            key: const ValueKey('customer-account-deletion-confirm'),
            onPressed: () => Navigator.of(dialogContext).pop(true),
            child: Text(_locale == 'ar' ? 'تأكيد الطلب' : 'Confirm request'),
          ),
        ],
      ),
    );

    if (confirmed != true || password.text.isEmpty) {
      password.dispose();
      return;
    }

    try {
      final response = await api.requestAccountDeletion(password.text);
      if (!mounted) return;
      final data = response is Map && response['data'] is Map
          ? Map<String, dynamic>.from(response['data'] as Map)
          : const <String, dynamic>{};
      final status = data['status']?.toString() ?? 'REQUESTED';
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          key: const ValueKey('customer-account-deletion-status'),
          content: Text(
            _locale == 'ar'
                ? 'حالة طلب حذف الحساب: $status'
                : 'Account deletion request status: $status',
          ),
        ),
      );
    } on B2cAccountException catch (error) {
      if (!mounted) return;
      final message = error.fieldErrors['password']?.first ??
          error.serverMessage ??
          error.code;
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(message)));
    } finally {
      password.dispose();
    }
  }

  Future<void> _contactUs() async {
    if (widget.api is! CustomerContactApi) return;
    final contactApi = widget.api as CustomerContactApi;

    Object? profile;
    try {
      profile = await _profile;
    } catch (_) {
      profile = null;
    }
    if (!mounted) return;

    String initialPhone = '';
    if (profile is Map) {
      final customer = profile['customer'];
      if (customer is Map) {
        initialPhone = customer['phone']?.toString() ?? '';
      }
    }

    final draft = await showDialog<_CustomerContactDraft>(
      context: context,
      builder: (_) => _CustomerContactDialog(initialPhone: initialPhone),
    );
    if (draft == null || !mounted) return;

    try {
      final response = await contactApi.submitContactUs(
        storeId: widget.retailStoreId,
        phone: draft.phone,
        message: draft.message,
      );
      if (!mounted) return;

      String reference = '';
      if (response is Map && response['data'] is Map) {
        final data = response['data'] as Map;
        reference =
            data['id']?.toString() ?? data['public_id']?.toString() ?? '';
      }
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          key: const ValueKey('customer-contact-success'),
          content: Text(
            context.tr('customer.contact.success') +
                (reference.isEmpty
                    ? ''
                    : " · ${context.tr('customer.contact.case')} #$reference"),
          ),
        ),
      );
    } on B2cAccountException catch (error) {
      if (!mounted) return;
      String? fieldMessage;
      for (final messages in error.fieldErrors.values) {
        if (messages.isNotEmpty) {
          fieldMessage = messages.first;
          break;
        }
      }
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            fieldMessage ??
                error.serverMessage ??
                (error.code.isEmpty
                    ? context.tr('customer.contact.error')
                    : error.code),
          ),
        ),
      );
    }
  }

  Future<void> _editProfile(Map<String, dynamic> profile) async {
    final name = TextEditingController(text: profile['name']?.toString() ?? '');
    final email = TextEditingController(
      text: profile['email']?.toString() ?? '',
    );
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
            const SizedBox(height: CustomerUiSpacing.sm),
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
      body: FoodexUnifiedHeaderSurface(
        title: context.tr('customer.profile.title'),
        searchHint: context.tr('customer.products.search_name_sku_barcode'),
        child: RefreshIndicator(
          onRefresh: () async {
            _reloadAll();
            await Future.wait<Object?>([
              _profile.catchError((_) => null),
              _addresses.catchError((_) => null),
              _favorites.catchError((_) => null),
              (_notifications ?? Future<Object?>.value(null)).catchError(
                (_) => null,
              ),
            ]);
          },
          child: ListView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsetsDirectional.fromSTEB(
              CustomerUiSpacing.page,
              CustomerUiSpacing.lg,
              CustomerUiSpacing.page,
              CustomerUiSpacing.xxl,
            ),
            children: [
              _profileCard(),
              const SizedBox(height: CustomerUiSpacing.sm),
              ValueListenableBuilder<ThemeMode>(
                valueListenable: CustomerDisplayPreferences.instance.themeMode,
                builder: (context, themeMode, _) => CustomerAccountSurfaceCard(
                  key: const ValueKey('customer-account-dark-mode'),
                  padding: EdgeInsets.zero,
                  child: Material(
                    color: Colors.transparent,
                    child: SwitchListTile(
                      secondary: Icon(
                        themeMode == ThemeMode.dark
                            ? Icons.dark_mode_rounded
                            : Icons.light_mode_rounded,
                      ),
                      title: Text(context.tr('customer.appearance.dark_mode')),
                      subtitle: Text(
                        context.tr('customer.appearance.dark_mode_subtitle'),
                      ),
                      value: themeMode == ThemeMode.dark,
                      onChanged: (enabled) async {
                        await CustomerDisplayPreferences.instance
                            .setDarkMode(enabled);
                      },
                    ),
                  ),
                ),
              ),
              const SizedBox(height: CustomerUiSpacing.sm),
              _dataModeCard(),
              const SizedBox(height: CustomerUiSpacing.sm),
              _countCard(
                key: const ValueKey('customer-account-addresses-section'),
                future: _addresses,
                title: context.tr('customer.profile.addresses'),
                icon: Icons.location_on_outlined,
                onTap: widget.onOpenAddresses,
                onRetry: () =>
                    setState(() => _addresses = widget.api.addresses()),
              ),
              const SizedBox(height: CustomerUiSpacing.sm),
              _countCard(
                key: const ValueKey('customer-account-favorites-section'),
                future: _favorites,
                title: context.tr('customer.profile.favorites'),
                icon: Icons.favorite_border_rounded,
                onTap: widget.onOpenFavorites,
                onRetry: () => setState(
                  () => _favorites = widget.favoritesApi.favoritesForStore(
                    widget.retailStoreId,
                  ),
                ),
              ),
              const SizedBox(height: CustomerUiSpacing.sm),
              _countCard(
                key: const ValueKey('customer-account-notifications-section'),
                future: _notifications ?? Future<Object?>.value(null),
                title: context.tr('customer.notifications.title'),
                icon: Icons.notifications_none_rounded,
                onTap: widget.onOpenNotifications,
                onRetry: () => setState(
                  () => _notifications = widget.api.notifications(
                    locale: _locale,
                  ),
                ),
              ),
              if (widget.onOpenOrders != null) ...[
                const SizedBox(height: CustomerUiSpacing.sm),
                CustomerAccountShortcutCard(
                  key: const ValueKey('customer-account-orders-section'),
                  title: context.tr('customer.profile.orders'),
                  icon: Icons.receipt_long_outlined,
                  onTap: widget.onOpenOrders,
                ),
              ],
              if (widget.api is CustomerContactApi) ...[
                const SizedBox(height: CustomerUiSpacing.sm),
                CustomerAccountShortcutCard(
                  key: const ValueKey('customer-account-contact-us'),
                  title: context.tr('customer.contact.title'),
                  icon: Icons.support_agent_rounded,
                  onTap: _contactUs,
                ),
              ],
              if (widget.api is HttpB2cAccountApi) ...[
                const SizedBox(height: CustomerUiSpacing.lg),
                OutlinedButton.icon(
                  key: const ValueKey('customer-account-delete-account'),
                  onPressed: _requestAccountDeletion,
                  icon: const Icon(Icons.person_off_outlined),
                  label: Text(
                    _locale == 'ar'
                        ? 'طلب حذف الحساب'
                        : 'Request account deletion',
                  ),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }

  Widget _dataModeCard() {
    final usage = CustomerDiagnostics.instance.dataUsageSnapshot;
    final downloaded = (usage['downloaded_bytes'] as num?)?.toInt() ?? 0;
    final hitRatio = (usage['cache_hit_ratio'] as num?)?.toDouble() ?? 0;
    final effective = _dataMode.effectiveMode;
    final selected = _dataMode.preference.name;

    String label(String value) {
      if (_locale == 'ar') {
        return switch (value) {
          'automatic' => 'تلقائي',
          'normal' => 'عادي',
          'lite' => 'توفير البيانات',
          'offline' => 'بدون إنترنت',
          _ => value,
        };
      }
      return switch (value) {
        'auto' => 'Auto',
        'normal' => 'Normal',
        'lite' => 'Lite / Low Data',
        'offline' => 'Offline',
        _ => value,
      };
    }

    return CustomerAccountSurfaceCard(
      key: const ValueKey('customer-data-mode'),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              const Icon(Icons.data_saver_on_outlined),
              const SizedBox(width: CustomerUiSpacing.sm),
              Expanded(
                child: Text(
                  _locale == 'ar' ? 'استهلاك الإنترنت' : 'Data usage',
                  style: Theme.of(context).textTheme.titleMedium,
                ),
              ),
              Text(
                label(effective.name),
                key: const ValueKey('customer-data-mode-effective'),
                style: Theme.of(context).textTheme.labelLarge,
              ),
            ],
          ),
          const SizedBox(height: CustomerUiSpacing.sm),
          DropdownButtonFormField<String>(
            key: const ValueKey('customer-data-mode-selector'),
            initialValue: selected,
            decoration: InputDecoration(
              labelText: _locale == 'ar' ? 'وضع البيانات' : 'Data mode',
            ),
            items: const ['automatic', 'lite']
                .map(
                  (value) => DropdownMenuItem<String>(
                    value: value,
                    child: Text(label(value)),
                  ),
                )
                .toList(growable: false),
            onChanged: (value) {
              if (value == null) return;
              _dataMode.setPreference(
                value == 'lite'
                    ? CustomerDataPreference.lite
                    : CustomerDataPreference.automatic,
              );
            },
          ),
          const SizedBox(height: CustomerUiSpacing.sm),
          Text(
            _locale == 'ar'
                ? 'تم تنزيل ${(downloaded / 1024).toStringAsFixed(1)} ك.ب · نسبة استخدام الكاش ${(hitRatio * 100).toStringAsFixed(0)}%'
                : 'Downloaded ${(downloaded / 1024).toStringAsFixed(1)} KB · cache hit ${(hitRatio * 100).toStringAsFixed(0)}%',
            key: const ValueKey('customer-data-usage-summary'),
            style: Theme.of(
              context,
            ).textTheme.bodySmall?.copyWith(color: CustomerUiColors.muted),
          ),
          if (_dataMode.isAutoManaged && _dataMode.autoLite) ...[
            const SizedBox(height: CustomerUiSpacing.xs),
            Text(
              _locale == 'ar'
                  ? 'تم تفعيل التوفير تلقائياً بسبب اتصال ضعيف أو غير مستقر.'
                  : 'Lite mode was enabled automatically for a weak or unstable connection.',
              style: Theme.of(context).textTheme.bodySmall,
            ),
          ],
        ],
      ),
    );
  }

  Widget _profileCard() {
    const key = ValueKey('customer-account-profile-section');
    return CustomerAccountSurfaceCard(
      key: key,
      child: FutureBuilder<Object?>(
        future: _profile,
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done) {
            return const Row(
              children: [
                CustomerSkeletonBox(height: 72, width: 72, radius: 36),
                SizedBox(width: CustomerUiSpacing.md),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      CustomerSkeletonBox(height: 18, radius: 9),
                      SizedBox(height: CustomerUiSpacing.xs),
                      CustomerSkeletonBox(height: 14, width: 180, radius: 7),
                      SizedBox(height: CustomerUiSpacing.md),
                      CustomerSkeletonBox(
                        height: 44,
                        width: 150,
                        radius: CustomerUiRadii.md,
                      ),
                    ],
                  ),
                ),
              ],
            );
          }

          if (snapshot.hasError) {
            return _sectionError(
              key: const ValueKey('customer-account-profile-section-error'),
              error: snapshot.error,
              onRetry: () => setState(() => _profile = widget.api.profile()),
            );
          }

          final profile = customerAccountMap(snapshot.data);
          final name = profile['name']?.toString().trim();
          final email = profile['email']?.toString().trim();

          return Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              CustomerAccountAvatar(name: name),
              const SizedBox(width: CustomerUiSpacing.md),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      name == null || name.isEmpty
                          ? context.tr('customer.profile.title')
                          : name,
                      style: Theme.of(context).textTheme.titleLarge,
                    ),
                    if (email != null && email.isNotEmpty) ...[
                      const SizedBox(height: CustomerUiSpacing.xxs),
                      Text(
                        email,
                        style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                              color: CustomerUiColors.muted,
                            ),
                      ),
                    ],
                    const SizedBox(height: CustomerUiSpacing.md),
                    OutlinedButton.icon(
                      key: const ValueKey('customer-account-edit-profile'),
                      onPressed: () => _editProfile(profile),
                      icon: const Icon(Icons.edit_outlined),
                      label: Text(context.tr('customer.settings.edit_profile')),
                    ),
                  ],
                ),
              ),
            ],
          );
        },
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
    return FutureBuilder<Object?>(
      future: future,
      builder: (context, snapshot) {
        if (snapshot.connectionState != ConnectionState.done) {
          return CustomerAccountSurfaceCard(
            key: key,
            child: const Row(
              children: [
                CustomerSkeletonBox(height: 48, width: 48, radius: 24),
                SizedBox(width: CustomerUiSpacing.sm),
                Expanded(child: CustomerSkeletonBox(height: 18, radius: 9)),
                SizedBox(width: CustomerUiSpacing.sm),
                CustomerSkeletonBox(height: 28, width: 42, radius: 14),
              ],
            ),
          );
        }

        if (snapshot.hasError) {
          return CustomerAccountSurfaceCard(
            key: key,
            child: _sectionError(
              key: ValueKey(key.toString() + '-error'),
              error: snapshot.error,
              onRetry: onRetry,
            ),
          );
        }

        final count = customerAccountRows(snapshot.data).length;
        return CustomerAccountShortcutCard(
          key: key,
          title: title,
          icon: icon,
          value: count.toString(),
          onTap: onTap,
        );
      },
    );
  }

  Widget _sectionError({
    required Key key,
    required Object? error,
    required VoidCallback onRetry,
  }) {
    return Column(
      key: key,
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const Icon(
          Icons.error_outline_rounded,
          color: CustomerUiColors.destructive,
        ),
        const SizedBox(height: CustomerUiSpacing.xs),
        Text(context.tr(customerAccountErrorKey(error))),
        const SizedBox(height: CustomerUiSpacing.xs),
        TextButton.icon(
          onPressed: onRetry,
          icon: const Icon(Icons.refresh_rounded),
          label: Text(context.tr('customer.action.retry')),
        ),
      ],
    );
  }
}


class _CustomerContactDraft {
  const _CustomerContactDraft({
    required this.phone,
    required this.message,
  });

  final String phone;
  final String message;
}

class _CustomerContactDialog extends StatefulWidget {
  const _CustomerContactDialog({required this.initialPhone});

  final String initialPhone;

  @override
  State<_CustomerContactDialog> createState() => _CustomerContactDialogState();
}

class _CustomerContactDialogState extends State<_CustomerContactDialog> {
  final GlobalKey<FormState> _formKey = GlobalKey<FormState>();
  late final TextEditingController _phone;
  final TextEditingController _message = TextEditingController();

  @override
  void initState() {
    super.initState();
    _phone = TextEditingController(text: widget.initialPhone);
  }

  @override
  void dispose() {
    _phone.dispose();
    _message.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: Text(context.tr('customer.contact.title')),
      content: Form(
        key: _formKey,
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(context.tr('customer.contact.subtitle')),
              const SizedBox(height: CustomerUiSpacing.md),
              TextFormField(
                key: const ValueKey('customer-contact-phone'),
                controller: _phone,
                keyboardType: TextInputType.phone,
                maxLength: 50,
                decoration: InputDecoration(
                  labelText: context.tr('customer.contact.phone'),
                ),
                validator: (value) => value == null || value.trim().isEmpty
                    ? context.tr('customer.contact.phone_required')
                    : null,
              ),
              const SizedBox(height: CustomerUiSpacing.sm),
              TextFormField(
                key: const ValueKey('customer-contact-message'),
                controller: _message,
                minLines: 4,
                maxLines: 7,
                maxLength: 8000,
                decoration: InputDecoration(
                  labelText: context.tr('customer.contact.message'),
                  alignLabelWithHint: true,
                ),
                validator: (value) => value == null || value.trim().isEmpty
                    ? context.tr('customer.contact.message_required')
                    : null,
              ),
            ],
          ),
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: Text(context.tr('customer.action.cancel')),
        ),
        FilledButton(
          key: const ValueKey('customer-contact-submit'),
          onPressed: () {
            if (_formKey.currentState?.validate() != true) return;
            Navigator.of(context).pop(
              _CustomerContactDraft(
                phone: _phone.text.trim(),
                message: _message.text.trim(),
              ),
            );
          },
          child: Text(context.tr('customer.contact.send')),
        ),
      ],
    );
  }
}
