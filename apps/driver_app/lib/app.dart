import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';

import 'core/api/http_driver_api.dart';
import 'core/auth/driver_auth_persistence.dart';
import 'core/auth/driver_session.dart';
import 'core/config/foodex_environment.dart';
import 'core/diagnostics/driver_runtime_inspector.dart';
import 'core/localization/driver_translations.dart';
import 'core/location/driver_location_gate_service.dart';
import 'core/location/driver_location_tracking_service.dart';
import 'core/preview/driver_preview_bootstrap.dart';
import 'core/preview/driver_preview_context.dart';
import 'core/preview/driver_preview_viewport.dart';
import 'core/push/firebase_push_service.dart';
import 'core/theme/foodex_theme.dart';
import 'core/version/driver_version_policy_client.dart';
import 'features/auth/driver_login.dart';
import 'features/inspector/driver_inspector_panel.dart';
import 'features/location/driver_location_gate.dart';
import 'features/delivery/driver_assignment_contract.dart';
import 'features/notifications/notification_feed.dart';
import 'features/version/driver_version_policy_gate.dart';
import 'navigation.dart';

typedef DriverAssignmentRepositoryFactory = DriverAssignmentRepository Function(
  DriverSession session,
);
typedef DriverNotificationRepositoryFactory = DriverNotificationRepository Function(
  DriverSession session,
);
typedef DriverLocationTrackingFactory = DriverLocationTrackingController Function(
  DriverSession session,
  void Function() onSessionInvalid,
);

class FoodexDriverApp extends StatefulWidget {
  const FoodexDriverApp({
    super.key,
    this.initialRoute = DriverRoutes.root,
    this.locale = const Locale('ar'),
    this.translationOverrides = const {},
    this.translationFetcher,
    this.apiBaseUrl,
    this.authRepository,
    this.assignmentRepositoryFactory,
    this.notificationRepositoryFactory,
    this.initialSession,
    this.theme,
    this.pushService,
    this.previewContext,
    this.previewBootstrap,
    this.locationGateService,
    this.locationTrackingFactory,
    this.versionPolicyClient,
    this.updateLauncher,
    this.showPersistentFooter = true,
  });

  factory FoodexDriverApp.preview({
    Key? key,
    required DriverPreviewContext previewContext,
    required DriverAssignmentRepository assignmentRepository,
    DriverNotificationRepository? notificationRepository,
    String initialRoute = DriverRoutes.root,
    Locale? locale,
    Map<String, String> translationOverrides = const {},
    DriverTranslationFetcher? translationFetcher,
    ThemeData? theme,
    DriverPreviewBootstrap? previewBootstrap,
  }) {
    return FoodexDriverApp(
      key: key,
      initialRoute: initialRoute,
      locale: locale ?? Locale(previewContext.targetLocale == 'en' ? 'en' : 'ar'),
      translationOverrides: translationOverrides,
      translationFetcher: translationFetcher,
      assignmentRepositoryFactory: (_) => assignmentRepository,
      notificationRepositoryFactory: notificationRepository == null
          ? null
          : (_) => notificationRepository,
      initialSession: previewContext.runtimeIdentity,
      theme: theme,
      previewContext: previewContext,
      previewBootstrap: previewBootstrap,
    );
  }

  final String initialRoute;
  final Locale locale;
  final Map<String, String> translationOverrides;
  final DriverTranslationFetcher? translationFetcher;
  final String? apiBaseUrl;
  final DriverAuthRepository? authRepository;
  final DriverAssignmentRepositoryFactory? assignmentRepositoryFactory;
  final DriverNotificationRepositoryFactory? notificationRepositoryFactory;
  final DriverSession? initialSession;
  final ThemeData? theme;
  final DriverPushService? pushService;
  final DriverPreviewContext? previewContext;
  final DriverPreviewBootstrap? previewBootstrap;
  final DriverLocationGateService? locationGateService;
  final DriverLocationTrackingFactory? locationTrackingFactory;
  final DriverVersionPolicyClient? versionPolicyClient;
  final DriverUpdateLauncher? updateLauncher;
  final bool showPersistentFooter;

