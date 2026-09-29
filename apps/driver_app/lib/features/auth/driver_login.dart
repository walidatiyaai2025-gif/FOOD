import 'package:flutter/material.dart';

import '../../core/auth/driver_session.dart';
import '../../core/localization/driver_translations.dart';
import '../../core/theme/foodex_theme.dart';

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
  final _username = TextEditingController();
  bool _submitting = false;
  String? _errorKey;

  @override
  void dispose() {
    _username.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final repository = widget.repository;
    if (repository == null || _submitting) return;
    final username = _username.text.trim();
    if (username.isEmpty) {
      setState(() => _errorKey = 'driver.login.required');
      return;
    }

    setState(() {
      _submitting = true;
      _errorKey = null;
    });

    try {
      final session = await repository.login(username: username);
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
                                  key: const Key('driver-login-username'),
                                  controller: _username,
                                  textInputAction: TextInputAction.done,
                                  autocorrect: false,
                                  enableSuggestions: false,
                                  decoration: InputDecoration(
                                    labelText: context.tr('driver.login.username'),
                                    prefixIcon: const Icon(Icons.person_outline_rounded),
                                  ),
                                  onSubmitted: (_) => _submit(),
                                ),
                                const SizedBox(height: 16),
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
            semanticLabel: 'FOODEX Economical Group',
          ),
        ),
        const SizedBox(height: 12),
        Text(
          context.tr('driver.app.title'),
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
