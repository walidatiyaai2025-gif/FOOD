import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';

void main() => runApp(const VanMockupApp());

class VanMockupApp extends StatefulWidget {
  const VanMockupApp({super.key});
  @override
  State<VanMockupApp> createState() => _VanMockupAppState();
}

class _VanMockupAppState extends State<VanMockupApp> {
  bool arabic = true;

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      debugShowCheckedModeBanner: false,
      title: arabic ? 'فودكس - موكاب الفان' : 'FOODEX Van Mockup',
      locale: Locale(arabic ? 'ar' : 'en'),
      supportedLocales: const [Locale('ar'), Locale('en')],
      localizationsDelegates: const [
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      theme: FoodexTheme.light(),
      home: GalleryScreen(
        arabic: arabic,
        onLanguage: () => setState(() => arabic = !arabic),
      ),
    );
  }
}

abstract final class C {
  static const green = Color(0xFF147A3D);
  static const greenDark = Color(0xFF0E5D2E);
  static const mint = Color(0xFFEAF6EF);
  static const bg = Color(0xFFF4F7F5);
  static const ink = Color(0xFF1D2733);
  static const muted = Color(0xFF6B7280);
  static const border = Color(0xFFE1E7E3);
  static const orange = Color(0xFFF59E0B);
  static const blue = Color(0xFF2F6FED);
  static const red = Color(0xFFDC3545);
}

abstract final class FoodexTheme {
  static ThemeData light() {
    final scheme = ColorScheme.fromSeed(
      seedColor: C.green,
      primary: C.green,
      brightness: Brightness.light,
    );
    return ThemeData(
      useMaterial3: true,
      colorScheme: scheme,
      scaffoldBackgroundColor: C.bg,
      fontFamilyFallback: const ['Arial', 'sans-serif'],
      appBarTheme: const AppBarTheme(
        backgroundColor: Colors.white,
        foregroundColor: C.ink,
        elevation: 0,
        surfaceTintColor: Colors.transparent,
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: Colors.white,
        contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 13),
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(14),
          borderSide: const BorderSide(color: C.border),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(14),
          borderSide: const BorderSide(color: C.border),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(14),
          borderSide: const BorderSide(color: C.green, width: 1.4),
        ),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          backgroundColor: C.green,
          foregroundColor: Colors.white,
          minimumSize: const Size(0, 50),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
          textStyle: const TextStyle(fontWeight: FontWeight.w800),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          foregroundColor: C.greenDark,
          minimumSize: const Size(0, 48),
          side: const BorderSide(color: C.border),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
        ),
      ),
      chipTheme: ChipThemeData(
        backgroundColor: Colors.white,
        selectedColor: C.mint,
        side: const BorderSide(color: C.border),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
        labelStyle: const TextStyle(fontWeight: FontWeight.w700),
      ),
    );
  }
}

bool ar(BuildContext context) =>
    Localizations.localeOf(context).languageCode == 'ar';

String tx(BuildContext context, String en, String arabic) =>
    ar(context) ? arabic : en;

enum MockScreen {
  login,
  dashboard,
  routes,
  routeMap,
  routeDetail,
  customers,
  visit,
  customer360,
  catalog,
  orderBuilder,
  orderReview,
  orders,
  offers,
  wallet,
  collection,
  receipt,
  remittance,
  notifications,
  profile,
}

extension MockMeta on MockScreen {
  String en() {
    switch (this) {
      case MockScreen.login: return 'Login';
      case MockScreen.dashboard: return 'Home Dashboard';
      case MockScreen.routes: return 'Routes';
      case MockScreen.routeMap: return 'Route Map';
      case MockScreen.routeDetail: return 'Route Detail';
      case MockScreen.customers: return 'Customers';
      case MockScreen.visit: return 'Visit Workspace';
      case MockScreen.customer360: return 'Customer 360';
      case MockScreen.catalog: return 'Product Catalog';
      case MockScreen.orderBuilder: return 'Order Builder';
      case MockScreen.orderReview: return 'Order Review';
      case MockScreen.orders: return 'Orders';
      case MockScreen.offers: return 'Offers';
      case MockScreen.wallet: return 'Wallet';
      case MockScreen.collection: return 'Collection';
      case MockScreen.receipt: return 'Receipt';
      case MockScreen.remittance: return 'Remittance';
      case MockScreen.notifications: return 'Notifications';
      case MockScreen.profile: return 'Profile & Settings';
    }
  }

  String arabic() {
    switch (this) {
      case MockScreen.login: return 'تسجيل الدخول';
      case MockScreen.dashboard: return 'الرئيسية';
      case MockScreen.routes: return 'المسارات';
      case MockScreen.routeMap: return 'خريطة المسار';
      case MockScreen.routeDetail: return 'تفاصيل المسار';
      case MockScreen.customers: return 'العملاء';
      case MockScreen.visit: return 'مساحة الزيارة';
      case MockScreen.customer360: return 'ملف العميل';
      case MockScreen.catalog: return 'كتالوج المنتجات';
      case MockScreen.orderBuilder: return 'إنشاء طلب';
      case MockScreen.orderReview: return 'مراجعة الطلب';
      case MockScreen.orders: return 'الطلبات';
      case MockScreen.offers: return 'العروض';
      case MockScreen.wallet: return 'المحفظة';
      case MockScreen.collection: return 'التحصيل';
      case MockScreen.receipt: return 'الإيصال';
      case MockScreen.remittance: return 'التوريد';
      case MockScreen.notifications: return 'الإشعارات';
      case MockScreen.profile: return 'الملف والإعدادات';
    }
  }

  IconData icon() {
    switch (this) {
      case MockScreen.login: return Icons.lock_outline;
      case MockScreen.dashboard: return Icons.dashboard_outlined;
      case MockScreen.routes: return Icons.route_outlined;
      case MockScreen.routeMap: return Icons.map_outlined;
      case MockScreen.routeDetail: return Icons.alt_route_outlined;
      case MockScreen.customers: return Icons.storefront_outlined;
      case MockScreen.visit: return Icons.fact_check_outlined;
      case MockScreen.customer360: return Icons.account_circle_outlined;
      case MockScreen.catalog: return Icons.inventory_2_outlined;
      case MockScreen.orderBuilder: return Icons.add_shopping_cart_outlined;
      case MockScreen.orderReview: return Icons.receipt_long_outlined;
      case MockScreen.orders: return Icons.list_alt_outlined;
      case MockScreen.offers: return Icons.local_offer_outlined;
      case MockScreen.wallet: return Icons.account_balance_wallet_outlined;
      case MockScreen.collection: return Icons.payments_outlined;
      case MockScreen.receipt: return Icons.receipt_outlined;
      case MockScreen.remittance: return Icons.account_balance_outlined;
      case MockScreen.notifications: return Icons.notifications_none_outlined;
      case MockScreen.profile: return Icons.settings_outlined;
    }
  }
}

void open(BuildContext context, MockScreen screen) {
  Navigator.of(context).push(
    MaterialPageRoute(builder: (_) => MockPage(screen: screen)),
  );
}

class GalleryScreen extends StatelessWidget {
  const GalleryScreen({
    super.key,
    required this.arabic,
    required this.onLanguage,
  });

  final bool arabic;
  final VoidCallback onLanguage;

  @override
  Widget build(BuildContext context) {
    final groups = <String, List<MockScreen>>{
      tx(context, 'Access & Home', 'الدخول والرئيسية'): [
        MockScreen.login,
        MockScreen.dashboard,
        MockScreen.notifications,
        MockScreen.profile,
      ],
      tx(context, 'Route & Field Work', 'المسار والعمل الميداني'): [
        MockScreen.routes,
        MockScreen.routeMap,
        MockScreen.routeDetail,
        MockScreen.customers,
        MockScreen.visit,
        MockScreen.customer360,
      ],
      tx(context, 'Sales', 'المبيعات'): [
        MockScreen.catalog,
        MockScreen.orderBuilder,
        MockScreen.orderReview,
        MockScreen.orders,
        MockScreen.offers,
      ],
      tx(context, 'Money', 'الماليات'): [
        MockScreen.wallet,
        MockScreen.collection,
        MockScreen.receipt,
        MockScreen.remittance,
      ],
    };

    return Scaffold(
      appBar: AppBar(
        title: const Brand(compact: true),
        actions: [
          TextButton.icon(
            onPressed: onLanguage,
            icon: const Icon(Icons.language),
            label: Text(arabic ? 'EN' : 'عربي'),
          ),
          const SizedBox(width: 6),
        ],
      ),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 10, 16, 32),
        children: [
          const DesignOnlyBanner(),
          const SizedBox(height: 12),
          HeroMockCard(
            onStart: () => open(context, MockScreen.login),
          ),
          const SizedBox(height: 20),
          ...groups.entries.expand((entry) sync* {
            yield SectionTitle(entry.key);
            yield const SizedBox(height: 8);
            yield LayoutBuilder(
              builder: (context, constraints) {
                final two = constraints.maxWidth > 520;
                final width = two ? (constraints.maxWidth - 10) / 2 : constraints.maxWidth;
                return Wrap(
                  spacing: 10,
                  runSpacing: 10,
                  children: entry.value.map((screen) {
                    return SizedBox(
                      width: width,
                      child: Panel(
                        onTap: () => open(context, screen),
                        child: Row(
                          children: [
                            CircleAvatar(
                              backgroundColor: C.mint,
                              child: Icon(screen.icon(), color: C.green),
                            ),
                            const SizedBox(width: 10),
                            Expanded(
                              child: Text(
                                ar(context) ? screen.arabic() : screen.en(),
                                style: const TextStyle(fontWeight: FontWeight.w800),
                              ),
                            ),
                            const Icon(Icons.chevron_right, color: C.muted),
                          ],
                        ),
                      ),
                    );
                  }).toList(),
                );
              },
            );
            yield const SizedBox(height: 20);
          }),
        ],
      ),
    );
  }
}

class MockPage extends StatelessWidget {
  const MockPage({super.key, required this.screen});
  final MockScreen screen;

