import 'package:flutter/material.dart';
import 'package:foodex_formatting/foodex_formatting.dart';
import 'package:foodex_visualization/foodex_visualization.dart';

import '../../core/auth/van_session.dart';
import '../../core/theme/foodex_van_theme.dart';
import '../../shared/van_action_button.dart';
import '../wallet/van_wallet_contract.dart';
import 'van_order_contract.dart';

class VanProductCatalogPage extends StatefulWidget {
  const VanProductCatalogPage({
    super.key,
    required this.repository,
    required this.customerRepository,
    required this.draft,
    required this.onOpenBuilder,
    required this.onSessionExpired,
  });

  final VanOrderRepository repository;
  final VanWalletRepository customerRepository;
  final VanOrderDraftController draft;
  final VoidCallback onOpenBuilder;
  final Future<void> Function() onSessionExpired;

  @override
  State<VanProductCatalogPage> createState() => _VanProductCatalogPageState();
}

class _VanProductCatalogPageState extends State<VanProductCatalogPage> {
  static const _perPage = 50;

  final _search = TextEditingController();
  bool _loading = true;
  bool _loadingMore = false;
  Object? _error;
  Object? _loadMoreError;
  List<VanCustomerScope> _customers = const [];
  List<VanCatalogProduct> _products = const [];
  int _page = 0;
  bool _hasMore = false;
  int _requestSerial = 0;

  bool get _arabic => Localizations.localeOf(context).languageCode == 'ar';
  String _text(String en, String ar) => _arabic ? ar : en;

  @override
  void initState() {
    super.initState();
    _loadCustomers();
  }

  @override
  void dispose() {
    _requestSerial += 1;
    _search.dispose();
    super.dispose();
  }

