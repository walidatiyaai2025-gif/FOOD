import 'package:flutter/material.dart';

import '../../core/auth/van_session.dart';
import '../../core/theme/foodex_van_theme.dart';
import '../wallet/van_wallet_contract.dart';
import '../commercial/van_commercial_contract.dart';
import '../commercial/van_offers_page.dart';
import '../wallet/van_wallet_page.dart';

class VanFoundationScreen extends StatelessWidget {
  const VanFoundationScreen({
    super.key,
    required this.session,
    required this.onLogout,
    required this.walletRepository,
    required this.commercialRepository,
  });

  final VanSession session;
  final Future<void> Function() onLogout;
  final VanWalletRepository walletRepository;
  final VanCommercialRepository commercialRepository;

  String _text(BuildContext context, String en, String ar) =>
      Localizations.localeOf(context).languageCode == 'ar' ? ar : en;

  @override
  Widget build(BuildContext context) {
    final arabic = Localizations.localeOf(context).languageCode == 'ar';
    final tabs = arabic
        ? const ['نظرة عامة', 'المسارات', 'الزيارات', 'العروض', 'المحفظة']
        : const ['Overview', 'Routes', 'Visits', 'Offers', 'Wallet'];

    return DefaultTabController(
      length: 5,
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
            VanOffersPage(
              session: session,
              commercialRepository: commercialRepository,
              customerRepository: walletRepository,
              onSessionExpired: onLogout,
            ),
            VanWalletPage(
              repository: walletRepository,
              onSessionExpired: onLogout,
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
    return ListView(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      children: [
        DecoratedBox(
          decoration: BoxDecoration(
            color: FoodexVanTokens.surface,
            border: Border.all(color: FoodexVanTokens.border),
            borderRadius: BorderRadius.circular(FoodexVanTokens.cardRadius),
          ),
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Icon(icon, size: 32, color: FoodexVanTokens.green),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        title,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: Theme.of(context).textTheme.titleMedium,
                      ),
                      const SizedBox(height: 4),
                      Text(
                        subtitle,
                        maxLines: 3,
                        overflow: TextOverflow.ellipsis,
                        style: Theme.of(context).textTheme.bodySmall?.copyWith(
                              color: FoodexVanTokens.muted,
                            ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ),
      ],
    );
  }
}
