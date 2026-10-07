
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:google_fonts/google_fonts.dart';

void main() => runApp(const DriverMockupApp());

class DriverMockupApp extends StatefulWidget {
  const DriverMockupApp({super.key});
  @override
  State<DriverMockupApp> createState() => _DriverMockupAppState();
}

class _DriverMockupAppState extends State<DriverMockupApp> {
  bool arabic = true;

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      debugShowCheckedModeBanner: false,
      locale: Locale(arabic ? 'ar' : 'en'),
      supportedLocales: const [Locale('ar'), Locale('en')],
      localizationsDelegates: const [
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      theme: FoodexTheme.light(),
      home: LoginPage(onLanguage: () => setState(() => arabic = !arabic)),
    );
  }
}

abstract final class F {
  static const green = Color(0xFF158A3A);
  static const greenDark = Color(0xFF165D2D);
  static const greenBright = Color(0xFF27B658);
  static const greenSoft = Color(0xFFEAF7EF);
  static const orange = Color(0xFFEE731C);
  static const orangeSoft = Color(0xFFFFF1E6);
  static const blue = Color(0xFF4B8CF5);
  static const red = Color(0xFFEF5350);
  static const ink = Color(0xFF172033);
  static const muted = Color(0xFF667085);
  static const surface = Color(0xFFFFFFFF);
  static const bg = Color(0xFFF6F8F6);
  static const border = Color(0xFFE3E8E4);
}

class FoodexTheme {
  static ThemeData light() {
    const scheme = ColorScheme.light(
      primary: F.green,
      onPrimary: Colors.white,
      secondary: F.orange,
      onSecondary: Colors.white,
      surface: F.surface,
      onSurface: F.ink,
      error: F.red,
      onError: Colors.white,
    );
    final base = ThemeData(useMaterial3: true, colorScheme: scheme);
    final text = GoogleFonts.alexandriaTextTheme(base.textTheme).apply(
      bodyColor: F.ink,
      displayColor: F.ink,
    );
    return base.copyWith(
      textTheme: text,
      primaryTextTheme: GoogleFonts.alexandriaTextTheme(base.primaryTextTheme),
      scaffoldBackgroundColor: F.bg,
      appBarTheme: const AppBarTheme(
        backgroundColor: F.surface,
        foregroundColor: F.ink,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        toolbarHeight: 64,
        titleTextStyle: TextStyle(color: F.ink, fontSize: 19, fontWeight: FontWeight.w800),
      ),
      navigationBarTheme: const NavigationBarThemeData(
        height: 74,
        backgroundColor: F.surface,
        indicatorColor: F.greenSoft,
        elevation: 8,
        surfaceTintColor: Colors.transparent,
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          backgroundColor: F.green,
          foregroundColor: Colors.white,
          minimumSize: const Size(0, 52),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          foregroundColor: F.greenDark,
          minimumSize: const Size(0, 50),
          side: const BorderSide(color: F.border),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        ),
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: F.surface,
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 15),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(16),
          borderSide: const BorderSide(color: F.border),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(16),
          borderSide: const BorderSide(color: F.green, width: 1.5),
        ),
      ),
    );
  }
}

bool isAr(BuildContext c) => Localizations.localeOf(c).languageCode == 'ar';
String tr(BuildContext c, String en, String ar) => isAr(c) ? ar : en;

void open(BuildContext c, Widget w) {
  Navigator.of(c).push(MaterialPageRoute(builder: (_) => w));
}

class LoginPage extends StatefulWidget {
  const LoginPage({super.key, required this.onLanguage});
  final VoidCallback onLanguage;
  @override
  State<LoginPage> createState() => _LoginPageState();
}

