import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';

void main() => runApp(const CustomerMockupApp());

class CustomerMockupApp extends StatefulWidget {
  const CustomerMockupApp({super.key});

  @override
  State<CustomerMockupApp> createState() => _CustomerMockupAppState();
}

class _CustomerMockupAppState extends State<CustomerMockupApp> {
  bool arabic = true;

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      debugShowCheckedModeBanner: false,
      title: arabic ? 'فودكس - موكاب العميل' : 'FOODEX Customer Mockup',
      locale: Locale(arabic ? 'ar' : 'en'),
      supportedLocales: const [Locale('ar'), Locale('en')],
      localizationsDelegates: const [
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      theme: CustomerTheme.light(),
      home: MarketplaceMock(
        onLanguage: () => setState(() => arabic = !arabic),
      ),
    );
  }
}

abstract final class C {
  static const deepGreen = Color(0xFF00452F);
  static const deepGreenStrong = Color(0xFF003C2A);
  static const deepGreenSoft = Color(0xFF0A5B40);
  static const lime = Color(0xFF9BE252);
  static const limeSoft = Color(0xFFE7F8D7);
  static const mint = Color(0xFFEDF7F1);
  static const mintStrong = Color(0xFFC5E0CC);
  static const white = Color(0xFFFFFFFF);
  static const ink = Color(0xFF17231D);
  static const inkSoft = Color(0xFF34453C);
  static const muted = Color(0xFF68766E);
  static const border = Color(0xFFDDE8E1);
  static const warning = Color(0xFFF59E0B);
  static const info = Color(0xFF3B82F6);
  static const red = Color(0xFFE5484D);
}

abstract final class CustomerTheme {
  static ThemeData light() {
    final scheme = ColorScheme.fromSeed(
      seedColor: C.deepGreen,
      primary: C.deepGreen,
      secondary: C.lime,
      surface: C.white,
      brightness: Brightness.light,
    );
    return ThemeData(
      useMaterial3: true,
      colorScheme: scheme,
      scaffoldBackgroundColor: C.mint,
      fontFamilyFallback: const ['Arial', 'sans-serif'],
      appBarTheme: const AppBarTheme(
        backgroundColor: C.deepGreen,
        foregroundColor: C.white,
        elevation: 0,
        surfaceTintColor: Colors.transparent,
        toolbarHeight: 58,
      ),
      navigationBarTheme: const NavigationBarThemeData(
        height: 72,
        backgroundColor: C.white,
        indicatorColor: C.limeSoft,
        surfaceTintColor: Colors.transparent,
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          backgroundColor: C.deepGreen,
          foregroundColor: C.white,
          minimumSize: const Size(0, 52),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
          textStyle: const TextStyle(fontWeight: FontWeight.w800),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          foregroundColor: C.deepGreen,
          minimumSize: const Size(0, 50),
          side: const BorderSide(color: C.border),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        ),
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: C.white,
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(999),
          borderSide: const BorderSide(color: C.border),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(999),
          borderSide: const BorderSide(color: C.border),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(999),
          borderSide: const BorderSide(color: C.lime, width: 1.5),
        ),
      ),
    );
  }
}

bool isAr(BuildContext context) =>
    Localizations.localeOf(context).languageCode == 'ar';

String t(BuildContext context, String en, String ar) => isAr(context) ? ar : en;

enum CustomerMockScreen {
  marketplace,
  auth,
  retailHome,
  categories,
  products,
  productDetails,
  offers,
  favorites,
  cart,
  checkout,
  orders,
  orderDetails,
  orderTracking,
  notifications,
  addresses,
  profile,
  wholesaleHome,
  wholesaleDashboard,
  wholesaleProducts,
  wholesaleProductDetails,
  wholesaleCart,
  wholesaleCheckout,
  wholesaleOrders,
  wholesaleOrderDetails,
  invoices,
  invoiceDetails,
  accountStatement,
  purchaseReports,
  topProducts,
  wholesaleNotifications,
  wholesaleProfile,
}

extension CustomerMockMeta on CustomerMockScreen {
  String en() {
    switch (this) {
      case CustomerMockScreen.marketplace: return 'Marketplace';
      case CustomerMockScreen.auth: return 'Login & Register';
      case CustomerMockScreen.retailHome: return 'Retail Store Home';
      case CustomerMockScreen.categories: return 'Categories';
      case CustomerMockScreen.products: return 'Products';
      case CustomerMockScreen.productDetails: return 'Product Details';
      case CustomerMockScreen.offers: return 'Offers';
      case CustomerMockScreen.favorites: return 'Favorites';
      case CustomerMockScreen.cart: return 'Cart';
      case CustomerMockScreen.checkout: return 'Checkout';
      case CustomerMockScreen.orders: return 'My Orders';
      case CustomerMockScreen.orderDetails: return 'Order Details';
      case CustomerMockScreen.orderTracking: return 'Order Tracking';
      case CustomerMockScreen.notifications: return 'Notifications';
      case CustomerMockScreen.addresses: return 'Addresses';
      case CustomerMockScreen.profile: return 'Profile & Settings';
      case CustomerMockScreen.wholesaleHome: return 'Wholesale Home';
      case CustomerMockScreen.wholesaleDashboard: return 'Business Dashboard';
      case CustomerMockScreen.wholesaleProducts: return 'Wholesale Products';
      case CustomerMockScreen.wholesaleProductDetails: return 'Wholesale Product';
      case CustomerMockScreen.wholesaleCart: return 'Wholesale Cart';
      case CustomerMockScreen.wholesaleCheckout: return 'Wholesale Checkout';
      case CustomerMockScreen.wholesaleOrders: return 'Wholesale Orders';
      case CustomerMockScreen.wholesaleOrderDetails: return 'Wholesale Order Details';
      case CustomerMockScreen.invoices: return 'Invoices';
      case CustomerMockScreen.invoiceDetails: return 'Invoice Details';
      case CustomerMockScreen.accountStatement: return 'Account Statement';
      case CustomerMockScreen.purchaseReports: return 'Purchase Reports';
      case CustomerMockScreen.topProducts: return 'Top Products';
      case CustomerMockScreen.wholesaleNotifications: return 'Business Notifications';
      case CustomerMockScreen.wholesaleProfile: return 'Business Profile';
    }
  }

  String ar() {
    switch (this) {
      case CustomerMockScreen.marketplace: return 'المتجر الرئيسي';
      case CustomerMockScreen.auth: return 'الدخول والتسجيل';
      case CustomerMockScreen.retailHome: return 'واجهة متجر التجزئة';
      case CustomerMockScreen.categories: return 'الأقسام';
      case CustomerMockScreen.products: return 'المنتجات';
      case CustomerMockScreen.productDetails: return 'تفاصيل المنتج';
      case CustomerMockScreen.offers: return 'العروض';
      case CustomerMockScreen.favorites: return 'المفضلة';
      case CustomerMockScreen.cart: return 'السلة';
      case CustomerMockScreen.checkout: return 'إتمام الطلب';
      case CustomerMockScreen.orders: return 'طلباتي';
      case CustomerMockScreen.orderDetails: return 'تفاصيل الطلب';
      case CustomerMockScreen.orderTracking: return 'تتبع الطلب';
      case CustomerMockScreen.notifications: return 'الإشعارات';
      case CustomerMockScreen.addresses: return 'العناوين';
      case CustomerMockScreen.profile: return 'الملف والإعدادات';
      case CustomerMockScreen.wholesaleHome: return 'متجر الجملة';
      case CustomerMockScreen.wholesaleDashboard: return 'لوحة حساب الأعمال';
      case CustomerMockScreen.wholesaleProducts: return 'منتجات الجملة';
      case CustomerMockScreen.wholesaleProductDetails: return 'منتج الجملة';
      case CustomerMockScreen.wholesaleCart: return 'سلة الجملة';
      case CustomerMockScreen.wholesaleCheckout: return 'إتمام طلب الجملة';
      case CustomerMockScreen.wholesaleOrders: return 'طلبات الجملة';
      case CustomerMockScreen.wholesaleOrderDetails: return 'تفاصيل طلب الجملة';
      case CustomerMockScreen.invoices: return 'الفواتير';
      case CustomerMockScreen.invoiceDetails: return 'تفاصيل الفاتورة';
      case CustomerMockScreen.accountStatement: return 'كشف الحساب';
      case CustomerMockScreen.purchaseReports: return 'تقارير المشتريات';
      case CustomerMockScreen.topProducts: return 'أكثر المنتجات شراءً';
      case CustomerMockScreen.wholesaleNotifications: return 'إشعارات الأعمال';
      case CustomerMockScreen.wholesaleProfile: return 'ملف حساب الأعمال';
    }
  }

  IconData icon() {
    switch (this) {
      case CustomerMockScreen.marketplace: return Icons.storefront_outlined;
      case CustomerMockScreen.auth: return Icons.lock_outline;
      case CustomerMockScreen.retailHome: return Icons.home_outlined;
      case CustomerMockScreen.categories: return Icons.grid_view_outlined;
      case CustomerMockScreen.products: return Icons.inventory_2_outlined;
      case CustomerMockScreen.productDetails: return Icons.shopping_bag_outlined;
      case CustomerMockScreen.offers: return Icons.local_offer_outlined;
      case CustomerMockScreen.favorites: return Icons.favorite_border;
      case CustomerMockScreen.cart: return Icons.shopping_cart_outlined;
      case CustomerMockScreen.checkout: return Icons.credit_card_outlined;
      case CustomerMockScreen.orders: return Icons.receipt_long_outlined;
      case CustomerMockScreen.orderDetails: return Icons.description_outlined;
      case CustomerMockScreen.orderTracking: return Icons.local_shipping_outlined;
      case CustomerMockScreen.notifications: return Icons.notifications_none;
      case CustomerMockScreen.addresses: return Icons.location_on_outlined;
      case CustomerMockScreen.profile: return Icons.person_outline;
      case CustomerMockScreen.wholesaleHome: return Icons.warehouse_outlined;
      case CustomerMockScreen.wholesaleDashboard: return Icons.dashboard_outlined;
      case CustomerMockScreen.wholesaleProducts: return Icons.inventory_outlined;
      case CustomerMockScreen.wholesaleProductDetails: return Icons.inventory_2_outlined;
      case CustomerMockScreen.wholesaleCart: return Icons.shopping_cart_checkout;
      case CustomerMockScreen.wholesaleCheckout: return Icons.fact_check_outlined;
      case CustomerMockScreen.wholesaleOrders: return Icons.list_alt_outlined;
      case CustomerMockScreen.wholesaleOrderDetails: return Icons.assignment_outlined;
      case CustomerMockScreen.invoices: return Icons.request_quote_outlined;
      case CustomerMockScreen.invoiceDetails: return Icons.receipt_outlined;
      case CustomerMockScreen.accountStatement: return Icons.account_balance_wallet_outlined;
      case CustomerMockScreen.purchaseReports: return Icons.insights_outlined;
      case CustomerMockScreen.topProducts: return Icons.trending_up_outlined;
      case CustomerMockScreen.wholesaleNotifications: return Icons.campaign_outlined;
      case CustomerMockScreen.wholesaleProfile: return Icons.business_outlined;
    }
  }

  bool get wholesale => index >= CustomerMockScreen.wholesaleHome.index;
}

