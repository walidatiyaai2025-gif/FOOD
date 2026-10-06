import 'package:flutter/material.dart';

import '../../core/auth/van_session.dart';
import '../../core/theme/foodex_van_theme.dart';

class VanFoundationScreen extends StatelessWidget {
  const VanFoundationScreen({
    super.key,
    required this.session,
    required this.onLogout,
  });

  final VanSession session;
  final Future<void> Function() onLogout;

  String _text(BuildContext context, String en, String ar) =>
      Localizations.localeOf(context).languageCode == 'ar' ? ar : en;

  @override
  Widget build(BuildContext context) {
    final arabic = Localizations.localeOf(context).languageCode == 'ar';
    final tabs = arabic
        ? const ['نظرة عامة', 'المسارات', 'الزيارات', 'المحفظة']
        : const ['Overview', 'Routes', 'Visits', 'Wallet'];

    return DefaultTabController(
      length: 4,
      child: Scaffold(
        appBar: AppBar(
          title: const Text('FOODEX Van'),
          actions: [
            Center(
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 8),
                child: Text(
                  session.name,
                  key: const Key('van-session-name'),
                  overflow: TextOverflow.ellipsis,
                ),
              ),
            ),
            IconButton(
              key: const Key('van-logout'),
              tooltip: _text(context, 'Sign out', 'تسجيل الخروج'),
              onPressed: onLogout,
              icon: const Icon(Icons.logout),
            ),
          ],
          bottom: TabBar(
            isScrollable: true,
            tabs: tabs.map((label) => Tab(text: label)).toList(growable: false),
          ),
        ),
        body: TabBarView(
          children: [
            _FoundationState(
              icon: Icons.local_shipping_outlined,
              title: _text(context, 'Van foundation ready', 'تطبيق سيارة البيع جاهز'),
              subtitle: _text(
                context,
                'Operational modules plug into this authenticated shell without duplicating business domains.',
                'تعمل الوحدات التشغيلية داخل جلسة مصادق عليها دون تكرار منطق الأعمال.',
              ),
            ),
            _FoundationState(
              icon: Icons.route_outlined,
              title: tabs[1],
              subtitle: _text(
                context,
                'Routing remains backend-authoritative and configuration-driven.',
                'تظل سياسات المسارات معتمدة من الخادم وقابلة للتهيئة.',
              ),
            ),
            _FoundationState(
              icon: Icons.people_outline,
              title: tabs[2],
              subtitle: _text(
                context,
                'Field visits and order capture reuse canonical customer and commerce APIs.',
                'تعيد الزيارات والطلبات استخدام واجهات العملاء والتجارة المعتمدة.',
              ),
            ),
            _FoundationState(
              icon: Icons.account_balance_wallet_outlined,
              title: tabs[3],
              subtitle: _text(
                context,
                'Collections and remittances reuse the shared custody domain.',
                'تستخدم التحصيلات والتوريدات نطاق العهدة المالي المشترك.',
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _FoundationState extends StatelessWidget {
  const _FoundationState({
    required this.icon,
    required this.title,
    required this.subtitle,
  });

  final IconData icon;
  final String title;
  final String subtitle;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 520),
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: DecoratedBox(
            decoration: BoxDecoration(
              color: FoodexVanTokens.surface,
              border: Border.all(color: FoodexVanTokens.border),
              borderRadius: BorderRadius.circular(FoodexVanTokens.cardRadius),
            ),
            child: Padding(
              padding: const EdgeInsets.all(24),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Icon(icon, size: 40, color: FoodexVanTokens.green),
                  const SizedBox(height: 16),
                  Text(title, style: Theme.of(context).textTheme.titleLarge),
                  const SizedBox(height: 8),
                  Text(
                    subtitle,
                    textAlign: TextAlign.center,
                    style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                          color: FoodexVanTokens.muted,
                        ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
