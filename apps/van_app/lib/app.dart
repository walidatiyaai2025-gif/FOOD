import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';

import 'core/theme/foodex_van_theme.dart';
import 'features/foundation/van_foundation_screen.dart';

class FoodexVanApp extends StatelessWidget {
  const FoodexVanApp({super.key, this.locale = const Locale('ar')});

  final Locale locale;

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      debugShowCheckedModeBanner: false,
      title: 'FOODEX Van',
      theme: FoodexVanTheme.light(),
      locale: locale,
      supportedLocales: const [Locale('ar'), Locale('en')],
      localizationsDelegates: const [
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      home: const VanFoundationScreen(),
    );
  }
}
