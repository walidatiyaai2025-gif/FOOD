import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:http/http.dart' as http;

import 'core/api/b2b_api.dart';
import 'core/api/b2c_catalog_api.dart';
import 'core/api/b2c_account_api.dart';
import 'core/api/customer_action_api.dart';
import 'core/api/storefront_api.dart';
import 'core/api/wholesale_commerce_api.dart';
import 'core/auth/customer_session.dart';
import 'core/auth/customer_session_http_client.dart';
import 'core/auth/customer_session_store.dart';
import 'core/config/foodex_environment.dart';
import 'core/diagnostics/customer_diagnostics.dart';
import 'core/localization/app_translations.dart';
import 'core/location/customer_location_service.dart';
import 'core/location/customer_map_pin_selector.dart';
import 'core/push/firebase_push_service.dart';
import 'core/preview/customer_preview_bootstrap.dart';
import 'core/preview/customer_preview_context.dart';
import 'core/preview/customer_preview_viewport.dart';
import 'core/routing/customer_commerce_context_store.dart';
import 'core/routing/customer_router.dart';
import 'core/routing/customer_routes.dart';
import 'core/theme/foodex_theme.dart';
import 'features/customer_orders/customer_orders_api.dart';
import 'features/retail/commerce/retail_commerce_api.dart';
import 'features/storefront/marketplace_barcode_scanner.dart';

class FoodexCustomerApp extends StatefulWidget {
  const FoodexCustomerApp({
    super.key,
    this.session = const CustomerSession.guest(),
    this.sessionStore,
    this.initialRoute = CustomerRoutePaths.marketplace,
    this.b2bApi,
    this.b2cCatalogApi,
    this.b2cAccountApi,
    this.actionApi,
    this.storefrontApi,
    this.wholesaleCommerceApi,
    this.locale = const Locale('ar'),
    this.translationOverrides = const {},
    this.translationFetcher,
    this.theme,
    this.pushService,
    this.locationService,
    this.mapPinPicker,
    this.previewContext,
    this.previewBootstrap,
    this.marketplaceClient,
    this.marketplaceBarcodeScanner,
  });

  factory FoodexCustomerApp.preview({
    Key? key,
    required CustomerPreviewContext previewContext,
    required B2cCatalogApi b2cCatalogApi,
    required B2cAccountApi b2cAccountApi,
    required StorefrontApi storefrontApi,
    required http.Client marketplaceClient,
    B2bApi? b2bApi,
    WholesaleCommerceApi? wholesaleCommerceApi,
    String initialRoute = CustomerRoutePaths.marketplace,
    Locale? locale,
    Map<String, String> translationOverrides = const {},
    TranslationFetcher? translationFetcher,
    ThemeData? theme,
    CustomerPreviewBootstrap? previewBootstrap,
  }) {
    if (previewContext.channel == CustomerChannel.b2b &&
        (b2bApi == null || wholesaleCommerceApi == null)) {
      throw ArgumentError(
        'B2B Customer preview requires host-injected B2B and wholesale APIs.',
      );
    }

    return FoodexCustomerApp(
      key: key,
      session: previewContext.runtimeIdentity,
      initialRoute: initialRoute,
      b2bApi: b2bApi,
      b2cCatalogApi: PreviewB2cCatalogApi(b2cCatalogApi, previewContext),
      b2cAccountApi: PreviewB2cAccountApi(b2cAccountApi, previewContext),
      actionApi: const PreviewCustomerActionApi(),
      storefrontApi: PreviewStorefrontApi(storefrontApi, previewContext),
      wholesaleCommerceApi: wholesaleCommerceApi == null
          ? null
          : PreviewWholesaleCommerceApi(
              wholesaleCommerceApi,
              previewContext,
            ),
      locale: locale ??
          Locale(previewContext.targetLocale == 'en' ? 'en' : 'ar'),
      translationOverrides: translationOverrides,
      translationFetcher: translationFetcher,
      theme: theme,
      locationService: const PreviewCustomerLocationService(),
      mapPinPicker: previewCustomerMapPinPicker,
      previewContext: previewContext,
      previewBootstrap: previewBootstrap,
      marketplaceClient: PreviewReadOnlyHttpClient(marketplaceClient),
      marketplaceBarcodeScanner: (context) async => null,
    );
  }

