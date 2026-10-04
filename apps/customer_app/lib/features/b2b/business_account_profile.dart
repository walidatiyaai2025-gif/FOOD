import 'package:flutter/material.dart';

import '../../core/api/b2b_api.dart';

class B2bBusinessAccountProfile extends StatefulWidget {
  const B2bBusinessAccountProfile({
    required this.api,
    required this.endpoint,
    required this.addressesRoute,
    this.onSwitchStore,
    super.key,
  });

  final B2bApi api;
  final String endpoint;
  final String addressesRoute;

  /// UI hook consumed by the cross-cutting C13 navigation lane (#894).
  /// Screen 13 owns the visible action; #408/#894 own switch authorization
  /// and commerce-context mutation.
  final VoidCallback? onSwitchStore;

  @override
  State<B2bBusinessAccountProfile> createState() =>
      _B2bBusinessAccountProfileState();
}

class _B2bBusinessAccountProfileState
    extends State<B2bBusinessAccountProfile> {
  late Future<Object?> _future = widget.api.get(widget.endpoint);

  @override
  void didUpdateWidget(covariant B2bBusinessAccountProfile oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.api != widget.api || oldWidget.endpoint != widget.endpoint) {
      _future = widget.api.get(widget.endpoint);
    }
  }

  void _retry() => setState(() {
        _future = widget.api.get(widget.endpoint);
      });

  @override
  Widget build(BuildContext context) => FutureBuilder<Object?>(
        future: _future,
        builder: (context, snapshot) {
          final isArabic =
              Localizations.localeOf(context).languageCode == 'ar';

          if (snapshot.connectionState != ConnectionState.done) {
            return const Padding(
              padding: EdgeInsets.symmetric(vertical: 28),
              child: Center(
                key: ValueKey('b2b-loading'),
                child: CircularProgressIndicator(),
              ),
            );
          }

          if (snapshot.hasError) {
            return _ProfileError(isArabic: isArabic, onRetry: _retry);
          }

          if (snapshot.data is! Map) {
            return _ProfileEmpty(isArabic: isArabic);
          }

          final data = Map<String, dynamic>.from(snapshot.data as Map);
          final customer = data['customer'] is Map
              ? Map<String, dynamic>.from(data['customer'] as Map)
              : <String, dynamic>{};
          final businessAccount = data['business_account'] is Map
              ? Map<String, dynamic>.from(data['business_account'] as Map)
              : <String, dynamic>{};

          final linkedAccounts = _mapRows(data['retail_wholesale_accounts']);
          final ownedStoreIds = _ids(data['owned_retail_store_ids']);
          final managedStoreIds = _ids(data['managed_retail_store_ids']);
          final retailStoreIds = _ids(data['retail_store_ids']);
          final b2bCustomerIds = _ids(data['b2b_customer_ids']);
          final addresses = _rows(data['addresses']);
          final favorites = _rows(data['favorites']);
          final roles = _strings(data['roles']);

          final companyName = _first(
            businessAccount['company_name'],
            data['company_name'],
            customer['name'],
            data['name'],
          );
          final accountStatus = _first(businessAccount['status']);
          final taxNumber = _first(businessAccount['tax_number']);
          final accountName = _first(data['name'], customer['name']);
          final email = _first(customer['email'], data['email']);
          final phone = _first(customer['phone'], data['phone']);
          final locale = _first(data['locale'], isArabic ? 'ar' : 'en');
          final customerType = _first(
            customer['type'],
            b2bCustomerIds.isNotEmpty ? 'b2b' : null,
          );
          final customerId = _first(customer['id'], data['id']);
          final customerStoreId = _first(customer['store_id']);
          final retailMerchant = data['retail_merchant'] == true;

          final typeLabel = switch (customerType.toLowerCase()) {
            'b2b' => isArabic ? 'جملة B2B' : 'Wholesale B2B',
            'b2c' => isArabic ? 'تجزئة B2C' : 'Retail B2C',
            _ => customerType.isEmpty
                ? (isArabic ? 'غير محدد' : 'Not specified')
                : customerType,
          };

          return Column(
            key: const ValueKey('b2b-profile-friendly-data'),
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              _ProfileHero(
                isArabic: isArabic,
                displayName: accountName.isEmpty ? companyName : accountName,
                companyName: companyName,
                email: email,
                accountStatus: accountStatus,
                typeLabel: typeLabel,
              ),
              const SizedBox(height: 14),
              _QuickActions(
                isArabic: isArabic,
                addressesRoute: widget.addressesRoute,
                locale: locale,
                roles: roles,
                addressesCount: addresses.length,
                favoritesCount: favorites.length,
                onSwitchStore: widget.onSwitchStore,
              ),
              const SizedBox(height: 14),
              _ProfileCard(
                icon: Icons.business_outlined,
                title: isArabic ? 'بيانات الحساب' : 'Account details',
                subtitle: isArabic
                    ? 'بيانات الشركة والتواصل المسجلة'
                    : 'Registered company and contact information',
                children: [
                  if (companyName.isNotEmpty)
                    _ProfileRow(
                      icon: Icons.apartment_rounded,
                      label: isArabic ? 'الشركة' : 'Company',
                      value: companyName,
                    ),
                  if (accountName.isNotEmpty && accountName != companyName)
                    _ProfileRow(
                      icon: Icons.person_outline_rounded,
                      label: isArabic ? 'الاسم' : 'Name',
                      value: accountName,
                    ),
                  if (email.isNotEmpty)
                    _ProfileRow(
                      icon: Icons.email_outlined,
                      label: isArabic ? 'البريد الإلكتروني' : 'Email',
                      value: email,
                    ),
                  if (phone.isNotEmpty)
                    _ProfileRow(
                      icon: Icons.phone_outlined,
                      label: isArabic ? 'الهاتف' : 'Phone',
                      value: phone,
                    ),
                  if (taxNumber.isNotEmpty)
                    _ProfileRow(
                      icon: Icons.receipt_long_outlined,
                      label: isArabic ? 'الرقم الضريبي' : 'Tax number',
                      value: taxNumber,
                    ),
                  _ProfileRow(
                    icon: Icons.badge_outlined,
                    label: isArabic ? 'رقم الحساب' : 'Account ID',
                    value: customerId.isEmpty ? '—' : customerId,
                  ),
                  _ProfileRow(
                    icon: Icons.layers_outlined,
                    label: isArabic ? 'نوع الحساب' : 'Account type',
                    value: typeLabel,
                    isLast: true,
                  ),
                ],
              ),
              if (retailStoreIds.isNotEmpty ||
                  ownedStoreIds.isNotEmpty ||
                  managedStoreIds.isNotEmpty ||
                  b2bCustomerIds.isNotEmpty ||
                  linkedAccounts.isNotEmpty) ...[
                const SizedBox(height: 14),
                _ProfileCard(
                  icon: Icons.hub_outlined,
                  title: isArabic ? 'الوصول التجاري' : 'Commerce access',
                  subtitle: isArabic
                      ? 'الجملة والتجزئة المرتبطان بنفس الحساب'
                      : 'Wholesale and Retail access linked to this account',
                  children: [
                    _Metrics(
                      items: [
                        _Metric(
                          isArabic ? 'متاجر مرتبطة' : 'Linked stores',
                          retailStoreIds.length.toString(),
                          Icons.storefront_outlined,
                        ),
                        _Metric(
                          isArabic ? 'متاجر أملكها' : 'Owned stores',
                          ownedStoreIds.length.toString(),
                          Icons.home_work_outlined,
                        ),
                        _Metric(
                          isArabic ? 'متاجر أديرها' : 'Managed stores',
                          managedStoreIds.length.toString(),
                          Icons.admin_panel_settings_outlined,
                        ),
                        _Metric(
                          isArabic ? 'حسابات جملة' : 'Wholesale accounts',
                          b2bCustomerIds.length.toString(),
                          Icons.groups_2_outlined,
                        ),
                      ],
                    ),
                    if (linkedAccounts.isNotEmpty) ...[
                      const SizedBox(height: 10),
                      ...linkedAccounts.asMap().entries.map((entry) {
                        final row = entry.value;
                        return _LinkedAccountRow(
                          retailStoreId: _first(row['retail_store_id']),
                          b2bCustomerId: _first(row['b2b_customer_id']),
                          isArabic: isArabic,
                          isLast: entry.key == linkedAccounts.length - 1,
                        );
                      }),
                    ],
                  ],
                ),
              ],
              const SizedBox(height: 14),
              _ProfileCard(
                icon: Icons.verified_user_outlined,
                title: isArabic ? 'حالة الحساب' : 'Account status',
                subtitle: isArabic
                    ? 'ملخص الهوية والإعدادات الحالية'
                    : 'Current identity and settings summary',
                children: [
                  if (accountStatus.isNotEmpty)
                    _ProfileRow(
                      icon: Icons.verified_user_outlined,
                      label: isArabic ? 'الحالة' : 'Status',
                      value: accountStatus.toLowerCase() == 'active'
                          ? (isArabic ? 'نشط' : 'Active')
                          : accountStatus,
                    ),
                  _ProfileRow(
                    icon: Icons.language_rounded,
                    label: isArabic ? 'اللغة' : 'Language',
                    value: locale.isEmpty ? '—' : locale.toUpperCase(),
                  ),
                  _ProfileRow(
                    icon: Icons.store_outlined,
                    label: isArabic ? 'نطاق المتجر' : 'Store scope',
                    value: customerStoreId.isNotEmpty
                        ? customerStoreId
                        : (isArabic ? 'منصة الجملة' : 'Wholesale platform'),
                  ),
                  _ProfileRow(
                    icon: Icons.storefront_outlined,
                    label: isArabic ? 'حساب تاجر تجزئة' : 'Retail merchant',
                    value: retailMerchant
                        ? (isArabic ? 'نعم' : 'Yes')
                        : (isArabic ? 'لا' : 'No'),
                    isLast: true,
                  ),
                ],
              ),
            ],
          );
        },
      );
}

