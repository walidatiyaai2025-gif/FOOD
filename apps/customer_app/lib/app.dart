import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';

import 'core/auth/customer_session.dart';
import 'core/routing/customer_router.dart';
import 'core/routing/customer_routes.dart';

class FoodexCustomerApp extends StatelessWidget {
  const FoodexCustomerApp({
    super.key,
    this.session = const CustomerSession.guest(),
    this.initialRoute = CustomerRoutePaths.splash,
  });

  final CustomerSession session;
  final String initialRoute;

  @override
  Widget build(BuildContext context) {
    final router = CustomerAppRouter(session);

    return MaterialApp(
      debugShowCheckedModeBanner: false,
      title: 'FOODEX Customer',
      locale: const Locale('ar'),
      localizationsDelegates: const [
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      supportedLocales: const [Locale('ar'), Locale('en')],
      initialRoute: initialRoute,
      onGenerateInitialRoutes: (routeName) => [
        router.onGenerateRoute(RouteSettings(name: routeName)),
      ],
      onGenerateRoute: router.onGenerateRoute,
    );
  }
}
