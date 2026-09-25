import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';

import 'core/localization/driver_translations.dart';
import 'core/theme/foodex_theme.dart';
import 'navigation.dart';

class FoodexDriverApp extends StatefulWidget {
  const FoodexDriverApp({
    super.key,
    this.channel = DriverChannel.b2c,
    this.initialRoute = DriverRoutes.root,
    this.locale = const Locale('ar'),
    this.translationOverrides = const {},
    this.translationFetcher,
  });

  final DriverChannel channel;
  final String initialRoute;
  final Locale locale;
  final Map<String, String> translationOverrides;
  final DriverTranslationFetcher? translationFetcher;

  @override
  State<FoodexDriverApp> createState() => _FoodexDriverAppState();
}

class _FoodexDriverAppState extends State<FoodexDriverApp> {
  late Map<String, String> _translations;

  @override
  void initState() {
    super.initState();
    _translations = Map<String, String>.from(widget.translationOverrides);
    _loadRemoteTranslations();
  }

  @override
  void didUpdateWidget(covariant FoodexDriverApp oldWidget) {
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
          : await fetchDriverTranslationBundle(baseUrl, widget.locale.languageCode);

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
    final navigator = DriverNavigator(widget.channel);

    return MaterialApp(
      debugShowCheckedModeBanner: false,
      title: 'FOODEX Driver',
      theme: FoodexTheme.light(),
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
      initialRoute: widget.initialRoute,
      onGenerateRoute: navigator.onGenerateRoute,
    );
  }
}
