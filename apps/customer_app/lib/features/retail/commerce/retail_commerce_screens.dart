import 'package:flutter/material.dart';
import '../../storefront/storefront_design_system.dart';

import '../../../core/api/b2c_account_api.dart';
import '../../../core/routing/customer_routes.dart';
import '../../../shared/customer_ui_v3/customer_ui_v3.dart';
import '../../checkout/customer_checkout_vnext_components.dart';
import 'retail_commerce_api.dart';
import 'package:foodex_formatting/foodex_formatting.dart';

enum RetailAuthIntent { login, register }

typedef RetailAuthHandoff = Future<RetailCommerceApi?> Function(
    RetailAuthIntent intent, int storeId);
typedef RetailAddressHandoff = Future<void> Function(int storeId);
typedef RetailAddressEditHandoff = Future<void> Function(
    int storeId, int addressId);
typedef RetailOrderCreated = void Function(int orderId, int storeId);

class RetailCartScreen extends StatefulWidget {
  const RetailCartScreen({
    required this.storeId,
    required this.api,
    required this.isAuthenticated,
    required this.onCheckout,
    this.onAuthenticate,
    super.key,
  });

  final int storeId;
  final RetailCommerceApi api;
  final bool isAuthenticated;
  final ValueChanged<int> onCheckout;
  final RetailAuthHandoff? onAuthenticate;

  @override
  State<RetailCartScreen> createState() => _RetailCartScreenState();
}

