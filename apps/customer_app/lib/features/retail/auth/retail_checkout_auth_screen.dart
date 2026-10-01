import 'package:flutter/material.dart';

import '../../../core/api/customer_action_api.dart';
import '../../../core/auth/customer_session.dart';
import '../../../core/localization/app_translations.dart';
import '../commerce/retail_commerce_api.dart';

typedef RetailCommerceTokenFactory = RetailCommerceApi Function(String token);

class RetailCheckoutAuthScreen extends StatefulWidget {
  const RetailCheckoutAuthScreen({
    required this.storeId,
    required this.nextRoute,
    required this.actionApi,
    required this.onAuthenticated,
    required this.onPlatformAuthenticated,
    required this.commerceForToken,
    this.registerInitially = false,
    super.key,
  });

  final int storeId;
  final String nextRoute;
  final CustomerActionApi actionApi;
  final void Function(CustomerChannel channel, String token) onAuthenticated;
  final ValueChanged<String> onPlatformAuthenticated;
  final RetailCommerceTokenFactory commerceForToken;
  final bool registerInitially;

  @override
  State<RetailCheckoutAuthScreen> createState() =>
      _RetailCheckoutAuthScreenState();
}

class _RetailCheckoutAuthScreenState extends State<RetailCheckoutAuthScreen> {
  final _name = TextEditingController();
  final _email = TextEditingController();
  final _phone = TextEditingController();
  final _password = TextEditingController();
  final _confirmation = TextEditingController();

  late bool _register = widget.registerInitially;
  bool _busy = false;
  String? _errorKey;

  @override
  void dispose() {
    _name.dispose();
    _email.dispose();
    _phone.dispose();
    _password.dispose();
    _confirmation.dispose();
    super.dispose();
  }

  void _switchMode(bool register) {
    if (_busy) return;
    setState(() {
      _register = register;
      _errorKey = null;
    });
  }

  Future<void> _submit() async {
    final email = _email.text.trim();
    if (email.isEmpty || _password.text.isEmpty) {
      setState(() => _errorKey = 'customer.marketplace.registration_validation');
      return;
    }

    if (_register &&
        (_name.text.trim().isEmpty ||
            _phone.text.trim().isEmpty ||
            _password.text.length < 8 ||
            _password.text != _confirmation.text)) {
      setState(() => _errorKey = 'customer.marketplace.registration_validation');
      return;
    }

    setState(() {
      _busy = true;
      _errorKey = null;
    });

    try {
      final CustomerLoginResult result;
      if (_register) {
        final api = widget.actionApi;
        if (api is! HttpCustomerActionApi) {
          throw const CustomerActionException('registration_unavailable');
        }
        result = await api.credentialRegister(
          name: _name.text,
          email: email,
          phone: _phone.text,
          password: _password.text,
          passwordConfirmation: _confirmation.text,
          locale: Localizations.localeOf(context).languageCode,
          storeId: widget.storeId,
        );
      } else {
        final api = widget.actionApi;
        result = api is HttpCustomerActionApi
            ? await api.credentialLogin(
                email: email,
                password: _password.text,
              )
            : await api.login(username: email);
      }

      if (result.platformCustomer) {
        widget.onPlatformAuthenticated(result.token);
      } else {
        widget.onAuthenticated(CustomerChannel.b2c, result.token);
      }

      final commerce = widget.commerceForToken(result.token);
      await commerce.mergeGuestCartAfterAuthentication(storeId: widget.storeId);

      if (!mounted) return;
      Navigator.of(context).pushReplacementNamed(widget.nextRoute);
    } catch (_) {
      if (!mounted) return;
      setState(
        () => _errorKey = _register
            ? 'customer.marketplace.registration_failed'
            : 'customer.error.action_failed',
      );
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        key: const ValueKey('retail-checkout-auth-screen'),
        appBar: AppBar(
          title: Text(
            context.tr(
              _register
                  ? 'customer.marketplace.register_title'
                  : 'customer.checkout_login.title',
            ),
          ),
        ),
        body: SafeArea(
          child: ListView(
            padding: const EdgeInsets.all(20),
            children: [
              SegmentedButton<bool>(
                segments: [
                  ButtonSegment<bool>(
                    value: false,
                    label: Text(context.tr('customer.action.login')),
                    icon: const Icon(Icons.login_rounded),
                  ),
                  ButtonSegment<bool>(
                    value: true,
                    label: Text(context.tr('customer.marketplace.register')),
                    icon: const Icon(Icons.person_add_alt_1_rounded),
                  ),
                ],
                selected: {_register},
                onSelectionChanged: _busy
                    ? null
                    : (values) => _switchMode(values.first),
              ),
              const SizedBox(height: 18),
              if (_register) ...[
                TextField(
                  key: const ValueKey('retail-auth-name'),
                  controller: _name,
                  textInputAction: TextInputAction.next,
                  decoration: InputDecoration(
                    labelText: context.tr('customer.settings.name'),
                  ),
                ),
                const SizedBox(height: 10),
              ],
              TextField(
                key: const ValueKey('retail-auth-email'),
                controller: _email,
                keyboardType: TextInputType.emailAddress,
                textInputAction: TextInputAction.next,
                autocorrect: false,
                enableSuggestions: false,
                decoration: InputDecoration(
                  labelText: context.tr('customer.login.email'),
                ),
              ),
              if (_register) ...[
                const SizedBox(height: 10),
                TextField(
                  key: const ValueKey('retail-auth-phone'),
                  controller: _phone,
                  keyboardType: TextInputType.phone,
                  textInputAction: TextInputAction.next,
                  decoration: InputDecoration(
                    labelText: context.tr('customer.marketplace.phone'),
                  ),
                ),
              ],
              const SizedBox(height: 10),
              TextField(
                key: const ValueKey('retail-auth-password'),
                controller: _password,
                obscureText: true,
                textInputAction:
                    _register ? TextInputAction.next : TextInputAction.done,
                decoration: InputDecoration(
                  labelText: context.tr('customer.login.password'),
                ),
                onSubmitted: (_) {
                  if (!_register) _submit();
                },
              ),
              if (_register) ...[
                const SizedBox(height: 10),
                TextField(
                  key: const ValueKey('retail-auth-password-confirmation'),
                  controller: _confirmation,
                  obscureText: true,
                  textInputAction: TextInputAction.done,
                  decoration: InputDecoration(
                    labelText:
                        context.tr('customer.marketplace.password_confirmation'),
                  ),
                  onSubmitted: (_) => _submit(),
                ),
              ],
              if (_errorKey != null) ...[
                const SizedBox(height: 12),
                Text(
                  context.tr(_errorKey!),
                  key: const ValueKey('retail-auth-error'),
                  style: TextStyle(color: Theme.of(context).colorScheme.error),
                ),
              ],
              const SizedBox(height: 18),
              FilledButton(
                key: const ValueKey('retail-auth-submit'),
                onPressed: _busy ? null : _submit,
                child: _busy
                    ? const SizedBox.square(
                        dimension: 18,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : Text(
                        context.tr(
                          _register
                              ? 'customer.marketplace.create_account'
                              : 'customer.action.login',
                        ),
                      ),
              ),
            ],
          ),
        ),
      );
}