class _LoginPageState extends State<LoginPage> {
  bool remember = true;
  bool biometric = true;
  bool hide = true;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Stack(children: [
        const Positioned.fill(child: DecoratedBox(
          decoration: BoxDecoration(
            gradient: LinearGradient(
              begin: Alignment.topCenter,
              end: Alignment.bottomCenter,
              colors: [F.greenDark, F.green, F.bg, F.bg],
              stops: [0, .34, .34, 1],
            ),
          ),
        )),
        SafeArea(child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(20, 20, 20, 28),
          child: Center(child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 430),
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              Align(
                alignment: AlignmentDirectional.centerEnd,
                child: TextButton.icon(
                  style: TextButton.styleFrom(foregroundColor: Colors.white),
                  onPressed: widget.onLanguage,
                  icon: const Icon(Icons.language_rounded),
                  label: Text(isAr(context) ? 'EN' : 'عربي'),
                ),
              ),
              Container(
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(24)),
                child: Image.asset('assets/branding/foodex-economical-group.webp', height: 86, fit: BoxFit.contain),
              ),
              const SizedBox(height: 12),
              Text(
                tr(context, 'Driver App', 'تطبيق السائق'),
                textAlign: TextAlign.center,
                style: const TextStyle(color: Colors.white, fontSize: 22, fontWeight: FontWeight.w900),
              ),
              const SizedBox(height: 22),
              Card(
                margin: EdgeInsets.zero,
                elevation: 0,
                shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(28),
                  side: const BorderSide(color: F.border),
                ),
                child: Padding(
                  padding: const EdgeInsets.all(22),
                  child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                    Text(tr(context, 'Welcome back', 'مرحباً بعودتك'), textAlign: TextAlign.center, style: const TextStyle(fontSize: 23, fontWeight: FontWeight.w900)),
                    const SizedBox(height: 5),
                    Text(tr(context, 'Sign in to start your driver workspace.', 'سجّل الدخول لبدء مساحة عمل السائق.'), textAlign: TextAlign.center, style: const TextStyle(color: F.muted)),
                    const SizedBox(height: 22),
                    TextField(decoration: InputDecoration(labelText: tr(context, 'Email', 'البريد الإلكتروني'), prefixIcon: const Icon(Icons.alternate_email_rounded))),
                    const SizedBox(height: 12),
                    TextField(
                      obscureText: hide,
                      decoration: InputDecoration(
                        labelText: tr(context, 'Password', 'كلمة المرور'),
                        prefixIcon: const Icon(Icons.lock_outline_rounded),
                        suffixIcon: IconButton(onPressed: () => setState(() => hide = !hide), icon: Icon(hide ? Icons.visibility_outlined : Icons.visibility_off_outlined)),
                      ),
                    ),
                    CheckboxListTile(
                      contentPadding: EdgeInsets.zero,
                      dense: true,
                      controlAffinity: ListTileControlAffinity.leading,
                      value: remember,
                      title: Text(tr(context, 'Remember me', 'تذكرني')),
                      onChanged: (v) => setState(() {
                        remember = v ?? false;
                        if (!remember) biometric = false;
                      }),
                    ),
                    CheckboxListTile(
                      contentPadding: EdgeInsets.zero,
                      dense: true,
                      controlAffinity: ListTileControlAffinity.leading,
                      value: biometric,
                      title: Text(tr(context, 'Enable biometric login', 'تفعيل الدخول بالبصمة')),
                      subtitle: Text(tr(context, 'Requires Remember Me', 'يتطلب تفعيل تذكرني')),
                      onChanged: (v) => setState(() {
                        biometric = v ?? false;
                        if (biometric) remember = true;
                      }),
                    ),
                    const SizedBox(height: 8),
                    FilledButton.icon(
                      onPressed: () => open(context, const LocationGatePage()),
                      icon: const Icon(Icons.login_rounded),
                      label: Text(tr(context, 'Sign in', 'تسجيل الدخول')),
                    ),
                    const SizedBox(height: 10),
                    OutlinedButton.icon(
                      onPressed: () => open(context, const LocationGatePage()),
                      icon: const Icon(Icons.fingerprint_rounded),
                      label: Text(tr(context, 'Unlock with biometrics', 'فتح بالبصمة')),
                    ),
                  ]),
                ),
              ),
            ]),
          )),
        )),
      ]),
    );
  }
}

class LocationGatePage extends StatelessWidget {
  const LocationGatePage({super.key});
  @override
  Widget build(BuildContext context) {
    return Scaffold(body: SafeArea(child: Center(child: SingleChildScrollView(
      padding: const EdgeInsets.all(22),
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 420),
        child: Box(
          child: Column(children: [
            Container(
              width: 76,
              height: 76,
              decoration: BoxDecoration(color: F.greenSoft, borderRadius: BorderRadius.circular(24)),
              child: const Icon(Icons.location_searching_rounded, color: F.greenDark, size: 40),
            ),
            const SizedBox(height: 18),
            Text(tr(context, 'Location required', 'الموقع مطلوب'), style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w900)),
            const SizedBox(height: 8),
            Text(tr(context, 'FOODEX Driver requires precise location while you are on duty.', 'يتطلب تطبيق سائق فودكس الموقع الدقيق أثناء العمل.'), textAlign: TextAlign.center, style: const TextStyle(color: F.muted, height: 1.45)),
            const SizedBox(height: 20),
            FilledButton.icon(
              onPressed: () => Navigator.of(context).pushAndRemoveUntil(MaterialPageRoute(builder: (_) => const DriverShell()), (_) => false),
              icon: const Icon(Icons.check_circle_outline_rounded),
              label: Text(tr(context, 'Allow precise location', 'السماح بالموقع الدقيق')),
            ),
            const SizedBox(height: 10),
            OutlinedButton.icon(onPressed: () {}, icon: const Icon(Icons.app_settings_alt_outlined), label: Text(tr(context, 'Open app settings', 'فتح إعدادات التطبيق'))),
            const SizedBox(height: 10),
            OutlinedButton.icon(onPressed: () {}, icon: const Icon(Icons.location_on_outlined), label: Text(tr(context, 'Open location settings', 'فتح إعدادات الموقع'))),
          ]),
        ),
      ),
    ))));
  }
}

class DriverShell extends StatefulWidget {
  const DriverShell({super.key, this.initial = 0});
  final int initial;
  @override
  State<DriverShell> createState() => _DriverShellState();
}

class _DriverShellState extends State<DriverShell> {
  late int index = widget.initial;