class _RetailCartScreenState extends State<RetailCartScreen>
    with WidgetsBindingObserver {
  late RetailCommerceApi _api = widget.api;
  late bool _authenticated = widget.isAuthenticated;
  RetailCartSnapshot? _cart;
  Object? _error;
  bool _busy = true;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _reload();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed && mounted) {
      _reload();
    }
  }

  Future<void> _reload() async {
    if (mounted) {
      setState(() {
        _busy = true;
        _error = null;
      });
    }
    try {
      final cart = await _api.loadCart(storeId: widget.storeId);
      if (!mounted) return;
      setState(() => _cart = cart);
    } catch (error) {
      if (!mounted) return;
      setState(() => _error = error);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _changeQuantity(RetailCartItem item, double quantity) async {
    if (quantity <= 0) {
      await _remove(item);
      return;
    }

    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final cart = await _api.updateQuantity(
        storeId: widget.storeId,
        itemId: item.id,
        quantity: quantity,
      );
      if (mounted) setState(() => _cart = cart);
    } catch (error) {
      if (mounted) setState(() => _error = error);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _remove(RetailCartItem item) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final cart = await _api.removeItem(
        storeId: widget.storeId,
        itemId: item.id,
      );
      if (mounted) setState(() => _cart = cart);
    } catch (error) {
      if (mounted) setState(() => _error = error);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _checkout() async {
    if (_authenticated) {
      widget.onCheckout(widget.storeId);
      return;
    }

    final handoff = widget.onAuthenticate;
    if (handoff == null) {
      setState(
        () => _error = const RetailCommerceException('authentication_required'),
      );
      return;
    }

    final intent = await showModalBottomSheet<RetailAuthIntent>(
      context: context,
      showDragHandle: true,
      builder: (context) {
        final strings = _RetailCommerceStrings.of(context);
        return SafeArea(
          child: Padding(
            padding: const EdgeInsets.all(20),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(
                  strings.authRequired,
                  style: Theme.of(context).textTheme.titleLarge,
                ),
                const SizedBox(height: 16),
                FilledButton(
                  key: const ValueKey('retail-cart-login'),
                  onPressed: () =>
                      Navigator.pop(context, RetailAuthIntent.login),
                  child: Text(strings.login),
                ),
                const SizedBox(height: 8),
                OutlinedButton(
                  key: const ValueKey('retail-cart-register'),
                  onPressed: () =>
                      Navigator.pop(context, RetailAuthIntent.register),
                  child: Text(strings.register),
                ),
              ],
            ),
          ),
        );
      },
    );

    if (intent == null || !mounted) return;

    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final authenticatedApi = await handoff(intent, widget.storeId);
      if (authenticatedApi == null) return;

      _api = authenticatedApi;
      final merged = await _api.mergeGuestCartAfterAuthentication(
        storeId: widget.storeId,
      );
      if (!mounted) return;
      setState(() {
        _authenticated = true;
        _cart = merged;
      });
      widget.onCheckout(widget.storeId);
    } catch (error) {
      if (mounted) setState(() => _error = error);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final strings = _RetailCommerceStrings.of(context);
    final cart = _cart;

    if (_isOwnStoreBlocked(_error)) {
      return Scaffold(
        backgroundColor: CustomerUiColors.mint,
        body: FoodexUnifiedHeaderSurface(
          title: strings.cart,
          searchHint: context.tr('customer.products.search_name_sku_barcode'),
          child: _OwnStoreBlockedState(strings: strings),
        ),
      );
    }

    return Scaffold(
      backgroundColor: CustomerUiColors.mint,
      body: FoodexUnifiedHeaderSurface(
        title: strings.cart,
        searchHint: context.tr('customer.products.search_name_sku_barcode'),
        child: _busy && cart == null
            ? const _CommerceLoadingSkeleton()
            : RefreshIndicator(
                onRefresh: _reload,
                child: ListView(
                  physics: const AlwaysScrollableScrollPhysics(),
                  padding: const EdgeInsets.fromLTRB(
                    CustomerUiSpacing.page,
                    CustomerUiSpacing.lg,
                    CustomerUiSpacing.page,
                    CustomerUiSpacing.xxl,
                  ),
                  children: [
                    if (_error != null)
                      _ErrorBanner(
                        message: _commerceErrorText(strings, _error!),
                        retryLabel: strings.retry,
                        onRetry: _reload,
                      ),
                    if (cart == null || cart.items.isEmpty)
                      CustomerStateView(
                        kind: CustomerStateKind.empty,
                        title: strings.emptyCart,
                        icon: Icons.shopping_bag_outlined,
                      )
                    else ...[
                      for (final item in cart.items) ...[
                        CustomerCartItemCard(
                          item: item,
                          currency: cart.currency,
                          busy: _busy,
                          onMinus: () => _changeQuantity(
                            item,
                            item.quantity - 1,
                          ),
                          onPlus: () => _changeQuantity(
                            item,
                            item.quantity + 1,
                          ),
                        ),
                        const SizedBox(height: CustomerUiSpacing.sm),
                      ],
                      _CommerceSectionCard(
                        key: const ValueKey('retail-cart-summary'),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.stretch,
                          children: [
                            if (cart.syncState !=
                                RetailCartSyncState.synced) ...[
                              Container(
                                key: const ValueKey('retail-cart-sync-state'),
                                padding: const EdgeInsets.symmetric(
                                  horizontal: 12,
                                  vertical: 9,
                                ),
                                decoration: BoxDecoration(
                                  color: CustomerUiColors.warning
                                      .withValues(alpha: 0.12),
                                  borderRadius: BorderRadius.circular(12),
                                ),
                                child: Row(
                                  children: [
                                    const Icon(
                                      Icons.cloud_upload_outlined,
                                      size: 18,
                                      color: CustomerUiColors.warning,
                                    ),
                                    const SizedBox(width: 8),
                                    Expanded(
                                      child: Text(
                                        Localizations.localeOf(context)
                                                    .languageCode ==
                                                'ar'
                                            ? 'تم حفظ التغييرات محلياً · في انتظار مزامنة الإنترنت'
                                            : 'Saved locally · waiting for network sync',
                                        style: Theme.of(context)
                                            .textTheme
                                            .bodySmall
                                            ?.copyWith(
                                              color: CustomerUiColors.inkSoft,
                                              fontWeight: FontWeight.w700,
                                            ),
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                              const SizedBox(height: CustomerUiSpacing.sm),
                            ],
                            CustomerCheckoutTotalsCard(
                              key: const ValueKey('retail-cart-totals'),
                              rows: [
                                CustomerCheckoutTotalRow(
                                  label: strings.subtotal,
                                  value: foodexMoneyText(
                                    cart.subtotal,
                                    cart.currency,
                                  ),
                                ),
                                if (cart.discountTotal > 0)
                                  CustomerCheckoutTotalRow(
                                    label: strings.discount,
                                    value:
                                        '-${foodexMoneyText(cart.discountTotal, cart.currency)}',
                                  ),
                                if (cart.deliveryTotal > 0)
                                  CustomerCheckoutTotalRow(
                                    label: strings.delivery,
                                    value: foodexMoneyText(
                                      cart.deliveryTotal,
                                      cart.currency,
                                    ),
                                  ),
                                if (cart.taxTotal > 0)
                                  CustomerCheckoutTotalRow(
                                    label: strings.tax,
                                    value: foodexMoneyText(
                                      cart.taxTotal,
                                      cart.currency,
                                    ),
                                  ),
                                CustomerCheckoutTotalRow(
                                  label: strings.total,
                                  value: foodexMoneyText(
                                    cart.grandTotal,
                                    cart.currency,
                                  ),
                                  emphasized: true,
                                ),
                              ],
                            ),
                            if (cart.hasUnavailableItems) ...[
                              const SizedBox(height: CustomerUiSpacing.sm),
                              Text(
                                strings.unavailableItems,
                                style: Theme.of(context)
                                    .textTheme
                                    .bodyMedium
                                    ?.copyWith(
                                      color: CustomerUiColors.destructive,
                                    ),
                              ),
                            ],
                            const SizedBox(height: CustomerUiSpacing.lg),
                            FilledButton.icon(
                              key: const ValueKey('retail-cart-checkout'),
                              onPressed: _busy ||
                                      cart.hasUnavailableItems ||
                                      cart.syncState !=
                                          RetailCartSyncState.synced
                                  ? null
                                  : _checkout,
                              icon: const Icon(Icons.lock_outline_rounded),
                              label: Text(strings.checkout),
                            ),
                          ],
                        ),
                      ),
                    ],
                  ],
                ),
              ),
      ),
    );
  }
}

class RetailCheckoutScreen extends StatefulWidget {
  const RetailCheckoutScreen({
    required this.storeId,
    required this.api,
    this.onOrderCreated,
    this.onTrackOrder,
    this.onContinueShopping,
    this.onAddAddress,
    this.onEditAddress,
    super.key,
  });

  final int storeId;
  final RetailCommerceApi api;
  final RetailOrderCreated? onOrderCreated;
  final RetailOrderCreated? onTrackOrder;
  final VoidCallback? onContinueShopping;
  final RetailAddressHandoff? onAddAddress;
  final RetailAddressEditHandoff? onEditAddress;

  @override
  State<RetailCheckoutScreen> createState() => _RetailCheckoutScreenState();
}

class _RetailCheckoutScreenState extends State<RetailCheckoutScreen>
    with WidgetsBindingObserver {
  late final RetailCheckoutSubmissionGuard _submission =
      RetailCheckoutSubmissionGuard(widget.api);
  final TextEditingController _coupon = TextEditingController();

  RetailCheckoutOptions? _options;
  RetailCartSnapshot? _checkoutCart;
  RetailCreatedOrder? _createdOrder;
  int? _addressId;
  String? _paymentMethod;
  Object? _error;
  bool _loading = true;
  bool _submitting = false;
  bool _selectionNeedsReview = false;
  int _optionsRequestGeneration = 0;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _loadOptions();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _coupon.dispose();
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed && mounted && !_submitting) {
      _loadOptions();
    }
  }

  Future<void> _loadOptions({bool reviewNewChoices = false}) async {
    final requestGeneration = ++_optionsRequestGeneration;
    if (mounted) {
      setState(() {
        _loading = true;
        _error = null;
      });
    }

    try {
      final options = await widget.api.checkoutOptions(
        storeId: widget.storeId,
      );
      final cart = await widget.api.loadCart(storeId: widget.storeId);
      if (!mounted || requestGeneration != _optionsRequestGeneration) return;

      final hadLoadedOptions = _options != null;
      final selectedAddressId = _addressId;
      final selectedPaymentMethod = _paymentMethod;

      final addressStillEligible = selectedAddressId != null &&
          options.addresses.any((address) => address.id == selectedAddressId);
      final paymentStillEligible = selectedPaymentMethod != null &&
          options.paymentMethods.contains(selectedPaymentMethod);

      RetailCheckoutAddress? defaultAddress;
      if (!hadLoadedOptions) {
        for (final address in options.addresses) {
          if (address.isDefault) {
            defaultAddress = address;
            break;
          }
        }
      }

      final nextAddressId = hadLoadedOptions
          ? (addressStillEligible ? selectedAddressId : null)
          : defaultAddress?.id ??
              (options.addresses.isEmpty ? null : options.addresses.first.id);
      final nextPaymentMethod = hadLoadedOptions
          ? (paymentStillEligible ? selectedPaymentMethod : null)
          : options.paymentMethods.isEmpty
              ? null
              : options.paymentMethods.first;

      final lostSelection = hadLoadedOptions &&
          ((selectedAddressId != null && !addressStillEligible) ||
              (selectedPaymentMethod != null && !paymentStillEligible));
      final hasNewUnselectedChoices = reviewNewChoices &&
          ((selectedAddressId == null && options.addresses.isNotEmpty) ||
              (selectedPaymentMethod == null &&
                  options.paymentMethods.isNotEmpty));

      setState(() {
        _options = options;
        _checkoutCart = cart;
        _addressId = nextAddressId;
        _paymentMethod = nextPaymentMethod;
        _selectionNeedsReview = lostSelection || hasNewUnselectedChoices;
      });
    } catch (error) {
      if (mounted && requestGeneration == _optionsRequestGeneration) {
        setState(() => _error = error);
      }
    } finally {
      if (mounted && requestGeneration == _optionsRequestGeneration) {
        setState(() => _loading = false);
      }
    }
  }

  void _selectAddress(int? addressId) {
    setState(() {
      _addressId = addressId;
      _clearCheckoutFieldError();
      _clearSelectionReviewIfComplete();
    });
  }

  void _selectPayment(String? paymentMethod) {
    setState(() {
      _paymentMethod = paymentMethod;
      _clearCheckoutFieldError();
      _clearSelectionReviewIfComplete();
    });
  }

  void _clearCheckoutFieldError() {
    final error = _error;
    if (error is RetailCommerceException &&
        error.code == 'checkout_fields_required') {
      _error = null;
    }
  }

  void _clearSelectionReviewIfComplete() {
    final paymentMethod = _paymentMethod;
    if (_addressId != null &&
        paymentMethod != null &&
        paymentMethod.isNotEmpty) {
      _selectionNeedsReview = false;
    }
  }

  Future<void> _addAddress() async {
    final handoff = widget.onAddAddress;
    if (handoff == null) return;
    await handoff(widget.storeId);
    if (mounted) await _loadOptions(reviewNewChoices: true);
  }

  Future<void> _editAddress(int addressId) async {
    final handoff = widget.onEditAddress;
    if (handoff == null) return;
    await handoff(widget.storeId, addressId);
    if (mounted) await _loadOptions();
  }

  Future<void> _submit() async {
    final addressId = _addressId;
    final payment = _paymentMethod;
    final options = _options;
    final addressEligible = addressId != null &&
        options != null &&
        options.addresses.any((address) => address.id == addressId);
    final paymentEligible = payment != null &&
        payment.isNotEmpty &&
        options != null &&
        options.paymentMethods.contains(payment);

    if (!addressEligible || !paymentEligible || _selectionNeedsReview) {
      setState(() {
        _selectionNeedsReview = true;
        _error = const RetailCommerceException(
          'checkout_fields_required',
        );
      });
      return;
    }

    if (_submitting) return;
    setState(() {
      _submitting = true;
      _error = null;
    });

    try {
      final order = await _submission.submit(
        storeId: widget.storeId,
        addressId: addressId,
        paymentMethod: payment,
        couponCode: _coupon.text,
      );
      if (!mounted) return;
      setState(() => _createdOrder = order);
      widget.onOrderCreated?.call(order.id, order.storeId);
    } catch (error) {
      if (mounted) setState(() => _error = error);
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final strings = _RetailCommerceStrings.of(context);
    final options = _options;

    final createdOrder = _createdOrder;
    if (createdOrder != null) {
      return Scaffold(
        key: const ValueKey('retail-order-success-screen'),
        backgroundColor: CustomerUiColors.mint,
        body: FoodexUnifiedHeaderSurface(
          title: strings.orderSuccess,
          searchHint: context.tr('customer.products.search_name_sku_barcode'),
          child: CustomerOrderSuccessPanel(
            title: strings.orderSuccess,
            message: strings.orderSuccessMessage,
            orderReferenceLabel: strings.orderReference,
            orderReference: createdOrder.reference,
            trackLabel: strings.trackOrder,
            continueLabel: strings.continueShopping,
            onTrack: () => widget.onTrackOrder?.call(
              createdOrder.id,
              createdOrder.storeId,
            ),
            onContinue: () => widget.onContinueShopping?.call(),
            summary: CustomerCheckoutTotalsCard(
              rows: [
                CustomerCheckoutTotalRow(
                  label: strings.subtotal,
                  value: foodexMoneyText(
                    createdOrder.subtotal,
                    createdOrder.currency,
                  ),
                ),
                if (createdOrder.discountTotal > 0)
                  CustomerCheckoutTotalRow(
                    label: strings.discount,
                    value:
                        '-${foodexMoneyText(createdOrder.discountTotal, createdOrder.currency)}',
                  ),
                if (createdOrder.deliveryTotal > 0)
                  CustomerCheckoutTotalRow(
                    label: strings.delivery,
                    value: foodexMoneyText(
                      createdOrder.deliveryTotal,
                      createdOrder.currency,
                    ),
                  ),
                if (createdOrder.taxTotal > 0)
                  CustomerCheckoutTotalRow(
                    label: strings.tax,
                    value: foodexMoneyText(
                      createdOrder.taxTotal,
                      createdOrder.currency,
                    ),
                  ),
                CustomerCheckoutTotalRow(
                  label: strings.total,
                  value: foodexMoneyText(
                    createdOrder.grandTotal,
                    createdOrder.currency,
                  ),
                  emphasized: true,
                ),
              ],
            ),
          ),
        ),
      );
    }

    if (_isOwnStoreBlocked(_error)) {
      return Scaffold(
        backgroundColor: CustomerUiColors.mint,
        body: FoodexUnifiedHeaderSurface(
          title: strings.checkout,
          searchHint: context.tr('customer.products.search_name_sku_barcode'),
          child: _OwnStoreBlockedState(strings: strings),
        ),
      );
    }

    return Scaffold(
      backgroundColor: CustomerUiColors.mint,
      body: FoodexUnifiedHeaderSurface(
        title: strings.checkout,
        searchHint: context.tr('customer.products.search_name_sku_barcode'),
        child: _loading
            ? const _CheckoutLoadingSkeleton()
            : ListView(
                padding: const EdgeInsets.fromLTRB(
                  CustomerUiSpacing.page,
                  CustomerUiSpacing.lg,
                  CustomerUiSpacing.page,
                  CustomerUiSpacing.xxl,
                ),
                children: [
                  CustomerCheckoutProgress(
                    key: const ValueKey('retail-checkout-progress'),
                    steps: [
                      CustomerCheckoutStep(
                        label: strings.address,
                        icon: Icons.location_on_outlined,
                      ),
                      CustomerCheckoutStep(
                        label: strings.delivery,
                        icon: Icons.local_shipping_outlined,
                      ),
                      CustomerCheckoutStep(
                        label: strings.paymentMethod,
                        icon: Icons.payments_outlined,
                      ),
                      CustomerCheckoutStep(
                        label: strings.review,
                        icon: Icons.fact_check_outlined,
                      ),
                    ],
                  ),
                  const SizedBox(height: CustomerUiSpacing.md),
                  if (_error != null)
                    _ErrorBanner(
                      message: _commerceErrorText(strings, _error!),
                      retryLabel: strings.retry,
                      onRetry: _loadOptions,
                    ),
                  if (_selectionNeedsReview)
                    _CheckoutSelectionNotice(
                      message: strings.checkoutOptionsChanged,
                    ),
                  _CommerceSectionCard(
                    key: const ValueKey('retail-checkout-address-section'),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        _SectionTitle(
                          icon: Icons.location_on_outlined,
                          label: strings.deliveryAddress,
                        ),
                        const SizedBox(height: CustomerUiSpacing.sm),
                        if (options == null || options.addresses.isEmpty)
                          CustomerStateView(
                            kind: CustomerStateKind.empty,
                            title: strings.noAddresses,
                            icon: Icons.add_location_alt_outlined,
                          )
                        else
                          RadioGroup<int>(
                            groupValue: _addressId,
                            onChanged: (value) {
                              if (!_submitting) _selectAddress(value);
                            },
                            child: Column(
                              children: [
                                for (final address in options.addresses)
                                  Padding(
                                    padding: const EdgeInsets.only(
                                      bottom: CustomerUiSpacing.xs,
                                    ),
                                    child: Material(
                                      color: CustomerUiColors.white,
                                      clipBehavior: Clip.antiAlias,
                                      shape: RoundedRectangleBorder(
                                        borderRadius: BorderRadius.circular(
                                          CustomerUiRadii.md,
                                        ),
                                        side: BorderSide(
                                          color: _addressId == address.id
                                              ? CustomerUiColors.lime
                                              : CustomerUiColors.border,
                                          width: _addressId == address.id
                                              ? CustomerUiStroke.emphasis
                                              : CustomerUiStroke.hairline,
                                        ),
                                      ),
                                      child: RadioListTile<int>(
                                        key: ValueKey(
                                          'retail-checkout-address-${address.id}',
                                        ),
                                        value: address.id,
                                        enabled: !_submitting,
                                        title: Text(
                                          address.label.isEmpty
                                              ? strings.address
                                              : address.label,
                                        ),
                                        subtitle: Text(
                                          '${address.line1} · ${address.city}',
                                        ),
                                        secondary: widget.onEditAddress == null
                                            ? null
                                            : IconButton(
                                                onPressed: () =>
                                                    _editAddress(address.id),
                                                icon: const Icon(
                                                  Icons.edit_outlined,
                                                ),
                                              ),
                                      ),
                                    ),
                                  ),
                              ],
                            ),
                          ),
                        if (widget.onAddAddress != null)
                          TextButton.icon(
                            key: const ValueKey(
                              'retail-checkout-add-address',
                            ),
                            onPressed: _addAddress,
                            icon: const Icon(
                              Icons.add_location_alt_outlined,
                            ),
                            label: Text(strings.addAddress),
                          ),
                      ],
                    ),
                  ),
                  const SizedBox(height: CustomerUiSpacing.md),
                  _CommerceSectionCard(
                    key: const ValueKey('retail-checkout-payment-section'),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        _SectionTitle(
                          icon: Icons.payments_outlined,
                          label: strings.paymentMethod,
                        ),
                        const SizedBox(height: CustomerUiSpacing.sm),
                        KeyedSubtree(
                          key: const ValueKey(
                            'retail-checkout-payment-method',
                          ),
                          child: DropdownButtonFormField<String>(
                            key: ValueKey(
                              'retail-checkout-payment-value-${_paymentMethod ?? 'none'}',
                            ),
                            initialValue: _paymentMethod,
                            isExpanded: true,
                            decoration: InputDecoration(
                              labelText: strings.paymentMethod,
                              prefixIcon: const Icon(
                                Icons.account_balance_wallet_outlined,
                              ),
                            ),
                            items: (options?.paymentMethods ?? const <String>[])
                                .map(
                                  (method) => DropdownMenuItem<String>(
                                    value: method,
                                    child: Text(
                                      _paymentLabel(strings, method),
                                      maxLines: 1,
                                      overflow: TextOverflow.ellipsis,
                                    ),
                                  ),
                                )
                                .toList(growable: false),
                            onChanged: _submitting ? null : _selectPayment,
                          ),
                        ),
                        const SizedBox(height: CustomerUiSpacing.md),
                        TextField(
                          key: const ValueKey('retail-checkout-coupon'),
                          controller: _coupon,
                          textCapitalization: TextCapitalization.characters,
                          decoration: InputDecoration(
                            labelText: strings.coupon,
                            prefixIcon: const Icon(Icons.local_offer_outlined),
                          ),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: CustomerUiSpacing.md),
                  if (_checkoutCart != null)
                    CustomerCheckoutTotalsCard(
                      key: const ValueKey('retail-checkout-review'),
                      rows: [
                        CustomerCheckoutTotalRow(
                          label: strings.subtotal,
                          value: foodexMoneyText(
                            _checkoutCart!.subtotal,
                            _checkoutCart!.currency,
                          ),
                        ),
                        CustomerCheckoutTotalRow(
                          label: strings.total,
                          value: foodexMoneyText(
                            _checkoutCart!.grandTotal,
                            _checkoutCart!.currency,
                          ),
                          emphasized: true,
                        ),
                      ],
                    ),
                  const SizedBox(height: CustomerUiSpacing.lg),
                  FilledButton.icon(
                    key: const ValueKey('retail-checkout-submit'),
                    onPressed: _submitting ? null : _submit,
                    icon: _submitting
                        ? const SizedBox.square(
                            dimension: 18,
                            child: CircularProgressIndicator(
                              strokeWidth: 2,
                            ),
                          )
                        : const Icon(Icons.shopping_bag_outlined),
                    label: Text(
                      _submitting
                          ? strings.checkoutInProgress
                          : strings.placeOrder,
                    ),
                  ),
                ],
              ),
      ),
    );
  }
}

class _CheckoutSelectionNotice extends StatelessWidget {
  const _CheckoutSelectionNotice({required this.message});

  final String message;

  @override
  Widget build(BuildContext context) => Semantics(
        liveRegion: true,
        container: true,
        child: Container(
          key: const ValueKey('retail-checkout-options-changed'),
          margin: const EdgeInsets.only(bottom: CustomerUiSpacing.md),
          padding: const EdgeInsets.all(CustomerUiSpacing.md),
          decoration: BoxDecoration(
            color: CustomerUiColors.warning.withValues(alpha: 0.10),
            borderRadius: BorderRadius.circular(CustomerUiRadii.md),
            border: Border.all(
              color: CustomerUiColors.warning.withValues(alpha: 0.28),
            ),
          ),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Icon(
                Icons.info_outline_rounded,
                color: CustomerUiColors.warning,
              ),
              const SizedBox(width: CustomerUiSpacing.sm),
              Expanded(
                child: Text(
                  message,
                  style: Theme.of(context).textTheme.bodyMedium,
                ),
              ),
            ],
          ),
        ),
      );
}

