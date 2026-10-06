import 'package:flutter/material.dart';

import '../../core/auth/van_auth_persistence.dart';
import '../../core/auth/van_session.dart';
import '../../core/auth/van_session_store.dart';
import '../../core/theme/foodex_van_theme.dart';

typedef VanAuthenticatedCallback = Future<void> Function(
  VanSession session,
  bool rememberMe,
  bool biometricEnabled,
);

class VanLoginScreen extends StatefulWidget {
  const VanLoginScreen({
    super.key,
    required this.repository,
    required this.onAuthenticated,
    required this.sessionStore,
    required this.preferences,
    required this.biometricAuthenticator,
  });

  final VanAuthRepository repository;
  final VanAuthenticatedCallback onAuthenticated;
  final VanSessionStore sessionStore;
  final VanAuthPreferences preferences;
  final VanBiometricAuthenticator biometricAuthenticator;

  @override
  State<VanLoginScreen> createState() => _VanLoginScreenState();
}

class _VanLoginScreenState extends State<VanLoginScreen> {
  final _email = TextEditingController();
  final _password = TextEditingController();
  bool _submitting = false;
  bool _passwordVisible = false;
  late bool _rememberMe;
  late bool _enableBiometrics;
  bool _biometricAvailable = false;
  bool _savedBiometricLogin = false;
  bool _checkingBiometrics = true;
  String? _error;

  String _text(String en, String ar) =>
      Localizations.localeOf(context).languageCode == 'ar' ? ar : en;

  @override
  void initState() {
    super.initState();
    _rememberMe = widget.preferences.rememberMe;
    _enableBiometrics = widget.preferences.biometricEnabled;
    _loadAuthOptions();
  }

  Future<void> _loadAuthOptions() async {
    final available = await widget.biometricAuthenticator.isAvailable();
    VanSession? stored;
    if (widget.preferences.rememberMe &&
        widget.preferences.biometricEnabled) {
      try {
        stored = await widget.sessionStore.read();
      } catch (_) {
        stored = null;
      }
    }

    if (!mounted) return;
    setState(() {
      _biometricAvailable = available;
      _savedBiometricLogin =
          available &&
          widget.preferences.biometricEnabled &&
          stored != null &&
          stored.canUseVan;
      _checkingBiometrics = false;
    });
  }

