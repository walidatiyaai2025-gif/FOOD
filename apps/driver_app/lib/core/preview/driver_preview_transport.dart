import 'package:http/http.dart' as http;

import '../api/http_driver_api.dart';

class DriverPreviewMutationBlocked implements Exception {
  const DriverPreviewMutationBlocked(this.action);

  final String action;

  @override
  String toString() => 'Driver preview mutation blocked: $action';
}

class DriverPreviewReadHttpClient extends http.BaseClient {
  DriverPreviewReadHttpClient(
    this.delegate, {
    required this.credential,
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

  @override
  Future<http.StreamedResponse> send(http.BaseRequest request) {
    final method = request.method.toUpperCase();
    if (method != 'GET' && method != 'HEAD') {
      return Future<http.StreamedResponse>.error(
        DriverPreviewMutationBlocked('http.' + method.toLowerCase()),
      );
    }

    final path = request.url.path;
    const productionPrefix = '/api/v1/driver/assignments';
    if (path != productionPrefix && !path.startsWith('$productionPrefix/')) {
      return Future<http.StreamedResponse>.error(
        const DriverPreviewMutationBlocked('read.path'),
      );
    }

    final targetUrl = request.url.replace(
      path: path.replaceFirst(
        productionPrefix,
        '/api/v1/app-preview/driver/assignments',
      ),
    );
    final forwarded = http.Request(method, targetUrl)
      ..followRedirects = request.followRedirects
      ..maxRedirects = request.maxRedirects
      ..persistentConnection = request.persistentConnection;

    request.headers.forEach((key, value) {
      final normalized = key.toLowerCase();
      if (normalized == 'authorization' ||
          normalized == 'x-foodex-preview-token' ||
          normalized == 'cookie' ||
          normalized == 'x-guest-token') {
        return;
      }
      forwarded.headers[key] = value;
    });
    forwarded.headers['X-Foodex-Preview-Token'] = credential;

    return delegate.send(forwarded);
  }

  @override
  void close() {
    // Runtime owner controls the underlying HTTP client lifecycle.
  }
}

class DriverPreviewAssignmentBundle {
  DriverPreviewAssignmentBundle({
    required String baseUrl,
    required String credential,
    http.Client? client,
  })  : owner = client ?? http.Client(),
        ownsOwner = client == null {
    transport = DriverPreviewReadHttpClient(
      owner,
      credential: credential,
    );
    assignments = HttpDriverAssignmentRepository(
      baseUrl,
      _authSentinel,
      client: transport,
    );
  }

  static const _authSentinel = 'foodex-preview-host-authenticated';

  final http.Client owner;
  final bool ownsOwner;
  late final DriverPreviewReadHttpClient transport;
  late final HttpDriverAssignmentRepository assignments;

  void close() {
    if (ownsOwner) owner.close();
  }
}
