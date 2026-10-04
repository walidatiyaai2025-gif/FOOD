import 'dart:convert';
import 'package:http/http.dart' as http;

abstract class B2bApi {
  Future<Object?> get(String path);
}

abstract class B2bDocumentApi {
  Future<List<int>> getBytes(String path);
}

class HttpB2bApi implements B2bApi, B2bDocumentApi {
  HttpB2bApi({
    required this.baseUrl,
    required this.token,
    this.retailStoreContextId,
    http.Client? client,
  }) : _client = client ?? http.Client();

  final String baseUrl;
  final String token;
  final int? retailStoreContextId;
  final http.Client _client;

  @override
  Future<Object?> get(String path) async {
    final response = await _client.get(
      Uri.parse('$baseUrl$path'),
      headers: {
        'Accept': 'application/json',
        'Authorization': 'Bearer $token',
        if (retailStoreContextId != null)
          'X-FOODEX-Retail-Store-ID': retailStoreContextId.toString(),
      },
    );
    if (response.statusCode == 401 || response.statusCode == 403) {
      throw B2bApiException(
        'not_authorized',
        statusCode: response.statusCode,
        supportReference: _supportReference(response.headers),
      );
    }
    if (response.statusCode < 200 || response.statusCode >= 300) {
      throw B2bApiException(
        'http_${response.statusCode}',
        statusCode: response.statusCode,
        supportReference: _supportReference(response.headers),
      );
    }
    return response.body.isEmpty ? null : jsonDecode(response.body);
  }
}

class B2bApiException implements Exception {
  const B2bApiException(
    this.code, {
    this.statusCode,
    this.supportReference,
  });

  final String code;
  final int? statusCode;
  final String? supportReference;
}

String? _supportReference(Map<String, String> headers) {
  for (final key in const [
    'x-request-id',
    'x-correlation-id',
    'request-id',
    'traceparent',
  ]) {
    final value = headers[key];
    if (value != null && value.trim().isNotEmpty) {
      return value.trim();
    }
  }
  return null;
}
