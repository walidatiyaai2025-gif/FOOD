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
import 'core/auth/customer_auth_persistence.dart';
import 'core/auth/customer_session.dart';
import 'core/auth/customer_session_http_client.dart';
import 'core/auth/customer_session_store.dart';
import 'core/config/foodex_environment.dart';
import 'core/diagnostics/customer_diagnostics.dart';
import 'core/engagement/notification_campaign_popup_service.dart';
import 'core/localization/app_translations.dart';
import 'core/location/customer_location_service.dart';
import 'core/location/customer_map_pin_selector.dart';
import 'core/push/firebase_push_service.dart';
import 'core/preview/customer_preview_bootstrap.dart';
import 'core/preview/customer_preview_context.dart';
import 'core/preview/customer_preview_viewport.dart';
import 'core/routing/customer_commerce_context.dart';
import 'core/routing/customer_commerce_context_store.dart';
import 'core/routing/customer_pending_action.dart';
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
    this.authPreferences = const CustomerAuthPreferences(),
    this.authPreferenceStore,
    this.biometricAuthenticator,
    this.pendingActionStore,
    this.initialRoute = CustomerRoutePaths.entry,
    this.b2bApi,
    this.b2cCatalogApi,
    this.b2cAccountApi,
    this.actionApi,
    this.storefrontApi,
    this.wholesaleCommerceApi,
    this.customerOrdersApi,
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
    this.notificationCampaignPopupService,
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
    CustomerOrdersApi? customerOrdersApi,
    String initialRoute = CustomerRoutePaths.marketplace,
    Locale? locale,
    Map<String, String> translationOverrides = const {},
    TranslationFetcher? translationFetcher,
    ThemeData? theme,
    CustomerPreviewBootstrap? previewBootstrap,
    CustomerNotificationCampaignPopupService? notificationCampaignPopupService,
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
      customerOrdersApi: customerOrdersApi,
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
      notificationCampaignPopupService: notificationCampaignPopupService,
    );
  }

  final CustomerSession session;
  final CustomerSessionStore? sessionStore;
  final CustomerAuthPreferences authPreferences;
  final CustomerAuthPreferenceStore? authPreferenceStore;
  final CustomerBiometricAuthenticator? biometricAuthenticator;
  final CustomerPendingActionStore? pendingActionStore;
  final String initialRoute;
  final B2bApi? b2bApi;
  final B2cCatalogApi? b2cCatalogApi;
  final B2cAccountApi? b2cAccountApi;
  final CustomerActionApi? actionApi;
  final StorefrontApi? storefrontApi;
  final WholesaleCommerceApi? wholesaleCommerceApi;
  final CustomerOrdersApi? customerOrdersApi;
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
  final CustomerNotificationCampaignPopupService? notificationCampaignPopupService;

  @override
  State<FoodexCustomerApp> createState() => _FoodexCustomerAppState();
}

class _FoodexCustomerAppState extends State<FoodexCustomerApp> {
  late Map<String, String> _translations;
  late CustomerSession _session;
  late CustomerAuthPreferences _authPreferences;
  late CustomerCommerceContext? _activeCommerceContext;
  late final CustomerPendingActionStore _pendingActionStore;
  late Locale _locale;
  final CustomerGuestSession _guestSession = CustomerGuestSession();
  final CustomerGuestCartTokenStore _guestCartTokenStore =
      SecureCustomerGuestCartTokenStore();
  final GlobalKey<NavigatorState> _navigatorKey = GlobalKey<NavigatorState>();
  final GlobalKey<ScaffoldMessengerState> _messengerKey = GlobalKey<ScaffoldMessengerState>();
  CustomerAppRouter? _activeRouter;
  StreamSubscription<String>? _pushRouteSubscription;
  StreamSubscription<FoodexPushAlert>? _pushAlertSubscription;
  late final CustomerNotificationCampaignPopupService _launchCampaignPopups;
  bool _launchCampaignPopupScheduled = false;
  Timer? _versionFooterTimer;
  bool _showVersionFooter = false;
  final CustomerDiagnostics _diagnostics = CustomerDiagnostics.instance;
  late final CustomerDiagnosticsHttpClient _diagnosticsHttpClient;
  late final CustomerSessionHttpClient _sessionHttpClient;

  static const _appVersion = '1.0.58';

