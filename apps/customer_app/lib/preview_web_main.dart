// ignore_for_file: avoid_web_libraries_in_flutter, deprecated_member_use

import 'dart:async';
import 'dart:convert';
import 'dart:html' as html;

import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;

import 'app.dart';
import 'core/api/b2c_account_api.dart';
import 'core/api/b2c_catalog_api.dart';
import 'core/api/storefront_api.dart';
import 'core/auth/customer_session.dart';
import 'core/config/foodex_environment.dart';
import 'core/preview/customer_preview_bootstrap.dart';
import 'core/preview/customer_preview_transport.dart';
import 'core/routing/customer_routes.dart';

const _contractVersion = String.fromEnvironment(
  'FOODEX_PREVIEW_CONTRACT_VERSION',
  defaultValue: 'shared-flutter-v1',
);
const _configuredParentOrigin = String.fromEnvironment(
  'FOODEX_PREVIEW_PARENT_ORIGIN',
);
const _appVersion = '1.0.38';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  runApp(const CustomerPreviewWebHost());
}

class CustomerPreviewWebHost extends StatefulWidget {
  const CustomerPreviewWebHost({super.key});

  @override
  State<CustomerPreviewWebHost> createState() => _CustomerPreviewWebHostState();
}

class _CustomerPreviewWebHostState extends State<CustomerPreviewWebHost> {
  StreamSubscription<html.MessageEvent>? _messages;
  CustomerPreviewBootstrap? _bootstrap;
  CustomerPreviewApiBundle? _authenticatedBundle;
  http.Client? _transportOwner;
  String? _configurationError;

  String? get _parentOrigin {
    final uri = Uri.tryParse(_configuredParentOrigin.trim());
    if (uri == null ||
        !uri.hasScheme ||
        uri.host.isEmpty ||
        (uri.scheme != 'https' && uri.scheme != 'http')) {
      return null;
    }
    return uri.origin;
  }

  @override
  void initState() {
    super.initState();

    final origin = _parentOrigin;
    if (origin == null) {
      _configurationError = 'preview_parent_origin_missing';
      return;
    }

    _messages = html.window.onMessage.listen(_handleMessage);
    WidgetsBinding.instance.addPostFrameCallback((_) {
      _post(CustomerPreviewBootstrap.readyEnvelope(_contractVersion));
    });
  }

