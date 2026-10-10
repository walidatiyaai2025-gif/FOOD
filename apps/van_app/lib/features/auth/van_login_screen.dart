import 'package:flutter/material.dart';
import '../../core/auth/van_auth_persistence.dart';
import '../../core/auth/van_session.dart';
import '../../core/auth/van_session_store.dart';

typedef VanAuthenticatedCallback = Future<void> Function(
  VanSession session,
  bool rememberMe,
  bool biometricEnabled,
);

abstract final class _LoginColors {
  static const deepGreen = Color(0xFF00452F);
  static const deepGreenSoft = Color(0xFF0A5B40);
  static const mint = Color(0xFFEDF7F1);
  static const white = Color(0xFFFFFFFF);
  static const ink = Color(0xFF17231D);
  static const muted = Color(0xFF68766E);
  static const border = Color(0xFFDDE8E1);
  static const danger = Color(0xFFE5484D);
}

class VanLoginScreen extends StatefulWidget {
  const VanLoginScreen({
    super.key,
    required this.repository,
    required this.onAuthenticated,
    this.sessionStore,
    this.preferences = const VanAuthPreferences(),
    this.biometricAuthenticator,
  });

  final VanAuthRepository repository;
  final VanAuthenticatedCallback onAuthenticated;
  final VanSessionStore? sessionStore;
  final VanAuthPreferences preferences;
  final VanBiometricAuthenticator? biometricAuthenticator;

  @override
  State<VanLoginScreen> createState() => _VanLoginScreenState();
}

class _VanLoginScreenState extends State<VanLoginScreen> {
  final _email = TextEditingController();
  final _password = TextEditingController();

  bool _submitting = false;
  late bool _rememberMe;
  late bool _enableBiometrics;
  bool _biometricAvailable = false;
  bool _savedBiometricLogin = false;
  bool _checkingBiometrics = true;
  String? _error;
  late final VanSessionStore _sessionStore;
  late final VanBiometricAuthenticator _biometricAuthenticator;

  bool get _arabic => Localizations.localeOf(context).languageCode == 'ar';
  String _text(String en, String ar) => _arabic ? ar : en;

  @override
  void initState() {
    super.initState();
    _sessionStore = widget.sessionStore ?? SecureVanSessionStore();
    _biometricAuthenticator =
        widget.biometricAuthenticator ?? LocalAuthVanBiometricAuthenticator();
    _rememberMe = widget.preferences.rememberMe;
    _enableBiometrics = widget.preferences.biometricEnabled;
    _loadAuthOptions();
  }