class _CommerceSectionCard extends StatelessWidget {
  const _CommerceSectionCard({
    required this.child,
    super.key,
  });

  final Widget child;

  @override
  Widget build(BuildContext context) => DecoratedBox(
        decoration: BoxDecoration(
          color: CustomerUiColors.white,
          borderRadius: BorderRadius.circular(CustomerUiRadii.xl),
          border: Border.all(color: CustomerUiColors.border),
        ),
        child: Padding(
          padding: const EdgeInsets.all(CustomerUiSpacing.lg),
          child: child,
        ),
      );
}

class _SectionTitle extends StatelessWidget {
  const _SectionTitle({
    required this.icon,
    required this.label,
  });

  final IconData icon;
  final String label;

  @override
  Widget build(BuildContext context) => Row(
        children: [
          DecoratedBox(
            decoration: const BoxDecoration(
              color: CustomerUiColors.limeSoft,
              shape: BoxShape.circle,
            ),
            child: SizedBox.square(
              dimension: 40,
              child: Icon(
                icon,
                color: CustomerUiColors.deepGreenStrong,
                size: 21,
              ),
            ),
          ),
          const SizedBox(width: CustomerUiSpacing.sm),
          Expanded(
            child: Text(
              label,
              style: Theme.of(context).textTheme.titleMedium,
            ),
          ),
        ],
      );
}