  @override
  Widget build(BuildContext context) {
    if (screen == MockScreen.login) return const LoginMock();
    if (screen == MockScreen.receipt) return const ReceiptMock();

    return Scaffold(
      appBar: AppBar(
        title: Text(
          ar(context) ? screen.arabic() : screen.en(),
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w900),
        ),
        actions: const [
          Padding(
            padding: EdgeInsetsDirectional.only(end: 10),
            child: Center(child: MockBadge()),
          ),
        ],
      ),
      body: _buildBody(context),
      bottomNavigationBar: _showBottom() ? AppBottom(current: screen) : null,
    );
  }

  bool _showBottom() => {
    MockScreen.dashboard,
    MockScreen.routes,
    MockScreen.catalog,
    MockScreen.orders,
    MockScreen.wallet,
    MockScreen.notifications,
    MockScreen.profile,
  }.contains(screen);

  Widget _buildBody(BuildContext context) {
    switch (screen) {
      case MockScreen.dashboard: return const DashboardMock();
      case MockScreen.routes: return const RoutesMock();
      case MockScreen.routeMap: return const RouteMapMock();
      case MockScreen.routeDetail: return const RouteDetailMock();
      case MockScreen.customers: return const CustomersMock();
      case MockScreen.visit: return const VisitMock();
      case MockScreen.customer360: return const Customer360Mock();
      case MockScreen.catalog: return const CatalogMock();
      case MockScreen.orderBuilder: return const OrderBuilderMock();
      case MockScreen.orderReview: return const OrderReviewMock();
      case MockScreen.orders: return const OrdersMock();
      case MockScreen.offers: return const OffersMock();
      case MockScreen.wallet: return const WalletMock();
      case MockScreen.collection: return const CollectionMock();
      case MockScreen.remittance: return const RemittanceMock();
      case MockScreen.notifications: return const NotificationsMock();
      case MockScreen.profile: return const ProfileMock();
      case MockScreen.login:
      case MockScreen.receipt:
        return const SizedBox.shrink();
    }
  }
}

class LoginMock extends StatefulWidget {
  const LoginMock({super.key});
  @override
  State<LoginMock> createState() => _LoginMockState();
}

class _LoginMockState extends State<LoginMock> {
  bool remember = true;
  bool biometric = true;
  bool hide = true;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.fromLTRB(20, 28, 20, 24),
          children: [
            const Row(
              children: [Brand(), Spacer(), MockBadge()],
            ),
            const SizedBox(height: 28),
            Container(
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                color: C.mint,
                borderRadius: BorderRadius.circular(20),
              ),
              child: Row(
                children: [
                  Container(
                    width: 54,
                    height: 54,
                    decoration: BoxDecoration(
                      color: C.green,
                      borderRadius: BorderRadius.circular(16),
                    ),
                    child: const Icon(Icons.local_shipping_rounded, color: Colors.white, size: 30),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          tx(context, 'Van App', 'تطبيق الفان'),
                          style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w900),
                        ),
                        Text(
                          tx(context, 'Field sales & delivery workspace', 'مساحة البيع والتوزيع الميداني'),
                          style: const TextStyle(color: C.muted),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 26),
            Text(
              tx(context, 'Welcome back', 'مرحباً بعودتك'),
              style: const TextStyle(fontSize: 28, fontWeight: FontWeight.w900),
            ),
            const SizedBox(height: 5),
            Text(
              tx(context, 'Sign in to start today’s route.', 'سجّل الدخول لبدء مسار اليوم.'),
              style: const TextStyle(color: C.muted),
            ),
            const SizedBox(height: 20),
            TextField(
              decoration: InputDecoration(
                labelText: tx(context, 'Email or mobile', 'البريد أو رقم الهاتف'),
                prefixIcon: const Icon(Icons.person_outline),
              ),
            ),
            const SizedBox(height: 12),
            TextField(
              obscureText: hide,
              decoration: InputDecoration(
                labelText: tx(context, 'Password', 'كلمة المرور'),
                prefixIcon: const Icon(Icons.lock_outline),
                suffixIcon: IconButton(
                  onPressed: () => setState(() => hide = !hide),
                  icon: Icon(hide ? Icons.visibility_outlined : Icons.visibility_off_outlined),
                ),
              ),
            ),
            const SizedBox(height: 6),
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              value: remember,
              activeThumbColor: C.green,
              onChanged: (v) => setState(() => remember = v),
              title: Text(tx(context, 'Remember me', 'تذكرني'), style: const TextStyle(fontWeight: FontWeight.w800)),
              subtitle: Text(tx(context, 'Keep this device signed in securely', 'احتفظ بتسجيل الدخول بشكل آمن')),
            ),
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              value: biometric,
              activeThumbColor: C.green,
              onChanged: remember ? (v) => setState(() => biometric = v) : null,
              title: Text(tx(context, 'Biometric unlock', 'فتح بالبصمة'), style: const TextStyle(fontWeight: FontWeight.w800)),
              subtitle: Text(tx(context, 'Fingerprint or face unlock', 'بصمة الإصبع أو الوجه')),
            ),
            const SizedBox(height: 8),
            FilledButton.icon(
              onPressed: () => open(context, MockScreen.dashboard),
              icon: const Icon(Icons.login),
              label: Text(tx(context, 'Sign in', 'تسجيل الدخول')),
            ),
            const SizedBox(height: 10),
            OutlinedButton.icon(
              onPressed: () => open(context, MockScreen.dashboard),
              icon: const Icon(Icons.fingerprint),
              label: Text(tx(context, 'Unlock with biometrics', 'فتح بالبصمة')),
            ),
            const SizedBox(height: 18),
            Text(
              tx(context, 'Design prototype only • Any credentials work visually', 'نموذج تصميم فقط • أي بيانات دخول تعمل بصرياً'),
              textAlign: TextAlign.center,
              style: const TextStyle(color: C.muted, fontSize: 12, fontWeight: FontWeight.w600),
            ),
          ],
        ),
      ),
    );
  }
}

class DashboardMock extends StatelessWidget {
  const DashboardMock({super.key});

  @override
  Widget build(BuildContext context) {
    return ScrollBody(children: [
      Row(
        children: [
          const CircleAvatar(backgroundColor: C.mint, child: Icon(Icons.person_outline, color: C.green)),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(tx(context, 'Good morning, Ahmed', 'صباح الخير، أحمد'), style: const TextStyle(fontSize: 19, fontWeight: FontWeight.w900)),
                Text(tx(context, 'Wednesday • Route R-024 • Van V-12', 'الأربعاء • المسار R-024 • الفان V-12'), maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(color: C.muted, fontSize: 12)),
              ],
            ),
          ),
        ],
      ),
      const SizedBox(height: 12),
      const Row(
        children: [
          Expanded(child: Metric(icon: Icons.store_mall_directory_outlined, value: '14', en: 'Stops', ar: 'زيارة')),
          SizedBox(width: 8),
          Expanded(child: Metric(icon: Icons.check_circle_outline, value: '5', en: 'Done', ar: 'تمت')),
          SizedBox(width: 8),
          Expanded(child: Metric(icon: Icons.payments_outlined, value: '128.5', en: 'Collected', ar: 'تحصيل')),
        ],
      ),
      const SizedBox(height: 14),
      SectionTitle(tx(context, 'Today’s route', 'مسار اليوم')),
      const SizedBox(height: 8),
      Panel(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                const Icon(Icons.route, color: C.green),
                const SizedBox(width: 8),
                Expanded(child: Text(tx(context, 'Al-Rai North • R-024', 'الري الشمالي • R-024'), style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 16))),
                const Pill(en: 'ACTIVE', ar: 'نشط', color: C.green),
              ],
            ),
            const SizedBox(height: 12),
            LinearProgressIndicator(value: 5 / 14, minHeight: 8, borderRadius: BorderRadius.circular(99), color: C.green, backgroundColor: C.mint),
            const SizedBox(height: 7),
            Text(tx(context, '5 of 14 stops completed • 9 remaining', 'تمت 5 من 14 زيارة • 9 متبقية'), style: const TextStyle(color: C.muted)),
            const SizedBox(height: 12),
            Row(
              children: [
                Expanded(child: FilledButton.icon(onPressed: () => open(context, MockScreen.routeDetail), icon: const Icon(Icons.play_arrow), label: Text(tx(context, 'Continue', 'استكمال')))),
                const SizedBox(width: 8),
                IconButton.filledTonal(onPressed: () => open(context, MockScreen.routeMap), icon: const Icon(Icons.map_outlined)),
              ],
            ),
          ],
        ),
      ),
      const SizedBox(height: 14),
      SectionTitle(tx(context, 'Quick actions', 'إجراءات سريعة')),
      const SizedBox(height: 8),
      GridView.count(
        crossAxisCount: 2,
        childAspectRatio: 1.65,
        shrinkWrap: true,
        physics: const NeverScrollableScrollPhysics(),
        mainAxisSpacing: 8,
        crossAxisSpacing: 8,
        children: [
          Quick(icon: Icons.storefront_outlined, en: 'Customers', ar: 'العملاء', tap: () => open(context, MockScreen.customers)),
          Quick(icon: Icons.add_shopping_cart, en: 'New order', ar: 'طلب جديد', tap: () => open(context, MockScreen.catalog)),
          Quick(icon: Icons.payments_outlined, en: 'Collect', ar: 'تحصيل', tap: () => open(context, MockScreen.collection)),
          Quick(icon: Icons.local_offer_outlined, en: 'Offers', ar: 'العروض', tap: () => open(context, MockScreen.offers)),
        ],
      ),
      const SizedBox(height: 14),
      SectionTitle(tx(context, 'Next stop', 'الزيارة التالية')),
      const SizedBox(height: 8),
      CustomerTile(
        name: tx(context, 'Al Noor Market', 'سوق النور'),
        code: 'C-2048',
        detail: tx(context, 'Block 3 • Street 17', 'قطعة 3 • شارع 17'),
        badge: tx(context, 'Due 24.750 KWD', 'مستحق 24.750 د.ك'),
        tap: () => open(context, MockScreen.visit),
      ),
    ]);
  }
}

class RoutesMock extends StatelessWidget {
  const RoutesMock({super.key});

