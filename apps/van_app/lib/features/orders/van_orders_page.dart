import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/auth/van_session.dart';
import '../../core/theme/foodex_van_theme.dart';
import '../wallet/van_wallet_contract.dart';
import 'van_order_contract.dart';

class VanOrdersPage extends StatefulWidget {
  const VanOrdersPage({
    super.key,
    required this.repository,
    required this.customerRepository,
    required this.onSessionExpired,
  });

  final VanOrderRepository repository;
  final VanWalletRepository customerRepository;
  final Future<void> Function() onSessionExpired;

  @override
  State<VanOrdersPage> createState() => _VanOrdersPageState();
}

class _VanOrdersPageState extends State<VanOrdersPage>
    with WidgetsBindingObserver {
  bool _loading = true;
  bool _refreshing = false;
  bool _stale = false;
  Object? _error;
  List<VanOrderRecord> _orders = const [];
  Map<String, String> _customerNames = const {};

  bool get _arabic => Localizations.localeOf(context).languageCode == 'ar';
  String _text(String en, String ar) => _arabic ? ar : en;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _load();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed && !_loading && !_refreshing) {
      unawaited(_load(background: true));
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  Future<void> _load({bool background = false}) async {
    if (mounted) {
      setState(() {
        if (background && _orders.isNotEmpty) {
          _refreshing = true;
        } else {
          _loading = true;
        }
        _error = null;
      });
    }
    try {
      final results = await Future.wait<Object>([
        widget.repository.orders(),
        widget.customerRepository.customers(),
      ]);
      final orders = results[0] as List<VanOrderRecord>;
      final customers = results[1] as List<VanCustomerScope>;
      if (!mounted) return;
      setState(() {
        _orders = orders;
        _customerNames = {
          for (final customer in customers)
            '${customer.type}:${customer.id}': customer.name,
        };
        _loading = false;
        _refreshing = false;
        _stale = false;
      });
    } on VanSessionExpiredException {
      await widget.onSessionExpired();
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _refreshing = false;
        if (background && _orders.isNotEmpty) {
          _stale = true;
        } else {
          _error = error;
        }
      });
    }
  }

  String _status(String value) {
    switch (value) {
      case 'pending':
        return _text('Pending', 'قيد المراجعة');
      case 'confirmed':
        return _text('Confirmed', 'مؤكد');
      case 'ready':
        return _text('Ready', 'جاهز');
      case 'delivered':
        return _text('Delivered', 'تم التسليم');
      case 'cancelled':
        return _text('Cancelled', 'ملغي');
      default:
        return value.replaceAll('_', ' ');
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_loading && _orders.isEmpty) {
      return const Center(child: CircularProgressIndicator());
    }
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        key: const ValueKey('van-orders-page'),
        padding: const EdgeInsets.fromLTRB(12, 10, 12, 24),
        children: [
          if (_refreshing || _stale)
            Container(
              margin: const EdgeInsets.only(bottom: 10),
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: FoodexVanTokens.surface,
                border: Border.all(color: FoodexVanTokens.border),
                borderRadius: BorderRadius.circular(14),
              ),
              child: Text(
                _stale
                    ? _text(
                        'Showing the last confirmed order list.',
                        'يتم عرض آخر قائمة طلبات مؤكدة.',
                      )
                    : _text('Refreshing orders…', 'جارٍ تحديث الطلبات…'),
              ),
            ),
          if (_error != null && _orders.isEmpty)
            _state(
              _text('Unable to load orders.', 'تعذر تحميل الطلبات.'),
            )
          else if (_orders.isEmpty)
            _state(_text('No Van orders yet.', 'لا توجد طلبات فان بعد.'))
          else
            for (final order in _orders)
              Card(
                key: ValueKey('van-order-${order.id}'),
                elevation: 0,
                margin: const EdgeInsets.only(bottom: 8),
                child: ListTile(
                  leading: const CircleAvatar(
                    backgroundColor: FoodexVanTokens.mint,
                    child: Icon(
                      Icons.receipt_long_outlined,
                      color: FoodexVanTokens.green,
                    ),
                  ),
                  title: Text(
                    order.orderNumber,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(fontWeight: FontWeight.w900),
                  ),
                  subtitle: Text(
                    _customerNames[
                            '${order.customerType}:${order.customerId}'] ??
                        _text('Assigned customer', 'عميل مسند'),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                  trailing: ConstrainedBox(
                    constraints: const BoxConstraints(maxWidth: 135),
                    child: Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      crossAxisAlignment: CrossAxisAlignment.end,
                      children: [
                        Text(
                          '${order.grandTotal.toStringAsFixed(3)} '
                          '${order.currency}',
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style:
                              const TextStyle(fontWeight: FontWeight.w900),
                        ),
                        Text(
                          _status(order.status),
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: Theme.of(context)
                              .textTheme
                              .bodySmall
                              ?.copyWith(color: FoodexVanTokens.green),
                        ),
                      ],
                    ),
                  ),
                ),
              ),
        ],
      ),
    );
  }

  Widget _state(String text) => Card(
        elevation: 0,
        child: Padding(
          padding: const EdgeInsets.all(18),
          child: Column(
            children: [
              Text(text, textAlign: TextAlign.center),
              const SizedBox(height: 10),
              OutlinedButton(
                onPressed: _load,
                child: Text(_text('Refresh', 'تحديث')),
              ),
            ],
          ),
        ),
      );
}
