import 'dart:math' as math;
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:share_plus/share_plus.dart';

import '../../core/api/b2b_api.dart';
import '../../core/api/b2c_account_api.dart';
import '../../core/api/customer_action_api.dart';
import '../../core/auth/customer_session.dart';
import '../../core/diagnostics/customer_diagnostics.dart';
import '../../core/localization/app_translations.dart';
import '../../core/routing/customer_commerce_context.dart';
import '../../core/routing/customer_routes.dart';
import '../customer_account/customer_address_book_screen.dart';
import '../customer_account/customer_account_data.dart';
import '../customer_account/customer_notification_center_screen.dart';
import '../customer_orders/customer_order_screens.dart';
import 'business_account_profile.dart';
import '../customer_orders/customer_orders_api.dart';
import '../../shared/customer_action_widgets.dart';
import '../../shared/customer_persistent_footer.dart';

class B2bJourneyScreen extends StatelessWidget {
  const B2bJourneyScreen({
    required this.definition,
    required this.location,
    required this.actionApi,
    this.api,
    this.accountApi,
    this.ordersApi,
    this.onLocaleChanged,
    this.onLogout,
    super.key,
  });

  final CustomerRouteDefinition definition;
  final String location;
  final B2bApi? api;
  final B2cAccountApi? accountApi;
  final CustomerOrdersApi? ordersApi;
  final CustomerActionApi actionApi;
  final ValueChanged<Locale>? onLocaleChanged;
  final Future<void> Function()? onLogout;

  @override
  Widget build(BuildContext context) {
    final commerceContext =
        CustomerCommerceContext.tryParseLocation(location);

    Widget withFooter(
      Widget child,
      CustomerFooterDestination destination,
    ) {
      if (commerceContext == null || !commerceContext.isWholesale) {
        return child;
      }
      return CustomerPersistentFooterShell(
        commerceContext: commerceContext,
        activeDestination: destination,
        child: child,
      );
    }

    if (definition.pattern == CustomerRoutePaths.b2bAddresses &&
        accountApi != null) {
      return withFooter(
        CustomerAddressBookScreen(api: accountApi!),
        CustomerFooterDestination.account,
      );
    }

    if (definition.pattern == CustomerRoutePaths.b2bNotifications &&
        accountApi != null) {
      return withFooter(
        CustomerNotificationCenterScreen(
          api: accountApi!,
          onOpenOrder: (target) => _openNotificationOrder(context, target),
        ),
        CustomerFooterDestination.account,
      );
    }

    if (definition.pattern == CustomerRoutePaths.b2bOrders &&
        ordersApi != null) {
      return withFooter(
        CustomerOrdersScreen(
          api: ordersApi!,
          actionApi: actionApi,
          onOpenCart: (order) {
            final orderContext = CustomerCommerceContext(
              channel: CustomerCommerceChannel.wholesale,
              storeId: order.storeId,
            );
            Navigator.of(context).pushNamed(
              Uri(
                path: CustomerRoutePaths.b2bCart,
                queryParameters: orderContext.toQueryParameters(),
              ).toString(),
            );
          },
          onOpenOrder: (order) {
            final orderContext = CustomerCommerceContext(
              channel: order.channel == 'b2b'
                  ? CustomerCommerceChannel.wholesale
                  : CustomerCommerceChannel.retail,
              storeId: order.storeId,
            );
            final target = order.channel == 'b2b'
                ? Uri(
                    path: '/b2b/orders/${order.id}',
                    queryParameters: orderContext.toQueryParameters(),
                  ).toString()
                : Uri(
                    path: '/orders/${order.id}/track',
                    queryParameters: orderContext.toQueryParameters(),
                  ).toString();
            Navigator.of(context).pushNamed(target);
          },
        ),
        CustomerFooterDestination.orders,
      );
    }

    final content = _contentFor(context, definition.pattern);
    final hasRemoteState = api != null && _endpoint() != null;
    final isProfile =
        definition.pattern == CustomerRoutePaths.b2bProfile;
    final keepLocalActions =
        definition.pattern == CustomerRoutePaths.b2bProductDetails ||
        definition.pattern == CustomerRoutePaths.b2bCart;

    final destination = switch (definition.pattern) {
      CustomerRoutePaths.b2bProducts ||
      CustomerRoutePaths.b2bProductDetails =>
        CustomerFooterDestination.products,
      CustomerRoutePaths.b2bCart ||
      CustomerRoutePaths.b2bCheckout =>
        CustomerFooterDestination.cart,
      CustomerRoutePaths.b2bOrders ||
      CustomerRoutePaths.b2bOrderDetails =>
        CustomerFooterDestination.orders,
      CustomerRoutePaths.b2bProfile ||
      CustomerRoutePaths.b2bAddresses ||
      CustomerRoutePaths.b2bNotifications =>
        CustomerFooterDestination.account,
      _ => CustomerFooterDestination.home,
    };

    return withFooter(
      Scaffold(
      appBar: AppBar(title: Text(context.tr('b2b.app.title'))),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(20),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
            Text(
              content.$1,
              key: const ValueKey('customer-route-label'),
              style: Theme.of(context).textTheme.headlineSmall,
            ),
            const SizedBox(height: 8),
            Text(content.$2),
            const SizedBox(height: 20),
            if (!hasRemoteState || keepLocalActions) ...content.$3,
            if (hasRemoteState && isProfile) ...[
              B2bBusinessAccountProfile(
                api: api!,
                endpoint: _endpoint()!,
                addressesRoute:
                    _scopedB2bRoute(CustomerRoutePaths.b2bAddresses),
              ),
              const SizedBox(height: 14),
              _B2bAccountHub(
                api: api!,
                financeEndpoint: _accountSummaryEndpoint(),
                purchasesRoute:
                    _scopedB2bRoute(CustomerRoutePaths.b2bPurchaseReports),
                invoicesRoute:
                    _scopedB2bRoute(CustomerRoutePaths.b2bInvoices),
                statementRoute:
                    _scopedB2bRoute(CustomerRoutePaths.b2bAccountStatement),
                ordersRoute:
                    _scopedB2bRoute(CustomerRoutePaths.b2bOrders),
                notificationsRoute:
                    _scopedB2bRoute(CustomerRoutePaths.b2bNotifications),
                addressesRoute:
                    _scopedB2bRoute(CustomerRoutePaths.b2bAddresses),
                onLocaleChanged: onLocaleChanged,
                onLogout: onLogout,
              ),
            ],
            if (hasRemoteState && keepLocalActions)
              definition.pattern == CustomerRoutePaths.b2bProductDetails
                  ? _B2bProductDetailRemoteState(
                      api: api!,
                      endpoint: _endpoint()!,
                    )
                  : _RemoteState(
                      api: api!,
                      endpoint: _endpoint()!,
                      routePattern: definition.pattern,
                      showEmpty: false,
                    ),
            if (hasRemoteState && !keepLocalActions && !isProfile)
              definition.pattern == CustomerRoutePaths.b2bDashboard
                  ? _B2bDashboardRemoteState(
                      api: api!,
                      endpoint: _endpoint()!,
                    )
                  : definition.pattern == CustomerRoutePaths.b2bPurchaseReports
                      ? _PurchaseReportRemoteState(
                          api: api!,
                          endpoint: _endpoint()!,
                        )
                      : definition.pattern == CustomerRoutePaths.b2bTopProducts
                          ? _TopProductsRemoteState(
                              api: api!,
                              endpoint: _endpoint()!,
                            )
                          : definition.pattern == CustomerRoutePaths.b2bInvoices
                          ? _InvoicesRemoteState(
                              api: api!,
                              endpoint: _endpoint()!,
                            )
                          : definition.pattern ==
                                  CustomerRoutePaths.b2bInvoiceDetails
                              ? _InvoiceDetailRemoteState(
                                  api: api!,
                                  endpoint: _endpoint()!,
                                )
                              : definition.pattern ==
                                      CustomerRoutePaths.b2bAccountStatement
                                  ? _B2bAccountStatementRemoteState(
                                      api: api!,
                                      endpoint: _endpoint()!,
                                    )
                                  : _RemoteState(
                                      api: api!,
                                      endpoint: _endpoint()!,
                                      routePattern: definition.pattern,
                                    ),
            ],
          ),
        ),
      ),
      ),
      destination,
    );
  }

  (String, String, List<Widget>) _contentFor(
    BuildContext context,
    String pattern,
  ) {
    switch (pattern) {
      case CustomerRoutePaths.b2bDashboard:
        return (
          context.tr('b2b.dashboard.title'),
          context.tr('b2b.dashboard.subtitle'),
          [
            _section(context.tr('b2b.finance.purchases')),
            _section(context.tr('b2b.finance.invoices')),
            _section(context.tr('b2b.finance.statement')),
          ],
        );
      case CustomerRoutePaths.b2bPurchaseReports:
        return (
          context.tr('b2b.purchase_reports.title'),
          context.tr('b2b.purchase_reports.subtitle'),
          [_empty(context.tr('b2b.empty.period'))],
        );
      case CustomerRoutePaths.b2bTopProducts:
        return (
          context.tr('b2b.top_products.title'),
          context.tr('b2b.top_products.subtitle'),
          [_empty(context.tr('b2b.empty.purchases'))],
        );
      case CustomerRoutePaths.b2bProducts:
        return (
          context.tr('b2b.products.title'),
          context.tr('b2b.products.subtitle'),
          [
            SearchBar(hintText: context.tr('customer.products.search')),
            _empty(context.tr('b2b.empty.products')),
          ],
        );
      case CustomerRoutePaths.b2bProductDetails:
        return (
          context.tr('b2b.product.title'),
          context.tr('b2b.product.subtitle'),
          [
            AddCartAction(
              api: actionApi,
              location: location,
              cartRoute: _b2bCartRoute(),
            ),
          ],
        );
      case CustomerRoutePaths.b2bInvoices:
        return (
          context.tr('b2b.invoices.title'),
          context.tr('b2b.invoices.subtitle'),
          [_empty(context.tr('b2b.empty.invoices'))],
        );
      case CustomerRoutePaths.b2bInvoiceDetails:
        return (
          context.tr('b2b.invoice.title'),
          context.tr('b2b.invoice.subtitle'),
          [
            _section(context.tr('b2b.invoice.items')),
            _section(context.tr('b2b.invoice.payment_status')),
          ],
        );
      case CustomerRoutePaths.b2bAccountStatement:
        return (
          context.tr('b2b.statement.title'),
          context.tr('b2b.statement.subtitle'),
          [_empty(context.tr('b2b.empty.statement'))],
        );
      case CustomerRoutePaths.b2bOrders:
        return (
          context.tr('b2b.orders.title'),
          context.tr('b2b.orders.subtitle'),
          [_empty(context.tr('b2b.empty.orders'))],
        );
      case CustomerRoutePaths.b2bOrderDetails:
        return (
          context.tr('b2b.order.title'),
          context.tr('b2b.order.subtitle'),
          [
            _section(context.tr('b2b.order.status')),
            _section(context.tr('b2b.order.tracking')),
          ],
        );
      case CustomerRoutePaths.b2bNotifications:
        return (
          context.tr('customer.notifications.title'),
          context.tr('customer.notifications.subtitle'),
          const <Widget>[],
        );
      case CustomerRoutePaths.b2bCart:
        return (
          context.tr('b2b.cart.title'),
          context.tr('b2b.cart.subtitle'),
          [
            _empty(context.tr('b2b.cart.empty')),
            _button(
              context,
              context.tr('b2b.action.checkout'),
              CustomerRoutePaths.b2bCheckout,
            ),
          ],
        );
      case CustomerRoutePaths.b2bCheckout:
        return (
          context.tr('customer.checkout.title'),
          context.tr('customer.checkout.subtitle'),
          [
            CheckoutAction(
              api: actionApi,
              channel: CustomerChannel.b2b,
              accountApi: accountApi,
            ),
          ],
        );
      case CustomerRoutePaths.b2bAddresses:
        return (
          context.tr('customer.profile.addresses'),
          context.tr('customer.addresses.empty'),
          [_empty(context.tr('customer.addresses.empty'))],
        );
      case CustomerRoutePaths.b2bProfile:
        return (
          context.tr('b2b.profile.title'),
          context.tr('b2b.profile.subtitle'),
          [
            _section(context.tr('b2b.profile.company')),
            _button(
              context,
              context.tr('customer.profile.addresses'),
              _scopedB2bRoute(CustomerRoutePaths.b2bAddresses),
            ),
            _section(context.tr('b2b.profile.settings')),
          ],
        );
      default:
        return (
          definition.label,
          'FOODEX Business',
          [_empty(context.tr('customer.empty'))],
        );
    }
  }

  void _openNotificationOrder(
    BuildContext context,
    CustomerNotificationTarget target,
  ) {
    final uri = Uri.parse(location);
    final currentStoreId = int.tryParse(
      uri.queryParameters['store_id'] ??
          uri.queryParameters['store'] ??
          '',
    );

    if (target.channel.toLowerCase() == 'b2b') {
      final storeId = target.storeId ?? currentStoreId;
      if (storeId == null || storeId <= 0) return;
      Navigator.of(context).pushNamed(
        Uri(
          path: '/b2b/orders/${target.orderId}',
          queryParameters: <String, String>{
            'channel': 'wholesale',
            'store_id': storeId.toString(),
          },
        ).toString(),
      );
      return;
    }

    if (target.channel.toLowerCase() == 'b2c') {
      final storeId = target.storeId;
      if (storeId == null || storeId <= 0) return;
      Navigator.of(context).pushNamed(
        Uri(
          path: '/orders/${target.orderId}/track',
          queryParameters: <String, String>{
            'channel': 'retail',
            'store_id': storeId.toString(),
          },
        ).toString(),
      );
    }
  }

  String _scopedB2bRoute(String path) {
    final commerceContext =
        CustomerCommerceContext.tryParseLocation(location);
    if (commerceContext == null || !commerceContext.isWholesale) {
      return path;
    }
    return Uri(
      path: path,
      queryParameters: commerceContext.toQueryParameters(),
    ).toString();
  }

  String _accountSummaryEndpoint() {
    final uri = Uri.parse(location);
    final storeId =
        uri.queryParameters['store_id'] ?? uri.queryParameters['store'];
    if (storeId == null || storeId.isEmpty) {
      return '/api/v1/b2b/account-summary';
    }
    return '/api/v1/b2b/account-summary?store_id=$storeId';
  }

  String _b2bCartRoute() {
    final uri = Uri.parse(location);
    final storeId =
        uri.queryParameters['store_id'] ?? uri.queryParameters['store'];

    if (storeId == null || storeId.isEmpty) {
      return CustomerRoutePaths.b2bCart;
    }

    return Uri(
      path: CustomerRoutePaths.b2bCart,
      queryParameters: {'store': storeId},
    ).toString();
  }

  String? _endpoint() {
    final uri = Uri.parse(location);
    final segments = uri.pathSegments;
    switch (definition.pattern) {
      case CustomerRoutePaths.b2bDashboard:
        return '/api/v1/b2b/dashboard${uri.hasQuery ? '?${uri.query}' : ''}';
      case CustomerRoutePaths.b2bPurchaseReports:
        final storeId =
            uri.queryParameters['store_id'] ?? uri.queryParameters['store'];
        return '/api/v1/b2b/reports/purchases'
            '${storeId == null || storeId.isEmpty ? '' : '?store_id=$storeId'}';
      case CustomerRoutePaths.b2bTopProducts:
        return '/api/v1/b2b/products/top${uri.hasQuery ? '?${uri.query}' : ''}';
      case CustomerRoutePaths.b2bProducts:
        return '/api/v1/b2b/products${uri.hasQuery ? '?${uri.query}' : ''}';
      case CustomerRoutePaths.b2bProductDetails:
        final storeId =
            uri.queryParameters['store_id'] ?? uri.queryParameters['store'];
        if (storeId == null || storeId.isEmpty) return null;
        return '/api/v1/b2b/products/${segments.last}?store_id=$storeId';
      case CustomerRoutePaths.b2bInvoices:
        return '/api/v1/b2b/invoices${uri.hasQuery ? '?${uri.query}' : ''}';
      case CustomerRoutePaths.b2bInvoiceDetails:
        final storeId =
            uri.queryParameters['store_id'] ?? uri.queryParameters['store'];
        return '/api/v1/b2b/invoices/${segments.last}'
            '${storeId == null || storeId.isEmpty ? '' : '?store_id=$storeId'}';
      case CustomerRoutePaths.b2bAccountStatement:
        return '/api/v1/b2b/account-statement${uri.hasQuery ? '?${uri.query}' : ''}';
      case CustomerRoutePaths.b2bOrders:
        return '/api/v1/b2b/orders';
      case CustomerRoutePaths.b2bOrderDetails:
        return '/api/v1/b2b/orders/${segments.last}';
      case CustomerRoutePaths.b2bCart:
        return '/api/v1/cart${uri.hasQuery ? '?${uri.query}' : ''}';
      case CustomerRoutePaths.b2bProfile:
        return '/api/v1/profile';
      default:
        return null;
    }
  }

  Widget _button(
    BuildContext context,
    String label,
    String route,
  ) =>
      Padding(
        padding: const EdgeInsets.only(bottom: 12),
        child: FilledButton(
          onPressed: () => Navigator.of(context).pushNamed(route),
          child: Text(label),
        ),
      );

  Widget _section(String label) => Card(
        child: ListTile(
          title: Text(label),
          trailing: const Icon(Icons.chevron_right),
        ),
      );

  Widget _empty(String label) => Card(
        key: const ValueKey('b2b-empty'),
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Center(child: Text(label)),
        ),
      );
}



class _B2bDashboardRemoteState extends StatefulWidget {
  const _B2bDashboardRemoteState({
    required this.api,
    required this.endpoint,
  });

  final B2bApi api;
  final String endpoint;

  @override
  State<_B2bDashboardRemoteState> createState() =>
      _B2bDashboardRemoteStateState();
}