class CustomerCartItemCard extends StatelessWidget {
  const CustomerCartItemCard({
    required this.item,
    required this.currency,
    required this.busy,
    required this.onMinus,
    required this.onPlus,
    super.key,
  });

  final RetailCartItem item;
  final String currency;
  final bool busy;
  final VoidCallback onMinus;
  final VoidCallback onPlus;

  @override
  Widget build(BuildContext context) => _CommerceSectionCard(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.center,
              children: [
                DecoratedBox(
                  decoration: BoxDecoration(
                    color: CustomerUiColors.mint,
                    borderRadius: BorderRadius.circular(CustomerUiRadii.md),
                  ),
                  child: const SizedBox.square(
                    dimension: 58,
                    child: Icon(
                      Icons.shopping_basket_outlined,
                      color: CustomerUiColors.deepGreenSoft,
                    ),
                  ),
                ),
                const SizedBox(width: CustomerUiSpacing.sm),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        item.name,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: Theme.of(context).textTheme.titleMedium,
                      ),
                      const SizedBox(height: CustomerUiSpacing.xxs),
                      Text(
                        foodexMoneyText(item.lineTotal, currency),
                        style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                              color: CustomerUiColors.deepGreenStrong,
                              fontWeight: FontWeight.w700,
                            ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
            const SizedBox(height: CustomerUiSpacing.sm),
            Align(
              alignment: AlignmentDirectional.centerEnd,
              child: CustomerQuantityStepper(
                valueText: item.quantity.toStringAsFixed(0),
                decreaseKey: ValueKey('retail-cart-minus-${item.id}'),
                increaseKey: ValueKey('retail-cart-plus-${item.id}'),
                busy: busy,
                onDecrease: onMinus,
                onIncrease: onPlus,
              ),
            ),
          ],
        ),
      );
}