  @override
  Widget build(BuildContext context) {
    return ScrollBody(children: [
      const CompactDateFilter(),
      const SizedBox(height: 10),
      Row(
        children: [
          Expanded(child: FilterChip(selected: true, onSelected: (_) {}, label: Text(tx(context, 'Today', 'اليوم')))),
          const SizedBox(width: 6),
          Expanded(child: FilterChip(selected: false, onSelected: (_) {}, label: Text(tx(context, 'Upcoming', 'قادمة')))),
          const SizedBox(width: 6),
          Expanded(child: FilterChip(selected: false, onSelected: (_) {}, label: Text(tx(context, 'Done', 'مكتملة')))),
        ],
      ),
      const SizedBox(height: 12),
      RouteTile(title: tx(context, 'Al-Rai North', 'الري الشمالي'), code: 'R-024', stops: '14', done: '5', color: C.blue, tap: () => open(context, MockScreen.routeDetail)),
      const SizedBox(height: 8),
      RouteTile(title: tx(context, 'Shuwaikh Retail', 'الشويخ التجاري'), code: 'R-031', stops: '11', done: '0', color: C.orange, tap: () => open(context, MockScreen.routeDetail)),
      const SizedBox(height: 8),
      RouteTile(title: tx(context, 'Farwaniya South', 'الفروانية الجنوبية'), code: 'R-019', stops: '9', done: '9', color: C.green, tap: () => open(context, MockScreen.routeDetail)),
    ]);
  }
}

class RouteMapMock extends StatelessWidget {
  const RouteMapMock({super.key});

  @override
  Widget build(BuildContext context) {
    return Stack(
      children: [
        const Positioned.fill(child: FakeMap()),
        PositionedDirectional(
          top: 12,
          start: 12,
          end: 12,
          child: Panel(
            child: Row(
              children: [
                const Icon(Icons.route, color: C.green),
                const SizedBox(width: 8),
                Expanded(child: Text(tx(context, 'R-024 • 5/14 completed', 'R-024 • تمت 5/14'), style: const TextStyle(fontWeight: FontWeight.w900))),
                const Pill(en: 'LIVE', ar: 'مباشر', color: C.green),
              ],
            ),
          ),
        ),
        PositionedDirectional(
          bottom: 14,
          start: 14,
          end: 14,
          child: Panel(
            child: Column(
              children: [
                Row(
                  children: [
                    const CircleAvatar(backgroundColor: C.mint, child: Icon(Icons.storefront, color: C.green)),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(tx(context, 'Next: Al Noor Market', 'التالي: سوق النور'), style: const TextStyle(fontWeight: FontWeight.w900)),
                          Text(tx(context, '1.8 km • 6 min', '1.8 كم • 6 دقائق'), style: const TextStyle(color: C.muted)),
                        ],
                      ),
                    ),
                    IconButton.filled(onPressed: () {}, icon: const Icon(Icons.navigation_outlined)),
                  ],
                ),
                const SizedBox(height: 8),
                FilledButton(onPressed: () => open(context, MockScreen.visit), child: Text(tx(context, 'Open visit', 'فتح الزيارة'))),
              ],
            ),
          ),
        ),
      ],
    );
  }
}

class RouteDetailMock extends StatelessWidget {
  const RouteDetailMock({super.key});

  @override
  Widget build(BuildContext context) {
    final stops = [
      ['1', tx(context, 'Fresh Corner', 'فريش كورنر'), tx(context, 'Completed • 09:12', 'تمت • 09:12'), C.green],
      ['2', tx(context, 'Al Waha Co-op', 'جمعية الواحة'), tx(context, 'Completed • 09:48', 'تمت • 09:48'), C.green],
      ['3', tx(context, 'Al Noor Market', 'سوق النور'), tx(context, 'Next • 1.8 km', 'التالي • 1.8 كم'), C.blue],
      ['4', tx(context, 'Basma Mini Mart', 'بسمة ميني ماركت'), tx(context, 'Scheduled • 11:00', 'مجدولة • 11:00'), C.muted],
      ['5', tx(context, 'City Grocer', 'سيتي جروسر'), tx(context, 'Scheduled • 11:35', 'مجدولة • 11:35'), C.muted],
    ];

    return ScrollBody(children: [
      Panel(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                const Icon(Icons.route, color: C.green, size: 28),
                const SizedBox(width: 10),
                Expanded(child: Text(tx(context, 'Al-Rai North', 'الري الشمالي'), style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w900))),
                const Pill(en: 'ACTIVE', ar: 'نشط', color: C.green),
              ],
            ),
            const SizedBox(height: 6),
            Text(tx(context, 'Route R-024 • Van V-12 • 14 stops', 'المسار R-024 • الفان V-12 • 14 زيارة'), style: const TextStyle(color: C.muted)),
            const SizedBox(height: 12),
            Row(
              children: [
                Expanded(child: FilledButton.icon(onPressed: () => open(context, MockScreen.routeMap), icon: const Icon(Icons.map_outlined), label: Text(tx(context, 'Map', 'الخريطة')))),
                const SizedBox(width: 8),
                Expanded(child: OutlinedButton.icon(onPressed: () => open(context, MockScreen.customers), icon: const Icon(Icons.storefront_outlined), label: Text(tx(context, 'Stops', 'الزيارات')))),
              ],
            ),
          ],
        ),
      ),
      const SizedBox(height: 14),
      SectionTitle(tx(context, 'Stop sequence', 'ترتيب الزيارات')),
      const SizedBox(height: 8),
      ...stops.map((s) => Padding(
        padding: const EdgeInsets.only(bottom: 8),
        child: Panel(
          onTap: () => open(context, MockScreen.visit),
          child: Row(
            children: [
              CircleAvatar(backgroundColor: (s[3] as Color).withValues(alpha: 0.10), child: Text(s[0] as String, style: TextStyle(color: s[3] as Color, fontWeight: FontWeight.w900))),
              const SizedBox(width: 10),
              Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(s[1] as String, style: const TextStyle(fontWeight: FontWeight.w900)),
                Text(s[2] as String, style: TextStyle(color: s[3] as Color, fontSize: 12)),
              ])),
              const Icon(Icons.chevron_right, color: C.muted),
            ],
          ),
        ),
      )),
    ]);
  }
}

class CustomersMock extends StatelessWidget {
  const CustomersMock({super.key});

  @override
  Widget build(BuildContext context) {
    return ScrollBody(children: [
      TextField(decoration: InputDecoration(hintText: tx(context, 'Search customer, code or area', 'ابحث بالعميل أو الكود أو المنطقة'), prefixIcon: const Icon(Icons.search), suffixIcon: const Icon(Icons.tune))),
      const SizedBox(height: 10),
      SingleChildScrollView(
        scrollDirection: Axis.horizontal,
        child: Row(children: [
          FilterChip(selected: true, onSelected: (_) {}, label: Text(tx(context, 'Assigned', 'المسندون'))),
          const SizedBox(width: 6),
          FilterChip(selected: false, onSelected: (_) {}, label: Text(tx(context, 'Due balance', 'عليهم رصيد'))),
          const SizedBox(width: 6),
          FilterChip(selected: false, onSelected: (_) {}, label: Text(tx(context, 'Priority', 'أولوية'))),
        ]),
      ),
      const SizedBox(height: 12),
      CustomerTile(name: tx(context, 'Al Noor Market', 'سوق النور'), code: 'C-2048', detail: tx(context, 'Block 3 • Street 17', 'قطعة 3 • شارع 17'), badge: tx(context, 'Due 24.750 KWD', 'مستحق 24.750 د.ك'), tap: () => open(context, MockScreen.customer360)),
      const SizedBox(height: 8),
      CustomerTile(name: tx(context, 'Basma Mini Mart', 'بسمة ميني ماركت'), code: 'C-1832', detail: tx(context, 'Block 2 • Main Street', 'قطعة 2 • الشارع الرئيسي'), badge: tx(context, 'Good standing', 'حساب جيد'), tap: () => open(context, MockScreen.customer360)),
      const SizedBox(height: 8),
      CustomerTile(name: tx(context, 'City Grocer', 'سيتي جروسر'), code: 'C-2651', detail: tx(context, 'Industrial Area • Gate 4', 'المنطقة الصناعية • بوابة 4'), badge: tx(context, 'Visit due', 'زيارة مطلوبة'), tap: () => open(context, MockScreen.customer360)),
    ]);
  }
}

class VisitMock extends StatelessWidget {
  const VisitMock({super.key});

  @override
  Widget build(BuildContext context) {
    return ScrollBody(children: [
      CustomerTile(name: tx(context, 'Al Noor Market', 'سوق النور'), code: 'C-2048', detail: tx(context, 'Block 3 • Street 17', 'قطعة 3 • شارع 17'), badge: tx(context, 'On site', 'في الموقع'), tap: () => open(context, MockScreen.customer360)),
      const SizedBox(height: 10),
      const Row(
        children: [
          Expanded(child: Metric(icon: Icons.account_balance_wallet_outlined, value: '24.750', en: 'Outstanding', ar: 'مستحق')),
          SizedBox(width: 8),
          Expanded(child: Metric(icon: Icons.shopping_bag_outlined, value: '3', en: 'Open orders', ar: 'طلبات مفتوحة')),
        ],
      ),
      const SizedBox(height: 14),
      SectionTitle(tx(context, 'Visit actions', 'إجراءات الزيارة')),
      const SizedBox(height: 8),
      ActionRow(icon: Icons.add_shopping_cart, title: tx(context, 'Create sales order', 'إنشاء طلب بيع'), subtitle: tx(context, 'Browse products and build the order', 'تصفح المنتجات وأنشئ الطلب'), tap: () => open(context, MockScreen.catalog)),
      const SizedBox(height: 8),
      ActionRow(icon: Icons.payments_outlined, title: tx(context, 'Collect payment', 'تحصيل دفعة'), subtitle: tx(context, 'Outstanding invoices: 2', 'فواتير مستحقة: 2'), tap: () => open(context, MockScreen.collection)),
      const SizedBox(height: 8),
      ActionRow(icon: Icons.local_offer_outlined, title: tx(context, 'Available offers', 'العروض المتاحة'), subtitle: tx(context, '3 eligible offers', '3 عروض مؤهلة'), tap: () => open(context, MockScreen.offers)),
      const SizedBox(height: 14),
      SectionTitle(tx(context, 'Visit checklist', 'قائمة الزيارة')),
      const SizedBox(height: 8),
      Panel(child: Column(children: [
        Checklist(en: 'GPS check-in', arabic: 'تسجيل الوصول بالموقع', done: true),
        const Divider(height: 18),
        Checklist(en: 'Customer verified', arabic: 'تم التحقق من العميل', done: true),
        const Divider(height: 18),
        Checklist(en: 'Order / no-sale decision', arabic: 'قرار الطلب / بدون بيع', done: false),
        const Divider(height: 18),
        Checklist(en: 'Collection review', arabic: 'مراجعة التحصيل', done: false),
      ])),
      const SizedBox(height: 12),
      FilledButton.icon(onPressed: () => toast(context, tx(context, 'Visit completed in mockup', 'تم إنهاء الزيارة في النموذج')), icon: const Icon(Icons.check_circle_outline), label: Text(tx(context, 'Complete visit', 'إنهاء الزيارة'))),
    ]);
  }
}

