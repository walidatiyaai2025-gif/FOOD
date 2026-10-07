import 'package:flutter/material.dart';

import '../../core/auth/driver_auth_persistence.dart';
import '../../core/auth/driver_session.dart';
import '../../core/localization/driver_translations.dart';
import '../../core/theme/foodex_theme.dart';

typedef DriverAuthenticatedCallback = void Function(
  DriverSession session,
  bool rememberMe,
  bool biometricEnabled,
);

class DriverLoginPage extends StatefulWidget {
  const DriverLoginPage({
    super.key,
    required this.repository,
    required this.onAuthenticated,
    required this.sessionStore,
    required this.biometricAuthenticator,
  });

  final DriverAuthRepository? repository;
  final DriverAuthenticatedCallback onAuthenticated;
  final DriverSessionStore sessionStore;
  final DriverBiometricAuthenticator biometricAuthenticator;

  @override
  State<DriverLoginPage> createState() => _DriverLoginPageState();
}

class _DriverLoginPageState extends State<DriverLoginPage> {
  final _email = TextEditingController();
  final _password = TextEditingController();
  bool _submitting = false;
  bool _passwordVisible = false;
  bool _rememberMe = false;
  bool _enableBiometrics = false;
  bool _biometricAvailable = false;
  bool _savedBiometricLogin = false;
  bool _checkingBiometrics = true;
  String? _errorKey;

  @override
  void initState() {
    super.initState();
    _loadAuthOptions();
  }

  Future<void> _loadAuthOptions() async {
    final available = await widget.biometricAuthenticator.isAvailable();
    DriverStoredSession? stored;
    try {
      stored = await widget.sessionStore.read();
    } catch (_) {
      stored = null;
    }
    if (!mounted) return;
    setState(() {
      _biometricAvailable = available;
      _savedBiometricLogin = available && stored?.biometricEnabled == true;
      _checkingBiometrics = false;
    });
  }