  @override
  Widget build(BuildContext context) {
    final pages = [const HomeTab(), const DeliveriesTab(), const NotificationsTab()];
    final titles = [tr(context, 'Driver App', 'تطبيق السائق'), tr(context, 'Deliveries', 'التوصيلات'), tr(context, 'Notifications', 'الإشعارات')];
    return Scaffold(
      appBar: AppBar(
        title: Text(titles[index]),
        actions: [
          IconButton(onPressed: () => _pushAlert(context), icon: const Icon(Icons.notifications_active_outlined)),
          IconButton(onPressed: () {}, icon: const Icon(Icons.refresh_rounded)),
          if (index == 0) IconButton(onPressed: () {}, icon: const Icon(Icons.logout_rounded)),
        ],
      ),
      body: pages[index],
      bottomNavigationBar: NavigationBar(
        selectedIndex: index,
        onDestinationSelected: (i) => setState(() => index = i),
        destinations: [
          NavigationDestination(icon: const Icon(Icons.home_outlined), selectedIcon: const Icon(Icons.home_rounded), label: tr(context, 'Home', 'الرئيسية')),
          NavigationDestination(icon: const Icon(Icons.route_outlined), selectedIcon: const Icon(Icons.route_rounded), label: tr(context, 'Deliveries', 'التوصيلات')),
          NavigationDestination(icon: const Icon(Icons.notifications_none_rounded), selectedIcon: const Icon(Icons.notifications_rounded), label: tr(context, 'Notifications', 'الإشعارات')),
        ],
      ),
    );
  }

  void _pushAlert(BuildContext context) {
    ScaffoldMessenger.of(context)
      ..hideCurrentSnackBar()
      ..showSnackBar(SnackBar(
        content: Text(tr(context, 'Order ORD-20481\nNew delivery assigned', 'الطلب ORD-20481\nتم تعيين توصيلة جديدة')),
        action: SnackBarAction(label: tr(context, 'View', 'عرض'), onPressed: () => open(context, const AssignmentDetailPage())),
      ));
  }
}

class HomeTab extends StatelessWidget {
  const HomeTab({super.key});
  @override
  Widget build(BuildContext context) {
    return RefreshIndicator(
      onRefresh: () async {},
      child: ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 22),
        children: [
          Row(children: [
            Container(width: 40, height: 40, decoration: BoxDecoration(color: F.greenSoft, borderRadius: BorderRadius.circular(12)), child: const Icon(Icons.local_shipping_rounded, color: F.greenDark)),
            const SizedBox(width: 10),
            Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(tr(context, 'Driver App', 'تطبيق السائق'), style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 18)),
              Text(tr(context, 'Ahmed Al-Salem • B2C Driver', 'أحمد السالم • سائق B2C'), style: const TextStyle(color: F.muted, fontSize: 12)),
            ])),
          ]),
          const SizedBox(height: 14),
          Container(
            padding: const EdgeInsets.all(18),
            decoration: BoxDecoration(
              gradient: const LinearGradient(colors: [F.greenDark, F.green]),
              borderRadius: BorderRadius.circular(24),
              boxShadow: const [BoxShadow(color: Color(0x24165D2D), blurRadius: 22, offset: Offset(0, 10))],
            ),
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              Row(children: [
                Container(width: 42, height: 42, decoration: BoxDecoration(color: const Color(0x29FFFFFF), borderRadius: BorderRadius.circular(14)), child: const Icon(Icons.route_rounded, color: Colors.white)),
                const SizedBox(width: 12),
                Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(tr(context, 'Ahmed Al-Salem', 'أحمد السالم'), style: const TextStyle(color: Colors.white, fontSize: 22, fontWeight: FontWeight.w900)),
                  Text(tr(context, 'Your active delivery workspace for today', 'مساحة التوصيلات النشطة لليوم'), style: const TextStyle(color: Color(0xFFE3F5E9), fontSize: 12, fontWeight: FontWeight.w600)),
                ])),
              ]),
              const SizedBox(height: 16),
              Row(children: [
                Expanded(child: FilledButton.icon(
                  style: FilledButton.styleFrom(backgroundColor: Colors.white, foregroundColor: F.greenDark, minimumSize: const Size(0, 48)),
                  onPressed: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const DriverShell(initial: 1))),
                  icon: const Icon(Icons.route_rounded),
                  label: Text(tr(context, 'Open deliveries', 'فتح التوصيلات'), maxLines: 1),
                )),
                const SizedBox(width: 10),
                Expanded(child: OutlinedButton.icon(
                  style: OutlinedButton.styleFrom(foregroundColor: Colors.white, side: const BorderSide(color: Colors.white54), minimumSize: const Size(0, 48)),
                  onPressed: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const DriverShell(initial: 2))),
                  icon: const Icon(Icons.notifications_none_rounded),
                  label: Text(tr(context, 'Notifications', 'الإشعارات'), maxLines: 1),
                )),
              ]),
              const SizedBox(height: 10),
              OutlinedButton.icon(
                style: OutlinedButton.styleFrom(foregroundColor: Colors.white, side: const BorderSide(color: Colors.white54), minimumSize: const Size(0, 48)),
                onPressed: () => open(context, const WalletPage()),
                icon: const Icon(Icons.account_balance_wallet_outlined),
                label: Text(tr(context, 'Open wallet', 'فتح المحفظة')),
              ),
            ]),
          ),
          const SizedBox(height: 18),
          Text(tr(context, 'Status summary', 'ملخص الحالات'), style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w900)),
          const SizedBox(height: 10),
          Wrap(spacing: 8, runSpacing: 8, children: [
            Metric('3', tr(context, 'Accepted', 'مقبولة'), F.orange),
            Metric('2', tr(context, 'Picked up', 'تم الاستلام'), F.blue),
            Metric('4', tr(context, 'Out for delivery', 'قيد التوصيل'), F.blue),
            Metric('1', tr(context, 'Failed', 'فشلت'), F.red),
            Metric('12', tr(context, 'Delivered', 'تم التسليم'), F.green),
          ]),
        ],
      ),
    );
  }
}