class _ProfileHero extends StatelessWidget {
  const _ProfileHero({
    required this.isArabic,
    required this.displayName,
    required this.companyName,
    required this.email,
    required this.accountStatus,
    required this.typeLabel,
  });

  final bool isArabic;
  final String displayName;
  final String companyName;
  final String email;
  final String accountStatus;
  final String typeLabel;

  @override
  Widget build(BuildContext context) {
    final title = displayName.isEmpty
        ? (isArabic ? 'عميل الأعمال' : 'Business customer')
        : displayName;
    final initial = title.substring(0, 1).toUpperCase();
    final active = accountStatus.toLowerCase() == 'active';
    final statusText = accountStatus.isEmpty
        ? typeLabel
        : active
            ? (isArabic ? 'حساب نشط' : 'Active account')
            : accountStatus;

    return Container(
      key: const ValueKey('b2b-profile-hero'),
      padding: const EdgeInsets.fromLTRB(18, 18, 18, 16),
      decoration: BoxDecoration(
        color: const Color(0xFF005B3E),
        borderRadius: BorderRadius.circular(24),
        boxShadow: const [
          BoxShadow(
            color: Color(0x2200452F),
            blurRadius: 20,
            offset: Offset(0, 8),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            isArabic ? 'حسابي' : 'My account',
            style: const TextStyle(
              color: Colors.white,
              fontSize: 21,
              fontWeight: FontWeight.w900,
            ),
          ),
          const SizedBox(height: 14),
          Row(
            children: [
              Container(
                width: 58,
                height: 58,
                alignment: Alignment.center,
                decoration: const BoxDecoration(
                  color: Color(0xFFE7F8D7),
                  shape: BoxShape.circle,
                ),
                child: Text(
                  initial,
                  style: const TextStyle(
                    color: Color(0xFF005B3E),
                    fontSize: 24,
                    fontWeight: FontWeight.w900,
                  ),
                ),
              ),
              const SizedBox(width: 13),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      title,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                        color: Colors.white,
                        fontSize: 17,
                        fontWeight: FontWeight.w900,
                      ),
                    ),
                    if (companyName.isNotEmpty && companyName != title) ...[
                      const SizedBox(height: 3),
                      Text(
                        companyName,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                          color: Color(0xFFD8EAE2),
                          fontSize: 11,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    ],
                    if (email.isNotEmpty) ...[
                      const SizedBox(height: 3),
                      Text(
                        email,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        textDirection: TextDirection.ltr,
                        style: const TextStyle(
                          color: Color(0xFFBFD8CD),
                          fontSize: 10.5,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                    ],
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: 14),
          Align(
            alignment:
                isArabic ? Alignment.centerRight : Alignment.centerLeft,
            child: Container(
              padding: const EdgeInsets.symmetric(horizontal: 11, vertical: 6),
              decoration: BoxDecoration(
                color: const Color(0x24FFFFFF),
                borderRadius: BorderRadius.circular(99),
                border: Border.all(color: const Color(0x4DFFFFFF)),
              ),
              child: Text(
                statusText,
                style: const TextStyle(
                  color: Colors.white,
                  fontSize: 10.5,
                  fontWeight: FontWeight.w800,
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _QuickActions extends StatelessWidget {
  const _QuickActions({
    required this.isArabic,
    required this.addressesRoute,
    required this.locale,
    required this.roles,
    required this.addressesCount,
    required this.favoritesCount,
    required this.onSwitchStore,
  });

  final bool isArabic;
  final String addressesRoute;
  final String locale;
  final List<String> roles;
  final int addressesCount;
  final int favoritesCount;
  final VoidCallback? onSwitchStore;

  @override
  Widget build(BuildContext context) => Container(
        key: const ValueKey('b2b-profile-actions'),
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(20),
          border: Border.all(color: const Color(0xFFDDE8E1)),
        ),
        child: LayoutBuilder(
          builder: (context, constraints) {
            final itemWidth = (constraints.maxWidth - 10) / 2;
            return Wrap(
              spacing: 10,
              runSpacing: 10,
              children: [
                SizedBox(
                  width: itemWidth,
                  child: _AccountActionTile(
                    key: const ValueKey('b2b-profile-addresses-action'),
                    icon: Icons.location_on_outlined,
                    label: isArabic ? 'العناوين' : 'Addresses',
                    caption: isArabic
                        ? '$addressesCount عنوان محفوظ'
                        : '$addressesCount saved',
                    onTap: () =>
                        Navigator.of(context).pushNamed(addressesRoute),
                  ),
                ),
                SizedBox(
                  width: itemWidth,
                  child: _AccountActionTile(
                    key: const ValueKey('b2b-profile-settings-action'),
                    icon: Icons.settings_outlined,
                    label: isArabic ? 'الإعدادات' : 'Settings',
                    caption: isArabic ? 'اللغة والحساب' : 'Language & account',
                    onTap: () => _showSettings(
                      context,
                      isArabic: isArabic,
                      locale: locale,
                      roles: roles,
                    ),
                  ),
                ),
                SizedBox(
                  width: itemWidth,
                  child: _AccountActionTile(
                    key: const ValueKey('b2b-profile-switch-store'),
                    icon: Icons.swap_horiz_rounded,
                    label: isArabic ? 'تبديل المتجر' : 'Switch store',
                    caption: isArabic
                        ? 'الجملة والتجزئة'
                        : 'Wholesale / Retail',
                    onTap: onSwitchStore,
                    pendingIntegration: onSwitchStore == null,
                  ),
                ),
                SizedBox(
                  width: itemWidth,
                  child: _AccountActionTile(
                    key: const ValueKey('b2b-profile-favorites-summary'),
                    icon: Icons.favorite_border_rounded,
                    label: isArabic ? 'المفضلة' : 'Favorites',
                    caption: isArabic
                        ? '$favoritesCount عنصر محفوظ'
                        : '$favoritesCount saved',
                  ),
                ),
              ],
            );
          },
        ),
      );

  static Future<void> _showSettings(
    BuildContext context, {
    required bool isArabic,
    required String locale,
    required List<String> roles,
  }) =>
      showModalBottomSheet<void>(
        context: context,
        showDragHandle: true,
        builder: (sheetContext) => SafeArea(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(20, 4, 20, 24),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(
                  isArabic ? 'إعدادات الحساب' : 'Account settings',
                  textAlign: TextAlign.center,
                  style: Theme.of(sheetContext)
                      .textTheme
                      .titleLarge
                      ?.copyWith(fontWeight: FontWeight.w900),
                ),
                const SizedBox(height: 16),
                _ProfileRow(
                  icon: Icons.language_rounded,
                  label: isArabic ? 'اللغة' : 'Language',
                  value: locale.isEmpty ? '—' : locale.toUpperCase(),
                ),
                _ProfileRow(
                  icon: Icons.security_outlined,
                  label: isArabic ? 'عدد الصلاحيات' : 'Role count',
                  value: roles.length.toString(),
                  isLast: true,
                ),
              ],
            ),
          ),
        ),
      );
}

class _AccountActionTile extends StatelessWidget {
  const _AccountActionTile({
    required super.key,
    required this.icon,
    required this.label,
    required this.caption,
    this.onTap,
    this.pendingIntegration = false,
  });

  final IconData icon;
  final String label;
  final String caption;
  final VoidCallback? onTap;
  final bool pendingIntegration;

  @override
  Widget build(BuildContext context) => Material(
        color: const Color(0xFFF6FAF7),
        borderRadius: BorderRadius.circular(16),
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(16),
          child: Container(
            constraints: const BoxConstraints(minHeight: 98),
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(16),
              border: Border.all(color: const Color(0xFFE2ECE6)),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Container(
                      width: 34,
                      height: 34,
                      decoration: const BoxDecoration(
                        color: Color(0xFFE7F8D7),
                        shape: BoxShape.circle,
                      ),
                      child: Icon(
                        icon,
                        color: const Color(0xFF078A43),
                        size: 19,
                      ),
                    ),
                    const Spacer(),
                    if (onTap != null)
                      const Icon(
                        Icons.chevron_right_rounded,
                        color: Color(0xFF8A9991),
                        size: 20,
                      ),
                  ],
                ),
                const SizedBox(height: 9),
                Text(
                  label,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                    color: Color(0xFF17231D),
                    fontWeight: FontWeight.w900,
                    fontSize: 12,
                  ),
                ),
                const SizedBox(height: 3),
                Text(
                  pendingIntegration
                      ? (Localizations.localeOf(context).languageCode == 'ar'
                          ? '$caption · من المزيد'
                          : '$caption · from More')
                      : caption,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                    color: Color(0xFF68766E),
                    fontSize: 9.5,
                    height: 1.25,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ],
            ),
          ),
        ),
      );
}

class _ProfileCard extends StatelessWidget {
  const _ProfileCard({
    required this.icon,
    required this.title,
    required this.subtitle,
    required this.children,
  });

  final IconData icon;
  final String title;
  final String subtitle;
  final List<Widget> children;

  @override
  Widget build(BuildContext context) => Container(
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(18),
          border: Border.all(color: const Color(0xFFDDE8E1)),
          boxShadow: const [
            BoxShadow(
              color: Color(0x0F163629),
              blurRadius: 14,
              offset: Offset(0, 5),
            ),
          ],
        ),
        padding: const EdgeInsets.all(15),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Container(
                  width: 38,
                  height: 38,
                  decoration: const BoxDecoration(
                    color: Color(0xFFE7F8D7),
                    shape: BoxShape.circle,
                  ),
                  child: Icon(
                    icon,
                    color: const Color(0xFF078A43),
                    size: 21,
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        title,
                        style: const TextStyle(
                          color: Color(0xFF00452F),
                          fontSize: 16,
                          fontWeight: FontWeight.w900,
                        ),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        subtitle,
                        style: const TextStyle(
                          color: Color(0xFF68766E),
                          fontSize: 10.5,
                          height: 1.3,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
            const SizedBox(height: 12),
            ...children,
          ],
        ),
      );
}

class _ProfileRow extends StatelessWidget {
  const _ProfileRow({
    required this.icon,
    required this.label,
    required this.value,
    this.isLast = false,
  });

  final IconData icon;
  final String label;
  final String value;
  final bool isLast;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(vertical: 10),
        decoration: BoxDecoration(
          border: isLast
              ? null
              : const Border(
                  bottom: BorderSide(color: Color(0xFFEEF3F0)),
                ),
        ),
        child: Row(
          children: [
            Container(
              width: 30,
              height: 30,
              decoration: const BoxDecoration(
                color: Color(0xFFF1F8F4),
                shape: BoxShape.circle,
              ),
              child: Icon(icon, size: 16, color: const Color(0xFF005B3E)),
            ),
            const SizedBox(width: 9),
            Expanded(
              child: Text(
                value.isEmpty ? '—' : value,
                textAlign: TextAlign.start,
                overflow: TextOverflow.ellipsis,
                maxLines: 2,
                style: const TextStyle(
                  color: Color(0xFF17231D),
                  fontSize: 12,
                  fontWeight: FontWeight.w800,
                ),
              ),
            ),
            const SizedBox(width: 10),
            Flexible(
              child: Text(
                label,
                textAlign: TextAlign.end,
                style: const TextStyle(
                  color: Color(0xFF68766E),
                  fontSize: 11,
                  fontWeight: FontWeight.w700,
                ),
              ),
            ),
          ],
        ),
      );
}

class _Metric {
  const _Metric(this.label, this.value, this.icon);

