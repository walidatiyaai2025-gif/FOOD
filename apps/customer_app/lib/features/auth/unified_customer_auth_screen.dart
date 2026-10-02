import 'package:flutter/material.dart';

import '../../core/api/customer_action_api.dart';
import '../../core/auth/customer_auth_persistence.dart';
import '../../core/auth/customer_session_store.dart';
import '../../core/localization/app_translations.dart';
import '../../core/routing/customer_commerce_context.dart';
import '../../core/routing/customer_pending_action.dart';
import '../../core/routing/customer_routes.dart';
import '../../shared/customer_ui_v3/customer_ui_v3.dart';
import '../retail/commerce/retail_commerce_api.dart';

typedef CustomerUnifiedAuthenticated = Future<void> Function(
  String token,
  CustomerAuthPreferences preferences,
);

typedef CustomerAuthenticatedRouteResume = Future<void> Function(String route);

class UnifiedCustomerAuthScreen extends StatefulWidget {
  const UnifiedCustomerAuthScreen({
    required this.nextRoute,
    required this.actionApi,
    required this.onAuthenticated,
    this.commerceContext,
    this.registrationStoreId,
    this.commerceForToken,
    this.pendingActionStore,
    this.sessionStore,
    this.preferences = const CustomerAuthPreferences(),
    this.biometricAuthenticator,
    this.resumeAuthenticatedRoute,
    this.registerInitially = false,
    super.key,
  });

  final String nextRoute;
  final CustomerActionApi actionApi;
  final CustomerUnifiedAuthenticated onAuthenticated;
  final CustomerCommerceContext? commerceContext;
  final int? registrationStoreId;
  final RetailCommerceApi Function(String token)? commerceForToken;
  final CustomerPendingActionStore? pendingActionStore;
  final CustomerSessionStore? sessionStore;
  final CustomerAuthPreferences preferences;
  final CustomerBiometricAuthenticator? biometricAuthenticator;
  final CustomerAuthenticatedRouteResume? resumeAuthenticatedRoute;
  final bool registerInitially;

  @override
  State<UnifiedCustomerAuthScreen> createState() =>
      _UnifiedCustomerAuthScreenState();
}

class _UnifiedCustomerAuthScreenState extends State<UnifiedCustomerAuthScreen> {
  final _name = TextEditingController();
  final _email = TextEditingController();
  final _phone = TextEditingController();
  final _password = TextEditingController();
  final _confirmation = TextEditingController();

  late bool _register = widget.registerInitially;
  late bool _rememberMe = widget.preferences.rememberMe;
  late bool _biometricEnabled = widget.preferences.biometricEnabled;
  bool _biometricAvailable = false;
  bool _checkingBiometric = false;
  bool _busy = false;
  String? _errorKey;

  @override
  void initState() {
    super.initState();
    _loadBiometricAvailability();
  }

  @override
  void dispose() {
    _name.dispose();
    _email.dispose();
    _phone.dispose();
    _password.dispose();
    _confirmation.dispose();
    super.dispose();
  }

  Future<void> _loadBiometricAvailability() async {
    final authenticator = widget.biometricAuthenticator;
    if (authenticator == null || widget.sessionStore == null) return;
    setState(() => _checkingBiometric = true);
    final available = await authenticator.isAvailable();
    if (!mounted) return;
    setState(() {
      _biometricAvailable = available;
      _checkingBiometric = false;
      if (!available) _biometricEnabled = false;
    });
  }

  void _switchMode(bool register) {
    if (_busy) return;
    setState(() {
      _register = register;
      _errorKey = null;
    });
  }

  CustomerAuthPreferences get _selectedPreferences =>
      CustomerAuthPreferences(
        rememberMe: _rememberMe || _biometricEnabled,
        biometricEnabled: _biometricAvailable && _biometricEnabled,
      );

  Future<void> _resumeAfterAuthentication({
    required String nextRoute,
    required CustomerPendingActionStore? pendingStore,
    required CustomerCommerceContext? commerceContext,
    required CustomerAuthenticatedRouteResume? appLevelResume,
  }) async {
    var target = nextRoute;
    if (pendingStore != null) {
      try {
        final pending = await pendingStore.take().timeout(
          const Duration(milliseconds: 750),
          onTimeout: () => null,
        );
        if (pending != null &&
            (commerceContext == null ||
                commerceContext.sameScope(pending.context))) {
          target = pending.nextLocation;
        }
      } catch (_) {
        // Pending-action persistence is supplementary routing state. Once
        // authentication succeeded, a secure-storage failure must not turn the
        // valid login into an auth error or block the already-safe nextRoute.
      }
    }

    if (appLevelResume != null) {
      await appLevelResume(target);
      return;
    }

    if (!mounted) return;
    Navigator.of(context).pushReplacementNamed(target);
  }

