import 'package:flutter/material.dart';

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
  final _search = TextEditingController();
  bool _loading = true;
  Object? _error;
  List<VanCustomerScope> _customers = const [];
  List<VanCatalogProduct> _products = const [];

  bool get _arabic => Localizations.localeOf(context).languageCode == 'ar';
  String _text(String en, String ar) => _arabic ? ar : en;

  @override
  void initState() {
    super.initState();
    _loadCustomers();
  }

  @override
  void dispose() {
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

  Future<void> _loadProducts() async {
    final customer = widget.draft.customer;
    if (customer == null) {
      if (mounted) setState(() => _loading = false);
      return;
    }
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final products = await widget.repository.catalog(
        customer,
        search: _search.text,
      );
      if (!mounted) return;
      setState(() {
        _products = products;
        _loading = false;
      });
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

  Future<void> _selectCustomer(VanCustomerScope? customer) async {
    if (customer == null) return;
    widget.draft.selectCustomer(customer);
    await _loadProducts();
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
      builder: (context, _) => ListView(
        key: const ValueKey('van-catalog-page'),
        padding: const EdgeInsets.fromLTRB(12, 10, 12, 24),
        children: [
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
          if (_loading)
            const Center(child: CircularProgressIndicator())
          else if (_error != null)
            _State(
              text: _text(
                'Unable to load the authoritative catalog.',
                'تعذر تحميل الكتالوج المعتمد.',
              ),
              action: _loadProducts,
              actionLabel: _text('Retry', 'إعادة المحاولة'),
            )
          else if (_products.isEmpty)
            _State(
              text: _text(
                'No available products match this customer and store.',
                'لا توجد منتجات متاحة مطابقة لهذا العميل والمتجر.',
              ),
              action: _loadProducts,
              actionLabel: _text('Refresh', 'تحديث'),
            )
          else
            for (final product in _products)
              Card(
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
                              style:
                                  const TextStyle(fontWeight: FontWeight.w900),
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
                              '${product.unitPrice.toStringAsFixed(3)} '
                              '${product.currency}',
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
                        onPressed:
                            product.isAvailable ? () => widget.draft.add(product) : null,
                        icon: const Icon(Icons.add),
                      ),
                    ],
                  ),
                ),
              ),
          const SizedBox(height: 6),
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
              VanActionButton.secondary(onPressed: action, child: Text(actionLabel)),
            ],
          ),
        ),
      );
}