class _B2bDashboardRemoteStateState extends State<_B2bDashboardRemoteState>
    with WidgetsBindingObserver {
  late Future<Object?> _future;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _future = _loadB2bRemote(widget.api, widget.endpoint);
  }

  @override
  void didUpdateWidget(covariant _B2bDashboardRemoteState oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.api != widget.api || oldWidget.endpoint != widget.endpoint) {
      _future = _loadB2bRemote(widget.api, widget.endpoint);
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      _reload();
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  void _reload() {
    if (!mounted) return;
    setState(() {
      _future = _loadB2bRemote(widget.api, widget.endpoint);
    });
  }

  String _scopedRoute(String path) {
    final source = Uri.parse(widget.endpoint);
    final params = <String, String>{};
    for (final key in const [
      'channel',
      'store_id',
      'store',
      'retail_store_id',
    ]) {
      final value = source.queryParameters[key];
      if (value != null && value.isNotEmpty) {
        params[key] = value;
      }
    }
    return Uri(
      path: path,
      queryParameters: params.isEmpty ? null : params,
    ).toString();
  }

  String _money(Object? value, String currency) {
    final amount = double.tryParse(value?.toString() ?? '');
    if (amount == null) return '—';
    return '${amount.toStringAsFixed(3)} $currency';
  }

  Widget _metric(
    BuildContext context, {
    required String keyName,
    required String label,
    required String value,
    required IconData icon,
    required String route,
  }) {
    return Card(
      key: ValueKey('b2b-dashboard-$keyName'),
      child: InkWell(
        onTap: () => Navigator.of(context).pushNamed(_scopedRoute(route)),
        child: ListTile(
          leading: Icon(icon),
          title: Text(label),
          subtitle: Text(
            value,
            style: Theme.of(context).textTheme.titleMedium?.copyWith(
                  fontWeight: FontWeight.w800,
                ),
          ),
          trailing: const Icon(Icons.chevron_right_rounded),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) => FutureBuilder<Object?>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done) {
            return const Center(
              key: ValueKey('b2b-loading'),
              child: CircularProgressIndicator(),
            );
          }

          if (snapshot.hasError) {
            return _B2bRemoteErrorCard(
              error: snapshot.error,
              onRetry: _reload,
            );
          }

          final value = snapshot.data;
          if (value is! Map) {
            return Card(
              key: const ValueKey('b2b-empty'),
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Text(context.tr('b2b.remote.empty')),
              ),
            );
          }

          final data = Map<Object?, Object?>.from(value);
          final customer = data['customer'] is Map
              ? Map<Object?, Object?>.from(data['customer'] as Map)
              : <Object?, Object?>{};
          final account = data['account'] is Map
              ? Map<Object?, Object?>.from(data['account'] as Map)
              : <Object?, Object?>{};
          final finance = data['finance'] is Map
              ? Map<Object?, Object?>.from(data['finance'] as Map)
              : <Object?, Object?>{};
          final operations = data['operations'] is Map
              ? Map<Object?, Object?>.from(data['operations'] as Map)
              : <Object?, Object?>{};
          final freshness = data['freshness'] is Map
              ? Map<Object?, Object?>.from(data['freshness'] as Map)
              : <Object?, Object?>{};

          final currency = finance['currency']?.toString() ??
              data['currency']?.toString() ??
              '';
          final rawBalance =
              double.tryParse(finance['balance']?.toString() ?? '') ?? 0;
          final balanceDirection =
              finance['balance_direction']?.toString() ?? 'settled';
          final directionLabel = switch (balanceDirection) {
            'customer_owes_company' =>
              context.tr('b2b.dashboard.balance.you_owe'),
            'company_owes_customer' =>
              context.tr('b2b.dashboard.balance.credit'),
            _ => context.tr('b2b.dashboard.balance.settled'),
          };
          final generatedAt = freshness['generated_at']?.toString();
          final generatedDate =
              generatedAt == null ? null : DateTime.tryParse(generatedAt);
          final age = generatedDate == null
              ? null
              : DateTime.now().toUtc().difference(generatedDate.toUtc());
          final stale = freshness['stale'] == true ||
              (age != null &&
                  !age.isNegative &&
                  age > const Duration(minutes: 5));

          return Column(
            key: const ValueKey('b2b-dashboard-data'),
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Card(
                key: const ValueKey('b2b-dashboard-identity'),
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Text(
                        account['company_name']?.toString() ?? '—',
                        style: Theme.of(context).textTheme.titleLarge?.copyWith(
                              fontWeight: FontWeight.w800,
                            ),
                      ),
                      const SizedBox(height: 4),
                      Text(customer['name']?.toString() ?? '—'),
                      if ((customer['email']?.toString() ?? '').isNotEmpty)
                        Text(customer['email'].toString()),
                      const SizedBox(height: 12),
                      Row(
                        children: [
                          Expanded(
                            child: Text(
                              "${context.tr('b2b.dashboard.last_updated')}: ${generatedAt ?? '—'}",
                              key: const ValueKey(
                                'b2b-dashboard-last-updated',
                              ),
                            ),
                          ),
                          IconButton(
                            key: const ValueKey('b2b-dashboard-refresh'),
                            tooltip: context.tr('b2b.dashboard.refresh'),
                            onPressed: _reload,
                            icon: const Icon(Icons.refresh_rounded),
                          ),
                        ],
                      ),
                      if (stale)
                        Text(
                          context.tr('b2b.dashboard.stale'),
                          key: const ValueKey('b2b-dashboard-stale'),
                          style: Theme.of(context).textTheme.bodySmall,
                        ),
                    ],
                  ),
                ),
              ),
              const SizedBox(height: 8),
              Text(
                context.tr('b2b.dashboard.finance'),
                style: Theme.of(context).textTheme.titleMedium?.copyWith(
                      fontWeight: FontWeight.w800,
                    ),
              ),
              _metric(
                context,
                keyName: 'balance',
                label: context.tr('b2b.dashboard.balance'),
                value: '$directionLabel ${_money(rawBalance.abs(), currency)}',
                icon: Icons.account_balance_wallet_outlined,
                route: CustomerRoutePaths.b2bAccountStatement,
              ),
              _metric(
                context,
                keyName: 'credit-limit',
                label: context.tr('b2b.dashboard.credit_limit'),
                value: _money(finance['credit_limit'], currency),
                icon: Icons.credit_score_outlined,
                route: CustomerRoutePaths.b2bAccountStatement,
              ),
              _metric(
                context,
                keyName: 'available-credit',
                label: context.tr('b2b.dashboard.available_credit'),
                value: _money(finance['available_credit_line'], currency),
                icon: Icons.savings_outlined,
                route: CustomerRoutePaths.b2bAccountStatement,
              ),
              _metric(
                context,
                keyName: 'open-invoices',
                label: context.tr('b2b.dashboard.open_invoices'),
                value: _money(finance['open_amount'], currency),
                icon: Icons.receipt_long_outlined,
                route: CustomerRoutePaths.b2bInvoices,
              ),
              _metric(
                context,
                keyName: 'overdue',
                label: context.tr('b2b.dashboard.overdue'),
                value: _money(finance['overdue_amount'], currency),
                icon: Icons.warning_amber_rounded,
                route: CustomerRoutePaths.b2bInvoices,
              ),
              const SizedBox(height: 8),
              Text(
                context.tr('b2b.dashboard.operations'),
                style: Theme.of(context).textTheme.titleMedium?.copyWith(
                      fontWeight: FontWeight.w800,
                    ),
              ),
              _metric(
                context,
                keyName: 'purchases-month',
                label: context.tr('b2b.dashboard.purchases_month'),
                value: _money(
                  operations['purchases_this_month'] ??
                      data['purchases_total'] ??
                      data['purchase_total'],
                  currency,
                ),
                icon: Icons.shopping_bag_outlined,
                route: CustomerRoutePaths.b2bPurchaseReports,
              ),
              _metric(
                context,
                keyName: 'payments-month',
                label: context.tr('b2b.dashboard.payments_month'),
                value: _money(operations['payments_this_month'], currency),
                icon: Icons.payments_outlined,
                route: CustomerRoutePaths.b2bAccountStatement,
              ),
              _metric(
                context,
                keyName: 'invoice-count',
                label: context.tr('b2b.dashboard.invoice_count'),
                value: operations['invoice_count']?.toString() ?? '0',
                icon: Icons.description_outlined,
                route: CustomerRoutePaths.b2bInvoices,
              ),
              _metric(
                context,
                keyName: 'order-count',
                label: context.tr('b2b.dashboard.order_count'),
                value: operations['order_count']?.toString() ?? '0',
                icon: Icons.inventory_2_outlined,
                route: CustomerRoutePaths.b2bOrders,
              ),
              _metric(
                context,
                keyName: 'active-orders',
                label: context.tr('b2b.dashboard.active_orders'),
                value: operations['active_orders']?.toString() ?? '0',
                icon: Icons.local_shipping_outlined,
                route: CustomerRoutePaths.b2bOrders,
              ),
              const SizedBox(height: 8),
              Card(
                key: const ValueKey('b2b-dashboard-offers'),
                child: ListTile(
                  leading: const Icon(Icons.local_offer_outlined),
                  title: Text(context.tr('b2b.dashboard.offers')),
                  subtitle: Text(context.tr('b2b.dashboard.offers_cta')),
                  trailing: const Icon(Icons.chevron_right_rounded),
                  onTap: () => Navigator.of(context).pushNamed(
                    _scopedRoute(CustomerRoutePaths.b2bTopProducts),
                  ),
                ),
              ),
            ],
          );
        },
      );
}

class _B2bProductDetailRemoteState extends StatefulWidget {
  const _B2bProductDetailRemoteState({
    required this.api,
    required this.endpoint,
  });

  final B2bApi api;
  final String endpoint;

  @override
  State<_B2bProductDetailRemoteState> createState() =>
      _B2bProductDetailRemoteStateState();
}

class _B2bProductDetailRemoteStateState
    extends State<_B2bProductDetailRemoteState> {
  late Future<Object?> _future;

  @override
  void initState() {
    super.initState();
    _future = _loadB2bRemote(widget.api, widget.endpoint);
  }

  @override
  void didUpdateWidget(covariant _B2bProductDetailRemoteState oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.api != widget.api || oldWidget.endpoint != widget.endpoint) {
      _future = _loadB2bRemote(widget.api, widget.endpoint);
    }
  }

  void _retry() {
    setState(() {
      _future = _loadB2bRemote(widget.api, widget.endpoint);
    });
  }

  @override
  Widget build(BuildContext context) => FutureBuilder<Object?>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done) {
            return const Center(
              key: ValueKey('b2b-loading'),
              child: CircularProgressIndicator(),
            );
          }

          if (snapshot.hasError) {
            return _B2bRemoteErrorCard(
              error: snapshot.error,
              onRetry: _retry,
              backRoute: _productsBackRoute(widget.endpoint),
            );
          }

          final value = snapshot.data;
          if (value is! Map) {
            return Card(
              key: const ValueKey('b2b-empty'),
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Text(context.tr('b2b.remote.empty')),
              ),
            );
          }

          final name = value['name']?.toString() ?? '';
          final sku = value['sku']?.toString() ?? '';
          final price = value['account_price']?.toString() ?? '-';
          final minimum =
              value['minimum_order_quantity']?.toString() ?? '-';
          final available = value['available_quantity']?.toString();
          final currency = value['currency']?.toString() ?? 'KWD';
          final tier = value['price_tier']?.toString() ?? '-';
          final images = (value['images'] as List? ?? const [])
              .whereType<String>()
              .where((url) => url.trim().isNotEmpty)
              .toList(growable: false);
          final primaryImage = value['image_url']?.toString();
          final gallery = images.isNotEmpty
              ? images
              : (primaryImage == null || primaryImage.isEmpty
                  ? const <String>[]
                  : <String>[primaryImage]);

          return Card(
            key: const ValueKey('b2b-product-detail-data'),
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  if (gallery.isNotEmpty)
                    SizedBox(
                      key: const ValueKey('b2b-product-gallery'),
                      height: 240,
                      child: PageView.builder(
                        itemCount: gallery.length,
                        itemBuilder: (_, index) => Padding(
                          padding: const EdgeInsets.only(bottom: 12),
                          child: _B2bCatalogImage(url: gallery[index]),
                        ),
                      ),
                    ),
                  Text(
                    name,
                    style: Theme.of(context).textTheme.titleLarge,
                  ),
                  const SizedBox(height: 6),
                  Text(sku),
                  const SizedBox(height: 12),
                  Text(
                    '${context.tr('b2b.product.account_price')}: $price $currency',
                  ),
                  Text(
                    '${context.tr('b2b.minimum_order')}: $minimum',
                  ),
                  Text(
                    '${context.tr('b2b.product.inventory')}: ${available ?? context.tr('b2b.product.inventory_unbounded')}',
                  ),
                  Text(
                    '${context.tr('b2b.product.price_tier')}: $tier',
                  ),
                ],
              ),
            ),
          );
        },
      );
}

class _B2bCatalogImage extends StatelessWidget {
  const _B2bCatalogImage({required this.url});

  final String url;

  @override
  Widget build(BuildContext context) => ClipRRect(
        borderRadius: BorderRadius.circular(18),
        child: ColoredBox(
          color: const Color(0xFFF2F4F7),
          child: Image.network(
            url,
            fit: BoxFit.cover,
            loadingBuilder: (context, child, progress) => progress == null
                ? child
                : const Center(child: CircularProgressIndicator()),
            errorBuilder: (_, __, ___) => const Center(
              child: Icon(
                Icons.shopping_basket_outlined,
                color: Color(0xFF087347),
                size: 42,
              ),
            ),
          ),
        ),
      );
}

enum _TopProductsPeriod { all, current, previous, custom }

class _TopProductsRemoteState extends StatefulWidget {
  const _TopProductsRemoteState({required this.api, required this.endpoint});

  final B2bApi api;
  final String endpoint;

  @override
  State<_TopProductsRemoteState> createState() => _TopProductsRemoteStateState();
}

class _TopProductsRemoteStateState extends State<_TopProductsRemoteState> {
  late Future<Object?> _future;
  late TextEditingController _searchController;
  DateTime? _from;
  DateTime? _to;
  _TopProductsPeriod _period = _TopProductsPeriod.all;
  String _sort = 'quantity';
  int _page = 1;
  int _perPage = 20;

  @override
  void initState() {
    super.initState();
    _syncControlsFromEndpoint();
    _future = _loadB2bRemote(widget.api, widget.endpoint);
  }

