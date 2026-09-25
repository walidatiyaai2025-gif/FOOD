import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';

import 'core/api/b2b_api.dart';
import 'core/auth/customer_session.dart';
import 'core/localization/app_translations.dart';
import 'core/routing/customer_router.dart';
import 'core/routing/customer_routes.dart';

class FoodexCustomerApp extends StatefulWidget {
  const FoodexCustomerApp({
    super.key,
    this.session = const CustomerSession.guest(),
    this.initialRoute = CustomerRoutePaths.splash,
    this.b2bApi,
    this.locale = const Locale('ar'),
    this.translationOverrides = const {},
    this.translationFetcher,
  });

  final CustomerSession session;
  final String initialRoute;
  final B2bApi? b2bApi;
  final Locale locale;
  final Map<String, String> translationOverrides;
  final TranslationFetcher? translationFetcher;

  @override
  State<FoodexCustomerApp> createState() => _FoodexCustomerAppState();
}

class _FoodexCustomerAppState extends State<FoodexCustomerApp> {
  late Map<String, String> _translations;

  @override
  void initState() {
    super.initState();
    _translations = Map<String, String>.from(widget.translationOverrides);
    _loadRemoteTranslations();
  }

  @override
  void didUpdateWidget(covariant FoodexCustomerApp oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.locale != widget.locale || oldWidget.translationOverrides != widget.translationOverrides) {
      _translations = Map<String, String>.from(widget.translationOverrides);
      _loadRemoteTranslations();
    }
  }

  Future<void> _loadRemoteTranslations() async {
    try {
      final fetcher = widget.translationFetcher;
      final baseUrl = const String.fromEnvironment('FOODEX_API_BASE_URL', defaultValue: '');
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

  @override
  Widget build(BuildContext context) {
    final token = widget.session.accessToken;
    final api = widget.b2bApi ??
        (token == null
            ? null
            : HttpB2bApi(
                baseUrl: const String.fromEnvironment(
                  'FOODEX_API_BASE_URL',
                  defaultValue: 'http://localhost:8000',
                ),
                token: token,
              ));
    final router = CustomerAppRouter(widget.session, b2bApi: api);

    return MaterialApp(
      debugShowCheckedModeBanner: false,
      title: 'FOODEX Customer',
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
