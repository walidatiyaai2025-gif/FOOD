import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';

import 'core/api/b2b_api.dart';
import 'core/api/b2c_catalog_api.dart';
import 'core/api/b2c_account_api.dart';
import 'core/api/customer_action_api.dart';
import 'core/auth/customer_session.dart';
import 'core/config/foodex_environment.dart';
import 'core/localization/app_translations.dart';
import 'core/push/firebase_push_service.dart';
import 'core/routing/customer_router.dart';
import 'core/routing/customer_routes.dart';
import 'core/theme/foodex_theme.dart';

class FoodexCustomerApp extends StatefulWidget {
  const FoodexCustomerApp({
    super.key,
    this.session = const CustomerSession.guest(),
    this.initialRoute = CustomerRoutePaths.splash,
    this.b2bApi,
    this.b2cCatalogApi,
    this.b2cAccountApi,
    this.actionApi,
    this.locale = const Locale('ar'),
    this.translationOverrides = const {},
    this.translationFetcher,
    this.theme,
    this.pushService,
  });

  final CustomerSession session;
  final String initialRoute;
  final B2bApi? b2bApi;
  final B2cCatalogApi? b2cCatalogApi;
  final B2cAccountApi? b2cAccountApi;
  final CustomerActionApi? actionApi;
  final Locale locale;
  final Map<String, String> translationOverrides;
  final TranslationFetcher? translationFetcher;
  final ThemeData? theme;
  final CustomerFirebasePushService? pushService;

  @override
  State<FoodexCustomerApp> createState() => _FoodexCustomerAppState();
}

class _FoodexCustomerAppState extends State<FoodexCustomerApp> {
  late Map<String, String> _translations;
  late CustomerSession _session;
  final CustomerGuestSession _guestSession = CustomerGuestSession();
  final GlobalKey<NavigatorState> _navigatorKey = GlobalKey<NavigatorState>();
  final GlobalKey<ScaffoldMessengerState> _messengerKey = GlobalKey<ScaffoldMessengerState>();
  StreamSubscription<String>? _pushRouteSubscription;
  StreamSubscription<FoodexPushAlert>? _pushAlertSubscription;

  @override
  void initState() {
    super.initState();
    _translations = Map<String, String>.from(widget.translationOverrides);
    _session = widget.session;
    _loadRemoteTranslations();
    _configurePush();
  }

  @override
  void didUpdateWidget(covariant FoodexCustomerApp oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.locale != widget.locale ||
        oldWidget.translationOverrides != widget.translationOverrides) {
      _translations = Map<String, String>.from(widget.translationOverrides);
      _loadRemoteTranslations();
    }
    if (oldWidget.session != widget.session) {
      _session = widget.session;
      _bindPushSession();
    }
    if (oldWidget.pushService != widget.pushService) {
      unawaited(_pushRouteSubscription?.cancel());
      unawaited(_pushAlertSubscription?.cancel());
      _configurePush();
    }
  }

  Future<void> _loadRemoteTranslations() async {
    try {
      final fetcher = widget.translationFetcher;
      final baseUrl =
          FoodexEnvironment.apiBaseUrl;
      if (fetcher == null && baseUrl.isEmpty) {
        return;
      }

      final remote = fetcher != null
          ? await fetcher(widget.locale.languageCode)
          : await fetchTranslationBundle(baseUrl, widget.locale.languageCode);

      if (!mounted || remote.isEmpty) {
        return;
      }

      setState(() {
        _translations = {..._translations, ...remote};
      });
    } catch (_) {
      // Bundled translations remain the fallback when remote loading fails.
    }
  }

  void _configurePush() {
    final service = widget.pushService;
    if (service == null) return;
    _pushRouteSubscription = service.routes.listen(_navigateFromPush);
    _pushAlertSubscription = service.alerts.listen(_showPushAlert);
    WidgetsBinding.instance.addPostFrameCallback((_) {
      final route = service.takePendingRoute();
      if (route != null) _navigateFromPush(route);
    });
    _bindPushSession();
  }

  void _bindPushSession() {
    final service = widget.pushService;
    final token = _session.accessToken;
    if (service != null && token != null && token.isNotEmpty) {
      unawaited(service.bindSession(token));
    }
  }

  void _navigateFromPush(String route) {
    _navigatorKey.currentState?.pushNamed(route);
  }

  void _showPushAlert(FoodexPushAlert alert) {
    final message = alert.body.isEmpty ? alert.title : '${alert.title}\n${alert.body}';
    _messengerKey.currentState?.showSnackBar(SnackBar(content: Text(message)));
  }

  void _onAuthenticated(CustomerChannel channel, String token) {
    setState(() {
      _session = CustomerSession.authenticated(channel, accessToken: token);
    });
    _bindPushSession();
  }

  void _onSessionExpired() {
    final service = widget.pushService;
    if (service != null) unawaited(service.revokeSession());
    setState(() {
      _session = const CustomerSession.guest();
    });
  }

  @override
  void dispose() {
    unawaited(_pushRouteSubscription?.cancel());
    unawaited(_pushAlertSubscription?.cancel());
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final baseUrl = FoodexEnvironment.apiBaseUrl;
    final token = _session.accessToken;
    final b2bApi = widget.b2bApi ??
        (token == null ? null : HttpB2bApi(baseUrl: baseUrl, token: token));
    final b2cCatalogApi = widget.b2cCatalogApi ?? HttpB2cCatalogApi(baseUrl: baseUrl);
    final actionApi = widget.actionApi ?? HttpCustomerActionApi(
      baseUrl: baseUrl,
      token: token,
      guestSession: _guestSession,
    );
    final b2cAccountApi = widget.b2cAccountApi ?? HttpB2cAccountApi(
      baseUrl: baseUrl,
      token: token,
      guestSession: _guestSession,
    );
    final router = CustomerAppRouter(
      _session,
      b2bApi: b2bApi,
      b2cCatalogApi: b2cCatalogApi,
      b2cAccountApi: b2cAccountApi,
      actionApi: actionApi,
      onAuthenticated: _onAuthenticated,
      onSessionExpired: _onSessionExpired,
    );

    return MaterialApp(
      navigatorKey: _navigatorKey,
      scaffoldMessengerKey: _messengerKey,
      debugShowCheckedModeBanner: false,
      title: 'FOODEX Customer',
      theme: widget.theme ?? FoodexTheme.light(),
      locale: widget.locale,
      localizationsDelegates: const [
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      supportedLocales: const [Locale('ar'), Locale('en')],
      builder: (context, child) => AppTranslations(
        locale: widget.locale,
        overrides: _translations,
        child: child ?? const SizedBox.shrink(),
      ),
      initialRoute: widget.initialRoute,
      onGenerateInitialRoutes: (routeName) => [
        router.onGenerateRoute(RouteSettings(name: routeName)),
      ],
      onGenerateRoute: router.onGenerateRoute,
    );
  }
}