  @override
  void didUpdateWidget(covariant _TopProductsRemoteState oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.api != widget.api || oldWidget.endpoint != widget.endpoint) {
      _searchController.dispose();
      _syncControlsFromEndpoint();
      _future = _loadB2bRemote(widget.api, widget.endpoint);
    }
  }

  @override
  void dispose() {
    _searchController.dispose();
    super.dispose();
  }

  void _syncControlsFromEndpoint() {
    final uri = Uri.parse(widget.endpoint);
    _from = DateTime.tryParse(uri.queryParameters['from'] ?? '');
    _to = DateTime.tryParse(uri.queryParameters['to'] ?? '');
    _period = _from == null && _to == null
        ? _TopProductsPeriod.all
        : _TopProductsPeriod.custom;
    final requestedSort = uri.queryParameters['sort'];
    _sort = requestedSort == 'value' ? 'value' : 'quantity';
    _page = int.tryParse(uri.queryParameters['page'] ?? '') ?? 1;
    _perPage = int.tryParse(
          uri.queryParameters['per_page'] ?? uri.queryParameters['limit'] ?? '',
        ) ??
        20;
    _searchController = TextEditingController(
      text: uri.queryParameters['q'] ?? '',
    );
  }

  String _effectiveEndpoint({int? page}) {
    final uri = Uri.parse(widget.endpoint);
    final params = Map<String, String>.from(uri.queryParameters)
      ..remove('from')
      ..remove('to')
      ..remove('q')
      ..remove('sort')
      ..remove('page')
      ..remove('per_page')
      ..remove('limit');

    if (_from != null) params['from'] = _isoDate(_from!);
    if (_to != null) params['to'] = _isoDate(_to!);
    final search = _searchController.text.trim();
    if (search.isNotEmpty) params['q'] = search;
    params['sort'] = _sort;
    params['page'] = (page ?? _page).toString();
    params['per_page'] = _perPage.toString();

    return uri.replace(queryParameters: params).toString();
  }

  void _reload({int? page}) {
    final nextPage = page ?? _page;
    setState(() {
      _page = nextPage;
      _future = _loadB2bRemote(widget.api, _effectiveEndpoint(page: nextPage));
    });
  }

  Future<void> _pickDate({required bool from}) async {
    final initial = from ? (_from ?? DateTime.now()) : (_to ?? DateTime.now());
    final picked = await showDatePicker(
      context: context,
      initialDate: initial,
      firstDate: DateTime(2020),
      lastDate: DateTime.now().add(const Duration(days: 366)),
    );
    if (picked == null) return;

    _period = _TopProductsPeriod.custom;
    if (from) {
      _from = picked;
      if (_to != null && picked.isAfter(_to!)) _to = picked;
    } else {
      _to = picked;
      if (_from != null && picked.isBefore(_from!)) _from = picked;
    }
    _reload(page: 1);
  }

  void _applyPeriod(_TopProductsPeriod period) {
    final now = DateTime.now();
    DateTime? from;
    DateTime? to;

    switch (period) {
      case _TopProductsPeriod.all:
        break;
      case _TopProductsPeriod.current:
        from = DateTime(now.year, now.month, 1);
        to = DateTime(now.year, now.month, now.day);
        break;
      case _TopProductsPeriod.previous:
        final firstCurrent = DateTime(now.year, now.month, 1);
        to = firstCurrent.subtract(const Duration(days: 1));
        from = DateTime(to.year, to.month, 1);
        break;
      case _TopProductsPeriod.custom:
        from = _from;
        to = _to;
        break;
    }

    _period = period;
    _from = from;
    _to = to;
    _reload(page: 1);
  }

  String _availabilityText(BuildContext context, Map row) {
    final reason = row['unavailable_reason']?.toString();
    if (reason == 'OUT_OF_STOCK') {
      return context.tr('b2b.top_products.out_of_stock');
    }
    if (reason == 'BELOW_MINIMUM_ORDER') {
      return context.tr('b2b.top_products.below_moq');
    }
    if (reason == 'UNAVAILABLE_FOR_ACCOUNT') {
      return context.tr('b2b.top_products.unavailable_account');
    }
    return context.tr('b2b.top_products.available');
  }

  @override
  Widget build(BuildContext context) => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Card(
            key: const ValueKey('b2b-top-products-filters'),
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: Column(
                children: [
                  TextField(
                    key: const ValueKey('b2b-top-products-search'),
                    controller: _searchController,
                    textInputAction: TextInputAction.search,
                    decoration: InputDecoration(
                      hintText: context.tr('b2b.top_products.search'),
                      prefixIcon: const Icon(Icons.search),
                    ),
                    onSubmitted: (_) => _reload(page: 1),
                  ),
                  const SizedBox(height: 10),
                  DropdownButtonFormField<String>(
                    key: const ValueKey('b2b-top-products-sort'),
                    initialValue: _sort,
                    decoration: InputDecoration(
                      labelText: context.tr('b2b.top_products.sort'),
                    ),
                    items: [
                      DropdownMenuItem(
                        value: 'quantity',
                        child: Text(
                          context.tr('b2b.top_products.sort.quantity'),
                        ),
                      ),
                      DropdownMenuItem(
                        value: 'value',
                        child: Text(
                          context.tr('b2b.top_products.sort.value'),
                        ),
                      ),
                    ],
                    onChanged: (value) {
                      if (value == null || value == _sort) return;
                      _sort = value;
                      _reload(page: 1);
                    },
                  ),
                  const SizedBox(height: 12),
                  Align(
                    alignment: AlignmentDirectional.centerStart,
                    child: Text(
                      context.tr('b2b.top_products.period'),
                      style: Theme.of(context).textTheme.titleSmall?.copyWith(
                            fontWeight: FontWeight.w800,
                          ),
                    ),
                  ),
                  const SizedBox(height: 8),
                  Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    children: [
                      ChoiceChip(
                        key: const ValueKey('b2b-top-products-current-period'),
                        label: Text(
                          context.tr('b2b.top_products.current_period'),
                        ),
                        selected: _period == _TopProductsPeriod.current,
                        onSelected: (_) =>
                            _applyPeriod(_TopProductsPeriod.current),
                      ),
                      ChoiceChip(
                        key: const ValueKey('b2b-top-products-previous-period'),
                        label: Text(
                          context.tr('b2b.top_products.previous_period'),
                        ),
                        selected: _period == _TopProductsPeriod.previous,
                        onSelected: (_) =>
                            _applyPeriod(_TopProductsPeriod.previous),
                      ),
                      ChoiceChip(
                        key: const ValueKey('b2b-top-products-all-time'),
                        label: Text(
                          context.tr('b2b.top_products.all_time'),
                        ),
                        selected: _period == _TopProductsPeriod.all,
                        onSelected: (_) => _applyPeriod(_TopProductsPeriod.all),
                      ),
                    ],
                  ),
                  const SizedBox(height: 10),
                  Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    children: [
                      OutlinedButton.icon(
                        key: const ValueKey('b2b-top-products-from'),
                        onPressed: () => _pickDate(from: true),
                        icon: const Icon(Icons.calendar_today_outlined),
                        label: Text(
                          '${context.tr('b2b.top_products.from')}: ${_from == null ? '—' : _isoDate(_from!)}',
                        ),
                      ),
                      OutlinedButton.icon(
                        key: const ValueKey('b2b-top-products-to'),
                        onPressed: () => _pickDate(from: false),
                        icon: const Icon(Icons.event_outlined),
                        label: Text(
                          '${context.tr('b2b.top_products.to')}: ${_to == null ? '—' : _isoDate(_to!)}',
                        ),
                      ),
                      FilledButton.icon(
                        key: const ValueKey('b2b-top-products-apply'),
                        onPressed: () => _reload(page: 1),
                        icon: const Icon(Icons.tune),
                        label: Text(
                          context.tr('b2b.top_products.apply'),
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),
          ),
          const SizedBox(height: 12),
          FutureBuilder<Object?>(
            future: _future,
            builder: (context, snapshot) {
              if (snapshot.connectionState != ConnectionState.done) {
                return const Center(
                  key: ValueKey('b2b-loading'),
                  child: CircularProgressIndicator(),
                );
              }
              if (snapshot.hasError) {
                return _B2bRemoteErrorCard(
                  error: snapshot.error,
                  onRetry: () => _reload(),
                );
              }

              final value = snapshot.data;
              final rows = value is Map && value['data'] is List
                  ? (value['data'] as List)
                      .whereType<Map>()
                      .toList(growable: false)
                  : const <Map>[];
              final meta = value is Map && value['meta'] is Map
                  ? Map<Object?, Object?>.from(value['meta'] as Map)
                  : <Object?, Object?>{};
              final currentPage =
                  int.tryParse(meta['page']?.toString() ?? '') ?? _page;
              final hasMore = meta['has_more'] == true;

              if (rows.isEmpty) {
                return Card(
                  key: const ValueKey('b2b-empty'),
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Text(context.tr('b2b.empty.purchases')),
                  ),
                );
              }

              return Column(
                key: const ValueKey('b2b-top-products-data'),
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  ...rows.map((row) {
                    final rank = row['rank']?.toString() ?? '-';
                    final productId =
                        int.tryParse(row['product_id']?.toString() ?? '');
                    final storeId =
                        int.tryParse(row['store_id']?.toString() ?? '');
                    final name = row['name']?.toString() ?? '';
                    final sku = row['sku']?.toString() ?? '';
                    final quantity = row['quantity']?.toString() ?? '0';
                    final total = row['total']?.toString() ?? '0';
                    final currency = row['currency']?.toString() ?? '';
                    final lastPurchase =
                        row['last_purchased_at']?.toString() ?? '—';
                    final pack = row['pack_label']?.toString();
                    final currentPrice = row['account_price']?.toString();
                    final currentCurrency =
                        row['current_price_currency']?.toString() ?? '';
                    final imageUrl = row['image_url']?.toString();
                    final canOpen = productId != null &&
                        productId > 0 &&
                        storeId != null &&
                        storeId > 0;
                    final canRepurchase = row['can_repurchase'] == true;
                    final availability = _availabilityText(context, row);
                    final statusColor = canRepurchase
                        ? const Color(0xFF087347)
                        : Theme.of(context).colorScheme.error;

                    void openProduct() {
                      if (!canOpen) return;
                      Navigator.of(context).pushNamed(
                        Uri(
                          path: '/b2b/products/$productId',
                          queryParameters: <String, String>{
                            'channel': 'wholesale',
                            'store_id': storeId.toString(),
                          },
                        ).toString(),
                      );
                    }

                    return Card(
                      key: ValueKey('b2b-top-product-$rank'),
                      child: InkWell(
                        onTap: canOpen ? openProduct : null,
                        child: Padding(
                          padding: const EdgeInsets.all(12),
                          child: Row(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              SizedBox(
                                width: 72,
                                height: 72,
                                child: imageUrl == null || imageUrl.isEmpty
                                    ? const DecoratedBox(
                                        decoration: BoxDecoration(
                                          color: Color(0xFFF2F4F7),
                                          borderRadius: BorderRadius.all(
                                            Radius.circular(14),
                                          ),
                                        ),
                                        child: Icon(
                                          Icons.shopping_basket_outlined,
                                          color: Color(0xFF087347),
                                        ),
                                      )
                                    : _B2bCatalogImage(url: imageUrl),
                              ),
                              const SizedBox(width: 12),
                              Expanded(
                                child: Column(
                                  crossAxisAlignment:
                                      CrossAxisAlignment.stretch,
                                  children: [
                                    Row(
                                      crossAxisAlignment:
                                          CrossAxisAlignment.start,
                                      children: [
                                        CircleAvatar(
                                          key: ValueKey(
                                            'b2b-top-product-rank-$rank',
                                          ),
                                          radius: 17,
                                          backgroundColor: Theme.of(context)
                                              .colorScheme
                                              .primaryContainer,
                                          child: Text(
                                            '#$rank',
                                            style: const TextStyle(
                                              fontWeight: FontWeight.w800,
                                            ),
                                          ),
                                        ),
                                        const SizedBox(width: 8),
                                        Expanded(
                                          child: Text(
                                            name,
                                            style: Theme.of(context)
                                                .textTheme
                                                .titleMedium
                                                ?.copyWith(
                                                  fontWeight: FontWeight.w800,
                                                ),
                                          ),
                                        ),
                                      ],
                                    ),
                                    if (sku.isNotEmpty)
                                      Padding(
                                        padding: const EdgeInsets.only(top: 2),
                                        child: Text(
                                          sku,
                                          style: Theme.of(context)
                                              .textTheme
                                              .bodySmall,
                                        ),
                                      ),
                                    const SizedBox(height: 6),
                                    Text(
                                      '${context.tr('b2b.top_products.quantity')}: $quantity',
                                    ),
                                    Text(
                                      '${context.tr('b2b.top_products.spend')}: $total $currency',
                                    ),
                                    Text(
                                      '${context.tr('b2b.top_products.last_purchase')}: $lastPurchase',
                                    ),
                                    if (pack != null && pack.isNotEmpty)
                                      Text(
                                        '${context.tr('b2b.top_products.pack')}: $pack',
                                      ),
                                    if (currentPrice != null)
                                      Text(
                                        '${context.tr('b2b.top_products.current_price')}: $currentPrice $currentCurrency',
                                      ),
                                    const SizedBox(height: 6),
                                    DecoratedBox(
                                      decoration: BoxDecoration(
                                        color: statusColor.withValues(
                                          alpha: 0.10,
                                        ),
                                        borderRadius: BorderRadius.circular(20),
                                      ),
                                      child: Padding(
                                        padding: const EdgeInsets.symmetric(
                                          horizontal: 10,
                                          vertical: 7,
                                        ),
                                        child: Row(
                                          key: ValueKey(
                                            'b2b-top-product-availability-$rank',
                                          ),
                                          mainAxisSize: MainAxisSize.min,
                                          children: [
                                            Icon(
                                              canRepurchase
                                                  ? Icons.check_circle_outline
                                                  : Icons.info_outline,
                                              size: 18,
                                              color: statusColor,
                                            ),
                                            const SizedBox(width: 6),
                                            Flexible(
                                              child: Text(
                                                availability,
                                                style: TextStyle(
                                                  fontWeight: FontWeight.w700,
                                                  color: statusColor,
                                                ),
                                              ),
                                            ),
                                          ],
                                        ),
                                      ),
                                    ),
                                    if (canOpen) ...[
                                      const SizedBox(height: 8),
                                      Align(
                                        alignment:
                                            AlignmentDirectional.centerStart,
                                        child: FilledButton.tonalIcon(
                                          key: ValueKey(
                                            'b2b-top-product-open-$rank',
                                          ),
                                          onPressed: openProduct,
                                          icon: const Icon(
                                            Icons.open_in_new_outlined,
                                          ),
                                          label: Text(
                                            context.tr(
                                              'b2b.top_products.open_product',
                                            ),
                                          ),
                                        ),
                                      ),
                                    ],
                                  ],
                                ),
                              ),
                            ],
                          ),
                        ),
                      ),
                    );
                  }),
                  const SizedBox(height: 8),
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      OutlinedButton.icon(
                        key: const ValueKey('b2b-top-products-previous'),
                        onPressed: currentPage > 1
                            ? () => _reload(page: currentPage - 1)
                            : null,
                        icon: const Icon(Icons.chevron_left),
                        label: Text(
                          context.tr('b2b.top_products.previous'),
                        ),
                      ),
                      Text(
                        '${context.tr('b2b.top_products.page')} $currentPage',
                        key: const ValueKey('b2b-top-products-page'),
                      ),
                      OutlinedButton.icon(
                        key: const ValueKey('b2b-top-products-next'),
                        onPressed: hasMore
                            ? () => _reload(page: currentPage + 1)
                            : null,
                        icon: const Icon(Icons.chevron_right),
                        label: Text(
                          context.tr('b2b.top_products.next'),
                        ),
                      ),
                    ],
                  ),
                ],
              );
            },
          ),
        ],
      );

  static String _isoDate(DateTime value) =>
      '${value.year.toString().padLeft(4, '0')}-'
      '${value.month.toString().padLeft(2, '0')}-'
      '${value.day.toString().padLeft(2, '0')}';
}


enum _PurchaseReportPeriod { all, current, previous, custom }

class _PurchaseReportRemoteState extends StatefulWidget {
  const _PurchaseReportRemoteState({
    required this.api,
    required this.endpoint,
  });

  final B2bApi api;
  final String endpoint;

  @override
  State<_PurchaseReportRemoteState> createState() =>
      _PurchaseReportRemoteStateState();
}

