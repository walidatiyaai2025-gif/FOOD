import 'package:http/http.dart' as http;

class CustomerSessionHttpClient extends http.BaseClient {
  CustomerSessionHttpClient(
    this._inner, {
    required this.onUnauthorized,
    this.isCurrentToken,
  });

  final http.Client _inner;
  final void Function() onUnauthorized;

  /// Ignore late 401 responses from an already-replaced session. A rejected
  /// request must never revoke a newer token acquired while it was in flight.
  /// The app supplies the authoritative current token; other callers retain
  /// the original 401 behavior when this optional predicate is omitted.
  final bool Function(String token)? isCurrentToken;

  static final RegExp _bearerAuthorization = RegExp(
    r'^\s*Bearer\s+(\S+)\s*$',
    caseSensitive: false,
  );

  @override
  Future<http.StreamedResponse> send(http.BaseRequest request) async {
    final response = await _inner.send(request);
    final authorization =
        request.headers['Authorization'] ?? request.headers['authorization'];
    final token = authorization == null
        ? null
        : _bearerAuthorization.firstMatch(authorization)?.group(1);

    if (response.statusCode == 401 &&
        token != null &&
        (isCurrentToken?.call(token) ?? true)) {
      onUnauthorized();
    }

    return response;
  }

  @override
  void close() => _inner.close();
}
