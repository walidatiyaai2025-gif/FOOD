import 'package:flutter/material.dart';

import '../../core/api/b2b_api.dart';

class B2bBusinessAccountProfile extends StatefulWidget {
  const B2bBusinessAccountProfile({
    required this.api,
    required this.endpoint,
    required this.addressesRoute,
    super.key,
  });

  final B2bApi api;
  final String endpoint;
  final String addressesRoute;

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
            customer['company_name'],
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
              _QuickActions(
                isArabic: isArabic,
                addressesRoute: widget.addressesRoute,
                locale: locale,
                roles: roles,
              ),
              const SizedBox(height: 14),
              _ProfileCard(
                icon: Icons.person_outline_rounded,
                title: isArabic ? 'ملخص الحساب' : 'Account summary',
                subtitle: isArabic
                    ? 'المعلومات الأساسية عن حسابك'
                    : 'Core information about your account',
                children: [
                  _ProfileRow(
                    icon: Icons.badge_outlined,
                    label: isArabic ? 'رقم الحساب' : 'Account ID',
                    value: customerId.isEmpty ? '—' : customerId,
                  ),
                  _ProfileRow(
                    icon: Icons.layers_outlined,
                    label: isArabic ? 'نوع الحساب' : 'Account type',
                    value: typeLabel,
                  ),
                  if (accountStatus.isNotEmpty)
                    _ProfileRow(
                      icon: Icons.verified_user_outlined,
                      label: isArabic ? 'حالة الحساب' : 'Account status',
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
                    icon: Icons.storefront_outlined,
                    label: isArabic ? 'حساب تاجر تجزئة' : 'Retail merchant',
                    value: retailMerchant
                        ? (isArabic ? 'نعم' : 'Yes')
                        : (isArabic ? 'لا' : 'No'),
                    isLast: true,
                  ),
                ],
              ),
              const SizedBox(height: 14),
              _ProfileCard(
                icon: Icons.business_outlined,
                title: isArabic
                    ? 'بيانات الشركة والتواصل'
                    : 'Company & contact',
                subtitle: isArabic
                    ? 'البيانات المسجلة لهذا الحساب'
                    : 'Details registered for this account',
                children: [
                  if (companyName.isNotEmpty)
                    _ProfileRow(
                      icon: Icons.apartment_rounded,
                      label: isArabic ? 'الشركة / الحساب' : 'Company / account',
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
                  if (taxNumber.isNotEmpty)
                    _ProfileRow(
                      icon: Icons.receipt_long_outlined,
                      label: isArabic ? 'الرقم الضريبي' : 'Tax number',
                      value: taxNumber,
                    ),
                  _ProfileRow(
                    icon: Icons.phone_outlined,
                    label: isArabic ? 'الهاتف' : 'Phone',
                    value: phone.isEmpty ? '—' : phone,
                    isLast: true,
                  ),
                ],
              ),
              const SizedBox(height: 14),
              _ProfileCard(
                icon: Icons.hub_outlined,
                title: isArabic
                    ? 'المتاجر والحسابات المرتبطة'
                    : 'Linked stores & accounts',
                subtitle: isArabic
                    ? 'ملخص الارتباطات التجارية لهذا الحساب'
                    : 'Commerce relationships linked to this account',
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
                ],
              ),
              const SizedBox(height: 14),
              _ProfileCard(
                icon: Icons.link_rounded,
                title: isArabic
                    ? 'حسابات الجملة المرتبطة'
                    : 'Linked wholesale accounts',
                subtitle: isArabic
                    ? 'ربط متجر التجزئة بحساب الشراء بالجملة'
                    : 'Retail-store to wholesale-account links',
                children: [
                  if (linkedAccounts.isEmpty)
                    _InlineEmpty(
                      text: isArabic
                          ? 'لا توجد حسابات جملة مرتبطة.'
                          : 'No linked wholesale accounts.',
                    )
                  else
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
              ),
              if (customer.isNotEmpty) ...[
                const SizedBox(height: 14),
                _ProfileCard(
                  icon: Icons.assignment_ind_outlined,
                  title: isArabic ? 'ملف العميل' : 'Customer profile',
                  subtitle: isArabic
                      ? 'الهوية التجارية المستخدمة في الشراء'
                      : 'Commerce identity used for purchasing',
                  children: [
                    _ProfileRow(
                      icon: Icons.badge_outlined,
                      label: isArabic ? 'رقم العميل' : 'Customer ID',
                      value: _first(customer['id'], '—'),
                    ),
                    _ProfileRow(
                      icon: Icons.layers_outlined,
                      label: isArabic ? 'النوع' : 'Type',
                      value: typeLabel,
                    ),
                    _ProfileRow(
                      icon: Icons.store_outlined,
                      label: isArabic ? 'نطاق المتجر' : 'Store scope',
                      value: customerStoreId.isNotEmpty
                          ? customerStoreId
                          : (isArabic ? 'منصة الجملة' : 'Wholesale platform'),
                      isLast: true,
                    ),
                  ],
                ),
              ],
              const SizedBox(height: 14),
              _ProfileCard(
                icon: Icons.info_outline_rounded,
                title: isArabic ? 'الحالة' : 'Status',
                subtitle: isArabic
                    ? 'بياناتك المحفوظة داخل الحساب'
                    : 'Data currently saved in your account',
                children: [
                  Row(
                    children: [
                      Expanded(
                        child: _StatusTile(
                          icon: Icons.location_on_outlined,
                          label: isArabic ? 'العناوين' : 'Addresses',
                          value: addresses.isEmpty
                              ? (isArabic ? 'لا توجد بيانات' : 'No data')
                              : '${addresses.length}',
                          onTap: () => Navigator.of(context)
                              .pushNamed(widget.addressesRoute),
                        ),
                      ),
                      const SizedBox(width: 10),
                      Expanded(
                        child: _StatusTile(
                          icon: Icons.favorite_border_rounded,
                          label: isArabic ? 'المفضلة' : 'Favorites',
                          value: favorites.isEmpty
                              ? (isArabic ? 'لا توجد بيانات' : 'No data')
                              : '${favorites.length}',
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ],
          );
        },
      );
}

class _QuickActions extends StatelessWidget {
  const _QuickActions({
    required this.isArabic,
    required this.addressesRoute,
    required this.locale,
    required this.roles,
  });

  final bool isArabic;
  final String addressesRoute;
  final String locale;
  final List<String> roles;

  @override
  Widget build(BuildContext context) => Wrap(
        spacing: 8,
        runSpacing: 8,
        children: [
          _ActionChip(
            icon: Icons.business_outlined,
            label: isArabic ? 'بيانات الشركة' : 'Company details',
            selected: true,
          ),
          _ActionChip(
            icon: Icons.location_on_outlined,
            label: isArabic ? 'إدارة العناوين' : 'Manage addresses',
            onTap: () => Navigator.of(context).pushNamed(addressesRoute),
          ),
          _ActionChip(
            icon: Icons.settings_outlined,
            label: isArabic ? 'الإعدادات' : 'Settings',
            onTap: () => showModalBottomSheet<void>(
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
            ),
          ),
        ],
      );
}

class _ActionChip extends StatelessWidget {
  const _ActionChip({
    required this.icon,
    required this.label,
    this.selected = false,
    this.onTap,
  });

  final IconData icon;
  final String label;
  final bool selected;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) => Material(
        color: selected ? const Color(0xFF005B3E) : Colors.white,
        borderRadius: BorderRadius.circular(14),
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(14),
          child: Container(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 11),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(14),
              border: Border.all(
                color: selected
                    ? const Color(0xFF005B3E)
                    : const Color(0xFFDDE8E1),
              ),
            ),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Icon(
                  icon,
                  size: 18,
                  color: selected ? Colors.white : const Color(0xFF005B3E),
                ),
                const SizedBox(width: 7),
                Text(
                  label,
                  style: TextStyle(
                    color: selected ? Colors.white : const Color(0xFF17231D),
                    fontWeight: FontWeight.w800,
                    fontSize: 12,
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

class _StatusTile extends StatelessWidget {
  const _StatusTile({
    required this.icon,
    required this.label,
    required this.value,
    this.onTap,
  });

  final IconData icon;
  final String label;
  final String value;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) => Material(
        color: const Color(0xFFF6FAF7),
        borderRadius: BorderRadius.circular(14),
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(14),
          child: Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(14),
              border: Border.all(color: const Color(0xFFE2ECE6)),
            ),
            child: Column(
              children: [
                Icon(icon, color: const Color(0xFF078A43), size: 24),
                const SizedBox(height: 6),
                Text(
                  label,
                  style: const TextStyle(
                    color: Color(0xFF17231D),
                    fontWeight: FontWeight.w900,
                    fontSize: 11,
                  ),
                ),
                const SizedBox(height: 4),
                Text(
                  value,
                  textAlign: TextAlign.center,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                    color: Color(0xFF68766E),
                    fontSize: 9.5,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ],
            ),
          ),
        ),
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