class Customer360Mock extends StatelessWidget {
  const Customer360Mock({super.key});

  @override
  Widget build(BuildContext context) {
    return ScrollBody(children: [
      Panel(child: Row(children: [
        const CircleAvatar(radius: 28, backgroundColor: C.mint, child: Icon(Icons.storefront, color: C.green, size: 28)),
        const SizedBox(width: 12),
        Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(tx(context, 'Al Noor Market', 'سوق النور'), style: const TextStyle(fontSize: 19, fontWeight: FontWeight.w900)),
          const Text('C-2048', style: TextStyle(color: C.muted, fontWeight: FontWeight.w700)),
          const SizedBox(height: 7),
          const Wrap(spacing: 6, children: [Pill(en: 'B2B', ar: 'جملة', color: C.blue), Pill(en: 'PRIORITY', ar: 'أولوية', color: C.orange)]),
        ])),
        IconButton(onPressed: () {}, icon: const Icon(Icons.more_horiz)),
      ])),
      const SizedBox(height: 10),
      const Row(children: [
        Expanded(child: Metric(icon: Icons.credit_score_outlined, value: '500.000', en: 'Credit limit', ar: 'حد ائتماني')),
        SizedBox(width: 8),
        Expanded(child: Metric(icon: Icons.account_balance_wallet_outlined, value: '24.750', en: 'Outstanding', ar: 'مستحق')),
      ]),
      const SizedBox(height: 14),
      SectionTitle(tx(context, 'Account snapshot', 'ملخص الحساب')),
      const SizedBox(height: 8),
      Panel(child: Column(children: [
        Info(label: tx(context, 'Last order', 'آخر طلب'), value: 'ORD-10842'),
        const Divider(height: 18),
        Info(label: tx(context, 'Last visit', 'آخر زيارة'), value: tx(context, '2 days ago', 'منذ يومين')),
        const Divider(height: 18),
        Info(label: tx(context, 'Payment terms', 'شروط الدفع'), value: tx(context, '14 days', '14 يوم')),
        const Divider(height: 18),
        Info(label: tx(context, 'Assigned route', 'المسار المسند'), value: 'R-024'),
      ])),
      const SizedBox(height: 12),
      Row(children: [
        Expanded(child: FilledButton.icon(onPressed: () => open(context, MockScreen.catalog), icon: const Icon(Icons.add_shopping_cart), label: Text(tx(context, 'New order', 'طلب جديد')))),
        const SizedBox(width: 8),
        Expanded(child: OutlinedButton.icon(onPressed: () => open(context, MockScreen.collection), icon: const Icon(Icons.payments_outlined), label: Text(tx(context, 'Collect', 'تحصيل')))),
      ]),
    ]);
  }
}

class CatalogMock extends StatelessWidget {
  const CatalogMock({super.key});

  @override
  Widget build(BuildContext context) {
    return ScrollBody(bottom: 100, children: [
      Panel(child: Row(children: [
        const Icon(Icons.storefront_outlined, color: C.green),
        const SizedBox(width: 8),
        Expanded(child: Text(tx(context, 'Al Noor Market • C-2048', 'سوق النور • C-2048'), style: const TextStyle(fontWeight: FontWeight.w900))),
        const Icon(Icons.expand_more),
      ])),
      const SizedBox(height: 10),
      TextField(decoration: InputDecoration(hintText: tx(context, 'Search product, SKU or barcode', 'ابحث بالمنتج أو SKU أو الباركود'), prefixIcon: const Icon(Icons.search), suffixIcon: const Icon(Icons.qr_code_scanner))),
      const SizedBox(height: 10),
      SingleChildScrollView(scrollDirection: Axis.horizontal, child: Row(children: [
        FilterChip(selected: true, onSelected: (_) {}, label: Text(tx(context, 'All', 'الكل'))),
        const SizedBox(width: 6),
        FilterChip(selected: false, onSelected: (_) {}, label: Text(tx(context, 'Beverages', 'مشروبات'))),
        const SizedBox(width: 6),
        FilterChip(selected: false, onSelected: (_) {}, label: Text(tx(context, 'Snacks', 'سناكس'))),
        const SizedBox(width: 6),
        FilterChip(selected: false, onSelected: (_) {}, label: Text(tx(context, 'Offers', 'عروض'))),
      ])),
      const SizedBox(height: 12),
      ProductTile(name: tx(context, 'Orange Juice 1L', 'عصير برتقال 1 لتر'), sku: 'SKU-1024', price: '0.850 KWD', stock: '48', promo: true),
      const SizedBox(height: 8),
      ProductTile(name: tx(context, 'Mineral Water 6x1.5L', 'مياه معدنية 6×1.5 لتر'), sku: 'SKU-2078', price: '1.250 KWD', stock: '31'),
      const SizedBox(height: 8),
      ProductTile(name: tx(context, 'Potato Chips 45g', 'شيبس بطاطس 45 جم'), sku: 'SKU-3312', price: '0.150 KWD', stock: '92'),
      const SizedBox(height: 14),
      FilledButton.icon(onPressed: () => open(context, MockScreen.orderBuilder), icon: const Icon(Icons.shopping_cart_checkout), label: Text(tx(context, 'Open cart • 3 items', 'فتح السلة • 3 أصناف'))),
    ]);
  }
}

class OrderBuilderMock extends StatelessWidget {
  const OrderBuilderMock({super.key});

  @override
  Widget build(BuildContext context) {
    return ScrollBody(bottom: 100, children: [
      const OrderContext(),
      const SizedBox(height: 12),
      SectionTitle(tx(context, 'Order items', 'أصناف الطلب')),
      const SizedBox(height: 8),
      OrderLine(name: tx(context, 'Orange Juice 1L', 'عصير برتقال 1 لتر'), qty: 6, total: '5.100 KWD'),
      const SizedBox(height: 8),
      OrderLine(name: tx(context, 'Mineral Water 6x1.5L', 'مياه معدنية 6×1.5 لتر'), qty: 2, total: '2.500 KWD'),
      const SizedBox(height: 8),
      OrderLine(name: tx(context, 'Potato Chips 45g', 'شيبس بطاطس 45 جم'), qty: 12, total: '1.800 KWD'),
      const SizedBox(height: 12),
      Panel(child: Column(children: [
        Info(label: tx(context, 'Subtotal', 'الإجمالي قبل الخصم'), value: '9.400 KWD'),
        const SizedBox(height: 7),
        Info(label: tx(context, 'Offer discount', 'خصم العرض'), value: '-0.600 KWD', valueColor: C.green),
        const Divider(height: 20),
        Info(label: tx(context, 'Order total', 'إجمالي الطلب'), value: '8.800 KWD', strong: true),
      ])),
      const SizedBox(height: 12),
      FilledButton.icon(onPressed: () => open(context, MockScreen.orderReview), icon: const Icon(Icons.arrow_forward), label: Text(tx(context, 'Review order', 'مراجعة الطلب'))),
    ]);
  }
}

class OrderReviewMock extends StatelessWidget {
  const OrderReviewMock({super.key});

  @override
  Widget build(BuildContext context) {
    return ScrollBody(children: [
      const OrderContext(),
      const SizedBox(height: 12),
      SectionTitle(tx(context, 'Delivery', 'التسليم')),
      const SizedBox(height: 8),
      Panel(child: Column(children: [
        Info(label: tx(context, 'Delivery mode', 'طريقة التسليم'), value: tx(context, 'Van immediate delivery', 'تسليم مباشر من الفان')),
        const Divider(height: 18),
        Info(label: tx(context, 'Address', 'العنوان'), value: tx(context, 'Block 3 • Street 17', 'قطعة 3 • شارع 17')),
      ])),
      const SizedBox(height: 12),
      SectionTitle(tx(context, 'Payment', 'الدفع')),
      const SizedBox(height: 8),
      Panel(child: Column(children: [
        Info(label: tx(context, 'Method', 'الطريقة'), value: tx(context, 'Credit account', 'حساب آجل')),
        const Divider(height: 18),
        Info(label: tx(context, 'Available credit', 'الائتمان المتاح'), value: '475.250 KWD'),
      ])),
      const SizedBox(height: 12),
      SectionTitle(tx(context, 'Summary', 'الملخص')),
      const SizedBox(height: 8),
      Panel(child: Column(children: [
        Info(label: tx(context, 'Items', 'الأصناف'), value: '3'),
        const SizedBox(height: 7),
        Info(label: tx(context, 'Discount', 'الخصم'), value: '0.600 KWD'),
        const Divider(height: 20),
        Info(label: tx(context, 'Total', 'الإجمالي'), value: '8.800 KWD', strong: true),
      ])),
      const SizedBox(height: 14),
      FilledButton.icon(
        onPressed: () {
          toast(context, tx(context, 'Mock order submitted successfully', 'تم إرسال الطلب الوهمي بنجاح'));
          open(context, MockScreen.orders);
        },
        icon: const Icon(Icons.check_circle_outline),
        label: Text(tx(context, 'Submit order', 'تأكيد الطلب')),
      ),
    ]);
  }
}