void openScreen(BuildContext context, CustomerMockScreen screen) {
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
    final groups = <String, List<CustomerMockScreen>>{
      t(context, 'Entry & account', 'الدخول والحساب'): [
        CustomerMockScreen.marketplace,
        CustomerMockScreen.auth,
        CustomerMockScreen.notifications,
        CustomerMockScreen.addresses,
        CustomerMockScreen.profile,
      ],
      t(context, 'Retail journey', 'رحلة التجزئة'): [
        CustomerMockScreen.retailHome,
        CustomerMockScreen.categories,
        CustomerMockScreen.products,
        CustomerMockScreen.productDetails,
        CustomerMockScreen.offers,
        CustomerMockScreen.favorites,
        CustomerMockScreen.cart,
        CustomerMockScreen.checkout,
        CustomerMockScreen.orders,
        CustomerMockScreen.orderDetails,
        CustomerMockScreen.orderTracking,
      ],
      t(context, 'Wholesale / B2B', 'الجملة / حساب الأعمال'): [
        CustomerMockScreen.wholesaleHome,
        CustomerMockScreen.wholesaleDashboard,
        CustomerMockScreen.wholesaleProducts,
        CustomerMockScreen.wholesaleProductDetails,
        CustomerMockScreen.wholesaleCart,
        CustomerMockScreen.wholesaleCheckout,
        CustomerMockScreen.wholesaleOrders,
        CustomerMockScreen.wholesaleOrderDetails,
        CustomerMockScreen.invoices,
        CustomerMockScreen.invoiceDetails,
        CustomerMockScreen.accountStatement,
        CustomerMockScreen.purchaseReports,
        CustomerMockScreen.topProducts,
        CustomerMockScreen.wholesaleNotifications,
        CustomerMockScreen.wholesaleProfile,
      ],
    };

    return Scaffold(
      appBar: AppBar(
        title: const Brand(compact: true),
        actions: [
          TextButton.icon(
            style: TextButton.styleFrom(foregroundColor: C.white),
            onPressed: onLanguage,
            icon: const Icon(Icons.language, size: 18),
            label: Text(arabic ? 'EN' : 'عربي'),
          ),
          const SizedBox(width: 6),
        ],
      ),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 14, 16, 30),
        children: [
          const DesignBanner(),
          const SizedBox(height: 12),
          HeroCard(onStart: () => openScreen(context, CustomerMockScreen.marketplace)),
          const SizedBox(height: 20),
          ...groups.entries.expand((entry) sync* {
            yield SectionTitle(entry.key);
            yield const SizedBox(height: 8);
            yield LayoutBuilder(
              builder: (context, constraints) {
                final two = constraints.maxWidth > 560;
                final itemWidth = two
                    ? (constraints.maxWidth - 10) / 2
                    : constraints.maxWidth;
                return Wrap(
                  spacing: 10,
                  runSpacing: 10,
                  children: entry.value.map((screen) {
                    return SizedBox(
                      width: itemWidth,
                      child: Panel(
                        onTap: () => openScreen(context, screen),
                        child: Row(
                          children: [
                            CircleAvatar(
                              backgroundColor: screen.wholesale ? C.limeSoft : C.mintStrong.withValues(alpha: .45),
                              child: Icon(screen.icon(), color: C.deepGreen),
                            ),
                            const SizedBox(width: 10),
                            Expanded(
                              child: Text(
                                isAr(context) ? screen.ar() : screen.en(),
                                style: const TextStyle(fontWeight: FontWeight.w800),
                              ),
                            ),
                            const Icon(Icons.chevron_right_rounded, color: C.muted),
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

  final CustomerMockScreen screen;

  bool get showBottom => {
        CustomerMockScreen.retailHome,
        CustomerMockScreen.categories,
        CustomerMockScreen.products,
        CustomerMockScreen.offers,
        CustomerMockScreen.cart,
        CustomerMockScreen.orders,
        CustomerMockScreen.profile,
        CustomerMockScreen.wholesaleHome,
        CustomerMockScreen.wholesaleDashboard,
        CustomerMockScreen.wholesaleProducts,
        CustomerMockScreen.wholesaleCart,
        CustomerMockScreen.wholesaleOrders,
        CustomerMockScreen.wholesaleProfile,
      }.contains(screen);

  @override
  Widget build(BuildContext context) {
    if (screen == CustomerMockScreen.marketplace) {
      return const MarketplaceMock();
    }
    if (screen == CustomerMockScreen.auth) {
      return const AuthMock();
    }
    if (screen == CustomerMockScreen.orderTracking) {
      return const OrderTrackingPageMock();
    }

    return Scaffold(
      appBar: AppBar(
        title: Text(
          isAr(context) ? screen.ar() : screen.en(),
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
      body: bodyFor(context, screen),
      bottomNavigationBar: showBottom ? CustomerBottom(current: screen) : null,
    );
  }
}

Widget bodyFor(BuildContext context, CustomerMockScreen screen) {
  switch (screen) {
    case CustomerMockScreen.retailHome: return const RetailHomeMock();
    case CustomerMockScreen.categories: return const CategoriesMock();
    case CustomerMockScreen.products: return const ProductsMock(wholesale: false);
    case CustomerMockScreen.productDetails: return const ProductDetailsMock(wholesale: false);
    case CustomerMockScreen.offers: return const OffersMock();
    case CustomerMockScreen.favorites: return const FavoritesMock();
    case CustomerMockScreen.cart: return const CartMock(wholesale: false);
    case CustomerMockScreen.checkout: return const CheckoutMock(wholesale: false);
    case CustomerMockScreen.orders: return const OrdersMock(wholesale: false);
    case CustomerMockScreen.orderDetails: return const OrderDetailsMock(wholesale: false);
    case CustomerMockScreen.orderTracking: return const OrderTrackingMock();
    case CustomerMockScreen.notifications: return const NotificationsMock(business: false);
    case CustomerMockScreen.addresses: return const AddressesMock();
    case CustomerMockScreen.profile: return const ProfileMock(business: false);
    case CustomerMockScreen.wholesaleHome: return const WholesaleHomeMock();
    case CustomerMockScreen.wholesaleDashboard: return const WholesaleDashboardMock();
    case CustomerMockScreen.wholesaleProducts: return const ProductsMock(wholesale: true);
    case CustomerMockScreen.wholesaleProductDetails: return const ProductDetailsMock(wholesale: true);
    case CustomerMockScreen.wholesaleCart: return const CartMock(wholesale: true);
    case CustomerMockScreen.wholesaleCheckout: return const CheckoutMock(wholesale: true);
    case CustomerMockScreen.wholesaleOrders: return const OrdersMock(wholesale: true);
    case CustomerMockScreen.wholesaleOrderDetails: return const OrderDetailsMock(wholesale: true);
    case CustomerMockScreen.invoices: return const InvoicesMock();
    case CustomerMockScreen.invoiceDetails: return const InvoiceDetailsMock();
    case CustomerMockScreen.accountStatement: return const AccountStatementMock();
    case CustomerMockScreen.purchaseReports: return const PurchaseReportsMock();
    case CustomerMockScreen.topProducts: return const TopProductsMock();
    case CustomerMockScreen.wholesaleNotifications: return const NotificationsMock(business: true);
    case CustomerMockScreen.wholesaleProfile: return const ProfileMock(business: true);
    case CustomerMockScreen.marketplace:
    case CustomerMockScreen.auth:
      return const SizedBox.shrink();
  }
}

class MarketplaceMock extends StatelessWidget {
  const MarketplaceMock({super.key, this.onLanguage});
  final VoidCallback? onLanguage;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFFF8FAF9),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.fromLTRB(16, 14, 16, 28),
          children: [
            Row(
              children: [
                const Brand(),
                const Spacer(),
                const MockBadge(),
                const SizedBox(width: 6),
                if (onLanguage != null)
                  IconButton.filledTonal(
                    onPressed: onLanguage,
                    icon: const Icon(Icons.language),
                  ),
                const SizedBox(width: 6),
                IconButton.filledTonal(
                  onPressed: () => openScreen(context, CustomerMockScreen.notifications),
                  icon: const Icon(Icons.notifications_none),
                ),
                const SizedBox(width: 6),
                IconButton.filledTonal(
                  onPressed: () => openScreen(context, CustomerMockScreen.auth),
                  icon: const Icon(Icons.person_outline),
                ),
              ],
            ),
            const SizedBox(height: 14),
            TextField(
              decoration: InputDecoration(
                hintText: t(context, 'Search products or stores', 'ابحث عن منتج أو متجر'),
                prefixIcon: const Icon(Icons.search),
                suffixIcon: IconButton(
                  onPressed: () => toast(context, t(context, 'Barcode scanner mock', 'محاكاة مسح الباركود')),
                  icon: const Icon(Icons.qr_code_scanner),
                ),
              ),
            ),
            const SizedBox(height: 18),
            Panel(
              color: C.deepGreen,
              onTap: () => openScreen(context, CustomerMockScreen.wholesaleHome),
              child: Row(
                children: [
                  Container(
                    width: 58,
                    height: 58,
                    decoration: BoxDecoration(
                      color: C.lime,
                      borderRadius: BorderRadius.circular(18),
                    ),
                    child: const Icon(Icons.warehouse_outlined, color: C.deepGreenStrong, size: 32),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Text(
                          'FOODEX Wholesale',
                          style: TextStyle(color: C.white, fontSize: 20, fontWeight: FontWeight.w900),
                        ),
                        const SizedBox(height: 3),
                        Text(
                          t(context, 'Business pricing, invoices and wholesale ordering', 'أسعار الأعمال والفواتير وطلبات الجملة'),
                          style: const TextStyle(color: Colors.white70, fontSize: 12),
                        ),
                      ],
                    ),
                  ),
                  const Icon(Icons.chevron_right_rounded, color: C.white),
                ],
              ),
            ),
            const SizedBox(height: 18),
            SectionHeader(
              title: t(context, 'Retail stores', 'متاجر التجزئة'),
              action: t(context, 'View all', 'عرض الكل'),
            ),
            const SizedBox(height: 8),
            SizedBox(
              height: 158,
              child: ListView(
                scrollDirection: Axis.horizontal,
                children: [
                  StoreCard(
                    title: t(context, 'FOODEX Market', 'فودكس ماركت'),
                    subtitle: t(context, 'Grocery • 25–35 min', 'بقالة • 25–35 دقيقة'),
                    icon: Icons.local_grocery_store_outlined,
                    onTap: () => openScreen(context, CustomerMockScreen.retailHome),
                  ),
                  StoreCard(
                    title: t(context, 'Fresh Corner', 'فريش كورنر'),
                    subtitle: t(context, 'Fresh food • 30–40 min', 'طازج • 30–40 دقيقة'),
                    icon: Icons.eco_outlined,
                    onTap: () => openScreen(context, CustomerMockScreen.retailHome),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 18),
            SectionHeader(title: t(context, 'Today’s offers', 'عروض اليوم')),
            const SizedBox(height: 8),
            const PromoBanner(),
            const SizedBox(height: 18),
            SectionHeader(title: t(context, 'Recommended', 'مقترح لك')),
            const SizedBox(height: 8),
            ProductRow(
              onTap: () => openScreen(context, CustomerMockScreen.productDetails),
            ),
          ],
        ),
      ),
    );
  }
}

class AuthMock extends StatefulWidget {
  const AuthMock({super.key});

  @override
  State<AuthMock> createState() => _AuthMockState();
}

class _AuthMockState extends State<AuthMock> {
  bool register = false;
  bool remember = true;
  bool biometric = true;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.fromLTRB(20, 24, 20, 28),
          children: [
            const Row(children: [Brand(), Spacer(), MockBadge()]),
            const SizedBox(height: 22),
            Container(
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                color: C.deepGreen,
                borderRadius: BorderRadius.circular(26),
              ),
              child: Row(
                children: [
                  Container(
                    width: 56,
                    height: 56,
                    decoration: const BoxDecoration(color: C.lime, shape: BoxShape.circle),
                    child: const Icon(Icons.person_rounded, color: C.deepGreenStrong, size: 30),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(t(context, 'Customer App', 'تطبيق العميل'), style: const TextStyle(color: C.white, fontSize: 22, fontWeight: FontWeight.w900)),
                        Text(t(context, 'Retail + Wholesale in one account', 'تجزئة وجملة في حساب واحد'), style: const TextStyle(color: Colors.white70)),
                      ],
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 22),
            SegmentedButton<bool>(
              segments: [
                ButtonSegment(value: false, label: Text(t(context, 'Login', 'دخول'))),
                ButtonSegment(value: true, label: Text(t(context, 'Register', 'تسجيل جديد'))),
              ],
              selected: {register},
              onSelectionChanged: (v) => setState(() => register = v.first),
            ),
            const SizedBox(height: 18),
            if (register) ...[
              TextField(decoration: InputDecoration(labelText: t(context, 'Full name', 'الاسم الكامل'), prefixIcon: const Icon(Icons.person_outline))),
              const SizedBox(height: 10),
              TextField(decoration: InputDecoration(labelText: t(context, 'Mobile', 'رقم الهاتف'), prefixIcon: const Icon(Icons.phone_outlined))),
              const SizedBox(height: 10),
            ],
            TextField(decoration: InputDecoration(labelText: t(context, 'Email', 'البريد الإلكتروني'), prefixIcon: const Icon(Icons.email_outlined))),
            const SizedBox(height: 10),
            TextField(obscureText: true, decoration: InputDecoration(labelText: t(context, 'Password', 'كلمة المرور'), prefixIcon: const Icon(Icons.lock_outline))),
            if (register) ...[
              const SizedBox(height: 10),
              TextField(obscureText: true, decoration: InputDecoration(labelText: t(context, 'Confirm password', 'تأكيد كلمة المرور'), prefixIcon: const Icon(Icons.lock_reset_outlined))),
            ],
            const SizedBox(height: 6),
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              value: remember,
              activeThumbColor: C.deepGreen,
              onChanged: (v) => setState(() => remember = v),
              title: Text(t(context, 'Remember me', 'تذكرني'), style: const TextStyle(fontWeight: FontWeight.w800)),
            ),
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              value: biometric,
              activeThumbColor: C.deepGreen,
              onChanged: remember ? (v) => setState(() => biometric = v) : null,
              title: Text(t(context, 'Biometric login', 'الدخول بالبصمة'), style: const TextStyle(fontWeight: FontWeight.w800)),
            ),
            const SizedBox(height: 8),
            FilledButton.icon(
              onPressed: () => openScreen(context, CustomerMockScreen.marketplace),
              icon: Icon(register ? Icons.person_add_alt_1 : Icons.login),
              label: Text(register ? t(context, 'Create account', 'إنشاء الحساب') : t(context, 'Sign in', 'تسجيل الدخول')),
            ),
            const SizedBox(height: 10),
            OutlinedButton.icon(
              onPressed: () => toast(context, t(context, 'Biometric mock unlocked', 'تم فتح محاكاة البصمة')),
              icon: const Icon(Icons.fingerprint),
              label: Text(t(context, 'Use biometrics', 'استخدام البصمة')),
            ),
          ],
        ),
      ),
    );
  }
}

class RetailHomeMock extends StatelessWidget {
  const RetailHomeMock({super.key});

  @override
  Widget build(BuildContext context) {
    return ScrollBody(
      children: [
        StoreContextBanner(name: t(context, 'FOODEX Market', 'فودكس ماركت'), wholesale: false),
        const SizedBox(height: 10),
        TextField(
          decoration: InputDecoration(
            hintText: t(context, 'Search this store', 'ابحث داخل المتجر'),
            prefixIcon: const Icon(Icons.search),
            suffixIcon: const Icon(Icons.qr_code_scanner),
          ),
        ),
        const SizedBox(height: 14),
        const PromoBanner(),
        const SizedBox(height: 16),
        SectionHeader(title: t(context, 'Categories', 'الأقسام'), action: t(context, 'All', 'الكل')),
        const SizedBox(height: 8),
        const CategoryStrip(),
        const SizedBox(height: 16),
        SectionHeader(title: t(context, 'All products', 'كل المنتجات'), action: t(context, 'See all', 'عرض الكل')),
        const SizedBox(height: 8),
        ProductGridMock(
          wholesale: false,
          onOpen: () => openScreen(context, CustomerMockScreen.productDetails),
        ),
      ],
    );
  }
}

class CategoriesMock extends StatelessWidget {
  const CategoriesMock({super.key});

  @override
  Widget build(BuildContext context) {
    final items = [
      [Icons.local_grocery_store_outlined, 'Grocery', 'بقالة'],
      [Icons.local_drink_outlined, 'Drinks', 'مشروبات'],
      [Icons.bakery_dining_outlined, 'Bakery', 'مخبوزات'],
      [Icons.egg_alt_outlined, 'Dairy', 'ألبان'],
      [Icons.cleaning_services_outlined, 'Home care', 'العناية بالمنزل'],
      [Icons.child_care_outlined, 'Baby', 'الأطفال'],
      [Icons.pets_outlined, 'Pets', 'الحيوانات'],
      [Icons.more_horiz, 'More', 'المزيد'],
    ];
    return GridView.builder(
      padding: const EdgeInsets.all(16),
      gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
        crossAxisCount: 2,
        crossAxisSpacing: 10,
        mainAxisSpacing: 10,
        childAspectRatio: 1.45,
      ),
      itemCount: items.length,
      itemBuilder: (context, index) {
        final item = items[index];
        return Panel(
          onTap: () => openScreen(context, CustomerMockScreen.products),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Icon(item[0] as IconData, color: C.deepGreen, size: 34),
              const SizedBox(height: 8),
              Text(t(context, item[1] as String, item[2] as String), style: const TextStyle(fontWeight: FontWeight.w900)),
            ],
          ),
        );
      },
    );
  }
}

class ProductsMock extends StatelessWidget {
  const ProductsMock({super.key, required this.wholesale});
  final bool wholesale;

  @override
  Widget build(BuildContext context) {
    final target = wholesale ? CustomerMockScreen.wholesaleProductDetails : CustomerMockScreen.productDetails;
    return ScrollBody(
      children: [
        if (wholesale) StoreContextBanner(name: t(context, 'Al Safa Trading', 'شركة الصفا للتجارة'), wholesale: true),
        TextField(
          decoration: InputDecoration(
            hintText: t(context, 'Search products', 'ابحث في المنتجات'),
            prefixIcon: const Icon(Icons.search),
            suffixIcon: const Icon(Icons.tune),
          ),
        ),
        const SizedBox(height: 10),
        SingleChildScrollView(
          scrollDirection: Axis.horizontal,
          child: Row(
            children: [
              FilterChip(label: Text(t(context, 'All', 'الكل')), selected: true, onSelected: (_) {}),
              const SizedBox(width: 6),
              FilterChip(label: Text(t(context, 'Offers', 'عروض')), selected: false, onSelected: (_) {}),
              const SizedBox(width: 6),
              FilterChip(label: Text(t(context, 'In stock', 'متوفر')), selected: false, onSelected: (_) {}),
              if (wholesale) ...[
                const SizedBox(width: 6),
                FilterChip(label: Text(t(context, 'Case', 'كرتون')), selected: false, onSelected: (_) {}),
              ],
            ],
          ),
        ),
        const SizedBox(height: 12),
        ProductGridMock(
          wholesale: wholesale,
          onOpen: () => openScreen(context, target),
        ),
      ],
    );
  }
}

class ProductDetailsMock extends StatefulWidget {
  const ProductDetailsMock({super.key, required this.wholesale});
  final bool wholesale;

  @override
  State<ProductDetailsMock> createState() => _ProductDetailsMockState();
}

class _ProductDetailsMockState extends State<ProductDetailsMock> {
  int qty = 1;

  @override
  Widget build(BuildContext context) {
    return ScrollBody(
      children: [
        Container(
          height: 220,
          decoration: BoxDecoration(
            color: C.white,
            borderRadius: BorderRadius.circular(28),
            border: Border.all(color: C.border),
          ),
          child: const Center(child: Icon(Icons.inventory_2_outlined, size: 96, color: C.deepGreen)),
        ),
        const SizedBox(height: 14),
        Row(
          children: [
            Expanded(
              child: Text(
                t(context, 'Premium Long Grain Rice 5 KG', 'أرز حبة طويلة فاخر 5 كجم'),
                style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w900),
              ),
            ),
            IconButton.filledTonal(onPressed: () {}, icon: const Icon(Icons.favorite_border)),
          ],
        ),
        Text(
          widget.wholesale ? t(context, 'Case of 4 • Selling unit: case', 'كرتون 4 حبات • وحدة البيع: كرتون') : t(context, '5 KG • In stock', '5 كجم • متوفر'),
          style: const TextStyle(color: C.muted),
        ),
        const SizedBox(height: 12),
        Panel(
          color: C.limeSoft,
          child: Row(
            children: [
              const Icon(Icons.local_offer_outlined, color: C.deepGreen),
              const SizedBox(width: 8),
              Expanded(child: Text(t(context, '10% off today', 'خصم 10% اليوم'), style: const TextStyle(fontWeight: FontWeight.w900))),
              Text(widget.wholesale ? '8.400 KWD' : '2.100 KWD', style: const TextStyle(color: C.deepGreen, fontSize: 18, fontWeight: FontWeight.w900)),
            ],
          ),
        ),
        const SizedBox(height: 14),
        Text(t(context, 'Description', 'الوصف'), style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w900)),
        const SizedBox(height: 6),
        Text(
          t(context, 'A clear product detail layout with stock, selling unit, offer and fulfillment information from the current Customer journey.', 'تفاصيل واضحة للمنتج تعرض المخزون ووحدة البيع والعرض ومعلومات التجهيز بنفس أفكار رحلة العميل الحالية.'),
          style: const TextStyle(color: C.inkSoft, height: 1.5),
        ),
        const SizedBox(height: 18),
        Row(
          children: [
            IconButton.filledTonal(onPressed: qty > 1 ? () => setState(() => qty--) : null, icon: const Icon(Icons.remove)),
            Padding(padding: const EdgeInsets.symmetric(horizontal: 14), child: Text(qty.toString(), style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w900))),
            IconButton.filledTonal(onPressed: () => setState(() => qty++), icon: const Icon(Icons.add)),
            const SizedBox(width: 12),
            Expanded(
              child: FilledButton.icon(
                onPressed: () => openScreen(context, widget.wholesale ? CustomerMockScreen.wholesaleCart : CustomerMockScreen.cart),
                icon: const Icon(Icons.add_shopping_cart),
                label: Text(t(context, 'Add to cart', 'أضف للسلة')),
              ),
            ),
          ],
        ),
      ],
    );
  }
}

