import 'package:flutter/material.dart';

import '../../core/auth/van_session.dart';
import '../../core/theme/foodex_van_theme.dart';

class VanProfilePage extends StatelessWidget {
  const VanProfilePage({
    super.key,
    required this.session,
    required this.onLogout,
  });

  final VanSession session;
  final Future<void> Function() onLogout;

  bool _arabic(BuildContext context) =>
      Localizations.localeOf(context).languageCode == 'ar';

  String _text(BuildContext context, String en, String ar) =>
      _arabic(context) ? ar : en;

  @override
  Widget build(BuildContext context) {
    final permissions = session.permissions.toList()..sort();

    return ListView(
      key: const ValueKey('van-profile-page'),
      padding: const EdgeInsets.fromLTRB(12, 10, 12, 24),
      children: [
        Card(
          elevation: 0,
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const CircleAvatar(
                  radius: 26,
                  backgroundColor: FoodexVanTokens.mint,
                  child: Icon(
                    Icons.badge_outlined,
                    color: FoodexVanTokens.green,
                    size: 28,
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        session.name,
                        key: const ValueKey('van-profile-name'),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: Theme.of(context).textTheme.titleLarge?.copyWith(
                              fontWeight: FontWeight.w900,
                            ),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        session.email,
                        key: const ValueKey('van-profile-email'),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ),
        const SizedBox(height: 10),
        Card(
          elevation: 0,
          child: Column(
            children: [
              ListTile(
                leading: const Icon(Icons.language_outlined),
                title: Text(_text(context, 'Session locale', 'لغة الجلسة')),
                trailing: Text(
                  session.locale.toUpperCase(),
                  key: const ValueKey('van-profile-locale'),
                  style: const TextStyle(fontWeight: FontWeight.w800),
                ),
              ),
              const Divider(height: 1),
              ListTile(
                leading: const Icon(Icons.verified_user_outlined),
                title: Text(
                  _text(context, 'Authorized permissions', 'الصلاحيات المصرح بها'),
                ),
                trailing: Text(
                  '${permissions.length}',
                  key: const ValueKey('van-profile-permission-count'),
                  style: const TextStyle(fontWeight: FontWeight.w800),
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: 10),
        Card(
          elevation: 0,
          child: ExpansionTile(
            key: const ValueKey('van-profile-permissions'),
            leading: const Icon(Icons.admin_panel_settings_outlined),
            title: Text(
              _text(context, 'Access scope', 'نطاق الصلاحيات'),
              style: const TextStyle(fontWeight: FontWeight.w800),
            ),
            subtitle: Text(
              _text(
                context,
                'Server-authoritative session permissions',
                'صلاحيات الجلسة المعتمدة من الخادم',
              ),
            ),
            children: [
              if (permissions.isEmpty)
                Padding(
                  padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
                  child: Text(
                    _text(
                      context,
                      'No additional permissions are present in this session.',
                      'لا توجد صلاحيات إضافية في هذه الجلسة.',
                    ),
                  ),
                )
              else
                for (final permission in permissions)
                  ListTile(
                    dense: true,
                    leading: const Icon(Icons.check_circle_outline, size: 18),
                    title: Text(
                      permission,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
            ],
          ),
        ),
        const SizedBox(height: 10),
        Card(
          elevation: 0,
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Icon(
                  Icons.security_outlined,
                  color: FoodexVanTokens.green,
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: Text(
                    _text(
                      context,
                      'Authentication persistence, Remember Me and biometrics are managed by the secure Van sign-in flow. This screen does not expose or store the access token.',
                      'تتم إدارة حفظ تسجيل الدخول وخيار تذكرني والبصمة من خلال مسار دخول الفان الآمن. هذه الشاشة لا تعرض رمز الوصول ولا تخزنه.',
                    ),
                  ),
                ),
              ],
            ),
          ),
        ),
        const SizedBox(height: 14),
        FilledButton.icon(
          key: const ValueKey('van-profile-logout'),
          onPressed: onLogout,
          icon: const Icon(Icons.logout),
          label: Text(_text(context, 'Sign out', 'تسجيل الخروج')),
        ),
      ],
    );
  }
}