  Future<void> _finishAuthentication(
    String token, {
    required bool mergeRetailGuestCart,
  }) async {
    // Capture everything needed for post-auth work before the parent rebuild:
    // that rebuild may legitimately dispose this auth route.
    final nextRoute = widget.nextRoute;
    final pendingStore = widget.pendingActionStore;
    final commerceContext = widget.commerceContext;
    final commerceFactory = widget.commerceForToken;
    final appLevelResume = widget.resumeAuthenticatedRoute;
    final preferences = _selectedPreferences;

    // Authentication is authoritative only after the parent commits the new
    // platform session/router.
    await widget.onAuthenticated(token, preferences);

    // Guest-cart merge does not depend on this State remaining mounted.
    if (mergeRetailGuestCart &&
        commerceContext != null &&
        commerceContext.isRetail &&
        commerceFactory != null) {
      final commerce = commerceFactory(token);
      await commerce.mergeGuestCartAfterAuthentication(
        storeId: commerceContext.storeId,
      );
    }

    // The app-owned resume is intentionally allowed after this auth State has
    // been disposed by the authenticated rebuild. Only the local Navigator
    // fallback still requires [mounted].
    if (!mounted && appLevelResume == null) return;
    await _resumeAfterAuthentication(
      nextRoute: nextRoute,
      pendingStore: pendingStore,
      commerceContext: commerceContext,
      appLevelResume: appLevelResume,
    );
  }