class Metric extends StatelessWidget {
  const Metric(this.value, this.label, this.color, {super.key});
  final String value;
  final String label;
  final Color color;
  @override
  Widget build(BuildContext context) {
    final width = (MediaQuery.sizeOf(context).width - 48) / 2;
    return SizedBox(width: width, child: Box(
      padding: const EdgeInsets.all(12),
      child: Row(children: [
        CircleAvatar(backgroundColor: color.withAlpha(28), child: Text(value, style: TextStyle(color: color, fontWeight: FontWeight.w900))),
        const SizedBox(width: 9),
        Expanded(child: Text(label, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 12))),
      ]),
    ));
  }
}

class DeliveriesTab extends StatelessWidget {
  const DeliveriesTab({super.key});
  @override
  Widget build(BuildContext context) {
    return ListView(
      padding: const EdgeInsets.fromLTRB(12, 10, 12, 20),
      children: [
        Row(children: [
          Expanded(child: FilterChip(label: Text(tr(context, 'Today', 'اليوم')), selected: true, onSelected: (_) {})),
          const SizedBox(width: 6),
          Expanded(child: FilterChip(label: Text(tr(context, 'All', 'الكل')), selected: false, onSelected: (_) {})),
          const SizedBox(width: 6),
          Expanded(child: FilterChip(label: Text(tr(context, 'Custom', 'مخصص')), selected: false, onSelected: (_) {})),
        ]),
        const SizedBox(height: 10),
        Row(children: [
          Expanded(child: TextField(readOnly: true, decoration: InputDecoration(isDense: true, labelText: tr(context, 'Start', 'من'), suffixIcon: const Icon(Icons.calendar_today_outlined, size: 17)))),
          const SizedBox(width: 6),
          Expanded(child: TextField(readOnly: true, decoration: InputDecoration(isDense: true, labelText: tr(context, 'End', 'إلى'), suffixIcon: const Icon(Icons.calendar_today_outlined, size: 17)))),
          const SizedBox(width: 6),
          SizedBox(width: 50, height: 50, child: FilledButton(style: FilledButton.styleFrom(padding: EdgeInsets.zero), onPressed: () {}, child: const Icon(Icons.search))),
        ]),
        const SizedBox(height: 10),
        const StaleBanner(),
        const SizedBox(height: 10),
        AssignmentCard(reference: 'ORD-20481', status: tr(context, 'Accepted', 'مقبولة'), color: F.orange, store: tr(context, 'FOODEX Market - Salmiya', 'فودكس ماركت - السالمية'), customer: tr(context, 'Sara Al-Mutairi', 'سارة المطيري'), onTap: () => open(context, const AssignmentDetailPage())),
        const SizedBox(height: 8),
        AssignmentCard(reference: 'ORD-20477', status: tr(context, 'Picked up', 'تم الاستلام'), color: F.blue, store: tr(context, 'Fresh Corner', 'فريش كورنر'), customer: tr(context, 'Mohammed Al-Hajri', 'محمد الهاجري'), onTap: () => open(context, const AssignmentDetailPage())),
        const SizedBox(height: 8),
        AssignmentCard(reference: 'ORD-20475', status: tr(context, 'Out for delivery', 'قيد التوصيل'), color: F.blue, store: tr(context, 'FOODEX Market - Sharq', 'فودكس ماركت - شرق'), customer: tr(context, 'Noura Al-Sabah', 'نورة الصباح'), onTap: () => open(context, const AssignmentDetailPage())),
      ],
    );
  }
}

class AssignmentCard extends StatelessWidget {
  const AssignmentCard({super.key, required this.reference, required this.status, required this.color, required this.store, required this.customer, required this.onTap});
  final String reference;
  final String status;
  final Color color;
  final String store;
  final String customer;
  final VoidCallback onTap;
  @override
  Widget build(BuildContext context) {
    return Box(onTap: onTap, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Row(children: [
        Expanded(child: Text(reference, maxLines: 1, softWrap: false, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w900))),
        Pill(status, color),
        PopupMenuButton<String>(itemBuilder: (_) => [
          PopupMenuItem(value: 'open', child: Text(tr(context, 'Open', 'فتح'))),
          PopupMenuItem(value: 'navigate', child: Text(tr(context, 'Navigate', 'ملاحة'))),
        ]),
      ]),
      Text(store, style: const TextStyle(fontWeight: FontWeight.w800)),
      const SizedBox(height: 3),
      Text(customer, style: const TextStyle(color: F.muted)),
      const SizedBox(height: 3),
      Text(tr(context, 'Salmiya, Block 4, Street 12', 'السالمية، قطعة 4، شارع 12'), maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(color: F.muted, fontSize: 12)),
    ]));
  }
}