  final CustomerSession session;
  final CustomerSessionStore? sessionStore;
  final String initialRoute;
  final B2bApi? b2bApi;
  final B2cCatalogApi? b2cCatalogApi;
  final B2cAccountApi? b2cAccountApi;
  final CustomerActionApi? actionApi;
  final StorefrontApi? storefrontApi;
  final WholesaleCommerceApi? wholesaleCommerceApi;
  final Locale locale;
  final Map<String, String> translationOverrides;
  final TranslationFetcher? translationFetcher;
  final ThemeData? theme;
  final CustomerFirebasePushService? pushService;
  final CustomerLocationService? locationService;
  final CustomerMapPinPicker? mapPinPicker;
  final CustomerPreviewContext? previewContext;
  final CustomerPreviewBootstrap? previewBootstrap;
  final http.Client? marketplaceClient;
  final MarketplaceBarcodeScanner? marketplaceBarcodeScanner;

  @override
  State<FoodexCustomerApp> createState() => _FoodexCustomerAppState();
}

class _FoodexCustomerAppState extends State<FoodexCustomerApp> {
  late Map<String, String> _translations;
  late CustomerSession _session;
  late Locale _locale;
  final CustomerGuestSession _guestSession = CustomerGuestSession();
  final CustomerGuestCartTokenStore _guestCartTokenStore =
      SecureCustomerGuestCartTokenStore();
  final GlobalKey<NavigatorState> _navigatorKey = GlobalKey<NavigatorState>();
  final GlobalKey<ScaffoldMessengerState> _messengerKey = GlobalKey<ScaffoldMessengerState>();
  StreamSubscription<String>? _pushRouteSubscription;
  StreamSubscription<FoodexPushAlert>? _pushAlertSubscription;
  Timer? _versionFooterTimer;
  bool _showVersionFooter = false;
  final CustomerDiagnostics _diagnostics = CustomerDiagnostics.instance;
  late final CustomerDiagnosticsHttpClient _diagnosticsHttpClient;
  late final CustomerSessionHttpClient _sessionHttpClient;

  static const _appVersion = '1.0.43';

  @override
  void initState() {
    super.initState();
    _translations = Map<String, String>.from(widget.translationOverrides);
    _session = widget.session;
    _locale = widget.locale;
    _diagnosticsHttpClient = CustomerDiagnosticsHttpClient(
      http.Client(),
      diagnostics: _diagnostics,
    );
    _sessionHttpClient = CustomerSessionHttpClient(
      _diagnosticsHttpClient,
      onUnauthorized: _onSessionExpired,
    );
    _showVersionFooter = widget.initialRoute != CustomerRoutePaths.splash;
    if (!_showVersionFooter) {
      _versionFooterTimer = Timer(const Duration(milliseconds: 1150), () {
        if (mounted) setState(() => _showVersionFooter = true);
      });
    }
    _loadRemoteTranslations();
    _configurePush();
  }

