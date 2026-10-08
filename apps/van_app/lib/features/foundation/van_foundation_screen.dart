import 'package:flutter/material.dart';

import '../../core/auth/van_session.dart';
import '../../core/theme/foodex_van_theme.dart';
import '../commercial/van_commercial_contract.dart';
import '../commercial/van_offers_page.dart';
import '../notifications/van_notification_contract.dart';
import '../notifications/van_notifications_page.dart';
import '../orders/van_order_contract.dart';
import '../orders/van_product_catalog_page.dart';
import '../orders/van_order_builder_page.dart';
import '../orders/van_order_review_page.dart';
import '../orders/van_orders_page.dart';
import '../wallet/van_collection_page.dart';
import '../wallet/van_receipts_page.dart';
import '../wallet/van_remittance_page.dart';
import '../wallet/van_wallet_contract.dart';
import '../wallet/van_wallet_page.dart';
import '../visits/van_visit_contract.dart';
import '../visits/van_visit_workspace_page.dart';
import '../visits/van_routes_page.dart';
import '../visits/van_route_detail_page.dart';
import '../visits/van_route_map_page.dart';
import 'van_customer_360_page.dart';
import 'van_dashboard_page.dart';
import 'van_profile_page.dart';
import 'van_customers_page.dart';
import 'van_screen_inventory.dart';

class VanFoundationScreen extends StatefulWidget {
  const VanFoundationScreen({
    super.key,
    required this.session,
    required this.onLogout,
    required this.walletRepository,
    required this.commercialRepository,
    required this.visitRepository,
    required this.notificationRepository,
    required this.orderRepository,
  });

  final VanSession session;
  final Future<void> Function() onLogout;
  final VanWalletRepository walletRepository;
  final VanCommercialRepository commercialRepository;
  final VanVisitRepository visitRepository;
  final VanNotificationRepository notificationRepository;
  final VanOrderRepository orderRepository;

  @override
  State<VanFoundationScreen> createState() => _VanFoundationScreenState();
}

class _VanFoundationScreenState extends State<VanFoundationScreen> {
  final GlobalKey<ScaffoldState> _scaffoldKey = GlobalKey<ScaffoldState>();
  VanScreenId _screen = VanScreenId.dashboard;
  late final VanOrderDraftController _orderDraft;

  @override
  void initState() {
    super.initState();
    _orderDraft = VanOrderDraftController();
  }

  @override
  void dispose() {
    _orderDraft.dispose();
    super.dispose();
  }

  bool get _arabic => Localizations.localeOf(context).languageCode == 'ar';

  String _text(String en, String ar) => _arabic ? ar : en;

  void _open(VanScreenId screen) {
    if (_screen == screen) return;
    setState(() => _screen = screen);
  }

  void _openNavigationDrawer() {
    final scaffold = _scaffoldKey.currentState;
    if (scaffold == null) return;

    if (_arabic) {
      scaffold.openEndDrawer();
    } else {
      scaffold.openDrawer();
    }
  }

  void _selectFromNavigationDrawer(
    BuildContext drawerContext,
    VanScreenId screen,
  ) {
    Navigator.of(drawerContext).pop();
    _open(screen);
  }