  @override
  State<FoodexDriverApp> createState() => _FoodexDriverAppState();
}

class _FoodexDriverAppState extends State<FoodexDriverApp> with WidgetsBindingObserver {
  late Map<String, String> _translations;
  DriverSession? _session;
  final GlobalKey<NavigatorState> _driverNavigatorKey = GlobalKey<NavigatorState>();
  final GlobalKey<ScaffoldMessengerState> _messengerKey = GlobalKey<ScaffoldMessengerState>();
  StreamSubscription<DriverPushOpen>? _pushOpenSubscription;
  StreamSubscription<DriverPushAlert>? _pushAlertSubscription;
  final Set<String> _seenPushAlerts = <String>{};
  int? _lastPushAssignmentId;
  bool _inspectorOpen = false;
  String? _routeBeforeInspector;
  DriverLocationTrackingController? _locationTracking;
  bool _locationGateReady = false;
  bool _appInForeground = true;
  final DriverSessionStore _sessionStore = SecureDriverSessionStore();
  final DriverBiometricAuthenticator _biometricAuthenticator =
      LocalAuthDriverBiometricAuthenticator();

  static const _appVersion = '1.0.67';

  String get _baseUrl =>
      widget.apiBaseUrl ??
      FoodexEnvironment.apiBaseUrl;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _session = widget.initialSession;
    _translations = Map<String, String>.from(widget.translationOverrides);
    DriverRuntimeInspector.instance.recordNavigation(
      _session == null ? 'driver.login' : widget.initialRoute,
    );
    _loadRemoteTranslations();
    _configurePush();
    if (_session == null && widget.previewContext == null) {
      unawaited(_restoreRememberedSession());
    }
    final session = _session;
    if (session != null) {
      _configureDiagnostics(session);
      _configureLocationTracking(session);
    }
  }

  @override
  void didUpdateWidget(covariant FoodexDriverApp oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.locale != widget.locale ||
        oldWidget.translationOverrides != widget.translationOverrides) {
      _translations = Map<String, String>.from(widget.translationOverrides);
      _loadRemoteTranslations();
    }
    if (oldWidget.initialSession != widget.initialSession && widget.initialSession != null) {
      _locationGateReady = false;
      _session = widget.initialSession;
      _bindPushSession();
      _configureDiagnostics(widget.initialSession!);
      _configureLocationTracking(widget.initialSession!);
    }
    if (oldWidget.pushService != widget.pushService) {
      unawaited(_pushOpenSubscription?.cancel());
      unawaited(_pushAlertSubscription?.cancel());
      _configurePush();
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    final foreground = state == AppLifecycleState.resumed;
    if (_appInForeground == foreground) return;
    _appInForeground = foreground;
    _locationTracking?.setAppInForeground(foreground);
    if (foreground) {
      DriverRuntimeInspector.instance.notifyConnectivityRecovered();
    }
  }

  DriverAuthRepository? _authRepository() {
    if (widget.previewContext != null) return null;
    if (widget.authRepository != null) return widget.authRepository;
    if (_baseUrl.isEmpty) return null;
    return HttpDriverAuthRepository(_baseUrl);
  }

  DriverAssignmentRepository? _assignmentRepository(DriverSession session) {
    final factory = widget.assignmentRepositoryFactory;
    if (factory != null) return factory(session);
    if (widget.previewContext != null) return null;
    if (_baseUrl.isEmpty) return null;
    return HttpDriverAssignmentRepository(_baseUrl, session.token);
  }

  DriverNotificationRepository? _notificationRepository(DriverSession session) {
    final factory = widget.notificationRepositoryFactory;
    if (factory != null) return factory(session);
    if (widget.previewContext != null) return null;
    if (_baseUrl.isEmpty) return null;
    return HttpDriverNotificationRepository(
      baseUrl: _baseUrl,
      token: session.token,
    );
  }

  void _configureDiagnostics(DriverSession session) {
    if (widget.previewContext != null || _baseUrl.isEmpty) {
      DriverRuntimeInspector.instance.clearInspectorUpload();
      return;
    }

    DriverRuntimeInspector.instance.configureInspectorUpload(
      baseUrl: _baseUrl,
      token: session.token,
      channel: session.channel == DriverChannel.b2c ? 'b2c' : 'b2b',
      storeId: session.storeId,
    );
  }

  void _configureLocationTracking(DriverSession session) {
    _disposeLocationTracking();

    if (widget.previewContext != null || widget.locationGateService == null) {
      return;
    }

    final factory = widget.locationTrackingFactory;
    if (factory == null && _baseUrl.isEmpty) {
      return;
    }

    final tracking = factory?.call(session, _sessionExpired) ??
        DriverLocationTrackingService(
          locationSource: GeolocatorDriverLocationSource(locale: session.locale),
          heartbeatClient: HttpDriverLocationHeartbeatClient(
            baseUrl: _baseUrl,
            token: session.token,
          ),
          onSessionInvalid: _sessionExpired,
        );

    _locationTracking = tracking;
    tracking.setAppInForeground(_appInForeground);
    tracking.start();
    tracking.setGateReady(_locationGateReady);
  }

  void _onLocationGateStatus(DriverLocationGateStatus status) {
    final ready = status == DriverLocationGateStatus.ready;
    _locationGateReady = ready;
    _locationTracking?.setGateReady(ready);
  }

  void _disposeLocationTracking() {
    final tracking = _locationTracking;
    _locationTracking = null;
    if (tracking == null) return;
    tracking.stop(clearQueue: true);
    tracking.dispose();
  }

  Future<void> _loadRemoteTranslations() async {
    try {
      final fetcher = widget.translationFetcher;
      if (fetcher == null && _baseUrl.isEmpty) return;

      final remote = fetcher != null
          ? await fetcher(widget.locale.languageCode)
          : await fetchDriverTranslationBundle(
              _baseUrl,
              widget.locale.languageCode,
            );

      if (!mounted || remote.isEmpty) return;
      setState(() => _translations = {..._translations, ...remote});
    } catch (_) {
      // Bundled translations remain the fallback when remote loading fails.
    }
  }

  void _configurePush() {
    if (widget.previewContext != null) return;
    final service = widget.pushService;
    if (service == null) return;
    _pushOpenSubscription = service.opens.listen(
      (open) => unawaited(_openFromPush(open)),
    );
    _pushAlertSubscription = service.alerts.listen(_showPushAlert);
    WidgetsBinding.instance.addPostFrameCallback((_) {
      final pending = service.takePendingOpen();
      if (pending != null) unawaited(_openFromPush(pending));
    });
    _bindPushSession();
  }

  void _bindPushSession() {
    if (widget.previewContext != null) return;
    final service = widget.pushService;
    final session = _session;
    if (service != null && session != null) {
      unawaited(service.bindSession(session.token));
    }
  }

  Future<void> _openFromPush(DriverPushOpen open) async {
    final session = _session;
    if (session == null || open.accessRevoked) return;

    var assignmentId = open.assignmentId;
    if (assignmentId == null) {
      final orderId = open.orderId;
      final repository = _assignmentRepository(session);
      if (orderId != null && repository != null) {
        try {
          final rows = await repository.list(session.channel);
          final matches = rows
              .where(
                (row) =>
                    row.channel == session.channel &&
                    row.orderId == orderId,
              )
              .toList(growable: false);
          if (matches.length == 1) {
            assignmentId = matches.single.id;
          }
        } on DriverSessionExpiredException {
          _sessionExpired();
          return;
        } catch (_) {
          assignmentId = null;
        }
      }
    }

    if (!mounted) return;
    if (assignmentId == null) {
      final messenger = _messengerKey.currentState;
      if (messenger != null) {
        messenger.showSnackBar(
          SnackBar(
            content: Text(
              context.tr('driver.notifications.order_unavailable'),
            ),
          ),
        );
      }
      return;
    }

    final route = session.channel == DriverChannel.b2c
        ? DriverRoutes.b2cDeliveries
        : DriverRoutes.b2bDeliveries;
    final navigator = _driverNavigatorKey.currentState;
    if (navigator == null) return;

    if (_lastPushAssignmentId == assignmentId) {
      navigator.pushReplacementNamed(route, arguments: assignmentId);
    } else {
      _lastPushAssignmentId = assignmentId;
      navigator.pushNamed(route, arguments: assignmentId);
    }
  }

  void _showPushAlert(DriverPushAlert alert) {
    if (!_seenPushAlerts.add(alert.eventKey)) return;
    if (_seenPushAlerts.length > 50) {
      _seenPushAlerts.remove(_seenPushAlerts.first);
    }

    final context = _messengerKey.currentContext;
    final identity = context == null
        ? null
        : driverPushIdentityLabel(
            alert.open,
            orderLabel: context.tr('driver.notifications.order_identity'),
            assignmentLabel:
                context.tr('driver.notifications.assignment_identity'),
          );
    final payloadMessage = alert.body.isEmpty
        ? alert.title
        : '${alert.title}\n${alert.body}'; // localization-gate: allow — server-localized push payload.
    final message = identity == null
        ? payloadMessage
        : '$identity\n$payloadMessage';
    final actionable =
        !alert.open.accessRevoked &&
        (alert.open.assignmentId != null || alert.open.orderId != null);

    _messengerKey.currentState?.showSnackBar(
      SnackBar(
        content: Text(message),
        action: actionable
            ? SnackBarAction(
                label: context?.tr('driver.notifications.open') ?? 'View',
                onPressed: () => _openFromPush(alert.open),
              )
            : null,
      ),
    );
  }

  Future<void> _restoreRememberedSession() async {
    try {
      final stored = await _sessionStore.read();
      if (!mounted || stored == null || stored.biometricEnabled) return;
      _authenticated(stored.session, true, false);
    } catch (_) {
      // Secure storage may be unavailable on unsupported preview/test runtimes.
    }
  }

  void _authenticated(
    DriverSession session, [
    bool rememberMe = false,
    bool biometricEnabled = false,
  ]) {
    DriverRuntimeInspector.instance.recordNavigation(
      session.channel == DriverChannel.b2c
          ? DriverRoutes.b2cHome
          : DriverRoutes.b2bHome,
    );
    _locationGateReady = false;
    if (widget.previewContext == null) {
      if (rememberMe) {
        unawaited(
          _sessionStore.write(
            session,
            biometricEnabled: biometricEnabled,
          ),
        );
      } else {
        unawaited(_clearRememberedSession());
      }
    }
    if (mounted) setState(() => _session = session);
    _bindPushSession();
    _configureDiagnostics(session);
    _configureLocationTracking(session);
  }

  Future<void> _clearRememberedSession() async {
    try {
      await _sessionStore.clear();
    } catch (_) {
      // Secure storage is best-effort on unsupported/test runtimes.
    }
  }

  void _sessionExpired() {
    _seenPushAlerts.clear();
    _lastPushAssignmentId = null;
    _disposeLocationTracking();
    _locationGateReady = false;
    final service = widget.pushService;
    if (service != null) unawaited(service.revokeSession());
    DriverRuntimeInspector.instance.clearInspectorUpload();
    DriverRuntimeInspector.instance.recordNavigation('driver.login');
    if (widget.previewContext == null) {
      unawaited(_clearRememberedSession());
    }
    if (mounted) setState(() => _session = null);
  }

  Future<void> _logout() async {
    if (widget.previewContext != null) {
      final previewContext = _messengerKey.currentContext;
      _messengerKey.currentState?.showSnackBar(
        SnackBar(
          content: Text(
            previewContext?.tr('driver.preview.mutation_blocked') ??
                'Safe preview blocks production actions.',
          ),
        ),
      );
      return;
    }

    final session = _session;
    _seenPushAlerts.clear();
    _lastPushAssignmentId = null;
    _disposeLocationTracking();
    _locationGateReady = false;
    final service = widget.pushService;
    if (service != null) await service.revokeSession();
    DriverRuntimeInspector.instance.clearInspectorUpload();
    DriverRuntimeInspector.instance.recordNavigation('driver.login');
    unawaited(_clearRememberedSession());
    if (mounted) setState(() => _session = null);
    if (session == null) return;
    final repository = _authRepository();
    if (repository == null) return;
    try {
      await repository.logout(session.token);
    } catch (_) {
      // Local logout is immediate; remote token expiry remains server-controlled.
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _disposeLocationTracking();
    unawaited(_pushOpenSubscription?.cancel());
    unawaited(_pushAlertSubscription?.cancel());
    super.dispose();
  }

  Widget _withPreviewViewport(Widget child) {
    final bootstrap = widget.previewBootstrap;
    if (bootstrap == null) return child;
    return DriverPreviewViewport(bootstrap: bootstrap, child: child);
  }

  @override
  Widget build(BuildContext context) {
    final session = _session;
    final preview = widget.previewContext;
    final previewConfigurationInvalid = preview != null &&
        (session == null ||
            !preview.matchesSession(session) ||
            widget.assignmentRepositoryFactory == null);
    final authRepository = _authRepository();
    final assignments =
        session == null ? null : _assignmentRepository(session);
    final notifications =
        session == null ? null : _notificationRepository(session);
    final navigator = session == null || assignments == null
        ? null
        : DriverNavigator(
            session.channel,
            driverName: session.name,
            repository: assignments,
            notificationRepository: notifications,
            onSessionExpired: _sessionExpired,
            onLogout: () {
              _logout();
            },
            previewContext: preview,
          );

    final runtimeHome = previewConfigurationInvalid
          ? const _DriverPreviewConfigurationError()
          : session == null
              ? DriverLoginPage(
                  repository: authRepository,
                  sessionStore: _sessionStore,
                  biometricAuthenticator: _biometricAuthenticator,
                  onAuthenticated: _authenticated,
                )
              : assignments == null
                  ? const _DriverRuntimeConfigurationError()
                  : widget.locationGateService == null || preview != null
                      ? Navigator(
                          key: _driverNavigatorKey,
                          initialRoute: widget.initialRoute,
                          onGenerateRoute: navigator!.onGenerateRoute,
                        )
                      : DriverLocationGate(
                          service: widget.locationGateService!,
                          onLogout: _logout,
                          onStatusChanged: _onLocationGateStatus,
                          child: Navigator(
                            key: _driverNavigatorKey,
                            initialRoute: widget.initialRoute,
                            onGenerateRoute: navigator!.onGenerateRoute,
                          ),
                        );

    return MaterialApp(
      scaffoldMessengerKey: _messengerKey,
      debugShowCheckedModeBanner: false,
      title: 'FOODEX Driver',
      theme: widget.theme ?? FoodexTheme.light(),
      locale: widget.locale,
      localizationsDelegates: const [
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      supportedLocales: const [Locale('ar'), Locale('en')],
      builder: (context, child) => _withPreviewViewport(DriverTranslations(
        locale: widget.locale,
        overrides: _translations,
        child: Builder(
          builder: (translatedContext) => Stack(
            fit: StackFit.expand,
            children: [
              child ?? const SizedBox.shrink(),
              if (widget.showPersistentFooter)
                PositionedDirectional(
                start: 0,
                end: 0,
                bottom: 0,
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
                      height: 38,
                      child: Padding(
                        padding: const EdgeInsetsDirectional.only(
                          start: 12,
                          end: 4,
                        ),
                        child: Row(
                          children: [
                            Expanded(
                              child: Text(
                                preview == null
                                    ? '${translatedContext.tr('driver.version')} $_appVersion'
                                    : _previewFooterLabel(
                                        translatedContext,
                                        preview,
                                      ),
                                key: const Key('driver-app-version-footer'),
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
                            TextButton.icon(
                              key: const Key('driver-global-inspector'),
                              onPressed: () {
                                _routeBeforeInspector =
                                    DriverRuntimeInspector.instance.lastRoute ??
                                        (_session == null
                                            ? 'driver.login'
                                            : widget.initialRoute);
                                DriverRuntimeInspector.instance
                                    .recordNavigation('driver.inspector');
                                setState(() => _inspectorOpen = true);
                              },
                              icon: const Icon(
                                Icons.bug_report_outlined,
                                size: 16,
                              ),
                              label: Text(
                                translatedContext.tr('driver.inspector.open'),
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                              ),
                            ),
                            if (_session != null)
                              TextButton.icon(
                                key: const Key('driver-global-logout'),
                                onPressed: _logout,
                                icon: const Icon(
                                  Icons.logout_rounded,
                                  size: 16,
                                ),
                                label: Text(
                                  translatedContext.tr('driver.logout'),
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                ),
                              ),
                          ],
                        ),
                      ),
                    ),
                  ),
                ),
              ),
              if (!widget.showPersistentFooter)
                PositionedDirectional(
                  end: 8,
                  bottom: 8,
                  child: SafeArea(
                    top: false,
                    child: Semantics(
                      button: true,
                      label: translatedContext.tr('driver.inspector.open'),
                      child: IconButton.filledTonal(
                      key: const Key('driver-floating-inspector'),
                      onPressed: () {
                        _routeBeforeInspector =
                            DriverRuntimeInspector.instance.lastRoute ??
                                (_session == null
                                    ? 'driver.login'
                                    : widget.initialRoute);
                        DriverRuntimeInspector.instance
                            .recordNavigation('driver.inspector');
                        setState(() => _inspectorOpen = true);
                      },
                      icon: const Icon(Icons.bug_report_outlined),
                      ),
                    ),
                  ),
                ),
              if (_inspectorOpen)
                Positioned.fill(
                  child: DriverInspectorPanel(
                    authenticated: _session != null,
                    onClose: () {
                      final route = _routeBeforeInspector;
                      if (route != null) {
                        DriverRuntimeInspector.instance.recordNavigation(route);
                      }
                      setState(() {
                        _inspectorOpen = false;
                        _routeBeforeInspector = null;
                      });
                    },
                  ),
                ),
            ],
          ),
        ),
      )),
      home: preview != null || widget.versionPolicyClient == null
          ? runtimeHome
          : DriverVersionPolicyGate(
              client: widget.versionPolicyClient!,
              updateLauncher: widget.updateLauncher,
              child: runtimeHome,
            ),
    );
  }

  String _previewFooterLabel(
    BuildContext context,
    DriverPreviewContext preview,
  ) {
    final parts = <String>[
      context.tr('driver.preview.safe'),
      preview.channel == DriverChannel.b2c ? 'B2C' : 'B2B',
      '${context.tr('driver.preview.store')} ${preview.storeId}',
      if (preview.configurationRevision?.isNotEmpty == true)
        '${context.tr('driver.preview.revision')} ${preview.configurationRevision}',
      if (preview.runtimeVersion?.isNotEmpty == true)
        '${context.tr('driver.preview.runtime')} ${preview.runtimeVersion}',
      '${context.tr('driver.version')} $_appVersion',
    ];
    return parts.join(' · ');
  }
}

class _DriverPreviewConfigurationError extends StatelessWidget {
  const _DriverPreviewConfigurationError();

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Center(
        child: Text(
          context.tr('driver.preview.configuration_invalid'),
          key: const Key('driver-preview-configuration-invalid'),
        ),
      ),
    );
  }
}

class _DriverRuntimeConfigurationError extends StatelessWidget {
  const _DriverRuntimeConfigurationError();

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Center(
        child: Text(
          context.tr('driver.config.missing'),
          key: const Key('driver-config-missing'),
        ),
      ),
    );
  }
}