  @override
  void didUpdateWidget(covariant FoodexCustomerApp oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.locale != widget.locale ||
        oldWidget.translationOverrides != widget.translationOverrides) {
      _locale = widget.locale;
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
      final baseUrl = FoodexEnvironment.apiBaseUrl;
      if (widget.previewContext != null && fetcher == null) {
        return;
      }
      if (fetcher == null && baseUrl.isEmpty) {
        return;
      }

      final remote = fetcher != null
          ? await fetcher(_locale.languageCode)
          : await fetchTranslationBundle(baseUrl, _locale.languageCode);

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

  void _changeLocale(Locale locale) {
    if (_locale.languageCode == locale.languageCode) return;
    setState(() {
      _locale = locale;
      _translations = Map<String, String>.from(widget.translationOverrides);
    });
    _loadRemoteTranslations();
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

  Future<void> _persistSession(CustomerSession session) async {
    final store = widget.sessionStore;
    if (widget.previewContext != null || store == null) return;

    try {
      await store.write(session);
    } catch (error) {
      _diagnostics.record('session_persist_error', {
        'error_type': error.runtimeType.toString(),
      });
    }
  }

  Future<void> _clearPersistedSession() async {
    final store = widget.sessionStore;
    if (widget.previewContext != null || store == null) return;

    try {
      await store.clear();
    } catch (error) {
      _diagnostics.record('session_clear_error', {
        'error_type': error.runtimeType.toString(),
      });
    }
  }

  void _onAuthenticated(CustomerChannel channel, String token) {
    if (widget.previewContext != null) return;
    final session = CustomerSession.authenticated(
      channel,
      accessToken: token,
    );
    setState(() {
      _session = session;
    });
    unawaited(_persistSession(session));
    _bindPushSession();
  }

  void _onPlatformRegistered(String token) {
    if (widget.previewContext != null) return;
    final session = CustomerSession.authenticated(
      CustomerChannel.b2b,
      accessToken: token,
      platformWide: true,
    );
    setState(() {
      _session = session;
    });
    unawaited(_persistSession(session));
    _bindPushSession();
  }

  void _enterWholesale(int? retailStoreId) {
    if (widget.previewContext != null || !_session.isAuthenticated) return;
    final session = _session.asB2bRetailContext(retailStoreId);
    setState(() {
      _session = session;
    });
    unawaited(_persistSession(session));
  }

  void _onSessionExpired() {
    if (widget.previewContext != null ||
        !mounted ||
        !_session.isAuthenticated) {
      return;
    }
    final service = widget.pushService;
    if (service != null) unawaited(service.revokeSession());
    unawaited(_clearPersistedSession());
    _guestSession.clear();
    setState(() {
      _session = const CustomerSession.guest();
    });
    _navigatorKey.currentState?.pushNamedAndRemoveUntil(
      CustomerRoutePaths.marketplace,
      (route) => false,
    );
  }

  Future<void> _logout(CustomerActionApi actionApi) async {
    if (widget.previewContext != null) return;
    await _clearPersistedSession();
    final service = widget.pushService;
    if (service != null) {
      await service.revokeSession();
    }

    try {
      await actionApi.logout();
    } catch (_) {
      // Local sign-out remains authoritative for the pilot app experience.
    }

    if (!mounted) return;
    _guestSession.clear();
    setState(() {
      _session = const CustomerSession.guest();
    });
    _navigatorKey.currentState?.pushNamedAndRemoveUntil(
      CustomerRoutePaths.marketplace,
      (route) => false,
    );
  }

  @override
  void dispose() {
    _sessionHttpClient.close();
    _versionFooterTimer?.cancel();
    unawaited(_pushRouteSubscription?.cancel());
    unawaited(_pushAlertSubscription?.cancel());
    super.dispose();
  }

  Widget _withPreviewViewport(Widget child) {
    final bootstrap = widget.previewBootstrap;
    if (bootstrap == null) return child;
    return CustomerPreviewViewport(bootstrap: bootstrap, child: child);
  }

  @override
  Widget build(BuildContext context) {
    final baseUrl = FoodexEnvironment.apiBaseUrl;
    final token = _session.accessToken;
    _diagnostics.updateContext(
      appVersion: _appVersion,
      apiBaseUrl: baseUrl,
      locale: _locale.languageCode,
      authenticated: _session.isAuthenticated,
      channel: _session.channel?.name,
      platformWide: _session.platformWide,
      retailStoreContextId: _session.b2bRetailStoreId,
    );
    final preview = widget.previewContext;
    if (preview != null) {
      if (widget.b2cCatalogApi == null ||
          widget.b2cAccountApi == null ||
          widget.actionApi == null ||
          widget.storefrontApi == null ||
          widget.marketplaceClient == null ||
          widget.marketplaceBarcodeScanner == null) {
        throw StateError(
          'Customer preview requires host-injected Customer APIs.',
        );
      }
      if (preview.channel == CustomerChannel.b2b &&
          (widget.b2bApi == null || widget.wholesaleCommerceApi == null)) {
        throw StateError(
          'B2B Customer preview requires host-injected B2B APIs.',
        );
      }
    }

    final b2bApi = preview != null
        ? widget.b2bApi
        : widget.b2bApi ??
            (token == null
                ? null
                : HttpB2bApi(
                    baseUrl: baseUrl,
                    token: token,
                    retailStoreContextId: _session.b2bRetailStoreId,
                    client: _sessionHttpClient,
                  ));
    final b2cCatalogApi = preview != null
        ? widget.b2cCatalogApi!
        : widget.b2cCatalogApi ??
            HttpB2cCatalogApi(
              baseUrl: baseUrl,
              client: _sessionHttpClient,
            );
    final actionApi = preview != null
        ? widget.actionApi!
        : widget.actionApi ??
            HttpCustomerActionApi(
              baseUrl: baseUrl,
              token: token,
              guestSession: _guestSession,
              b2bRetailStoreId: _session.b2bRetailStoreId,
              client: _sessionHttpClient,
            );
    final b2cAccountApi = preview != null
        ? widget.b2cAccountApi!
        : widget.b2cAccountApi ??
            HttpB2cAccountApi(
              baseUrl: baseUrl,
              token: token,
              guestSession: _guestSession,
              client: _sessionHttpClient,
            );
    final storefrontApi = preview != null
        ? widget.storefrontApi!
        : widget.storefrontApi ??
            (widget.b2cCatalogApi != null
                ? null
                : HttpStorefrontApi(
                    baseUrl: baseUrl,
                    token: token,
                    retailStoreContextId: _session.b2bRetailStoreId,
                    client: _sessionHttpClient,
                  ));
    final wholesaleCommerceApi = preview != null
        ? widget.wholesaleCommerceApi
        : widget.wholesaleCommerceApi ??
            (token == null || widget.b2bApi != null
                ? null
                : HttpWholesaleCommerceApi(
                    baseUrl: baseUrl,
                    token: token,
                    retailStoreContextId: _session.b2bRetailStoreId,
                    client: _sessionHttpClient,
                  ));

    RetailCommerceApi retailCommerceForToken(String? accessToken) {
      final scopedAccountApi = accessToken == null || accessToken == token
          ? b2cAccountApi
          : HttpB2cAccountApi(
              baseUrl: baseUrl,
              token: accessToken,
              guestSession: _guestSession,
              client: _sessionHttpClient,
            );
      final scopedActionApi = accessToken == null || accessToken == token
          ? actionApi
          : HttpCustomerActionApi(
              baseUrl: baseUrl,
              token: accessToken,
              guestSession: _guestSession,
              b2bRetailStoreId: _session.b2bRetailStoreId,
              client: _sessionHttpClient,
            );

      return DefaultRetailCommerceApi(
        accountApi: scopedAccountApi,
        actionApi: scopedActionApi,
        checkoutOptionsApi: HttpRetailCheckoutOptionsApi(
          baseUrl: baseUrl,
          token: accessToken ?? token ?? '',
          client: _sessionHttpClient,
        ),
        guestSession: _guestSession,
        guestCartTokenStore: _guestCartTokenStore,
      );
    }

    final retailCommerceApi = retailCommerceForToken(token);
    final customerOrdersApi = token == null || token.isEmpty
        ? null
        : HttpCustomerOrdersApi(
            baseUrl: baseUrl,
            token: token,
            client: _sessionHttpClient,
          );
    final favoritesApi = b2cAccountApi is B2cRetailFavoritesApi
        ? b2cAccountApi as B2cRetailFavoritesApi
        : null;

    final router = CustomerAppRouter(
      _session,
      b2bApi: b2bApi,
      b2cCatalogApi: b2cCatalogApi,
      b2cAccountApi: b2cAccountApi,
      actionApi: actionApi,
      storefrontApi: storefrontApi,
      wholesaleApi: wholesaleCommerceApi,
      retailCommerceApi: retailCommerceApi,
      retailCommerceForToken: (newToken) =>
          retailCommerceForToken(newToken),
      customerOrdersApi: customerOrdersApi,
      favoritesApi: favoritesApi,
      onAuthenticated: _onAuthenticated,
      onSessionExpired: _onSessionExpired,
      onEnterWholesale: _enterWholesale,
      onPlatformRegistered: _onPlatformRegistered,
      onLocaleChanged: _changeLocale,
      locationService: widget.locationService ??
          const GeolocatorCustomerLocationService(),
      mapPinPicker: widget.mapPinPicker ?? showCustomerMapPinSelector,
      marketplaceClient: widget.marketplaceClient,
      marketplaceBarcodeScanner: widget.marketplaceBarcodeScanner,
    );

    return MaterialApp(
      navigatorKey: _navigatorKey,
      scaffoldMessengerKey: _messengerKey,
      navigatorObservers: [
        CustomerDiagnosticsNavigatorObserver(_diagnostics),
      ],
      debugShowCheckedModeBanner: false,
      title: 'FOODEX Customer',
      theme: widget.theme ?? FoodexTheme.light(),
      locale: _locale,
      localizationsDelegates: const [
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      supportedLocales: const [Locale('ar'), Locale('en')],
      builder: (context, child) => _withPreviewViewport(AppTranslations(
        locale: _locale,
        overrides: _translations,
        child: Builder(
          builder: (translatedContext) => Stack(
            fit: StackFit.expand,
            children: [
              child ?? const SizedBox.shrink(),
              if (_showVersionFooter) ...[
                PositionedDirectional(
                  start: 0,
                  end: 0,
                  bottom: 0,
                  child: IgnorePointer(
                    child: DecoratedBox(
                      decoration: const BoxDecoration(
                        color: Color(0xFFF8FAFC),
                        border: Border(
                          top: BorderSide(color: Color(0xFFE3E8EF)),
                        ),
                      ),
                      child: SafeArea(
                        top: false,
                        child: SizedBox(
                          height: 34,
                          child: Padding(
                            padding: const EdgeInsets.symmetric(horizontal: 12),
                            child: Align(
                              alignment: AlignmentDirectional.centerStart,
                              child: Text(
                                '${translatedContext.tr('customer.version')} $_appVersion',
                                key: const ValueKey('customer-app-version-footer'),
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: Theme.of(translatedContext)
                                    .textTheme
                                    .labelSmall
                                    ?.copyWith(
                                      color: FoodexBrand.muted,
                                      fontWeight: FontWeight.w700,
                                    ),
                              ),
                            ),
                          ),
                        ),
                      ),
                    ),
                  ),
                ),
                if (_session.isAuthenticated)
                  PositionedDirectional(
                    end: 6,
                    bottom: 0,
                    child: SafeArea(
                      top: false,
                      child: SizedBox(
                        height: 34,
                        child: TextButton.icon(
                          key: const ValueKey('customer-logout'),
                          onPressed: () => _logout(actionApi),
                          icon: const Icon(Icons.logout_rounded, size: 16),
                          label: Text(
                            translatedContext.tr('customer.logout'),
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                          ),
                        ),
                      ),
                    ),
                  ),
              ],
            ],
          ),
        ),
      )),
      initialRoute: widget.initialRoute,
      onGenerateInitialRoutes: (routeName) => [
        router.onGenerateRoute(RouteSettings(name: routeName)),
      ],
      onGenerateRoute: router.onGenerateRoute,
    );
  }
}