  @override
  void initState() {
    super.initState();
    _translations = Map<String, String>.from(widget.translationOverrides);
    _launchCampaignPopups = widget.notificationCampaignPopupService ??
        CustomerNotificationCampaignPopupService();
    _session = widget.session;
    _authPreferences = widget.authPreferences;
    _activeCommerceContext =
        CustomerCommerceContext.tryParseLocation(widget.initialRoute);
    _pendingActionStore =
        widget.pendingActionStore ?? SecureCustomerPendingActionStore();
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
    _configureDiagnostics();
    _scheduleLaunchCampaignPopup();
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
      _configureDiagnostics();
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

  void _scheduleLaunchCampaignPopup() {
    if (_launchCampaignPopupScheduled) return;
    _launchCampaignPopupScheduled = true;

    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      final popupContext = _navigatorKey.currentContext;
      if (popupContext == null) return;

      final preview = widget.previewContext;
      unawaited(() async {
        try {
          await _launchCampaignPopups.showForContext(
            popupContext,
            channel: preview?.channel.name ?? 'all',
            storeId: preview?.storeId,
            accessToken: preview == null ? _session.accessToken : null,
          );
        } catch (_) {
          if (!mounted || preview == null) return;
          _messengerKey.currentState?.showSnackBar(
            SnackBar(
              content: Text(
                _locale.languageCode == 'ar'
                    ? 'تعذر تحميل بيانات الحملة الحالية في المعاينة.'
                    : 'Current campaign data is unavailable in preview.',
              ),
            ),
          );
        }
      }());
    });
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

  void _configureDiagnostics() {
    if (widget.previewContext != null) {
      _diagnostics.clearInspectorUpload();
      return;
    }

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

    if (token == null || token.isEmpty || baseUrl.isEmpty) {
      _diagnostics.clearInspectorUpload();
      return;
    }

    _diagnostics.configureInspectorUpload(baseUrl: baseUrl, token: token);
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
    if (widget.previewContext != null) return;

    final sessionStore = widget.sessionStore;
    final preferenceStore = widget.authPreferenceStore;
    try {
      if (sessionStore != null) {
        if (_authPreferences.rememberMe) {
          await sessionStore.write(session);
        } else {
          await sessionStore.clear();
        }
      }
      if (preferenceStore != null) {
        await preferenceStore.write(_authPreferences);
      }
    } catch (error) {
      _diagnostics.record('session_persist_error', {
        'error_type': error.runtimeType.toString(),
      });
    }
  }

  Future<void> _clearPersistedSession() async {
    if (widget.previewContext != null) return;

    try {
      await widget.sessionStore?.clear();
      await widget.authPreferenceStore?.clear();
    } catch (error) {
      _diagnostics.record('session_clear_error', {
        'error_type': error.runtimeType.toString(),
      });
    }
    _authPreferences = const CustomerAuthPreferences();
  }

  Future<void> _completeUnifiedAuthentication(
    String token,
    CustomerAuthPreferences preferences,
  ) async {
    if (widget.previewContext != null) return;
    final session = CustomerSession.platformCustomer(
      accessToken: token,
      b2bRetailStoreId: _session.b2bRetailStoreId,
    );
    setState(() {
      _session = session;
      _authPreferences = preferences;
    });
    unawaited(_persistSession(session));
    _bindPushSession();
    _configureDiagnostics();

    // Complete only after MaterialApp/Navigator has received the router built
    // from the authenticated platform session. The auth screen awaits this
    // future before resolving its exact pending return route.
    await WidgetsBinding.instance.endOfFrame;
  }

  void _onAuthenticated(CustomerChannel channel, String token) {
    unawaited(
      _completeUnifiedAuthentication(
        token,
        const CustomerAuthPreferences(),
      ),
    );
  }

  void _onPlatformRegistered(String token) {
    unawaited(
      _completeUnifiedAuthentication(
        token,
        const CustomerAuthPreferences(),
      ),
    );
  }

  Future<void> _resumeAuthenticatedRoute(String target) async {
    // Route from the app-owned Navigator after the authenticated rebuild.
    // Generate the replacement from the latest router instance directly:
    // relying on Navigator.pushReplacementNamed here can race with the
    // Navigator widget updating its onGenerateRoute callback.
    await WidgetsBinding.instance.endOfFrame;
    if (!mounted) return;

    final navigator = _navigatorKey.currentState;
    final router = _activeRouter;
    if (navigator == null || router == null) return;

    final route = router.onGenerateRoute(RouteSettings(name: target));
    unawaited(navigator.pushReplacement(route).then<void>((_) {}));
  }

  void _enterWholesale(int? retailStoreId) {
    if (widget.previewContext != null || !_session.isAuthenticated) return;
    final session = _session.asB2bRetailContext(retailStoreId);
    setState(() {
      _session = session;
    });
    unawaited(_persistSession(session));
    _configureDiagnostics();
  }