class AssignmentDetailPage extends StatelessWidget {
  const AssignmentDetailPage({super.key});
  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(tr(context, 'Delivery details', 'تفاصيل التوصيل')), actions: [IconButton(onPressed: () {}, icon: const Icon(Icons.refresh_rounded))]),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(12, 10, 12, 24),
        children: [
          Info(tr(context, 'Order', 'الطلب'), 'ORD-20481', strong: true),
          Info(tr(context, 'Assignment status', 'حالة المهمة'), tr(context, 'Accepted', 'مقبولة')),
          Info(tr(context, 'Order status', 'حالة الطلب'), tr(context, 'Confirmed', 'مؤكد')),
          Info(tr(context, 'Store', 'المتجر'), tr(context, 'FOODEX Market - Salmiya', 'فودكس ماركت - السالمية')),
          Info(tr(context, 'Customer', 'العميل'), tr(context, 'Sara Al-Mutairi', 'سارة المطيري')),
          const Info('Phone', '+965 5000 1234'),
          const SizedBox(height: 8),
          Box(
            color: const Color(0xFFF2F8F5),
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                const Icon(Icons.location_on_outlined, color: Color(0xFF087347)),
                const SizedBox(width: 8),
                Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(tr(context, 'Address', 'العنوان'), style: const TextStyle(fontWeight: FontWeight.w900)),
                  Text(tr(context, 'Salmiya, Block 4, Street 12, House 18', 'السالمية، قطعة 4، شارع 12، منزل 18')),
                ])),
              ]),
              const SizedBox(height: 10),
              FilledButton.icon(onPressed: () => toast(context, tr(context, 'Navigation launch simulated', 'تمت محاكاة فتح الملاحة')), icon: const Icon(Icons.map_outlined), label: Text(tr(context, 'Open navigation', 'فتح الملاحة'))),
            ]),
          ),
          const SizedBox(height: 14),
          Text(tr(context, 'Actions', 'الإجراءات'), style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w900)),
          const SizedBox(height: 8),
          Row(children: [
            Expanded(child: FilledButton.icon(onPressed: () => _startSheet(context), icon: const Icon(Icons.local_shipping_outlined), label: Text(tr(context, 'Start delivery', 'بدء التوصيل')))),
            const SizedBox(width: 8),
            Expanded(child: OutlinedButton.icon(onPressed: () => open(context, const CompletionPage(failed: true)), icon: const Icon(Icons.report_problem_outlined), label: Text(tr(context, 'Failed', 'فشل')))),
          ]),
          const SizedBox(height: 10),
          Row(children: [
            Expanded(child: OutlinedButton.icon(onPressed: () => open(context, const InvoicePage()), icon: const Icon(Icons.receipt_long_rounded), label: Text(tr(context, 'Invoice', 'الفاتورة')))),
            const SizedBox(width: 8),
            Expanded(child: OutlinedButton.icon(onPressed: () => _collectionDialog(context), icon: const Icon(Icons.payments_outlined), label: Text(tr(context, 'Collect', 'تحصيل')))),
          ]),
          const SizedBox(height: 14),
          Box(child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Text(tr(context, 'Settlement', 'التسوية'), style: const TextStyle(fontWeight: FontWeight.w900)),
            const SizedBox(height: 8),
            Info(tr(context, 'Order total', 'إجمالي الطلب'), '12.750 KWD'),
            Info(tr(context, 'Balance applied', 'الرصيد المستخدم'), '4.000 KWD'),
            Info(tr(context, 'Remaining', 'المتبقي'), '8.750 KWD'),
            Info(tr(context, 'Collect now', 'تحصيل الآن'), '8.750 KWD', strong: true),
          ])),
          const SizedBox(height: 12),
          Info(tr(context, 'Payment', 'الدفع'), tr(context, 'Cash · Partially paid', 'نقدي · مدفوع جزئياً')),
          Info(tr(context, 'Total', 'الإجمالي'), '12.750 KWD', strong: true),
          Info(tr(context, 'Customer note', 'ملاحظة العميل'), tr(context, 'Call on arrival', 'الاتصال عند الوصول')),
          const SizedBox(height: 12),
          Text(tr(context, 'Items', 'المنتجات'), style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w900)),
          const SizedBox(height: 8),
          ProductLine(name: tr(context, 'Premium Rice 5 KG', 'أرز فاخر 5 كجم'), sku: 'SKU-RICE-05', qty: '2', total: '4.200 KWD'),
          const SizedBox(height: 8),
          ProductLine(name: tr(context, 'Mineral Water', 'مياه معدنية'), sku: 'SKU-WATER-12', qty: '1', total: '0.750 KWD'),
          const SizedBox(height: 12),
          FilledButton.icon(onPressed: () => open(context, const CompletionPage()), icon: const Icon(Icons.check_circle_outline_rounded), label: Text(tr(context, 'Complete delivery', 'إتمام التوصيل'))),
        ],
      ),
    );
  }

  void _startSheet(BuildContext context) {
    showModalBottomSheet<void>(
      context: context,
      showDragHandle: true,
      isScrollControlled: true,
      builder: (sheetContext) => SafeArea(top: false, child: Padding(
        padding: EdgeInsets.fromLTRB(20, 4, 20, 20 + MediaQuery.viewInsetsOf(sheetContext).bottom),
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Text(tr(context, 'Start delivery', 'بدء التوصيل'), style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w900)),
          const SizedBox(height: 6),
          const Text('ORD-20481', maxLines: 1, overflow: TextOverflow.ellipsis),
          const SizedBox(height: 16),
          TextField(minLines: 2, maxLines: 4, decoration: InputDecoration(labelText: tr(context, 'Optional note', 'ملاحظة اختيارية'))),
          const SizedBox(height: 16),
          Row(children: [
            Expanded(child: OutlinedButton(onPressed: () {
              Navigator.pop(sheetContext);
              open(context, const CompletionPage(failed: true));
            }, child: Text(tr(context, 'Delivery failed', 'فشل التوصيل')))),
            const SizedBox(width: 12),
            Expanded(child: FilledButton(onPressed: () => Navigator.pop(sheetContext), child: Text(tr(context, 'Confirm start', 'تأكيد البدء')))),
          ]),
        ]),
      )),
    );
  }

  void _collectionDialog(BuildContext context) {
    showDialog<void>(
      context: context,
      builder: (d) => AlertDialog(
        title: Text(tr(context, 'Collect & deliver', 'تحصيل وتسليم')),
        content: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Text(tr(context, 'Amount to collect now', 'المبلغ المطلوب تحصيله الآن')),
          const Text('8.750 KWD', style: TextStyle(fontSize: 25, fontWeight: FontWeight.w900, color: F.greenDark)),
          const SizedBox(height: 12),
          TextField(controller: TextEditingController(text: '8.750'), keyboardType: const TextInputType.numberWithOptions(decimal: true), decoration: InputDecoration(labelText: tr(context, 'Collected amount', 'المبلغ المحصل'), suffixText: 'KWD')),
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.pop(d), child: Text(tr(context, 'Cancel', 'إلغاء'))),
          FilledButton(onPressed: () {
            Navigator.pop(d);
            open(context, const CompletionPage());
          }, child: Text(tr(context, 'Confirm', 'تأكيد'))),
        ],
      ),
    );
  }
}

