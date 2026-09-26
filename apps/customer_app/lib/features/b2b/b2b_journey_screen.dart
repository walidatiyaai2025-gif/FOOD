import 'package:flutter/material.dart';

import '../../core/api/b2b_api.dart';
import '../../core/api/customer_action_api.dart';
import '../../core/auth/customer_session.dart';
import '../../core/localization/app_translations.dart';
import '../../core/routing/customer_routes.dart';
import '../../shared/customer_action_widgets.dart';

class B2bJourneyScreen extends StatelessWidget {
  const B2bJourneyScreen({
    required this.definition,
    required this.location,
    required this.actionApi,
    required this.onAuthenticated,
    this.api,
    super.key,
  });

  final CustomerRouteDefinition definition;
  final String location;
  final B2bApi? api;
  final CustomerActionApi actionApi;
  final CustomerAuthenticated onAuthenticated;

  @override
  Widget build(BuildContext context) {
    final content = _contentFor(context, definition.pattern);
    final hasRemoteState = api != null && _endpoint() != null;
    final keepLocalActions =
        definition.pattern == CustomerRoutePaths.b2bProductDetails ||
        definition.pattern == CustomerRoutePaths.b2bCart;

    return Scaffold(
      appBar: AppBar(title: Text(context.tr('b2b.app.title'))),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.all(20),
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
            if (hasRemoteState && !keepLocalActions)
              definition.pattern == CustomerRoutePaths.b2bTopProducts
                  ? _TopProductsRemoteState(api: api!, endpoint: _endpoint()!)
                  : _RemoteState(
                      api: api!,
                      endpoint: _endpoint()!,
                      routePattern: definition.pattern,
                    ),
            Text(
              location,
              key: const ValueKey('customer-route-location'),
              style: Theme.of(context).textTheme.labelSmall,
            ),
          ],
        ),
      ),
    );
  }

  (String, String, List<Widget>) _contentFor(
    BuildContext context,
    String pattern,
  ) {
    switch (pattern) {
      case CustomerRoutePaths.b2bLogin:
        return (
          context.tr('b2b.login.title'),
          context.tr('b2b.login.subtitle'),
          [
            CustomerLoginAction(
              channel: CustomerChannel.b2b,
              api: actionApi,
              onAuthenticated: onAuthenticated,
              successRoute: CustomerRoutePaths.b2bDashboard,
            ),
          ],
        );
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
            ),
          ],
        );
      case CustomerRoutePaths.b2bProfile:
        return (
          context.tr('b2b.profile.title'),
          context.tr('b2b.profile.subtitle'),
          [
            _section(context.tr('b2b.profile.company')),
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
        return '/api/v1/b2b/dashboard';
      case CustomerRoutePaths.b2bPurchaseReports:
        return '/api/v1/b2b/reports/purchases';
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
        return '/api/v1/b2b/invoices';
      case CustomerRoutePaths.b2bInvoiceDetails:
        return '/api/v1/b2b/invoices/${segments.last}';
      case CustomerRoutePaths.b2bAccountStatement:
        return '/api/v1/b2b/account-statement';
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


class _B2bProductDetailRemoteState extends StatelessWidget {
  const _B2bProductDetailRemoteState({
    required this.api,
    required this.endpoint,
  });

  final B2bApi api;
  final String endpoint;

  @override
  Widget build(BuildContext context) => FutureBuilder<Object?>(
        future: api.get(endpoint),
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done) {
            return const Center(
              key: ValueKey('b2b-loading'),
              child: CircularProgressIndicator(),
            );
          }

          if (snapshot.hasError) {
            final error = snapshot.error;
            final forbidden = error is B2bApiException &&
                error.code == 'not_authorized';
            return Card(
              key: ValueKey(forbidden ? 'b2b-forbidden' : 'b2b-error'),
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Text(
                  context.tr(
                    forbidden
                        ? 'b2b.remote.forbidden'
                        : 'b2b.remote.error',
                  ),
                ),
              ),
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

          return Card(
            key: const ValueKey('b2b-product-detail-data'),
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
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

class _TopProductsRemoteState extends StatelessWidget {
  const _TopProductsRemoteState({required this.api, required this.endpoint});

  final B2bApi api;
  final String endpoint;

  @override
  Widget build(BuildContext context) => FutureBuilder<Object?>(
        future: api.get(endpoint),
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done) {
            return const Center(
              key: ValueKey('b2b-loading'),
              child: CircularProgressIndicator(),
            );
          }
          if (snapshot.hasError) {
            return Card(
              key: const ValueKey('b2b-error'),
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Text(context.tr('b2b.remote.error')),
              ),
            );
          }

          final value = snapshot.data;
          final rows = value is Map && value['data'] is List
              ? (value['data'] as List).whereType<Map>().toList(growable: false)
              : const <Map>[];
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
            children: rows.map((row) {
              final rank = row['rank']?.toString() ?? '-';
              final name = row['name']?.toString() ?? '';
              final sku = row['sku']?.toString() ?? '';
              final quantity = row['quantity']?.toString() ?? '0';
              final total = row['total']?.toString() ?? '0';
              final currency = row['currency']?.toString() ?? 'KWD';
              return Card(
                child: ListTile(
                  key: ValueKey('b2b-top-product-$rank'),
                  title: Text('#$rank · $name'),
                  subtitle: Text('$sku · $quantity · $total $currency'),
                ),
              );
            }).toList(growable: false),
          );
        },
      );
}

class _RemoteState extends StatelessWidget {
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
  Widget build(BuildContext context) => FutureBuilder<Object?>(
        future: api.get(endpoint),
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done) {
            return const Center(
              key: ValueKey('b2b-loading'),
              child: CircularProgressIndicator(),
            );
          }

          if (snapshot.hasError) {
            final error = snapshot.error;
            final forbidden =
                error is B2bApiException && error.code == 'not_authorized';
            return Card(
              key: ValueKey(forbidden ? 'b2b-forbidden' : 'b2b-error'),
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Text(
                  context.tr(
                    forbidden
                        ? 'customer.error.forbidden'
                        : 'b2b.remote.error',
                  ),
                ),
              ),
            );
          }

          final value = snapshot.data;
          final empty = value == null ||
              (value is List && value.isEmpty) ||
              (value is Map &&
                  value['data'] is List &&
                  (value['data'] as List).isEmpty);

          if (empty) {
            return showEmpty
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
            routePattern: routePattern,
          );
        },
      );
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
      return Column(
        key: const ValueKey('b2b-authoritative-collection'),
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: rows
            .map((row) => _rowCard(context, row))
            .toList(growable: false),
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
      if (value == null) continue;

      if (value is Map) {
        widgets.add(
          Padding(
            padding: const EdgeInsets.only(top: 10, bottom: 4),
            child: Text(
              _label(key),
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
              _label(key),
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
          child: Text('${_label(key)}: ${_formatValue(value)}'),
        ),
      );
    }
    return widgets;
  }

  String _label(String key) {
    const labels = <String, String>{
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

