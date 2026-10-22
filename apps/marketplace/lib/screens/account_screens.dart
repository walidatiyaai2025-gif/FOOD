import 'package:flutter/material.dart';

import '../core/app_state.dart';
import '../core/foodex_theme.dart';
import '../data/mock_data.dart';
import '../widgets/foodex_widgets.dart';

class OffersScreen extends StatelessWidget {
  const OffersScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return FoodexPageScaffold(
      canPop: true,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 28),
        children: [
          Text('العروض', style: Theme.of(context).textTheme.headlineMedium),
          const SizedBox(height: 12),
          const Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              Chip(label: Text('الكل')),
              Chip(label: Text('مطاعم')),
              Chip(label: Text('وجبات')),
              Chip(label: Text('توصيل مجاني')),
            ],
          ),
          const SizedBox(height: 14),
          _OfferCard(title: 'خصم 20% على وجبات برجر هاوس', subtitle: 'على وجبات مختارة • حتى 50 جنيه', imageUrl: 'https://images.unsplash.com/photo-1568901346375-23c9450c58cd?auto=format&fit=crop&w=1200&q=85'),
          const SizedBox(height: 12),
          _OfferCard(title: 'خصم 15% على البيتزا الكبيرة', subtitle: 'بيتزا روما • اليوم فقط', imageUrl: 'https://images.unsplash.com/photo-1565299624946-b28f40a0ae38?auto=format&fit=crop&w=1200&q=85'),
          const SizedBox(height: 12),
          _OfferCard(title: 'توصيل مجاني', subtitle: 'على أول طلب من المطاعم المشاركة', imageUrl: 'https://images.unsplash.com/photo-1526367790999-0150786686a2?auto=format&fit=crop&w=1200&q=85'),
        ],
      ),
    );
  }
}

class NotificationsScreen extends StatelessWidget {
  const NotificationsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final items = [
      (Icons.receipt_long_rounded, 'طلبك بيتجهز', 'برجر هاوس بدأ تجهيز طلب #FX123456', 'منذ دقيقتين'),
      (Icons.local_offer_rounded, 'عرض جديد ليك', 'خصم 20% على وجبات مختارة اليوم', 'منذ ساعة'),
      (Icons.delivery_dining_rounded, 'السائق في الطريق', 'تابع طلبك لحظة بلحظة من شاشة الطلب', 'أمس'),
    ];
    return FoodexPageScaffold(
      canPop: true,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 28),
        children: [
          Text('الإشعارات', style: Theme.of(context).textTheme.headlineMedium),
          const SizedBox(height: 14),
          ...items.map((item) => Card(
                margin: const EdgeInsets.only(bottom: 10),
                child: ListTile(
                  contentPadding: const EdgeInsets.all(12),
                  leading: CircleAvatar(backgroundColor: FoodexColors.limeSoft, child: Icon(item.$1, color: FoodexColors.deepGreen)),
                  title: Text(item.$2, style: const TextStyle(fontWeight: FontWeight.w800)),
                  subtitle: Padding(padding: const EdgeInsets.only(top: 4), child: Text(item.$3)),
                  trailing: Text(item.$4, style: Theme.of(context).textTheme.bodySmall?.copyWith(fontSize: 9)),
                ),
              )),
        ],
      ),
    );
  }
}

class FavoritesScreen extends StatelessWidget {
  const FavoritesScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final favorites = restaurants.where((r) => appState.favoriteRestaurants.contains(r.id)).toList();
    return AnimatedBuilder(
      animation: appState,
      builder: (context, _) => FoodexPageScaffold(
        canPop: true,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(16, 16, 16, 28),
          children: [
            Text('المفضلة', style: Theme.of(context).textTheme.headlineMedium),
            const SizedBox(height: 14),
            if (favorites.isEmpty)
              const Padding(padding: EdgeInsets.all(40), child: Center(child: Text('لسه ما ضفتش مطاعم للمفضلة.')))
            else
              ...favorites.map((restaurant) => Padding(
                    padding: const EdgeInsets.only(bottom: 11),
                    child: RestaurantCard(restaurant: restaurant, compact: true, onTap: () => Navigator.pushNamed(context, '/restaurant', arguments: restaurant)),
                  )),
          ],
        ),
      ),
    );
  }
}