class OffersMock extends StatelessWidget {
  const OffersMock({super.key});

  @override
  Widget build(BuildContext context) {
    return ScrollBody(
      children: [
        const PromoBanner(),
        const SizedBox(height: 14),
        OfferCard(title: t(context, 'Weekend groceries', 'عروض نهاية الأسبوع'), subtitle: t(context, 'Up to 20% on selected grocery', 'خصم حتى 20% على منتجات مختارة'), value: '20%'),
        const SizedBox(height: 10),
        OfferCard(title: t(context, 'Free delivery', 'توصيل مجاني'), subtitle: t(context, 'On orders over 10 KWD', 'على الطلبات فوق 10 د.ك'), value: 'FREE'),
        const SizedBox(height: 10),
        OfferCard(title: t(context, 'Buy 2 get 1', 'اشترِ 2 واحصل على 1'), subtitle: t(context, 'Selected beverages', 'مشروبات مختارة'), value: '2+1'),
      ],
    );
  }
}

class FavoritesMock extends StatelessWidget {
  const FavoritesMock({super.key});

  @override
  Widget build(BuildContext context) {
    return ScrollBody(
      children: [
        ProductRow(favorite: true, onTap: () => openScreen(context, CustomerMockScreen.productDetails)),
        const SizedBox(height: 10),
        ProductRow(favorite: true, second: true, onTap: () => openScreen(context, CustomerMockScreen.productDetails)),
      ],
    );
  }
}

