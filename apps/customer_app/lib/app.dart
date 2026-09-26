import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';

import 'core/api/b2b_api.dart';
import 'core/api/b2c_catalog_api.dart';
import 'core/api/customer_action_api.dart';
import 'core/auth/customer_session.dart';
import 'core/localization/app_translations.dart';
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
    this.actionApi,
    this.locale = const Locale('ar'),
    this.translationOverrides = const {},
    this.translationFetcher,
  });

  final CustomerSession session;
  final String initialRoute;
  final B2bApi? b2bApi;
  final B2cCatalogApi? b2cCatalogApi;
  final CustomerActionApi? actionApi;
  final Locale locale;
  final Map<String, String> translationOverrides;
  final TranslationFetcher? translationFetcher;

  @override
  State<FoodexCustomerApp> createState() => _FoodexCustomerAppState();
}

class _FoodexCustomerAppState extends State<FoodexCustomerApp> {
  late Map<String, String> _translations;
  late CustomerSession _session;

  @override
  void initState() {
    super.initState();
    _translations = Map<String, String>.from(widget.translationOverrides);
    _session = widget.session;
    _loadRemoteTranslations();
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
    }
  }

  Future<void> _loadRemoteTranslations() async {
    try {
      final fetcher = widget.translationFetcher;
      final baseUrl =
          const String.fromEnvironment('FOODEX_API_BASE_URL', defaultValue: '');
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

  void _onAuthenticated(CustomerChannel channel, String token) {
    setState(() {
      _session = CustomerSession.authenticated(channel, accessToken: token);
    });
  }

  @override
  Widget build(BuildContext context) {
    final baseUrl = const String.fromEnvironment(
      'FOODEX_API_BASE_URL',
      defaultValue: 'http://localhost:8000',
    );
    final token = _session.accessToken;
    final b2bApi = widget.b2bApi ??
        (token == null ? null : HttpB2bApi(baseUrl: baseUrl, token: token));
    final b2cCatalogApi = widget.b2cCatalogApi ?? HttpB2cCatalogApi(baseUrl: baseUrl);
    final actionApi = widget.actionApi ??
        HttpCustomerActionApi(baseUrl: baseUrl, token: token);
    final router = CustomerAppRouter(
      _session,
      b2bApi: b2bApi,
      b2cCatalogApi: b2cCatalogApi,
      actionApi: actionApi,
      onAuthenticated: _onAuthenticated,
    );

    return MaterialApp(
      debugShowCheckedModeBanner: false,
      title: 'FOODEX Customer',
      theme: FoodexTheme.light(),
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
