import 'dart:async';

import 'package:flutter/material.dart';
import 'package:foodex_visualization/foodex_visualization.dart';

import '../../core/auth/van_session.dart';
import '../../core/theme/foodex_van_theme.dart';
import '../wallet/van_wallet_contract.dart';
import 'van_order_contract.dart';
import 'van_order_detail_page.dart';
import 'package:foodex_formatting/foodex_formatting.dart';

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
  static const int _pageSize = 50;

  bool _loading = true;
  bool _refreshing = false;
  bool _loadingMore = false;
  bool _stale = false;
  Object? _error;
  Object? _loadMoreError;
  List<VanOrderRecord> _orders = const [];
  Map<String, String> _customerNames = const {};
  String _filter = 'all';
  int _currentPage = 0;
  int _total = 0;
  int _requestGeneration = 0;

  bool get _hasMore => _orders.length < _total;

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
    if (state == AppLifecycleState.resumed &&
        !_loading &&
        !_refreshing &&
        !_loadingMore) {
      unawaited(_load(background: true));
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  Future<void> _load({bool background = false}) async {
    if (_loadingMore) return;
    final generation = ++_requestGeneration;
    final targetPage = _currentPage > 0 ? _currentPage : 1;

    if (mounted) {
      setState(() {
        if (background && _orders.isNotEmpty) {
          _refreshing = true;
        } else {
          _loading = true;
        }
        _error = null;
        _loadMoreError = null;
      });
    }

    try {
      final results = await Future.wait<Object>([
        _loadOrderPages(targetPage),
        widget.customerRepository.customers(),
      ]);
      final history = results[0] as _VanOrderHistorySnapshot;
      final customers = results[1] as List<VanCustomerScope>;
      if (!mounted || generation != _requestGeneration) return;
      setState(() {
        _orders = history.items;
        _currentPage = history.currentPage;
        _total = history.total;
        _customerNames = {
          for (final customer in customers)
            '${customer.type}:${customer.id}': customer.name,
        };
        _loading = false;
        _refreshing = false;
        _stale = false;
      });
    } on VanSessionExpiredException {
      if (generation != _requestGeneration) return;
      await widget.onSessionExpired();
    } catch (error) {
      if (!mounted || generation != _requestGeneration) return;
      setState(() {
        _loading = false;
        _refreshing = false;
        if (_orders.isNotEmpty) {
          _stale = true;
        } else {
          _error = error;
        }
      });
    }
  }

  Future<_VanOrderHistorySnapshot> _loadOrderPages(int targetPage) async {
    final byId = <int, VanOrderRecord>{};
    var currentPage = 0;
    var total = 0;

    for (var requestedPage = 1;
        requestedPage <= targetPage;
        requestedPage++) {
      final page = await widget.repository.orders(
        page: requestedPage,
        perPage: _pageSize,
      );
      for (final order in page.items) {
        byId[order.id] = order;
      }
      currentPage = page.currentPage;
      total = page.total;
      if (!page.hasMore) break;
    }

    return _VanOrderHistorySnapshot(
      items: byId.values.toList(growable: false),
      currentPage: currentPage,
      total: total,
    );
  }

  Future<void> _loadMore() async {
    if (_loading || _refreshing || _loadingMore || !_hasMore) return;
    final generation = _requestGeneration;
    setState(() {
      _loadingMore = true;
      _loadMoreError = null;
    });

    try {
      final page = await widget.repository.orders(
        page: _currentPage + 1,
        perPage: _pageSize,
      );
      if (!mounted || generation != _requestGeneration) return;
      final byId = <int, VanOrderRecord>{
        for (final order in _orders) order.id: order,
      };
      for (final order in page.items) {
        byId[order.id] = order;
      }
      setState(() {
        _orders = byId.values.toList(growable: false);
        _currentPage = page.currentPage;
        _total = page.total;
        _loadingMore = false;
      });
    } on VanSessionExpiredException {
      if (generation != _requestGeneration) return;
      if (mounted) {
        setState(() => _loadingMore = false);
      }
      await widget.onSessionExpired();
    } catch (error) {
      if (!mounted || generation != _requestGeneration) return;
      setState(() {
        _loadingMore = false;
        _loadMoreError = error;
      });
    }
  }

  String _effectiveStatus(VanOrderRecord order) =>
      order.vanExecutionStatus?.trim().isNotEmpty == true
          ? order.vanExecutionStatus!
          : order.status;

  bool _matchesFilter(VanOrderRecord order) {
    final status = _effectiveStatus(order);
    switch (_filter) {
      case 'new':
        return {'assigned', 'pending', 'confirmed'}.contains(status);
      case 'active':
        return {'accepted', 'picked_up'}.contains(status);
      case 'ready':
        return status == 'ready';
      case 'ofd':
        return status == 'out_for_delivery';
      case 'failed':
        return status == 'failed';
      case 'completed':
        return {'delivered', 'completed'}.contains(status);
      default:
        return true;
    }
  }

  List<VanOrderRecord> get _filteredOrders =>
      _orders.where(_matchesFilter).toList(growable: false);

  String _filterLabel(String value) {
    switch (value) {
      case 'new':
        return _text('New', 'جديد');
      case 'active':
        return _text('Active', 'نشط');
      case 'ready':
        return _text('Ready', 'جاهز');
      case 'ofd':
        return _text('OFD', 'للتسليم');
      case 'failed':
        return _text('Failed', 'متعذر');
      case 'completed':
        return _text('Completed', 'مكتمل');
      default:
        return _text('All', 'الكل');
    }
  }

  Future<void> _openDetail(VanOrderRecord order) async {
    await Navigator.of(context).push<void>(
      MaterialPageRoute(
        builder: (_) => VanOrderDetailPage(
          orderId: order.id,
          repository: widget.repository,
          walletRepository: widget.customerRepository,
          onSessionExpired: widget.onSessionExpired,
        ),
      ),
    );
    if (mounted) {
      unawaited(_load(background: true));
    }
  }

  int get _activeCount => _orders.where((order) {
        return !{
          'delivered',
          'completed',
          'cancelled',
          'refunded',
          'failed',
        }.contains(order.status);
      }).length;

  int get _completedCount => _orders
      .where((order) => {'delivered', 'completed'}.contains(order.status))
      .length;

  int get _exceptionCount => _orders
      .where((order) => {'cancelled', 'refunded', 'failed'}.contains(order.status))
      .length;

  double get _ordersValue =>
      _orders.fold(0, (sum, order) => sum + order.grandTotal);

  String get _ordersCurrency {
    final currencies = _orders.map((order) => order.currency).toSet();
    return currencies.length == 1 ? currencies.first : '';
  }

  Widget _analytics(BuildContext context) => Card(
        key: const ValueKey('van-orders-analytics'),
        elevation: 0,
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(
                _text('Order outcomes', 'نتائج الطلبات'),
                style: Theme.of(context).textTheme.titleMedium?.copyWith(
                      fontWeight: FontWeight.w900,
                    ),
              ),
              const SizedBox(height: 4),
              Text(
                _text(
                  'Which orders still need attention, and what has completed?',
                  'ما الطلبات التي ما زالت تحتاج متابعة وما الذي اكتمل؟',
                ),
                style: Theme.of(context).textTheme.bodySmall,
              ),
              const SizedBox(height: 12),
              Align(
                alignment: AlignmentDirectional.centerStart,
                child: Text(
                  _ordersCurrency.isEmpty
                      ? _ordersValue.toStringAsFixed(3)
                      : foodexMoneyText(_ordersValue, _ordersCurrency),
                  style: Theme.of(context).textTheme.titleLarge?.copyWith(
                        fontWeight: FontWeight.w900,
                      ),
                ),
              ),
              const SizedBox(height: 10),
              FoodexDonutChart(
                key: const ValueKey('van-orders-status-chart'),
                values: [
                  _activeCount.toDouble(),
                  _completedCount.toDouble(),
                  _exceptionCount.toDouble(),
                ],
                semanticLabel: _text(
                  'Active, completed and exception order distribution',
                  'توزيع الطلبات النشطة والمكتملة والاستثنائية',
                ),
                size: 134,
                strokeWidth: 18,
              ),
              const SizedBox(height: 10),
              FoodexChartLegend(
                labels: [
                  _text('Active', 'نشط'),
                  _text('Completed', 'مكتمل'),
                  _text('Exceptions', 'استثناءات'),
                ],
              ),
            ],
          ),
        ),
      );

  String _statusLabel(String value) {
    switch (value) {
      case 'assigned':
        return _text('Assigned', 'مسند');
      case 'pending':
        return _text('Pending', 'قيد المراجعة');
      case 'confirmed':
        return _text('Confirmed', 'مؤكد');
      case 'ready':
        return _text('Ready', 'جاهز');
      case 'accepted':
        return _text('Accepted', 'مقبول');
      case 'picked_up':
        return _text('Picked up', 'تم الاستلام');
      case 'out_for_delivery':
        return _text('Out for delivery', 'خرج للتسليم');
      case 'failed':
        return _text('Failed', 'تعذر التسليم');
      case 'delivered':
        return _text('Delivered', 'تم التسليم');
      case 'completed':
        return _text('Completed', 'مكتمل');
      case 'cancelled':
        return _text('Cancelled', 'ملغي');
      default:
        return value.replaceAll('_', ' ');
    }
  }

  Widget _orderCard(VanOrderRecord order) => Card(
        key: ValueKey('van-order-${order.id}'),
        elevation: 0,
        margin: const EdgeInsets.only(bottom: 8),
        child: ListTile(
          onTap: () => _openDetail(order),
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
            _customerNames['${order.customerType}:${order.customerId}'] ??
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
                  foodexMoneyText(order.grandTotal, order.currency),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(fontWeight: FontWeight.w900),
                ),
                Text(
                  _statusLabel(_effectiveStatus(order)),
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
      );

  Widget _paginationProgress() => Semantics(
        liveRegion: true,
        child: Padding(
          padding: const EdgeInsets.only(bottom: 10),
          child: Text(
            _text(
              'Showing ${_orders.length} of $_total orders',
              'يتم عرض ${_orders.length} من أصل $_total طلب',
            ),
            key: const ValueKey('van-orders-page-progress'),
            style: Theme.of(context).textTheme.bodySmall,
          ),
        ),
      );

  Widget _paginationFooter() {
    if (_loadingMore) {
      return Semantics(
        liveRegion: true,
        child: Padding(
          key: const ValueKey('van-orders-loading-more'),
          padding: const EdgeInsets.symmetric(vertical: 12),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              const SizedBox(
                width: 18,
                height: 18,
                child: CircularProgressIndicator(strokeWidth: 2),
              ),
              const SizedBox(width: 10),
              Text(_text('Loading more orders…', 'جارٍ تحميل طلبات إضافية…')),
            ],
          ),
        ),
      );
    }

    if (_loadMoreError != null) {
      return Card(
        key: const ValueKey('van-orders-load-more-error'),
        elevation: 0,
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(
            children: [
              Text(
                _text(
                  'Unable to load more orders.',
                  'تعذر تحميل طلبات إضافية.',
                ),
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: 8),
              OutlinedButton(
                key: const ValueKey('van-orders-load-more-retry'),
                onPressed: _loadMore,
                child: Text(_text('Retry', 'إعادة المحاولة')),
              ),
            ],
          ),
        ),
      );
    }

    if (_hasMore) {
      return Padding(
        padding: const EdgeInsets.symmetric(vertical: 8),
        child: OutlinedButton(
          key: const ValueKey('van-orders-load-more'),
          onPressed: _loadMore,
          child: Text(_text('Load more orders', 'تحميل طلبات إضافية')),
        ),
      );
    }

    return Semantics(
      liveRegion: true,
      child: Padding(
        key: const ValueKey('van-orders-end'),
        padding: const EdgeInsets.symmetric(vertical: 12),
        child: Text(
          _text(
            'All $_total orders are loaded.',
            'تم تحميل جميع الطلبات وعددها $_total.',
          ),
          textAlign: TextAlign.center,
          style: Theme.of(context).textTheme.bodySmall,
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    if (_loading && _orders.isEmpty) {
      return const Center(child: CircularProgressIndicator());
    }

    final filteredOrders = _filteredOrders;
    final prefix = <Widget>[
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
        _state(_text('Unable to load orders.', 'تعذر تحميل الطلبات.'))
      else if (_orders.isEmpty)
        _state(_text('No Van orders yet.', 'لا توجد طلبات فان بعد.'))
      else ...[
        _analytics(context),
        const SizedBox(height: 10),
        SingleChildScrollView(
          key: const ValueKey('van-orders-filters'),
          scrollDirection: Axis.horizontal,
          child: Row(
            children: [
              for (final value in const [
                'all',
                'new',
                'active',
                'ready',
                'ofd',
                'failed',
                'completed',
              ]) ...[
                ChoiceChip(
                  key: ValueKey('van-orders-filter-$value'),
                  label: Text(_filterLabel(value)),
                  selected: _filter == value,
                  onSelected: (_) => setState(() => _filter = value),
                ),
                const SizedBox(width: 6),
              ],
            ],
          ),
        ),
        const SizedBox(height: 10),
        _paginationProgress(),
        if (filteredOrders.isEmpty)
          _state(
            _hasMore
                ? _text(
                    'No matching orders in the loaded history. Load more to continue searching.',
                    'لا توجد طلبات مطابقة ضمن السجل المحمّل. حمّل المزيد لمتابعة البحث.',
                  )
                : _text(
                    'No orders match this filter.',
                    'لا توجد طلبات مطابقة لهذا الفلتر.',
                  ),
          ),
      ],
    ];

    final includePagination = _orders.isNotEmpty;
    final itemCount = prefix.length +
        filteredOrders.length +
        (includePagination ? 1 : 0);

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView.builder(
        key: const ValueKey('van-orders-page'),
        padding: const EdgeInsets.fromLTRB(12, 10, 12, 24),
        physics: const AlwaysScrollableScrollPhysics(),
        itemCount: itemCount,
        itemBuilder: (context, index) {
          if (index < prefix.length) {
            return prefix[index];
          }

          final orderIndex = index - prefix.length;
          if (orderIndex < filteredOrders.length) {
            return _orderCard(filteredOrders[orderIndex]);
          }

          return _paginationFooter();
        },
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

class _VanOrderHistorySnapshot {
  const _VanOrderHistorySnapshot({
    required this.items,
    required this.currentPage,
    required this.total,
  });

  final List<VanOrderRecord> items;
  final int currentPage;
  final int total;
}