class CartMock extends StatelessWidget {
  const CartMock({super.key, required this.wholesale});
  final bool wholesale;

  @override
  Widget build(BuildContext context) {
    return ScrollBody(
      children: [
        if (wholesale) StoreContextBanner(name: t(context, 'Al Safa Trading', 'شركة الصفا للتجارة'), wholesale: true),
        CartLine(title: t(context, 'Premium Rice 5 KG', 'أرز فاخر 5 كجم'), qty: wholesale ? '2 cases' : '2', price: wholesale ? '16.800 KWD' : '4.200 KWD'),
        const SizedBox(height: 10),
        CartLine(title: t(context, 'Mineral Water', 'مياه معدنية'), qty: wholesale ? '3 cases' : '1', price: wholesale ? '9.600 KWD' : '0.750 KWD'),
        const SizedBox(height: 14),
        SummaryCard(wholesale: wholesale),
        const SizedBox(height: 14),
        FilledButton.icon(
          onPressed: () => openScreen(context, wholesale ? CustomerMockScreen.wholesaleCheckout : CustomerMockScreen.checkout),
          icon: const Icon(Icons.arrow_forward),
          label: Text(t(context, 'Continue to checkout', 'متابعة لإتمام الطلب')),
        ),
      ],
    );
  }
}

class CheckoutMock extends StatefulWidget {
  const CheckoutMock({super.key, required this.wholesale});
  final bool wholesale;

  @override
  State<CheckoutMock> createState() => _CheckoutMockState();
}

class _CheckoutMockState extends State<CheckoutMock> {
  int payment = 0;

  @override
  Widget build(BuildContext context) {
    return ScrollBody(
      children: [
        StepCard(number: '1', title: t(context, 'Delivery address', 'عنوان التوصيل'), child: Row(children: [
          const Icon(Icons.location_on_outlined, color: C.deepGreen),
          const SizedBox(width: 8),
          Expanded(child: Text(t(context, widget.wholesale ? 'Main warehouse • Shuwaikh' : 'Home • Salmiya Block 4', widget.wholesale ? 'المخزن الرئيسي • الشويخ' : 'المنزل • السالمية قطعة 4'), style: const TextStyle(fontWeight: FontWeight.w800))),
          TextButton(onPressed: () {}, child: Text(t(context, 'Change', 'تغيير'))),
        ])),
        const SizedBox(height: 10),
        StepCard(number: '2', title: t(context, 'Payment', 'الدفع'), child: Column(children: [
          RadioListTile<int>(contentPadding: EdgeInsets.zero, value: 0, groupValue: payment, onChanged: (v) => setState(() => payment = v ?? 0), title: Text(t(context, 'KNET / Card', 'كي نت / بطاقة'))),
          RadioListTile<int>(contentPadding: EdgeInsets.zero, value: 1, groupValue: payment, onChanged: (v) => setState(() => payment = v ?? 0), title: Text(widget.wholesale ? t(context, 'Business credit terms', 'شروط ائتمان الأعمال') : t(context, 'Cash on delivery', 'الدفع عند الاستلام'))),
        ])),
        const SizedBox(height: 10),
        SummaryCard(wholesale: widget.wholesale),
        const SizedBox(height: 14),
        FilledButton.icon(
          onPressed: () => openScreen(context, widget.wholesale ? CustomerMockScreen.wholesaleOrders : CustomerMockScreen.orderTracking),
          icon: const Icon(Icons.verified_outlined),
          label: Text(t(context, 'Place order', 'تأكيد الطلب')),
        ),
      ],
    );
  }
}

class OrdersMock extends StatelessWidget {
  const OrdersMock({super.key, required this.wholesale});
  final bool wholesale;

  @override
  Widget build(BuildContext context) {
    return ScrollBody(
      children: [
        CompactDateFilter(),
        const SizedBox(height: 10),
        SegmentedButton<int>(
          segments: [
            ButtonSegment(value: 0, label: Text(t(context, 'Active', 'نشطة'))),
            ButtonSegment(value: 1, label: Text(t(context, 'Completed', 'مكتملة'))),
          ],
          selected: const {0},
          onSelectionChanged: (_) {},
        ),
        const SizedBox(height: 12),
        OrderTile(
          number: wholesale ? 'B2B-10492' : 'ORD-20481',
          date: '07 Oct 2026',
          total: wholesale ? '86.450 KWD' : '12.750 KWD',
          status: t(context, 'Out for delivery', 'قيد التوصيل'),
          onTap: () => openScreen(context, wholesale ? CustomerMockScreen.wholesaleOrderDetails : CustomerMockScreen.orderTracking),
        ),
        const SizedBox(height: 10),
        OrderTile(
          number: wholesale ? 'B2B-10480' : 'ORD-20460',
          date: '05 Oct 2026',
          total: wholesale ? '144.900 KWD' : '8.300 KWD',
          status: t(context, 'Delivered', 'تم التسليم'),
          onTap: () => openScreen(context, wholesale ? CustomerMockScreen.wholesaleOrderDetails : CustomerMockScreen.orderTracking),
        ),
      ],
    );
  }
}

class OrderDetailsMock extends StatelessWidget {
  const OrderDetailsMock({super.key, required this.wholesale});
  final bool wholesale;

  @override
  Widget build(BuildContext context) {
    return ScrollBody(
      children: [
        Panel(
          color: C.deepGreen,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(wholesale ? 'B2B-10492' : 'ORD-20481', style: const TextStyle(color: C.white, fontSize: 22, fontWeight: FontWeight.w900)),
              const SizedBox(height: 5),
              Text(t(context, 'Confirmed • 07 Oct 2026', 'مؤكد • 07 أكتوبر 2026'), style: const TextStyle(color: Colors.white70)),
            ],
          ),
        ),
        const SizedBox(height: 12),
        CartLine(title: t(context, 'Premium Rice 5 KG', 'أرز فاخر 5 كجم'), qty: wholesale ? '2 cases' : '2', price: wholesale ? '16.800 KWD' : '4.200 KWD'),
        const SizedBox(height: 8),
        CartLine(title: t(context, 'Mineral Water', 'مياه معدنية'), qty: wholesale ? '3 cases' : '1', price: wholesale ? '9.600 KWD' : '0.750 KWD'),
        const SizedBox(height: 12),
        SummaryCard(wholesale: wholesale),
        const SizedBox(height: 12),
        if (!wholesale)
          FilledButton.icon(
            onPressed: () => openScreen(context, CustomerMockScreen.orderTracking),
            icon: const Icon(Icons.local_shipping_outlined),
            label: Text(t(context, 'Track order', 'تتبع الطلب')),
          ),
        if (wholesale)
          OutlinedButton.icon(
            onPressed: () => openScreen(context, CustomerMockScreen.invoices),
            icon: const Icon(Icons.request_quote_outlined),
            label: Text(t(context, 'Related invoice', 'الفاتورة المرتبطة')),
          ),
      ],
    );
  }
}

class OrderTrackingPageMock extends StatelessWidget {
  const OrderTrackingPageMock({super.key});

  @override
  Widget build(BuildContext context) => Scaffold(
        backgroundColor: C.mint,
        appBar: AppBar(
          title: Text(t(context, 'Order tracking', 'تتبع الطلب')),
          actions: [
            IconButton(
              tooltip: t(context, 'Refresh', 'تحديث'),
              onPressed: () => toast(context, t(context, 'Status refreshed', 'تم تحديث حالة الطلب')),
              icon: const Icon(Icons.refresh_rounded),
            ),
            const Padding(
              padding: EdgeInsetsDirectional.only(end: 10),
              child: Center(child: MockBadge()),
            ),
          ],
        ),
        body: const OrderTrackingMock(),
      );
}

class OrderTrackingMock extends StatelessWidget {
  const OrderTrackingMock({super.key});

  @override
  Widget build(BuildContext context) {
    final steps = [
      [Icons.check_circle_rounded, 'Order confirmed', 'تم تأكيد الطلب', '10:08 AM', true],
      [Icons.inventory_2_rounded, 'Preparing order', 'جاري تجهيز الطلب', '10:34 AM', true],
      [Icons.local_shipping_rounded, 'Out for delivery', 'خرج للتوصيل', '12:12 PM', true],
      [Icons.home_rounded, 'Delivered', 'تم التسليم', '--', false],
    ];
    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 28),
      children: [
        Panel(
          color: C.white,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Row(
                children: [
                  const CircleAvatar(
                    backgroundColor: C.limeSoft,
                    child: Icon(Icons.receipt_long_rounded, color: C.deepGreen),
                  ),
                  const SizedBox(width: 10),
                  const Expanded(
                    child: Text(
                      'ORD-20481',
                      maxLines: 1,
                      softWrap: false,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(fontSize: 18, fontWeight: FontWeight.w900),
                    ),
                  ),
                  StatusPill(text: t(context, 'OUT FOR DELIVERY', 'قيد التوصيل'), color: C.info),
                ],
              ),
              const SizedBox(height: 10),
              KeyValue(label: t(context, 'Store', 'المتجر'), value: t(context, 'FOODEX Market', 'فودكس ماركت')),
              KeyValue(label: t(context, 'Total', 'الإجمالي'), value: '12.750 KWD', strong: true),
            ],
          ),
        ),
        const SizedBox(height: 8),
        Text(
          '${t(context, 'Last updated', 'آخر تحديث')}: 12:13 PM',
          textAlign: TextAlign.end,
          style: const TextStyle(color: C.muted, fontSize: 12, fontWeight: FontWeight.w600),
        ),
        const SizedBox(height: 14),
        Text(t(context, 'Order timeline', 'مراحل الطلب'), style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w900)),
        const SizedBox(height: 8),
        Panel(
          child: Column(
            children: [
              for (var i = 0; i < steps.length; i++) ...[
                _TimelineRow(
                  icon: steps[i][0] as IconData,
                  title: t(context, steps[i][1] as String, steps[i][2] as String),
                  time: steps[i][3] as String,
                  done: steps[i][4] as bool,
                  last: i == steps.length - 1,
                ),
              ],
            ],
          ),
        ),
        const SizedBox(height: 14),
        Text(t(context, 'Tracking', 'التتبع'), style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w900)),
        const SizedBox(height: 8),
        Panel(
          color: const Color(0xFFF7FBF8),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Icon(Icons.location_searching_rounded, color: C.deepGreen),
              const SizedBox(width: 10),
              Expanded(
                child: Text(
                  t(
                    context,
                    'FOODEX shows the authoritative order status and timeline here. Live vehicle-map tracking is not available for this order.',
                    'فودكس يعرض هنا الحالة المعتمدة للطلب ومراحله. التتبع الحي لموقع مركبة التوصيل غير متاح لهذا الطلب.',
                  ),
                  style: const TextStyle(color: C.inkSoft, height: 1.45),
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

class _TimelineRow extends StatelessWidget {
  const _TimelineRow({
    required this.icon,
    required this.title,
    required this.time,
    required this.done,
    required this.last,
  });
  final IconData icon;
  final String title;
  final String time;
  final bool done;
  final bool last;

  @override
  Widget build(BuildContext context) => Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 36,
            child: Column(
              children: [
                CircleAvatar(
                  radius: 16,
                  backgroundColor: done ? C.deepGreen : C.mint,
                  child: Icon(icon, color: done ? C.white : C.muted, size: 18),
                ),
                if (!last)
                  Container(
                    width: 2,
                    height: 34,
                    color: done ? C.mintStrong : C.border,
                  ),
              ],
            ),
          ),
          const SizedBox(width: 9),
          Expanded(
            child: Padding(
              padding: const EdgeInsets.only(top: 5),
              child: Text(
                title,
                style: TextStyle(
                  color: done ? C.ink : C.muted,
                  fontWeight: FontWeight.w800,
                ),
              ),
            ),
          ),
          Padding(
            padding: const EdgeInsets.only(top: 5),
            child: Text(time, style: const TextStyle(color: C.muted, fontSize: 11)),
          ),
        ],
      );
}