class _PurchaseReportRemoteStateState
    extends State<_PurchaseReportRemoteState> {
  late Future<Object?> _future;
  Map<Object?, Object?>? _lastData;
  _PurchaseReportPeriod _period = _PurchaseReportPeriod.all;
  DateTime? _from;
  DateTime? _to;
  int _page = 1;

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  @override
  void didUpdateWidget(covariant _PurchaseReportRemoteState oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.api != widget.api || oldWidget.endpoint != widget.endpoint) {
      _page = 1;
      _future = _load();
    }
  }

  Future<Object?> _load() async {
    final value = await _loadB2bRemote(widget.api, _requestEndpoint());
    if (value is Map) {
      _lastData = Map<Object?, Object?>.from(value);
    }
    return value;
  }

  String _requestEndpoint() {
    final uri = Uri.parse(widget.endpoint);
    final query = <String, String>{...uri.queryParameters};

    if (_from == null) {
      query.remove('from');
    } else {
      query['from'] = _isoDate(_from!);
    }
    if (_to == null) {
      query.remove('to');
    } else {
      query['to'] = _isoDate(_to!);
    }

    query['page'] = _page.toString();
    query['per_page'] = '5';

    return Uri(
      path: uri.path,
      queryParameters: query,
    ).toString();
  }

  void _reload({int? page}) {
    setState(() {
      if (page != null) _page = page;
      _future = _load();
    });
  }

  void _applyPeriod(_PurchaseReportPeriod period) {
    final now = _dateOnly(DateTime.now());
    DateTime? from;
    DateTime? to;

    switch (period) {
      case _PurchaseReportPeriod.all:
        break;
      case _PurchaseReportPeriod.current:
        from = DateTime(now.year, now.month, 1);
        to = now;
        break;
      case _PurchaseReportPeriod.previous:
        final firstCurrentMonth = DateTime(now.year, now.month, 1);
        to = firstCurrentMonth.subtract(const Duration(days: 1));
        from = DateTime(to.year, to.month, 1);
        break;
      case _PurchaseReportPeriod.custom:
        from = _from;
        to = _to;
        break;
    }

    setState(() {
      _period = period;
      _from = from;
      _to = to;
      _page = 1;
      _future = _load();
    });
  }

  Future<void> _pickDate({required bool from}) async {
    final now = _dateOnly(DateTime.now());
    final initial = from ? (_from ?? _to ?? now) : (_to ?? _from ?? now);
    final picked = await showDatePicker(
      context: context,
      initialDate: initial.isAfter(now) ? now : initial,
      firstDate: DateTime(now.year - 5, 1, 1),
      lastDate: now,
    );
    if (picked == null) return;

    setState(() {
      _period = _PurchaseReportPeriod.custom;
      if (from) {
        _from = picked;
        if (_to != null && picked.isAfter(_to!)) _to = picked;
      } else {
        _to = picked;
        if (_from != null && picked.isBefore(_from!)) _from = picked;
      }
      _page = 1;
      _future = _load();
    });
  }

  @override
  Widget build(BuildContext context) => FutureBuilder<Object?>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done &&
              _lastData == null) {
            return const Center(
              key: ValueKey('b2b-loading'),
              child: CircularProgressIndicator(),
            );
          }

          if (snapshot.hasError && _lastData == null) {
            return _B2bRemoteErrorCard(
              error: snapshot.error,
              onRetry: _reload,
            );
          }

          final fresh = snapshot.data is Map
              ? Map<Object?, Object?>.from(snapshot.data as Map)
              : null;
          final data = fresh ?? _lastData;
          if (data == null) {
            return Card(
              key: const ValueKey('b2b-empty'),
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Text(context.tr('b2b.purchase_reports.empty')),
              ),
            );
          }

          final summary = _reportMap(data['summary']);
          final comparison = _reportMap(data['comparison']);
          final trend = _reportRows(data['data']);
          final categories = _reportRows(data['categories']);
          final orders = _reportRows(data['orders']);
          final meta = _reportMap(data['meta']);
          final currency = data['currency']?.toString() ?? '';
          final totalPurchases = _reportDouble(summary['total_purchases']);
          final isEmpty = totalPurchases == 0 &&
              trend.isEmpty &&
              categories.isEmpty &&
              orders.isEmpty;
          final stale = snapshot.hasError && _lastData != null;
          final loading =
              snapshot.connectionState != ConnectionState.done && _lastData != null;
          final currentPage =
              int.tryParse(meta['page']?.toString() ?? '') ?? _page;
          final hasMore = meta['has_more'] == true;

          return Column(
            key: const ValueKey('b2b-purchases-report'),
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              _filters(context),
              if (loading) const LinearProgressIndicator(),
              if (stale) ...[
                const SizedBox(height: 10),
                Card(
                  key: const ValueKey('b2b-purchases-stale'),
                  child: ListTile(
                    leading: const Icon(Icons.cloud_off_outlined),
                    title: Text(context.tr('b2b.purchase_reports.stale')),
                    trailing: IconButton(
                      tooltip: context.tr('b2b.purchase_reports.refresh'),
                      onPressed: _reload,
                      icon: const Icon(Icons.refresh),
                    ),
                  ),
                ),
              ],
              const SizedBox(height: 10),
              _summary(context, summary, currency),
              if (comparison.isNotEmpty) ...[
                const SizedBox(height: 10),
                _comparison(context, comparison, currency),
              ],
              if (isEmpty) ...[
                const SizedBox(height: 10),
                Card(
                  key: const ValueKey('b2b-purchases-empty'),
                  child: Padding(
                    padding: const EdgeInsets.all(18),
                    child: Text(context.tr('b2b.purchase_reports.empty')),
                  ),
                ),
              ] else ...[
                const SizedBox(height: 10),
                _trend(context, trend, currency),
                const SizedBox(height: 10),
                _categories(context, categories, currency),
                if (orders.isNotEmpty) ...[
                  const SizedBox(height: 10),
                  _orders(
                    context,
                    orders,
                    currency,
                    currentPage: currentPage,
                    hasMore: hasMore,
                  ),
                ],
              ],
            ],
          );
        },
      );

  Widget _filters(BuildContext context) => Card(
        key: const ValueKey('b2b-purchases-filters'),
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(
                context.tr('b2b.purchase_reports.period'),
                style: Theme.of(context).textTheme.titleMedium?.copyWith(
                      fontWeight: FontWeight.w800,
                    ),
              ),
              const SizedBox(height: 10),
              Wrap(
                spacing: 8,
                runSpacing: 8,
                children: [
                  ChoiceChip(
                    key: const ValueKey('b2b-purchases-current-period'),
                    label:
                        Text(context.tr('b2b.purchase_reports.current_period')),
                    selected: _period == _PurchaseReportPeriod.current,
                    onSelected: (_) =>
                        _applyPeriod(_PurchaseReportPeriod.current),
                  ),
                  ChoiceChip(
                    key: const ValueKey('b2b-purchases-previous-period'),
                    label:
                        Text(context.tr('b2b.purchase_reports.previous_period')),
                    selected: _period == _PurchaseReportPeriod.previous,
                    onSelected: (_) =>
                        _applyPeriod(_PurchaseReportPeriod.previous),
                  ),
                  ChoiceChip(
                    key: const ValueKey('b2b-purchases-all-periods'),
                    label: Text(context.tr('b2b.purchase_reports.all_time')),
                    selected: _period == _PurchaseReportPeriod.all,
                    onSelected: (_) => _applyPeriod(_PurchaseReportPeriod.all),
                  ),
                ],
              ),
              const SizedBox(height: 10),
              Wrap(
                spacing: 8,
                runSpacing: 8,
                children: [
                  OutlinedButton.icon(
                    key: const ValueKey('b2b-purchases-from'),
                    onPressed: () => _pickDate(from: true),
                    icon: const Icon(Icons.calendar_today_outlined),
                    label: Text(
                      '${context.tr('b2b.purchase_reports.from')}: '
                      '${_from == null ? '—' : _isoDate(_from!)}',
                    ),
                  ),
                  OutlinedButton.icon(
                    key: const ValueKey('b2b-purchases-to'),
                    onPressed: () => _pickDate(from: false),
                    icon: const Icon(Icons.event_available_outlined),
                    label: Text(
                      '${context.tr('b2b.purchase_reports.to')}: '
                      '${_to == null ? '—' : _isoDate(_to!)}',
                    ),
                  ),
                  IconButton.filledTonal(
                    key: const ValueKey('b2b-purchases-refresh'),
                    tooltip: context.tr('b2b.purchase_reports.refresh'),
                    onPressed: _reload,
                    icon: const Icon(Icons.refresh),
                  ),
                ],
              ),
            ],
          ),
        ),
      );

  Widget _summary(
    BuildContext context,
    Map<Object?, Object?> summary,
    String currency,
  ) {
    final values = <({String keyName, String label, String value, IconData icon})>[
      (
        keyName: 'total',
        label: context.tr('b2b.purchase_reports.total_purchases'),
        value: _reportMoney(summary['total_purchases'], currency),
        icon: Icons.shopping_bag_outlined,
      ),
      (
        keyName: 'orders',
        label: context.tr('b2b.purchase_reports.order_count'),
        value: summary['order_count']?.toString() ?? '0',
        icon: Icons.inventory_2_outlined,
      ),
      (
        keyName: 'invoices',
        label: context.tr('b2b.purchase_reports.invoice_count'),
        value: summary['invoice_count']?.toString() ?? '0',
        icon: Icons.receipt_long_outlined,
      ),
      (
        keyName: 'average',
        label: context.tr('b2b.purchase_reports.average_order'),
        value: _reportMoney(summary['average_order_value'], currency),
        icon: Icons.query_stats_outlined,
      ),
    ];

    return Card(
      key: const ValueKey('b2b-purchases-summary'),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: LayoutBuilder(
          builder: (context, constraints) {
            final tileWidth = constraints.maxWidth >= 520
                ? (constraints.maxWidth - 24) / 4
                : (constraints.maxWidth - 8) / 2;
            return Wrap(
              spacing: 8,
              runSpacing: 8,
              children: values
                  .map(
                    (item) => SizedBox(
                      width: tileWidth,
                      child: DecoratedBox(
                        decoration: BoxDecoration(
                          color: Theme.of(context)
                              .colorScheme
                              .surfaceContainerHighest,
                          borderRadius: BorderRadius.circular(14),
                        ),
                        child: Padding(
                          padding: const EdgeInsets.all(12),
                          child: Column(
                            key: ValueKey(
                              'b2b-purchases-summary-${item.keyName}',
                            ),
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Icon(item.icon, size: 20),
                              const SizedBox(height: 8),
                              Text(
                                item.label,
                                style: Theme.of(context).textTheme.bodySmall,
                              ),
                              const SizedBox(height: 4),
                              Text(
                                item.value,
                                style: Theme.of(context)
                                    .textTheme
                                    .titleMedium
                                    ?.copyWith(fontWeight: FontWeight.w800),
                              ),
                            ],
                          ),
                        ),
                      ),
                    ),
                  )
                  .toList(),
            );
          },
        ),
      ),
    );
  }

  Widget _comparison(
    BuildContext context,
    Map<Object?, Object?> comparison,
    String currency,
  ) {
    final change = _reportDouble(comparison['change_amount']);
    final percent = comparison['change_percent'];
    final prefix = change > 0 ? '+' : '';
    final percentText = percent == null
        ? '—'
        : '${_reportDouble(percent).toStringAsFixed(1)}%';

    return Card(
      key: const ValueKey('b2b-purchases-comparison'),
      child: ListTile(
        leading: Icon(
          change >= 0 ? Icons.trending_up : Icons.trending_down,
        ),
        title: Text(context.tr('b2b.purchase_reports.comparison')),
        subtitle: Text(
          '${context.tr('b2b.purchase_reports.previous_period_total')}: '
          '${_reportMoney(comparison['total_purchases'], currency)}',
        ),
        trailing: Text(
          '$prefix${_reportMoney(change, currency)}\n$percentText',
          textAlign: TextAlign.end,
          style: const TextStyle(fontWeight: FontWeight.w800),
        ),
      ),
    );
  }

  Widget _trend(
    BuildContext context,
    List<Map<Object?, Object?>> rows,
    String currency,
  ) => Card(
        key: const ValueKey('b2b-purchases-trend'),
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(
                context.tr('b2b.purchase_reports.trend'),
                style: Theme.of(context).textTheme.titleMedium?.copyWith(
                      fontWeight: FontWeight.w800,
                    ),
              ),
              const SizedBox(height: 12),
              if (rows.isEmpty)
                Text(context.tr('b2b.purchase_reports.no_trend'))
              else
                _PurchaseTrendBars(rows: rows, currency: currency),
            ],
          ),
        ),
      );

  Widget _categories(
    BuildContext context,
    List<Map<Object?, Object?>> rows,
    String currency,
  ) => Card(
        key: const ValueKey('b2b-purchases-categories'),
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(
                context.tr('b2b.purchase_reports.categories'),
                style: Theme.of(context).textTheme.titleMedium?.copyWith(
                      fontWeight: FontWeight.w800,
                    ),
              ),
              const SizedBox(height: 12),
              if (rows.isEmpty)
                Text(context.tr('b2b.purchase_reports.no_categories'))
              else
                _PurchaseCategoryDistribution(
                  rows: rows,
                  currency: currency,
                ),
            ],
          ),
        ),
      );

  Widget _orders(
    BuildContext context,
    List<Map<Object?, Object?>> rows,
    String currency, {
    required int currentPage,
    required bool hasMore,
  }) =>
      Card(
        key: const ValueKey('b2b-purchases-orders'),
        child: Padding(
          padding: const EdgeInsets.all(10),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Padding(
                padding: const EdgeInsets.all(4),
                child: Text(
                  context.tr('b2b.purchase_reports.recent_orders'),
                  style: Theme.of(context).textTheme.titleMedium?.copyWith(
                        fontWeight: FontWeight.w800,
                      ),
                ),
              ),
              ...rows.map((row) {
                final id = int.tryParse(row['id']?.toString() ?? '');
                final storeId =
                    int.tryParse(row['store_id']?.toString() ?? '');
                final orderNumber = row['order_number']?.toString() ?? '—';
                final rowCurrency = row['currency']?.toString();
                final displayCurrency = rowCurrency == null ||
                        rowCurrency.trim().isEmpty
                    ? currency
                    : rowCurrency;
                return ListTile(
                  key: ValueKey('b2b-purchases-order-${id ?? orderNumber}'),
                  leading: const Icon(Icons.receipt_long_outlined),
                  title: Text(orderNumber),
                  subtitle: Text(
                    '${row['created_at'] ?? ''} · ${row['status'] ?? ''}',
                  ),
                  trailing: Text(
                    _reportMoney(row['grand_total'], displayCurrency),
                    style: const TextStyle(fontWeight: FontWeight.w700),
                  ),
                  onTap: id == null
                      ? null
                      : () {
                          final query = <String, String>{
                            'channel': 'wholesale',
                            if (storeId != null) 'store_id': '$storeId',
                          };
                          Navigator.of(context).pushNamed(
                            Uri(
                              path: '/b2b/orders/$id',
                              queryParameters: query,
                            ).toString(),
                          );
                        },
                );
              }),
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Text(
                    '${context.tr('b2b.purchase_reports.page')} $currentPage',
                  ),
                  Wrap(
                    spacing: 6,
                    children: [
                      TextButton.icon(
                        key: const ValueKey('b2b-purchases-previous-page'),
                        onPressed: currentPage > 1
                            ? () => _reload(page: currentPage - 1)
                            : null,
                        icon: const Icon(Icons.chevron_left),
                        label: Text(
                          context.tr('b2b.purchase_reports.previous'),
                        ),
                      ),
                      TextButton.icon(
                        key: const ValueKey('b2b-purchases-next-page'),
                        onPressed: hasMore
                            ? () => _reload(page: currentPage + 1)
                            : null,
                        icon: const Icon(Icons.chevron_right),
                        label: Text(
                          context.tr('b2b.purchase_reports.next'),
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ],
          ),
        ),
      );

  static DateTime _dateOnly(DateTime value) =>
      DateTime(value.year, value.month, value.day);

  static String _isoDate(DateTime value) =>
      '${value.year.toString().padLeft(4, '0')}-'
      '${value.month.toString().padLeft(2, '0')}-'
      '${value.day.toString().padLeft(2, '0')}';
}

class _PurchaseTrendBars extends StatelessWidget {
  const _PurchaseTrendBars({
    required this.rows,
    required this.currency,
  });

  final List<Map<Object?, Object?>> rows;
  final String currency;

  @override
  Widget build(BuildContext context) {
    final maxValue = rows.fold<double>(
      0,
      (current, row) =>
          math.max(current, _reportDouble(row['purchase_total'])),
    );

    return SizedBox(
      height: 190,
      child: SingleChildScrollView(
        scrollDirection: Axis.horizontal,
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.end,
          children: rows.map((row) {
            final value = _reportDouble(row['purchase_total']);
            final ratio = maxValue <= 0 ? 0.0 : value / maxValue;
            final period = row['period']?.toString() ?? '';
            return SizedBox(
              width: 54,
              child: Column(
                mainAxisAlignment: MainAxisAlignment.end,
                children: [
                  Text(
                    _reportCompactNumber(value),
                    style: Theme.of(context).textTheme.labelSmall,
                    overflow: TextOverflow.ellipsis,
                  ),
                  const SizedBox(height: 6),
                  SizedBox(
                    height: 105,
                    child: Align(
                      alignment: Alignment.bottomCenter,
                      child: Tooltip(
                        message: _reportMoney(value, currency),
                        child: Container(
                          key: ValueKey('b2b-purchases-bar-$period'),
                          width: 22,
                          height: math.max(8.0, 96 * ratio),
                          decoration: BoxDecoration(
                            color: Theme.of(context).colorScheme.primary,
                            borderRadius: const BorderRadius.vertical(
                              top: Radius.circular(8),
                            ),
                          ),
                        ),
                      ),
                    ),
                  ),
                  const SizedBox(height: 6),
                  Text(
                    _shortReportPeriod(period),
                    style: Theme.of(context).textTheme.labelSmall,
                    overflow: TextOverflow.ellipsis,
                  ),
                ],
              ),
            );
          }).toList(),
        ),
      ),
    );
  }
}

class _PurchaseCategoryDistribution extends StatelessWidget {
  const _PurchaseCategoryDistribution({
    required this.rows,
    required this.currency,
  });

  final List<Map<Object?, Object?>> rows;
  final String currency;

  @override
  Widget build(BuildContext context) {
    final colors = <Color>[
      Theme.of(context).colorScheme.primary,
      Theme.of(context).colorScheme.secondary,
      Theme.of(context).colorScheme.tertiary,
      Theme.of(context).colorScheme.error,
      Theme.of(context).colorScheme.primaryContainer,
      Theme.of(context).colorScheme.secondaryContainer,
    ];
    final values = rows
        .map((row) => _reportDouble(row['purchase_total']))
        .toList(growable: false);

    return Column(
      children: [
        SizedBox(
          key: const ValueKey('b2b-purchases-category-donut'),
          width: 138,
          height: 138,
          child: CustomPaint(
            painter: _PurchaseCategoryDonutPainter(
              values: values,
              colors: colors,
              trackColor: Theme.of(context).colorScheme.surfaceContainerHighest,
            ),
          ),
        ),
        const SizedBox(height: 14),
        ...rows.asMap().entries.map((entry) {
          final row = entry.value;
          final color = colors[entry.key % colors.length];
          final percentage = _reportDouble(row['percentage']);
          return Padding(
            padding: const EdgeInsets.symmetric(vertical: 5),
            child: Row(
              children: [
                Container(
                  width: 12,
                  height: 12,
                  decoration: BoxDecoration(
                    color: color,
                    shape: BoxShape.circle,
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    row['category_name']?.toString() ?? '—',
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
                Text(
                  '${percentage.toStringAsFixed(1)}% · '
                  '${_reportMoney(row['purchase_total'], currency)}',
                  style: Theme.of(context).textTheme.bodySmall?.copyWith(
                        fontWeight: FontWeight.w700,
                      ),
                ),
              ],
            ),
          );
        }),
      ],
    );
  }
}

class _PurchaseCategoryDonutPainter extends CustomPainter {
  const _PurchaseCategoryDonutPainter({
    required this.values,
    required this.colors,
    required this.trackColor,
  });

  final List<double> values;
  final List<Color> colors;
  final Color trackColor;

  @override
  void paint(Canvas canvas, Size size) {
    final center = size.center(Offset.zero);
    final radius = math.min(size.width, size.height) / 2 - 12;
    final rect = Rect.fromCircle(center: center, radius: radius);
    final total = values.fold<double>(0, (sum, value) => sum + value);

    final track = Paint()
      ..color = trackColor
      ..style = PaintingStyle.stroke
      ..strokeWidth = 18;
    canvas.drawCircle(center, radius, track);

    if (total <= 0) return;

    var start = -math.pi / 2;
    for (var index = 0; index < values.length; index++) {
      final value = values[index];
      if (value <= 0) continue;
      final sweep = (value / total) * math.pi * 2;
      final paint = Paint()
        ..color = colors[index % colors.length]
        ..style = PaintingStyle.stroke
        ..strokeWidth = 18
        ..strokeCap = StrokeCap.butt;
      canvas.drawArc(rect, start, sweep, false, paint);
      start += sweep;
    }
  }

  @override
  bool shouldRepaint(covariant _PurchaseCategoryDonutPainter oldDelegate) =>
      oldDelegate.values != values ||
      oldDelegate.colors != colors ||
      oldDelegate.trackColor != trackColor;
}

Map<Object?, Object?> _reportMap(Object? value) =>
    value is Map ? Map<Object?, Object?>.from(value) : <Object?, Object?>{};

List<Map<Object?, Object?>> _reportRows(Object? value) => value is List
    ? value
        .whereType<Map>()
        .map((row) => Map<Object?, Object?>.from(row))
        .toList(growable: false)
    : const <Map<Object?, Object?>>[];

double _reportDouble(Object? value) =>
    value is num ? value.toDouble() : double.tryParse('$value') ?? 0;

String _reportMoney(Object? value, String currency) {
  final amount = _reportDouble(value);
  final formatted = amount == amount.roundToDouble()
      ? amount.toStringAsFixed(0)
      : amount.toStringAsFixed(3).replaceFirst(RegExp(r'0+$'), '').replaceFirst(
            RegExp(r'\.$'),
            '',
          );
  return currency.trim().isEmpty ? formatted : '$formatted $currency';
}

String _reportCompactNumber(double value) {
  if (value.abs() >= 1000000) {
    return '${(value / 1000000).toStringAsFixed(1)}M';
  }
  if (value.abs() >= 1000) {
    return '${(value / 1000).toStringAsFixed(1)}K';
  }
  return value == value.roundToDouble()
      ? value.toStringAsFixed(0)
      : value.toStringAsFixed(1);
}

String _shortReportPeriod(String value) {
  final parsed = DateTime.tryParse(value);
  if (parsed == null) return value;
  return '${parsed.month.toString().padLeft(2, '0')}/'
      '${parsed.day.toString().padLeft(2, '0')}';
}

class _RemoteState extends StatefulWidget {
  const _RemoteState({
    required this.api,
    required this.endpoint,
    required this.routePattern,
    this.showEmpty = true,
  });

  final B2bApi api;
  final String endpoint;
  final String routePattern;
  final bool showEmpty;

  @override
  State<_RemoteState> createState() => _RemoteStateState();
}

class _RemoteStateState extends State<_RemoteState> {
  late Future<Object?> _future;

  @override
  void initState() {
    super.initState();
    _future = _loadB2bRemote(widget.api, widget.endpoint);
  }

  @override
  void didUpdateWidget(covariant _RemoteState oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.api != widget.api || oldWidget.endpoint != widget.endpoint) {
      _future = _loadB2bRemote(widget.api, widget.endpoint);
    }
  }

  void _retry() {
    setState(() {
      _future = _loadB2bRemote(widget.api, widget.endpoint);
    });
  }

  @override
  Widget build(BuildContext context) => FutureBuilder<Object?>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done) {
            return const Center(
              key: ValueKey('b2b-loading'),
              child: CircularProgressIndicator(),
            );
          }

          if (snapshot.hasError) {
            return _B2bRemoteErrorCard(
              error: snapshot.error,
              onRetry: _retry,
            );
          }

          final value = snapshot.data;
          final empty = value == null ||
              (value is List && value.isEmpty) ||
              (value is Map &&
                  value['data'] is List &&
                  (value['data'] as List).isEmpty &&
                  value.entries.every(
                    (entry) => entry.key == 'data' || entry.value == null,
                  ));

