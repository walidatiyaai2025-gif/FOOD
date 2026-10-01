// ignore_for_file: prefer_interpolation_to_compose_strings

import 'package:flutter/material.dart';

import '../../core/api/b2c_account_api.dart';
import '../../core/localization/app_translations.dart';
import 'customer_account_data.dart';

class CustomerFavoritesScreen extends StatefulWidget {
  const CustomerFavoritesScreen({
    required this.api,
    required this.favoritesApi,
    required this.retailStoreId,
    this.onOpenProduct,
    super.key,
  });

  final B2cAccountApi api;
  final B2cRetailFavoritesApi favoritesApi;
  final int retailStoreId;
  final ValueChanged<int>? onOpenProduct;

  @override
  State<CustomerFavoritesScreen> createState() => _CustomerFavoritesScreenState();
}

class _CustomerFavoritesScreenState extends State<CustomerFavoritesScreen> {
  late Future<Object?> _future;

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  @override
  void didUpdateWidget(covariant CustomerFavoritesScreen oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.api != widget.api ||
        oldWidget.favoritesApi != widget.favoritesApi ||
        oldWidget.retailStoreId != widget.retailStoreId) {
      _reload();
    }
  }

  Future<Object?> _load() =>
      widget.favoritesApi.favoritesForStore(widget.retailStoreId);

  void _reload() => setState(() => _future = _load());

  Future<void> _remove(int productId) async {
    try {
      await widget.favoritesApi.removeFavoriteForStore(
        widget.retailStoreId,
        productId,
      );
      if (mounted) _reload();
    } catch (error) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(context.tr(customerAccountErrorKey(error)))),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      key: const ValueKey('customer-favorites-screen'),
      appBar: AppBar(
        title: Text(context.tr('customer.profile.favorites')),
      ),
      body: RefreshIndicator(
        onRefresh: () async {
          _reload();
          try {
            await _future;
          } catch (_) {}
        },
        child: FutureBuilder<Object?>(
          future: _future,
          builder: (context, snapshot) {
            if (snapshot.connectionState != ConnectionState.done) {
              return const _ScrollableState(
                child: CircularProgressIndicator(),
              );
            }
            if (snapshot.hasError) {
              return _ScrollableState(
                key: const ValueKey('customer-favorites-error'),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(context.tr(customerAccountErrorKey(snapshot.error))),
                    const SizedBox(height: 8),
                    FilledButton.tonalIcon(
                      onPressed: _reload,
                      icon: const Icon(Icons.refresh_rounded),
                      label: Text(context.tr('customer.action.retry')),
                    ),
                  ],
                ),
              );
            }

            final rows = customerAccountRows(snapshot.data);
            if (rows.isEmpty) {
              return _ScrollableState(
                key: const ValueKey('customer-favorites-empty'),
                child: Text(context.tr('customer.favorites.empty')),
              );
            }

            return ListView.separated(
              key: const ValueKey('customer-favorites-list'),
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.fromLTRB(16, 16, 16, 28),
              itemCount: rows.length,
              separatorBuilder: (_, __) => const SizedBox(height: 8),
              itemBuilder: (context, index) {
                final raw = rows[index];
                final product = raw['product'] is Map
                    ? Map<String, dynamic>.from(raw['product'] as Map)
                    : raw;
                final id = (product['id'] as num?)?.toInt() ??
                    (raw['product_id'] as num?)?.toInt();
                final name = product['name']?.toString() ??
                    product['title']?.toString() ??
                    '';
                final price = product['price'] ?? product['sale_price'];
                final currency =
                    product['currency']?.toString() ?? raw['currency']?.toString();

                return Card(
                  child: ListTile(
                    key: ValueKey('customer-favorite-' + (id?.toString() ?? 'unknown')),
                    title: Text(name),
                    subtitle: price == null
                        ? null
                        : Text(
                            price.toString() +
                                (currency == null || currency.isEmpty
                                    ? ''
                                    : ' ' + currency),
                          ),
                    onTap: id == null || widget.onOpenProduct == null
                        ? null
                        : () => widget.onOpenProduct!(id),
                    trailing: id == null
                        ? null
                        : IconButton(
                            key: ValueKey('customer-favorite-remove-' + id.toString()),
                            tooltip: context.tr('customer.addresses.delete'),
                            onPressed: () => _remove(id),
                            icon: const Icon(Icons.favorite_rounded),
                          ),
                  ),
                );
              },
            );
          },
        ),
      ),
    );
  }
}

class _ScrollableState extends StatelessWidget {
  const _ScrollableState({
    required this.child,
    super.key,
  });

  final Widget child;

  @override
  Widget build(BuildContext context) {
    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.all(24),
      children: [
        const SizedBox(height: 80),
        Center(child: child),
      ],
    );
  }
}