class NotificationsMock extends StatelessWidget {
  const NotificationsMock({super.key, required this.business});
  final bool business;

  @override
  Widget build(BuildContext context) {
    return ScrollBody(
      children: [
        Notice(icon: Icons.local_shipping_outlined, title: t(context, 'Order is on the way', 'طلبك في الطريق'), body: business ? t(context, 'Business order B2B-10492 left the warehouse.', 'طلب الأعمال B2B-10492 خرج من المخزن.') : t(context, 'ORD-20481 will arrive today.', 'ORD-20481 سيصل اليوم.'), unread: true),
        const SizedBox(height: 10),
        Notice(icon: Icons.local_offer_outlined, title: t(context, 'New offer', 'عرض جديد'), body: t(context, 'Selected products have a limited-time discount.', 'خصم لفترة محدودة على منتجات مختارة.'), unread: true),
        const SizedBox(height: 10),
        Notice(icon: business ? Icons.request_quote_outlined : Icons.favorite_outline, title: business ? t(context, 'Invoice posted', 'تم إصدار فاتورة') : t(context, 'Back in stock', 'عاد للمخزون'), body: business ? t(context, 'Invoice INV-8842 is available.', 'الفاتورة INV-8842 متاحة الآن.') : t(context, 'One of your favorites is available again.', 'أحد منتجات المفضلة متاح مرة أخرى.'), unread: false),
      ],
    );
  }
}

class AddressesMock extends StatelessWidget {
  const AddressesMock({super.key});

  @override
  Widget build(BuildContext context) {
    return ScrollBody(
      children: [
        AddressCard(title: t(context, 'Home', 'المنزل'), body: t(context, 'Salmiya, Block 4, Street 12', 'السالمية، قطعة 4، شارع 12'), primary: true),
        const SizedBox(height: 10),
        AddressCard(title: t(context, 'Office', 'العمل'), body: t(context, 'Kuwait City, Sharq', 'مدينة الكويت، شرق'), primary: false),
        const SizedBox(height: 14),
        OutlinedButton.icon(onPressed: () {}, icon: const Icon(Icons.add_location_alt_outlined), label: Text(t(context, 'Add address', 'إضافة عنوان'))),
      ],
    );
  }
}

class ProfileMock extends StatelessWidget {
  const ProfileMock({super.key, required this.business});
  final bool business;

  @override
  Widget build(BuildContext context) {
    return ScrollBody(
      children: [
        Panel(
          color: C.deepGreen,
          child: Row(
            children: [
              const CircleAvatar(radius: 28, backgroundColor: C.lime, child: Icon(Icons.person, color: C.deepGreenStrong, size: 30)),
              const SizedBox(width: 12),
              Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(business ? t(context, 'Al Safa Trading Co.', 'شركة الصفا للتجارة') : t(context, 'Ahmed Al-Salem', 'أحمد السالم'), style: const TextStyle(color: C.white, fontSize: 18, fontWeight: FontWeight.w900)),
                Text(business ? 'B2B Customer • C-2048' : 'ahmed@example.com', style: const TextStyle(color: Colors.white70)),
              ])),
            ],
          ),
        ),
        const SizedBox(height: 12),
        SettingTile(icon: Icons.location_on_outlined, title: t(context, 'Addresses', 'العناوين'), value: '2'),
        const SizedBox(height: 8),
        SettingTile(icon: Icons.language, title: t(context, 'Language', 'اللغة'), value: isAr(context) ? 'العربية' : 'English'),
        const SizedBox(height: 8),
        SettingTile(icon: Icons.fingerprint, title: t(context, 'Biometric login', 'الدخول بالبصمة'), value: t(context, 'On', 'مفعل')),
        const SizedBox(height: 8),
        SettingTile(icon: Icons.notifications_outlined, title: t(context, 'Notifications', 'الإشعارات'), value: t(context, 'On', 'مفعلة')),
        if (business) ...[
          const SizedBox(height: 8),
          SettingTile(icon: Icons.badge_outlined, title: t(context, 'Business account', 'حساب الأعمال'), value: t(context, 'Verified', 'موثق')),
        ],
        const SizedBox(height: 14),
        OutlinedButton.icon(onPressed: () {}, icon: const Icon(Icons.logout), label: Text(t(context, 'Sign out', 'تسجيل الخروج'))),
      ],
    );
  }
}

class WholesaleHomeMock extends StatelessWidget {
  const WholesaleHomeMock({super.key});

  @override
  Widget build(BuildContext context) {
    return ScrollBody(
      children: [
        StoreContextBanner(name: t(context, 'Al Safa Trading', 'شركة الصفا للتجارة'), wholesale: true),
        const SizedBox(height: 10),
        Row(children: [
          Expanded(child: MetricCard(label: t(context, 'Credit available', 'الرصيد المتاح'), value: '420.000 KWD', icon: Icons.account_balance_wallet_outlined)),
          const SizedBox(width: 8),
          Expanded(child: MetricCard(label: t(context, 'Open invoices', 'فواتير مفتوحة'), value: '3', icon: Icons.request_quote_outlined)),
        ]),
        const SizedBox(height: 12),
        TextField(decoration: InputDecoration(hintText: t(context, 'Search wholesale catalog', 'ابحث في كتالوج الجملة'), prefixIcon: const Icon(Icons.search))),
        const SizedBox(height: 14),
        const PromoBanner(business: true),
        const SizedBox(height: 16),
        SectionHeader(title: t(context, 'Quick access', 'وصول سريع')),
        const SizedBox(height: 8),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            QuickAction(icon: Icons.dashboard_outlined, label: t(context, 'Dashboard', 'لوحة الأعمال'), onTap: () => openScreen(context, CustomerMockScreen.wholesaleDashboard)),
            QuickAction(icon: Icons.request_quote_outlined, label: t(context, 'Invoices', 'الفواتير'), onTap: () => openScreen(context, CustomerMockScreen.invoices)),
            QuickAction(icon: Icons.account_balance_outlined, label: t(context, 'Statement', 'كشف الحساب'), onTap: () => openScreen(context, CustomerMockScreen.accountStatement)),
            QuickAction(icon: Icons.insights_outlined, label: t(context, 'Reports', 'التقارير'), onTap: () => openScreen(context, CustomerMockScreen.purchaseReports)),
          ],
        ),
        const SizedBox(height: 16),
        SectionHeader(title: t(context, 'Wholesale products', 'منتجات الجملة'), action: t(context, 'View all', 'عرض الكل')),
        const SizedBox(height: 8),
        ProductRow(wholesale: true, onTap: () => openScreen(context, CustomerMockScreen.wholesaleProductDetails)),
      ],
    );
  }
}

class WholesaleDashboardMock extends StatelessWidget {
  const WholesaleDashboardMock({super.key});

  @override
  Widget build(BuildContext context) {
    return ScrollBody(
      children: [
        Panel(
          color: C.deepGreen,
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(t(context, 'Business overview', 'ملخص حساب الأعمال'), style: const TextStyle(color: C.white, fontSize: 21, fontWeight: FontWeight.w900)),
            const SizedBox(height: 4),
            Text(t(context, 'Al Safa Trading • C-2048', 'شركة الصفا للتجارة • C-2048'), style: const TextStyle(color: Colors.white70)),
          ]),
        ),
        const SizedBox(height: 10),
        Row(children: [
          Expanded(child: MetricCard(label: t(context, 'Credit limit', 'حد الائتمان'), value: '500 KWD', icon: Icons.credit_score_outlined)),
          const SizedBox(width: 8),
          Expanded(child: MetricCard(label: t(context, 'Available', 'المتاح'), value: '420 KWD', icon: Icons.account_balance_wallet_outlined)),
        ]),
        const SizedBox(height: 8),
        Row(children: [
          Expanded(child: MetricCard(label: t(context, 'This month', 'هذا الشهر'), value: '1,248 KWD', icon: Icons.calendar_month_outlined)),
          const SizedBox(width: 8),
          Expanded(child: MetricCard(label: t(context, 'Open orders', 'طلبات مفتوحة'), value: '4', icon: Icons.list_alt_outlined)),
        ]),
        const SizedBox(height: 12),
        SectionHeader(title: t(context, 'Recent activity', 'آخر النشاطات')),
        const SizedBox(height: 8),
        OrderTile(number: 'B2B-10492', date: '07 Oct 2026', total: '86.450 KWD', status: t(context, 'In progress', 'قيد التنفيذ'), onTap: () => openScreen(context, CustomerMockScreen.wholesaleOrderDetails)),
        const SizedBox(height: 8),
        Notice(icon: Icons.request_quote_outlined, title: 'INV-8842', body: t(context, 'Invoice posted today', 'فاتورة صادرة اليوم'), unread: false),
      ],
    );
  }
}

class InvoicesMock extends StatelessWidget {
  const InvoicesMock({super.key});

  @override
  Widget build(BuildContext context) {
    return ScrollBody(
      children: [
        CompactDateFilter(),
        const SizedBox(height: 10),
        FinanceTile(ref: 'INV-8842', subtitle: '07 Oct 2026 • B2B-10492', amount: '86.450 KWD', status: t(context, 'Open', 'مفتوحة'), onTap: () => openScreen(context, CustomerMockScreen.invoiceDetails)),
        const SizedBox(height: 8),
        FinanceTile(ref: 'INV-8829', subtitle: '01 Oct 2026 • B2B-10421', amount: '144.900 KWD', status: t(context, 'Paid', 'مدفوعة'), onTap: () => openScreen(context, CustomerMockScreen.invoiceDetails)),
      ],
    );
  }
}

class InvoiceDetailsMock extends StatelessWidget {
  const InvoiceDetailsMock({super.key});

  @override
  Widget build(BuildContext context) {
    return ScrollBody(
      children: [
        Panel(
          color: C.white,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const Brand(),
              const SizedBox(height: 12),
              Row(children: [
                Expanded(child: Text(t(context, 'TAX INVOICE', 'فاتورة ضريبية'), style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w900))),
                const StatusPill(text: 'OPEN', color: C.warning),
              ]),
              const SizedBox(height: 10),
              const KeyValue(label: 'Invoice', value: 'INV-8842'),
              const KeyValue(label: 'Order', value: 'B2B-10492'),
              const KeyValue(label: 'Date', value: '07 Oct 2026'),
              const Divider(height: 24),
              CartLine(title: 'Premium Rice 5 KG', qty: '2 cases', price: '16.800 KWD', embedded: true),
              const SizedBox(height: 6),
              CartLine(title: 'Mineral Water', qty: '3 cases', price: '9.600 KWD', embedded: true),
              const Divider(height: 24),
              const KeyValue(label: 'Subtotal', value: '82.333 KWD'),
              const KeyValue(label: 'Tax', value: '4.117 KWD'),
              const KeyValue(label: 'Total', value: '86.450 KWD', strong: true),
            ],
          ),
        ),
        const SizedBox(height: 12),
        OutlinedButton.icon(onPressed: () {}, icon: const Icon(Icons.download_outlined), label: Text(t(context, 'Download invoice', 'تحميل الفاتورة'))),
      ],
    );
  }
}

