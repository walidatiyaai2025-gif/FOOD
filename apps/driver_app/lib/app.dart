import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';

import 'core/api/http_driver_api.dart';
import 'core/auth/driver_session.dart';
import 'core/config/foodex_environment.dart';
import 'core/localization/driver_translations.dart';
import 'core/push/firebase_push_service.dart';
import 'core/theme/foodex_theme.dart';
import 'features/auth/driver_login.dart';
import 'features/tasks/driver_journey.dart';
import 'navigation.dart';

typedef DriverAssignmentRepositoryFactory = DriverAssignmentRepository Function(
  DriverSession session,
);

class FoodexDriverApp extends StatefulWidget {
  const FoodexDriverApp({
    super.key,
    this.initialRoute = DriverRoutes.root,
    this.locale = const Locale('ar'),
    this.translationOverrides = const {},
    this.translationFetcher,
    this.apiBaseUrl,
    this.authRepository,
    this.assignmentRepositoryFactory,
    this.initialSession,
    this.theme,
    this.pushService,
  });

  final String initialRoute;
  final Locale locale;
  final Map<String, String> translationOverrides;
  final DriverTranslationFetcher? translationFetcher;
  final String? apiBaseUrl;
  final DriverAuthRepository? authRepository;
  final DriverAssignmentRepositoryFactory? assignmentRepositoryFactory;
  final DriverSession? initialSession;
  final ThemeData? theme;
  final DriverFirebasePushService? pushService;

  @override
  State<FoodexDriverApp> createState() => _FoodexDriverAppState();
}

class _FoodexDriverAppState extends State<FoodexDriverApp> {
  late Map<String, String> _translations;
  DriverSession? _session;
  final GlobalKey<NavigatorState> _driverNavigatorKey = GlobalKey<NavigatorState>();
  final GlobalKey<ScaffoldMessengerState> _messengerKey = GlobalKey<ScaffoldMessengerState>();
  StreamSubscription<void>? _pushOpenSubscription;
  StreamSubscription<DriverPushAlert>? _pushAlertSubscription;

  String get _baseUrl =>
      widget.apiBaseUrl ??
      FoodexEnvironment.apiBaseUrl;

  @override
  void initState() {
    super.initState();
    _session = widget.initialSession;
    _translations = Map<String, String>.from(widget.translationOverrides);
    _loadRemoteTranslations();
    _configurePush();
  }

  @override
  void didUpdateWidget(covariant FoodexDriverApp oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.locale != widget.locale ||
        oldWidget.translationOverrides != widget.translationOverrides) {
      _translations = Map<String, String>.from(widget.translationOverrides);
      _loadRemoteTranslations();
    }
    if (oldWidget.initialSession != widget.initialSession && widget.initialSession != null) {
      _session = widget.initialSession;
      _bindPushSession();
    }
    if (oldWidget.pushService != widget.pushService) {
      unawaited(_pushOpenSubscription?.cancel());
      unawaited(_pushAlertSubscription?.cancel());
      _configurePush();
    }
  }

  DriverAuthRepository? _authRepository() {
    if (widget.authRepository != null) return widget.authRepository;
    if (_baseUrl.isEmpty) return null;
    return HttpDriverAuthRepository(_baseUrl);
  }

  DriverAssignmentRepository? _assignmentRepository(DriverSession session) {
    final factory = widget.assignmentRepositoryFactory;
    if (factory != null) return factory(session);
    if (_baseUrl.isEmpty) return null;
    return HttpDriverAssignmentRepository(_baseUrl, session.token);
  }

  Future<void> _loadRemoteTranslations() async {
    try {
      final fetcher = widget.translationFetcher;
      if (fetcher == null && _baseUrl.isEmpty) return;

      final remote = fetcher != null
          ? await fetcher(widget.locale.languageCode)
          : await fetchDriverTranslationBundle(
              _baseUrl,
              widget.locale.languageCode,
            );

      if (!mounted || remote.isEmpty) return;
      setState(() => _translations = {..._translations, ...remote});
    } catch (_) {
      // Bundled translations remain the fallback when remote loading fails.
    }
  }

  void _configurePush() {
    final service = widget.pushService;
    if (service == null) return;
    _pushOpenSubscription = service.opens.listen((_) => _openFromPush());
    _pushAlertSubscription = service.alerts.listen(_showPushAlert);
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (service.takePendingOpen()) _openFromPush();
    });
    _bindPushSession();
  }

  void _bindPushSession() {
    final service = widget.pushService;
    final session = _session;
    if (service != null && session != null) {
      unawaited(service.bindSession(session.token));
    }
  }

  void _openFromPush() {
    final session = _session;
    if (session == null) return;
    final route = session.channel == DriverChannel.b2c
        ? DriverRoutes.b2cDeliveries
        : DriverRoutes.b2bDeliveries;
    _driverNavigatorKey.currentState?.pushNamed(route);
  }

  void _showPushAlert(DriverPushAlert alert) {
    final message = alert.body.isEmpty ? alert.title : '${alert.title}\n${alert.body}';
    _messengerKey.currentState?.showSnackBar(SnackBar(content: Text(message)));
  }

  void _authenticated(DriverSession session) {
    if (mounted) setState(() => _session = session);
    _bindPushSession();
  }

  void _sessionExpired() {
    final service = widget.pushService;
    if (service != null) unawaited(service.revokeSession());
    if (mounted) setState(() => _session = null);
  }

  Future<void> _logout() async {
    final session = _session;
    final service = widget.pushService;
    if (service != null) unawaited(service.revokeSession());
    if (mounted) setState(() => _session = null);
    if (session == null) return;
    final repository = _authRepository();
    if (repository == null) return;
    try {
      await repository.logout(session.token);
    } catch (_) {
      // Local logout is immediate; remote token expiry remains server-controlled.
    }
  }

  @override
  void dispose() {
    unawaited(_pushOpenSubscription?.cancel());
    unawaited(_pushAlertSubscription?.cancel());
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final session = _session;
    final authRepository = _authRepository();
    final assignments =
        session == null ? null : _assignmentRepository(session);
    final navigator = session == null || assignments == null
        ? null
        : DriverNavigator(
            session.channel,
            repository: assignments,
            onSessionExpired: _sessionExpired,
            onLogout: () {
              _logout();
            },
          );

    return MaterialApp(
      scaffoldMessengerKey: _messengerKey,
      debugShowCheckedModeBanner: false,
      title: 'FOODEX Driver',
      theme: widget.theme ?? FoodexTheme.light(),
      locale: widget.locale,
      localizationsDelegates: const [
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      supportedLocales: const [Locale('ar'), Locale('en')],
      builder: (context, child) => DriverTranslations(
        locale: widget.locale,
        overrides: _translations,
        child: child ?? const SizedBox.shrink(),
      ),
      home: session == null
          ? DriverLoginPage(
              repository: authRepository,
              onAuthenticated: _authenticated,
            )
          : assignments == null
              ? const _DriverRuntimeConfigurationError()
              : Navigator(
                  key: _driverNavigatorKey,
                  initialRoute: widget.initialRoute,
                  onGenerateRoute: navigator!.onGenerateRoute,
                ),
    );
  }
}

class _DriverRuntimeConfigurationError extends StatelessWidget {
  const _DriverRuntimeConfigurationError();

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Center(
        child: Text(
          context.tr('driver.config.missing'),
          key: const Key('driver-config-missing'),
        ),
      ),
    );
  }
}