  final String label;
  final String value;
  final IconData icon;
}

class _Metrics extends StatelessWidget {
  const _Metrics({required this.items});

  final List<_Metric> items;

  @override
  Widget build(BuildContext context) => LayoutBuilder(
        builder: (context, constraints) {
          final tileWidth = (constraints.maxWidth - 8) / 2;
          return Wrap(
            spacing: 8,
            runSpacing: 8,
            children: items
                .map(
                  (item) => SizedBox(
                    width: tileWidth,
                    child: Container(
                      padding: const EdgeInsets.symmetric(
                        horizontal: 10,
                        vertical: 12,
                      ),
                      decoration: BoxDecoration(
                        color: const Color(0xFFF6FAF7),
                        borderRadius: BorderRadius.circular(14),
                        border: Border.all(color: const Color(0xFFE2ECE6)),
                      ),
                      child: Column(
                        children: [
                          Icon(item.icon,
                              color: const Color(0xFF078A43), size: 21),
                          const SizedBox(height: 5),
                          Text(
                            item.value,
                            style: const TextStyle(
                              color: Color(0xFF00452F),
                              fontSize: 20,
                              fontWeight: FontWeight.w900,
                            ),
                          ),
                          const SizedBox(height: 2),
                          Text(
                            item.label,
                            textAlign: TextAlign.center,
                            maxLines: 2,
                            overflow: TextOverflow.ellipsis,
                            style: const TextStyle(
                              color: Color(0xFF68766E),
                              fontSize: 9.5,
                              fontWeight: FontWeight.w700,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                )
                .toList(growable: false),
          );
        },
      );
}

class _LinkedAccountRow extends StatelessWidget {
  const _LinkedAccountRow({
    required this.retailStoreId,
    required this.b2bCustomerId,
    required this.isArabic,
    required this.isLast,
  });

  final String retailStoreId;
  final String b2bCustomerId;
  final bool isArabic;
  final bool isLast;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(vertical: 10),
        decoration: BoxDecoration(
          border: isLast
              ? null
              : const Border(
                  bottom: BorderSide(color: Color(0xFFEEF3F0)),
                ),
        ),
        child: Row(
          children: [
            Expanded(
              child: _MiniValue(
                label: isArabic ? 'متجر التجزئة' : 'Retail store',
                value: retailStoreId.isEmpty ? '—' : retailStoreId,
                icon: Icons.storefront_outlined,
              ),
            ),
            Container(
              width: 1,
              height: 42,
              margin: const EdgeInsets.symmetric(horizontal: 10),
              color: const Color(0xFFDDE8E1),
            ),
            Expanded(
              child: _MiniValue(
                label: isArabic ? 'حساب الجملة' : 'Wholesale account',
                value: b2bCustomerId.isEmpty ? '—' : b2bCustomerId,
                icon: Icons.groups_2_outlined,
              ),
            ),
          ],
        ),
      );
}

class _MiniValue extends StatelessWidget {
  const _MiniValue({
    required this.label,
    required this.value,
    required this.icon,
  });

  final String label;
  final String value;
  final IconData icon;

  @override
  Widget build(BuildContext context) => Row(
        children: [
          Icon(icon, color: const Color(0xFF078A43), size: 18),
          const SizedBox(width: 7),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  label,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                    color: Color(0xFF68766E),
                    fontSize: 9,
                    fontWeight: FontWeight.w700,
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  value,
                  style: const TextStyle(
                    color: Color(0xFF17231D),
                    fontSize: 13,
                    fontWeight: FontWeight.w900,
                  ),
                ),
              ],
            ),
          ),
        ],
      );
}

class _InlineEmpty extends StatelessWidget {
  const _InlineEmpty({required this.text});