          if (empty) {
            return widget.showEmpty
                ? Card(
                    key: const ValueKey('b2b-empty'),
                    child: Padding(
                      padding: const EdgeInsets.all(16),
                      child: Text(context.tr('b2b.remote.empty')),
                    ),
                  )
                : const SizedBox.shrink();
          }

          return _AuthoritativeDataView(
            value: value,
            routePattern: widget.routePattern,
          );
        },
      );
}

class _B2bFailureInfo {
  const _B2bFailureInfo({
    required this.category,
    required this.messageKey,
    required this.forbiddenVisual,
    this.statusCode,
    this.supportReference,
  });

  final String category;
  final String messageKey;
  final bool forbiddenVisual;
  final int? statusCode;
  final String? supportReference;
}

_B2bFailureInfo _b2bFailureInfo(Object? error) {
  if (error is B2bApiException) {
    final status = error.statusCode;
    if (status == 401) {
      return _B2bFailureInfo(
        category: 'unauthorized',
        messageKey: 'customer.error.session_expired',
        forbiddenVisual: false,
        statusCode: status,
        supportReference: _safeSupportReference(error.supportReference),
      );
    }
    if (status == 403 || (status == null && error.code == 'not_authorized')) {
      return _B2bFailureInfo(
        category: 'forbidden',
        messageKey: 'customer.error.forbidden',
        forbiddenVisual: true,
        statusCode: status,
        supportReference: _safeSupportReference(error.supportReference),
      );
    }
    if (status == 404) {
      return _B2bFailureInfo(
        category: 'not_found',
        messageKey: 'b2b.remote.not_found',
        forbiddenVisual: false,
        statusCode: status,
        supportReference: _safeSupportReference(error.supportReference),
      );
    }
    if (status != null && status >= 500) {
      return _B2bFailureInfo(
        category: 'server_failure',
        messageKey: 'b2b.remote.error',
        forbiddenVisual: false,
        statusCode: status,
        supportReference: _safeSupportReference(error.supportReference),
      );
    }
  }

  return const _B2bFailureInfo(
    category: 'network_or_client_failure',
    messageKey: 'b2b.remote.error',
    forbiddenVisual: false,
  );
}

String? _safeSupportReference(String? value) {
  final reference = value?.trim();
  if (reference == null || reference.isEmpty || reference.length > 128) {
    return null;
  }
  return RegExp(r'^[A-Za-z0-9._:/-]+$').hasMatch(reference)
      ? reference
      : null;
}

Future<Object?> _loadB2bRemote(B2bApi api, String endpoint) async {
  try {
    return await api.get(endpoint);
  } catch (error, stack) {
    final failure = _b2bFailureInfo(error);
    CustomerDiagnostics.instance.recordRuntimeFailure(
      operation: 'b2b_remote_load',
      path: endpoint,
      category: failure.category,
      statusCode: failure.statusCode,
      supportReference: failure.supportReference,
    );
    Error.throwWithStackTrace(error, stack);
  }
}

String _productsBackRoute(String endpoint) {
  final uri = Uri.parse(endpoint);
  final storeId =
      uri.queryParameters['store_id'] ?? uri.queryParameters['store'];
  if (storeId == null || storeId.isEmpty) {
    return CustomerRoutePaths.b2bProducts;
  }
  return Uri(
    path: CustomerRoutePaths.b2bProducts,
    queryParameters: {'store_id': storeId},
  ).toString();
}

class _B2bRemoteErrorCard extends StatelessWidget {
  const _B2bRemoteErrorCard({
    required this.error,
    required this.onRetry,
    this.backRoute,
  });

  final Object? error;
  final VoidCallback onRetry;
  final String? backRoute;

  @override
  Widget build(BuildContext context) {
    final failure = _b2bFailureInfo(error);
    return Card(
      key: ValueKey(failure.forbiddenVisual ? 'b2b-forbidden' : 'b2b-error'),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(context.tr(failure.messageKey)),
            if (failure.supportReference != null) ...[
              const SizedBox(height: 8),
              Text(
                '${context.tr('b2b.remote.support_reference')}: ${failure.supportReference}',
                key: const ValueKey('b2b-error-support-reference'),
                style: Theme.of(context).textTheme.labelMedium,
              ),
            ],
            const SizedBox(height: 12),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                FilledButton.tonalIcon(
                  key: const ValueKey('b2b-error-retry'),
                  onPressed: onRetry,
                  icon: const Icon(Icons.refresh),
                  label: Text(context.tr('customer.action.retry')),
                ),
                if (backRoute != null)
                  OutlinedButton.icon(
                    key: const ValueKey('b2b-error-back-products'),
                    onPressed: () =>
                        Navigator.of(context).pushReplacementNamed(backRoute!),
                    icon: const Icon(Icons.arrow_back),
                    label: Text(context.tr('b2b.remote.back_products')),
                  ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _AuthoritativeDataView extends StatelessWidget {
  const _AuthoritativeDataView({
    required this.value,
    required this.routePattern,
  });

  final Object? value;
  final String routePattern;

  @override
  Widget build(BuildContext context) {
    final rows = _rows(value);
    if (rows != null) {
      final summary = value is Map
          ? Map<Object?, Object?>.fromEntries(
              (value as Map).entries.where((entry) => entry.key != 'data'),
            )
          : <Object?, Object?>{};
      return Column(
        key: const ValueKey('b2b-authoritative-collection'),
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (summary.isNotEmpty) _mapCard(context, summary),
          ...rows.map((row) => _rowCard(context, row)),
          if (rows.isEmpty)
            Card(
              key: const ValueKey('b2b-empty'),
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Text(context.tr('b2b.remote.empty')),
              ),
            ),
        ],
      );
    }

    if (value is Map) {
      return _mapCard(
        context,
        Map<Object?, Object?>.from(value as Map),
        key: const ValueKey('b2b-authoritative-detail'),
      );
    }

    return Card(
      key: const ValueKey('b2b-authoritative-detail'),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Text(value.toString()),
      ),
    );
  }

  List<Map<Object?, Object?>>? _rows(Object? source) {
    if (source is List) {
      return source
          .whereType<Map>()
          .map((row) => Map<Object?, Object?>.from(row))
          .toList(growable: false);
    }
    if (source is Map && source['data'] is List) {
      return (source['data'] as List)
          .whereType<Map>()
          .map((row) => Map<Object?, Object?>.from(row))
          .toList(growable: false);
    }
    return null;
  }

  Widget _rowCard(BuildContext context, Map<Object?, Object?> row) {
    final route = _routeForRow(row);
    final title = _firstValue(
      row,
      const [
        'name',
        'product_name',
        'order_number',
        'invoice_number',
        'company_name',
        'sku',
        'reference',
        'id',
      ],
    );

    return Card(
      child: InkWell(
        onTap: route == null
            ? null
            : () => Navigator.of(context).pushNamed(route),
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              if (row['image_url'] != null && row['image_url'].toString().isNotEmpty)
                Padding(
                  padding: const EdgeInsets.only(bottom: 10),
                  child: SizedBox(
                    key: ValueKey('b2b-product-image-${row['id']}'),
                    height: 140,
                    child: _B2bCatalogImage(url: row['image_url'].toString()),
                  ),
                ),
              if (title != null && title.isNotEmpty)
                Text(
                  title,
                  style: Theme.of(context).textTheme.titleMedium,
                ),
              if (title != null && title.isNotEmpty)
                const SizedBox(height: 8),
              ..._entries(context, row),
              if (route != null)
                const Align(
                  alignment: AlignmentDirectional.centerEnd,
                  child: Icon(Icons.chevron_right),
                ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _mapCard(
    BuildContext context,
    Map<Object?, Object?> map, {
    Key? key,
  }) =>
      Card(
        key: key,
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: _entries(context, map),
          ),
        ),
      );

  List<Widget> _entries(
    BuildContext context,
    Map<Object?, Object?> map,
  ) {
    final widgets = <Widget>[];
    for (final entry in map.entries) {
      final key = entry.key.toString();
      final value = entry.value;
      if (value == null || key == 'image_url' || key == 'images') continue;

      if (value is Map) {
        widgets.add(
          Padding(
            padding: const EdgeInsets.only(top: 10, bottom: 4),
            child: Text(
              _label(context, key),
              style: Theme.of(context).textTheme.titleSmall,
            ),
          ),
        );
        widgets.addAll(
          _entries(context, Map<Object?, Object?>.from(value)),
        );
        continue;
      }

      if (value is List) {
        widgets.add(
          Padding(
            padding: const EdgeInsets.only(top: 10, bottom: 4),
            child: Text(
              _label(context, key),
              style: Theme.of(context).textTheme.titleSmall,
            ),
          ),
        );
        if (value.isEmpty) {
          widgets.add(Text(context.tr('b2b.remote.empty')));
        } else {
          for (final item in value) {
            if (item is Map) {
              widgets.add(
                _mapCard(
                  context,
                  Map<Object?, Object?>.from(item),
                ),
              );
            } else {
              widgets.add(Text(item.toString()));
            }
          }
        }
        continue;
      }

      widgets.add(
        Padding(
          padding: const EdgeInsets.only(bottom: 6),
          child: Text('${_label(context, key)}: ${_formatValue(value)}'),
        ),
      );
    }
    return widgets;
  }

  String _label(BuildContext context, String key) {
    final arabic = Directionality.of(context) == TextDirection.rtl;
    const en = <String, String>{
      'id': 'ID',
      'sku': 'SKU',
      'name': 'Name',
      'product_name': 'Product',
      'order_number': 'Order',
      'invoice_number': 'Invoice',
      'status': 'Status',
      'payment_status': 'Payment status',
      'currency': 'Currency',
      'total': 'Total',
      'grand_total': 'Grand total',
      'subtotal': 'Subtotal',
      'balance': 'Balance',
      'quantity': 'Quantity',
      'unit_price': 'Unit price',
      'account_price': 'Account price',
      'minimum_quantity': 'Minimum quantity',
      'minimum_order_quantity': 'Minimum order quantity',
      'available_quantity': 'Available quantity',
      'company_name': 'Company',
      'email': 'Email',
      'phone': 'Phone',
      'tax_number': 'Tax number',
      'created_at': 'Created',
      'updated_at': 'Updated',
      'period': 'Period',
      'items': 'Items',
      'data': 'Data',
    };
    const ar = <String, String>{
      'id': 'المعرّف',
      'sku': 'SKU',
      'name': 'الاسم',
      'product_name': 'المنتج',
      'order_number': 'الطلب',
      'invoice_number': 'الفاتورة',
      'status': 'الحالة',
      'payment_status': 'حالة السداد',
      'currency': 'العملة',
      'total': 'الإجمالي',
      'grand_total': 'الإجمالي النهائي',
      'subtotal': 'الإجمالي الفرعي',
      'balance': 'الرصيد',
      'quantity': 'الكمية',
      'unit_price': 'سعر الوحدة',
      'account_price': 'سعر الحساب',
      'minimum_quantity': 'الحد الأدنى للكمية',
      'minimum_order_quantity': 'الحد الأدنى للطلب',
      'available_quantity': 'الكمية المتاحة',
      'company_name': 'الشركة',
      'email': 'البريد الإلكتروني',
      'phone': 'الهاتف',
      'tax_number': 'الرقم الضريبي',
      'created_at': 'تاريخ الإنشاء',
      'updated_at': 'آخر تحديث',
      'period': 'الفترة',
      'items': 'البنود',
      'data': 'البيانات',
    };
    final labels = arabic ? ar : en;
    return labels[key] ??
        key
            .split('_')
            .map((part) => part.isEmpty
                ? part
                : '${part[0].toUpperCase()}${part.substring(1)}')
            .join(' ');
  }

  String _formatValue(Object? value) {
    if (value is bool) return value ? 'Yes' : 'No';
    return value.toString();
  }

  String? _firstValue(
    Map<Object?, Object?> row,
    List<String> keys,
  ) {
    for (final key in keys) {
      final value = row[key];
      if (value != null && value.toString().isNotEmpty) {
        return value.toString();
      }
    }
    return null;
  }

  String? _routeForRow(Map<Object?, Object?> row) {
    final id = row['id']?.toString();
    if (id == null || id.isEmpty) return null;

    switch (routePattern) {
      case CustomerRoutePaths.b2bInvoices:
        return '/b2b/invoices/$id';
      case CustomerRoutePaths.b2bOrders:
        return '/b2b/orders/$id';
      case CustomerRoutePaths.b2bProducts:
        final storeId = row['store_id']?.toString();
        if (storeId == null || storeId.isEmpty) return null;
        return '/b2b/products/$id?store_id=$storeId';
      default:
        return null;
    }
  }
}

class _B2bAccountHub extends StatefulWidget {
  const _B2bAccountHub({
    required this.api,
    required this.financeEndpoint,
    required this.purchasesRoute,
    required this.invoicesRoute,
    required this.statementRoute,
    required this.ordersRoute,
    required this.notificationsRoute,
    required this.addressesRoute,
    this.onLocaleChanged,
    this.onLogout,
  });

  final B2bApi api;
  final String financeEndpoint;
  final String purchasesRoute;
  final String invoicesRoute;
  final String statementRoute;
  final String ordersRoute;
  final String notificationsRoute;
  final String addressesRoute;
  final ValueChanged<Locale>? onLocaleChanged;
  final Future<void> Function()? onLogout;

  @override
  State<_B2bAccountHub> createState() => _B2bAccountHubState();
}

class _B2bAccountHubState extends State<_B2bAccountHub> {
  late Future<Object?> _finance = widget.api.get(widget.financeEndpoint);

  @override
  void didUpdateWidget(covariant _B2bAccountHub oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.api != widget.api ||
        oldWidget.financeEndpoint != widget.financeEndpoint) {
      _finance = widget.api.get(widget.financeEndpoint);
    }
  }

  void _retryFinance() {
    setState(() => _finance = widget.api.get(widget.financeEndpoint));
  }

  void _open(String route) {
    Navigator.of(context).pushNamed(route);
  }

  @override
  Widget build(BuildContext context) {
    final isArabic = Localizations.localeOf(context).languageCode == 'ar';
    final shortcuts = <({IconData icon, String label, String route})>[
      (
        icon: Icons.shopping_bag_outlined,
        label: isArabic ? 'طلباتي' : 'My orders',
        route: widget.ordersRoute,
      ),
      (
        icon: Icons.receipt_long_outlined,
        label: isArabic ? 'الفواتير' : 'Invoices',
        route: widget.invoicesRoute,
      ),
      (
        icon: Icons.account_balance_wallet_outlined,
        label: isArabic ? 'كشف الحساب' : 'Account statement',
        route: widget.statementRoute,
      ),
      (
        icon: Icons.insights_outlined,
        label: isArabic ? 'تقرير المشتريات' : 'Purchases report',
        route: widget.purchasesRoute,
      ),
      (
        icon: Icons.notifications_none_rounded,
        label: isArabic ? 'الإشعارات' : 'Notifications',
        route: widget.notificationsRoute,
      ),
      (
        icon: Icons.location_on_outlined,
        label: isArabic ? 'العناوين' : 'Addresses',
        route: widget.addressesRoute,
      ),
    ];

    return Column(
      key: const ValueKey('b2b-account-hub'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Card(
          key: const ValueKey('b2b-finance-summary'),
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: FutureBuilder<Object?>(
              future: _finance,
              builder: (context, snapshot) {
                if (snapshot.connectionState != ConnectionState.done) {
                  return const Center(
                    child: Padding(
                      padding: EdgeInsets.all(12),
                      child: CircularProgressIndicator(),
                    ),
                  );
                }
                if (snapshot.hasError) {
                  return Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Text(
                        isArabic
                            ? 'تعذر تحميل الملخص المالي.'
                            : 'Financial summary could not be loaded.',
                      ),
                      const SizedBox(height: 8),
                      OutlinedButton.icon(
                        onPressed: _retryFinance,
                        icon: const Icon(Icons.refresh),
                        label: Text(isArabic ? 'إعادة المحاولة' : 'Retry'),
                      ),
                    ],
                  );
                }

                final envelope = snapshot.data is Map
                    ? Map<Object?, Object?>.from(snapshot.data as Map)
                    : <Object?, Object?>{};
                final data = envelope['data'] is Map
                    ? Map<Object?, Object?>.from(envelope['data'] as Map)
                    : envelope;
                final balance = _number(data['balance']);
                final currency = data['currency']?.toString() ?? '';
                final direction = data['balance_direction']?.toString() ?? '';
                final directionLabel = switch (direction) {
                  'customer_owes_company' => isArabic ? 'عليك' : 'You owe',
                  'company_owes_customer' => isArabic ? 'لك' : 'Credit due to you',
                  _ => isArabic ? 'متوازن' : 'Settled',
                };

                return Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Text(
                      isArabic ? 'ملخص الحساب المالي' : 'Financial account summary',
                      style: Theme.of(context).textTheme.titleMedium?.copyWith(
                            fontWeight: FontWeight.w800,
                          ),
                    ),
                    const SizedBox(height: 12),
                    _FinanceValue(
                      label: directionLabel,
                      value: _money(balance.abs(), currency),
                      emphasis: true,
                    ),
                    _FinanceValue(
                      label: isArabic ? 'حد الائتمان' : 'Credit limit',
                      value: _money(_number(data['credit_limit']), currency),
                    ),
                    _FinanceValue(
                      label: isArabic ? 'الائتمان المتاح' : 'Available credit',
                      value: _money(
                        _number(data['available_credit_line']),
                        currency,
                      ),
                    ),
                    _FinanceValue(
                      label: isArabic ? 'القوة الشرائية' : 'Purchasing power',
                      value: _money(
                        _number(data['purchasing_power']),
                        currency,
                      ),
                    ),
                    const SizedBox(height: 8),
                    TextButton.icon(
                      key: const ValueKey('b2b-profile-open-statement'),
                      onPressed: () => _open(widget.statementRoute),
                      icon: const Icon(Icons.open_in_new_rounded),
                      label: Text(
                        isArabic
                            ? 'فتح كشف الحساب والتفاصيل'
                            : 'Open statement and details',
                      ),
                    ),
                  ],
                );
              },
            ),
          ),
        ),
        const SizedBox(height: 14),
        Card(
          child: Column(
            children: [
              ListTile(
                title: Text(
                  isArabic ? 'اختصارات الحساب' : 'Account shortcuts',
                  style: const TextStyle(fontWeight: FontWeight.w800),
                ),
                subtitle: Text(
                  isArabic
                      ? 'كل وظائف رحلة العميل من مكان واحد'
                      : 'All customer-journey account functions in one place',
                ),
              ),
              ...shortcuts.map(
                (item) => ListTile(
                  leading: Icon(item.icon),
                  title: Text(item.label),
                  trailing: const Icon(Icons.chevron_right_rounded),
                  onTap: () => _open(item.route),
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: 14),
        Card(
          key: const ValueKey('b2b-profile-settings-security'),
          child: Column(
            children: [
              ListTile(
                leading: const Icon(Icons.settings_outlined),
                title: Text(isArabic ? 'الإعدادات' : 'Settings'),
                subtitle: Text(
                  isArabic
                      ? 'اللغة والإشعارات والمساعدة والأمان'
                      : 'Language, notifications, help and security',
                ),
              ),
              if (widget.onLocaleChanged != null)
                Padding(
                  padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
                  child: SegmentedButton<String>(
                    segments: const [
                      ButtonSegment(value: 'ar', label: Text('العربية')),
                      ButtonSegment(value: 'en', label: Text('English')),
                    ],
                    selected: {isArabic ? 'ar' : 'en'},
                    onSelectionChanged: (value) {
                      widget.onLocaleChanged!(Locale(value.first));
                    },
                  ),
                ),
              ListTile(
                leading: const Icon(Icons.notifications_active_outlined),
                title: Text(
                  isArabic
                      ? 'الإشعارات وتفضيلات التنبيه'
                      : 'Notifications & alert preferences',
                ),
                subtitle: Text(
                  isArabic
                      ? 'عرض مركز الإشعارات الحالي'
                      : 'Open the current notification center',
                ),
                trailing: const Icon(Icons.chevron_right_rounded),
                onTap: () => _open(widget.notificationsRoute),
              ),
              ListTile(
                leading: const Icon(Icons.support_agent_outlined),
                title: Text(isArabic ? 'المساعدة والدعم' : 'Help & support'),
                subtitle: Text(
                  isArabic
                      ? 'تشخيص التطبيق ومعلومات يمكن مشاركتها مع الدعم'
                      : 'App diagnostics and support-safe information',
                ),
                trailing: const Icon(Icons.chevron_right_rounded),
                onTap: () => _open(CustomerRoutePaths.diagnostics),
              ),
              if (widget.onLogout != null)
                ListTile(
                  key: const ValueKey('b2b-profile-security-action'),
                  leading: const Icon(Icons.security_outlined),
                  title: Text(
                    isArabic ? 'الأمان وتسجيل الدخول' : 'Security & sign-in',
                  ),
                  subtitle: Text(
                    isArabic
                        ? 'تسجيل خروج آمن ومسح الجلسة المحمية ثم تسجيل الدخول من جديد'
                        : 'Securely sign out, clear the protected session, then sign in again',
                  ),
                  trailing: const Icon(Icons.logout_rounded),
                  onTap: widget.onLogout,
                ),
            ],
          ),
        ),
        if (widget.onLogout != null) ...[
          const SizedBox(height: 14),
          OutlinedButton.icon(
            key: const ValueKey('b2b-profile-logout'),
            onPressed: widget.onLogout,
            icon: const Icon(Icons.logout_rounded),
            label: Text(isArabic ? 'تسجيل الخروج' : 'Sign out'),
          ),
        ],
      ],
    );
  }

  double _number(Object? value) =>
      value is num ? value.toDouble() : double.tryParse('$value') ?? 0;

  String _money(double value, String currency) {
    final suffix = currency.isEmpty ? '' : ' $currency';
    return '${value.toStringAsFixed(3)}$suffix';
  }
}

class _B2bAccountStatementRemoteState extends StatefulWidget {
  const _B2bAccountStatementRemoteState({
    required this.api,
    required this.endpoint,
  });