class AccountStatementMock extends StatelessWidget {
  const AccountStatementMock({super.key});

  @override
  Widget build(BuildContext context) {
    return ScrollBody(
      children: [
        Panel(
          color: C.deepGreen,
          child: Row(children: [
            Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(t(context, 'Current balance', 'الرصيد الحالي'), style: const TextStyle(color: Colors.white70)),
              const Text('80.000 KWD', style: TextStyle(color: C.white, fontSize: 26, fontWeight: FontWeight.w900)),
            ])),
            const Icon(Icons.account_balance_wallet_outlined, color: C.lime, size: 42),
          ]),
        ),
        const SizedBox(height: 12),
        CompactDateFilter(),
        const SizedBox(height: 12),
        const StatementTile(title: 'Invoice INV-8842', amount: '+86.450 KWD', date: '07 Oct'),
        const SizedBox(height: 8),
        const StatementTile(title: 'Payment PAY-3177', amount: '-150.000 KWD', date: '06 Oct', credit: true),
        const SizedBox(height: 8),
        const StatementTile(title: 'Invoice INV-8829', amount: '+144.900 KWD', date: '01 Oct'),
      ],
    );
  }
}

class PurchaseReportsMock extends StatelessWidget {
  const PurchaseReportsMock({super.key});

  @override
  Widget build(BuildContext context) {
    return ScrollBody(
      children: [
        CompactDateFilter(),
        const SizedBox(height: 12),
        Row(children: [
          Expanded(child: MetricCard(label: t(context, 'Purchases', 'المشتريات'), value: '1,248 KWD', icon: Icons.shopping_bag_outlined)),
          const SizedBox(width: 8),
          Expanded(child: MetricCard(label: t(context, 'Orders', 'الطلبات'), value: '18', icon: Icons.receipt_long_outlined)),
        ]),
        const SizedBox(height: 12),
        Panel(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(t(context, 'Monthly trend', 'اتجاه المشتريات الشهري'), style: const TextStyle(fontWeight: FontWeight.w900)),
            const SizedBox(height: 18),
            const SimpleBars(values: [0.35, 0.58, 0.42, 0.78, 0.64, 0.9]),
          ]),
        ),
      ],
    );
  }
}

class TopProductsMock extends StatelessWidget {
  const TopProductsMock({super.key});

  @override
  Widget build(BuildContext context) {
    return ScrollBody(
      children: [
        RankTile(rank: 1, title: t(context, 'Premium Rice 5 KG', 'أرز فاخر 5 كجم'), value: '42 cases'),
        const SizedBox(height: 8),
        RankTile(rank: 2, title: t(context, 'Mineral Water', 'مياه معدنية'), value: '38 cases'),
        const SizedBox(height: 8),
        RankTile(rank: 3, title: t(context, 'Cooking Oil 1.5L', 'زيت طبخ 1.5 لتر'), value: '24 cases'),
      ],
    );
  }
}

class ScrollBody extends StatelessWidget {
  const ScrollBody({super.key, required this.children});
  final List<Widget> children;

  @override
  Widget build(BuildContext context) => ListView(
        padding: const EdgeInsets.fromLTRB(16, 14, 16, 28),
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
            decoration: BoxDecoration(
              color: C.lime,
              borderRadius: BorderRadius.circular(compact ? 10 : 12),
            ),
            child: const Icon(Icons.eco_rounded, color: C.deepGreenStrong),
          ),
          const SizedBox(width: 8),
          Text(
            'FOODEX',
            style: TextStyle(
              color: Theme.of(context).appBarTheme.backgroundColor == C.deepGreen ? C.white : C.deepGreen,
              fontSize: compact ? 17 : 22,
              fontWeight: FontWeight.w900,
              letterSpacing: .5,
            ),
          ),
        ],
      );
}

class DesignBanner extends StatelessWidget {
  const DesignBanner({super.key});

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 9),
        decoration: BoxDecoration(
          color: C.limeSoft,
          borderRadius: BorderRadius.circular(14),
          border: Border.all(color: C.mintStrong),
        ),
        child: Row(
          children: [
            const Icon(Icons.design_services_outlined, color: C.deepGreen, size: 19),
            const SizedBox(width: 8),
            Expanded(child: Text(t(context, 'DESIGN-ONLY • Based on current Customer App concepts', 'تصميم فقط • مبني على أفكار تطبيق العميل الحالي'), style: const TextStyle(color: C.deepGreenStrong, fontWeight: FontWeight.w800, fontSize: 12))),
          ],
        ),
      );
}

class MockBadge extends StatelessWidget {
  const MockBadge({super.key});

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
        decoration: BoxDecoration(color: C.lime, borderRadius: BorderRadius.circular(99)),
        child: const Text('MOCK', style: TextStyle(color: C.deepGreenStrong, fontSize: 10, fontWeight: FontWeight.w900)),
      );
}

class HeroCard extends StatelessWidget {
  const HeroCard({super.key, required this.onStart});
  final VoidCallback onStart;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.all(20),
        decoration: BoxDecoration(
          color: C.deepGreen,
          borderRadius: BorderRadius.circular(28),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Icon(Icons.shopping_bag_outlined, color: C.lime, size: 40),
            const SizedBox(height: 12),
            Text(t(context, 'Customer App design lab', 'مختبر تصميم تطبيق العميل'), style: const TextStyle(color: C.white, fontSize: 24, fontWeight: FontWeight.w900)),
            const SizedBox(height: 6),
            Text(t(context, 'Retail + Wholesale journeys from the current app, redesigned as one polished prototype.', 'رحلات التجزئة والجملة الموجودة في التطبيق الحالي، معاد تصميمها داخل نموذج احترافي واحد.'), style: const TextStyle(color: Colors.white70, height: 1.45)),
            const SizedBox(height: 16),
            FilledButton.icon(
              style: FilledButton.styleFrom(backgroundColor: C.lime, foregroundColor: C.deepGreenStrong),
              onPressed: onStart,
              icon: const Icon(Icons.play_arrow_rounded),
              label: Text(t(context, 'Open customer journey', 'ابدأ رحلة العميل')),
            ),
          ],
        ),
      );
}

class Panel extends StatelessWidget {
  const Panel({
    super.key,
    required this.child,
    this.onTap,
    this.color = C.white,
    this.padding = const EdgeInsets.all(14),
  });

  final Widget child;
  final VoidCallback? onTap;
  final Color color;
  final EdgeInsets padding;

  @override
  Widget build(BuildContext context) => Material(
        color: color,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(18),
          side: BorderSide(color: color == C.deepGreen ? C.deepGreen : C.border),
        ),
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: onTap,
          child: Padding(padding: padding, child: child),
        ),
      );
}

class SectionTitle extends StatelessWidget {
  const SectionTitle(this.text, {super.key});
  final String text;

  @override
  Widget build(BuildContext context) => Text(text, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w900, color: C.ink));
}

class SectionHeader extends StatelessWidget {
  const SectionHeader({super.key, required this.title, this.action});
  final String title;
  final String? action;

  @override
  Widget build(BuildContext context) => Row(
        children: [
          Expanded(child: Text(title, style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w900))),
          if (action != null) Text(action!, style: const TextStyle(color: C.deepGreen, fontWeight: FontWeight.w800, fontSize: 12)),
        ],
      );
}

class StoreCard extends StatelessWidget {
  const StoreCard({super.key, required this.title, required this.subtitle, required this.icon, required this.onTap});
  final String title;
  final String subtitle;
  final IconData icon;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => SizedBox(
        width: 210,
        child: Padding(
          padding: const EdgeInsetsDirectional.only(end: 10),
          child: Panel(
            onTap: onTap,
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              CircleAvatar(backgroundColor: C.limeSoft, child: Icon(icon, color: C.deepGreen)),
              const Spacer(),
              Text(title, style: const TextStyle(fontWeight: FontWeight.w900)),
              const SizedBox(height: 3),
              Text(subtitle, style: const TextStyle(color: C.muted, fontSize: 12)),
            ]),
          ),
        ),
      );
}

class PromoBanner extends StatelessWidget {
  const PromoBanner({super.key, this.business = false});
  final bool business;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.all(18),
        decoration: BoxDecoration(
          gradient: const LinearGradient(colors: [C.deepGreenStrong, C.deepGreenSoft]),
          borderRadius: BorderRadius.circular(24),
        ),
        child: Row(
          children: [
            Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(business ? t(context, 'Business bulk deal', 'عرض جملة للأعمال') : t(context, 'Fresh deals today', 'عروض طازجة اليوم'), style: const TextStyle(color: C.white, fontSize: 19, fontWeight: FontWeight.w900)),
              const SizedBox(height: 4),
              Text(business ? t(context, 'Save on selected case quantities', 'وفر على كميات الكراتين المختارة') : t(context, 'Up to 20% on selected items', 'خصم حتى 20% على منتجات مختارة'), style: const TextStyle(color: Colors.white70)),
            ])),
            Container(
              width: 62,
              height: 62,
              decoration: const BoxDecoration(color: C.lime, shape: BoxShape.circle),
              alignment: Alignment.center,
              child: Text(business ? 'B2B' : '20%', style: const TextStyle(color: C.deepGreenStrong, fontWeight: FontWeight.w900, fontSize: 18)),
            ),
          ],
        ),
      );
}

class CategoryStrip extends StatelessWidget {
  const CategoryStrip({super.key});

  @override
  Widget build(BuildContext context) => Row(
        children: [
          Expanded(child: CategoryChip(icon: Icons.local_grocery_store_outlined, label: t(context, 'Grocery', 'بقالة'))),
          const SizedBox(width: 7),
          Expanded(child: CategoryChip(icon: Icons.local_drink_outlined, label: t(context, 'Drinks', 'مشروبات'))),
          const SizedBox(width: 7),
          Expanded(child: CategoryChip(icon: Icons.bakery_dining_outlined, label: t(context, 'Bakery', 'مخبوزات'))),
        ],
      );
}

class CategoryChip extends StatelessWidget {
  const CategoryChip({super.key, required this.icon, required this.label});
  final IconData icon;
  final String label;

  @override
  Widget build(BuildContext context) => Panel(
        padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 6),
        child: Column(children: [
          Icon(icon, color: C.deepGreen, size: 28),
          const SizedBox(height: 6),
          Text(label, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w800)),
        ]),
      );
}

class ProductGridMock extends StatelessWidget {
  const ProductGridMock({
    super.key,
    required this.wholesale,
    required this.onOpen,
  });
  final bool wholesale;
  final VoidCallback onOpen;