  Future<void> _loadCustomers() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final customers = await widget.customerRepository.customers();
      if (!mounted) return;
      if (customers.isNotEmpty && widget.draft.customer == null) {
        widget.draft.selectCustomer(customers.first);
      }
      setState(() => _customers = customers);
      await _loadProducts();
    } on VanSessionExpiredException {
      await widget.onSessionExpired();
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _error = error;
      });
    }
  }

  bool _sameScope(VanCustomerScope? left, VanCustomerScope right) =>
      left?.type == right.type &&
      left?.id == right.id &&
      left?.storeId == right.storeId;

  Future<void> _loadProducts({bool append = false}) async {
    final customer = widget.draft.customer;
    if (customer == null) {
      if (mounted) {
        setState(() {
          _loading = false;
          _products = const [];
          _page = 0;
          _hasMore = false;
        });
      }
      return;
    }
    if (append && (_loadingMore || !_hasMore)) return;

    final query = _search.text.trim();
    final targetPage = append ? _page + 1 : 1;
    final request = ++_requestSerial;

    setState(() {
      if (append) {
        _loadingMore = true;
        _loadMoreError = null;
      } else {
        _loading = true;
        _error = null;
        _loadMoreError = null;
        _products = const [];
        _page = 0;
        _hasMore = false;
      }
    });

    try {
      final result = await widget.repository.catalog(
        customer,
        search: query,
        page: targetPage,
        perPage: _perPage,
      );
      if (!mounted ||
          request != _requestSerial ||
          !_sameScope(widget.draft.customer, customer) ||
          _search.text.trim() != query) {
        return;
      }

      setState(() {
        if (append) {
          final byId = <int, VanCatalogProduct>{
            for (final product in _products) product.id: product,
            for (final product in result.products) product.id: product,
          };
          _products = byId.values.toList(growable: false);
        } else {
          _products = result.products;
        }
        _page = result.page;
        _hasMore = result.hasMore;
        _loading = false;
        _loadingMore = false;
      });
    } on VanSessionExpiredException {
      await widget.onSessionExpired();
    } catch (error) {
      if (!mounted || request != _requestSerial) return;
      setState(() {
        if (append) {
          _loadingMore = false;
          _loadMoreError = error;
        } else {
          _loading = false;
          _error = error;
        }
      });
    }
  }

  Future<void> _selectCustomer(VanCustomerScope? customer) async {
    if (customer == null) return;
    widget.draft.selectCustomer(customer);
    await _loadProducts();
  }

  int get _unavailableCount =>
      _products.where((product) => !product.isAvailable).length;

  int get _lowStockCount => _products.where((product) {
        final quantity = product.availableQuantity;
        return product.isAvailable &&
            quantity != null &&
            quantity <= product.minimumQuantity;
      }).length;

  int get _healthyCount =>
      _products.length - _unavailableCount - _lowStockCount;

  Widget _stockAnalytics(BuildContext context) => Card(
        key: const ValueKey('van-catalog-stock-analytics'),
        elevation: 0,
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(
                _text('Catalog availability', 'توفر الكتالوج'),
                style: Theme.of(context).textTheme.titleMedium?.copyWith(
                      fontWeight: FontWeight.w900,
                    ),
              ),
              const SizedBox(height: 4),
              Text(
                _hasMore
                    ? _text(
                        'Loaded subset only · ${_products.length} products',
                        'الجزء المحمّل فقط · ${_products.length} منتج',
                      )
                    : _text(
                        'All filtered results loaded · ${_products.length} products',
                        'تم تحميل كل النتائج المصفاة · ${_products.length} منتج',
                      ),
                style: Theme.of(context).textTheme.bodySmall,
              ),
              const SizedBox(height: 12),
              FoodexDonutChart(
                key: const ValueKey('van-catalog-stock-chart'),
                values: [
                  _healthyCount.toDouble(),
                  _lowStockCount.toDouble(),
                  _unavailableCount.toDouble(),
                ],
                semanticLabel: _text(
                  'Availability distribution for the currently loaded catalog products',
                  'توزيع التوفر لمنتجات الكتالوج المحملة حاليًا',
                ),
                size: 134,
                strokeWidth: 18,
              ),
              const SizedBox(height: 10),
              FoodexChartLegend(
                labels: [
                  _text('Available', 'متاح'),
                  _text('Low stock', 'مخزون منخفض'),
                  _text('Unavailable', 'غير متاح'),
                ],
              ),
            ],
          ),
        ),
      );

  Widget _productCard(BuildContext context, VanCatalogProduct product) => Card(
        key: ValueKey('van-catalog-product-${product.id}'),
        elevation: 0,
        margin: const EdgeInsets.only(bottom: 8),
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Row(
            children: [
              const CircleAvatar(
                backgroundColor: FoodexVanTokens.mint,
                child: Icon(
                  Icons.inventory_2_outlined,
                  color: FoodexVanTokens.green,
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      product.name,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(fontWeight: FontWeight.w900),
                    ),
                    Text(
                      product.sku,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: Theme.of(context)
                          .textTheme
                          .bodySmall
                          ?.copyWith(color: FoodexVanTokens.muted),
                    ),
                    const SizedBox(height: 4),
                    Text(
                      foodexMoneyText(product.unitPrice, product.currency),
                      style: const TextStyle(
                        color: FoodexVanTokens.green,
                        fontWeight: FontWeight.w900,
                      ),
                    ),
                  ],
                ),
              ),
              IconButton.filledTonal(
                key: ValueKey('van-catalog-add-${product.id}'),
                tooltip: _text(
                  'Add ${product.name} to cart',
                  'إضافة ${product.name} إلى السلة',
                ),
                onPressed: product.isAvailable
                    ? () => widget.draft.add(product)
                    : null,
                icon: const Icon(Icons.add),
              ),
            ],
          ),
        ),
      );

  Widget _resultStatus() => Semantics(
        liveRegion: true,
        container: true,
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: 8),
          child: Text(
            _hasMore
                ? _text(
                    'Loaded ${_products.length} products · more results available',
                    'تم تحميل ${_products.length} منتج · توجد نتائج إضافية',
                  )
                : _text(
                    'Loaded ${_products.length} products · end of results',
                    'تم تحميل ${_products.length} منتج · نهاية النتائج',
                  ),
            key: const ValueKey('van-catalog-result-status'),
            style: Theme.of(context).textTheme.bodySmall,
            textAlign: TextAlign.center,
          ),
        ),
      );

  List<Widget> _topContent(BuildContext context) {
    final content = <Widget>[
      DropdownButtonFormField<VanCustomerScope>(
        initialValue: widget.draft.customer,
        isExpanded: true,
        decoration: InputDecoration(
          labelText: _text('Customer', 'العميل'),
          border: const OutlineInputBorder(),
        ),
        items: [
          for (final customer in _customers)
            DropdownMenuItem(
              value: customer,
              child: Text(
                customer.name,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
              ),
            ),
        ],
        onChanged: _selectCustomer,
      ),
      const SizedBox(height: 10),
      TextField(
        key: const ValueKey('van-catalog-search'),
        controller: _search,
        textInputAction: TextInputAction.search,
        onSubmitted: (_) => _loadProducts(),
        decoration: InputDecoration(
          hintText: _text(
            'Search product, SKU or barcode',
            'ابحث بالمنتج أو SKU أو الباركود',
          ),
          prefixIcon: const Icon(Icons.search),
          suffixIcon: VanIconAction(
            onPressed: _loadProducts,
            icon: const Icon(Icons.arrow_forward),
          ),
          border: const OutlineInputBorder(),
        ),
      ),
      const SizedBox(height: 10),
    ];

    if (_loading) {
      content.add(const Center(child: CircularProgressIndicator()));
    } else if (_error != null) {
      content.add(
        _State(
          text: _text(
            'Unable to load the authoritative catalog.',
            'تعذر تحميل الكتالوج المعتمد.',
          ),
          action: _loadProducts,
          actionLabel: _text('Retry', 'إعادة المحاولة'),
        ),
      );
    } else if (_products.isEmpty && !_hasMore) {
      content.add(
        _State(
          text: _text(
            'No available products match this customer, store and search.',
            'لا توجد منتجات متاحة مطابقة لهذا العميل والمتجر والبحث.',
          ),
          action: _loadProducts,
          actionLabel: _text('Refresh', 'تحديث'),
        ),
      );
    } else if (_products.isNotEmpty) {
      content
        ..add(_stockAnalytics(context))
        ..add(_resultStatus());
    } else {
      content.add(_resultStatus());
    }

    return content;
  }

  List<Widget> _bottomContent() {
    final content = <Widget>[];

    if (!_loading && _error == null && _loadMoreError != null) {
      content.add(
        _State(
          text: _text(
            'Unable to load more catalog products. Your loaded products and cart are unchanged.',
            'تعذر تحميل المزيد من منتجات الكتالوج. المنتجات المحملة والسلة لم تتغير.',
          ),
          action: () => _loadProducts(append: true),
          actionLabel: _text('Retry load more', 'إعادة تحميل المزيد'),
        ),
      );
    } else if (_loadingMore) {
      content.add(
        const Padding(
          padding: EdgeInsets.symmetric(vertical: 12),
          child: Center(child: CircularProgressIndicator()),
        ),
      );
    } else if (!_loading && _error == null && _hasMore) {
      content.add(
        VanActionButton.secondary(
          onPressed: () => _loadProducts(append: true),
          child: Text(_text('Load more products', 'تحميل المزيد من المنتجات')),
        ),
      );
    }

    content
      ..add(const SizedBox(height: 10))
      ..add(
        VanActionButton.icon(
          key: const ValueKey('van-catalog-open-cart'),
          onPressed: widget.draft.lines.isEmpty ? null : widget.onOpenBuilder,
          icon: const Icon(Icons.shopping_cart_checkout),
          label: Text(
            _text(
              'Open cart · ${widget.draft.lines.length} items',
              'فتح السلة · ${widget.draft.lines.length} أصناف',
            ),
          ),
        ),
      );

    return content;
  }

  @override
  Widget build(BuildContext context) {
    if (_loading && _customers.isEmpty) {
      return const Center(child: CircularProgressIndicator());
    }
    if (_customers.isEmpty) {
      return _State(
        text: _text('No assigned customers', 'لا يوجد عملاء مسندون'),
        action: _loadCustomers,
        actionLabel: _text('Refresh', 'تحديث'),
      );
    }

    return AnimatedBuilder(
      animation: widget.draft,
      builder: (context, _) => CustomScrollView(
        key: const ValueKey('van-catalog-page'),
        slivers: [
          SliverPadding(
            padding: const EdgeInsets.fromLTRB(12, 10, 12, 0),
            sliver: SliverList(
              delegate: SliverChildListDelegate(_topContent(context)),
            ),
          ),
          if (!_loading && _error == null && _products.isNotEmpty)
            SliverPadding(
              padding: const EdgeInsets.symmetric(horizontal: 12),
              sliver: SliverList(
                delegate: SliverChildBuilderDelegate(
                  (context, index) => _productCard(context, _products[index]),
                  childCount: _products.length,
                ),
              ),
            ),
          SliverPadding(
            padding: const EdgeInsets.fromLTRB(12, 0, 12, 24),
            sliver: SliverList(
              delegate: SliverChildListDelegate(_bottomContent()),
            ),
          ),
        ],
      ),
    );
  }
}

class _State extends StatelessWidget {
  const _State({
    required this.text,
    required this.action,
    required this.actionLabel,
  });

  final String text;
  final Future<void> Function() action;
  final String actionLabel;

  @override
  Widget build(BuildContext context) => Card(
        elevation: 0,
        child: Padding(
          padding: const EdgeInsets.all(18),
          child: Column(
            children: [
              Text(text, textAlign: TextAlign.center),
              const SizedBox(height: 10),
              VanActionButton.secondary(
                onPressed: action,
                child: Text(actionLabel),
              ),
            ],
          ),
        ),
      );
}