bool _isOwnStoreBlocked(Object? error) {
  return switch (error) {
    RetailCommerceException(:final code) =>
      code == 'SELF_STORE_PURCHASE_NOT_ALLOWED',
    B2cAccountException(:final code) =>
      code == 'SELF_STORE_PURCHASE_NOT_ALLOWED',
    _ => false,
  };
}

class _OwnStoreBlockedState extends StatelessWidget {
  const _OwnStoreBlockedState({required this.strings});

  final _RetailCommerceStrings strings;

  @override
  Widget build(BuildContext context) => KeyedSubtree(
        key: const ValueKey('retail-own-store-blocked'),
        child: CustomerStateView(
          kind: CustomerStateKind.error,
          title: strings.ownStoreBlocked,
          message: strings.ownStoreBlockedBody,
          actionLabel: strings.backMarketplace,
          onAction: () => Navigator.of(context).pushNamedAndRemoveUntil(
            CustomerRoutePaths.marketplace,
            (route) => false,
          ),
          icon: Icons.storefront_outlined,
        ),
      );
}

class _CommerceLoadingSkeleton extends StatelessWidget {
  const _CommerceLoadingSkeleton();

  @override
  Widget build(BuildContext context) => ListView(
        padding: const EdgeInsets.all(CustomerUiSpacing.page),
        children: const [
          CustomerSkeletonBox(
            height: 112,
            radius: CustomerUiRadii.xl,
          ),
          SizedBox(height: CustomerUiSpacing.sm),
          CustomerSkeletonBox(
            height: 112,
            radius: CustomerUiRadii.xl,
          ),
          SizedBox(height: CustomerUiSpacing.sm),
          CustomerSkeletonBox(
            height: 132,
            radius: CustomerUiRadii.xl,
          ),
        ],
      );
}