class OrdersMock extends StatelessWidget {
  const OrdersMock({super.key});

  @override
  Widget build(BuildContext context) {
    return ScrollBody(children: [
      TextField(decoration: InputDecoration(hintText: tx(context, 'Search order number', 'ابحث برقم الطلب'), prefixIcon: const Icon(Icons.search))),
      const SizedBox(height: 10),
      Row(children: [
        Expanded(child: FilterChip(selected: true, onSelected: (_) {}, label: Text(tx(context, 'All', 'الكل')))),
        const SizedBox(width: 6),
        Expanded(child: FilterChip(selected: false, onSelected: (_) {}, label: Text(tx(context, 'Open', 'مفتوح')))),
        const SizedBox(width: 6),
        Expanded(child: FilterChip(selected: false, onSelected: (_) {}, label: Text(tx(context, 'Delivered', 'تم التسليم')))),
      ]),
      const SizedBox(height: 12),
      OrderTile(number: 'ORD-10842', customer: tx(context, 'Al Noor Market', 'سوق النور'), total: '8.800 KWD', state: tx(context, 'Ready to deliver', 'جاهز للتسليم'), color: C.blue),
      const SizedBox(height: 8),
      OrderTile(number: 'ORD-10831', customer: tx(context, 'Basma Mini Mart', 'بسمة ميني ماركت'), total: '16.250 KWD', state: tx(context, 'Delivered', 'تم التسليم'), color: C.green),
      const SizedBox(height: 8),
      OrderTile(number: 'ORD-10818', customer: tx(context, 'City Grocer', 'سيتي جروسر'), total: '11.400 KWD', state: tx(context, 'Pending', 'قيد المراجعة'), color: C.orange),
    ]);
  }
}

class OffersMock extends StatelessWidget {
  const OffersMock({super.key});

  @override
  Widget build(BuildContext context) {
    return ScrollBody(children: [
      Panel(
        color: const Color(0xFFF3FAF6),
        child: Row(children: [
          const Icon(Icons.storefront, color: C.green),
          const SizedBox(width: 9),
          Expanded(child: Text(tx(context, 'Offers for Al Noor Market', 'عروض خاصة بسوق النور'), style: const TextStyle(fontWeight: FontWeight.w900))),
          const Icon(Icons.expand_more),
        ]),
      ),
      const SizedBox(height: 12),
      OfferTile(icon: Icons.bolt, color: C.orange, title: tx(context, 'Flash: Juice Bundle', 'فلاش: باقة العصائر'), subtitle: tx(context, 'Buy 12 bottles • special van price', 'اشترِ 12 عبوة • سعر خاص للفان'), value: '8.900 KWD'),
      const SizedBox(height: 8),
      OfferTile(icon: Icons.local_offer, color: C.green, title: tx(context, 'Water volume offer', 'عرض كميات المياه'), subtitle: tx(context, '5% off from 5 packs', 'خصم 5% عند شراء 5 باكيت'), value: '5%'),
      const SizedBox(height: 8),
      OfferTile(icon: Icons.star_outline, color: C.blue, title: tx(context, 'Priority customer deal', 'عرض عميل أولوية'), subtitle: tx(context, 'Mixed snack carton', 'كرتون سناكس متنوع'), value: '4.750 KWD'),
    ]);
  }
}

class WalletMock extends StatelessWidget {
  const WalletMock({super.key});

  @override
  Widget build(BuildContext context) {
    return ScrollBody(children: [
      Container(
        padding: const EdgeInsets.all(18),
        decoration: BoxDecoration(
          gradient: const LinearGradient(colors: [C.greenDark, C.green]),
          borderRadius: BorderRadius.circular(22),
        ),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(tx(context, 'Custody wallet', 'محفظة العهدة'), style: const TextStyle(color: Colors.white70, fontWeight: FontWeight.w700)),
          const SizedBox(height: 6),
          const Text('128.500 KWD', style: TextStyle(color: Colors.white, fontSize: 30, fontWeight: FontWeight.w900)),
          const SizedBox(height: 14),
          Row(children: [
            Expanded(child: WhiteMetric(label: tx(context, 'To remit', 'متاح للتوريد'), value: '96.250')),
            Expanded(child: WhiteMetric(label: tx(context, 'Pending', 'قيد المراجعة'), value: '32.250')),
          ]),
        ]),
      ),
      const SizedBox(height: 12),
      Row(children: [
        Expanded(child: Quick(icon: Icons.payments_outlined, en: 'Collect', ar: 'تحصيل', tap: () => open(context, MockScreen.collection))),
        const SizedBox(width: 8),
        Expanded(child: Quick(icon: Icons.account_balance_outlined, en: 'Remit', ar: 'توريد', tap: () => open(context, MockScreen.remittance))),
      ]),
      const SizedBox(height: 14),
      SectionTitle(tx(context, 'Recent activity', 'آخر الحركات')),
      const SizedBox(height: 8),
      MoneyTile(title: tx(context, 'Collection • Al Noor Market', 'تحصيل • سوق النور'), ref: 'INV-30184', amount: '+24.750', positive: true),
      const SizedBox(height: 8),
      MoneyTile(title: tx(context, 'Remittance', 'توريد'), ref: 'REM-1182', amount: '-55.000', positive: false),
      const SizedBox(height: 8),
      MoneyTile(title: tx(context, 'Collection • City Grocer', 'تحصيل • سيتي جروسر'), ref: 'INV-30170', amount: '+18.500', positive: true),
    ]);
  }
}

class CollectionMock extends StatefulWidget {
  const CollectionMock({super.key});
  @override
  State<CollectionMock> createState() => _CollectionMockState();
}

class _CollectionMockState extends State<CollectionMock> {
  int invoice = 0;
  int method = 0;

  @override
  Widget build(BuildContext context) {
    return ScrollBody(children: [
      CustomerTile(name: tx(context, 'Al Noor Market', 'سوق النور'), code: 'C-2048', detail: tx(context, 'Outstanding balance', 'الرصيد المستحق'), badge: '24.750 KWD', tap: () {}),
      const SizedBox(height: 14),
      SectionTitle(tx(context, 'Select invoice', 'اختر الفاتورة')),
      const SizedBox(height: 8),
      InvoiceChoice(selected: invoice == 0, number: 'INV-30184', date: '04 Oct 2026', amount: '14.250 KWD', tap: () => setState(() => invoice = 0)),
      const SizedBox(height: 8),
      InvoiceChoice(selected: invoice == 1, number: 'INV-30122', date: '29 Sep 2026', amount: '10.500 KWD', tap: () => setState(() => invoice = 1)),
      const SizedBox(height: 12),
      TextFormField(
        initialValue: invoice == 0 ? '14.250' : '10.500',
        decoration: InputDecoration(labelText: tx(context, 'Amount received', 'المبلغ المستلم'), suffixText: 'KWD'),
      ),
      const SizedBox(height: 12),
      SectionTitle(tx(context, 'Payment method', 'طريقة الدفع')),
      const SizedBox(height: 8),
      SegmentedButton<int>(
        segments: [
          ButtonSegment(value: 0, icon: const Icon(Icons.payments_outlined), label: Text(tx(context, 'Cash', 'نقدي'))),
          ButtonSegment(value: 1, icon: const Icon(Icons.credit_card), label: Text(tx(context, 'KNET', 'كي نت'))),
        ],
        selected: {method},
        onSelectionChanged: (v) => setState(() => method = v.first),
      ),
      const SizedBox(height: 14),
      FilledButton.icon(onPressed: () => open(context, MockScreen.receipt), icon: const Icon(Icons.check_circle_outline), label: Text(tx(context, 'Confirm collection', 'تأكيد التحصيل'))),
    ]);
  }
}

class ReceiptMock extends StatelessWidget {
  const ReceiptMock({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        child: ScrollBody(children: [
          Align(alignment: AlignmentDirectional.centerStart, child: IconButton(onPressed: () => Navigator.of(context).pop(), icon: const Icon(Icons.close))),
          const SizedBox(height: 8),
          const Center(child: CircleAvatar(radius: 38, backgroundColor: C.mint, child: Icon(Icons.check_rounded, color: C.green, size: 42))),
          const SizedBox(height: 14),
          Text(tx(context, 'Payment collected', 'تم التحصيل بنجاح'), textAlign: TextAlign.center, style: const TextStyle(fontSize: 24, fontWeight: FontWeight.w900)),
          const SizedBox(height: 5),
          const Text('14.250 KWD', textAlign: TextAlign.center, style: TextStyle(fontSize: 31, fontWeight: FontWeight.w900, color: C.greenDark)),
          const SizedBox(height: 18),
          Panel(child: Column(children: [
            Info(label: tx(context, 'Receipt', 'الإيصال'), value: 'REC-88431'),
            const Divider(height: 18),
            Info(label: tx(context, 'Customer', 'العميل'), value: tx(context, 'Al Noor Market', 'سوق النور')),
            const Divider(height: 18),
            const Info(label: 'Invoice', value: 'INV-30184'),
            const Divider(height: 18),
            Info(label: tx(context, 'Method', 'الطريقة'), value: tx(context, 'Cash', 'نقدي')),
            const Divider(height: 18),
            Info(label: tx(context, 'Remaining', 'المتبقي'), value: '10.500 KWD'),
          ])),
          const SizedBox(height: 12),
          Row(children: [
            Expanded(child: OutlinedButton.icon(onPressed: () => toast(context, tx(context, 'Share mockup', 'مشاركة النموذج')), icon: const Icon(Icons.share_outlined), label: Text(tx(context, 'Share', 'مشاركة')))),
            const SizedBox(width: 8),
            Expanded(child: OutlinedButton.icon(onPressed: () => toast(context, tx(context, 'Print mockup', 'طباعة النموذج')), icon: const Icon(Icons.print_outlined), label: Text(tx(context, 'Print', 'طباعة')))),
          ]),
          const SizedBox(height: 10),
          FilledButton(onPressed: () => Navigator.of(context).pop(), child: Text(tx(context, 'Done', 'تم'))),
        ]),
      ),
    );
  }
}

