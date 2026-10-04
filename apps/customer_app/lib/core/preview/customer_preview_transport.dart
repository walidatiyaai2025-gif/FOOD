import 'package:http/http.dart' as http;

import '../api/b2b_api.dart';
import '../api/b2c_account_api.dart';
import '../api/b2c_catalog_api.dart';
import '../api/customer_action_api.dart';
import '../api/storefront_api.dart';
import '../api/wholesale_commerce_api.dart';
import '../auth/customer_session.dart';
import 'customer_preview_context.dart';

class CustomerPreviewReadHttpClient extends http.BaseClient {
  CustomerPreviewReadHttpClient(
    this.delegate, {
    required this.credential,
    required this.channel,
  }) {
    if (credential.trim().isEmpty) {
      throw ArgumentError.value(
        credential,
        'credential',
        'Preview credential cannot be empty.',
      );
    }
  }

  final http.Client delegate;
  final String credential;
  final CustomerChannel channel;

  @override
  Future<http.StreamedResponse> send(http.BaseRequest request) {
    final method = request.method.toUpperCase();
    if (method != 'GET' && method != 'HEAD') {
      return Future<http.StreamedResponse>.error(
        CustomerPreviewMutationBlocked(
          'http.${method.toLowerCase()}',
        ),
      );
    }

    final mappedPath = _previewPath(request.url.path);
    final publicRead = mappedPath == null && _isPublicRead(request.url.path);

    if (mappedPath == null && !publicRead) {
      return Future<http.StreamedResponse>.error(
        const CustomerPreviewScopeException('preview_read_path_not_allowed'),
      );
    }

    final targetUrl = mappedPath == null
        ? request.url
        : request.url.replace(path: mappedPath);

    final forwarded = http.Request(method, targetUrl)
      ..followRedirects = request.followRedirects
      ..maxRedirects = request.maxRedirects
      ..persistentConnection = request.persistentConnection;

    request.headers.forEach((key, value) {
      final normalized = key.toLowerCase();
      if (normalized == 'authorization' ||
          normalized == 'x-foodex-preview-token' ||
          normalized == 'cookie') {
        return;
      }
      forwarded.headers[key] = value;
    });

    if (mappedPath != null) {
      forwarded.headers['X-Foodex-Preview-Token'] = credential;
    }

    return delegate.send(forwarded);
  }

  String? _previewPath(String path) {
    const api = '/api/v1';
    if (path == '$api/app-preview/storefront-configuration' ||
        path == '$api/app-preview/events') {
      return path;
    }

    final previewPrefix = channel == CustomerChannel.b2b
        ? '$api/b2b/app-preview/customer'
        : '$api/app-preview/customer';

    if (path == '$api/notification-campaign-popups') {
      return '$previewPrefix/notification-campaign-popups';
    }

    const commonRoots = <String>[
      '/profile',
      '/orders',
      '/invoices',
      '/cart',
      '/store-selector',
    ];

    if (path.startsWith('$api/b2b/')) {
      if (channel != CustomerChannel.b2b) {
        return null;
      }

      final tail = path.substring('$api/b2b'.length);
      if (_isAllowedB2bTail(tail)) {
        return '$previewPrefix$tail';
      }

      return null;
    }

    if (!path.startsWith(api)) {
      return null;
    }

    final tail = path.substring(api.length);
    if (commonRoots.any(
      (root) => tail == root || tail.startsWith('$root/'),
    )) {
      return '$previewPrefix$tail';
    }

    return null;
  }

  bool _isAllowedB2bTail(String tail) {
    const roots = <String>[
      '/stores/',
      '/checkout/options',
      '/dashboard',
      '/reports/',
      '/products',
      '/invoices',
      '/account-statement',
      '/orders',
    ];

    return roots.any(
      (root) => tail == root || tail.startsWith(root),
    );
  }

  bool _isPublicRead(String path) {
    const exact = <String>{
      '/api/v1/marketplace',
      '/api/v1/stores',
      '/api/v1/live-ads',
      '/api/v1/mobile/runtime',
      '/api/v1/app-version',
    };
    if (exact.contains(path)) {
      return true;
    }

    return path.startsWith('/api/v1/stores/') ||
        path.startsWith('/api/v1/products/') ||
        path.startsWith('/api/v1/platform/') ||
        path.startsWith('/api/v1/translations/') ||
        path.startsWith('/api/v1/wholesale/stores/');
  }

  @override
  void close() {
    // The embedding Preview host owns the underlying transport lifecycle.
  }
}

class CustomerPreviewApiBundle {
  CustomerPreviewApiBundle({
    required this.baseUrl,
    required this.context,
    required String credential,
    http.Client? client,
  })  : _transportOwner = client ?? http.Client(),
        _ownsTransport = client == null {
    if (!context.authenticated) {
      throw ArgumentError(
        'CustomerPreviewApiBundle is only for authenticated preview sessions.',
      );
    }

    transport = CustomerPreviewReadHttpClient(
      _transportOwner,
      credential: credential,
      channel: context.channel,
    );

    catalog = HttpB2cCatalogApi(
      baseUrl: baseUrl,
      client: transport,
    );

    account = HttpB2cAccountApi(
      baseUrl: baseUrl,
      token: _authSentinel,
      guestSession: CustomerGuestSession(),
      client: transport,
    );

    storefront = HttpStorefrontApi(
      baseUrl: baseUrl,
      token: context.authenticated ? _authSentinel : null,
      client: transport,
    );

    if (context.channel == CustomerChannel.b2b) {
      b2b = HttpB2bApi(
        baseUrl: baseUrl,
        token: _authSentinel,
        client: transport,
      );
      wholesale = HttpWholesaleCommerceApi(
        baseUrl: baseUrl,
        token: _authSentinel,
        client: transport,
      );
    }
  }

  static const _authSentinel = 'foodex-preview-host-authenticated';

  final String baseUrl;
  final CustomerPreviewContext context;
  final http.Client _transportOwner;
  final bool _ownsTransport;

  late final CustomerPreviewReadHttpClient transport;
  late final B2cCatalogApi catalog;
  late final B2cAccountApi account;
  late final StorefrontApi storefront;
  B2bApi? b2b;
  WholesaleCommerceApi? wholesale;

  void close() {
    if (_ownsTransport) {
      _transportOwner.close();
    }
  }
}