  Future<void> _loginWithBiometrics() async {
    if (_submitting || !_savedBiometricLogin) return;
    setState(() {
      _submitting = true;
      _errorKey = null;
    });
    try {
      final authenticated = await widget.biometricAuthenticator.authenticate(
        reason: context.tr('driver.login.biometric_reason'),
      );
      if (!authenticated) {
        if (mounted) {
          setState(() => _errorKey = 'driver.login.biometric_failed');
        }
        return;
      }
      final stored = await widget.sessionStore.read();
      if (stored == null || !stored.biometricEnabled) {
        if (mounted) {
          setState(() => _errorKey = 'driver.login.biometric_unavailable');
        }
        return;
      }
      widget.onAuthenticated(stored.session, true, true);
    } catch (_) {
      if (mounted) {
        setState(() => _errorKey = 'driver.login.biometric_failed');
      }
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final repository = widget.repository;
    if (repository == null || _submitting) return;
    final email = _email.text.trim();
    final password = _password.text;
    if (email.isEmpty || password.isEmpty) {
      setState(() => _errorKey = 'driver.login.required');
      return;
    }

    setState(() {
      _submitting = true;
      _errorKey = null;
    });

    try {
      final session = await repository.login(email: email, password: password);
      if (mounted) {
        widget.onAuthenticated(session, _rememberMe, _enableBiometrics);
      }
    } on DriverAuthenticationException {
      if (mounted) setState(() => _errorKey = 'driver.login.invalid');
    } on DriverRoleDeniedException {
      if (mounted) setState(() => _errorKey = 'driver.login.role_denied');
    } on DriverOfflineException {
      if (mounted) setState(() => _errorKey = 'driver.offline');
    } catch (_) {
      if (mounted) setState(() => _errorKey = 'driver.error');
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final configured = widget.repository != null;
    return Scaffold(
      body: Stack(
        children: [
          Positioned.fill(
            child: DecoratedBox(
              decoration: BoxDecoration(
                gradient: LinearGradient(
                  begin: Alignment.topCenter,
                  end: Alignment.bottomCenter,
                  colors: [
                    FoodexBrand.greenDark,
                    FoodexBrand.green,
                    Theme.of(context).scaffoldBackgroundColor,
                    Theme.of(context).scaffoldBackgroundColor,
                  ],
                  stops: const [0, .34, .34, 1],
                ),
              ),
            ),
          ),
          SafeArea(
            child: Center(
              child: SingleChildScrollView(
                padding: const EdgeInsets.fromLTRB(20, 28, 20, 28),
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 430),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      const _DriverBrandHeader(),
                      const SizedBox(height: 24),
                      Card(
                        elevation: 0,
                        margin: EdgeInsets.zero,
                        shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(28),
                          side: const BorderSide(color: FoodexBrand.border),
                        ),
                        child: Padding(
                          padding: const EdgeInsets.all(22),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.stretch,
                            children: [
                              Text(
                                context.tr('driver.login.welcome'),
                                textAlign: TextAlign.center,
                                style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                                      fontWeight: FontWeight.w900,
                                    ),
                              ),
                              const SizedBox(height: 6),
                              Text(
                                context.tr('driver.login.subtitle'),
                                textAlign: TextAlign.center,
                                style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                                      color: FoodexBrand.muted,
                                    ),
                              ),
                              const SizedBox(height: 24),
                              if (!configured)
                                Container(
                                  key: const Key('driver-config-missing'),
                                  padding: const EdgeInsets.all(14),
                                  decoration: BoxDecoration(
                                    color: FoodexBrand.orangeSoft,
                                    borderRadius: BorderRadius.circular(14),
                                  ),
                                  child: Text(
                                    context.tr('driver.config.missing'),
                                    textAlign: TextAlign.center,
                                  ),
                                ),
                              if (configured) ...[
                                TextField(
                                  key: const Key('driver-login-email'),
                                  controller: _email,
                                  keyboardType: TextInputType.emailAddress,
                                  autofillHints: const [AutofillHints.email],
                                  textInputAction: TextInputAction.next,
                                  autocorrect: false,
                                  enableSuggestions: false,
                                  decoration: InputDecoration(
                                    labelText: context.tr('driver.login.email'),
                                    prefixIcon: const Icon(Icons.alternate_email_rounded),
                                  ),
                                ),
                                const SizedBox(height: 14),
                                TextField(
                                  key: const Key('driver-login-password'),
                                  controller: _password,
                                  obscureText: !_passwordVisible,
                                  autofillHints: const [AutofillHints.password],
                                  textInputAction: TextInputAction.done,
                                  autocorrect: false,
                                  enableSuggestions: false,
                                  decoration: InputDecoration(
                                    labelText: context.tr('driver.login.password'),
                                    prefixIcon: const Icon(Icons.lock_outline_rounded),
                                    suffixIcon: IconButton(
                                      key: const Key('driver-password-toggle'),
                                      tooltip: context.tr(
                                        _passwordVisible
                                            ? 'driver.login.hide_password'
                                            : 'driver.login.show_password',
                                      ),
                                      onPressed: () => setState(
                                        () => _passwordVisible = !_passwordVisible,
                                      ),
                                      icon: Icon(
                                        _passwordVisible
                                            ? Icons.visibility_off_outlined
                                            : Icons.visibility_outlined,
                                      ),
                                    ),
                                  ),
                                  onSubmitted: (_) => _submit(),
                                ),
                                const SizedBox(height: 8),
                                if (_errorKey != null)
                                  Container(
                                    key: const Key('driver-login-error'),
                                    padding: const EdgeInsets.all(12),
                                    decoration: BoxDecoration(
                                      color: const Color(0xFFFFF0F0),
                                      borderRadius: BorderRadius.circular(14),
                                      border: Border.all(
                                        color: FoodexBrand.red.withAlpha(64),
                                      ),
                                    ),
                                    child: Text(
                                      context.tr(_errorKey!),
                                      textAlign: TextAlign.center,
                                      style: const TextStyle(
                                        color: FoodexBrand.red,
                                        fontWeight: FontWeight.w700,
                                      ),
                                    ),
                                  ),
                                const SizedBox(height: 16),
                                FilledButton.icon(
                                  key: const Key('driver-login-submit'),
                                  onPressed: _submitting ? null : _submit,
                                  icon: _submitting
                                      ? const SizedBox.square(
                                          dimension: 20,
                                          child: CircularProgressIndicator(
                                            strokeWidth: 2,
                                            color: Colors.white,
                                          ),
                                        )
                                      : const Icon(Icons.login_rounded),
                                  label: Text(context.tr('driver.login.submit')),
                                ),
                                if (_savedBiometricLogin) ...[
                                  const SizedBox(height: 12),
                                  OutlinedButton.icon(
                                    key: const Key('driver-login-biometric'),
                                    onPressed:
                                        _submitting ? null : _loginWithBiometrics,
                                    icon: const Icon(Icons.fingerprint_rounded),
                                    label: Text(
                                      context.tr('driver.login.biometric'),
                                    ),
                                  ),
                                ],
                                const SizedBox(height: 10),
                                CheckboxListTile(
                                  key: const Key('driver-login-remember'),
                                  contentPadding: EdgeInsets.zero,
                                  controlAffinity: ListTileControlAffinity.leading,
                                  value: _rememberMe,
                                  dense: true,
                                  title: Text(context.tr('driver.login.remember_me')),
                                  onChanged: _submitting
                                      ? null
                                      : (value) => setState(() {
                                            _rememberMe = value ?? false;
                                            if (!_rememberMe) {
                                              _enableBiometrics = false;
                                            }
                                          }),
                                ),
                                if (!_checkingBiometrics && _biometricAvailable)
                                  CheckboxListTile(
                                    key: const Key('driver-login-biometric-toggle'),
                                    contentPadding: EdgeInsets.zero,
                                    controlAffinity: ListTileControlAffinity.leading,
                                    value: _enableBiometrics,
                                    dense: true,
                                    title: Text(
                                      context.tr('driver.login.enable_biometric'),
                                    ),
                                    subtitle: Text(
                                      context.tr('driver.login.biometric_hint'),
                                    ),
                                    onChanged: _submitting
                                        ? null
                                        : (value) => setState(() {
                                              _enableBiometrics = value ?? false;
                                              if (_enableBiometrics) {
                                                _rememberMe = true;
                                              }
                                            }),
                                  ),
                              ],
                            ],
                          ),
                        ),
                      ),
                      const SizedBox(height: 16),
                      Text(
                        context.tr('driver.login.secure'),
                        textAlign: TextAlign.center,
                        style: Theme.of(context).textTheme.bodySmall?.copyWith(
                              color: FoodexBrand.muted,
                            ),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _DriverBrandHeader extends StatelessWidget {
  const _DriverBrandHeader();

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        Container(
          constraints: const BoxConstraints(maxWidth: 330),
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
          decoration: BoxDecoration(
            color: Colors.white,
            borderRadius: BorderRadius.circular(22),
            boxShadow: const [
              BoxShadow(
                color: Color(0x2A003223),
                blurRadius: 28,
                offset: Offset(0, 14),
              ),
            ],
          ),
          child: Image.asset(
            'assets/branding/foodex-economical-group.webp',
            height: 96,
            fit: BoxFit.contain,
            semanticLabel: context.tr('driver.brand.economical_group'),
          ),
        ),
        const SizedBox(height: 12),
        Text(
          context.tr('driver.app.title'),
          key: const Key('driver-app-identity'),
          textAlign: TextAlign.center,
          style: const TextStyle(
            color: Colors.white,
            fontSize: 16,
            fontWeight: FontWeight.w800,
          ),
        ),
      ],
    );
  }
}