class RemittanceMock extends StatelessWidget {
  const RemittanceMock({super.key});

  @override
  Widget build(BuildContext context) {
    return ScrollBody(children: [
      Panel(color: const Color(0xFFF3FAF6), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(tx(context, 'Available to remit', 'المتاح للتوريد'), style: const TextStyle(color: C.muted, fontWeight: FontWeight.w700)),
        const SizedBox(height: 5),
        const Text('96.250 KWD', style: TextStyle(fontSize: 28, fontWeight: FontWeight.w900, color: C.greenDark)),
      ])),
      const SizedBox(height: 12),
      TextFormField(initialValue: '55.000', decoration: InputDecoration(labelText: tx(context, 'Remittance amount', 'مبلغ التوريد'), suffixText: 'KWD')),
      const SizedBox(height: 10),
      DropdownButtonFormField<String>(
        initialValue: 'bank',
        items: [
          DropdownMenuItem(value: 'bank', child: Text(tx(context, 'Bank deposit', 'إيداع بنكي'))),
          DropdownMenuItem(value: 'cash', child: Text(tx(context, 'Cash handover', 'تسليم نقدي'))),
        ],
        onChanged: (_) {},
        decoration: InputDecoration(labelText: tx(context, 'Method', 'طريقة التوريد')),
      ),
      const SizedBox(height: 10),
      TextField(decoration: InputDecoration(labelText: tx(context, 'Reference', 'المرجع'), prefixIcon: const Icon(Icons.tag))),
      const SizedBox(height: 10),
      TextField(maxLines: 3, decoration: InputDecoration(labelText: tx(context, 'Note (optional)', 'ملاحظة (اختياري)'))),
      const SizedBox(height: 14),
      FilledButton.icon(onPressed: () => toast(context, tx(context, 'Mock remittance submitted', 'تم إرسال التوريد الوهمي')), icon: const Icon(Icons.account_balance_outlined), label: Text(tx(context, 'Submit remittance', 'إرسال التوريد'))),
    ]);
  }
}

class NotificationsMock extends StatelessWidget {
  const NotificationsMock({super.key});

  @override
  Widget build(BuildContext context) {
    return ScrollBody(children: [
      Row(children: [
        Expanded(child: FilterChip(selected: true, onSelected: (_) {}, label: Text(tx(context, 'All', 'الكل')))),
        const SizedBox(width: 8),
        Expanded(child: FilterChip(selected: false, onSelected: (_) {}, label: Text(tx(context, 'Unread', 'غير مقروء')))),
      ]),
      const SizedBox(height: 12),
      Notice(icon: Icons.route_outlined, color: C.blue, title: tx(context, 'Route updated', 'تم تحديث المسار'), body: tx(context, 'Stop 7 moved ahead of stop 6.', 'تم تقديم الزيارة 7 قبل الزيارة 6.'), time: tx(context, '2 min', 'دقيقتان'), unread: true),
      const SizedBox(height: 8),
      Notice(icon: Icons.local_offer_outlined, color: C.orange, title: tx(context, 'New flash offer', 'عرض فلاش جديد'), body: tx(context, 'Juice bundle is available for selected customers.', 'باقة العصائر متاحة الآن لعملاء محددين.'), time: tx(context, '18 min', '18 دقيقة'), unread: true),
      const SizedBox(height: 8),
      Notice(icon: Icons.payments_outlined, color: C.green, title: tx(context, 'Collection posted', 'تم ترحيل التحصيل'), body: tx(context, 'Receipt REC-88431 was posted successfully.', 'تم ترحيل الإيصال REC-88431 بنجاح.'), time: tx(context, '1 hour', 'ساعة')),
    ]);
  }
}

class ProfileMock extends StatelessWidget {
  const ProfileMock({super.key});

  @override
  Widget build(BuildContext context) {
    return ScrollBody(children: [
      Panel(child: Row(children: [
        const CircleAvatar(radius: 30, backgroundColor: C.mint, child: Icon(Icons.person, color: C.green, size: 30)),
        const SizedBox(width: 12),
        Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          const Text('Ahmed Al-Salem', style: TextStyle(fontSize: 19, fontWeight: FontWeight.w900)),
          Text(tx(context, 'Van sales representative', 'مندوب مبيعات فان'), style: const TextStyle(color: C.muted)),
          const SizedBox(height: 4),
          const Text('Van V-12 • R-024', style: TextStyle(fontWeight: FontWeight.w700)),
        ])),
      ])),
      const SizedBox(height: 14),
      SectionTitle(tx(context, 'App settings', 'إعدادات التطبيق')),
      const SizedBox(height: 8),
      const Setting(icon: Icons.language, en: 'Language', ar: 'اللغة', value: 'AR / EN'),
      const SizedBox(height: 8),
      Setting(icon: Icons.fingerprint, en: 'Biometric unlock', ar: 'فتح بالبصمة', value: tx(context, 'On', 'مفعل')),
      const SizedBox(height: 8),
      Setting(icon: Icons.sync, en: 'Last sync', ar: 'آخر مزامنة', value: tx(context, 'Just now', 'الآن')),
      const SizedBox(height: 8),
      const Setting(icon: Icons.info_outline, en: 'Design build', ar: 'نسخة التصميم', value: 'UIUX 0.1'),
      const SizedBox(height: 14),
      OutlinedButton.icon(onPressed: () => toast(context, tx(context, 'Mock logout', 'تسجيل خروج وهمي')), icon: const Icon(Icons.logout), label: Text(tx(context, 'Sign out', 'تسجيل الخروج'))),
      const SizedBox(height: 12),
      const DesignOnlyBanner(),
    ]);
  }
}

class ScrollBody extends StatelessWidget {
  const ScrollBody({super.key, required this.children, this.bottom = 30});
  final List<Widget> children;
  final double bottom;

  @override
  Widget build(BuildContext context) => ListView(
    padding: EdgeInsets.fromLTRB(12, 10, 12, bottom),
    children: children,
  );
}

class Brand extends StatelessWidget {
  const Brand({super.key, this.compact = false});
  final bool compact;

  @override
  Widget build(BuildContext context) => Row(
    mainAxisSize: MainAxisSize.min,
    children: [
      Container(
        width: compact ? 30 : 38,
        height: compact ? 30 : 38,
        decoration: BoxDecoration(color: C.green, borderRadius: BorderRadius.circular(compact ? 9 : 12)),
        child: Icon(Icons.local_shipping_rounded, color: Colors.white, size: compact ? 18 : 23),
      ),
      const SizedBox(width: 8),
      Text('FOODEX', style: TextStyle(color: C.greenDark, fontSize: compact ? 18 : 22, fontWeight: FontWeight.w900, letterSpacing: .6)),
    ],
  );
}

class MockBadge extends StatelessWidget {
  const MockBadge({super.key});

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 5),
    decoration: BoxDecoration(color: C.mint, borderRadius: BorderRadius.circular(999)),
    child: Text(tx(context, 'UIUX MOCKUP', 'موكاب UIUX'), style: const TextStyle(color: C.greenDark, fontSize: 10, fontWeight: FontWeight.w900)),
  );
}

class DesignOnlyBanner extends StatelessWidget {
  const DesignOnlyBanner({super.key});

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 9),
    decoration: BoxDecoration(color: const Color(0xFFFFF8E8), borderRadius: BorderRadius.circular(14), border: Border.all(color: const Color(0xFFFFE1A1))),
    child: Row(children: [
      const Icon(Icons.design_services_outlined, color: C.orange, size: 20),
      const SizedBox(width: 8),
      Expanded(child: Text(tx(context, 'DESIGN ONLY • fake data • no backend', 'تصميم فقط • بيانات وهمية • بدون باك إند'), style: const TextStyle(color: Color(0xFF8A5A00), fontSize: 12, fontWeight: FontWeight.w900))),
    ]),
  );
}

class HeroMockCard extends StatelessWidget {
  const HeroMockCard({super.key, required this.onStart});
  final VoidCallback onStart;

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.all(18),
    decoration: BoxDecoration(
      gradient: const LinearGradient(colors: [C.greenDark, C.green]),
      borderRadius: BorderRadius.circular(24),
    ),
    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      const Icon(Icons.local_shipping_rounded, color: Colors.white, size: 40),
      const SizedBox(height: 14),
      Text(tx(context, 'FOODEX Van UI/UX Lab', 'مختبر تصميم تطبيق الفان'), style: const TextStyle(color: Colors.white, fontSize: 24, fontWeight: FontWeight.w900)),
      const SizedBox(height: 6),
      Text(tx(context, 'Standalone visual prototype with 19 mockup screens and fake data only.', 'نموذج بصري مستقل يضم 19 شاشة موكاب وبيانات وهمية فقط.'), style: const TextStyle(color: Colors.white70, height: 1.45)),
      const SizedBox(height: 14),
      FilledButton(style: FilledButton.styleFrom(backgroundColor: Colors.white, foregroundColor: C.greenDark), onPressed: onStart, child: Text(tx(context, 'Start design tour', 'ابدأ الجولة التصميمية'))),
    ]),
  );
}

class Panel extends StatelessWidget {
  const Panel({super.key, required this.child, this.onTap, this.color, this.padding = const EdgeInsets.all(14)});
  final Widget child;
  final VoidCallback? onTap;
  final Color? color;
  final EdgeInsets padding;

  @override
  Widget build(BuildContext context) {
    final box = Container(
      padding: padding,
      decoration: BoxDecoration(color: color ?? Colors.white, borderRadius: BorderRadius.circular(18), border: Border.all(color: C.border)),
      child: child,
    );
    if (onTap == null) return box;
    return Material(color: Colors.transparent, child: InkWell(onTap: onTap, borderRadius: BorderRadius.circular(18), child: box));
  }
}

class SectionTitle extends StatelessWidget {
  const SectionTitle(this.title, {super.key});
  final String title;
  @override
  Widget build(BuildContext context) => Text(title, style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w900));
}

class Metric extends StatelessWidget {
  const Metric({super.key, required this.icon, required this.value, required this.en, required this.ar});
  final IconData icon;
  final String value;
  final String en;
  final String ar;