  void _onSessionExpired() {
    if (widget.previewContext != null ||
        !mounted ||
        !_session.isAuthenticated) {
      return;
    }
    final service = widget.pushService;
    if (service != null) unawaited(service.revokeSession());
    _diagnostics.clearInspectorUpload();
    unawaited(_clearPersistedSession());
    _guestSession.clear();
    setState(() {
      _session = const CustomerSession.guest();
    });
    _navigatorKey.currentState?.pushNamedAndRemoveUntil(
      CustomerRoutePaths.entry,
      (route) => false,
    );
  }

  Future<void> _logout(CustomerActionApi actionApi) async {
    if (widget.previewContext != null) return;
    _diagnostics.clearInspectorUpload();
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
      CustomerRoutePaths.entry,
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
              token: token,
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
    final b2bAccountApi = preview != null
        ? widget.b2cAccountApi!
        : HttpB2cAccountApi(
            baseUrl: baseUrl,
            token: token,
            guestSession: _guestSession,
            customerDomain: 'b2b',
            retailStoreContextId: _session.b2bRetailStoreId,
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

    CustomerActionApi customerActionForToken(String accessToken) {
      if (widget.actionApi != null) {
        return widget.actionApi!;
      }
      return HttpCustomerActionApi(
        baseUrl: baseUrl,
        token: accessToken,
        guestSession: _guestSession,
        b2bRetailStoreId: _session.b2bRetailStoreId,
        client: _sessionHttpClient,
      );
    }

    Future<String?> executePendingAction(
      CustomerPendingAction action,
      String accessToken,
    ) async {
      if (action.kind != CustomerPendingActionKind.addToCart ||
          !action.context.isWholesale ||
          action.productId == null ||
          action.quantity == null) {
        return null;
      }

      await customerActionForToken(accessToken).addCartItem(
        storeId: action.context.storeId,
        productId: action.productId!,
        quantity: action.quantity!,
      );
      return CustomerRouteLocations.wholesaleCart(action.context);
    }

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
    final customerOrdersApi = widget.customerOrdersApi ??
        (token == null || token.isEmpty
            ? null
            : HttpCustomerOrdersApi(
                baseUrl: baseUrl,
                token: token,
                client: _sessionHttpClient,
              ));
    final favoritesApi = b2cAccountApi is B2cRetailFavoritesApi
        ? b2cAccountApi as B2cRetailFavoritesApi
        : null;
    final wholesaleFavoritesApi = b2bAccountApi is B2cRetailFavoritesApi
        ? b2bAccountApi as B2cRetailFavoritesApi
        : null;

    final router = CustomerAppRouter(
      _session,
      b2bApi: b2bApi,
      b2cCatalogApi: b2cCatalogApi,
      b2cAccountApi: b2cAccountApi,
      b2bAccountApi: b2bAccountApi,
      actionApi: actionApi,
      storefrontApi: storefrontApi,
      wholesaleApi: wholesaleCommerceApi,
      retailCommerceApi: retailCommerceApi,
      retailCommerceForToken: (newToken) =>
          retailCommerceForToken(newToken),
      customerOrdersApi: customerOrdersApi,
      favoritesApi: favoritesApi,
      wholesaleFavoritesApi: wholesaleFavoritesApi,
      onAuthenticated: _onAuthenticated,
      onSessionExpired: _onSessionExpired,
      onEnterWholesale: _enterWholesale,
      onPlatformRegistered: _onPlatformRegistered,
      onUnifiedAuthenticated: _completeUnifiedAuthentication,
      onAuthenticatedRouteResume: _resumeAuthenticatedRoute,
      onLogout: widget.previewContext == null ? () => _logout(actionApi) : null,
      sessionStore: widget.previewContext == null ? widget.sessionStore : null,
      authPreferences: _authPreferences,
      biometricAuthenticator:
          widget.previewContext == null ? widget.biometricAuthenticator : null,
      pendingActionStore:
          widget.previewContext == null ? _pendingActionStore : null,
      pendingActionExecutor:
          widget.previewContext == null ? executePendingAction : null,
      onLocaleChanged: _changeLocale,
      locationService: widget.locationService ??
          const GeolocatorCustomerLocationService(),
      mapPinPicker: widget.mapPinPicker ?? showCustomerMapPinSelector,
      marketplaceClient: widget.marketplaceClient,
      marketplaceBarcodeScanner: widget.marketplaceBarcodeScanner,
      currentSession: () => _session,
      currentCommerceContext: () => _activeCommerceContext,
      onCommerceContextChanged: (context) {
        _activeCommerceContext = context;
      },
    );
    _activeRouter = router;

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