class CompletionPage extends StatefulWidget {
  const CompletionPage({super.key, this.failed = false});
  final bool failed;
  @override
  State<CompletionPage> createState() => _CompletionPageState();
}

class _CompletionPageState extends State<CompletionPage> {
  late bool failed = widget.failed;
  bool proof = false;
  String reason = 'customer';

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(failed ? tr(context, 'Failed delivery', 'فشل التوصيل') : tr(context, 'Complete delivery', 'إتمام التوصيل'))),
      body: ListView(padding: const EdgeInsets.all(20), children: [
        SegmentedButton<bool>(
          segments: [
            ButtonSegment(value: false, icon: const Icon(Icons.check_circle_outline_rounded), label: Text(tr(context, 'Delivered', 'تم التسليم'))),
            ButtonSegment(value: true, icon: const Icon(Icons.report_problem_outlined), label: Text(tr(context, 'Failed', 'فشل'))),
          ],
          selected: {failed},
          onSelectionChanged: (v) => setState(() => failed = v.first),
        ),
        const SizedBox(height: 16),
        if (failed) ...[
          DropdownButtonFormField<String>(
            initialValue: reason,
            decoration: InputDecoration(labelText: tr(context, 'Failure reason', 'سبب الفشل')),
            items: [
              DropdownMenuItem(value: 'customer', child: Text(tr(context, 'Customer unavailable', 'العميل غير متاح'))),
              DropdownMenuItem(value: 'address', child: Text(tr(context, 'Wrong address', 'عنوان غير صحيح'))),
              DropdownMenuItem(value: 'other', child: Text(tr(context, 'Other', 'أخرى'))),
            ],
            onChanged: (v) => setState(() => reason = v ?? reason),
          ),
          const SizedBox(height: 12),
        ],
        TextField(minLines: 2, maxLines: 4, decoration: InputDecoration(labelText: tr(context, 'Optional note', 'ملاحظة اختيارية'))),
        const SizedBox(height: 16),
        Text(tr(context, 'Attach proof', 'إرفاق إثبات'), style: const TextStyle(fontWeight: FontWeight.w900)),
        const SizedBox(height: 8),
        Row(children: [
          Expanded(child: OutlinedButton.icon(onPressed: () => setState(() => proof = true), icon: const Icon(Icons.photo_camera_rounded), label: Text(tr(context, 'Camera', 'الكاميرا')))),
          const SizedBox(width: 8),
          Expanded(child: OutlinedButton.icon(onPressed: () => setState(() => proof = true), icon: const Icon(Icons.photo_library_rounded), label: Text(tr(context, 'Gallery', 'المعرض')))),
        ]),
        if (proof) ...[
          const SizedBox(height: 10),
          Box(child: Row(children: [
            const Icon(Icons.image_outlined, color: F.greenDark),
            const SizedBox(width: 10),
            Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(tr(context, 'Proof attached', 'تم إرفاق الإثبات'), style: const TextStyle(fontWeight: FontWeight.w900)),
              const Text('delivery-proof.jpg · 1.2 MB', style: TextStyle(color: F.muted, fontSize: 12)),
            ])),
            TextButton(onPressed: () => setState(() => proof = false), child: Text(tr(context, 'Remove', 'إزالة'))),
          ])),
        ],
        const SizedBox(height: 18),
        failed
            ? OutlinedButton(onPressed: () => Navigator.pop(context), child: Text(tr(context, 'Submit failed delivery', 'تسجيل فشل التوصيل')))
            : FilledButton.icon(onPressed: () => Navigator.pop(context), icon: const Icon(Icons.check_circle_outline_rounded), label: Text(tr(context, 'Confirm delivered', 'تأكيد التسليم'))),
      ]),
    );
  }
}

