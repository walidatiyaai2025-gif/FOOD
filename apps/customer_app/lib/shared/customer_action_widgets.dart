import 'package:flutter/material.dart';

import '../core/api/customer_action_api.dart';
import '../core/auth/customer_session.dart';
import '../core/localization/app_translations.dart';

typedef CustomerAuthenticated = void Function(
  CustomerChannel channel,
  String token,
);
typedef PlatformCustomerRegistered = void Function(String token);

class CustomerLoginAction extends StatefulWidget {
  const CustomerLoginAction({
    required this.channel,
    required this.api,
    required this.onAuthenticated,
    required this.successRoute,
    super.key,
  });

  final CustomerChannel channel;
  final CustomerActionApi api;
  final CustomerAuthenticated onAuthenticated;
  final String successRoute;

  @override
  State<CustomerLoginAction> createState() => _CustomerLoginActionState();
}

class _CustomerLoginActionState extends State<CustomerLoginAction> {
  final _username = TextEditingController();
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _username.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (_username.text.trim().isEmpty) {
      setState(() => _error = 'customer.validation.username');
      return;
    }

    setState(() {
      _busy = true;
      _error = null;
    });

    try {
      final result = await widget.api.login(
        username: _username.text.trim(),
      );
      if (!mounted) return;
      widget.onAuthenticated(widget.channel, result.token);
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
            decoration: InputDecoration(labelText: context.tr('customer.login.username')),
            onSubmitted: (_) => _submit(),
          ),
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

class CustomerPasswordLoginAction extends StatefulWidget {
  const CustomerPasswordLoginAction({
    required this.api,
    required this.onAuthenticated,
    required this.successRoute,
    super.key,
  });

  final CustomerActionApi api;
  final PlatformCustomerRegistered onAuthenticated;
  final String successRoute;

  @override
  State<CustomerPasswordLoginAction> createState() =>
      _CustomerPasswordLoginActionState();
}

class _CustomerPasswordLoginActionState
    extends State<CustomerPasswordLoginAction> {
  final _email = TextEditingController();
  final _password = TextEditingController();
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (_email.text.trim().isEmpty || _password.text.isEmpty) {
      setState(() => _error = 'customer.validation.credentials');
      return;
    }

    setState(() {
      _busy = true;
      _error = null;
    });

    try {
      final result = await widget.api.loginWithPassword(
        email: _email.text,
        password: _password.text,
      );
      if (!mounted) return;
      widget.onAuthenticated(result.token);
      Navigator.of(context).pushNamedAndRemoveUntil(
        widget.successRoute,
        (route) => false,
      );
    } catch (_) {
      if (mounted) setState(() => _error = 'customer.error.login_failed');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => AutofillGroup(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            TextField(
              key: const ValueKey('customer-password-login-email'),
              controller: _email,
              keyboardType: TextInputType.emailAddress,
              textInputAction: TextInputAction.next,
              autofillHints: const [AutofillHints.email],
              decoration: InputDecoration(
                labelText: context.tr('customer.login.email'),
                prefixIcon: const Icon(Icons.email_outlined),
              ),
            ),
            const SizedBox(height: 10),
            TextField(
              key: const ValueKey('customer-password-login-password'),
              controller: _password,
              obscureText: true,
              textInputAction: TextInputAction.done,
              autofillHints: const [AutofillHints.password],
              decoration: InputDecoration(
                labelText: context.tr('customer.login.password'),
                prefixIcon: const Icon(Icons.lock_outline_rounded),
              ),
              onSubmitted: (_) => _submit(),
            ),
            if (_error != null) ...[
              const SizedBox(height: 8),
              Text(
                context.tr(_error!),
                key: const ValueKey('customer-password-login-error'),
                style: TextStyle(color: Theme.of(context).colorScheme.error),
              ),
            ],
            const SizedBox(height: 14),
            FilledButton.icon(
              key: const ValueKey('customer-password-login-submit'),
              onPressed: _busy ? null : _submit,
              icon: const Icon(Icons.login_rounded),
              label: _busy
                  ? const SizedBox.square(
                      dimension: 18,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : Text(context.tr('customer.action.login')),
            ),
          ],
        ),
      );
}

class CustomerRegistrationAction extends StatefulWidget {
  const CustomerRegistrationAction({
    required this.api,
    required this.onRegistered,
    required this.successRoute,
    super.key,
  });