  final B2bApi api;
  final String endpoint;

  @override
  State<_B2bAccountStatementRemoteState> createState() =>
      _B2bAccountStatementRemoteStateState();
}

class _B2bAccountStatementRemoteStateState
    extends State<_B2bAccountStatementRemoteState> {
  static const _perPage = 30;

  Map<Object?, Object?>? _data;
  Object? _error;
  bool _loading = true;
  bool _stale = false;
  bool _exporting = false;
  int _page = 1;
  String? _from;
  String? _to;
  String? _storeId;

  @override
  void initState() {
    super.initState();
    _hydrateScope();
    _load();
  }

  @override
  void didUpdateWidget(covariant _B2bAccountStatementRemoteState oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.api != widget.api || oldWidget.endpoint != widget.endpoint) {
      _hydrateScope();
      _page = 1;
      _load();
    }
  }

  void _hydrateScope() {
    final uri = Uri.parse(widget.endpoint);
    _storeId = uri.queryParameters['store_id'] ?? uri.queryParameters['store'];
    _from = uri.queryParameters['from'];
    _to = uri.queryParameters['to'];
    _page = int.tryParse(uri.queryParameters['page'] ?? '') ?? 1;
  }

  String get _requestPath {
    final query = <String, String>{
      if (_storeId != null && _storeId!.isNotEmpty) 'store_id': _storeId!,
      if (_from != null && _from!.isNotEmpty) 'from': _from!,
      if (_to != null && _to!.isNotEmpty) 'to': _to!,
      'page': _page.toString(),
      'per_page': _perPage.toString(),
    };
    return Uri(
      path: '/api/v1/b2b/account-statement',
      queryParameters: query,
    ).toString();
  }

  Future<void> _load() async {
    if (mounted) {
      setState(() {
        _loading = _data == null;
        _error = null;
      });
    }
    try {
      final value = await _loadB2bRemote(widget.api, _requestPath);
      final envelope = value is Map ? Map<Object?, Object?>.from(value) : null;
      final raw = envelope?['data'];
      final statement =
          raw is Map ? Map<Object?, Object?>.from(raw) : envelope;
      if (!mounted) return;
      setState(() {
        _data = statement;
        _error = null;
        _loading = false;
        _stale = false;
      });
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _error = error;
        _loading = false;
        _stale = _data != null;
      });
    }
  }

  Future<void> _pickDate({required bool from}) async {
    final current = DateTime.tryParse((from ? _from : _to) ?? '');
    final selected = await showDatePicker(
      context: context,
      initialDate: current ?? DateTime.now(),
      firstDate: DateTime(2020),
      lastDate: DateTime.now().add(const Duration(days: 366)),
    );
    if (selected == null) return;
    setState(() {
      if (from) {
        _from = _isoDate(selected);
        if (_to != null && selected.isAfter(DateTime.parse(_to!))) {
          _to = _from;
        }
      } else {
        _to = _isoDate(selected);
        if (_from != null && selected.isBefore(DateTime.parse(_from!))) {
          _from = _to;
        }
      }
      _page = 1;
    });
    await _load();
  }

  Future<void> _preset(String preset) async {
    final now = DateTime.now();
    DateTime? from;
    DateTime? to;
    switch (preset) {
      case 'month':
        from = DateTime(now.year, now.month, 1);
        to = now;
        break;
      case 'previous':
        final firstCurrent = DateTime(now.year, now.month, 1);
        to = firstCurrent.subtract(const Duration(days: 1));
        from = DateTime(to.year, to.month, 1);
        break;
      case '30':
        from = now.subtract(const Duration(days: 29));
        to = now;
        break;
      case 'all':
        from = null;
        to = null;
        break;
    }
    setState(() {
      _from = from == null ? null : _isoDate(from);
      _to = to == null ? null : _isoDate(to);
      _page = 1;
    });
    await _load();
  }

  Future<void> _export(String format) async {
    if (_exporting) return;
    setState(() => _exporting = true);
    final locale =
        Localizations.localeOf(context).languageCode == 'ar' ? 'ar' : 'en';
    final query = <String, String>{
      if (_storeId != null && _storeId!.isNotEmpty) 'store_id': _storeId!,
      if (_from != null && _from!.isNotEmpty) 'from': _from!,
      if (_to != null && _to!.isNotEmpty) 'to': _to!,
      'format': format,
      'locale': locale,
    };
    final subject = context.tr('b2b.statement.title');
    try {
      if (widget.api is! B2bDownloadApi) {
        throw UnsupportedError('B2B statement export is unavailable.');
      }
      final download = await (widget.api as B2bDownloadApi).download(
        Uri(
          path: '/api/v1/b2b/account-statement/export',
          queryParameters: query,
        ).toString(),
      );
      await SharePlus.instance.share(
        ShareParams(
          files: [
            XFile.fromData(
              download.bytes,
              mimeType: download.mimeType,
              name: download.filename,
            ),
          ],
          subject: subject,
        ),
      );
    } catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(context.tr('b2b.statement.export_failed'))),
      );
    } finally {
      if (mounted) setState(() => _exporting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_loading && _data == null) {
      return const Center(
        key: ValueKey('b2b-loading'),
        child: CircularProgressIndicator(),
      );
    }
    if (_error != null && _data == null) {
      return _B2bRemoteErrorCard(error: _error, onRetry: _load);
    }

    final data = _data ?? <Object?, Object?>{};
    final currency = data['currency']?.toString() ?? '';
    final transactions = (data['transactions'] as List? ?? const <Object?>[])
        .whereType<Map>()
        .map((row) => Map<Object?, Object?>.from(row))
        .toList(growable: false);
    final pagination = data['pagination'] is Map
        ? Map<Object?, Object?>.from(data['pagination'] as Map)
        : <Object?, Object?>{};
    final currentPage = _int(pagination['current_page'], fallback: _page);
    final lastPage = _int(pagination['last_page'], fallback: currentPage);
    final closing = _amount(data['closing_balance']);
    final current = _amount(data['balance']);
    final direction = closing > 0
        ? context.tr('b2b.statement.you_owe')
        : closing < 0
            ? context.tr('b2b.statement.owed_to_you')
            : context.tr('b2b.statement.settled');

    return Column(
      key: const ValueKey('b2b-statement-data'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Card(
          key: const ValueKey('b2b-statement-filters'),
          child: Padding(
            padding: const EdgeInsets.all(14),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(
                  context.tr('b2b.statement.period'),
                  style: Theme.of(context).textTheme.titleMedium,
                ),
                const SizedBox(height: 10),
                Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  children: [
                    OutlinedButton.icon(
                      key: const ValueKey('b2b-statement-from'),
                      onPressed: () => _pickDate(from: true),
                      icon: const Icon(Icons.calendar_today_outlined),
                      label: Text(
                        '${context.tr('b2b.statement.from')}: ${_from ?? context.tr('b2b.statement.any_date')}',
                      ),
                    ),
                    OutlinedButton.icon(
                      key: const ValueKey('b2b-statement-to'),
                      onPressed: () => _pickDate(from: false),
                      icon: const Icon(Icons.event_available_outlined),
                      label: Text(
                        '${context.tr('b2b.statement.to')}: ${_to ?? context.tr('b2b.statement.any_date')}',
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 8),
                Wrap(
                  spacing: 6,
                  runSpacing: 6,
                  children: [
                    _presetChip('month', 'b2b.statement.this_month'),
                    _presetChip('previous', 'b2b.statement.previous_month'),
                    _presetChip('30', 'b2b.statement.last_30_days'),
                    _presetChip('all', 'b2b.statement.all_time'),
                  ],
                ),
              ],
            ),
          ),
        ),
        if (_stale) ...[
          const SizedBox(height: 10),
          Card(
            key: const ValueKey('b2b-statement-stale'),
            child: ListTile(
              leading: const Icon(Icons.cloud_off_outlined),
              title: Text(context.tr('b2b.statement.stale')),
              trailing: IconButton(
                tooltip: context.tr('customer.action.retry'),
                onPressed: _load,
                icon: const Icon(Icons.refresh),
              ),
            ),
          ),
        ],
        const SizedBox(height: 10),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            _summaryCard(
              'opening',
              context.tr('b2b.statement.opening_balance'),
              _money(data['opening_balance'], currency),
            ),
            _summaryCard(
              'debits',
              context.tr('b2b.statement.period_debits'),
              _money(data['period_debits'], currency),
            ),
            _summaryCard(
              'credits',
              context.tr('b2b.statement.period_credits'),
              _money(data['period_credits'], currency),
            ),
            _summaryCard(
              'closing',
              context.tr('b2b.statement.closing_balance'),
              '$direction · ${_money(closing.abs(), currency)}',
            ),
            _summaryCard(
              'current',
              context.tr('b2b.statement.current_balance'),
              _money(current.abs(), currency),
            ),
          ],
        ),
        const SizedBox(height: 10),
        Card(
          child: Padding(
            padding: const EdgeInsets.all(12),
            child: Wrap(
              spacing: 8,
              runSpacing: 8,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                Text(
                  context.tr('b2b.statement.export_exact'),
                  style: Theme.of(context).textTheme.bodyMedium,
                ),
                FilledButton.tonalIcon(
                  key: const ValueKey('b2b-statement-export-pdf'),
                  onPressed: _exporting ? null : () => _export('pdf'),
                  icon: const Icon(Icons.picture_as_pdf_outlined),
                  label: const Text('PDF'),
                ),
                FilledButton.tonalIcon(
                  key: const ValueKey('b2b-statement-export-xlsx'),
                  onPressed: _exporting ? null : () => _export('xlsx'),
                  icon: const Icon(Icons.table_view_outlined),
                  label: const Text('Excel'),
                ),
                IconButton(
                  key: const ValueKey('b2b-statement-refresh'),
                  tooltip: context.tr('customer.action.retry'),
                  onPressed: _load,
                  icon: const Icon(Icons.refresh),
                ),
              ],
            ),
          ),
        ),
        const SizedBox(height: 10),
        Text(
          context.tr('b2b.statement.transactions'),
          style: Theme.of(context).textTheme.titleMedium,
        ),
        const SizedBox(height: 6),
        if (transactions.isEmpty)
          Card(
            key: const ValueKey('b2b-empty'),
            child: Padding(
              padding: const EdgeInsets.all(18),
              child: Text(context.tr('b2b.empty.statement')),
            ),
          )
        else
          ...transactions.map(
            (row) => _transactionCard(context, row, currency),
          ),
        if (lastPage > 1) ...[
          const SizedBox(height: 8),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              OutlinedButton(
                key: const ValueKey('b2b-statement-prev-page'),
                onPressed: currentPage <= 1
                    ? null
                    : () {
                        setState(() => _page = currentPage - 1);
                        _load();
                      },
                child: Text(context.tr('b2b.statement.previous_page')),
              ),
              Text(
                '${context.tr('b2b.statement.page')} $currentPage / $lastPage',
                key: const ValueKey('b2b-statement-page'),
              ),
              OutlinedButton(
                key: const ValueKey('b2b-statement-next-page'),
                onPressed: currentPage >= lastPage
                    ? null
                    : () {
                        setState(() => _page = currentPage + 1);
                        _load();
                      },
                child: Text(context.tr('b2b.statement.next_page')),
              ),
            ],
          ),
        ],
      ],
    );
  }

  Widget _presetChip(String preset, String labelKey) => ActionChip(
        key: ValueKey('b2b-statement-preset-$preset'),
        label: Text(context.tr(labelKey)),
        onPressed: () => _preset(preset),
      );

  Widget _summaryCard(String keyName, String label, String value) => SizedBox(
        width: 190,
        child: Card(
          key: ValueKey('b2b-statement-summary-$keyName'),
          child: Padding(
            padding: const EdgeInsets.all(12),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(label, style: Theme.of(context).textTheme.labelLarge),
                const SizedBox(height: 4),
                Text(
                  value,
                  style: Theme.of(context).textTheme.titleMedium?.copyWith(
                        fontWeight: FontWeight.w800,
                      ),
                ),
              ],
            ),
          ),
        ),
      );

  Widget _transactionCard(
    BuildContext context,
    Map<Object?, Object?> row,
    String currency,
  ) {
    final type = row['type']?.toString() ?? '';
    final reference = row['reference']?.toString() ?? '—';
    final description = row['description']?.toString() ?? '—';
    final route = _referenceRoute(row);

    return Card(
      key: ValueKey('b2b-statement-row-${row['id']}'),
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    _typeLabel(type),
                    style: Theme.of(context).textTheme.titleSmall?.copyWith(
                          fontWeight: FontWeight.w800,
                        ),
                  ),
                ),
                Text(_shortDate(row['occurred_at']?.toString())),
              ],
            ),
            const SizedBox(height: 5),
            Text(description),
            const SizedBox(height: 6),
            if (route == null)
              Text(
                '${context.tr('b2b.statement.reference')}: $reference',
                key: ValueKey('b2b-statement-reference-${row['id']}'),
              )
            else
              Align(
                alignment: AlignmentDirectional.centerStart,
                child: TextButton.icon(
                  key: ValueKey('b2b-statement-reference-${row['id']}'),
                  onPressed: () => Navigator.of(context).pushNamed(route),
                  icon: const Icon(Icons.open_in_new_rounded, size: 18),
                  label: Text(
                    '${context.tr('b2b.statement.reference')}: $reference',
                  ),
                ),
              ),
            const Divider(),
            Wrap(
              spacing: 16,
              runSpacing: 6,
              children: [
                Text(
                  '${context.tr('b2b.statement.debit')}: ${_money(row['debit'], currency)}',
                ),
                Text(
                  '${context.tr('b2b.statement.credit')}: ${_money(row['credit'], currency)}',
                ),
                Text(
                  '${context.tr('b2b.statement.running_balance')}: ${_money(row['running_balance'], currency)}',
                  key: ValueKey('b2b-statement-running-${row['id']}'),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  String? _referenceRoute(Map<Object?, Object?> row) {
    final invoiceId = int.tryParse(row['invoice_id']?.toString() ?? '');
    if (invoiceId != null && invoiceId > 0) {
      return '/b2b/invoices/$invoiceId';
    }
    final orderId = int.tryParse(row['order_id']?.toString() ?? '');
    if (orderId != null && orderId > 0) {
      return '/b2b/orders/$orderId';
    }
    return null;
  }

  String _typeLabel(String type) {
    const known = <String>{
      'opening_balance',
      'invoice',
      'payment',
      'credit_note',
      'debit_note',
      'return',
      'refund',
      'adjustment_positive',
      'adjustment_negative',
    };
    return known.contains(type)
        ? context.tr('b2b.statement.type.$type')
        : type.replaceAll('_', ' ');
  }

  static double _amount(Object? value) =>
      double.tryParse(value?.toString() ?? '') ?? 0;

  static int _int(Object? value, {required int fallback}) =>
      int.tryParse(value?.toString() ?? '') ?? fallback;

  static String _money(Object? value, String currency) {
    final amount = _amount(value);
    final suffix = currency.isEmpty ? '' : ' $currency';
    return '${amount.toStringAsFixed(3)}$suffix';
  }

  static String _shortDate(String? value) {
    if (value == null || value.isEmpty) return '—';
    final parsed = DateTime.tryParse(value);
    return parsed == null ? value : _isoDate(parsed);
  }

  static String _isoDate(DateTime date) =>
      '${date.year.toString().padLeft(4, '0')}-'
      '${date.month.toString().padLeft(2, '0')}-'
      '${date.day.toString().padLeft(2, '0')}';
}

class _FinanceValue extends StatelessWidget {
  const _FinanceValue({
    required this.label,
    required this.value,
    this.emphasis = false,
  });

  final String label;
  final String value;
  final bool emphasis;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 4),
        child: Row(
          children: [
            Expanded(child: Text(label)),
            Text(
              value,
              style: TextStyle(
                fontWeight: emphasis ? FontWeight.w900 : FontWeight.w700,
              ),
            ),
          ],
        ),
      );
}