  final String text;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: const Color(0xFFF6FAF7),
          borderRadius: BorderRadius.circular(12),
        ),
        child: Text(
          text,
          textAlign: TextAlign.center,
          style: const TextStyle(
            color: Color(0xFF68766E),
            fontSize: 11,
            fontWeight: FontWeight.w700,
          ),
        ),
      );
}

class _ProfileError extends StatelessWidget {
  const _ProfileError({required this.isArabic, required this.onRetry});

  final bool isArabic;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.all(18),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(18),
          border: Border.all(color: const Color(0xFFDDE8E1)),
        ),
        child: Column(
          children: [
            const Icon(Icons.cloud_off_outlined,
                color: Color(0xFF68766E), size: 36),
            const SizedBox(height: 8),
            Text(
              isArabic
                  ? 'تعذر تحميل بيانات الحساب.'
                  : 'Could not load account details.',
            ),
            const SizedBox(height: 10),
            FilledButton.tonalIcon(
              onPressed: onRetry,
              icon: const Icon(Icons.refresh_rounded),
              label: Text(isArabic ? 'إعادة المحاولة' : 'Retry'),
            ),
          ],
        ),
      );
}

class _ProfileEmpty extends StatelessWidget {
  const _ProfileEmpty({required this.isArabic});

  final bool isArabic;

  @override
  Widget build(BuildContext context) => _InlineEmpty(
        text: isArabic
            ? 'لا توجد بيانات حساب متاحة.'
            : 'No account data is available.',
      );
}

List<Object?> _rows(Object? value) =>
    value is List ? value : const <Object?>[];

List<Map<String, dynamic>> _mapRows(Object? value) =>
    (value as List? ?? const <Object>[])
        .whereType<Map>()
        .map((row) => Map<String, dynamic>.from(row))
        .toList(growable: false);

List<int> _ids(Object? value) =>
    (value as List? ?? const <Object>[])
        .map((item) => item is num
            ? item.toInt()
            : int.tryParse(item.toString()) ?? 0)
        .where((id) => id > 0)
        .toList(growable: false);

List<String> _strings(Object? value) =>
    (value as List? ?? const <Object>[])
        .map((item) => item.toString().trim())
        .where((item) => item.isNotEmpty)
        .toList(growable: false);

String _first(Object? first, [
  Object? second,
  Object? third,
  Object? fourth,
]) {
  for (final value in [first, second, third, fourth]) {
    final text = value?.toString().trim() ?? '';
    if (text.isNotEmpty && text.toLowerCase() != 'null') return text;
  }
  return '';
}
