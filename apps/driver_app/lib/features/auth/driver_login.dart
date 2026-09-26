import 'package:flutter/material.dart';

import '../../core/auth/driver_session.dart';
import '../../core/localization/driver_translations.dart';

class DriverLoginPage extends StatefulWidget {
  const DriverLoginPage({
    super.key,
    required this.repository,
    required this.onAuthenticated,
  });

  final DriverAuthRepository? repository;
  final ValueChanged<DriverSession> onAuthenticated;

  @override
  State<DriverLoginPage> createState() => _DriverLoginPageState();
}

class _DriverLoginPageState extends State<DriverLoginPage> {
  final _email = TextEditingController();
  final _password = TextEditingController();
  bool _submitting = false;
  String? _errorKey;

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
      if (mounted) widget.onAuthenticated(session);
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
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 420),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text(
                    context.tr('driver.app.title'),
                    textAlign: TextAlign.center,
                    style: Theme.of(context).textTheme.headlineMedium?.copyWith(
                          fontWeight: FontWeight.w800,
                        ),
                  ),
                  const SizedBox(height: 28),
                  if (!configured)
                    Text(
                      context.tr('driver.config.missing'),
                      key: const Key('driver-config-missing'),
                      textAlign: TextAlign.center,
                    ),
                  if (configured) ...[
                    TextField(
                      key: const Key('driver-login-email'),
                      controller: _email,
                      keyboardType: TextInputType.emailAddress,
                      autofillHints: const [AutofillHints.email],
                      decoration: InputDecoration(
                        labelText: context.tr('driver.login.email'),
                      ),
                    ),
                    const SizedBox(height: 12),
                    TextField(
                      key: const Key('driver-login-password'),
                      controller: _password,
                      obscureText: true,
                      autofillHints: const [AutofillHints.password],
                      decoration: InputDecoration(
                        labelText: context.tr('driver.login.password'),
                      ),
                      onSubmitted: (_) => _submit(),
                    ),
                    const SizedBox(height: 16),
                    if (_errorKey != null)
                      Text(
                        context.tr(_errorKey!),
                        key: const Key('driver-login-error'),
                        textAlign: TextAlign.center,
                      ),
                    const SizedBox(height: 12),
                    FilledButton(
                      key: const Key('driver-login-submit'),
                      onPressed: _submitting ? null : _submit,
                      child: _submitting
                          ? const SizedBox.square(
                              dimension: 20,
                              child: CircularProgressIndicator(strokeWidth: 2),
                            )
                          : Text(context.tr('driver.login.submit')),
                    ),
                  ],
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