class InvoicePage extends StatelessWidget {
  const InvoicePage({super.key});
  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(tr(context, 'Invoice', 'الفاتورة'))),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        Box(child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Image.asset('assets/branding/foodex-economical-group.webp', height: 70, fit: BoxFit.contain),
          const SizedBox(height: 12),
          Row(children: [
            Expanded(child: Text(tr(context, 'Invoice INV-8842', 'الفاتورة INV-8842'), style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w900))),
            Pill(tr(context, 'ISSUED', 'صادرة'), F.green),
          ]),
          const SizedBox(height: 12),
          const Info('Order', 'ORD-20481'),
          const Info('Revision', '1'),
          const Info('Issued', '07 Oct 2026 · 10:04'),
          const Divider(height: 24),
          ProductLine(name: tr(context, 'Premium Rice 5 KG', 'أرز فاخر 5 كجم'), sku: 'SKU-RICE-05', qty: '2', total: '4.200 KWD', embedded: true),
          const SizedBox(height: 8),
          ProductLine(name: tr(context, 'Mineral Water', 'مياه معدنية'), sku: 'SKU-WATER-12', qty: '1', total: '0.750 KWD', embedded: true),
          const Divider(height: 24),
          Info(tr(context, 'Subtotal', 'المجموع الفرعي'), '11.857 KWD'),
          Info(tr(context, 'Delivery', 'التوصيل'), '0.250 KWD'),
          Info(tr(context, 'Tax', 'الضريبة'), '0.643 KWD'),
          Info(tr(context, 'Total', 'الإجمالي'), '12.750 KWD', strong: true),
          Info(tr(context, 'Payment', 'الدفع'), tr(context, 'Cash · Partially paid', 'نقدي · مدفوع جزئياً')),
        ])),
        const SizedBox(height: 12),
        FilledButton.tonalIcon(onPressed: () {}, icon: const Icon(Icons.picture_as_pdf_outlined), label: Text(tr(context, 'Download PDF', 'تحميل PDF'))),
      ]),
    );
  }
}

class NotificationsTab extends StatelessWidget {
  const NotificationsTab({super.key});
  @override
  Widget build(BuildContext context) {
    return ListView(padding: const EdgeInsets.fromLTRB(12, 10, 12, 20), children: [
      const StaleBanner(),
      const SizedBox(height: 10),
      NotificationCard(icon: Icons.local_shipping_rounded, title: tr(context, 'Order ORD-20481', 'الطلب ORD-20481'), body: tr(context, 'New delivery assigned to you.', 'تم تعيين توصيلة جديدة لك.'), time: '12:18', unread: true, onTap: () => open(context, const AssignmentDetailPage())),
      const SizedBox(height: 8),
      NotificationCard(icon: Icons.route_rounded, title: tr(context, 'Assignment #7312', 'المهمة #7312'), body: tr(context, 'Customer address was updated.', 'تم تحديث عنوان العميل.'), time: '11:43', unread: true, onTap: () => open(context, const AssignmentDetailPage())),
      const SizedBox(height: 8),
      NotificationCard(icon: Icons.account_balance_wallet_outlined, title: tr(context, 'Wallet updated', 'تم تحديث المحفظة'), body: tr(context, 'Collection posted successfully.', 'تم تسجيل التحصيل بنجاح.'), time: '10:21', unread: false, onTap: () => open(context, const WalletPage())),
    ]);
  }
}

class WalletPage extends StatelessWidget {
  const WalletPage({super.key});
  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(tr(context, 'Wallet', 'المحفظة')), actions: [IconButton(onPressed: () {}, icon: const Icon(Icons.refresh_rounded))]),
      body: ListView(padding: const EdgeInsets.fromLTRB(12, 10, 12, 20), children: [
        const StaleBanner(),
        const SizedBox(height: 10),
        Box(child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Row(children: [
            const CircleAvatar(backgroundColor: F.greenSoft, child: Icon(Icons.account_balance_wallet_outlined, color: F.greenDark)),
            const SizedBox(width: 10),
            Expanded(child: Text(tr(context, 'KWD collection account', 'حساب التحصيل بالدينار'), style: const TextStyle(fontWeight: FontWeight.w900))),
          ]),
          const SizedBox(height: 12),
          Info(tr(context, 'Custody balance', 'رصيد العهدة'), '36.500 KWD', strong: true),
          Info(tr(context, 'Available to remit', 'المتاح للتوريد'), '28.750 KWD', strong: true),
          const SizedBox(height: 12),
          FilledButton.icon(onPressed: () => _remit(context), icon: const Icon(Icons.account_balance_rounded), label: Text(tr(context, 'Submit remittance', 'تقديم توريد'))),
          const SizedBox(height: 16),
          Text(tr(context, 'Recent', 'آخر العمليات'), style: const TextStyle(fontWeight: FontWeight.w900)),
          const SizedBox(height: 8),
          const MoneyRow(title: 'Collection ORD-20481', amount: '+8.750 KWD', status: 'Posted', positive: true),
          const SizedBox(height: 8),
          const MoneyRow(title: 'Remittance REM-1042', amount: '-25.000 KWD', status: 'Accepted', positive: false),
        ])),
      ]),
    );
  }

  void _remit(BuildContext context) {
    showDialog<void>(context: context, builder: (d) => AlertDialog(
      title: Text(tr(context, 'Submit remittance', 'تقديم توريد')),
      content: TextField(controller: TextEditingController(text: '28.750'), keyboardType: const TextInputType.numberWithOptions(decimal: true), decoration: InputDecoration(labelText: tr(context, 'Amount', 'المبلغ'), suffixText: 'KWD')),
      actions: [
        TextButton(onPressed: () => Navigator.pop(d), child: Text(tr(context, 'Cancel', 'إلغاء'))),
        FilledButton(onPressed: () => Navigator.pop(d), child: Text(tr(context, 'Submit', 'إرسال'))),
      ],
    ));
  }
}