  void _handleMessage(html.MessageEvent event) {
    final origin = _parentOrigin;
    if (origin == null || event.source != html.window.parent) {
      return;
    }

    try {
      final bootstrap = CustomerPreviewBootstrap.parse(
        message: _messageData(event.data),
        eventOrigin: event.origin,
        allowedParentOrigin: origin,
        expectedContractVersion: _contractVersion,
      );

      _replaceTransport(bootstrap);
      if (!mounted) return;

      setState(() {
        _bootstrap = bootstrap;
        _configurationError = null;
      });

      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (!mounted || _bootstrap != bootstrap) return;
        _post(
          bootstrap.statusMessage(
            'ready',
            runtimeVersion: _contractVersion,
            appVersion: _appVersion,
            configurationRevision: bootstrap.context.configurationRevision,
          ),
        );
      });
    } on CustomerPreviewBootstrapException catch (error) {
      _post({
        'type': CustomerPreviewBootstrap.statusType,
        'version': _contractVersion,
        'state': 'error',
        'code': error.code,
      });
    } catch (_) {
      _post({
        'type': CustomerPreviewBootstrap.statusType,
        'version': _contractVersion,
        'state': 'error',
        'code': 'bootstrap_failed',
      });
    }
  }

  Object? _messageData(Object? data) {
    if (data is String) {
      try {
        return jsonDecode(data);
      } catch (_) {
        return data;
      }
    }
    if (data is Map) {
      return Map<String, dynamic>.from(data);
    }
    return data;
  }

  void _replaceTransport(CustomerPreviewBootstrap bootstrap) {
    _disposeTransport();

    final owner = _PreviewReportingHttpClient(
      http.Client(),
      onStatus: (status) => _reportHttpStatus(bootstrap, status),
    );
    _transportOwner = owner;

    if (bootstrap.authenticated) {
      _authenticatedBundle = CustomerPreviewApiBundle(
        baseUrl: FoodexEnvironment.apiBaseUrl,
        context: bootstrap.context,
        credential: bootstrap.credential!,
        client: owner,
      );
    }
  }

  void _reportHttpStatus(
    CustomerPreviewBootstrap bootstrap,
    int statusCode,
  ) {
    if (_bootstrap != bootstrap && _bootstrap != null) return;

    final state = switch (statusCode) {
      401 => 'expired',
      403 => 'forbidden',
      _ => statusCode >= 500 ? 'error' : null,
    };
    if (state == null) return;

    _post(
      bootstrap.statusMessage(
        state,
        code: 'http_$statusCode',
        runtimeVersion: _contractVersion,
        appVersion: _appVersion,
        configurationRevision: bootstrap.context.configurationRevision,
      ),
    );
  }

  void _post(Object payload) {
    final origin = _parentOrigin;
    if (origin == null) return;
    html.window.parent?.postMessage(payload, origin);
  }

  void _disposeTransport() {
    _authenticatedBundle?.close();
    _authenticatedBundle = null;
    _transportOwner?.close();
    _transportOwner = null;
  }

  @override
  void dispose() {
    unawaited(_messages?.cancel());
    _disposeTransport();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final error = _configurationError;
    if (error != null) {
      return _RuntimeStateShell(code: error);
    }

    final bootstrap = _bootstrap;
    final owner = _transportOwner;
    if (bootstrap == null || owner == null) {
      return const _RuntimeStateShell(code: 'waiting_for_dashboard');
    }

    if (bootstrap.authenticated) {
      final bundle = _authenticatedBundle;
      if (bundle == null) {
        return const _RuntimeStateShell(code: 'preview_transport_unavailable');
      }

      return FoodexCustomerApp.preview(
        key: ValueKey(_runtimeKey(bootstrap)),
        previewContext: bootstrap.context,
        b2cCatalogApi: bundle.catalog,
        b2cAccountApi: bundle.account,
        storefrontApi: bundle.storefront,
        marketplaceClient: bundle.transport,
        b2bApi: bundle.b2b,
        wholesaleCommerceApi: bundle.wholesale,
        initialRoute: _initialRoute(bootstrap),
        locale: Locale(bootstrap.locale),
      );
    }

    final guestSession = CustomerGuestSession();
    return FoodexCustomerApp.preview(
      key: ValueKey(_runtimeKey(bootstrap)),
      previewContext: bootstrap.context,
      b2cCatalogApi: HttpB2cCatalogApi(
        baseUrl: FoodexEnvironment.apiBaseUrl,
        client: owner,
      ),
      b2cAccountApi: HttpB2cAccountApi(
        baseUrl: FoodexEnvironment.apiBaseUrl,
        token: null,
        guestSession: guestSession,
        client: owner,
      ),
      storefrontApi: HttpStorefrontApi(
        baseUrl: FoodexEnvironment.apiBaseUrl,
        token: null,
        client: owner,
      ),
      marketplaceClient: owner,
      initialRoute: _initialRoute(bootstrap),
      locale: Locale(bootstrap.locale),
    );
  }

  String _runtimeKey(CustomerPreviewBootstrap bootstrap) =>
      '${bootstrap.context.sessionId ?? 'guest'}:'
      '${bootstrap.context.channel.name}:'
      '${bootstrap.context.storeId}:'
      '${bootstrap.configuration}:'
      '${bootstrap.locale}:'
      '${bootstrap.deviceProfile}:'
      '${bootstrap.deviceWidth}';

  String _initialRoute(CustomerPreviewBootstrap bootstrap) {
    if (bootstrap.context.channel == CustomerChannel.b2c) {
      return '/retail/${bootstrap.context.storeId}/home';
    }
    if (bootstrap.authenticated) {
      return CustomerRoutePaths.b2bHome;
    }
    return CustomerRoutePaths.marketplace;
  }
}

class _PreviewReportingHttpClient extends http.BaseClient {
  _PreviewReportingHttpClient(
    this.delegate, {
    required this.onStatus,
  });

  final http.Client delegate;
  final void Function(int statusCode) onStatus;

  @override
  Future<http.StreamedResponse> send(http.BaseRequest request) async {
    try {
      final response = await delegate.send(request);
      onStatus(response.statusCode);
      return response;
    } on http.ClientException {
      onStatus(503);
      rethrow;
    }
  }

  @override
  void close() => delegate.close();
}

class _RuntimeStateShell extends StatelessWidget {
  const _RuntimeStateShell({required this.code});

  final String code;

  @override
  Widget build(BuildContext context) => MaterialApp(
        debugShowCheckedModeBanner: false,
        home: Scaffold(
          body: Center(
            child: Semantics(
              label: code,
              child: const SizedBox(
                width: 28,
                height: 28,
                child: CircularProgressIndicator(strokeWidth: 2.5),
              ),
            ),
          ),
        ),
      );
}