  Future<void> _loadAuthOptions() async {
    final available = await _biometricAuthenticator.isAvailable();
    VanSession? stored;
    if (widget.preferences.rememberMe &&
        widget.preferences.biometricEnabled) {
      try {
        stored = await _sessionStore.read();
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
      final authenticated = await _biometricAuthenticator.authenticate(
        reason: _text(
          'Confirm your identity to unlock the Van app.',
          'أكد هويتك لفتح تطبيق الفان.',
        ),
      );
      if (!authenticated) {
        if (mounted) {
          setState(() => _error = _text(
                'Biometric verification failed. Use your password to sign in.',
                'فشل التحقق بالبصمة. استخدم كلمة المرور لتسجيل الدخول.',
              ));
        }
        return;
      }

      final stored = await _sessionStore.read();
      if (stored == null || !stored.canUseVan) {
        if (mounted) {
          setState(() => _error = _text(
                'The saved session is no longer available. Sign in again.',
                'جلسة الدخول المحفوظة لم تعد متاحة. سجّل الدخول مرة أخرى.',
              ));
        }
        return;
      }

      await widget.onAuthenticated(stored, true, true);
    } catch (_) {
      if (mounted) {
        setState(() => _error = _text(
              'Biometric verification failed. Use your password to sign in.',
              'فشل التحقق بالبصمة. استخدم كلمة المرور لتسجيل الدخول.',
            ));
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
      setState(() => _error = _text(
            'Email and password are required.',
            'البريد الإلكتروني وكلمة المرور مطلوبان.',
          ));
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
        setState(() => _error = _text(
              'Invalid email or password.',
              'البريد الإلكتروني أو كلمة المرور غير صحيحة.',
            ));
      }
    } on VanAccessDeniedException {
      if (mounted) {
        setState(() => _error = _text(
              'This account is not authorized for the Van app.',
              'هذا الحساب غير مصرح له باستخدام تطبيق الفان.',
            ));
      }
    } on VanOfflineException {
      if (mounted) {
        setState(() => _error = _text(
              'No network connection. Try again.',
              'لا يوجد اتصال بالشبكة. حاول مرة أخرى.',
            ));
      }
    } catch (_) {
      if (mounted) {
        setState(() => _error = _text(
              'Unable to sign in right now.',
              'تعذر تسجيل الدخول الآن.',
            ));
      }
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  void _toggleRemember(bool value) {
    if (_submitting) return;
    setState(() {
      _rememberMe = value;
      if (!value) _enableBiometrics = false;
    });
  }

  void _toggleBiometric() {
    if (_submitting || !_biometricAvailable) return;
    if (_savedBiometricLogin && _enableBiometrics) {
      _loginWithBiometrics();
      return;
    }
    setState(() {
      _enableBiometrics = !_enableBiometrics;
      if (_enableBiometrics) _rememberMe = true;
    });
  }

  void _showInfo(String title, String body) {
    showModalBottomSheet<void>(
      context: context,
      showDragHandle: true,
      builder: (context) => SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(24, 8, 24, 28),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(
                title,
                textAlign: TextAlign.center,
                style: TextStyle(
                  fontSize: 18,
                  fontWeight: FontWeight.w800,
                  color: _LoginColors.deepGreen,
                ),
              ),
              const SizedBox(height: 10),
              Text(
                body,
                textAlign: TextAlign.center,
                style: TextStyle(
                  fontSize: 13,
                  height: 1.5,
                  color: _LoginColors.muted,
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
    final direction = _arabic ? TextDirection.rtl : TextDirection.ltr;
    final fieldBorder = OutlineInputBorder(
      borderRadius: BorderRadius.circular(999),
      borderSide: const BorderSide(color: _LoginColors.border),
    );
    final focusBorder = OutlineInputBorder(
      borderRadius: BorderRadius.circular(999),
      borderSide: const BorderSide(
        color: _LoginColors.deepGreenSoft,
        width: 1.5,
      ),
    );

    return Directionality(
      textDirection: direction,
      child: Scaffold(
        key: const ValueKey('van-customer-parity-login'),
        backgroundColor: _LoginColors.white,
        body: SafeArea(
          child: SingleChildScrollView(
            padding: const EdgeInsets.only(bottom: 20),
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
                        key: const ValueKey('van-login-header'),
                        fit: BoxFit.contain,
                        semanticLabel: _text('FOODEX Economic Group', 'مجموعة فودكس الاقتصادية'),
                      ),
                    ),
                    const SizedBox(height: 8),
                    ClipRect(
                      child: AspectRatio(
                        aspectRatio: 941 / 496,
                        child: Image.asset(
                          'assets/branding/login_reference/foodex_truck_hero.png',
                          key: const ValueKey('van-login-hero'),
                          fit: BoxFit.cover,
                          alignment: Alignment.center,
                        ),
                      ),
                    ),
                    Transform.translate(
                      offset: const Offset(0, -24),
                      child: Container(
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
                              _text('Van App', 'تطبيق الفان'),
                              key: const Key('van-login-app-identity'),
                              textAlign: TextAlign.center,
                              style: TextStyle(
                                color: _LoginColors.deepGreen,
                                fontSize: 20,
                                fontWeight: FontWeight.w900,
                              ),
                            ),
                            const SizedBox(height: 4),
                            Text(
                              _text('Sign in', 'تسجيل الدخول'),
                              key: const ValueKey('van-login-title'),
                              textAlign: TextAlign.center,
                              style: TextStyle(
                                color: _LoginColors.ink,
                                fontSize: 15,
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
                              style: TextStyle(
                                color: _LoginColors.muted,
                                fontSize: 12,
                                fontWeight: FontWeight.w500,
                              ),
                            ),
                            const SizedBox(height: 14),
                            TextField(
                              key: const Key('van-login-email'),
                              controller: _email,
                              keyboardType: TextInputType.emailAddress,
                              textInputAction: TextInputAction.next,
                              autocorrect: false,
                              enableSuggestions: false,
                              style: TextStyle(fontSize: 14),
                              decoration: InputDecoration(
                                hintText: _text('Email', 'البريد الإلكتروني'),
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
                                contentPadding: const EdgeInsets.symmetric(
                                  horizontal: 20,
                                  vertical: 18,
                                ),
                              ),
                            ),
                            const SizedBox(height: 12),
                            TextField(
                              key: const Key('van-login-password'),
                              controller: _password,
                              obscureText: true,
                              textInputAction: TextInputAction.done,
                              autocorrect: false,
                              enableSuggestions: false,
                              onSubmitted: (_) => _submit(),
                              style: TextStyle(fontSize: 14),
                              decoration: InputDecoration(
                                hintText: _text('Password', 'كلمة المرور'),
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
                                contentPadding: const EdgeInsets.symmetric(
                                  horizontal: 20,
                                  vertical: 18,
                                ),
                              ),
                            ),
                            const SizedBox(height: 12),
                            Row(
                              children: [
                                Expanded(
                                  child: InkWell(
                                    key: const Key('van-login-remember'),
                                    onTap: _submitting
                                        ? null
                                        : () => _toggleRemember(!_rememberMe),
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
                                            onChanged: _submitting
                                                ? null
                                                : (value) => _toggleRemember(
                                                      value ?? false,
                                                    ),
                                            visualDensity:
                                                VisualDensity.compact,
                                            activeColor:
                                                _LoginColors.deepGreenSoft,
                                          ),
                                          Flexible(
                                            child: Text(
                                              _text(
                                                'Remember me',
                                                'تذكرني',
                                              ),
                                              maxLines: 1,
                                              overflow: TextOverflow.ellipsis,
                                              style: TextStyle(
                                                fontSize: 12,
                                              ),
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
                                    key: Key(
                                      _savedBiometricLogin
                                          ? 'van-login-biometric'
                                          : 'van-login-biometric-toggle',
                                    ),
                                    onPressed:
                                        _biometricAvailable && !_submitting
                                            ? _toggleBiometric
                                            : null,
                                    icon: Image.asset(
                                      'assets/branding/login_reference/fingerprint_icon.png',
                                      width: 30,
                                      height: 30,
                                      opacity: AlwaysStoppedAnimation<double>(
                                        _checkingBiometrics ? 0.45 : 1,
                                      ),
                                    ),
                                    label: Text(
                                      _text('Biometric', 'البصمة'),
                                      maxLines: 1,
                                      overflow: TextOverflow.ellipsis,
                                      style: TextStyle(
                                        fontSize: 12,
                                        fontWeight: FontWeight.w700,
                                      ),
                                    ),
                                    style: OutlinedButton.styleFrom(
                                      minimumSize: const Size(0, 48),
                                      foregroundColor: _LoginColors.deepGreen,
                                      backgroundColor: _enableBiometrics
                                          ? _LoginColors.mint
                                          : _LoginColors.white,
                                      side: BorderSide(
                                        color: _enableBiometrics
                                            ? _LoginColors.deepGreenSoft
                                            : _LoginColors.border,
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
                            if (_error != null) ...[
                              const SizedBox(height: 12),
                              Text(
                                _error!,
                                key: const Key('van-login-error'),
                                textAlign: TextAlign.center,
                                style: TextStyle(
                                  color: _LoginColors.danger,
                                  fontSize: 12,
                                  fontWeight: FontWeight.w600,
                                ),
                              ),
                            ],
                            const SizedBox(height: 12),
                            SizedBox(
                              height: 58,
                              child: FilledButton(
                                key: const Key('van-login-submit'),
                                onPressed: _submitting ? null : _submit,
                                style: FilledButton.styleFrom(
                                  backgroundColor:
                                      _LoginColors.deepGreenSoft,
                                  foregroundColor: _LoginColors.white,
                                  shape: RoundedRectangleBorder(
                                    borderRadius: BorderRadius.circular(18),
                                  ),
                                ),
                                child: _submitting
                                    ? const SizedBox.square(
                                        dimension: 20,
                                        child: CircularProgressIndicator(
                                          strokeWidth: 2,
                                          color: _LoginColors.white,
                                        ),
                                      )
                                    : Text(
                                        _text('Sign in', 'تسجيل الدخول'),
                                        style: TextStyle(
                                          color: _LoginColors.white,
                                          fontSize: 15,
                                          fontWeight: FontWeight.w700,
                                        ),
                                      ),
                              ),
                            ),
                            const SizedBox(height: 8),
                            OutlinedButton(
                              key: const ValueKey(
                                'van-business-forgot-password',
                              ),
                              onPressed: _submitting
                                  ? null
                                  : () => _showInfo(
                                        _text(
                                          'Forgot password?',
                                          'نسيت كلمة المرور؟',
                                        ),
                                        _text(
                                          'Contact FOODEX administration to reset the password for your approved Van account.',
                                          'تواصل مع إدارة FOODEX لإعادة تعيين كلمة مرور حساب الفان المعتمد.',
                                        ),
                                      ),
                              style: OutlinedButton.styleFrom(
                                minimumSize: const Size.fromHeight(52),
                                foregroundColor:
                                    _LoginColors.deepGreenSoft,
                                backgroundColor: const Color(0xFFF9FCFA),
                                side: const BorderSide(
                                  color: Color(0xFFE2E9E5),
                                ),
                                shape: RoundedRectangleBorder(
                                  borderRadius: BorderRadius.circular(18),
                                ),
                              ),
                              child: Text(
                                _text(
                                  'Forgot password?',
                                  'نسيت كلمة المرور؟',
                                ),
                                style: TextStyle(
                                  fontWeight: FontWeight.w700,
                                ),
                              ),
                            ),
                            const SizedBox(height: 10),
                            OutlinedButton(
                              key: const ValueKey('van-business-contact-us'),
                              onPressed: _submitting
                                  ? null
                                  : () => _showInfo(
                                        _text('Contact us', 'تواصل معنا'),
                                        _text(
                                          'Van accounts and assignments are managed from the FOODEX management dashboard. Contact your administrator for activation or sign-in help.',
                                          'حسابات الفان والإسنادات تُدار من لوحة إدارة FOODEX. تواصل مع المسؤول لتفعيل الحساب أو المساعدة في الدخول.',
                                        ),
                                      ),
                              style: OutlinedButton.styleFrom(
                                minimumSize: const Size.fromHeight(54),
                                foregroundColor: _LoginColors.deepGreen,
                                side: const BorderSide(
                                  color: _LoginColors.deepGreenSoft,
                                ),
                                shape: RoundedRectangleBorder(
                                  borderRadius: BorderRadius.circular(18),
                                ),
                              ),
                              child: Text(
                                _text('Contact us', 'تواصل معنا'),
                                style: TextStyle(
                                  fontWeight: FontWeight.w800,
                                ),
                              ),
                            ),
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
                              style: TextStyle(
                                color: _LoginColors.muted,
                                fontSize: 11,
                              ),
                            ),
                          ),
                          Text(
                            _text('Version 1.0.68', 'الإصدار 1.0.68'),
                            key: const ValueKey('van-business-login-version'),
                            style: TextStyle(
                              color: _LoginColors.muted,
                              fontSize: 11,
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
        ),
      ),
    );
  }
}