  @override
  Widget build(BuildContext context) {
    final names = [
      [t(context, 'Premium Rice 5 KG', 'أرز فاخر 5 كجم'), wholesale ? '8.400 KWD / case' : '2.100 KWD'],
      [t(context, 'Mineral Water 12 x 500ml', 'مياه معدنية 12 × 500 مل'), wholesale ? '9.600 KWD / case' : '0.750 KWD'],
      [t(context, 'Cooking Oil 1.5L', 'زيت طبخ 1.5 لتر'), wholesale ? '11.250 KWD / case' : '1.950 KWD'],
      [t(context, 'Fresh Milk 1L', 'حليب طازج 1 لتر'), wholesale ? '6.300 KWD / case' : '0.650 KWD'],
    ];
    return GridView.builder(
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      itemCount: names.length,
      gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
        crossAxisCount: 2,
        crossAxisSpacing: 12,
        mainAxisSpacing: 12,
        mainAxisExtent: 292,
      ),
      itemBuilder: (context, index) {
        final item = names[index];
        return Material(
          color: C.white,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(20),
            side: const BorderSide(color: C.border),
          ),
          clipBehavior: Clip.antiAlias,
          child: InkWell(
            onTap: onOpen,
            child: Padding(
              padding: const EdgeInsets.all(10),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(
                    child: Container(
                      width: double.infinity,
                      decoration: BoxDecoration(
                        color: C.mint,
                        borderRadius: BorderRadius.circular(16),
                      ),
                      child: const Icon(Icons.inventory_2_outlined, color: C.deepGreen, size: 58),
                    ),
                  ),
                  const SizedBox(height: 10),
                  Text(
                    item[0],
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(fontWeight: FontWeight.w900, height: 1.2),
                  ),
                  const SizedBox(height: 4),
                  Text(
                    wholesale ? t(context, 'Selling unit: case', 'وحدة البيع: كرتون') : t(context, 'In stock', 'متوفر'),
                    style: const TextStyle(color: C.muted, fontSize: 11),
                  ),
                  const SizedBox(height: 5),
                  Row(
                    children: [
                      Expanded(
                        child: Text(
                          item[1],
                          maxLines: 1,
                          style: const TextStyle(color: C.deepGreen, fontWeight: FontWeight.w900),
                        ),
                      ),
                      SizedBox(
                        width: 36,
                        height: 36,
                        child: IconButton.filledTonal(
                          padding: EdgeInsets.zero,
                          onPressed: () => toast(context, t(context, 'Added to mock cart', 'تمت الإضافة للسلة الوهمية')),
                          icon: const Icon(Icons.add, size: 18),
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),
          ),
        );
      },
    );
  }
}

class ProductRow extends StatelessWidget {
  const ProductRow({
    super.key,
    required this.onTap,
    this.wholesale = false,
    this.second = false,
    this.third = false,
    this.favorite = false,
  });

  final VoidCallback onTap;
  final bool wholesale;
  final bool second;
  final bool third;
  final bool favorite;

  @override
  Widget build(BuildContext context) {
    final title = third
        ? t(context, 'Cooking Oil 1.5L', 'زيت طبخ 1.5 لتر')
        : second
            ? t(context, 'Mineral Water 12 x 500ml', 'مياه معدنية 12 × 500 مل')
            : t(context, 'Premium Long Grain Rice 5 KG', 'أرز حبة طويلة فاخر 5 كجم');
    final price = wholesale ? (second ? '9.600 KWD / case' : '8.400 KWD / case') : (second ? '0.750 KWD' : '2.100 KWD');

    return Panel(
      onTap: onTap,
      child: Row(
        children: [
          Container(
            width: 72,
            height: 72,
            decoration: BoxDecoration(color: C.mint, borderRadius: BorderRadius.circular(18)),
            child: const Icon(Icons.inventory_2_outlined, color: C.deepGreen, size: 36),
          ),
          const SizedBox(width: 12),
          Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              Expanded(child: Text(title, maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w900))),
              if (favorite) const Icon(Icons.favorite, color: C.red, size: 20),
            ]),
            const SizedBox(height: 4),
            Text(wholesale ? t(context, 'Selling unit: case • In stock', 'وحدة البيع: كرتون • متوفر') : t(context, 'In stock • Same-day', 'متوفر • توصيل اليوم'), style: const TextStyle(color: C.muted, fontSize: 11)),
            const SizedBox(height: 5),
            Text(price, style: const TextStyle(color: C.deepGreen, fontWeight: FontWeight.w900)),
          ])),
          IconButton.filledTonal(onPressed: () => toast(context, t(context, 'Added to mock cart', 'تمت الإضافة للسلة الوهمية')), icon: const Icon(Icons.add)),
        ],
      ),
    );
  }
}

class OfferCard extends StatelessWidget {
  const OfferCard({super.key, required this.title, required this.subtitle, required this.value});
  final String title;
  final String subtitle;
  final String value;

  @override
  Widget build(BuildContext context) => Panel(
        child: Row(
          children: [
            Container(
              width: 58,
              height: 58,
              decoration: BoxDecoration(color: C.limeSoft, borderRadius: BorderRadius.circular(18)),
              alignment: Alignment.center,
              child: Text(value, style: const TextStyle(color: C.deepGreen, fontWeight: FontWeight.w900)),
            ),
            const SizedBox(width: 12),
            Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(title, style: const TextStyle(fontWeight: FontWeight.w900)),
              const SizedBox(height: 3),
              Text(subtitle, style: const TextStyle(color: C.muted, fontSize: 12)),
            ])),
            const Icon(Icons.chevron_right_rounded, color: C.muted),
          ],
        ),
      );
}

class CartLine extends StatelessWidget {
  const CartLine({super.key, required this.title, required this.qty, required this.price, this.embedded = false});
  final String title;
  final String qty;
  final String price;
  final bool embedded;

  @override
  Widget build(BuildContext context) {
    final content = Row(
      children: [
        Container(width: 50, height: 50, decoration: BoxDecoration(color: C.mint, borderRadius: BorderRadius.circular(14)), child: const Icon(Icons.inventory_2_outlined, color: C.deepGreen)),
        const SizedBox(width: 10),
        Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(title, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w900)),
          Text(qty, style: const TextStyle(color: C.muted, fontSize: 11)),
        ])),
        Text(price, style: const TextStyle(color: C.deepGreen, fontWeight: FontWeight.w900)),
      ],
    );
    return embedded ? content : Panel(child: content);
  }
}

class SummaryCard extends StatelessWidget {
  const SummaryCard({super.key, required this.wholesale});
  final bool wholesale;

  @override
  Widget build(BuildContext context) => Panel(
        child: Column(children: [
          KeyValue(label: t(context, 'Subtotal', 'المجموع الفرعي'), value: wholesale ? '82.333 KWD' : '11.857 KWD'),
          KeyValue(label: t(context, 'Delivery', 'التوصيل'), value: wholesale ? '0.000 KWD' : '0.250 KWD'),
          KeyValue(label: t(context, 'Tax', 'الضريبة'), value: wholesale ? '4.117 KWD' : '0.643 KWD'),
          const Divider(),
          KeyValue(label: t(context, 'Total', 'الإجمالي'), value: wholesale ? '86.450 KWD' : '12.750 KWD', strong: true),
        ]),
      );
}

class StepCard extends StatelessWidget {
  const StepCard({super.key, required this.number, required this.title, required this.child});
  final String number;
  final String title;
  final Widget child;

  @override
  Widget build(BuildContext context) => Panel(
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            CircleAvatar(radius: 14, backgroundColor: C.deepGreen, child: Text(number, style: const TextStyle(color: C.white, fontSize: 12, fontWeight: FontWeight.w900))),
            const SizedBox(width: 8),
            Text(title, style: const TextStyle(fontWeight: FontWeight.w900)),
          ]),
          const SizedBox(height: 8),
          child,
        ]),
      );
}

class OrderTile extends StatelessWidget {
  const OrderTile({super.key, required this.number, required this.date, required this.total, required this.status, required this.onTap});
  final String number;
  final String date;
  final String total;
  final String status;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => Panel(
        onTap: onTap,
        child: Row(children: [
          const CircleAvatar(backgroundColor: C.limeSoft, child: Icon(Icons.receipt_long_outlined, color: C.deepGreen)),
          const SizedBox(width: 10),
          Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(number, maxLines: 1, style: const TextStyle(fontWeight: FontWeight.w900)),
            Text(date, style: const TextStyle(color: C.muted, fontSize: 11)),
            const SizedBox(height: 5),
            StatusPill(text: status, color: C.info),
          ])),
          Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
            Text(total, style: const TextStyle(fontWeight: FontWeight.w900)),
            const SizedBox(height: 10),
            const Icon(Icons.chevron_right_rounded, color: C.muted),
          ]),
        ]),
      );
}

class TrackingStep extends StatelessWidget {
  const TrackingStep({super.key, required this.icon, required this.title, required this.done});
  final IconData icon;
  final String title;
  final bool done;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 6),
        child: Row(children: [
          CircleAvatar(backgroundColor: done ? C.deepGreen : C.white, child: Icon(icon, color: done ? C.white : C.muted)),
          const SizedBox(width: 10),
          Expanded(child: Text(title, style: TextStyle(fontWeight: FontWeight.w800, color: done ? C.ink : C.muted))),
          Icon(done ? Icons.check_circle : Icons.radio_button_unchecked, color: done ? C.lime : C.muted),
        ]),
      );
}

class Notice extends StatelessWidget {
  const Notice({super.key, required this.icon, required this.title, required this.body, required this.unread});
  final IconData icon;
  final String title;
  final String body;
  final bool unread;

  @override
  Widget build(BuildContext context) => Panel(
        color: unread ? const Color(0xFFF6FBF7) : C.white,
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          CircleAvatar(backgroundColor: C.limeSoft, child: Icon(icon, color: C.deepGreen)),
          const SizedBox(width: 10),
          Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(title, style: const TextStyle(fontWeight: FontWeight.w900)),
            const SizedBox(height: 3),
            Text(body, style: const TextStyle(color: C.muted, fontSize: 12, height: 1.35)),
          ])),
          if (unread) Container(width: 8, height: 8, decoration: const BoxDecoration(color: C.deepGreen, shape: BoxShape.circle)),
        ]),
      );
}

class AddressCard extends StatelessWidget {
  const AddressCard({super.key, required this.title, required this.body, required this.primary});
  final String title;
  final String body;
  final bool primary;

  @override
  Widget build(BuildContext context) => Panel(
        child: Row(children: [
          const CircleAvatar(backgroundColor: C.limeSoft, child: Icon(Icons.location_on_outlined, color: C.deepGreen)),
          const SizedBox(width: 10),
          Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              Text(title, style: const TextStyle(fontWeight: FontWeight.w900)),
              if (primary) ...[const SizedBox(width: 6), StatusPill(text: 'PRIMARY', color: C.deepGreen)],
            ]),
            Text(body, style: const TextStyle(color: C.muted, fontSize: 12)),
          ])),
          const Icon(Icons.more_vert, color: C.muted),
        ]),
      );
}

class SettingTile extends StatelessWidget {
  const SettingTile({super.key, required this.icon, required this.title, required this.value});
  final IconData icon;
  final String title;
  final String value;

  @override
  Widget build(BuildContext context) => Panel(
        padding: const EdgeInsets.all(12),
        child: Row(children: [
          CircleAvatar(backgroundColor: C.mint, child: Icon(icon, color: C.deepGreen)),
          const SizedBox(width: 10),
          Expanded(child: Text(title, style: const TextStyle(fontWeight: FontWeight.w800))),
          Text(value, style: const TextStyle(color: C.muted, fontWeight: FontWeight.w700)),
          const SizedBox(width: 3),
          const Icon(Icons.chevron_right_rounded, color: C.muted),
        ]),
      );
}

class StoreContextBanner extends StatelessWidget {
  const StoreContextBanner({super.key, required this.name, required this.wholesale});
  final String name;
  final bool wholesale;

