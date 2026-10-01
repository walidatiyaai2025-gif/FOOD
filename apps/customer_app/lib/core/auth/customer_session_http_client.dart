import 'package:http/http.dart' as http;

class CustomerSessionHttpClient extends http.BaseClient {
  CustomerSessionHttpClient(
    this._inner, {
    required this.onUnauthorized,
  });

  final http.Client _inner;
  final void Function() onUnauthorized;

  @override
  Future<http.StreamedResponse> send(http.BaseRequest request) async {
    final response = await _inner.send(request);
    final authorization = request.headers['Authorization'];

    if (response.statusCode == 401 &&
        authorization != null &&
        authorization.trim().isNotEmpty) {
      onUnauthorized();
    }

    return response;
  }

  @override
  void close() => _inner.close();
}
