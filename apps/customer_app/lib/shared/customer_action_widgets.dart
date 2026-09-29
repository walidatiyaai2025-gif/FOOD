import 'package:flutter/material.dart';

import '../core/api/customer_action_api.dart';
import '../core/auth/customer_session.dart';
import '../core/localization/app_translations.dart';

typedef CustomerAuthenticated = void Function(
  CustomerChannel channel,
  String token,
);

class CustomerLoginAction extends StatefulWidget {
  const CustomerLoginAction({
    required this.channel,
    required this.api,
    required this.onAuthenticated,
    required this.successRoute,
    this.onPlatformAuthenticated,
    super.key,
  });

  final CustomerChannel channel;
  final CustomerActionApi api;
  final CustomerAuthenticated onAuthenticated;
  final ValueChanged<String>? onPlatformAuthenticated;
  final String successRoute;

  @override
  State<CustomerLoginAction> createState() => _CustomerLoginActionState();
}

class _CustomerLoginActionState extends State<CustomerLoginAction> {
  final _username = TextEditingController();
  final _password = TextEditingController();
  bool _busy = false;
  String? _error;

  bool get _requiresPassword => widget.api is HttpCustomerActionApi;

  @override
  void dispose() {
    _username.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (_username.text.trim().isEmpty) {
      setState(() => _error = 'customer.validation.username');
      return;
    }
    if (_requiresPassword && _password.text.isEmpty) {
      setState(() => _error = 'customer.validation.password');
      return;
    }

    setState(() {
      _busy = true;
      _error = null;
    });

    try {
      final result = widget.api is HttpCustomerActionApi
          ? await (widget.api as HttpCustomerActionApi).credentialLogin(
              email: _username.text.trim(),
              password: _password.text,
            )
          : await widget.api.login(
              username: _username.text.trim(),
            );
      if (!mounted) return;
      if (result.platformCustomer && widget.onPlatformAuthenticated != null) {
        widget.onPlatformAuthenticated!(result.token);
      } else {
        widget.onAuthenticated(widget.channel, result.token);
      }
      Navigator.of(context).pushReplacementNamed(widget.successRoute);
    } catch (_) {
      if (mounted) setState(() => _error = 'customer.error.action_failed');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          TextField(
            key: const ValueKey('customer-login-username'),
            controller: _username,
            textInputAction: TextInputAction.done,
            autocorrect: false,
            enableSuggestions: false,
            keyboardType: TextInputType.emailAddress,
            decoration: InputDecoration(
              labelText: _requiresPassword
                  ? context.tr('customer.login.email')
                  : context.tr('customer.login.username'),
            ),
            onSubmitted: (_) {
              if (!_requiresPassword) _submit();
            },
          ),
          if (_requiresPassword) ...[
            const SizedBox(height: 10),
            TextField(
              key: const ValueKey('customer-login-password'),
              controller: _password,
              obscureText: true,
              textInputAction: TextInputAction.done,
              decoration: InputDecoration(
                labelText: context.tr('customer.login.password'),
              ),
              onSubmitted: (_) => _submit(),
            ),
          ],
          if (_error != null) ...[
            const SizedBox(height: 8),
            Text(
              context.tr(_error!),
              key: const ValueKey('customer-action-error'),
              style: TextStyle(color: Theme.of(context).colorScheme.error),
            ),
          ],
          const SizedBox(height: 12),
          FilledButton(
            key: const ValueKey('customer-login-submit'),
            onPressed: _busy ? null : _submit,
            child: _busy
                ? const SizedBox.square(
                    dimension: 18,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : Text(context.tr('customer.action.login')),
          ),
        ],
      );
}

class AddCartAction extends StatefulWidget {
  const AddCartAction({
    required this.api,
    required this.location,
    required this.cartRoute,
    super.key,
  });

  final CustomerActionApi api;
  final String location;
  final String cartRoute;

  @override
  State<AddCartAction> createState() => _AddCartActionState();
}

class _AddCartActionState extends State<AddCartAction> {
  final _quantity = TextEditingController(text: '1');
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _quantity.dispose();
    super.dispose();
  }

  int? get _storeId {
    final uri = Uri.parse(widget.location);
    return int.tryParse(uri.queryParameters['store_id'] ?? uri.queryParameters['store'] ?? '');
  }

  int? get _productId {
    final segments = Uri.parse(widget.location).pathSegments;
    return segments.isEmpty ? null : int.tryParse(segments.last);
  }

