import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';

import 'core/api/b2b_api.dart';
import 'core/auth/customer_session.dart';
import 'core/routing/customer_router.dart';
import 'core/routing/customer_routes.dart';

class FoodexCustomerApp extends StatelessWidget {
  const FoodexCustomerApp({
    super.key,
    this.session = const CustomerSession.guest(),
    this.initialRoute = CustomerRoutePaths.splash,
    this.b2bApi,
  });

  final CustomerSession session;
  final String initialRoute;
  final B2bApi? b2bApi;

  @override
  Widget build(BuildContext context) {
    final token = session.accessToken;
    final api = b2bApi ?? (token == null ? null : HttpB2bApi(baseUrl: const String.fromEnvironment('FOODEX_API_BASE_URL', defaultValue: 'http://localhost:8000'), token: token));
    final router = CustomerAppRouter(session, b2bApi: api);

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