class _InvoiceDetailRemoteState extends StatefulWidget {
  const _InvoiceDetailRemoteState({
    required this.api,
    required this.endpoint,
  });

  final B2bApi api;
  final String endpoint;

  @override
  State<_InvoiceDetailRemoteState> createState() =>
      _InvoiceDetailRemoteStateState();
}

class _InvoiceDetailRemoteStateState extends State<_InvoiceDetailRemoteState>
    with WidgetsBindingObserver {
  late Future<Object?> _future;
  Object? _lastGood;
  bool _sharingPdf = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _future = _fetch();
  }

  @override
  void didUpdateWidget(covariant _InvoiceDetailRemoteState oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.api != widget.api || oldWidget.endpoint != widget.endpoint) {
      _lastGood = null;
      _future = _fetch();
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) _reload();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  Future<Object?> _fetch() async {
    final value = await _loadB2bRemote(widget.api, widget.endpoint);
    _lastGood = value;
    return value;
  }

  void _reload() {
    if (!mounted) return;
    setState(() => _future = _fetch());
  }

  Map<Object?, Object?> _map(Object? raw) =>
      raw is Map ? Map<Object?, Object?>.from(raw) : <Object?, Object?>{};

  List<Map<Object?, Object?>> _rows(Object? raw) {
    if (raw is! List) return const [];
    return raw
        .whereType<Map>()
        .map((row) => Map<Object?, Object?>.from(row))
        .toList(growable: false);
  }

  String _money(Object? value, String currency) {
    final amount = double.tryParse(value?.toString() ?? '');
    if (amount == null) return '—';
    final suffix = currency.isEmpty ? '' : ' $currency';
    return '${amount.toStringAsFixed(3)}$suffix';
  }

  String _displayDate(Object? value) {
    final parsed = DateTime.tryParse(value?.toString() ?? '');
    if (parsed == null) return '—';
    final local = parsed.toLocal();
    return '${local.year.toString().padLeft(4, '0')}-'
        '${local.month.toString().padLeft(2, '0')}-'
        '${local.day.toString().padLeft(2, '0')}';
  }

  String _statusLabel(String value, bool ar) => switch (value) {
        'paid' => ar ? 'مدفوعة' : 'Paid',
        'partially_paid' => ar ? 'مدفوعة جزئياً' : 'Partially paid',
        'overdue' => ar ? 'متأخرة' : 'Overdue',
        'cancelled' => ar ? 'ملغاة' : 'Cancelled',
        'credited' => ar ? 'رصيد دائن' : 'Credited',
        _ => ar ? 'مفتوحة' : 'Open',
      };

  Future<void> _sharePdf(Map<Object?, Object?> data) async {
    if (_sharingPdf) return;
    final ar = Localizations.localeOf(context).languageCode == 'ar';
    final rawPath = data['pdf_path']?.toString() ?? '';
    if (rawPath.isEmpty) return;
    final documentApi = widget.api;
    if (documentApi is! B2bDocumentApi) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            ar
                ? 'تنزيل PDF غير متاح في وضع المعاينة.'
                : 'PDF download is unavailable in preview mode.',
          ),
        ),
      );
      return;
    }

    final path =
        '$rawPath${rawPath.contains('?') ? '&' : '?'}locale=${ar ? 'ar' : 'en'}';
    final number = data['invoice_number']?.toString() ?? 'invoice';
    setState(() => _sharingPdf = true);
    try {
      final bytes = await (documentApi as B2bDocumentApi).getBytes(path);
      final name = '$number.pdf';
      await SharePlus.instance.share(
        ShareParams(
          files: [
            XFile.fromData(
              Uint8List.fromList(bytes),
              mimeType: 'application/pdf',
              name: name,
            ),
          ],
          fileNameOverrides: [name],
          subject: number,
        ),
      );
    } catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            ar
                ? 'تعذر تنزيل ملف الفاتورة. أعد المحاولة.'
                : 'Invoice PDF could not be downloaded. Try again.',
          ),
        ),
      );
    } finally {
      if (mounted) setState(() => _sharingPdf = false);
    }
  }

  String? _orderRoute(Map<Object?, Object?> data) {
    final orderId = int.tryParse(data['order_id']?.toString() ?? '');
    if (orderId == null || orderId <= 0) return null;
    final storeId = data['store_id']?.toString();
    return Uri(
      path: '/b2b/orders/$orderId',
      queryParameters: <String, String>{
        'channel': 'wholesale',
        if (storeId != null && storeId.isNotEmpty) 'store_id': storeId,
      },
    ).toString();
  }

  Widget _summaryRow(String label, String value, {bool strong = false}) =>
      Padding(
        padding: const EdgeInsets.symmetric(vertical: 4),
        child: Row(
          children: [
            Expanded(child: Text(label)),
            Text(
              value,
              style: strong
                  ? const TextStyle(fontWeight: FontWeight.w800)
                  : null,
            ),
          ],
        ),
      );

  Widget _settlementMetric({
    required String label,
    required String value,
    required IconData icon,
    bool emphasized = false,
  }) {
    final scheme = Theme.of(context).colorScheme;
    return Expanded(
      child: Container(
        constraints: const BoxConstraints(minHeight: 88),
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 12),
        decoration: BoxDecoration(
          color: emphasized
              ? scheme.primaryContainer
              : scheme.surfaceContainerHighest,
          borderRadius: BorderRadius.circular(18),
          border: Border.all(
            color: emphasized ? scheme.primary : scheme.outlineVariant,
          ),
        ),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(
              icon,
              size: 20,
              color: emphasized
                  ? scheme.onPrimaryContainer
                  : scheme.onSurfaceVariant,
            ),
            const SizedBox(height: 6),
            Text(
              label,
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.labelMedium?.copyWith(
                    color: emphasized ? scheme.onPrimaryContainer : null,
                  ),
            ),
            const SizedBox(height: 3),
            FittedBox(
              fit: BoxFit.scaleDown,
              child: Text(
                value,
                maxLines: 1,
                style: Theme.of(context).textTheme.titleSmall?.copyWith(
                      color: emphasized ? scheme.onPrimaryContainer : null,
                      fontWeight: FontWeight.w900,
                    ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _body(Object? raw, {bool stale = false}) {
    final ar = Localizations.localeOf(context).languageCode == 'ar';
    final envelope = _map(raw);
    final data = _map(envelope['data']);
    if (data.isEmpty) {
      return Card(
        key: const ValueKey('b2b-empty'),
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Text(ar ? 'الفاتورة غير متاحة.' : 'Invoice is unavailable.'),
        ),
      );
    }

    final currency = data['currency']?.toString() ?? '';
    final seller = _map(data['seller']);
    final customer = _map(data['customer']);
    final items = _rows(data['items']);
    final payments = _rows(data['payments']);
    final ledger = _rows(data['ledger_entries']);
    final status =
        data['display_status']?.toString() ?? data['status']?.toString() ?? '';
    final orderRoute = _orderRoute(data);
    final credit =
        double.tryParse(data['credit_amount']?.toString() ?? '') ?? 0;
    final outstanding =
        double.tryParse(data['outstanding_amount']?.toString() ?? '') ?? 0;

    return Column(
      key: const ValueKey('b2b-invoice-detail-data'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (stale)
          Card(
            key: const ValueKey('b2b-invoice-detail-stale'),
            child: ListTile(
              leading: const Icon(Icons.cloud_off_outlined),
              title: Text(
                ar
                    ? 'تعذر التحديث؛ يتم عرض آخر بيانات فاتورة مؤكدة.'
                    : 'Refresh failed; showing the last confirmed invoice.',
              ),
              trailing: IconButton(
                onPressed: _reload,
                icon: const Icon(Icons.refresh_rounded),
              ),
            ),
          ),
        Container(
          key: const ValueKey('b2b-invoice-hero'),
          padding: const EdgeInsets.all(16),
          decoration: BoxDecoration(
            color: Theme.of(context).colorScheme.primaryContainer,
            borderRadius: BorderRadius.circular(24),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Container(
                    width: 48,
                    height: 48,
                    decoration: BoxDecoration(
                      color: Theme.of(context).colorScheme.surface,
                      borderRadius: BorderRadius.circular(16),
                    ),
                    child: const Icon(Icons.receipt_long_rounded),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          ar ? 'تفاصيل الفاتورة' : 'Invoice details',
                          style:
                              Theme.of(context).textTheme.labelLarge?.copyWith(
                                    color: Theme.of(context)
                                        .colorScheme
                                        .onPrimaryContainer,
                                    fontWeight: FontWeight.w800,
                                  ),
                        ),
                        const SizedBox(height: 2),
                        Text(
                          data['invoice_number']?.toString() ?? '—',
                          style:
                              Theme.of(context).textTheme.headlineSmall?.copyWith(
                                    color: Theme.of(context)
                                        .colorScheme
                                        .onPrimaryContainer,
                                    fontWeight: FontWeight.w900,
                                  ),
                        ),
                      ],
                    ),
                  ),
                  Chip(
                    key: const ValueKey('b2b-invoice-status-chip'),
                    label: Text(_statusLabel(status, ar)),
                  ),
                ],
              ),
              const SizedBox(height: 14),
              Wrap(
                spacing: 12,
                runSpacing: 8,
                children: [
                  Text(
                    '${ar ? 'الإصدار' : 'Issued'} · ${_displayDate(data['issued_at'])}',
                    style: TextStyle(
                      color: Theme.of(context).colorScheme.onPrimaryContainer,
                    ),
                  ),
                  Text(
                    '${ar ? 'الاستحقاق' : 'Due'} · ${_displayDate(data['due_at'])}',
                    style: TextStyle(
                      color: Theme.of(context).colorScheme.onPrimaryContainer,
                    ),
                  ),
                  Text(
                    '${ar ? 'العملة' : 'Currency'} · $currency',
                    style: TextStyle(
                      color: Theme.of(context).colorScheme.onPrimaryContainer,
                    ),
                  ),
                ],
              ),
              if ((seller['name']?.toString() ?? '').isNotEmpty ||
                  (customer['name']?.toString() ?? '').isNotEmpty) ...[
                const SizedBox(height: 12),
                const Divider(height: 1),
                const SizedBox(height: 12),
              ],
              if ((seller['name']?.toString() ?? '').isNotEmpty)
                Text(
                  '${ar ? 'البائع' : 'Seller'} · ${seller['name']}',
                  key: const ValueKey('b2b-invoice-seller'),
                  style: const TextStyle(fontWeight: FontWeight.w700),
                ),
              if ((customer['name']?.toString() ?? '').isNotEmpty)
                Text(
                  '${ar ? 'العميل' : 'Customer'} · ${customer['name']}',
                  key: const ValueKey('b2b-invoice-customer'),
                  style: const TextStyle(fontWeight: FontWeight.w700),
                ),
              if ((customer['email']?.toString() ?? '').isNotEmpty)
                Text(customer['email'].toString()),
              if ((customer['phone']?.toString() ?? '').isNotEmpty)
                Text(customer['phone'].toString()),
              const SizedBox(height: 14),
              FilledButton.icon(
                key: const ValueKey('b2b-invoice-detail-pdf'),
                onPressed: _sharingPdf ? null : () => _sharePdf(data),
                icon: _sharingPdf
                    ? const SizedBox.square(
                        dimension: 18,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Icon(Icons.picture_as_pdf_rounded),
                label: Text(
                  ar ? 'تحميل الفاتورة PDF' : 'Download invoice PDF',
                ),
              ),
              const SizedBox(height: 8),
              Row(
                children: [
                  if (orderRoute != null)
                    Expanded(
                      child: FilledButton.tonalIcon(
                        key: const ValueKey('b2b-invoice-related-order'),
                        onPressed: () =>
                            Navigator.of(context).pushNamed(orderRoute),
                        icon: const Icon(Icons.local_shipping_outlined),
                        label: Text(ar ? 'الطلب المرتبط' : 'Related order'),
                      ),
                    ),
                  if (orderRoute != null) const SizedBox(width: 8),
                  IconButton.filledTonal(
                    key: const ValueKey('b2b-invoice-detail-refresh'),
                    tooltip: ar ? 'تحديث' : 'Refresh',
                    onPressed: _reload,
                    icon: const Icon(Icons.refresh_rounded),
                  ),
                ],
              ),
            ],
          ),
        ),
        const SizedBox(height: 12),
        Row(
          key: const ValueKey('b2b-invoice-settlement-strip'),
          children: [
            _settlementMetric(
              label: ar ? 'الإجمالي' : 'Total',
              value: _money(data['total'], currency),
              icon: Icons.receipt_long_outlined,
            ),
            const SizedBox(width: 8),
            _settlementMetric(
              label: ar ? 'المدفوع' : 'Paid',
              value: _money(data['paid_amount'], currency),
              icon: Icons.check_circle_outline_rounded,
            ),
            const SizedBox(width: 8),
            _settlementMetric(
              label: credit > 0.0005
                  ? (ar ? 'لك' : 'Credit')
                  : (ar ? 'عليك' : 'Outstanding'),
              value: credit > 0.0005
                  ? _money(data['credit_amount'], currency)
                  : _money(data['outstanding_amount'], currency),
              icon: credit > 0.0005
                  ? Icons.savings_outlined
                  : Icons.account_balance_wallet_outlined,
              emphasized: credit > 0.0005 || outstanding > 0.0005,
            ),
          ],
        ),
        Card(
          key: const ValueKey('b2b-invoice-detail-totals'),
          child: Padding(
            padding: const EdgeInsets.all(14),
            child: Column(
              children: [
                _summaryRow(
                  ar ? 'الإجمالي الفرعي' : 'Subtotal',
                  _money(data['subtotal'], currency),
                ),
                _summaryRow(
                  ar ? 'الخصومات' : 'Discounts',
                  _money(data['discount_total'], currency),
                ),
                _summaryRow(
                  ar ? 'التوصيل / الرسوم' : 'Delivery / fees',
                  _money(data['delivery_total'], currency),
                ),
                _summaryRow(
                  ar ? 'الضريبة' : 'Tax',
                  _money(data['tax_total'], currency),
                ),
                const Divider(),
                _summaryRow(
                  ar ? 'الإجمالي' : 'Total',
                  _money(data['total'], currency),
                  strong: true,
                ),
                _summaryRow(
                  ar ? 'المدفوع' : 'Paid',
                  _money(data['paid_amount'], currency),
                ),
                if (outstanding > 0.0005)
                  _summaryRow(
                    ar ? 'المتبقي عليك' : 'Outstanding',
                    _money(data['outstanding_amount'], currency),
                    strong: true,
                  ),
                if (credit > 0.0005)
                  _summaryRow(
                    ar ? 'رصيد لك' : 'Credit to you',
                    _money(data['credit_amount'], currency),
                    strong: true,
                  ),
              ],
            ),
          ),
        ),
        const SizedBox(height: 8),
        Text(
          ar ? 'بنود الفاتورة' : 'Invoice items',
          style: Theme.of(context).textTheme.titleMedium?.copyWith(
                fontWeight: FontWeight.w800,
              ),
        ),
        const SizedBox(height: 6),
        if (items.isEmpty)
          Card(
            child: Padding(
              padding: const EdgeInsets.all(14),
              child: Text(ar ? 'لا توجد بنود.' : 'No invoice items.'),
            ),
          )
        else
          ...items.map((item) {
            final itemCurrency =
                item['currency']?.toString().isNotEmpty == true
                    ? item['currency'].toString()
                    : currency;
            final discount =
                double.tryParse(item['discount_total']?.toString() ?? '') ?? 0;
            final tax =
                double.tryParse(item['tax_total']?.toString() ?? '') ?? 0;
            return Card(
              key: ValueKey('b2b-invoice-item-${item['id']}'),
              child: Padding(
                padding: const EdgeInsets.all(12),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Text(
                      item['description']?.toString() ?? '—',
                      style: const TextStyle(fontWeight: FontWeight.w800),
                    ),
                    if ((item['sku']?.toString() ?? '').isNotEmpty)
                      Text('SKU: ${item['sku']}'),
                    Text(
                      '${ar ? 'الكمية: ' : 'Qty: '}${item['quantity']} × '
                      '${_money(item['unit_price'], itemCurrency)}',
                    ),
                    if (discount > 0.0005)
                      Text(
                        '${ar ? 'خصم: ' : 'Discount: '}'
                        '${_money(item['discount_total'], itemCurrency)}',
                      ),
                    if (tax > 0.0005)
                      Text(
                        '${ar ? 'ضريبة: ' : 'Tax: '}'
                        '${_money(item['tax_total'], itemCurrency)}',
                      ),
                    Text(
                      '${ar ? 'إجمالي البند: ' : 'Line total: '}'
                      '${_money(item['line_total'], itemCurrency)}',
                    ),
                  ],
                ),
              ),
            );
          }),
        const SizedBox(height: 8),
        Text(
          ar ? 'سجل المدفوعات' : 'Payment history',
          style: Theme.of(context).textTheme.titleMedium?.copyWith(
                fontWeight: FontWeight.w800,
              ),
        ),
        Card(
          key: const ValueKey('b2b-invoice-payments'),
          child: payments.isEmpty
              ? Padding(
                  padding: const EdgeInsets.all(14),
                  child: Text(ar ? 'لا توجد مدفوعات.' : 'No payments yet.'),
                )
              : Column(
                  children: payments.map((payment) {
                    final paymentCurrency =
                        payment['currency']?.toString() ?? currency;
                    final method = payment['method']?.toString() ?? '';
                    final reference = payment['reference']?.toString() ?? '';
                    return ListTile(
                      leading: const Icon(Icons.payments_outlined),
                      title: Text(
                        _money(payment['amount'], paymentCurrency),
                        style: const TextStyle(fontWeight: FontWeight.w800),
                      ),
                      subtitle: Text(
                        [
                          if (method.isNotEmpty) method,
                          if (reference.isNotEmpty) reference,
                          _displayDate(payment['paid_at']),
                        ].join(' · '),
                      ),
                    );
                  }).toList(growable: false),
                ),
        ),
        if (ledger.isNotEmpty) ...[
          const SizedBox(height: 8),
          Text(
            ar ? 'التسويات والحركات المرتبطة' : 'Related adjustments',
            style: Theme.of(context).textTheme.titleMedium?.copyWith(
                  fontWeight: FontWeight.w800,
                ),
          ),
          Card(
            key: const ValueKey('b2b-invoice-ledger'),
            child: Column(
              children: ledger.map((entry) {
                final entryCurrency =
                    entry['currency']?.toString() ?? currency;
                final debit =
                    double.tryParse(entry['debit']?.toString() ?? '') ?? 0;
                final creditAmount =
                    double.tryParse(entry['credit']?.toString() ?? '') ?? 0;
                final amountText = debit > 0.0005
                    ? (ar ? 'مدين ' : 'Debit ') +
                        _money(debit, entryCurrency)
                    : (ar ? 'دائن ' : 'Credit ') +
                        _money(creditAmount, entryCurrency);
                return ListTile(
                  title: Text(entry['type']?.toString() ?? '—'),
                  subtitle: Text(
                    [
                      if ((entry['reference']?.toString() ?? '').isNotEmpty)
                        entry['reference'].toString(),
                      if ((entry['description']?.toString() ?? '').isNotEmpty)
                        entry['description'].toString(),
                      _displayDate(entry['occurred_at']),
                    ].join(' · '),
                  ),
                  trailing: Text(amountText),
                );
              }).toList(growable: false),
            ),
          ),
        ],
      ],
    );
  }

  @override
  Widget build(BuildContext context) => FutureBuilder<Object?>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done &&
              _lastGood == null) {
            return const Center(
              key: ValueKey('b2b-loading'),
              child: CircularProgressIndicator(),
            );
          }

          if (snapshot.hasError) {
            if (_lastGood != null) {
              return _body(_lastGood, stale: true);
            }
            return _B2bRemoteErrorCard(
              error: snapshot.error,
              onRetry: _reload,
            );
          }

          final value = snapshot.connectionState == ConnectionState.done
              ? snapshot.data
              : _lastGood;
          return _body(value);
        },
      );
}

