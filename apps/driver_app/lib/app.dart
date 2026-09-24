import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';

import 'navigation.dart';

class FoodexDriverApp extends StatelessWidget {
  const FoodexDriverApp({
    super.key,
    this.channel = DriverChannel.b2c,
    this.initialRoute = DriverRoutes.root,
  });

  final DriverChannel channel;
  final String initialRoute;

  @override
  Widget build(BuildContext context) {
    final navigator = DriverNavigator(channel);

    return MaterialApp(
      debugShowCheckedModeBanner: false,
      title: 'FOODEX Driver',
      locale: const Locale('ar'),
      localizationsDelegates: const [
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      supportedLocales: const [Locale('ar'), Locale('en')],
      initialRoute: initialRoute,
      onGenerateRoute: navigator.onGenerateRoute,
    );
  }
}