class _CheckoutLoadingSkeleton extends StatelessWidget {
  const _CheckoutLoadingSkeleton();

  @override
  Widget build(BuildContext context) => ListView(
        padding: const EdgeInsets.all(CustomerUiSpacing.page),
        children: const [
          CustomerSkeletonBox(
            height: 220,
            radius: CustomerUiRadii.xl,
          ),
          SizedBox(height: CustomerUiSpacing.md),
          CustomerSkeletonBox(
            height: 190,
            radius: CustomerUiRadii.xl,
          ),
          SizedBox(height: CustomerUiSpacing.lg),
          CustomerSkeletonBox(
            height: 52,
            radius: CustomerUiRadii.pill,
          ),
        ],
      );
}

class _ErrorBanner extends StatelessWidget {
  const _ErrorBanner({
    required this.message,
    this.onRetry,
    this.retryLabel,
  });

  final String message;
  final VoidCallback? onRetry;
  final String? retryLabel;

  @override
  Widget build(BuildContext context) => Container(
        margin: const EdgeInsets.only(bottom: CustomerUiSpacing.md),
        padding: const EdgeInsets.all(CustomerUiSpacing.md),
        decoration: BoxDecoration(
          color: CustomerUiColors.destructive.withValues(alpha: 0.08),
          borderRadius: BorderRadius.circular(CustomerUiRadii.md),
          border: Border.all(
            color: CustomerUiColors.destructive.withValues(alpha: 0.18),
          ),
        ),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Icon(
              Icons.error_outline_rounded,
              color: CustomerUiColors.destructive,
            ),
            const SizedBox(width: CustomerUiSpacing.sm),
            Expanded(
              child: Text(
                message,
                style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                      color: CustomerUiColors.destructive,
                    ),
              ),
            ),
            if (onRetry != null) ...[
              const SizedBox(width: CustomerUiSpacing.xs),
              IconButton(
                key: const ValueKey('retail-commerce-retry'),
                tooltip: retryLabel,
                onPressed: onRetry,
                icon: const Icon(Icons.refresh_rounded),
                color: CustomerUiColors.destructive,
              ),
            ],
          ],
        ),
      );
}