  @override
  Widget build(BuildContext context) => Panel(
    padding: const EdgeInsets.all(11),
    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Icon(icon, color: C.green, size: 20),
      const SizedBox(height: 7),
      Text(value, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w900)),
      Text(tx(context, en, ar), maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(color: C.muted, fontSize: 11)),
    ]),
  );
}

class Pill extends StatelessWidget {
  const Pill({super.key, required this.en, required this.ar, required this.color});
  final String en;
  final String ar;
  final Color color;

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 5),
    decoration: BoxDecoration(color: color.withValues(alpha: .10), borderRadius: BorderRadius.circular(999)),
    child: Text(tx(context, en, ar), style: TextStyle(color: color, fontSize: 10, fontWeight: FontWeight.w900)),
  );
}

class Quick extends StatelessWidget {
  const Quick({super.key, required this.icon, required this.en, required this.ar, required this.tap});
  final IconData icon;
  final String en;
  final String ar;
  final VoidCallback tap;

  @override
  Widget build(BuildContext context) => Panel(
    onTap: tap,
    padding: const EdgeInsets.all(12),
    child: Row(children: [
      CircleAvatar(backgroundColor: C.mint, child: Icon(icon, color: C.green)),
      const SizedBox(width: 8),
      Expanded(child: Text(tx(context, en, ar), style: const TextStyle(fontWeight: FontWeight.w800))),
    ]),
  );
}

class CustomerTile extends StatelessWidget {
  const CustomerTile({super.key, required this.name, required this.code, required this.detail, required this.badge, required this.tap});
  final String name;
  final String code;
  final String detail;
  final String badge;
  final VoidCallback tap;

  @override
  Widget build(BuildContext context) => Panel(
    onTap: tap,
    child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
      const CircleAvatar(backgroundColor: C.mint, child: Icon(Icons.storefront_outlined, color: C.green)),
      const SizedBox(width: 10),
      Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(name, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 16)),
        Text(code + ' • ' + detail, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(color: C.muted, fontSize: 12)),
        const SizedBox(height: 7),
        Container(
          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
          decoration: BoxDecoration(color: C.mint, borderRadius: BorderRadius.circular(999)),
          child: Text(badge, style: const TextStyle(color: C.greenDark, fontSize: 10, fontWeight: FontWeight.w800)),
        ),
      ])),
      const Icon(Icons.chevron_right, color: C.muted),
    ]),
  );
}

class RouteTile extends StatelessWidget {
  const RouteTile({super.key, required this.title, required this.code, required this.stops, required this.done, required this.color, required this.tap});
  final String title;
  final String code;
  final String stops;
  final String done;
  final Color color;
  final VoidCallback tap;

  @override
  Widget build(BuildContext context) => Panel(
    onTap: tap,
    child: Column(children: [
      Row(children: [
        CircleAvatar(backgroundColor: color.withValues(alpha: .10), child: Icon(Icons.route, color: color)),
        const SizedBox(width: 10),
        Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(title, style: const TextStyle(fontWeight: FontWeight.w900)),
          Text(code + ' • 32 km', style: const TextStyle(color: C.muted, fontSize: 12)),
        ])),
        Pill(en: color == C.green ? 'DONE' : color == C.blue ? 'ACTIVE' : 'PLANNED', ar: color == C.green ? 'مكتمل' : color == C.blue ? 'نشط' : 'مخطط', color: color),
      ]),
      const SizedBox(height: 10),
      Row(children: [
        Expanded(child: Info(label: tx(context, 'Stops', 'الزيارات'), value: stops)),
        Expanded(child: Info(label: tx(context, 'Done', 'تم'), value: done)),
        const Icon(Icons.chevron_right, color: C.muted),
      ]),
    ]),
  );
}

class ActionRow extends StatelessWidget {
  const ActionRow({super.key, required this.icon, required this.title, required this.subtitle, required this.tap});
  final IconData icon;
  final String title;
  final String subtitle;
  final VoidCallback tap;

  @override
  Widget build(BuildContext context) => Panel(
    onTap: tap,
    padding: const EdgeInsets.all(12),
    child: Row(children: [
      CircleAvatar(backgroundColor: C.mint, child: Icon(icon, color: C.green)),
      const SizedBox(width: 10),
      Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(title, style: const TextStyle(fontWeight: FontWeight.w800)),
        Text(subtitle, style: const TextStyle(color: C.muted, fontSize: 12)),
      ])),
      const Icon(Icons.chevron_right, color: C.muted),
    ]),
  );
}

class Checklist extends StatelessWidget {
  const Checklist({super.key, required this.en, required this.arabic, required this.done});
  final String en;
  final String arabic;
  final bool done;

  @override
  Widget build(BuildContext context) => Row(children: [
    Icon(done ? Icons.check_circle : Icons.radio_button_unchecked, color: done ? C.green : C.muted),
    const SizedBox(width: 8),
    Expanded(child: Text(tx(context, en, arabic), style: TextStyle(fontWeight: FontWeight.w700, color: done ? C.ink : C.muted))),
  ]);
}

class Info extends StatelessWidget {
  const Info({super.key, required this.label, required this.value, this.strong = false, this.valueColor});
  final String label;
  final String value;
  final bool strong;
  final Color? valueColor;

  @override
  Widget build(BuildContext context) => Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
    Expanded(child: Text(label, style: const TextStyle(color: C.muted, fontSize: 12))),
    const SizedBox(width: 10),
    Flexible(child: Text(value, textAlign: TextAlign.end, style: TextStyle(color: valueColor ?? C.ink, fontWeight: strong ? FontWeight.w900 : FontWeight.w700, fontSize: strong ? 16 : 13))),
  ]);
}

class ProductTile extends StatelessWidget {
  const ProductTile({super.key, required this.name, required this.sku, required this.price, required this.stock, this.promo = false});
  final String name;
  final String sku;
  final String price;
  final String stock;
  final bool promo;

  @override
  Widget build(BuildContext context) => Panel(
    padding: const EdgeInsets.all(12),
    child: Row(children: [
      Container(width: 56, height: 56, decoration: BoxDecoration(color: C.mint, borderRadius: BorderRadius.circular(14)), child: const Icon(Icons.inventory_2_outlined, color: C.green, size: 28)),
      const SizedBox(width: 10),
      Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          Expanded(child: Text(name, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w900))),
          if (promo) const Pill(en: 'OFFER', ar: 'عرض', color: C.orange),
        ]),
        Text(sku + ' • ' + stock + ' ' + tx(context, 'in van', 'في الفان'), style: const TextStyle(color: C.muted, fontSize: 11)),
        const SizedBox(height: 4),
        Text(price, style: const TextStyle(color: C.greenDark, fontWeight: FontWeight.w900)),
      ])),
      IconButton.filledTonal(onPressed: () => toast(context, tx(context, 'Added to mock cart', 'تمت الإضافة للسلة الوهمية')), icon: const Icon(Icons.add)),
    ]),
  );
}

class OrderContext extends StatelessWidget {
  const OrderContext({super.key});
  @override
  Widget build(BuildContext context) => Panel(
    color: const Color(0xFFF3FAF6),
    child: Row(children: [
      const Icon(Icons.storefront_outlined, color: C.green),
      const SizedBox(width: 9),
      Expanded(child: Text(tx(context, 'Al Noor Market • C-2048', 'سوق النور • C-2048'), style: const TextStyle(fontWeight: FontWeight.w900))),
      const Pill(en: 'B2B', ar: 'جملة', color: C.blue),
    ]),
  );
}

class OrderLine extends StatelessWidget {
  const OrderLine({super.key, required this.name, required this.qty, required this.total});
  final String name;
  final int qty;
  final String total;

  @override
  Widget build(BuildContext context) => Panel(
    padding: const EdgeInsets.all(12),
    child: Column(children: [
      Row(children: [
        Expanded(child: Text(name, style: const TextStyle(fontWeight: FontWeight.w900))),
        IconButton(onPressed: () {}, visualDensity: VisualDensity.compact, icon: const Icon(Icons.more_horiz)),
      ]),
      Row(children: [
        Text(tx(context, 'Selling unit', 'وحدة البيع'), style: const TextStyle(color: C.muted, fontSize: 11)),
        const Spacer(),
        IconButton.filledTonal(onPressed: () {}, visualDensity: VisualDensity.compact, icon: const Icon(Icons.remove, size: 16)),
        Padding(padding: const EdgeInsets.symmetric(horizontal: 8), child: Text('$qty', style: const TextStyle(fontWeight: FontWeight.w900))),
        IconButton.filledTonal(onPressed: () {}, visualDensity: VisualDensity.compact, icon: const Icon(Icons.add, size: 16)),
        const SizedBox(width: 8),
        Text(total, style: const TextStyle(color: C.greenDark, fontWeight: FontWeight.w900)),
      ]),
    ]),
  );
}

class OrderTile extends StatelessWidget {
  const OrderTile({super.key, required this.number, required this.customer, required this.total, required this.state, required this.color});
  final String number;
  final String customer;
  final String total;
  final String state;
  final Color color;

  @override
  Widget build(BuildContext context) => Panel(
    child: Row(children: [
      CircleAvatar(backgroundColor: color.withValues(alpha: .10), child: Icon(Icons.receipt_long_outlined, color: color)),
      const SizedBox(width: 10),
      Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(number, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w900)),
        Text(customer, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(color: C.muted, fontSize: 12)),
        const SizedBox(height: 6),
        Row(children: [
          Container(padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 4), decoration: BoxDecoration(color: color.withValues(alpha: .10), borderRadius: BorderRadius.circular(99)), child: Text(state, style: TextStyle(color: color, fontSize: 10, fontWeight: FontWeight.w800))),
          const Spacer(),
          Text(total, style: const TextStyle(fontWeight: FontWeight.w900)),
        ]),
      ])),
      IconButton(onPressed: () {}, icon: const Icon(Icons.more_vert)),
    ]),
  );
}

class OfferTile extends StatelessWidget {
  const OfferTile({super.key, required this.icon, required this.color, required this.title, required this.subtitle, required this.value});
  final IconData icon;
  final Color color;
  final String title;
  final String subtitle;
  final String value;

