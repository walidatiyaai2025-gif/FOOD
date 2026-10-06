import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';

import 'core/api/http_van_api.dart';
import 'core/auth/van_session.dart';
import 'core/auth/van_session_store.dart';
import 'core/config/foodex_environment.dart';
import 'core/theme/foodex_van_theme.dart';
import 'features/auth/van_login_screen.dart';
import 'features/foundation/van_foundation_screen.dart';
import 'features/wallet/http_van_wallet_repository.dart';
import 'features/wallet/van_wallet_contract.dart';

class FoodexVanApp extends StatefulWidget {
  const FoodexVanApp({
    super.key,
    this.locale = const Locale('ar'),
    this.authRepository,
    this.sessionStore,
    this.initialSession,
    this.walletRepository,
  });

  final Locale locale;
  final VanAuthRepository? authRepository;
  final VanSessionStore? sessionStore;
  final VanSession? initialSession;
  final VanWalletRepository? walletRepository;

  @override
  State<FoodexVanApp> createState() => _FoodexVanAppState();
}

class _FoodexVanAppState extends State<FoodexVanApp> {
  late final VanAuthRepository _authRepository;
  late final VanSessionStore _sessionStore;
  VanSession? _session;
  bool _restoring = true;

  @override
  void initState() {
    super.initState();
    _authRepository =
        widget.authRepository ?? HttpVanAuthRepository(FoodexEnvironment.apiBaseUrl);
    _sessionStore = widget.sessionStore ?? SecureVanSessionStore();
    _session = widget.initialSession;

    if (_session != null) {
      _restoring = false;
    } else {
      _restoreSession();
    }
  }

  Future<void> _restoreSession() async {
    VanSession? stored;
    try {
      stored = await _sessionStore.read();
      if (stored != null && !stored.canUseVan) {
        await _sessionStore.clear();
        stored = null;
      }
    } catch (_) {
      stored = null;
    }

    if (!mounted) return;
    setState(() {
      _session = stored;
      _restoring = false;
    });
  }

  Future<void> _authenticated(VanSession session, bool persist) async {
    if (!session.canUseVan) return;

    if (persist) {
      await _sessionStore.write(session);
    } else {
      await _sessionStore.clear();
    }

    if (!mounted) return;
    setState(() => _session = session);
  }

  Future<void> _logout() async {
    final session = _session;
    if (session == null) return;

    try {
      await _authRepository.logout(session.token);
    } catch (_) {
      // Local logout must remain available when the network is unavailable.
    }
    await _sessionStore.clear();

    if (!mounted) return;
    setState(() => _session = null);
  }

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      debugShowCheckedModeBanner: false,
      title: 'FOODEX Van',
      theme: FoodexVanTheme.light(),
      locale: widget.locale,
      supportedLocales: const [Locale('ar'), Locale('en')],
      localizationsDelegates: const [
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      home: _restoring
          ? const Scaffold(
              body: Center(child: CircularProgressIndicator()),
            )
          : _session == null
              ? VanLoginScreen(
                  repository: _authRepository,
                  onAuthenticated: _authenticated,
                )
              : VanFoundationScreen(
                  session: _session!,
                  onLogout: _logout,
                  walletRepository: widget.walletRepository ??
                      HttpVanWalletRepository(
                        VanApiClient(
                          FoodexEnvironment.apiBaseUrl,
                          _session!.token,
                        ),
                      ),
                ),
    );
  }
}
