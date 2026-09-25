import 'dart:convert';
import 'package:http/http.dart' as http;

abstract class B2bApi {
  Future<Object?> get(String path);
}

class HttpB2bApi implements B2bApi {
  HttpB2bApi({required this.baseUrl, required this.token, http.Client? client}) : _client = client ?? http.Client();
  final String baseUrl;
  final String token;
  final http.Client _client;

  @override
  Future<Object?> get(String path) async {
    final response = await _client.get(Uri.parse('$baseUrl$path'), headers: {'Accept': 'application/json', 'Authorization': 'Bearer $token'});
    if (response.statusCode == 401 || response.statusCode == 403) throw const B2bApiException('not_authorized');
    if (response.statusCode < 200 || response.statusCode >= 300) throw B2bApiException('http_${response.statusCode}');
    return response.body.isEmpty ? null : jsonDecode(response.body);
  }
}

class B2bApiException implements Exception {
  const B2bApiException(this.code);
  final String code;
}