  Future<void> _submit() async {
    final storeId = _storeId;
    final productId = _productId;
    final quantity = double.tryParse(_quantity.text.trim());
    if (storeId == null || productId == null || quantity == null || quantity <= 0) {
      setState(() => _error = storeId == null
          ? 'customer.validation.store_required'
          : 'customer.validation.quantity');
      return;
    }

    setState(() {
      _busy = true;
      _error = null;
    });

    try {
      await widget.api.addCartItem(
        storeId: storeId,
        productId: productId,
        quantity: quantity,
      );
      if (!mounted) return;
      Navigator.of(context).pushNamed(widget.cartRoute);
    } catch (_) {
      if (mounted) setState(() => _error = 'customer.error.action_failed');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (_storeId == null)
            Text(
              context.tr('customer.validation.store_required'),
              key: const ValueKey('customer-store-required'),
            ),
          TextField(
            key: const ValueKey('customer-cart-quantity'),
            controller: _quantity,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            decoration: InputDecoration(labelText: context.tr('customer.cart.quantity')),
          ),
          if (_error != null)
            Text(
              context.tr(_error!),
              key: const ValueKey('customer-action-error'),
              style: TextStyle(color: Theme.of(context).colorScheme.error),
            ),
          const SizedBox(height: 12),
          FilledButton(
            key: const ValueKey('customer-add-cart'),
            onPressed: _busy || _storeId == null || _productId == null ? null : _submit,
            child: _busy
                ? const SizedBox.square(
                    dimension: 18,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : Text(context.tr('customer.action.add_cart')),
          ),
        ],
      );
}

class CheckoutAction extends StatefulWidget {
  const CheckoutAction({
    required this.api,
    required this.channel,
    this.storeId,
    super.key,
  });

  final CustomerActionApi api;
  final CustomerChannel channel;
  final int? storeId;

  @override
  State<CheckoutAction> createState() => _CheckoutActionState();
}

class _CheckoutActionState extends State<CheckoutAction> {
  final _address = TextEditingController();
  final _payment = TextEditingController();
  final _coupon = TextEditingController();
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _address.dispose();
    _payment.dispose();
    _coupon.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final addressId = int.tryParse(_address.text.trim());
    if (addressId == null || addressId <= 0) {
      setState(() => _error = 'customer.validation.address');
      return;
    }

    setState(() {
      _busy = true;
      _error = null;
    });

    try {
      final response = await widget.api.checkout(
        addressId: addressId,
        storeId: widget.storeId,
        paymentMethod: _payment.text,
        couponCode: _coupon.text,
        idempotencyKey: 'foodex-${DateTime.now().microsecondsSinceEpoch}',
      );
      if (!mounted) return;
      final id = response is Map ? response['id'] ?? response['order_id'] : null;
      if (id is! num) {
        setState(() => _error = 'customer.error.action_failed');
        return;
      }
      final route = widget.channel == CustomerChannel.b2b
          ? '/b2b/orders/${id.toInt()}'
          : '/orders/${id.toInt()}/track';
      Navigator.of(context).pushReplacementNamed(route);
    } catch (_) {
      if (mounted) setState(() => _error = 'customer.error.action_failed');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          TextField(
            key: const ValueKey('customer-checkout-address'),
            controller: _address,
            keyboardType: TextInputType.number,
            decoration: InputDecoration(labelText: context.tr('customer.checkout.address')),
          ),
          const SizedBox(height: 10),
          TextField(
            key: const ValueKey('customer-checkout-payment'),
            controller: _payment,
            decoration: InputDecoration(labelText: context.tr('customer.checkout.payment')),
          ),
          const SizedBox(height: 10),
          TextField(
            key: const ValueKey('customer-checkout-coupon'),
            controller: _coupon,
            textCapitalization: TextCapitalization.characters,
            decoration: InputDecoration(labelText: context.tr('customer.checkout.coupon')),
          ),
          if (_error != null) ...[
            const SizedBox(height: 8),
            Text(context.tr(_error!), key: const ValueKey('customer-action-error')),
          ],
          const SizedBox(height: 12),
          FilledButton(
            key: const ValueKey('customer-checkout-submit'),
            onPressed: _busy ? null : _submit,
            child: _busy
                ? const SizedBox.square(
                    dimension: 18,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : Text(context.tr('customer.action.confirm_order')),
          ),
        ],
      );
}
