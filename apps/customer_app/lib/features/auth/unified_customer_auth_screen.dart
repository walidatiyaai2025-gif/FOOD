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

typedef CustomerPendingActionExecutor = Future<String?> Function(
  CustomerPendingAction action,
  String token,
);

class UnifiedCustomerAuthScreen extends StatefulWidget {
  const UnifiedCustomerAuthScreen({
    required this.nextRoute,
    required this.actionApi,
    required this.onAuthenticated,
    this.commerceContext,
    this.registrationStoreId,
    this.commerceForToken,
    this.pendingActionStore,
    this.pendingActionExecutor,
    this.sessionStore,
    this.preferences = const CustomerAuthPreferences(),
    this.biometricAuthenticator,
    this.resumeAuthenticatedRoute,
    this.onLogout,
    this.onLocaleChanged,
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
  final CustomerPendingActionExecutor? pendingActionExecutor;
  final CustomerSessionStore? sessionStore;
  final CustomerAuthPreferences preferences;
  final CustomerBiometricAuthenticator? biometricAuthenticator;
  final CustomerAuthenticatedRouteResume? resumeAuthenticatedRoute;
  final Future<void> Function()? onLogout;
  final ValueChanged<Locale>? onLocaleChanged;
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
  bool _savedBiometricDismissed = false;
  bool _busy = false;
  String? _errorKey;
  Map<String, String> _fieldErrorKeys = const <String, String>{};

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
      _fieldErrorKeys = const <String, String>{};
    });
  }

  void _toggleLocale() {
    if (_busy || widget.onLocaleChanged == null) return;
    final current = Localizations.localeOf(context).languageCode;
    widget.onLocaleChanged!(Locale(current == 'ar' ? 'en' : 'ar'));
  }

  String? _fieldErrorText(BuildContext context, String field) {
    final key = _fieldErrorKeys[field];
    return key == null ? null : context.tr(key);
  }

  String _safeActionErrorKey(
    CustomerActionException error, {
    required bool register,
  }) {
    final code = error.code.trim().toLowerCase();
    if (code.contains('locked') || code.contains('blocked')) {
      return 'customer.auth.account_locked';
    }
    if (code.contains('inactive') ||
        code.contains('disabled') ||
        code.contains('suspended')) {
      return 'customer.auth.account_inactive';
    }
    if (code == 'http_401' ||
        code.contains('credential') ||
        code.contains('unauth')) {
      return 'customer.auth.invalid_credentials';
    }
    if (code.contains('offline') ||
        code.contains('network') ||
        code.contains('timeout')) {
      return 'customer.error.offline';
    }
    return register
        ? 'customer.marketplace.registration_failed'
        : 'customer.error.action_failed';
  }

  Map<String, String> _safeFieldErrors(CustomerActionException error) {
    const supported = <String>{
      'name',
      'email',
      'phone',
      'password',
      'password_confirmation',
    };
    return <String, String>{
      for (final field in error.fieldErrors.keys)
        if (supported.contains(field)) field: 'customer.validation.field_invalid',
    };
  }

  CustomerAuthPreferences get _selectedPreferences =>
      CustomerAuthPreferences(
        rememberMe: _rememberMe || _biometricEnabled,
        biometricEnabled: _biometricAvailable && _biometricEnabled,
      );

  Future<void> _resumeAfterAuthentication({
    required String token,
    required String nextRoute,
    required CustomerPendingActionStore? pendingStore,
    required CustomerPendingActionExecutor? pendingExecutor,
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

          // The pending action has already been consumed atomically by take().
          // Execute it at most once, after authentication, and accept only a
          // same-context route override. Operational failure falls back to the
          // safe product/checkout route so the successful login is preserved.
          if (pendingExecutor != null) {
            try {
              final executedTarget = await pendingExecutor(pending, token);
              if (executedTarget != null) {
                final safeTarget = safeCustomerContextReturnLocation(
                  executedTarget,
                  context: pending.context,
                );
                if (safeTarget != null) {
                  target = safeTarget;
                }
              }
            } catch (_) {
              // The target remains the safe pending route. The destination
              // renders the authoritative product/cart failure state.
            }
          }
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
    final pendingExecutor = widget.pendingActionExecutor;
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
      token: token,
      nextRoute: nextRoute,
      pendingStore: pendingStore,
      pendingExecutor: pendingExecutor,
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

  Future<void> _logoutRememberedBiometricSession() async {
    if (_busy) return;

    setState(() {
      _busy = true;
      _errorKey = null;
    });

    try {
      final logout = widget.onLogout;
      if (logout != null) {
        await logout();
        return;
      }

      await widget.sessionStore?.clear();
      if (!mounted) return;
      setState(() {
        _rememberMe = false;
        _biometricEnabled = false;
        _savedBiometricDismissed = true;
      });
    } catch (_) {
      if (mounted) {
        setState(() => _errorKey = 'customer.error.action_failed');
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Widget _buildC13SavedBiometricCard(BuildContext context) {
    final biometricAction = _busy || _checkingBiometric
        ? null
        : _authenticateWithBiometrics;

    return Container(
      key: const ValueKey('c13-saved-biometric-login-panel'),
      padding: const EdgeInsets.fromLTRB(24, 24, 24, 22),
      decoration: BoxDecoration(
        color: const Color(0xFFF2FBF8),
        borderRadius: BorderRadius.circular(28),
        border: Border.all(color: const Color(0xFFE2F2EC)),
        boxShadow: const [
          BoxShadow(
            color: Color(0x12004D3A),
            blurRadius: 24,
            offset: Offset(0, 10),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Center(
            child: Semantics(
              button: true,
              label: context.tr('customer.auth.biometric_saved_title'),
              child: SizedBox.square(
                dimension: 152,
                child: Stack(
                  alignment: Alignment.center,
                  children: [
                    Container(
                      width: 150,
                      height: 150,
                      decoration: const BoxDecoration(
                        shape: BoxShape.circle,
                        color: Color(0xFFDFF5EE),
                      ),
                    ),
                    Container(
                      width: 124,
                      height: 124,
                      decoration: const BoxDecoration(
                        shape: BoxShape.circle,
                        color: Color(0xFFCBEDE3),
                      ),
                    ),
                    Material(
                      color: CustomerUiColors.deepGreen,
                      elevation: 8,
                      shadowColor: const Color(0x3300664B),
                      shape: const CircleBorder(),
                      child: InkWell(
                        key: const ValueKey('customer-auth-biometric-login'),
                        onTap: biometricAction,
                        customBorder: const CircleBorder(),
                        child: SizedBox.square(
                          dimension: 94,
                          child: Center(
                            child: _checkingBiometric
                                ? const SizedBox.square(
                                    dimension: 28,
                                    child: CircularProgressIndicator(
                                      strokeWidth: 2.5,
                                      color: CustomerUiColors.white,
                                    ),
                                  )
                                : const Icon(
                                    Icons.fingerprint_rounded,
                                    size: 60,
                                    color: CustomerUiColors.white,
                                  ),
                          ),
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
          const SizedBox(height: 22),
          Text(
            context.tr('customer.auth.biometric_saved_title'),
            textAlign: TextAlign.center,
            style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                  color: CustomerUiColors.deepGreen,
                  fontWeight: FontWeight.w900,
                ),
          ),
          const SizedBox(height: 8),
          Text(
            context.tr('customer.auth.biometric_saved_hint'),
            textAlign: TextAlign.center,
            style: Theme.of(context).textTheme.titleMedium?.copyWith(
                  color: CustomerUiColors.muted,
                  fontWeight: FontWeight.w500,
                ),
          ),
          if (_errorKey != null) ...[
            const SizedBox(height: CustomerUiSpacing.sm),
            Text(
              context.tr(_errorKey!),
              key: const ValueKey('unified-auth-error'),
              textAlign: TextAlign.center,
              style: const TextStyle(
                color: CustomerUiColors.destructive,
                fontWeight: FontWeight.w600,
              ),
            ),
          ],
          const SizedBox(height: 26),
          OutlinedButton.icon(
            key: const ValueKey('c13-saved-biometric-logout'),
            onPressed: _busy ? null : _logoutRememberedBiometricSession,
            icon: const Icon(Icons.logout_rounded),
            label: Text(
              context.tr('customer.logout'),
              style: const TextStyle(fontWeight: FontWeight.w800),
            ),
            style: OutlinedButton.styleFrom(
              minimumSize: const Size.fromHeight(56),
              foregroundColor: CustomerUiColors.deepGreen,
              backgroundColor: CustomerUiColors.white,
              side: const BorderSide(
                color: CustomerUiColors.deepGreenSoft,
                width: 1.5,
              ),
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(18),
              ),
            ),
          ),
        ],
      ),
    );
  }

  Future<void> _submit() async {
    final email = _email.text.trim();
    final password = _password.text;
    final validation = <String, String>{};

    if (email.isEmpty) {
      validation['email'] = 'customer.validation.email';
    }
    if (password.isEmpty) {
      validation['password'] = 'customer.validation.password';
    }
    if (_register) {
      if (_name.text.trim().isEmpty) {
        validation['name'] = 'customer.validation.name';
      }
      if (_phone.text.trim().isEmpty) {
        validation['phone'] = 'customer.validation.phone';
      }
      if (password.length < 8) {
        validation['password'] = 'customer.validation.password_minimum';
      }
      if (_confirmation.text.isEmpty ||
          password != _confirmation.text) {
        validation['password_confirmation'] =
            'customer.validation.password_confirmation';
      }
    }

    if (validation.isNotEmpty) {
      setState(() {
        _fieldErrorKeys = validation;
        _errorKey = null;
      });
      return;
    }

    setState(() {
      _busy = true;
      _errorKey = null;
      _fieldErrorKeys = const <String, String>{};
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
          password: password,
          passwordConfirmation: _confirmation.text,
          locale: Localizations.localeOf(context).languageCode,
          storeId: widget.registrationStoreId,
        );
      } else {
        result = api is HttpCustomerActionApi
            ? await api.credentialLogin(
                email: email,
                password: password,
              )
            : await api.login(username: email);
      }

      await _finishAuthentication(
        result.token,
        mergeRetailGuestCart: true,
      );
    } on CustomerActionException catch (error) {
      if (!mounted) return;
      setState(() {
        _fieldErrorKeys = _safeFieldErrors(error);
        _errorKey = _safeActionErrorKey(
          error,
          register: _register,
        );
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _fieldErrorKeys = const <String, String>{};
        _errorKey = 'customer.error.offline';
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _showC13InfoSheet({
    required String title,
    required String body,
  }) {
    showModalBottomSheet<void>(
      context: context,
      backgroundColor: CustomerUiColors.white,
      showDragHandle: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(
          top: Radius.circular(CustomerUiRadii.xl),
        ),
      ),
      builder: (sheetContext) => SafeArea(
        top: false,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(
            CustomerUiSpacing.lg,
            CustomerUiSpacing.sm,
            CustomerUiSpacing.lg,
            CustomerUiSpacing.xxl,
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(
                title,
                style: Theme.of(sheetContext).textTheme.titleLarge,
              ),
              const SizedBox(height: CustomerUiSpacing.sm),
              Text(
                body,
                style: Theme.of(sheetContext).textTheme.bodyMedium?.copyWith(
                      color: CustomerUiColors.muted,
                    ),
              ),
              const SizedBox(height: CustomerUiSpacing.lg),
              FilledButton(
                onPressed: () => Navigator.of(sheetContext).pop(),
                child: const Text('OK'),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildC13BusinessEntry(
    BuildContext context, {
    required Locale locale,
    required TextDirection textDirection,
  }) {
    final isArabic = locale.languageCode == 'ar';
    final showRememberedBiometricCard =
        !_savedBiometricDismissed &&
        widget.preferences.rememberMe &&
        widget.preferences.biometricEnabled &&
        widget.sessionStore != null &&
        widget.biometricAuthenticator != null &&
        (_checkingBiometric || _biometricAvailable);
    final fieldBorder = OutlineInputBorder(
      borderRadius: BorderRadius.circular(CustomerUiRadii.pill),
      borderSide: const BorderSide(color: CustomerUiColors.border),
    );
    final focusBorder = OutlineInputBorder(
      borderRadius: BorderRadius.circular(CustomerUiRadii.pill),
      borderSide: const BorderSide(
        color: CustomerUiColors.deepGreenSoft,
        width: 1.5,
      ),
    );

    void toggleRemember(bool value) {
      if (_busy) return;
      setState(() {
        _rememberMe = value;
        if (!value) _biometricEnabled = false;
      });
    }

    void biometricPressed() {
      if (_busy || !_biometricAvailable) return;
      if (widget.preferences.biometricEnabled && _biometricEnabled) {
        _authenticateWithBiometrics();
        return;
      }
      setState(() {
        _biometricEnabled = !_biometricEnabled;
        if (_biometricEnabled) _rememberMe = true;
      });
    }

    return Directionality(
      textDirection: textDirection,
      child: Scaffold(
        key: const ValueKey('unified-customer-auth-screen'),
        backgroundColor: CustomerUiColors.white,
        body: SafeArea(
          child: Stack(
            children: [
              SingleChildScrollView(
                padding: const EdgeInsets.only(bottom: CustomerUiSpacing.lg),
                child: Center(
                  child: ConstrainedBox(
                    constraints: const BoxConstraints(maxWidth: 560),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        const SizedBox(height: 12),
                        Padding(
                          padding: const EdgeInsets.symmetric(horizontal: 34),
                          child: Image.asset(
                            'assets/branding/login_reference/header_complete.png',
                            fit: BoxFit.contain,
                            semanticLabel: 'FOODEX Economic Group',
                          ),
                        ),
                        const SizedBox(height: 8),
                        ClipRect(
                          child: AspectRatio(
                            aspectRatio: 941 / 496,
                            child: Image.asset(
                              'assets/branding/login_reference/foodex_truck_hero.png',
                              key: const ValueKey('c13-business-login-hero'),
                              fit: BoxFit.cover,
                              alignment: Alignment.center,
                            ),
                          ),
                        ),
                        Transform.translate(
                          offset: const Offset(0, -24),
                          child: Container(
                            margin: EdgeInsets.zero,
                            padding: const EdgeInsets.fromLTRB(20, 30, 20, 18),
                            decoration: const BoxDecoration(
                              color: Color(0xFFFCFEFD),
                              borderRadius: BorderRadius.vertical(
                                top: Radius.circular(34),
                              ),
                            ),
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.stretch,
                              children: [
                                Text(
                                  context.tr('customer.app.identity'),
                                  key: const ValueKey('customer-app-identity'),
                                  textAlign: TextAlign.center,
                                  style: Theme.of(context)
                                      .textTheme
                                      .headlineSmall
                                      ?.copyWith(
                                        color: CustomerUiColors.deepGreen,
                                        fontWeight: FontWeight.w900,
                                      ),
                                ),
                                const SizedBox(height: 4),
                                Text(
                                  context.tr('customer.auth.title'),
                                  key: const ValueKey('c13-business-login-title'),
                                  textAlign: TextAlign.center,
                                  style: Theme.of(context)
                                      .textTheme
                                      .titleMedium
                                      ?.copyWith(fontWeight: FontWeight.w800),
                                ),
                                const SizedBox(height: 4),
                                Text(
                                  context.tr('customer.auth.subtitle'),
                                  key: const ValueKey(
                                    'unified-customer-auth-subtitle',
                                  ),
                                  textAlign: TextAlign.center,
                                  style: Theme.of(context)
                                      .textTheme
                                      .bodySmall
                                      ?.copyWith(color: CustomerUiColors.muted),
                                ),
                                const SizedBox(height: 14),
                                if (showRememberedBiometricCard)
                                  _buildC13SavedBiometricCard(context)
                                else ...[
                                  TextField(
                                  key: const ValueKey('unified-auth-email'),
                                  controller: _email,
                                  keyboardType: TextInputType.emailAddress,
                                  textInputAction: TextInputAction.next,
                                  autocorrect: false,
                                  enableSuggestions: false,
                                  decoration: InputDecoration(
                                    hintText: context.tr('customer.login.email'),
                                    errorText: _fieldErrorText(context, 'email'),
                                    prefixIcon: Padding(
                                      padding: const EdgeInsets.all(14),
                                      child: Image.asset(
                                        'assets/branding/login_reference/email_icon.png',
                                        width: 24,
                                        height: 24,
                                      ),
                                    ),
                                    filled: true,
                                    fillColor: const Color(0xFFFCFDFC),
                                    border: fieldBorder,
                                    enabledBorder: fieldBorder,
                                    focusedBorder: focusBorder,
                                    errorBorder: fieldBorder.copyWith(
                                      borderSide: const BorderSide(
                                        color: CustomerUiColors.destructive,
                                      ),
                                    ),
                                    contentPadding: const EdgeInsets.symmetric(
                                      horizontal: 20,
                                      vertical: 18,
                                    ),
                                  ),
                                ),
                                const SizedBox(height: CustomerUiSpacing.sm),
                                TextField(
                                  key: const ValueKey('unified-auth-password'),
                                  controller: _password,
                                  obscureText: true,
                                  textInputAction: TextInputAction.done,
                                  decoration: InputDecoration(
                                    hintText: context.tr('customer.login.password'),
                                    errorText: _fieldErrorText(context, 'password'),
                                    prefixIcon: Padding(
                                      padding: const EdgeInsets.all(14),
                                      child: Image.asset(
                                        'assets/branding/login_reference/lock_icon.png',
                                        width: 24,
                                        height: 24,
                                      ),
                                    ),
                                    filled: true,
                                    fillColor: const Color(0xFFFCFDFC),
                                    border: fieldBorder,
                                    enabledBorder: fieldBorder,
                                    focusedBorder: focusBorder,
                                    errorBorder: fieldBorder.copyWith(
                                      borderSide: const BorderSide(
                                        color: CustomerUiColors.destructive,
                                      ),
                                    ),
                                    contentPadding: const EdgeInsets.symmetric(
                                      horizontal: 20,
                                      vertical: 18,
                                    ),
                                  ),
                                  onSubmitted: (_) => _submit(),
                                ),
                                const SizedBox(height: CustomerUiSpacing.sm),
                                Row(
                                  children: [
                                    Expanded(
                                      child: InkWell(
                                        key: const ValueKey(
                                          'customer-auth-remember-me',
                                        ),
                                        onTap: _busy
                                            ? null
                                            : () => toggleRemember(!_rememberMe),
                                        borderRadius: BorderRadius.circular(16),
                                        child: Padding(
                                          padding: const EdgeInsets.symmetric(
                                            vertical: 7,
                                          ),
                                          child: Row(
                                            mainAxisSize: MainAxisSize.min,
                                            children: [
                                              Checkbox(
                                                value: _rememberMe,
                                                onChanged: _busy
                                                    ? null
                                                    : (value) => toggleRemember(
                                                          value ?? false,
                                                        ),
                                                visualDensity:
                                                    VisualDensity.compact,
                                              ),
                                              Flexible(
                                                child: Text(
                                                  context.tr(
                                                    'customer.auth.remember_me',
                                                  ),
                                                  maxLines: 1,
                                                  overflow:
                                                      TextOverflow.ellipsis,
                                                ),
                                              ),
                                            ],
                                          ),
                                        ),
                                      ),
                                    ),
                                    const SizedBox(width: 8),
                                    Expanded(
                                      child: OutlinedButton.icon(
                                        key: const ValueKey(
                                          'customer-auth-biometric-login',
                                        ),
                                        onPressed:
                                            _biometricAvailable && !_busy
                                                ? biometricPressed
                                                : null,
                                        icon: _checkingBiometric
                                            ? const SizedBox.square(
                                                dimension: 18,
                                                child:
                                                    CircularProgressIndicator(
                                                  strokeWidth: 2,
                                                ),
                                              )
                                            : Image.asset(
                                                'assets/branding/login_reference/fingerprint_icon.png',
                                                width: 30,
                                                height: 30,
                                              ),
                                        label: Text(
                                          isArabic ? 'البصمة' : 'Biometric',
                                          maxLines: 1,
                                          overflow: TextOverflow.ellipsis,
                                        ),
                                        style: OutlinedButton.styleFrom(
                                          minimumSize: const Size(0, 48),
                                          foregroundColor:
                                              CustomerUiColors.deepGreen,
                                          backgroundColor: _biometricEnabled
                                              ? CustomerUiColors.mint
                                              : CustomerUiColors.white,
                                          side: BorderSide(
                                            color: _biometricEnabled
                                                ? CustomerUiColors.deepGreenSoft
                                                : CustomerUiColors.border,
                                          ),
                                          shape: RoundedRectangleBorder(
                                            borderRadius:
                                                BorderRadius.circular(16),
                                          ),
                                        ),
                                      ),
                                    ),
                                  ],
                                ),
                                if (_errorKey != null) ...[
                                  const SizedBox(height: CustomerUiSpacing.sm),
                                  Text(
                                    context.tr(_errorKey!),
                                    key: const ValueKey('unified-auth-error'),
                                    textAlign: TextAlign.center,
                                    style: const TextStyle(
                                      color: CustomerUiColors.destructive,
                                    ),
                                  ),
                                ],
                                const SizedBox(height: CustomerUiSpacing.sm),
                                SizedBox(
                                  height: 58,
                                  child: FilledButton(
                                    key: const ValueKey('unified-auth-submit'),
                                    onPressed: _busy ? null : _submit,
                                    style: FilledButton.styleFrom(
                                      backgroundColor:
                                          CustomerUiColors.deepGreenSoft,
                                      foregroundColor: CustomerUiColors.white,
                                      shape: RoundedRectangleBorder(
                                        borderRadius: BorderRadius.circular(18),
                                      ),
                                    ),
                                    child: _busy
                                        ? const SizedBox.square(
                                            dimension: 20,
                                            child: CircularProgressIndicator(
                                              strokeWidth: 2,
                                              color: CustomerUiColors.white,
                                            ),
                                          )
                                        : Text(
                                            context.tr('customer.action.login'),
                                            style: Theme.of(context)
                                                .textTheme
                                                .titleMedium
                                                ?.copyWith(
                                                  color:
                                                      CustomerUiColors.white,
                                                ),
                                          ),
                                  ),
                                ),
                                const SizedBox(height: 8),
                                OutlinedButton(
                                  key: const ValueKey(
                                    'c13-business-forgot-password',
                                  ),
                                  onPressed: _busy
                                      ? null
                                      : () => _showC13InfoSheet(
                                            title: isArabic
                                                ? 'نسيت كلمة المرور؟'
                                                : 'Forgot password?',
                                            body: isArabic
                                                ? 'تواصل مع إدارة FOODEX لإعادة تعيين كلمة مرور حساب الأعمال المعتمد.'
                                                : 'Contact FOODEX administration to reset the password for your approved business account.',
                                          ),
                                  style: OutlinedButton.styleFrom(
                                    minimumSize: const Size.fromHeight(52),
                                    foregroundColor:
                                        CustomerUiColors.deepGreenSoft,
                                    backgroundColor: const Color(0xFFF9FCFA),
                                    side: const BorderSide(
                                      color: Color(0xFFE2E9E5),
                                    ),
                                    shape: RoundedRectangleBorder(
                                      borderRadius: BorderRadius.circular(18),
                                    ),
                                  ),
                                  child: Text(
                                    isArabic
                                        ? 'نسيت كلمة المرور؟'
                                        : 'Forgot password?',
                                    style: const TextStyle(
                                      fontWeight: FontWeight.w700,
                                    ),
                                  ),
                                ),
                                const SizedBox(height: 10),
                                OutlinedButton(
                                  key: const ValueKey(
                                    'c13-business-contact-us',
                                  ),
                                  onPressed: _busy
                                      ? null
                                      : () => _showC13InfoSheet(
                                            title: isArabic
                                                ? 'تواصل معنا'
                                                : 'Contact us',
                                            body: isArabic
                                                ? 'حسابات الأعمال تُنشأ وتُعتمد من لوحة الإدارة. تواصل مع مسؤول FOODEX لتفعيل الحساب أو المساعدة في الدخول.'
                                                : 'Business accounts are created and approved from the management dashboard. Contact your FOODEX administrator for activation or sign-in help.',
                                          ),
                                  style: OutlinedButton.styleFrom(
                                    minimumSize: const Size.fromHeight(54),
                                    foregroundColor: CustomerUiColors.deepGreen,
                                    side: const BorderSide(
                                      color: CustomerUiColors.deepGreenSoft,
                                    ),
                                    shape: RoundedRectangleBorder(
                                      borderRadius: BorderRadius.circular(18),
                                    ),
                                  ),
                                  child: Text(
                                    isArabic ? 'تواصل معنا' : 'Contact us',
                                    style: const TextStyle(
                                      fontWeight: FontWeight.w800,
                                    ),
                                  ),
                                ),
                                ],
                              ],
                            ),
                          ),
                        ),
                        Padding(
                          padding: const EdgeInsets.fromLTRB(22, 0, 22, 8),
                          child: Row(
                            children: [
                              Expanded(
                                child: Text(
                                  'Good Food   A Stronger Tomorrow',
                                  style: Theme.of(context)
                                      .textTheme
                                      .bodySmall
                                      ?.copyWith(
                                        color: CustomerUiColors.muted,
                                      ),
                                ),
                              ),
                              Text(
                                isArabic
                                    ? 'الإصدار 1.0.68'
                                    : 'Version 1.0.68',
                                key: const ValueKey(
                                  'c13-business-login-version',
                                ),
                                style: Theme.of(context)
                                    .textTheme
                                    .bodySmall
                                    ?.copyWith(
                                      color: CustomerUiColors.muted,
                                      fontWeight: FontWeight.w700,
                                    ),
                              ),
                            ],
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
              ),
              if (widget.onLocaleChanged != null)
                PositionedDirectional(
                  top: 0,
                  start: 0,
                  child: Opacity(
                    opacity: 0,
                    child: TextButton(
                      key: const ValueKey('customer-auth-language-toggle'),
                      onPressed: _busy ? null : _toggleLocale,
                      child: Text(isArabic ? 'English' : 'العربية'),
                    ),
                  ),
                ),
            ],
          ),
        ),
      ),
    );
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
    final isC13BusinessEntry =
        widget.nextRoute == CustomerRoutePaths.b2bDashboard &&
        widget.commerceContext == null &&
        !widget.registerInitially;

    if (isC13BusinessEntry) {
      return _buildC13BusinessEntry(
        context,
        locale: locale,
        textDirection: textDirection,
      );
    }

    return Directionality(
      textDirection: textDirection,
      child: Scaffold(
        key: const ValueKey('unified-customer-auth-screen'),
      backgroundColor: CustomerUiColors.mint,
      appBar: AppBar(
        title: Text(title),
        backgroundColor: CustomerUiColors.deepGreen,
        foregroundColor: CustomerUiColors.white,
        actions: [
          IconButton(
            key: const ValueKey('customer-login-diagnostics'),
            onPressed: _busy
                ? null
                : () => Navigator.of(context).pushNamed(
                      CustomerRoutePaths.diagnostics,
                    ),
            tooltip: context.tr('customer.diagnostics.open'),
            icon: const Icon(Icons.bug_report_outlined),
          ),
          IconButton(
            key: const ValueKey('customer-auth-guest'),
            onPressed: _busy
                ? null
                : () => Navigator.of(context).pushReplacementNamed(
                      CustomerRoutePaths.marketplace,
                    ),
            tooltip: context.tr('customer.action.guest'),
            icon: const Icon(Icons.storefront_outlined),
          ),
          if (widget.onLocaleChanged != null)
            TextButton(
              key: const ValueKey('customer-auth-language-toggle'),
              onPressed: _busy ? null : _toggleLocale,
              child: Text(
                locale.languageCode == 'ar' ? 'English' : 'العربية',
                style: const TextStyle(color: CustomerUiColors.white),
              ),
            ),
        ],
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
                      context.tr('customer.app.identity'),
                      key: const ValueKey('customer-app-identity'),
                      textAlign: TextAlign.center,
                      style: Theme.of(context).textTheme.titleLarge?.copyWith(
                            color: CustomerUiColors.deepGreen,
                            fontWeight: FontWeight.w900,
                          ),
                    ),
                    const SizedBox(height: CustomerUiSpacing.xs),
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
                          errorText: _fieldErrorText(context, 'name'),
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
                        errorText: _fieldErrorText(context, 'email'),
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
                          errorText: _fieldErrorText(context, 'phone'),
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
                        errorText: _fieldErrorText(context, 'password'),
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
                          errorText: _fieldErrorText(
                            context,
                            'password_confirmation',
                          ),
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
          ],
        ),
        ),
      ),
    );
  }
}