  Future<void> _authenticateWithBiometrics() async {
    if (_busy ||
        !_biometricAvailable ||
        !_biometricEnabled ||
        widget.sessionStore == null ||
        widget.biometricAuthenticator == null) {
      return;
    }

    setState(() {
      _busy = true;
      _errorKey = null;
    });

    try {
      final ok = await widget.biometricAuthenticator!.authenticate(
        reason: context.tr('customer.auth.biometric_reason'),
      );
      if (!ok) {
        if (mounted) {
          setState(() => _errorKey = 'customer.auth.biometric_failed');
        }
        return;
      }

      final stored = await widget.sessionStore!.read();
      final token = stored?.accessToken?.trim() ?? '';
      if (stored == null || !stored.isAuthenticated || token.isEmpty) {
        if (mounted) {
          setState(() => _errorKey = 'customer.auth.biometric_session_missing');
        }
        return;
      }

      await _finishAuthentication(
        token,
        mergeRetailGuestCart: true,
      );
    } catch (_) {
      if (mounted) {
        setState(() => _errorKey = 'customer.auth.biometric_failed');
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _submit() async {
    final email = _email.text.trim();
    if (email.isEmpty || _password.text.isEmpty) {
      setState(() => _errorKey = 'customer.validation.credentials');
      return;
    }

    if (_register &&
        (_name.text.trim().isEmpty ||
            _phone.text.trim().isEmpty ||
            _password.text.length < 8 ||
            _password.text != _confirmation.text)) {
      setState(
        () => _errorKey = 'customer.marketplace.registration_validation',
      );
      return;
    }

    setState(() {
      _busy = true;
      _errorKey = null;
    });

    try {
      final CustomerLoginResult result;
      final api = widget.actionApi;
      if (_register) {
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
          storeId: widget.registrationStoreId,
        );
      } else {
        result = api is HttpCustomerActionApi
            ? await api.credentialLogin(
                email: email,
                password: _password.text,
              )
            : await api.login(username: email);
      }

      await _finishAuthentication(
        result.token,
        mergeRetailGuestCart: true,
      );
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
      _register ? 'customer.auth.register_title' : 'customer.auth.title',
    );

    final locale = AppTranslations.maybeOf(context)?.locale ??
        Localizations.maybeLocaleOf(context) ??
        const Locale('ar');
    final textDirection =
        locale.languageCode == 'ar' ? TextDirection.rtl : TextDirection.ltr;

    return Directionality(
      textDirection: textDirection,
      child: Scaffold(
        key: const ValueKey('unified-customer-auth-screen'),
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
            Material(
              color: CustomerUiColors.white,
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(CustomerUiRadii.xl),
                side: const BorderSide(color: CustomerUiColors.border),
              ),
              clipBehavior: Clip.antiAlias,
              child: Padding(
                padding: const EdgeInsets.all(CustomerUiSpacing.lg),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Text(
                      title,
                      style: Theme.of(context).textTheme.titleLarge,
                    ),
                    const SizedBox(height: CustomerUiSpacing.xs),
                    Text(
                      context.tr('customer.auth.subtitle'),
                      key: const ValueKey('unified-customer-auth-subtitle'),
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
                          label: Text(
                            context.tr('customer.marketplace.register'),
                          ),
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
                        key: const ValueKey('unified-auth-name'),
                        controller: _name,
                        textInputAction: TextInputAction.next,
                        decoration: InputDecoration(
                          labelText: context.tr('customer.settings.name'),
                          prefixIcon:
                              const Icon(Icons.person_outline_rounded),
                        ),
                      ),
                      const SizedBox(height: CustomerUiSpacing.sm),
                    ],
                    TextField(
                      key: const ValueKey('unified-auth-email'),
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
                        key: const ValueKey('unified-auth-phone'),
                        controller: _phone,
                        keyboardType: TextInputType.phone,
                        textInputAction: TextInputAction.next,
                        decoration: InputDecoration(
                          labelText:
                              context.tr('customer.marketplace.phone'),
                          prefixIcon: const Icon(Icons.phone_outlined),
                        ),
                      ),
                    ],
                    const SizedBox(height: CustomerUiSpacing.sm),
                    TextField(
                      key: const ValueKey('unified-auth-password'),
                      controller: _password,
                      obscureText: true,
                      textInputAction: _register
                          ? TextInputAction.next
                          : TextInputAction.done,
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
                        key: const ValueKey(
                          'unified-auth-password-confirmation',
                        ),
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
                    const SizedBox(height: CustomerUiSpacing.sm),
                    Material(
                      type: MaterialType.transparency,
                      child: SwitchListTile.adaptive(
                        key: const ValueKey('customer-auth-remember-me'),
                        contentPadding: EdgeInsets.zero,
                        value: _rememberMe,
                        onChanged: _busy
                            ? null
                            : (value) {
                                setState(() {
                                  _rememberMe = value;
                                  if (!value) _biometricEnabled = false;
                                });
                              },
                        title: Text(context.tr('customer.auth.remember_me')),
                        subtitle: Text(
                          context.tr('customer.auth.remember_me_help'),
                        ),
                      ),
                    ),
                    if (_checkingBiometric)
                      const LinearProgressIndicator(
                        key: ValueKey('customer-auth-biometric-checking'),
                      ),
                    if (_biometricAvailable)
                      Material(
                        type: MaterialType.transparency,
                        child: SwitchListTile.adaptive(
                          key: const ValueKey('customer-auth-biometric-toggle'),
                          contentPadding: EdgeInsets.zero,
                          value: _biometricEnabled,
                          onChanged: _busy
                              ? null
                              : (value) {
                                  setState(() {
                                    _biometricEnabled = value;
                                    if (value) _rememberMe = true;
                                  });
                                },
                          title: Text(
                            context.tr('customer.auth.biometric_enable'),
                          ),
                          subtitle: Text(
                            context.tr('customer.auth.biometric_help'),
                          ),
                        ),
                      ),
                    if (_errorKey != null) ...[
                      const SizedBox(height: CustomerUiSpacing.sm),
                      Text(
                        context.tr(_errorKey!),
                        key: const ValueKey('unified-auth-error'),
                        style: const TextStyle(
                          color: CustomerUiColors.destructive,
                        ),
                      ),
                    ],
                    const SizedBox(height: CustomerUiSpacing.lg),
                    FilledButton.icon(
                      key: const ValueKey('unified-auth-submit'),
                      onPressed: _busy ? null : _submit,
                      icon: _busy
                          ? const SizedBox.square(
                              dimension: 18,
                              child:
                                  CircularProgressIndicator(strokeWidth: 2),
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
                    if (!_register &&
                        _biometricAvailable &&
                        _biometricEnabled) ...[
                      const SizedBox(height: CustomerUiSpacing.sm),
                      OutlinedButton.icon(
                        key: const ValueKey(
                          'customer-auth-biometric-login',
                        ),
                        onPressed:
                            _busy ? null : _authenticateWithBiometrics,
                        icon: const Icon(Icons.fingerprint_rounded),
                        label: Text(
                          context.tr('customer.auth.biometric_login'),
                        ),
                      ),
                    ],
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
      ),
    );
  }
}