String _commerceErrorText(_RetailCommerceStrings strings, Object error) {
  if (error is RetailCommerceException) {
    if (error.fieldErrors.isNotEmpty) {
      return error.fieldErrors.values.expand((items) => items).join('\n');
    }
    return switch (error.code) {
      'authentication_required' => strings.authRequired,
      'checkout_fields_required' => strings.completeCheckoutFields,
      'checkout_in_progress' => strings.checkoutInProgress,
      'network_unavailable' || 'network_retry' || 'offline' => strings.offline,
      'payment_failed' || 'payment_declined' => strings.paymentFailed,
      'payment_cancelled' || 'payment_canceled' => strings.paymentCancelled,
      'cart_store_mismatch' ||
      'checkout_store_mismatch' =>
        strings.storeContextError,
      _ => '${strings.requestFailed}: ${error.code}',
    };
  }
  return strings.requestFailed;
}

String _paymentLabel(_RetailCommerceStrings strings, String method) =>
    switch (method) {
      'cash_on_delivery' => strings.cashOnDelivery,
      'knet' => 'KNET',
      'card' || 'card_online' => strings.card,
      _ => method,
    };

class _RetailCommerceStrings {
  const _RetailCommerceStrings({
    required this.cart,
    required this.emptyCart,
    required this.checkout,
    required this.total,
    required this.unavailableItems,
    required this.authRequired,
    required this.login,
    required this.register,
    required this.deliveryAddress,
    required this.delivery,
    required this.review,
    required this.subtotal,
    required this.discount,
    required this.tax,
    required this.orderSuccess,
    required this.orderSuccessMessage,
    required this.orderReference,
    required this.trackOrder,
    required this.continueShopping,
    required this.noAddresses,
    required this.address,
    required this.addAddress,
    required this.paymentMethod,
    required this.coupon,
    required this.placeOrder,
    required this.completeCheckoutFields,
    required this.checkoutOptionsChanged,
    required this.checkoutInProgress,
    required this.storeContextError,
    required this.requestFailed,
    required this.retry,
    required this.offline,
    required this.paymentFailed,
    required this.paymentCancelled,
    required this.cashOnDelivery,
    required this.card,
    required this.ownStoreBlocked,
    required this.ownStoreBlockedBody,
    required this.backMarketplace,
  });