  @override
  Widget build(BuildContext context) => Panel(
    child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
      CircleAvatar(backgroundColor: color.withValues(alpha: .10), child: Icon(icon, color: color)),
      const SizedBox(width: 10),
      Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(title, style: const TextStyle(fontWeight: FontWeight.w900)),
        Text(subtitle, style: const TextStyle(color: C.muted, fontSize: 12)),
        const SizedBox(height: 6),
        Text(value, style: TextStyle(color: color, fontSize: 17, fontWeight: FontWeight.w900)),
      ])),
      IconButton.filledTonal(onPressed: () => toast(context, tx(context, 'Offer selected', 'تم اختيار العرض')), icon: const Icon(Icons.add_shopping_cart)),
    ]),
  );
}

class WhiteMetric extends StatelessWidget {
  const WhiteMetric({super.key, required this.label, required this.value});
  final String label;
  final String value;
  @override
  Widget build(BuildContext context) => Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
    Text(label, style: const TextStyle(color: Colors.white70, fontSize: 11)),
    Text(value, style: const TextStyle(color: Colors.white, fontSize: 17, fontWeight: FontWeight.w900)),
  ]);
}

class MoneyTile extends StatelessWidget {
  const MoneyTile({super.key, required this.title, required this.ref, required this.amount, required this.positive});
  final String title;
  final String ref;
  final String amount;
  final bool positive;

  @override
  Widget build(BuildContext context) => Panel(
    padding: const EdgeInsets.all(12),
    child: Row(children: [
      CircleAvatar(backgroundColor: (positive ? C.green : C.orange).withValues(alpha: .10), child: Icon(positive ? Icons.arrow_downward : Icons.account_balance_outlined, color: positive ? C.green : C.orange)),
      const SizedBox(width: 10),
      Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(title, style: const TextStyle(fontWeight: FontWeight.w800)),
        Text(ref, style: const TextStyle(color: C.muted, fontSize: 11)),
      ])),
      Text(amount + ' KWD', style: TextStyle(color: positive ? C.greenDark : C.orange, fontWeight: FontWeight.w900)),
    ]),
  );
}

class InvoiceChoice extends StatelessWidget {
  const InvoiceChoice({super.key, required this.selected, required this.number, required this.date, required this.amount, required this.tap});
  final bool selected;
  final String number;
  final String date;
  final String amount;
  final VoidCallback tap;

  @override
  Widget build(BuildContext context) => Panel(
    onTap: tap,
    color: selected ? const Color(0xFFF3FAF6) : Colors.white,
    child: Row(children: [
      Icon(selected ? Icons.radio_button_checked : Icons.radio_button_unchecked, color: selected ? C.green : C.muted),
      const SizedBox(width: 10),
      Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(number, style: const TextStyle(fontWeight: FontWeight.w900)),
        Text(date, style: const TextStyle(color: C.muted, fontSize: 11)),
      ])),
      Text(amount, style: const TextStyle(color: C.greenDark, fontWeight: FontWeight.w900)),
    ]),
  );
}

class Notice extends StatelessWidget {
  const Notice({super.key, required this.icon, required this.color, required this.title, required this.body, required this.time, this.unread = false});
  final IconData icon;
  final Color color;
  final String title;
  final String body;
  final String time;
  final bool unread;

  @override
  Widget build(BuildContext context) => Panel(
    color: unread ? const Color(0xFFF3FAF6) : Colors.white,
    child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
      CircleAvatar(backgroundColor: color.withValues(alpha: .10), child: Icon(icon, color: color)),
      const SizedBox(width: 10),
      Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          Expanded(child: Text(title, style: const TextStyle(fontWeight: FontWeight.w900))),
          Text(time, style: const TextStyle(color: C.muted, fontSize: 10)),
        ]),
        const SizedBox(height: 3),
        Text(body, style: const TextStyle(color: C.muted, fontSize: 12, height: 1.35)),
      ])),
      if (unread) Container(margin: const EdgeInsetsDirectional.only(start: 6, top: 5), width: 8, height: 8, decoration: const BoxDecoration(color: C.green, shape: BoxShape.circle)),
    ]),
  );
}

class Setting extends StatelessWidget {
  const Setting({super.key, required this.icon, required this.en, required this.ar, required this.value});
  final IconData icon;
  final String en;
  final String ar;
  final String value;

  @override
  Widget build(BuildContext context) => Panel(
    padding: const EdgeInsets.all(12),
    child: Row(children: [
      CircleAvatar(backgroundColor: C.mint, child: Icon(icon, color: C.green)),
      const SizedBox(width: 10),
      Expanded(child: Text(tx(context, en, ar), style: const TextStyle(fontWeight: FontWeight.w800))),
      Text(value, style: const TextStyle(color: C.muted, fontWeight: FontWeight.w700)),
      const SizedBox(width: 4),
      const Icon(Icons.chevron_right, color: C.muted),
    ]),
  );
}

class CompactDateFilter extends StatelessWidget {
  const CompactDateFilter({super.key});

  @override
  Widget build(BuildContext context) => Row(children: [
    Expanded(child: TextField(readOnly: true, decoration: InputDecoration(isDense: true, labelText: tx(context, 'Start', 'من'), suffixIcon: const Icon(Icons.calendar_today_outlined, size: 18)))),
    const SizedBox(width: 6),
    Expanded(child: TextField(readOnly: true, decoration: InputDecoration(isDense: true, labelText: tx(context, 'End', 'إلى'), suffixIcon: const Icon(Icons.calendar_today_outlined, size: 18)))),
    const SizedBox(width: 6),
    SizedBox(width: 52, height: 50, child: FilledButton(style: FilledButton.styleFrom(padding: EdgeInsets.zero), onPressed: () {}, child: const Icon(Icons.search))),
  ]);
}

class AppBottom extends StatelessWidget {
  const AppBottom({super.key, required this.current});
  final MockScreen current;

  int index() {
    if (current == MockScreen.routes) return 1;
    if (current == MockScreen.catalog || current == MockScreen.orders) return 2;
    if (current == MockScreen.wallet) return 3;
    if (current == MockScreen.profile || current == MockScreen.notifications) return 4;
    return 0;
  }

  @override
  Widget build(BuildContext context) => NavigationBar(
    selectedIndex: index(),
    onDestinationSelected: (i) {
      final next = [MockScreen.dashboard, MockScreen.routes, MockScreen.catalog, MockScreen.wallet, MockScreen.profile][i];
      if (next == current) return;
      Navigator.of(context).pushReplacement(MaterialPageRoute(builder: (_) => MockPage(screen: next)));
    },
    destinations: [
      NavigationDestination(icon: const Icon(Icons.home_outlined), selectedIcon: const Icon(Icons.home), label: tx(context, 'Home', 'الرئيسية')),
      NavigationDestination(icon: const Icon(Icons.route_outlined), selectedIcon: const Icon(Icons.route), label: tx(context, 'Route', 'المسار')),
      NavigationDestination(icon: const Icon(Icons.shopping_bag_outlined), selectedIcon: const Icon(Icons.shopping_bag), label: tx(context, 'Sell', 'بيع')),
      NavigationDestination(icon: const Icon(Icons.account_balance_wallet_outlined), selectedIcon: const Icon(Icons.account_balance_wallet), label: tx(context, 'Wallet', 'المحفظة')),
      NavigationDestination(icon: const Icon(Icons.more_horiz), label: tx(context, 'More', 'المزيد')),
    ],
  );
}

class FakeMap extends StatelessWidget {
  const FakeMap({super.key});

  @override
  Widget build(BuildContext context) => CustomPaint(
    painter: MapPainter(),
    child: const Stack(children: [
      Positioned(top: 140, left: 42, child: MapPin(label: '1', color: C.green)),
      Positioned(top: 220, right: 58, child: MapPin(label: '2', color: C.green)),
      Positioned(top: 330, left: 95, child: MapPin(label: '3', color: C.blue, large: true)),
      Positioned(top: 430, right: 92, child: MapPin(label: '4', color: C.muted)),
    ]),
  );
}

class MapPin extends StatelessWidget {
  const MapPin({super.key, required this.label, required this.color, this.large = false});
  final String label;
  final Color color;
  final bool large;

  @override
  Widget build(BuildContext context) => Container(
    width: large ? 46 : 38,
    height: large ? 46 : 38,
    alignment: Alignment.center,
    decoration: BoxDecoration(color: color, shape: BoxShape.circle, border: Border.all(color: Colors.white, width: 3), boxShadow: const [BoxShadow(color: Color(0x22000000), blurRadius: 10, offset: Offset(0, 4))]),
    child: Text(label, style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w900)),
  );
}

class MapPainter extends CustomPainter {
  @override
  void paint(Canvas canvas, Size size) {
    canvas.drawRect(Offset.zero & size, Paint()..color = const Color(0xFFEFF3F0));
    final small = Paint()..color = Colors.white..strokeWidth = 5..style = PaintingStyle.stroke;
    for (var i = 1; i < 8; i++) {
      final y = size.height * i / 9;
      canvas.drawLine(Offset(0, y), Offset(size.width, y + 22), small);
    }
    for (var i = 1; i < 6; i++) {
      final x = size.width * i / 6;
      canvas.drawLine(Offset(x, 0), Offset(x - 35, size.height), small);
    }
    final path = Path()
      ..moveTo(-20, size.height * .18)
      ..cubicTo(size.width * .25, size.height * .15, size.width * .22, size.height * .46, size.width * .55, size.height * .44)
      ..cubicTo(size.width * .78, size.height * .43, size.width * .76, size.height * .73, size.width + 20, size.height * .70);
    canvas.drawPath(path, Paint()..color = Colors.white..strokeWidth = 13..style = PaintingStyle.stroke..strokeCap = StrokeCap.round);
    canvas.drawPath(path, Paint()..color = C.green..strokeWidth = 4..style = PaintingStyle.stroke..strokeCap = StrokeCap.round);
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

void toast(BuildContext context, String message) {
  ScaffoldMessenger.of(context)
    ..hideCurrentSnackBar()
    ..showSnackBar(SnackBar(content: Text(message), behavior: SnackBarBehavior.floating));
}