  Future<void> _loginWithBiometrics() async {
    if (_submitting || !_savedBiometricLogin) return;

    setState(() {
      _submitting = true;
      _error = null;
    });

    try {
      final authenticated = await widget.biometricAuthenticator.authenticate(
        reason: _text(
          'Confirm your identity to unlock the Van app.',
          'أكد هويتك لفتح تطبيق الفان.',
        ),
      );
      if (!authenticated) {
        if (mounted) {
          setState(
            () => _error = _text(
              'Biometric verification failed. Use your password to sign in.',
              'فشل التحقق بالبصمة. استخدم كلمة المرور لتسجيل الدخول.',
            ),
          );
        }
        return;
      }

      final stored = await widget.sessionStore.read();
      if (stored == null || !stored.canUseVan) {
        if (mounted) {
          setState(
            () => _error = _text(
              'The saved session is no longer available. Sign in again.',
              'جلسة الدخول المحفوظة لم تعد متاحة. سجّل الدخول مرة أخرى.',
            ),
          );
        }
        return;
      }

      await widget.onAuthenticated(stored, true, true);
    } catch (_) {
      if (mounted) {
        setState(
          () => _error = _text(
            'Biometric verification failed. Use your password to sign in.',
            'فشل التحقق بالبصمة. استخدم كلمة المرور لتسجيل الدخول.',
          ),
        );
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
    if (_submitting) return;
    if (_email.text.trim().isEmpty || _password.text.isEmpty) {
      setState(
        () => _error = _text(
          'Email and password are required.',
          'البريد الإلكتروني وكلمة المرور مطلوبان.',
        ),
      );
      return;
    }

    setState(() {
      _submitting = true;
      _error = null;
    });

    try {
      final session = await widget.repository.login(
        email: _email.text.trim(),
        password: _password.text,
      );
      await widget.onAuthenticated(
        session,
        _rememberMe,
        _rememberMe && _enableBiometrics,
      );
    } on VanAuthenticationException {
      if (mounted) {
        setState(
          () => _error = _text(
            'Invalid email or password.',
            'البريد الإلكتروني أو كلمة المرور غير صحيحة.',
          ),
        );
      }
    } on VanAccessDeniedException {
      if (mounted) {
        setState(
          () => _error = _text(
            'This account is not authorized for the Van app.',
            'هذا الحساب غير مصرح له باستخدام تطبيق الفان.',
          ),
        );
      }
    } on VanOfflineException {
      if (mounted) {
        setState(
          () => _error = _text(
            'No network connection. Try again.',
            'لا يوجد اتصال بالشبكة. حاول مرة أخرى.',
          ),
        );
      }
    } catch (_) {
      if (mounted) {
        setState(
          () => _error = _text(
            'Unable to sign in right now.',
            'تعذر تسجيل الدخول الآن.',
          ),
        );
      }
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 430),
              child: Card(
                elevation: 0,
                child: Padding(
                  padding: const EdgeInsets.all(24),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      const Icon(
                        Icons.local_shipping_outlined,
                        size: 48,
                        color: FoodexVanTokens.green,
                      ),
                      const SizedBox(height: 10),
                      Text(
                        _text('Van App', 'تطبيق الفان'),
                        key: const Key('van-login-app-identity'),
                        textAlign: TextAlign.center,
                        style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                              fontWeight: FontWeight.w800,
                            ),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        _text(
                          'FOODEX field operations',
                          'عمليات فودكس الميدانية',
                        ),
                        textAlign: TextAlign.center,
                        style: Theme.of(context).textTheme.bodyMedium,
                      ),
                      const SizedBox(height: 20),
                      TextField(
                        key: const Key('van-login-email'),
                        controller: _email,
                        keyboardType: TextInputType.emailAddress,
                        textInputAction: TextInputAction.next,
                        autocorrect: false,
                        enableSuggestions: false,
                        decoration: InputDecoration(
                          labelText: _text('Email', 'البريد الإلكتروني'),
                          prefixIcon: const Icon(Icons.alternate_email),
                        ),
                      ),
                      const SizedBox(height: 14),
                      TextField(
                        key: const Key('van-login-password'),
                        controller: _password,
                        obscureText: !_passwordVisible,
                        textInputAction: TextInputAction.done,
                        autocorrect: false,
                        enableSuggestions: false,
                        onSubmitted: (_) => _submit(),
                        decoration: InputDecoration(
                          labelText: _text('Password', 'كلمة المرور'),
                          prefixIcon: const Icon(Icons.lock_outline),
                          suffixIcon: IconButton(
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
                      ),
                      if (_error != null) ...[
                        const SizedBox(height: 12),
                        Text(
                          _error!,
                          key: const Key('van-login-error'),
                          textAlign: TextAlign.center,
                          style: const TextStyle(color: FoodexVanTokens.danger),
                        ),
                      ],
                      const SizedBox(height: 8),
                      CheckboxListTile(
                        key: const Key('van-login-remember'),
                        contentPadding: EdgeInsets.zero,
                        controlAffinity: ListTileControlAffinity.leading,
                        dense: true,
                        value: _rememberMe,
                        onChanged: _submitting
                            ? null
                            : (value) => setState(() {
                                  _rememberMe = value ?? false;
                                  if (!_rememberMe) {
                                    _enableBiometrics = false;
                                  }
                                }),
                        title: Text(_text('Remember me', 'تذكرني')),
                        subtitle: Text(
                          _text(
                            'Keep the authenticated session in secure device storage.',
                            'احتفظ بجلسة الدخول في التخزين الآمن للجهاز.',
                          ),
                        ),
                      ),
                      if (!_checkingBiometrics && _biometricAvailable)
                        CheckboxListTile(
                          key: const Key('van-login-biometric-toggle'),
                          contentPadding: EdgeInsets.zero,
                          controlAffinity: ListTileControlAffinity.leading,
                          dense: true,
                          value: _enableBiometrics,
                          onChanged: _submitting
                              ? null
                              : (value) => setState(() {
                                    _enableBiometrics = value ?? false;
                                    if (_enableBiometrics) {
                                      _rememberMe = true;
                                    }
                                  }),
                          title: Text(
                            _text(
                              'Use biometric unlock',
                              'استخدم فتح التطبيق بالبصمة',
                            ),
                          ),
                          subtitle: Text(
                            _text(
                              'Your password is never stored for biometric sign-in.',
                              'لا يتم تخزين كلمة المرور لتسجيل الدخول بالبصمة.',
                            ),
                          ),
                        ),
                      const SizedBox(height: 8),
                      FilledButton.icon(
                        key: const Key('van-login-submit'),
                        onPressed: _submitting ? null : _submit,
                        icon: _submitting
                            ? const SizedBox.square(
                                dimension: 20,
                                child: CircularProgressIndicator(
                                  strokeWidth: 2,
                                  color: Colors.white,
                                ),
                              )
                            : const Icon(Icons.login),
                        label: Text(_text('Sign in', 'تسجيل الدخول')),
                      ),
                      if (_savedBiometricLogin) ...[
                        const SizedBox(height: 12),
                        OutlinedButton.icon(
                          key: const Key('van-login-biometric'),
                          onPressed:
                              _submitting ? null : _loginWithBiometrics,
                          icon: const Icon(Icons.fingerprint_rounded),
                          label: Text(
                            _text(
                              'Unlock with biometrics',
                              'فتح التطبيق بالبصمة',
                            ),
                          ),
                        ),
                      ],
                    ],
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