class ProfileScreen extends StatelessWidget {
  const ProfileScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: appState,
      builder: (context, _) => FoodexPageScaffold(
        navIndex: 4,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(16, 16, 16, 28),
          children: [
            Text('المزيد', style: Theme.of(context).textTheme.headlineMedium),
            const SizedBox(height: 14),
            if (appState.isLoggedIn)
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(14),
                  child: Row(
                    children: [
                      const CircleAvatar(radius: 30, backgroundColor: FoodexColors.limeSoft, child: Icon(Icons.person_rounded, size: 32, color: FoodexColors.deepGreen)),
                      const SizedBox(width: 12),
                      Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text('محمد أحمد', style: Theme.of(context).textTheme.titleMedium), const SizedBox(height: 3), Text('0100 123 4567', style: Theme.of(context).textTheme.bodySmall)])),
                      const Icon(Icons.chevron_left_rounded, color: FoodexColors.muted),
                    ],
                  ),
                ),
              )
            else
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('أهلًا بيك في Foodx', style: Theme.of(context).textTheme.titleLarge),
                      const SizedBox(height: 6),
                      const Text('سجّل الدخول علشان تكمل الطلب وتتابع طلباتك ومفضلاتك.'),
                      const SizedBox(height: 12),
                      FilledButton(onPressed: appState.toggleLogin, child: const Text('تسجيل الدخول التجريبي')),
                    ],
                  ),
                ),
              ),
            const SizedBox(height: 12),
            _MenuTile(icon: Icons.favorite_border_rounded, title: 'المفضلة', onTap: () => Navigator.pushNamed(context, '/favorites')),
            _MenuTile(icon: Icons.location_on_outlined, title: 'العناوين', onTap: () => _info(context, 'العناوين', 'واجهة Mock فقط — لاحقًا تتوصل بعناوين العميل في Foodx.')),
            _MenuTile(icon: Icons.notifications_none_rounded, title: 'الإشعارات', onTap: () => Navigator.pushNamed(context, '/notifications')),
            _MenuTile(icon: Icons.language_rounded, title: 'اللغة', subtitle: 'العربية', onTap: () => _info(context, 'اللغة', 'في النسخة النهائية يتم ربطها مع AR/EN الحالية في Foodx.')),
            _MenuTile(icon: Icons.dark_mode_outlined, title: 'الوضع الداكن', subtitle: 'قريبًا في الموكاب', onTap: () => _info(context, 'الوضع الداكن', 'الـmockup الحالي Light فقط، لكن الـcomponents قابلة للربط بالـDark Theme الحالي.')),
            _MenuTile(icon: Icons.support_agent_rounded, title: 'اتصل بنا', onTap: () => _info(context, 'اتصل بنا', 'الفورم النهائي يتوصل بـCRM Cases كما هو مخطط في Foodx.')),
            const SizedBox(height: 10),
            if (appState.isLoggedIn)
              OutlinedButton.icon(onPressed: appState.toggleLogin, icon: const Icon(Icons.logout_rounded), label: const Text('تسجيل الخروج التجريبي')),
          ],
        ),
      ),
    );
  }
}

class _MenuTile extends StatelessWidget {
  const _MenuTile({required this.icon, required this.title, required this.onTap, this.subtitle});
  final IconData icon;
  final String title;
  final String? subtitle;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => Card(
        margin: const EdgeInsets.only(bottom: 9),
        child: ListTile(
          leading: CircleAvatar(backgroundColor: FoodexColors.mint, child: Icon(icon, color: FoodexColors.deepGreen)),
          title: Text(title, style: const TextStyle(fontWeight: FontWeight.w800)),
          subtitle: subtitle == null ? null : Text(subtitle!),
          trailing: const Icon(Icons.chevron_left_rounded),
          onTap: onTap,
        ),
      );
}

class _OfferCard extends StatelessWidget {
  const _OfferCard({required this.title, required this.subtitle, required this.imageUrl});
  final String title;
  final String subtitle;
  final String imageUrl;

  @override
  Widget build(BuildContext context) => Container(
        height: 160,
        clipBehavior: Clip.antiAlias,
        decoration: BoxDecoration(borderRadius: BorderRadius.circular(20), border: Border.all(color: FoodexColors.border)),
        child: Stack(
          fit: StackFit.expand,
          children: [
            Image.network(imageUrl, fit: BoxFit.cover, errorBuilder: (_, __, ___) => const ColoredBox(color: FoodexColors.deepGreen)),
            const DecoratedBox(decoration: BoxDecoration(gradient: LinearGradient(begin: AlignmentDirectional.centerStart, end: AlignmentDirectional.centerEnd, colors: [Color(0xED003C2A), Color(0x95003C2A), Colors.transparent]))),
            Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Container(padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 5), decoration: BoxDecoration(color: FoodexColors.lime, borderRadius: BorderRadius.circular(999)), child: const Text('FOODEX OFFER', style: TextStyle(fontSize: 9, fontWeight: FontWeight.w900, color: FoodexColors.deepGreenStrong))),
                  const SizedBox(height: 9),
                  SizedBox(width: 240, child: Text(title, style: const TextStyle(fontSize: 18, color: Colors.white, fontWeight: FontWeight.w900))),
                  const SizedBox(height: 4),
                  SizedBox(width: 240, child: Text(subtitle, style: const TextStyle(fontSize: 10.5, color: Color(0xE6FFFFFF)))),
                  const SizedBox(height: 10),
                  SizedBox(width: 110, height: 34, child: FilledButton(style: FilledButton.styleFrom(backgroundColor: Colors.white, foregroundColor: FoodexColors.deepGreen, padding: EdgeInsets.zero), onPressed: () => Navigator.pushNamed(context, '/restaurants'), child: const Text('اطلب الآن'))),
                ],
              ),
            ),
          ],
        ),
      );
}

void _info(BuildContext context, String title, String body) {
  showDialog<void>(context: context, builder: (_) => AlertDialog(title: Text(title), content: Text(body), actions: [TextButton(onPressed: () => Navigator.pop(context), child: const Text('تمام'))]));
}