class _InvoicesRemoteState extends StatefulWidget {
  const _InvoicesRemoteState({
    required this.api,
    required this.endpoint,
  });

  final B2bApi api;
  final String endpoint;

  @override
  State<_InvoicesRemoteState> createState() => _InvoicesRemoteStateState();
}

class _InvoicesRemoteStateState extends State<_InvoicesRemoteState>
    with WidgetsBindingObserver {
  late TextEditingController _searchController;
  late Future<Object?> _future;
  Object? _lastGood;
  DateTime? _from;
  DateTime? _to;
  String _status = '';
  int _page = 1;
  int _perPage = 20;
  bool _sharingPdf = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _syncFromEndpoint();
    _future = _fetch();
  }

  @override
  void didUpdateWidget(covariant _InvoicesRemoteState oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.api != widget.api || oldWidget.endpoint != widget.endpoint) {
      _searchController.dispose();
      _syncFromEndpoint();
      _future = _fetch();
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) _reload();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _searchController.dispose();
    super.dispose();
  }

  void _syncFromEndpoint() {
    final uri = Uri.parse(widget.endpoint);
    _from = DateTime.tryParse(uri.queryParameters['from'] ?? '');
    _to = DateTime.tryParse(uri.queryParameters['to'] ?? '');
    _status = uri.queryParameters['status'] ?? '';
    _page = int.tryParse(uri.queryParameters['page'] ?? '') ?? 1;
    _perPage = int.tryParse(uri.queryParameters['per_page'] ?? '') ?? 20;
    _searchController =
        TextEditingController(text: uri.queryParameters['q'] ?? '');
  }

  String _effectiveEndpoint({int? page}) {
    final uri = Uri.parse(widget.endpoint);
    final params = Map<String, String>.from(uri.queryParameters)
      ..remove('from')
      ..remove('to')
      ..remove('status')
      ..remove('q')
      ..remove('page')
      ..remove('per_page');
    if (_from != null) params['from'] = _date(_from!);
    if (_to != null) params['to'] = _date(_to!);
    if (_status.isNotEmpty) params['status'] = _status;
    final search = _searchController.text.trim();
    if (search.isNotEmpty) params['q'] = search;
    final targetPage = page ?? _page;
    if (targetPage > 1) params['page'] = targetPage.toString();
    if (_perPage != 20) params['per_page'] = _perPage.toString();
    if (params.isEmpty) return uri.path;
    return uri.replace(queryParameters: params).toString();
  }

  Future<Object?> _fetch() async {
    final value = await widget.api.get(_effectiveEndpoint());
    _lastGood = value;
    return value;
  }

  void _reload({int? page}) {
    final targetPage = page ?? _page;
    setState(() {
      _page = targetPage;
      _future = _fetch();
    });
  }

  Future<void> _pickDate({required bool from}) async {
    final initial = from ? (_from ?? DateTime.now()) : (_to ?? DateTime.now());
    final picked = await showDatePicker(
      context: context,
      initialDate: initial,
      firstDate: DateTime(2020),
      lastDate: DateTime.now().add(const Duration(days: 366)),
    );
    if (picked == null) return;
    setState(() {
      if (from) {
        _from = picked;
        if (_to != null && picked.isAfter(_to!)) _to = picked;
      } else {
        _to = picked;
        if (_from != null && picked.isBefore(_from!)) _from = picked;
      }
    });
    _reload(page: 1);
  }

  void _clearFilters() {
    setState(() {
      _from = null;
      _to = null;
      _status = '';
      _searchController.clear();
    });
    _reload(page: 1);
  }

  Future<void> _sharePdf(Map<Object?, Object?> row) async {
    if (_sharingPdf) return;
    final ar = Localizations.localeOf(context).languageCode == 'ar';
    final documentApi = widget.api;
    if (documentApi is! B2bDocumentApi) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            ar
                ? 'تنزيل PDF غير متاح في وضع المعاينة.'
                : 'PDF download is unavailable in preview mode.',
          ),
        ),
      );
      return;
    }
    final rawPath = row['pdf_path']?.toString() ?? '';
    if (rawPath.isEmpty) return;
    final path =
        '$rawPath${rawPath.contains('?') ? '&' : '?'}locale=${ar ? 'ar' : 'en'}';
    final number = row['invoice_number']?.toString() ?? 'invoice';
    setState(() => _sharingPdf = true);
    try {
      final bytes = await (documentApi as B2bDocumentApi).getBytes(path);
      final name = '$number.pdf';
      await SharePlus.instance.share(
        ShareParams(
          files: [
            XFile.fromData(
              Uint8List.fromList(bytes),
              mimeType: 'application/pdf',
              name: name,
            ),
          ],
          fileNameOverrides: [name],
          subject: number,
        ),
      );
    } catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            ar
                ? 'تعذر تنزيل ملف الفاتورة. أعد المحاولة.'
                : 'Invoice PDF could not be downloaded. Try again.',
          ),
        ),
      );
    } finally {
      if (mounted) setState(() => _sharingPdf = false);
    }
  }

  String _detailRoute(Map<Object?, Object?> row) {
    final source = Uri.parse(widget.endpoint);
    final params = <String, String>{'channel': 'wholesale'};
    final storeId =
        row['store_id']?.toString() ?? source.queryParameters['store_id'];
    if (storeId != null && storeId.isNotEmpty) params['store_id'] = storeId;
    return Uri(
      path: '/b2b/invoices/${row['id']}',
      queryParameters: params,
    ).toString();
  }

  String _statusLabel(BuildContext context, String value) {
    final ar = Localizations.localeOf(context).languageCode == 'ar';
    return switch (value) {
      'paid' => ar ? 'مدفوعة' : 'Paid',
      'partially_paid' => ar ? 'مدفوعة جزئياً' : 'Partially paid',
      'overdue' => ar ? 'متأخرة' : 'Overdue',
      'cancelled' => ar ? 'ملغاة' : 'Cancelled',
      'credited' => ar ? 'رصيد دائن' : 'Credited',
      _ => ar ? 'مفتوحة' : 'Open',
    };
  }

  String _money(Object? value, String currency) {
    final amount = double.tryParse(value?.toString() ?? '') ?? 0;
    return '${amount.toStringAsFixed(3)} $currency'.trim();
  }

  String _date(DateTime date) =>
      '${date.year.toString().padLeft(4, '0')}-'
      '${date.month.toString().padLeft(2, '0')}-'
      '${date.day.toString().padLeft(2, '0')}';

  String _displayDate(Object? value) {
    final parsed = DateTime.tryParse(value?.toString() ?? '');
    return parsed == null ? '—' : _date(parsed.toLocal());
  }

  List<Map<Object?, Object?>> _rows(Object? raw) {
    if (raw is! List) return const [];
    return raw
        .whereType<Map>()
        .map((row) => Map<Object?, Object?>.from(row))
        .toList(growable: false);
  }

  Widget _body(Object? raw, {bool stale = false}) {
    final ar = Localizations.localeOf(context).languageCode == 'ar';
    final envelope = raw is Map
        ? Map<Object?, Object?>.from(raw)
        : <Object?, Object?>{};
    final rows = _rows(envelope['data']);
    final summary = envelope['summary'] is Map
        ? Map<Object?, Object?>.from(envelope['summary'] as Map)
        : <Object?, Object?>{};
    final meta = envelope['meta'] is Map
        ? Map<Object?, Object?>.from(envelope['meta'] as Map)
        : <Object?, Object?>{};
    final totals = _rows(summary['totals']);
    final hasMore = meta['has_more'] == true;

    return Column(
      key: const ValueKey('b2b-invoices-data'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (stale)
          Card(
            key: const ValueKey('b2b-invoices-stale'),
            child: ListTile(
              leading: const Icon(Icons.cloud_off_outlined),
              title: Text(
                ar
                    ? 'تعذر التحديث؛ يتم عرض آخر بيانات مؤكدة.'
                    : 'Refresh failed; showing the last confirmed data.',
              ),
              trailing: IconButton(
                onPressed: _reload,
                icon: const Icon(Icons.refresh_rounded),
              ),
            ),
          ),
        Card(
          child: Padding(
            padding: const EdgeInsets.all(12),
            child: Column(
              children: [
                TextField(
                  key: const ValueKey('b2b-invoices-search'),
                  controller: _searchController,
                  textInputAction: TextInputAction.search,
                  decoration: InputDecoration(
                    labelText: ar ? 'بحث برقم الفاتورة' : 'Search invoice number',
                    prefixIcon: const Icon(Icons.search),
                  ),
                  onSubmitted: (_) => _reload(page: 1),
                ),
                const SizedBox(height: 10),
                DropdownButtonFormField<String>(
                  key: const ValueKey('b2b-invoices-status'),
                  initialValue: _status,
                  isExpanded: true,
                  decoration: InputDecoration(
                    labelText: ar ? 'الحالة' : 'Status',
                  ),
                  items: [
                    DropdownMenuItem(
                      value: '',
                      child: Text(ar ? 'كل الحالات' : 'All statuses'),
                    ),
                    for (final value in const [
                      'open',
                      'partially_paid',
                      'paid',
                      'overdue',
                      'credited',
                      'cancelled',
                    ])
                      DropdownMenuItem(
                        value: value,
                        child: Text(_statusLabel(context, value)),
                      ),
                  ],
                  onChanged: (value) {
                    setState(() => _status = value ?? '');
                    _reload(page: 1);
                  },
                ),
                const SizedBox(height: 10),
                Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  children: [
                    OutlinedButton.icon(
                      key: const ValueKey('b2b-invoices-from'),
                      onPressed: () => _pickDate(from: true),
                      icon: const Icon(Icons.date_range_outlined),
                      label: Text(_from == null
                          ? (ar ? 'من' : 'From')
                          : _date(_from!)),
                    ),
                    OutlinedButton.icon(
                      key: const ValueKey('b2b-invoices-to'),
                      onPressed: () => _pickDate(from: false),
                      icon: const Icon(Icons.event_outlined),
                      label: Text(
                        _to == null ? (ar ? 'إلى' : 'To') : _date(_to!),
                      ),
                    ),
                    TextButton.icon(
                      key: const ValueKey('b2b-invoices-clear-filters'),
                      onPressed: _clearFilters,
                      icon: const Icon(Icons.filter_alt_off_outlined),
                      label: Text(ar ? 'مسح الفلاتر' : 'Clear filters'),
                    ),
                    IconButton(
                      key: const ValueKey('b2b-invoices-refresh'),
                      tooltip: ar ? 'تحديث' : 'Refresh',
                      onPressed: _reload,
                      icon: const Icon(Icons.refresh_rounded),
                    ),
                  ],
                ),
              ],
            ),
          ),
        ),
        const SizedBox(height: 10),
        ...totals.map((total) {
          final currency = total['currency']?.toString() ?? '';
          return Card(
            key: ValueKey('b2b-invoices-summary-$currency'),
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: Wrap(
                spacing: 16,
                runSpacing: 8,
                children: [
                  Text('${ar ? 'الإجمالي' : 'Total'}: ${_money(total['total'], currency)}'),
                  Text('${ar ? 'المدفوع' : 'Paid'}: ${_money(total['paid'], currency)}'),
                  Text('${ar ? 'المتبقي' : 'Outstanding'}: ${_money(total['outstanding'], currency)}'),
                  Text('${ar ? 'المتأخر' : 'Overdue'}: ${_money(total['overdue'], currency)}'),
                  if ((double.tryParse(total['credit'].toString()) ?? 0) > 0)
                    Text('${ar ? 'لك' : 'Credit'}: ${_money(total['credit'], currency)}'),
                ],
              ),
            ),
          );
        }),
        if (rows.isEmpty)
          Card(
            key: const ValueKey('b2b-empty'),
            child: Padding(
              padding: const EdgeInsets.all(20),
              child: Text(
                ar
                    ? 'لا توجد فواتير مطابقة للفلاتر الحالية.'
                    : 'No invoices match the current filters.',
              ),
            ),
          )
        else
          ...rows.map((row) {
            final currency = row['currency']?.toString() ?? '';
            final status = row['display_status']?.toString() ??
                row['status']?.toString() ??
                'open';
            return Card(
              key: ValueKey('b2b-invoice-${row['id']}'),
              child: InkWell(
                onTap: () => Navigator.of(context).pushNamed(_detailRoute(row)),
                child: Padding(
                  padding: const EdgeInsets.all(12),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Row(
                        children: [
                          Expanded(
                            child: Text(
                              row['invoice_number']?.toString() ?? '—',
                              style: const TextStyle(fontWeight: FontWeight.w800),
                            ),
                          ),
                          Chip(label: Text(_statusLabel(context, status))),
                        ],
                      ),
                      Text('${ar ? 'تاريخ الإصدار' : 'Issued'}: ${_displayDate(row['issued_at'])}'),
                      Text('${ar ? 'الاستحقاق' : 'Due'}: ${_displayDate(row['due_at'])}'),
                      const SizedBox(height: 6),
                      Text('${ar ? 'الإجمالي' : 'Total'}: ${_money(row['total'], currency)}'),
                      Text('${ar ? 'المدفوع' : 'Paid'}: ${_money(row['paid_amount'], currency)}'),
                      Text('${ar ? 'المتبقي' : 'Outstanding'}: ${_money(row['outstanding_amount'], currency)}'),
                      if ((double.tryParse(row['credit_amount'].toString()) ?? 0) >
                          0)
                        Text('${ar ? 'لك' : 'Credit'}: ${_money(row['credit_amount'], currency)}'),
                      const SizedBox(height: 8),
                      Wrap(
                        spacing: 8,
                        children: [
                          OutlinedButton.icon(
                            key: ValueKey('b2b-invoice-pdf-${row['id']}'),
                            onPressed: _sharingPdf ? null : () => _sharePdf(row),
                            icon: const Icon(Icons.picture_as_pdf_outlined),
                            label: const Text('PDF'),
                          ),
                          FilledButton.tonalIcon(
                            key: ValueKey('b2b-invoice-open-${row['id']}'),
                            onPressed: () => Navigator.of(context)
                                .pushNamed(_detailRoute(row)),
                            icon: const Icon(Icons.open_in_new_rounded),
                            label: Text(ar ? 'التفاصيل' : 'Details'),
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
              ),
            );
          }),
        const SizedBox(height: 8),
        Row(
          children: [
            OutlinedButton(
              key: const ValueKey('b2b-invoices-previous'),
              onPressed: _page > 1 ? () => _reload(page: _page - 1) : null,
              child: Text(ar ? 'السابق' : 'Previous'),
            ),
            const Spacer(),
            Text('${ar ? 'صفحة' : 'Page'} $_page'),
            const Spacer(),
            OutlinedButton(
              key: const ValueKey('b2b-invoices-next'),
              onPressed: hasMore ? () => _reload(page: _page + 1) : null,
              child: Text(ar ? 'التالي' : 'Next'),
            ),
          ],
        ),
      ],
    );
  }

  @override
  Widget build(BuildContext context) => FutureBuilder<Object?>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done &&
              _lastGood == null) {
            return const Center(
              key: ValueKey('b2b-loading'),
              child: CircularProgressIndicator(),
            );
          }
          if (snapshot.hasError) {
            if (_lastGood != null) return _body(_lastGood, stale: true);
            return _B2bRemoteErrorCard(
              error: snapshot.error,
              onRetry: _reload,
            );
          }
          final value = snapshot.data ?? _lastGood;
          if (value is! Map) {
            return Card(
              key: const ValueKey('b2b-empty'),
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Text(context.tr('b2b.remote.empty')),
              ),
            );
          }
          return _body(value);
        },
      );
}