  @override
  Widget build(BuildContext context) => Panel(
        color: wholesale ? C.limeSoft : C.white,
        child: Row(children: [
          CircleAvatar(backgroundColor: wholesale ? C.deepGreen : C.limeSoft, child: Icon(wholesale ? Icons.warehouse_outlined : Icons.storefront_outlined, color: wholesale ? C.white : C.deepGreen)),
          const SizedBox(width: 10),
          Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(name, style: const TextStyle(fontWeight: FontWeight.w900)),
            Text(wholesale ? t(context, 'Wholesale account context', 'سياق حساب الجملة') : t(context, 'Retail store context', 'سياق متجر التجزئة'), style: const TextStyle(color: C.muted, fontSize: 11)),
          ])),
          const Icon(Icons.swap_horiz_rounded, color: C.deepGreen),
        ]),
      );
}

class MetricCard extends StatelessWidget {
  const MetricCard({super.key, required this.label, required this.value, required this.icon});
  final String label;
  final String value;
  final IconData icon;

  @override
  Widget build(BuildContext context) => Panel(
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Icon(icon, color: C.deepGreen),
          const SizedBox(height: 8),
          Text(value, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w900)),
          const SizedBox(height: 2),
          Text(label, style: const TextStyle(color: C.muted, fontSize: 11)),
        ]),
      );
}

class QuickAction extends StatelessWidget {
  const QuickAction({super.key, required this.icon, required this.label, required this.onTap});
  final IconData icon;
  final String label;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => SizedBox(
        width: (MediaQuery.sizeOf(context).width - 48) / 2,
        child: Panel(
          onTap: onTap,
          child: Row(children: [
            CircleAvatar(backgroundColor: C.limeSoft, child: Icon(icon, color: C.deepGreen)),
            const SizedBox(width: 8),
            Expanded(child: Text(label, style: const TextStyle(fontWeight: FontWeight.w800))),
          ]),
        ),
      );
}

class FinanceTile extends StatelessWidget {
  const FinanceTile({super.key, required this.ref, required this.subtitle, required this.amount, required this.status, required this.onTap});
  final String ref;
  final String subtitle;
  final String amount;
  final String status;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => Panel(
        onTap: onTap,
        child: Row(children: [
          const CircleAvatar(backgroundColor: C.limeSoft, child: Icon(Icons.request_quote_outlined, color: C.deepGreen)),
          const SizedBox(width: 10),
          Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(ref, style: const TextStyle(fontWeight: FontWeight.w900)),
            Text(subtitle, style: const TextStyle(color: C.muted, fontSize: 11)),
            const SizedBox(height: 5),
            StatusPill(text: status, color: status == 'Paid' || status == 'مدفوعة' ? C.deepGreen : C.warning),
          ])),
          Text(amount, style: const TextStyle(fontWeight: FontWeight.w900)),
        ]),
      );
}

class StatementTile extends StatelessWidget {
  const StatementTile({super.key, required this.title, required this.amount, required this.date, this.credit = false});
  final String title;
  final String amount;
  final String date;
  final bool credit;

  @override
  Widget build(BuildContext context) => Panel(
        padding: const EdgeInsets.all(12),
        child: Row(children: [
          CircleAvatar(backgroundColor: (credit ? C.limeSoft : const Color(0xFFFFF4DE)), child: Icon(credit ? Icons.south_west : Icons.north_east, color: credit ? C.deepGreen : C.warning)),
          const SizedBox(width: 10),
          Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(title, style: const TextStyle(fontWeight: FontWeight.w800)),
            Text(date, style: const TextStyle(color: C.muted, fontSize: 11)),
          ])),
          Text(amount, style: TextStyle(color: credit ? C.deepGreen : C.warning, fontWeight: FontWeight.w900)),
        ]),
      );
}

class RankTile extends StatelessWidget {
  const RankTile({super.key, required this.rank, required this.title, required this.value});
  final int rank;
  final String title;
  final String value;

  @override
  Widget build(BuildContext context) => Panel(
        child: Row(children: [
          CircleAvatar(backgroundColor: rank == 1 ? C.lime : C.mint, child: Text(rank.toString(), style: const TextStyle(color: C.deepGreenStrong, fontWeight: FontWeight.w900))),
          const SizedBox(width: 10),
          Expanded(child: Text(title, style: const TextStyle(fontWeight: FontWeight.w900))),
          Text(value, style: const TextStyle(color: C.deepGreen, fontWeight: FontWeight.w800)),
        ]),
      );
}

class SimpleBars extends StatelessWidget {
  const SimpleBars({super.key, required this.values});
  final List<double> values;

  @override
  Widget build(BuildContext context) => SizedBox(
        height: 150,
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.end,
          children: values.map((v) => Expanded(
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 4),
              child: FractionallySizedBox(
                heightFactor: v,
                alignment: Alignment.bottomCenter,
                child: Container(
                  decoration: BoxDecoration(color: C.deepGreen, borderRadius: const BorderRadius.vertical(top: Radius.circular(8))),
                ),
              ),
            ),
          )).toList(),
        ),
      );
}

class CompactDateFilter extends StatelessWidget {
  const CompactDateFilter({super.key});

  @override
  Widget build(BuildContext context) => Row(children: [
        Expanded(child: TextField(readOnly: true, decoration: InputDecoration(isDense: true, labelText: t(context, 'Start', 'من'), suffixIcon: const Icon(Icons.calendar_today_outlined, size: 17)))),
        const SizedBox(width: 6),
        Expanded(child: TextField(readOnly: true, decoration: InputDecoration(isDense: true, labelText: t(context, 'End', 'إلى'), suffixIcon: const Icon(Icons.calendar_today_outlined, size: 17)))),
        const SizedBox(width: 6),
        SizedBox(width: 50, height: 50, child: FilledButton(style: FilledButton.styleFrom(padding: EdgeInsets.zero), onPressed: () {}, child: const Icon(Icons.search))),
      ]);
}

class KeyValue extends StatelessWidget {
  const KeyValue({super.key, required this.label, required this.value, this.strong = false});
  final String label;
  final String value;
  final bool strong;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 4),
        child: Row(children: [
          Expanded(child: Text(label, style: TextStyle(color: strong ? C.ink : C.muted, fontWeight: strong ? FontWeight.w900 : FontWeight.w500))),
          Text(value, style: TextStyle(fontWeight: strong ? FontWeight.w900 : FontWeight.w700, fontSize: strong ? 17 : 13)),
        ]),
      );
}

class StatusPill extends StatelessWidget {
  const StatusPill({super.key, required this.text, required this.color});
  final String text;
  final Color color;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
        decoration: BoxDecoration(color: color.withValues(alpha: .10), borderRadius: BorderRadius.circular(99)),
        child: Text(text, maxLines: 1, style: TextStyle(color: color, fontSize: 10, fontWeight: FontWeight.w900)),
      );
}

class CustomerBottom extends StatelessWidget {
  const CustomerBottom({super.key, required this.current});
  final CustomerMockScreen current;

  bool get wholesale => current.wholesale;

  int get index {
    if (wholesale) {
      if (current == CustomerMockScreen.wholesaleProducts ||
          current == CustomerMockScreen.wholesaleProductDetails ||
          current == CustomerMockScreen.wholesaleCart ||
          current == CustomerMockScreen.wholesaleCheckout) return 1;
      if (current == CustomerMockScreen.wholesaleOrders ||
          current == CustomerMockScreen.wholesaleOrderDetails) return 2;
      if (current == CustomerMockScreen.invoices ||
          current == CustomerMockScreen.invoiceDetails) return 3;
      if (current == CustomerMockScreen.wholesaleProfile ||
          current == CustomerMockScreen.wholesaleDashboard ||
          current == CustomerMockScreen.accountStatement ||
          current == CustomerMockScreen.purchaseReports ||
          current == CustomerMockScreen.topProducts ||
          current == CustomerMockScreen.wholesaleNotifications) return 4;
      return 0;
    }
    if (current == CustomerMockScreen.products ||
        current == CustomerMockScreen.productDetails ||
        current == CustomerMockScreen.categories ||
        current == CustomerMockScreen.offers ||
        current == CustomerMockScreen.favorites) return 1;
    if (current == CustomerMockScreen.cart ||
        current == CustomerMockScreen.checkout) return 2;
    if (current == CustomerMockScreen.orders ||
        current == CustomerMockScreen.orderTracking) return 3;
    if (current == CustomerMockScreen.profile ||
        current == CustomerMockScreen.addresses ||
        current == CustomerMockScreen.notifications) return 4;
    return 0;
  }

  @override
  Widget build(BuildContext context) {
    final targets = wholesale
        ? const [
            CustomerMockScreen.wholesaleHome,
            CustomerMockScreen.wholesaleProducts,
            CustomerMockScreen.wholesaleOrders,
            CustomerMockScreen.invoices,
            CustomerMockScreen.wholesaleProfile,
          ]
        : const [
            CustomerMockScreen.retailHome,
            CustomerMockScreen.products,
            CustomerMockScreen.cart,
            CustomerMockScreen.orders,
            CustomerMockScreen.profile,
          ];
    final icons = wholesale
        ? const [
            Icons.home_outlined,
            Icons.shopping_cart_outlined,
            Icons.receipt_long_outlined,
            Icons.description_outlined,
            Icons.more_horiz_rounded,
          ]
        : const [
            Icons.home_outlined,
            Icons.grid_view_outlined,
            Icons.shopping_bag_outlined,
            Icons.receipt_long_outlined,
            Icons.person_outline_rounded,
          ];
    final labels = wholesale
        ? [
            t(context, 'Home', 'الرئيسية'),
            t(context, 'Shopping', 'التسوق'),
            t(context, 'Orders', 'الطلبات'),
            t(context, 'Invoices', 'الفواتير'),
            t(context, 'More', 'المزيد'),
          ]
        : [
            t(context, 'Home', 'الرئيسية'),
            t(context, 'Products', 'المنتجات'),
            t(context, 'Cart', 'السلة'),
            t(context, 'Orders', 'الطلبات'),
            t(context, 'Profile', 'الحساب'),
          ];

    return ColoredBox(
      color: C.mint,
      child: SafeArea(
        top: false,
        minimum: const EdgeInsets.fromLTRB(12, 8, 12, 8),
        child: Material(
          color: C.white,
          elevation: 8,
          shadowColor: const Color(0x1F163629),
          borderRadius: BorderRadius.circular(32),
          clipBehavior: Clip.antiAlias,
          child: Container(
            height: 82,
            decoration: BoxDecoration(
              border: Border.all(color: C.border),
              borderRadius: BorderRadius.circular(32),
            ),
            child: Row(
              children: List.generate(5, (i) {
                final selected = i == index;
                return Expanded(
                  child: InkWell(
                    onTap: () {
                      final next = targets[i];
                      if (next == current) return;
                      Navigator.of(context).pushReplacement(
                        MaterialPageRoute(builder: (_) => MockPage(screen: next)),
                      );
                    },
                    child: Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        AnimatedContainer(
                          duration: const Duration(milliseconds: 160),
                          width: 42,
                          height: 32,
                          decoration: BoxDecoration(
                            color: selected ? C.limeSoft : Colors.transparent,
                            borderRadius: BorderRadius.circular(18),
                          ),
                          child: Icon(
                            icons[i],
                            color: selected ? C.deepGreen : C.muted,
                            size: 22,
                          ),
                        ),
                        const SizedBox(height: 4),
                        Text(
                          labels[i],
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: TextStyle(
                            color: selected ? C.deepGreen : C.muted,
                            fontSize: 10,
                            fontWeight: selected ? FontWeight.w900 : FontWeight.w700,
                          ),
                        ),
                      ],
                    ),
                  ),
                );
              }),
            ),
          ),
        ),
      ),
    );
  }
}

void toast(BuildContext context, String message) {
  ScaffoldMessenger.of(context)
    ..hideCurrentSnackBar()
    ..showSnackBar(SnackBar(content: Text(message), behavior: SnackBarBehavior.floating));
}
