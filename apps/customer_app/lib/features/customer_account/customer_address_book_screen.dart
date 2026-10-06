// ignore_for_file: prefer_interpolation_to_compose_strings

import 'package:flutter/material.dart';

import '../../core/api/b2c_account_api.dart';
import '../../core/localization/app_translations.dart';
import '../../core/location/customer_location_service.dart';
import '../../shared/customer_ui_v3/customer_ui_v3.dart';
import '../../core/location/customer_map_pin_selector.dart';
import 'customer_account_data.dart';
import 'customer_account_v3_widgets.dart';

class CustomerAddressBookScreen extends StatefulWidget {
  const CustomerAddressBookScreen({
    required this.api,
    this.locationService = const GeolocatorCustomerLocationService(),
    this.mapPinPicker = showCustomerMapPinSelector,
    super.key,
  });

  final B2cAccountApi api;
  final CustomerLocationService locationService;
  final CustomerMapPinPicker mapPinPicker;

  @override
  State<CustomerAddressBookScreen> createState() =>
      _CustomerAddressBookScreenState();
}

class _CustomerAddressBookScreenState
    extends State<CustomerAddressBookScreen> with WidgetsBindingObserver {
  late Future<Object?> _future;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _future = widget.api.addresses();
  }


  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed && mounted) {
      _reload();
    }
  }

  @override
  void didUpdateWidget(covariant CustomerAddressBookScreen oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.api != widget.api) _reload();
  }

  void _reload() => setState(() => _future = widget.api.addresses());

  Future<void> _setDefault(int id) async {
    try {
      await widget.api.setDefaultAddress(id);
      if (mounted) _reload();
    } catch (error) {
      _showError(error);
    }
  }

  Future<void> _delete(int id) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: Text(context.tr('customer.addresses.delete')),
        content: Text(context.tr('customer.addresses.delete_confirmation')),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(dialogContext).pop(false),
            child: Text(context.tr('customer.action.cancel')),
          ),
          FilledButton(
            key: const ValueKey('customer-address-delete-confirm'),
            onPressed: () => Navigator.of(dialogContext).pop(true),
            child: Text(context.tr('customer.addresses.delete')),
          ),
        ],
      ),
    );

    if (confirmed != true) return;

    try {
      await widget.api.removeAddress(id);
      if (mounted) _reload();
    } catch (error) {
      _showError(error);
    }
  }

  void _showError(Object error) {
    if (!mounted) return;
    var message = context.tr(customerAccountErrorKey(error));
    if (error is B2cAccountException && error.fieldErrors.isNotEmpty) {
      final details = error.fieldErrors.entries
          .expand((entry) => entry.value.map((value) => '${entry.key}: $value'))
          .join('\n');
      if (details.trim().isNotEmpty) message = details;
    } else if (error is B2cAccountException &&
        error.serverMessage != null &&
        error.serverMessage!.trim().isNotEmpty) {
      message = error.serverMessage!.trim();
    }
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(message)),
    );
  }

  String _locationErrorKey(Object error) {
    if (error is CustomerLocationException) {
      switch (error.code) {
        case 'location_services_disabled':
          return 'customer.addresses.location_services_disabled';
        case 'location_permission_denied':
          return 'customer.addresses.location_permission_denied';
        case 'location_permission_denied_forever':
          return 'customer.addresses.location_permission_settings';
      }
    }
    return 'customer.addresses.location_failed';
  }

  Future<void> _edit([Map<String, dynamic>? existing]) async {
    final controllers = <String, TextEditingController>{
      'label': TextEditingController(text: existing?['label']?.toString() ?? ''),
      'recipient_name': TextEditingController(
        text: existing?['recipient_name']?.toString() ?? '',
      ),
      'delivery_phone': TextEditingController(
        text: existing?['delivery_phone']?.toString() ?? '',
      ),
      'line1': TextEditingController(text: existing?['line1']?.toString() ?? ''),
      'area': TextEditingController(text: existing?['area']?.toString() ?? ''),
      'governorate': TextEditingController(
        text: existing?['governorate']?.toString() ?? '',
      ),
      'block': TextEditingController(text: existing?['block']?.toString() ?? ''),
      'avenue': TextEditingController(text: existing?['avenue']?.toString() ?? ''),
      'building': TextEditingController(
        text: existing?['building']?.toString() ?? '',
      ),
      'floor': TextEditingController(text: existing?['floor']?.toString() ?? ''),
      'apartment': TextEditingController(
        text: existing?['apartment']?.toString() ?? '',
      ),
      'city': TextEditingController(
        text: existing?['city']?.toString() ?? 'Kuwait City',
      ),
      'country': TextEditingController(
        text: existing?['country']?.toString() ?? 'Kuwait',
      ),
      'country_code': TextEditingController(
        text: existing?['country_code']?.toString() ?? 'KW',
      ),
      'landmark': TextEditingController(
        text: existing?['landmark']?.toString() ?? '',
      ),
      'delivery_notes': TextEditingController(
        text: existing?['delivery_notes']?.toString() ?? '',
      ),
    };

    double? latitude = (existing?['latitude'] as num?)?.toDouble();
    double? longitude = (existing?['longitude'] as num?)?.toDouble();
    double? accuracy =
        (existing?['location_accuracy_meters'] as num?)?.toDouble();
    var source = existing?['location_source']?.toString() ?? 'manual';
    var makeDefault = existing?['is_default'] == true;
    var locating = false;
    String? dialogError;
    Map<String, dynamic>? payload;

    final accepted = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => StatefulBuilder(
        builder: (dialogContext, setDialogState) {
          Future<void> useCurrentLocation() async {
            setDialogState(() {
              locating = true;
              dialogError = null;
            });
            try {
              final point = await widget.locationService.currentLocation();
              setDialogState(() {
                latitude = point.latitude;
                longitude = point.longitude;
                accuracy = point.accuracyMeters;
                source = 'current_location';
              });
            } catch (error) {
              setDialogState(() {
                dialogError = _locationErrorKey(error);
              });
            } finally {
              setDialogState(() => locating = false);
            }
          }

          Future<void> chooseMap() async {
            try {
              final selected = await widget.mapPinPicker(
                dialogContext,
                initialLatitude: latitude,
                initialLongitude: longitude,
              );
              if (selected == null) return;
              setDialogState(() {
                latitude = selected.latitude;
                longitude = selected.longitude;
                accuracy = null;
                source = 'map_pin';
                dialogError = null;
              });
            } catch (error) {
              setDialogState(() {
                dialogError = _locationErrorKey(error);
              });
            }
          }

          void save() {
            final line1 = controllers['line1']!.text.trim();
            final city = controllers['city']!.text.trim();
            final countryCode =
                controllers['country_code']!.text.trim().toUpperCase();

            if (line1.isEmpty || city.isEmpty || countryCode.length != 2) {
              setDialogState(() {
                dialogError = 'customer.addresses.required_fields';
              });
              return;
            }

            payload = <String, dynamic>{
              'label': controllers['label']!.text.trim(),
              'recipient_name': controllers['recipient_name']!.text.trim(),
              'delivery_phone': controllers['delivery_phone']!.text.trim(),
              'line1': line1,
              'area': controllers['area']!.text.trim(),
              'governorate': controllers['governorate']!.text.trim(),
              'block': controllers['block']!.text.trim(),
              'avenue': controllers['avenue']!.text.trim(),
              'building': controllers['building']!.text.trim(),
              'floor': controllers['floor']!.text.trim(),
              'apartment': controllers['apartment']!.text.trim(),
              'city': city,
              'country': controllers['country']!.text.trim(),
              'country_code': countryCode,
              'landmark': controllers['landmark']!.text.trim(),
              'delivery_notes': controllers['delivery_notes']!.text.trim(),
              'latitude': latitude,
              'longitude': longitude,
              'location_accuracy_meters': accuracy,
              'location_source': source,
              'is_default': makeDefault,
            };
            Navigator.of(dialogContext).pop(true);
          }

          return AlertDialog(
            title: Text(
              existing == null
                  ? context.tr('customer.addresses.add')
                  : context.tr('customer.addresses.edit'),
            ),
            content: SizedBox(
              width: 540,
              child: SingleChildScrollView(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    _field(
                      controller: controllers['label']!,
                      label: context.tr('customer.addresses.label'),
                      key: const ValueKey('customer-address-label'),
                    ),
                    _field(
                      controller: controllers['recipient_name']!,
                      label: context.tr('customer.addresses.recipient'),
                    ),
                    _field(
                      controller: controllers['delivery_phone']!,
                      label: context.tr('customer.addresses.phone'),
                      keyboardType: TextInputType.phone,
                    ),
                    _field(
                      controller: controllers['line1']!,
                      label: context.tr('customer.addresses.line1'),
                      key: const ValueKey('customer-address-line1'),
                    ),
                    _field(
                      controller: controllers['area']!,
                      label: context.tr('customer.addresses.area'),
                    ),
                    _field(
                      controller: controllers['governorate']!,
                      label: context.tr('customer.addresses.governorate'),
                    ),
                    Row(
                      children: [
                        Expanded(
                          child: _field(
                            controller: controllers['block']!,
                            label: context.tr('customer.addresses.block'),
                          ),
                        ),
                        const SizedBox(width: 8),
                        Expanded(
                          child: _field(
                            controller: controllers['avenue']!,
                            label: context.tr('customer.addresses.avenue'),
                          ),
                        ),
                      ],
                    ),
                    Row(
                      children: [
                        Expanded(
                          child: _field(
                            controller: controllers['building']!,
                            label: context.tr('customer.addresses.building'),
                          ),
                        ),
                        const SizedBox(width: 8),
                        Expanded(
                          child: _field(
                            controller: controllers['floor']!,
                            label: context.tr('customer.addresses.floor'),
                          ),
                        ),
                        const SizedBox(width: 8),
                        Expanded(
                          child: _field(
                            controller: controllers['apartment']!,
                            label: context.tr('customer.addresses.apartment'),
                          ),
                        ),
                      ],
                    ),
                    Row(
                      children: [
                        Expanded(
                          flex: 2,
                          child: _field(
                            controller: controllers['city']!,
                            label: context.tr('customer.addresses.city'),
                            key: const ValueKey('customer-address-city'),
                          ),
                        ),
                        const SizedBox(width: 8),
                        Expanded(
                          child: _field(
                            controller: controllers['country_code']!,
                            label: context.tr('customer.addresses.country_code'),
                            key: const ValueKey('customer-address-country-code'),
                          ),
                        ),
                      ],
                    ),
                    _field(
                      controller: controllers['country']!,
                      label: context.tr('customer.addresses.country'),
                    ),
                    _field(
                      controller: controllers['landmark']!,
                      label: context.tr('customer.addresses.landmark'),
                    ),
                    _field(
                      controller: controllers['delivery_notes']!,
                      label: context.tr('customer.addresses.notes'),
                      maxLines: 2,
                    ),
                    const SizedBox(height: 8),
                    Row(
                      children: [
                        Expanded(
                          child: OutlinedButton.icon(
                            key: const ValueKey(
                              'customer-address-current-location',
                            ),
                            onPressed: locating ? null : useCurrentLocation,
                            icon: locating
                                ? const SizedBox.square(
                                    dimension: 16,
                                    child: CircularProgressIndicator(
                                      strokeWidth: 2,
                                    ),
                                  )
                                : const Icon(Icons.my_location_rounded),
                            label: Text(
                              context.tr(
                                'customer.addresses.share_location',
                              ),
                            ),
                          ),
                        ),
                        const SizedBox(width: 8),
                        Expanded(
                          child: OutlinedButton.icon(
                            key: const ValueKey('customer-address-map'),
                            onPressed: chooseMap,
                            icon: const Icon(Icons.map_outlined),
                            label: Text(
                              context.tr('customer.addresses.choose_map'),
                            ),
                          ),
                        ),
                      ],
                    ),
                    if (latitude != null && longitude != null) ...[
                      const SizedBox(height: 8),
                      Text(
                        latitude!.toStringAsFixed(7) +
                            ', ' +
                            longitude!.toStringAsFixed(7),
                        key: const ValueKey('customer-address-coordinates'),
                      ),
                    ],
                    if (dialogError != null) ...[
                      const SizedBox(height: 8),
                      Text(
                        context.tr(dialogError!),
                        key: const ValueKey('customer-address-dialog-error'),
                        style: TextStyle(
                          color: Theme.of(context).colorScheme.error,
                        ),
                      ),
                    ],
                    SwitchListTile(
                      contentPadding: EdgeInsets.zero,
                      value: makeDefault,
                      onChanged: (value) =>
                          setDialogState(() => makeDefault = value),
                      title: Text(
                        context.tr('customer.addresses.set_default'),
                      ),
                    ),
                  ],
                ),
              ),
            ),
            actions: [
              TextButton(
                onPressed: () => Navigator.of(dialogContext).pop(false),
                child: Text(context.tr('customer.action.cancel')),
              ),
              FilledButton(
                key: const ValueKey('customer-address-save'),
                onPressed: save,
                child: Text(context.tr('customer.action.save')),
              ),
            ],
          );
        },
      ),
    );

    // The dialog route can still be running its reverse transition when
    // showDialog completes. Disposing externally-owned field controllers here
    // races that final rebuild ("TextEditingController used after disposed").
    // Once the route subtree is gone and this method returns, these local
    // controllers become unreachable and are reclaimed safely.
    if (accepted != true || payload == null) return;

    try {
      final id = (existing?['id'] as num?)?.toInt();
      if (id == null) {
        await widget.api.createAddress(payload!);
      } else {
        await widget.api.updateAddress(id, payload!);
      }
      if (mounted) _reload();
    } catch (error) {
      _showError(error);
    }
  }

  Widget _field({
    required TextEditingController controller,
    required String label,
    Key? key,
    TextInputType? keyboardType,
    int maxLines = 1,
  }) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: TextField(
        key: key,
        controller: controller,
        keyboardType: keyboardType,
        maxLines: maxLines,
        decoration: InputDecoration(labelText: label),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      key: const ValueKey('customer-address-book-screen'),
      body: CustomerCurvedHeaderSurface(
        header: CustomerAccountHeader(
          title: context.tr('customer.profile.addresses'),
          subtitle: context.tr('customer.addresses.subtitle'),
        ),
        child: RefreshIndicator(
          onRefresh: () async {
            _reload();
            try {
              await _future;
            } catch (_) {}
          },
          child: FutureBuilder<Object?>(
            future: _future,
            builder: (context, snapshot) {
              if (snapshot.connectionState != ConnectionState.done) {
                return const _AddressScrollableState(
                  child: CustomerAccountListSkeleton(),
                );
              }

              if (snapshot.hasError) {
                return _AddressScrollableState(
                  key: const ValueKey('customer-addresses-error'),
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

              return ListView(
                key: const ValueKey('customer-addresses-list'),
                physics: const AlwaysScrollableScrollPhysics(),
                padding: const EdgeInsetsDirectional.fromSTEB(
                  CustomerUiSpacing.page,
                  CustomerUiSpacing.lg,
                  CustomerUiSpacing.page,
                  CustomerUiSpacing.xxl,
                ),
                children: [
                  FilledButton.icon(
                    key: const ValueKey('customer-address-add'),
                    onPressed: () => _edit(),
                    icon: const Icon(Icons.add_location_alt_outlined),
                    label: Text(context.tr('customer.addresses.add')),
                  ),
                  const SizedBox(height: CustomerUiSpacing.md),
                  if (rows.isEmpty)
                    CustomerStateView(
                      kind: CustomerStateKind.empty,
                      title: context.tr('customer.addresses.empty'),
                      message: context.tr('customer.addresses.subtitle'),
                      icon: Icons.location_off_outlined,
                    )
                  else
                    ...rows.map((address) {
                      final id = (address['id'] as num?)?.toInt();
                      final isDefault = address['is_default'] == true;
                      final label = address['label']?.toString().trim();
                      final parts = [
                        address['line1'],
                        address['area'],
                        address['city'],
                      ]
                          .where(
                            (value) =>
                                value != null &&
                                value.toString().trim().isNotEmpty,
                          )
                          .map((value) => value.toString())
                          .join(' · ');

                      return Padding(
                        padding: const EdgeInsets.only(
                          bottom: CustomerUiSpacing.sm,
                        ),
                        child: CustomerAccountSurfaceCard(
                          key: ValueKey(
                            'customer-address-' +
                                (id?.toString() ?? 'unknown'),
                          ),
                          child: Row(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              DecoratedBox(
                                decoration: BoxDecoration(
                                  color: isDefault
                                      ? CustomerUiColors.limeSoft
                                      : CustomerUiColors.mint,
                                  shape: BoxShape.circle,
                                ),
                                child: const SizedBox.square(
                                  dimension: 48,
                                  child: Icon(
                                    Icons.location_on_outlined,
                                    color: CustomerUiColors.deepGreenStrong,
                                  ),
                                ),
                              ),
                              const SizedBox(width: CustomerUiSpacing.sm),
                              Expanded(
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Wrap(
                                      spacing: CustomerUiSpacing.xs,
                                      runSpacing: CustomerUiSpacing.xs,
                                      crossAxisAlignment:
                                          WrapCrossAlignment.center,
                                      children: [
                                        Text(
                                          label == null || label.isEmpty
                                              ? context.tr(
                                                  'customer.addresses.address',
                                                )
                                              : label,
                                          style: Theme.of(context)
                                              .textTheme
                                              .titleMedium,
                                        ),
                                        if (isDefault)
                                          CustomerBadge(
                                            label: context.tr(
                                              'customer.addresses.default_badge',
                                            ),
                                            tone: CustomerBadgeTone.accent,
                                          ),
                                      ],
                                    ),
                                    if (parts.isNotEmpty) ...[
                                      const SizedBox(
                                        height: CustomerUiSpacing.xs,
                                      ),
                                      Text(
                                        parts,
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
                              if (id != null)
                                PopupMenuButton<String>(
                                  key: ValueKey(
                                    'customer-address-menu-' + id.toString(),
                                  ),
                                  icon: const Icon(
                                    Icons.more_vert_rounded,
                                    color: CustomerUiColors.inkSoft,
                                  ),
                                  onSelected: (action) {
                                    if (action == 'edit') {
                                      _edit(address);
                                    } else if (action == 'default') {
                                      _setDefault(id);
                                    } else if (action == 'delete') {
                                      _delete(id);
                                    }
                                  },
                                  itemBuilder: (context) => [
                                    PopupMenuItem<String>(
                                      value: 'edit',
                                      child: Text(
                                        context.tr(
                                          'customer.addresses.edit',
                                        ),
                                      ),
                                    ),
                                    if (!isDefault)
                                      PopupMenuItem<String>(
                                        value: 'default',
                                        child: Text(
                                          context.tr(
                                            'customer.addresses.set_default',
                                          ),
                                        ),
                                      ),
                                    PopupMenuItem<String>(
                                      value: 'delete',
                                      child: Text(
                                        context.tr(
                                          'customer.addresses.delete',
                                        ),
                                      ),
                                    ),
                                  ],
                                ),
                            ],
                          ),
                        ),
                      );
                    }),
                ],
              );
            },
          ),
        ),
      ),
    );
  }
}

class _AddressScrollableState extends StatelessWidget {
  const _AddressScrollableState({
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
