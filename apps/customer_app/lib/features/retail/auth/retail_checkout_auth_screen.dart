import 'package:flutter/material.dart';

import '../../../core/api/customer_action_api.dart';
import '../../../core/auth/customer_session.dart';
import '../../../core/localization/app_translations.dart';
import '../../../core/routing/customer_routes.dart';
import '../../../shared/customer_ui_v3/customer_ui_v3.dart';
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
  Widget build(BuildContext context) {
    final title = context.tr(
      _register
          ? 'customer.marketplace.register_title'
          : 'customer.checkout_login.title',
    );

    return Scaffold(
      key: const ValueKey('retail-checkout-auth-screen'),
      backgroundColor: CustomerUiColors.mint,
      appBar: AppBar(
        title: Text(title),
        backgroundColor: CustomerUiColors.deepGreen,
        foregroundColor: CustomerUiColors.white,
      ),
      body: SafeArea(
        top: false,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(
            CustomerUiSpacing.page,
            CustomerUiSpacing.lg,
            CustomerUiSpacing.page,
            CustomerUiSpacing.xxl,
          ),
          children: [
            DecoratedBox(
              decoration: BoxDecoration(
                color: CustomerUiColors.white,
                borderRadius: BorderRadius.circular(CustomerUiRadii.xl),
                border: Border.all(color: CustomerUiColors.border),
              ),
              child: Padding(
                padding: const EdgeInsets.all(CustomerUiSpacing.lg),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Row(
                      children: [
                        DecoratedBox(
                          decoration: const BoxDecoration(
                            color: CustomerUiColors.limeSoft,
                            shape: BoxShape.circle,
                          ),
                          child: SizedBox.square(
                            dimension: 48,
                            child: Icon(
                              _register
                                  ? Icons.person_add_alt_1_rounded
                                  : Icons.lock_open_rounded,
                              color: CustomerUiColors.deepGreenStrong,
                            ),
                          ),
                        ),
                        const SizedBox(width: CustomerUiSpacing.sm),
                        Expanded(
                          child: Text(
                            title,
                            style: Theme.of(context).textTheme.titleLarge,
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: CustomerUiSpacing.lg),
                    SegmentedButton<bool>(
                      segments: [
                        ButtonSegment<bool>(
                          value: false,
                          label: Text(context.tr('customer.action.login')),
                          icon: const Icon(Icons.login_rounded),
                        ),
                        ButtonSegment<bool>(
                          value: true,
                          label:
                              Text(context.tr('customer.marketplace.register')),
                          icon: const Icon(Icons.person_add_alt_1_rounded),
                        ),
                      ],
                      selected: {_register},
                      onSelectionChanged: _busy
                          ? null
                          : (values) => _switchMode(values.first),
                    ),
                    const SizedBox(height: CustomerUiSpacing.lg),
                    if (_register) ...[
                      TextField(
                        key: const ValueKey('retail-auth-name'),
                        controller: _name,
                        textInputAction: TextInputAction.next,
                        decoration: InputDecoration(
                          labelText: context.tr('customer.settings.name'),
                          prefixIcon: const Icon(Icons.person_outline_rounded),
                        ),
                      ),
                      const SizedBox(height: CustomerUiSpacing.sm),
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
                        prefixIcon: const Icon(Icons.mail_outline_rounded),
                      ),
                    ),
                    if (_register) ...[
                      const SizedBox(height: CustomerUiSpacing.sm),
                      TextField(
                        key: const ValueKey('retail-auth-phone'),
                        controller: _phone,
                        keyboardType: TextInputType.phone,
                        textInputAction: TextInputAction.next,
                        decoration: InputDecoration(
                          labelText: context.tr('customer.marketplace.phone'),
                          prefixIcon: const Icon(Icons.phone_outlined),
                        ),
                      ),
                    ],
                    const SizedBox(height: CustomerUiSpacing.sm),
                    TextField(
                      key: const ValueKey('retail-auth-password'),
                      controller: _password,
                      obscureText: true,
                      textInputAction:
                          _register ? TextInputAction.next : TextInputAction.done,
                      decoration: InputDecoration(
                        labelText: context.tr('customer.login.password'),
                        prefixIcon: const Icon(Icons.lock_outline_rounded),
                      ),
                      onSubmitted: (_) {
                        if (!_register) _submit();
                      },
                    ),
                    if (_register) ...[
                      const SizedBox(height: CustomerUiSpacing.sm),
                      TextField(
                        key:
                            const ValueKey('retail-auth-password-confirmation'),
                        controller: _confirmation,
                        obscureText: true,
                        textInputAction: TextInputAction.done,
                        decoration: InputDecoration(
                          labelText: context.tr(
                            'customer.marketplace.password_confirmation',
                          ),
                          prefixIcon:
                              const Icon(Icons.verified_user_outlined),
                        ),
                        onSubmitted: (_) => _submit(),
                      ),
                    ],
                    if (_errorKey != null) ...[
                      const SizedBox(height: CustomerUiSpacing.md),
                      Container(
                        key: const ValueKey('retail-auth-error'),
                        padding: const EdgeInsets.all(CustomerUiSpacing.sm),
                        decoration: BoxDecoration(
                          color: CustomerUiColors.destructive
                              .withValues(alpha: 0.08),
                          borderRadius:
                              BorderRadius.circular(CustomerUiRadii.md),
                          border: Border.all(
                            color: CustomerUiColors.destructive
                                .withValues(alpha: 0.18),
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
                                context.tr(_errorKey!),
                                style: Theme.of(context)
                                    .textTheme
                                    .bodyMedium
                                    ?.copyWith(
                                      color: CustomerUiColors.destructive,
                                    ),
                              ),
                            ),
                          ],
                        ),
                      ),
                    ],
                    const SizedBox(height: CustomerUiSpacing.lg),
                    FilledButton.icon(
                      key: const ValueKey('retail-auth-submit'),
                      onPressed: _busy ? null : _submit,
                      icon: _busy
                          ? const SizedBox.square(
                              dimension: 18,
                              child: CircularProgressIndicator(strokeWidth: 2),
                            )
                          : Icon(
                              _register
                                  ? Icons.person_add_alt_1_rounded
                                  : Icons.login_rounded,
                            ),
                      label: Text(
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
            ),
            const SizedBox(height: CustomerUiSpacing.md),
            OutlinedButton.icon(
              key: const ValueKey('customer-login-diagnostics'),
              onPressed: () => Navigator.of(context).pushNamed(
                CustomerRoutePaths.diagnostics,
              ),
              icon: const Icon(Icons.bug_report_outlined),
              label: Text(context.tr('customer.diagnostics.open')),
            ),
          ],
        ),
      ),
    );
  }
}