  Widget _navigationDrawer(List<VanScreenId> screens) {
    return Drawer(
      key: const Key('van-navigation-drawer'),
      child: SafeArea(
        child: Column(
          children: [
            ListTile(
              leading: const Icon(
                Icons.local_shipping_outlined,
                color: FoodexVanTokens.green,
              ),
              title: Text(
                _text('FOODEX Van', 'فودكس · تطبيق الفان'),
                style: const TextStyle(fontWeight: FontWeight.w800),
              ),
              subtitle: Text(widget.session.name),
            ),
            const Divider(height: 1),
            Expanded(
              child: ListView.builder(
                key: const Key('van-production-screen-menu'),
                itemCount: screens.length,
                itemBuilder: (context, index) {
                  final screen = screens[index];
                  return ListTile(
                    key: ValueKey('van-screen-${screen.name}'),
                    selected: screen == _screen,
                    leading: Icon(screen.icon),
                    title: Text(screen.label(_arabic)),
                    onTap: () => _selectFromNavigationDrawer(context, screen),
                  );
                },
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _body() {
    switch (_screen) {
      case VanScreenId.dashboard:
        return VanDashboardPage(
          repository: widget.walletRepository,
          onSessionExpired: widget.onLogout,
          onOpenCustomers: () => _open(VanScreenId.customers),
          onOpenWallet: () => _open(VanScreenId.wallet),
          onOpenReceipts: () => _open(VanScreenId.receipt),
          onOpenRemittance: () => _open(VanScreenId.remittance),
        );
      case VanScreenId.routes:
        return VanRoutesPage(
          repository: widget.visitRepository,
          onSessionExpired: widget.onLogout,
        );
      case VanScreenId.routeMap:
        return VanRouteMapPage(
          visitRepository: widget.visitRepository,
          customerRepository: widget.walletRepository,
          onSessionExpired: widget.onLogout,
        );
      case VanScreenId.routeDetail:
        return VanRouteDetailPage(
          repository: widget.visitRepository,
          onSessionExpired: widget.onLogout,
        );
      case VanScreenId.catalog:
        return VanProductCatalogPage(
          repository: widget.orderRepository,
          customerRepository: widget.walletRepository,
          draft: _orderDraft,
          onOpenBuilder: () => _open(VanScreenId.orderBuilder),
          onSessionExpired: widget.onLogout,
        );
      case VanScreenId.orderBuilder:
        return VanOrderBuilderPage(
          repository: widget.orderRepository,
          draft: _orderDraft,
          onReview: () => _open(VanScreenId.orderReview),
          onSessionExpired: widget.onLogout,
        );
      case VanScreenId.orderReview:
        return VanOrderReviewPage(
          repository: widget.orderRepository,
          draft: _orderDraft,
          onSubmitted: (_) {
            _orderDraft.reset();
            _open(VanScreenId.orders);
          },
          onSessionExpired: widget.onLogout,
        );
      case VanScreenId.orders:
        return VanOrdersPage(
          repository: widget.orderRepository,
          customerRepository: widget.walletRepository,
          onSessionExpired: widget.onLogout,
        );
      case VanScreenId.offers:
        return VanOffersPage(
          session: widget.session,
          commercialRepository: widget.commercialRepository,
          customerRepository: widget.walletRepository,
          onSessionExpired: widget.onLogout,
        );
      case VanScreenId.customers:
        return VanCustomersPage(
          repository: widget.walletRepository,
          onSessionExpired: widget.onLogout,
        );
      case VanScreenId.visit:
        return VanVisitWorkspacePage(
          visitRepository: widget.visitRepository,
          customerRepository: widget.walletRepository,
          orderRepository: widget.orderRepository,
          onSessionExpired: widget.onLogout,
        );
      case VanScreenId.customer360:
        return VanCustomer360Page(
          repository: widget.walletRepository,
          onSessionExpired: widget.onLogout,
        );
      case VanScreenId.wallet:
        return VanWalletPage(
          repository: widget.walletRepository,
          onSessionExpired: widget.onLogout,
        );
      case VanScreenId.remittance:
        return VanRemittancePage(
          repository: widget.walletRepository,
          onSessionExpired: widget.onLogout,
        );
      case VanScreenId.receipt:
        return VanReceiptsPage(
          repository: widget.walletRepository,
          onSessionExpired: widget.onLogout,
        );
      case VanScreenId.collection:
        return VanCollectionPage(
          repository: widget.walletRepository,
          onSessionExpired: widget.onLogout,
        );
      case VanScreenId.notifications:
        return VanNotificationsPage(
          repository: widget.notificationRepository,
          onSessionExpired: widget.onLogout,
        );
      case VanScreenId.profile:
        return VanProfilePage(
          session: widget.session,
          onLogout: widget.onLogout,
        );
      case VanScreenId.login:
        return _OperationalState(
          screen: _screen,
          arabic: _arabic,
          message: _text(
            'Authentication is handled by the secure Van login flow before this shell is opened.',
            'تتم المصادقة عبر شاشة دخول الفان الآمنة قبل فتح هذه الواجهة.',
          ),
        );
    }
  }

  @override
  Widget build(BuildContext context) {
    final screens = vanProductionScreenInventory
        .where((screen) => screen != VanScreenId.login)
        .toList(growable: false);

    final navigationDrawer = _navigationDrawer(screens);

    return Scaffold(
      key: _scaffoldKey,
      appBar: AppBar(
        automaticallyImplyLeading: false,
        leading: _arabic
            ? null
            : IconButton(
                key: const Key('van-menu-toggle'),
                tooltip: _text('Open navigation menu', 'فتح قائمة التنقل'),
                onPressed: _openNavigationDrawer,
                icon: const Icon(Icons.menu),
              ),
        title: Text(_screen.label(_arabic)),
        actions: [
          SizedBox(
            width: MediaQuery.sizeOf(context).width * 0.28,
            child: Align(
              alignment: AlignmentDirectional.centerEnd,
              child: Text(
                widget.session.name,
                key: const Key('van-session-name'),
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
              ),
            ),
          ),
          IconButton(
            key: const Key('van-logout'),
            tooltip: _text('Sign out', 'تسجيل الخروج'),
            onPressed: widget.onLogout,
            icon: const Icon(Icons.logout),
          ),
          if (_arabic)
            IconButton(
              key: const Key('van-menu-toggle'),
              tooltip: _text('Open navigation menu', 'فتح قائمة التنقل'),
              onPressed: _openNavigationDrawer,
              icon: const Icon(Icons.menu),
            ),
        ],
      ),
      drawer: _arabic ? null : navigationDrawer,
      endDrawer: _arabic ? navigationDrawer : null,
      body: _body(),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _primaryIndex(_screen),
        onDestinationSelected: (index) => _open(_primaryScreens[index]),
        destinations: [
          for (final screen in _primaryScreens)
            NavigationDestination(
              icon: Icon(screen.icon),
              label: screen.label(_arabic),
            ),
        ],
      ),
    );
  }
}

const _primaryScreens = <VanScreenId>[
  VanScreenId.dashboard,
  VanScreenId.routes,
  VanScreenId.customers,
  VanScreenId.orders,
  VanScreenId.wallet,
];

int _primaryIndex(VanScreenId screen) {
  final index = _primaryScreens.indexOf(screen);
  return index >= 0 ? index : 0;
}

class _OperationalState extends StatelessWidget {
  const _OperationalState({
    required this.screen,
    required this.arabic,
    required this.message,
  });

  final VanScreenId screen;
  final bool arabic;
  final String message;

  @override
  Widget build(BuildContext context) {
    return ListView(
      key: ValueKey('van-production-surface-${screen.name}'),
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
                Icon(screen.icon, size: 32, color: FoodexVanTokens.green),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        screen.label(arabic),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: Theme.of(context).textTheme.titleMedium?.copyWith(
                              fontWeight: FontWeight.w800,
                            ),
                      ),
                      const SizedBox(height: 6),
                      Text(
                        message,
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