  final String cart;
  final String emptyCart;
  final String checkout;
  final String total;
  final String unavailableItems;
  final String authRequired;
  final String login;
  final String register;
  final String deliveryAddress;
  final String delivery;
  final String review;
  final String subtotal;
  final String discount;
  final String tax;
  final String orderSuccess;
  final String orderSuccessMessage;
  final String orderReference;
  final String trackOrder;
  final String continueShopping;
  final String noAddresses;
  final String address;
  final String addAddress;
  final String paymentMethod;
  final String coupon;
  final String placeOrder;
  final String completeCheckoutFields;
  final String checkoutOptionsChanged;
  final String checkoutInProgress;
  final String storeContextError;
  final String requestFailed;
  final String retry;
  final String offline;
  final String paymentFailed;
  final String paymentCancelled;
  final String cashOnDelivery;
  final String card;
  final String ownStoreBlocked;
  final String ownStoreBlockedBody;
  final String backMarketplace;

  static _RetailCommerceStrings of(BuildContext context) {
    final ar = Localizations.localeOf(context).languageCode == 'ar';
    return ar ? arabic : english;
  }

  static const arabic = _RetailCommerceStrings(
    cart: 'السلة',
    emptyCart: 'السلة فارغة',
    checkout: 'إتمام الطلب',
    total: 'الإجمالي',
    unavailableItems: 'يوجد منتج غير متاح. راجع السلة قبل المتابعة.',
    authRequired: 'سجل الدخول أو أنشئ حسابًا لإكمال الطلب.',
    login: 'تسجيل الدخول',
    register: 'إنشاء حساب',
    deliveryAddress: 'عنوان التوصيل',
    delivery: 'التوصيل',
    review: 'مراجعة الطلب',
    subtotal: 'الإجمالي الفرعي',
    discount: 'الخصم',
    tax: 'الضريبة',
    orderSuccess: 'تم تأكيد طلبك',
    orderSuccessMessage: 'تم إنشاء الطلب بنجاح ويمكنك متابعة حالته الآن.',
    orderReference: 'رقم الطلب',
    trackOrder: 'تتبع الطلب',
    continueShopping: 'متابعة التسوق',
    noAddresses: 'أضف عنوان توصيل للمتابعة.',
    address: 'العنوان',
    addAddress: 'إضافة عنوان',
    paymentMethod: 'طريقة الدفع',
    coupon: 'كود الخصم',
    placeOrder: 'تأكيد الطلب',
    completeCheckoutFields: 'اختر عنوان التوصيل وطريقة الدفع.',
    checkoutOptionsChanged:
        'تم تحديث خيارات إتمام الطلب. راجع عنوان التوصيل وطريقة الدفع قبل التأكيد.',
    checkoutInProgress: 'جاري إرسال الطلب بالفعل.',
    storeContextError: 'تعذر الحفاظ على سياق المتجر. أعد فتح السلة من المتجر.',
    requestFailed: 'تعذر تنفيذ الطلب',
    retry: 'إعادة المحاولة',
    offline: 'لا يوجد اتصال بالإنترنت. تحقق من الشبكة ثم أعد المحاولة.',
    paymentFailed: 'تعذر إتمام الدفع. اختر طريقة دفع أخرى أو أعد المحاولة.',
    paymentCancelled: 'تم إلغاء عملية الدفع. يمكنك المحاولة مرة أخرى بأمان.',
    cashOnDelivery: 'الدفع عند الاستلام',
    card: 'بطاقة',
    ownStoreBlocked: 'لا يمكن الشراء من متجرك',
    ownStoreBlockedBody:
        'يمكنك إدارة هذا المتجر من لوحة الإدارة، لكن لا يمكنك الشراء منه بحساب المالك أو المدير.',
    backMarketplace: 'العودة إلى المتاجر',
  );

  static const english = _RetailCommerceStrings(
    cart: 'Cart',
    emptyCart: 'Your cart is empty',
    checkout: 'Checkout',
    total: 'Total',
    unavailableItems:
        'An item is unavailable. Review the cart before continuing.',
    authRequired: 'Sign in or create an account to continue checkout.',
    login: 'Sign in',
    register: 'Create account',
    deliveryAddress: 'Delivery address',
    delivery: 'Delivery',
    review: 'Review',
    subtotal: 'Subtotal',
    discount: 'Discount',
    tax: 'Tax',
    orderSuccess: 'Order confirmed',
    orderSuccessMessage:
        'Your order was created successfully and is ready to track.',
    orderReference: 'Order number',
    trackOrder: 'Track order',
    continueShopping: 'Continue shopping',
    noAddresses: 'Add a delivery address to continue.',
    address: 'Address',
    addAddress: 'Add address',
    paymentMethod: 'Payment method',
    coupon: 'Coupon code',
    placeOrder: 'Place order',
    completeCheckoutFields: 'Choose a delivery address and payment method.',
    checkoutOptionsChanged:
        'Checkout options changed. Review the delivery address and payment method before placing the order.',
    checkoutInProgress: 'This order is already being submitted.',
    storeContextError:
        'Store context was lost. Reopen the cart from the store.',
    requestFailed: 'Request failed',
    retry: 'Retry',
    offline: 'You are offline. Check your connection and try again.',
    paymentFailed:
        'Payment could not be completed. Try again or choose another payment method.',
    paymentCancelled: 'Payment was cancelled. You can safely try again.',
    cashOnDelivery: 'Cash on delivery',
    card: 'Card',
    ownStoreBlocked: 'You cannot purchase from your own store',
    ownStoreBlockedBody:
        'You can manage this store from the Dashboard, but an owner or manager account cannot purchase from it.',
    backMarketplace: 'Back to marketplace',
  );
}
