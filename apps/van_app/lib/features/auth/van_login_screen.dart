import 'package:flutter/material.dart';

import '../../core/auth/van_session.dart';
import '../../core/theme/foodex_van_theme.dart';

typedef VanAuthenticatedCallback = Future<void> Function(
  VanSession session,
  bool persist,
);

class VanLoginScreen extends StatefulWidget {
  const VanLoginScreen({
    super.key,
    required this.repository,
    required this.onAuthenticated,
  });

  final VanAuthRepository repository;
  final VanAuthenticatedCallback onAuthenticated;

  @override
  State<VanLoginScreen> createState() => _VanLoginScreenState();
}

class _VanLoginScreenState extends State<VanLoginScreen> {
  final _email = TextEditingController();
  final _password = TextEditingController();
  bool _submitting = false;
  bool _passwordVisible = false;
  bool _persist = true;
  String? _error;

  String _text(String en, String ar) =>
      Localizations.localeOf(context).languageCode == 'ar' ? ar : en;

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (_submitting) return;
    if (_email.text.trim().isEmpty || _password.text.isEmpty) {
      setState(() => _error = _text('Email and password are required.', 'البريد الإلكتروني وكلمة المرور مطلوبان.'));
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
      await widget.onAuthenticated(session, _persist);
    } on VanAuthenticationException {
      if (mounted) {
        setState(() => _error = _text('Invalid email or password.', 'البريد الإلكتروني أو كلمة المرور غير صحيحة.'));
      }
    } on VanAccessDeniedException {
      if (mounted) {
        setState(() => _error = _text('This account is not authorized for the Van app.', 'هذا الحساب غير مصرح له باستخدام تطبيق سيارة البيع.'));
      }
    } on VanOfflineException {
      if (mounted) {
        setState(() => _error = _text('No network connection. Try again.', 'لا يوجد اتصال بالشبكة. حاول مرة أخرى.'));
      }
    } catch (_) {
      if (mounted) {
        setState(() => _error = _text('Unable to sign in right now.', 'تعذر تسجيل الدخول الآن.'));
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
                      const SizedBox(height: 12),
                      Text(
                        'FOODEX Van',
                        textAlign: TextAlign.center,
                        style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                              fontWeight: FontWeight.w800,
                            ),
                      ),
                      const SizedBox(height: 6),
                      Text(
                        _text(
                          'Sign in with your authorized field-operations account.',
                          'سجّل الدخول بحساب عمليات ميدانية مصرح به.',
                        ),
                        textAlign: TextAlign.center,
                      ),
                      const SizedBox(height: 24),
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
                        key: const Key('van-login-persist'),
                        contentPadding: EdgeInsets.zero,
                        value: _persist,
                        onChanged: _submitting
                            ? null
                            : (value) => setState(() => _persist = value ?? false),
                        title: Text(_text('Keep me signed in securely', 'الاحتفاظ بتسجيل الدخول بشكل آمن')),
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