class StaleBanner extends StatelessWidget {
  const StaleBanner({super.key});
  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 9),
      decoration: BoxDecoration(color: const Color(0xFFFFF7E6), borderRadius: BorderRadius.circular(14), border: Border.all(color: const Color(0xFFF1D39A))),
      child: Row(children: [
        const Icon(Icons.cloud_off_outlined, size: 18, color: Color(0xFF8A5A00)),
        const SizedBox(width: 8),
        Expanded(child: Text(tr(context, 'Showing last confirmed data · 12:18', 'عرض آخر بيانات مؤكدة · 12:18'), style: const TextStyle(color: Color(0xFF6B4A00), fontWeight: FontWeight.w700, fontSize: 12))),
      ]),
    );
  }
}

class NotificationCard extends StatelessWidget {
  const NotificationCard({super.key, required this.icon, required this.title, required this.body, required this.time, required this.unread, required this.onTap});
  final IconData icon;
  final String title;
  final String body;
  final String time;
  final bool unread;
  final VoidCallback onTap;
  @override
  Widget build(BuildContext context) {
    return Box(
      onTap: onTap,
      color: unread ? const Color(0xFFF4FAF6) : F.surface,
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        CircleAvatar(backgroundColor: F.greenSoft, child: Icon(icon, color: F.greenDark)),
        const SizedBox(width: 10),
        Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Expanded(child: Text(title, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w900))),
            Text(time, style: const TextStyle(color: F.muted, fontSize: 10)),
          ]),
          const SizedBox(height: 3),
          Text(body, style: const TextStyle(color: F.muted, fontSize: 12, height: 1.35)),
        ])),
        if (unread) Container(margin: const EdgeInsetsDirectional.only(start: 6, top: 5), width: 8, height: 8, decoration: const BoxDecoration(color: F.green, shape: BoxShape.circle)),
      ]),
    );
  }
}

class ProductLine extends StatelessWidget {
  const ProductLine({super.key, required this.name, required this.sku, required this.qty, required this.total, this.embedded = false});
  final String name;
  final String sku;
  final String qty;
  final String total;
  final bool embedded;
  @override
  Widget build(BuildContext context) {
    final row = Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Container(width: 58, height: 58, decoration: BoxDecoration(color: const Color(0xFFF0F5F1), borderRadius: BorderRadius.circular(12)), child: const Icon(Icons.inventory_2_outlined, color: F.greenDark)),
      const SizedBox(width: 10),
      Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(name, style: const TextStyle(fontWeight: FontWeight.w900)),
        Text(sku, style: const TextStyle(color: F.muted, fontSize: 11)),
        const SizedBox(height: 4),
        Text(tr(context, 'Quantity: ', 'الكمية: ') + qty, style: const TextStyle(fontSize: 12)),
      ])),
      Text(total, style: const TextStyle(fontWeight: FontWeight.w900)),
    ]);
    return embedded ? row : Box(child: row);
  }
}

class MoneyRow extends StatelessWidget {
  const MoneyRow({super.key, required this.title, required this.amount, required this.status, required this.positive});
  final String title;
  final String amount;
  final String status;
  final bool positive;
  @override
  Widget build(BuildContext context) {
    final color = positive ? F.green : F.orange;
    return Row(children: [
      CircleAvatar(backgroundColor: color.withAlpha(28), child: Icon(positive ? Icons.south_west_rounded : Icons.north_east_rounded, color: color)),
      const SizedBox(width: 10),
      Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(title, style: const TextStyle(fontWeight: FontWeight.w800)),
        Text(status, style: const TextStyle(color: F.muted, fontSize: 11)),
      ])),
      Text(amount, style: TextStyle(color: color, fontWeight: FontWeight.w900)),
    ]);
  }
}

class Info extends StatelessWidget {
  const Info(this.label, this.value, {super.key, this.strong = false});
  final String label;
  final String value;
  final bool strong;
  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        SizedBox(width: 116, child: Text(label, style: const TextStyle(color: F.muted, fontSize: 12))),
        const SizedBox(width: 8),
        Expanded(child: Text(value, textAlign: TextAlign.end, style: TextStyle(fontWeight: strong ? FontWeight.w900 : FontWeight.w700, fontSize: strong ? 15 : 13))),
      ]),
    );
  }
}

class Pill extends StatelessWidget {
  const Pill(this.text, this.color, {super.key});
  final String text;
  final Color color;
  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
      decoration: BoxDecoration(color: color.withAlpha(26), borderRadius: BorderRadius.circular(999)),
      child: Text(text, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: color, fontSize: 10, fontWeight: FontWeight.w900)),
    );
  }
}

class Box extends StatelessWidget {
  const Box({super.key, required this.child, this.onTap, this.color = F.surface, this.padding = const EdgeInsets.all(14)});
  final Widget child;
  final VoidCallback? onTap;
  final Color color;
  final EdgeInsets padding;
  @override
  Widget build(BuildContext context) {
    return Material(
      color: color,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18), side: BorderSide(color: color == F.surface ? F.border : Colors.transparent)),
      clipBehavior: Clip.antiAlias,
      child: InkWell(onTap: onTap, child: Padding(padding: padding, child: child)),
    );
  }
}

void toast(BuildContext context, String message) {
  ScaffoldMessenger.of(context)
    ..hideCurrentSnackBar()
    ..showSnackBar(SnackBar(content: Text(message), behavior: SnackBarBehavior.floating));
}
