import 'dart:convert';

import 'package:http/http.dart' as http;

import 'driver_completion_contract.dart';

class HttpDriverCompletionGateway implements DriverCompletionGateway {
  HttpDriverCompletionGateway({
    required String baseUrl,
    required this.token,
    http.Client? client,
  })  : _base = _normalizeBase(baseUrl),
        _client = client ?? http.Client();

  final Uri _base;
  final String token;
  final http.Client _client;

  @override
  Future<DriverCompletionResult> submit({
    required int assignmentId,
    required DriverCompletionDraft draft,
  }) async {
    if (assignmentId <= 0) {
      throw const DriverCompletionException('invalid_assignment_id');
    }
    if (token.trim().isEmpty) {
      throw const DriverCompletionException('session_expired');
    }

    final validation = draft.validationError;
    if (validation != null) {
      throw DriverCompletionException(_validationCode(validation));
    }

    final request = http.MultipartRequest(
      'POST',
      _base.resolve('api/v1/driver/assignments/$assignmentId/status'),
    )
      ..headers.addAll({
        'Accept': 'application/json',
        'Authorization': 'Bearer $token',
      })
      ..fields['status'] = draft.target.status;

    final note = draft.note.trim();
    if (note.isNotEmpty) request.fields['note'] = note;

    final failureReason = draft.failureReason?.trim();
    if (failureReason != null && failureReason.isNotEmpty) {
      request.fields['failure_reason'] = failureReason;
    }

    final proof = draft.proof;
    if (proof != null) {
      request.files.add(
        await http.MultipartFile.fromPath(
          'proof_image',
          proof.path,
          filename: proof.fileName,
        ),
      );
    }

    final http.Response response;
    try {
      final streamed = await _client.send(request);
      response = await http.Response.fromStream(streamed);
    } on http.ClientException catch (error) {
      throw DriverCompletionException(
        'network_unavailable',
        message: error.message,
      );
    } catch (error) {
      throw DriverCompletionException(
        'proof_upload_failed',
        message: error.toString(),
      );
    }

    final decoded = _decode(response.body);

    if (response.statusCode == 401) {
      throw const DriverCompletionException('session_expired');
    }
    if (response.statusCode == 403) {
      throw const DriverCompletionException('forbidden');
    }
    if (response.statusCode == 404) {
      throw const DriverCompletionException('assignment_not_found');
    }
    if (response.statusCode == 409) {
      throw DriverCompletionException(
        'stale_assignment',
        message: _message(decoded),
      );
    }
    if (response.statusCode == 422) {
      throw DriverCompletionException(
        'validation_failed',
        message: _message(decoded),
      );
    }
    if (response.statusCode < 200 || response.statusCode >= 300) {
      throw DriverCompletionException(
        'http_${response.statusCode}',
        message: _message(decoded),
      );
    }
    if (decoded is! Map) {
      throw const DriverCompletionException('invalid_response');
    }

    return DriverCompletionResult.fromJson(
      assignmentId,
      Map<String, dynamic>.from(decoded),
    );
  }
}

Uri _normalizeBase(String raw) {
  final parsed = Uri.parse(raw);
  final path = parsed.path.endsWith('/') ? parsed.path : '${parsed.path}/';
  return parsed.replace(path: path);
}

Object? _decode(String body) {
  if (body.trim().isEmpty) return null;
  try {
    return jsonDecode(body);
  } catch (_) {
    return null;
  }
}

String? _message(Object? decoded) {
  if (decoded is Map && decoded['message'] is String) {
    final value = (decoded['message'] as String).trim();
    if (value.isNotEmpty) return value;
  }
  return null;
}

String _validationCode(DriverCompletionValidationError error) => switch (error) {
      DriverCompletionValidationError.proofRequired => 'proof_required',
      DriverCompletionValidationError.proofTooLarge => 'proof_too_large',
      DriverCompletionValidationError.failureReasonRequired =>
        'failure_reason_required',
      DriverCompletionValidationError.otherReasonNoteRequired =>
        'other_reason_note_required',
    };