  final CustomerActionApi api;
  final PlatformCustomerRegistered onRegistered;
  final String successRoute;

  @override
  State<CustomerRegistrationAction> createState() =>
      _CustomerRegistrationActionState();
}

class _CustomerRegistrationActionState
    extends State<CustomerRegistrationAction> {
  final _name = TextEditingController();
  final _email = TextEditingController();
  final _phone = TextEditingController();
  final _password = TextEditingController();
  final _confirmation = TextEditingController();
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _name.dispose();
    _email.dispose();
    _phone.dispose();
    _password.dispose();
    _confirmation.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final name = _name.text.trim();
    final email = _email.text.trim();
    final password = _password.text;
    final confirmation = _confirmation.text;
    if (name.isEmpty || email.isEmpty || password.length < 8) {
      setState(() => _error = 'customer.validation.registration');
      return;
    }
    if (password != confirmation) {
      setState(() => _error = 'customer.validation.password_confirmation');
      return;
    }

    setState(() {
      _busy = true;
      _error = null;
    });

    try {
      final result = await widget.api.register(
        name: name,
        email: email,
        phone: _phone.text.trim(),
        password: password,
        passwordConfirmation: confirmation,
        locale: Localizations.localeOf(context).languageCode,
      );
      if (!mounted) return;
      widget.onRegistered(result.token);
      Navigator.of(context).pushNamedAndRemoveUntil(
        widget.successRoute,
        (route) => false,
      );
    } catch (_) {
      if (mounted) setState(() => _error = 'customer.error.registration_failed');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => AutofillGroup(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            TextField(
              key: const ValueKey('customer-register-name'),
              controller: _name,
              textInputAction: TextInputAction.next,
              autofillHints: const [AutofillHints.name],
              decoration: InputDecoration(
                labelText: context.tr('customer.register.name'),
                prefixIcon: const Icon(Icons.person_outline_rounded),
              ),
            ),
            const SizedBox(height: 10),
            TextField(
              key: const ValueKey('customer-register-email'),
              controller: _email,
              keyboardType: TextInputType.emailAddress,
              textInputAction: TextInputAction.next,
              autofillHints: const [AutofillHints.email],
              decoration: InputDecoration(
                labelText: context.tr('customer.register.email'),
                prefixIcon: const Icon(Icons.email_outlined),
              ),
            ),
            const SizedBox(height: 10),
            TextField(
              key: const ValueKey('customer-register-phone'),
              controller: _phone,
              keyboardType: TextInputType.phone,
              textInputAction: TextInputAction.next,
              autofillHints: const [AutofillHints.telephoneNumber],
              decoration: InputDecoration(
                labelText: context.tr('customer.register.phone'),
                prefixIcon: const Icon(Icons.phone_outlined),
              ),
            ),
            const SizedBox(height: 10),
            TextField(
              key: const ValueKey('customer-register-password'),
              controller: _password,
              obscureText: true,
              textInputAction: TextInputAction.next,
              autofillHints: const [AutofillHints.newPassword],
              decoration: InputDecoration(
                labelText: context.tr('customer.register.password'),
                prefixIcon: const Icon(Icons.lock_outline_rounded),
              ),
            ),
            const SizedBox(height: 10),
            TextField(
              key: const ValueKey('customer-register-confirmation'),
              controller: _confirmation,
              obscureText: true,
              textInputAction: TextInputAction.done,
              autofillHints: const [AutofillHints.newPassword],
              decoration: InputDecoration(
                labelText: context.tr('customer.register.password_confirmation'),
                prefixIcon: const Icon(Icons.lock_reset_rounded),
              ),
              onSubmitted: (_) => _submit(),
            ),
            if (_error != null) ...[
              const SizedBox(height: 8),
              Text(
                context.tr(_error!),
                key: const ValueKey('customer-registration-error'),
                style: TextStyle(color: Theme.of(context).colorScheme.error),
              ),
            ],
            const SizedBox(height: 14),
            FilledButton.icon(
              key: const ValueKey('customer-register-submit'),
              onPressed: _busy ? null : _submit,
              icon: const Icon(Icons.person_add_alt_1_rounded),
              label: _busy
                  ? const SizedBox.square(
                      dimension: 18,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : Text(context.tr('customer.action.register')),
            ),
          ],
        ),
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
