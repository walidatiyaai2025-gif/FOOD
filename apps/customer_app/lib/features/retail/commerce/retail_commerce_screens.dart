import 'package:flutter/material.dart';

import 'retail_commerce_api.dart';

enum RetailAuthIntent { login, register }

typedef RetailAuthHandoff =
    Future<RetailCommerceApi?> Function(RetailAuthIntent intent, int storeId);
typedef RetailAddressHandoff = Future<void> Function(int storeId);
typedef RetailAddressEditHandoff =
    Future<void> Function(int storeId, int addressId);
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

class _RetailCartScreenState extends State<RetailCartScreen> {
  late RetailCommerceApi _api = widget.api;
  late bool _authenticated = widget.isAuthenticated;
  RetailCartSnapshot? _cart;
  Object? _error;
  bool _busy = true;

  @override
  void initState() {
    super.initState();
    _reload();
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
        () => _error =
            const RetailCommerceException('authentication_required'),
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

    return Scaffold(
      appBar: AppBar(title: Text(strings.cart)),
      body: SafeArea(
        child: _busy && cart == null
            ? const Center(child: CircularProgressIndicator())
            : RefreshIndicator(
                onRefresh: _reload,
                child: ListView(
                  physics: const AlwaysScrollableScrollPhysics(),
                  padding: const EdgeInsets.all(16),
                  children: [
                    if (_error != null)
                      _ErrorBanner(
                        message: _commerceErrorText(strings, _error!),
                      ),
                    if (cart == null || cart.items.isEmpty)
                      Padding(
                        padding: const EdgeInsets.symmetric(vertical: 72),
                        child: Center(child: Text(strings.emptyCart)),
                      )
                    else ...[
                      for (final item in cart.items)
                        Card(
                          child: ListTile(
                            title: Text(item.name),
                            subtitle: Text(
                              '${item.lineTotal.toStringAsFixed(3)} ${cart.currency}',
                            ),
                            trailing: Wrap(
                              crossAxisAlignment: WrapCrossAlignment.center,
                              children: [
                                IconButton(
                                  key: ValueKey('retail-cart-minus-${item.id}'),
                                  onPressed: _busy
                                      ? null
                                      : () => _changeQuantity(
                                            item,
                                            item.quantity - 1,
                                          ),
                                  icon: const Icon(Icons.remove),
                                ),
                                Text(item.quantity.toStringAsFixed(0)),
                                IconButton(
                                  key: ValueKey('retail-cart-plus-${item.id}'),
                                  onPressed: _busy
                                      ? null
                                      : () => _changeQuantity(
                                            item,
                                            item.quantity + 1,
                                          ),
                                  icon: const Icon(Icons.add),
                                ),
                              ],
                            ),
                          ),
                        ),
                      const SizedBox(height: 12),
                      Text(
                        '${strings.total}: ${cart.grandTotal.toStringAsFixed(3)} ${cart.currency}',
                        style: Theme.of(context).textTheme.titleMedium,
                      ),
                      if (cart.hasUnavailableItems)
                        Padding(
                          padding: const EdgeInsets.only(top: 8),
                          child: Text(
                            strings.unavailableItems,
                            style: TextStyle(
                              color: Theme.of(context).colorScheme.error,
                            ),
                          ),
                        ),
                      const SizedBox(height: 16),
                      FilledButton(
                        key: const ValueKey('retail-cart-checkout'),
                        onPressed:
                            _busy || cart.hasUnavailableItems ? null : _checkout,
                        child: Text(strings.checkout),
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
    required this.onOrderCreated,
    this.onAddAddress,
    this.onEditAddress,
    super.key,
  });

  final int storeId;
  final RetailCommerceApi api;
  final RetailOrderCreated onOrderCreated;
  final RetailAddressHandoff? onAddAddress;
  final RetailAddressEditHandoff? onEditAddress;

  @override
  State<RetailCheckoutScreen> createState() => _RetailCheckoutScreenState();
}

class _RetailCheckoutScreenState extends State<RetailCheckoutScreen> {
  late final RetailCheckoutSubmissionGuard _submission =
      RetailCheckoutSubmissionGuard(widget.api);
  final TextEditingController _coupon = TextEditingController();

  RetailCheckoutOptions? _options;
  int? _addressId;
  String? _paymentMethod;
  Object? _error;
  bool _loading = true;
  bool _submitting = false;

  @override
  void initState() {
    super.initState();
    _loadOptions();
  }

  @override
  void dispose() {
    _coupon.dispose();
    super.dispose();
  }

  Future<void> _loadOptions() async {
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
      if (!mounted) return;

      RetailCheckoutAddress? defaultAddress;
      for (final address in options.addresses) {
        if (address.isDefault) {
          defaultAddress = address;
          break;
        }
      }

      setState(() {
        _options = options;
        _addressId = defaultAddress?.id ??
            (options.addresses.isEmpty ? null : options.addresses.first.id);
        _paymentMethod = options.paymentMethods.isEmpty
            ? null
            : options.paymentMethods.first;
      });
    } catch (error) {
      if (mounted) setState(() => _error = error);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _addAddress() async {
    final handoff = widget.onAddAddress;
    if (handoff == null) return;
    await handoff(widget.storeId);
    if (mounted) await _loadOptions();
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
    if (addressId == null || payment == null || payment.isEmpty) {
      setState(
        () => _error = const RetailCommerceException(
          'checkout_fields_required',
        ),
      );
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
      widget.onOrderCreated(order.id, order.storeId);
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

    return Scaffold(
      appBar: AppBar(title: Text(strings.checkout)),
      body: SafeArea(
        child: _loading
            ? const Center(child: CircularProgressIndicator())
            : ListView(
                padding: const EdgeInsets.all(16),
                children: [
                  if (_error != null)
                    _ErrorBanner(
                      message: _commerceErrorText(strings, _error!),
                    ),
                  Text(
                    strings.deliveryAddress,
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                  const SizedBox(height: 8),
                  if (options == null || options.addresses.isEmpty)
                    Text(strings.noAddresses)
                  else
                    RadioGroup<int>(
                      groupValue: _addressId,
                      onChanged: (value) =>
                          setState(() => _addressId = value),
                      child: Column(
                        children: [
                          for (final address in options.addresses)
                            RadioListTile<int>(
                              key: ValueKey(
                                'retail-checkout-address-${address.id}',
                              ),
                              value: address.id,
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
                                      icon:
                                          const Icon(Icons.edit_outlined),
                                    ),
                            ),
                        ],
                      ),
                    ),
                  if (widget.onAddAddress != null)
                    TextButton.icon(
                      key: const ValueKey('retail-checkout-add-address'),
                      onPressed: _addAddress,
                      icon: const Icon(Icons.add_location_alt_outlined),
                      label: Text(strings.addAddress),
                    ),
                  const SizedBox(height: 16),
                  DropdownButtonFormField<String>(
                    key: const ValueKey('retail-checkout-payment-method'),
                    initialValue: _paymentMethod,
                    decoration: InputDecoration(
                      labelText: strings.paymentMethod,
                      border: const OutlineInputBorder(),
                    ),
                    items: (options?.paymentMethods ?? const <String>[])
                        .map(
                          (method) => DropdownMenuItem<String>(
                            value: method,
                            child: Text(_paymentLabel(strings, method)),
                          ),
                        )
                        .toList(growable: false),
                    onChanged: _submitting
                        ? null
                        : (value) =>
                            setState(() => _paymentMethod = value),
                  ),
                  const SizedBox(height: 16),
                  TextField(
                    key: const ValueKey('retail-checkout-coupon'),
                    controller: _coupon,
                    textCapitalization: TextCapitalization.characters,
                    decoration: InputDecoration(
                      labelText: strings.coupon,
                      border: const OutlineInputBorder(),
                    ),
                  ),
                  const SizedBox(height: 20),
                  FilledButton(
                    key: const ValueKey('retail-checkout-submit'),
                    onPressed: _submitting ? null : _submit,
                    child: _submitting
                        ? const SizedBox.square(
                            dimension: 22,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : Text(strings.placeOrder),
                  ),
                ],
              ),
      ),
    );
  }
}

class _ErrorBanner extends StatelessWidget {
  const _ErrorBanner({required this.message});

  final String message;

  @override
  Widget build(BuildContext context) => Container(
        margin: const EdgeInsets.only(bottom: 12),
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: Theme.of(context).colorScheme.errorContainer,
          borderRadius: BorderRadius.circular(12),
        ),
        child: Text(message),
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
      'cart_store_mismatch' || 'checkout_store_mismatch' =>
        strings.storeContextError,
      _ => '${strings.requestFailed}: ${error.code}',
    };
  }
  return strings.requestFailed;
}

String _paymentLabel(_RetailCommerceStrings strings, String method) => switch (
      method) {
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
    required this.noAddresses,
    required this.address,
    required this.addAddress,
    required this.paymentMethod,
    required this.coupon,
    required this.placeOrder,
    required this.completeCheckoutFields,
    required this.checkoutInProgress,
    required this.storeContextError,
    required this.requestFailed,
    required this.cashOnDelivery,
    required this.card,
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
  final String noAddresses;
  final String address;
  final String addAddress;
  final String paymentMethod;
  final String coupon;
  final String placeOrder;
  final String completeCheckoutFields;
  final String checkoutInProgress;
  final String storeContextError;
  final String requestFailed;
  final String cashOnDelivery;
  final String card;

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
    noAddresses: 'أضف عنوان توصيل للمتابعة.',
    address: 'العنوان',
    addAddress: 'إضافة عنوان',
    paymentMethod: 'طريقة الدفع',
    coupon: 'كود الخصم',
    placeOrder: 'تأكيد الطلب',
    completeCheckoutFields: 'اختر عنوان التوصيل وطريقة الدفع.',
    checkoutInProgress: 'جاري إرسال الطلب بالفعل.',
    storeContextError: 'تعذر الحفاظ على سياق المتجر. أعد فتح السلة من المتجر.',
    requestFailed: 'تعذر تنفيذ الطلب',
    cashOnDelivery: 'الدفع عند الاستلام',
    card: 'بطاقة',
  );

  static const english = _RetailCommerceStrings(
    cart: 'Cart',
    emptyCart: 'Your cart is empty',
    checkout: 'Checkout',
    total: 'Total',
    unavailableItems: 'An item is unavailable. Review the cart before continuing.',
    authRequired: 'Sign in or create an account to continue checkout.',
    login: 'Sign in',
    register: 'Create account',
    deliveryAddress: 'Delivery address',
    noAddresses: 'Add a delivery address to continue.',
    address: 'Address',
    addAddress: 'Add address',
    paymentMethod: 'Payment method',
    coupon: 'Coupon code',
    placeOrder: 'Place order',
    completeCheckoutFields: 'Choose a delivery address and payment method.',
    checkoutInProgress: 'This order is already being submitted.',
    storeContextError: 'Store context was lost. Reopen the cart from the store.',
    requestFailed: 'Request failed',
    cashOnDelivery: 'Cash on delivery',
    card: 'Card',
  );
}
