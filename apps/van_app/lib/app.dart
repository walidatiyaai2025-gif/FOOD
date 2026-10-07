import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';

import 'core/api/http_van_api.dart';
import 'core/auth/van_auth_persistence.dart';
import 'core/auth/van_session.dart';
import 'core/auth/van_session_store.dart';
import 'core/config/foodex_environment.dart';
import 'core/theme/foodex_van_theme.dart';
import 'core/push/firebase_push_service.dart';
import 'features/auth/van_login_screen.dart';
import 'features/foundation/van_foundation_screen.dart';
import 'features/commercial/http_van_commercial_repository.dart';
import 'features/commercial/van_commercial_contract.dart';
import 'features/wallet/http_van_wallet_repository.dart';
import 'features/wallet/van_wallet_contract.dart';
import 'features/visits/http_van_visit_repository.dart';
import 'features/visits/van_visit_contract.dart';
import 'features/notifications/http_van_notification_repository.dart';
import 'features/notifications/van_notification_contract.dart';
import 'features/orders/http_van_order_repository.dart';
import 'features/orders/van_order_contract.dart';

class FoodexVanApp extends StatefulWidget {
  const FoodexVanApp({
    super.key,
    this.locale = const Locale('ar'),
    this.theme,
    this.authRepository,
    this.sessionStore,
    this.authPreferenceStore,
    this.biometricAuthenticator,
    this.initialSession,
    this.walletRepository,
    this.commercialRepository,
    this.visitRepository,
    this.notificationRepository,
    this.orderRepository,
    this.pushService,
  });

  final Locale locale;
  final ThemeData? theme;
  final VanAuthRepository? authRepository;
  final VanSessionStore? sessionStore;
  final VanAuthPreferenceStore? authPreferenceStore;
  final VanBiometricAuthenticator? biometricAuthenticator;
  final VanSession? initialSession;
  final VanWalletRepository? walletRepository;
  final VanCommercialRepository? commercialRepository;
  final VanVisitRepository? visitRepository;
  final VanNotificationRepository? notificationRepository;
  final VanOrderRepository? orderRepository;
  final VanFirebasePushService? pushService;

  @override
  State<FoodexVanApp> createState() => _FoodexVanAppState();
}

class _FoodexVanAppState extends State<FoodexVanApp> {
  late final VanAuthRepository _authRepository;
  late final VanSessionStore _sessionStore;
  late final VanAuthPreferenceStore _authPreferenceStore;
  late final VanBiometricAuthenticator _biometricAuthenticator;
  VanAuthPreferences _preferences = const VanAuthPreferences();
  VanSession? _session;
  bool _restoring = true;
  StreamSubscription<VanPushAlert>? _pushAlertSubscription;

  @override
  void initState() {
    super.initState();
    _authRepository =
        widget.authRepository ?? HttpVanAuthRepository(FoodexEnvironment.apiBaseUrl);
    _sessionStore = widget.sessionStore ?? SecureVanSessionStore();
    _authPreferenceStore =
        widget.authPreferenceStore ?? SecureVanAuthPreferenceStore();
    _biometricAuthenticator =
        widget.biometricAuthenticator ?? LocalAuthVanBiometricAuthenticator();
    _session = widget.initialSession;

    if (_session != null) {
      _restoring = false;
      unawaited(widget.pushService?.bindSession(_session!.token));
    } else {
      _restoreAuthState();
    }

    final pushService = widget.pushService;
    if (pushService != null) {
      _pushAlertSubscription = pushService.alerts.listen(_showPushAlert);
    }
  }

  Future<void> _restoreAuthState() async {
    VanAuthPreferences? preferences;
    VanSession? stored;

    try {
      preferences = await _authPreferenceStore.read();
      stored = await _sessionStore.read();

      if (stored != null && !stored.canUseVan) {
        await _sessionStore.clear();
        stored = null;
      }

      if (preferences == null && stored != null) {
        preferences = const VanAuthPreferences(rememberMe: true);
        await _authPreferenceStore.write(preferences);
      }

      preferences ??= const VanAuthPreferences();

      if (!preferences.rememberMe) {
        if (stored != null) {
          await _sessionStore.clear();
          stored = null;
        }
      } else if (stored == null && preferences.biometricEnabled) {
        preferences = preferences.copyWith(biometricEnabled: false);
        await _authPreferenceStore.write(preferences);
      }
    } catch (_) {
      preferences = const VanAuthPreferences();
      stored = null;
    }

    final resolvedPreferences =
        preferences ?? const VanAuthPreferences();
    final restoredSession =
        resolvedPreferences.biometricEnabled ? null : stored;

    if (!mounted) return;
    setState(() {
      _preferences = resolvedPreferences;
      _session = restoredSession;
      _restoring = false;
    });

    if (restoredSession != null) {
      unawaited(widget.pushService?.bindSession(restoredSession.token));
    }
  }

  Future<void> _authenticated(
    VanSession session,
    bool rememberMe,
    bool biometricEnabled,
  ) async {
    if (!session.canUseVan) return;

    final preferences = VanAuthPreferences(
      rememberMe: rememberMe,
      biometricEnabled: rememberMe && biometricEnabled,
    );

    if (rememberMe) {
      await _sessionStore.write(session);
      await _authPreferenceStore.write(preferences);
    } else {
      await _sessionStore.clear();
      await _authPreferenceStore.clear();
    }

    unawaited(widget.pushService?.bindSession(session.token));

    if (!mounted) return;
    setState(() {
      _preferences = preferences;
      _session = session;
    });
  }

  Future<void> _logout() async {
    final session = _session;
    if (session == null) return;

    try {
      await _authRepository.logout(session.token);
    } catch (_) {
      // Local logout must remain available when the network is unavailable.
    }
    await widget.pushService?.revokeSession();
    await _sessionStore.clear();
    await _authPreferenceStore.clear();

    if (!mounted) return;
    setState(() {
      _preferences = const VanAuthPreferences();
      _session = null;
    });
  }

  void _showPushAlert(VanPushAlert alert) {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text('${alert.title}: ${alert.body}')),
    );
  }

  @override
  void dispose() {
    unawaited(_pushAlertSubscription?.cancel());
    unawaited(widget.pushService?.dispose());
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      debugShowCheckedModeBanner: false,
      title: widget.locale.languageCode == 'ar' ? 'فودكس للفان' : 'FOODEX Van',
      theme: widget.theme ?? FoodexVanTheme.light(),
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
                  sessionStore: _sessionStore,
                  preferences: _preferences,
                  biometricAuthenticator: _biometricAuthenticator,
                )
              : Builder(
                  builder: (context) {
                    final api = VanApiClient(
                      FoodexEnvironment.apiBaseUrl,
                      _session!.token,
                    );
                    final walletRepository = widget.walletRepository ??
                        HttpVanWalletRepository(api);
                    return VanFoundationScreen(
                      session: _session!,
                      onLogout: _logout,
                      walletRepository: walletRepository,
                      commercialRepository: widget.commercialRepository ??
                          HttpVanCommercialRepository(api),
                      visitRepository:
                          widget.visitRepository ?? HttpVanVisitRepository(api),
                      notificationRepository: widget.notificationRepository ??
                          HttpVanNotificationRepository(api),
                      orderRepository:
                          widget.orderRepository ?? HttpVanOrderRepository(api),
                    );
                  },
                ),
    );
  }
}
