import 'package:flutter/material.dart';

enum VanScreenId {
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

extension VanScreenMeta on VanScreenId {
  String label(bool arabic) {
    switch (this) {
      case VanScreenId.login: return arabic ? 'تسجيل الدخول' : 'Login';
      case VanScreenId.dashboard: return arabic ? 'الرئيسية' : 'Home Dashboard';
      case VanScreenId.routes: return arabic ? 'المسارات' : 'Routes';
      case VanScreenId.routeMap: return arabic ? 'خريطة المسار' : 'Route Map';
      case VanScreenId.routeDetail: return arabic ? 'تفاصيل المسار' : 'Route Detail';
      case VanScreenId.customers: return arabic ? 'العملاء' : 'Customers';
      case VanScreenId.visit: return arabic ? 'مساحة الزيارة' : 'Visit Workspace';
      case VanScreenId.customer360: return arabic ? 'ملف العميل' : 'Customer 360';
      case VanScreenId.catalog: return arabic ? 'كتالوج المنتجات' : 'Product Catalog';
      case VanScreenId.orderBuilder: return arabic ? 'إنشاء طلب' : 'Order Builder';
      case VanScreenId.orderReview: return arabic ? 'مراجعة الطلب' : 'Order Review';
      case VanScreenId.orders: return arabic ? 'الطلبات' : 'Orders';
      case VanScreenId.offers: return arabic ? 'العروض' : 'Offers';
      case VanScreenId.wallet: return arabic ? 'المحفظة' : 'Wallet';
      case VanScreenId.collection: return arabic ? 'التحصيل' : 'Collection';
      case VanScreenId.receipt: return arabic ? 'الإيصال' : 'Receipt';
      case VanScreenId.remittance: return arabic ? 'التوريد' : 'Remittance';
      case VanScreenId.notifications: return arabic ? 'الإشعارات' : 'Notifications';
      case VanScreenId.profile: return arabic ? 'الملف والإعدادات' : 'Profile & Settings';
    }
  }

  IconData get icon {
    switch (this) {
      case VanScreenId.login: return Icons.lock_outline;
      case VanScreenId.dashboard: return Icons.dashboard_outlined;
      case VanScreenId.routes: return Icons.route_outlined;
      case VanScreenId.routeMap: return Icons.map_outlined;
      case VanScreenId.routeDetail: return Icons.alt_route_outlined;
      case VanScreenId.customers: return Icons.storefront_outlined;
      case VanScreenId.visit: return Icons.fact_check_outlined;
      case VanScreenId.customer360: return Icons.account_circle_outlined;
      case VanScreenId.catalog: return Icons.inventory_2_outlined;
      case VanScreenId.orderBuilder: return Icons.add_shopping_cart_outlined;
      case VanScreenId.orderReview: return Icons.receipt_long_outlined;
      case VanScreenId.orders: return Icons.list_alt_outlined;
      case VanScreenId.offers: return Icons.local_offer_outlined;
      case VanScreenId.wallet: return Icons.account_balance_wallet_outlined;
      case VanScreenId.collection: return Icons.payments_outlined;
      case VanScreenId.receipt: return Icons.receipt_outlined;
      case VanScreenId.remittance: return Icons.account_balance_outlined;
      case VanScreenId.notifications: return Icons.notifications_none_outlined;
      case VanScreenId.profile: return Icons.settings_outlined;
    }
  }
}

const vanProductionScreenInventory = <VanScreenId>[
  VanScreenId.login,
  VanScreenId.dashboard,
  VanScreenId.routes,
  VanScreenId.routeMap,
  VanScreenId.routeDetail,
  VanScreenId.customers,
  VanScreenId.visit,
  VanScreenId.customer360,
  VanScreenId.catalog,
  VanScreenId.orderBuilder,
  VanScreenId.orderReview,
  VanScreenId.orders,
  VanScreenId.offers,
  VanScreenId.wallet,
  VanScreenId.collection,
  VanScreenId.receipt,
  VanScreenId.remittance,
  VanScreenId.notifications,
  VanScreenId.profile,
];
