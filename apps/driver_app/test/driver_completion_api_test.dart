import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_driver_app/features/delivery/completion/driver_completion_api.dart';
import 'package:foodex_driver_app/features/delivery/completion/driver_completion_contract.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  test('completion gateway posts proof and status in one multipart request', () async {
    final directory = await Directory.systemTemp.createTemp('foodex-proof-');
    addTearDown(() => directory.delete(recursive: true));
    final file = File('${directory.path}/proof.jpg');
    await file.writeAsBytes([1, 2, 3, 4]);

    http.Request? captured;
    final gateway = HttpDriverCompletionGateway(
      baseUrl: 'https://foodex.example',
      token: 'driver-token',
      client: MockClient((request) async {
        captured = request;
        return http.Response(
          '{"data":{"id":31,"status":"delivered"}}',
          200,
          headers: {'content-type': 'application/json'},
        );
      }),
    );

    final result = await gateway.submit(
      assignmentId: 31,
      draft: DriverCompletionDraft(
        target: DriverCompletionTarget.delivered,
        note: 'handed to customer',
        proof: DriverProofAttachment(
          path: file.path,
          fileName: 'proof.jpg',
          byteLength: await file.length(),
          mimeType: 'image/jpeg',
        ),
      ),
    );

    expect(captured?.method, 'POST');
    expect(captured?.url.path, '/api/v1/driver/assignments/31/status');
    expect(captured?.headers['Authorization'], 'Bearer driver-token');
    expect(captured?.headers['content-type'], startsWith('multipart/form-data;'));
    expect(captured?.body, contains('name="status"'));
    expect(captured?.body, contains('delivered'));
    expect(captured?.body, contains('name="note"'));
    expect(captured?.body, contains('handed to customer'));
    expect(captured?.body, contains('name="proof_image"'));
    expect(captured?.body, contains('filename="proof.jpg"'));
    expect(result.assignmentId, 31);
    expect(result.status, 'delivered');
  });

  test('completion gateway preserves backend validation failures', () async {
    final gateway = HttpDriverCompletionGateway(
      baseUrl: 'https://foodex.example',
      token: 'driver-token',
      client: MockClient(
        (request) async => http.Response(
          '{"message":"Proof is required."}',
          422,
          headers: {'content-type': 'application/json'},
        ),
      ),
    );

    await expectLater(
      gateway.submit(
        assignmentId: 31,
        draft: const DriverCompletionDraft(
          target: DriverCompletionTarget.failed,
          failureReason: DriverFailureReason.customerNoAnswer,
        ),
      ),
      throwsA(
        isA<DriverCompletionException>().having(
          (error) => error.code,
          'code',
          'validation_failed',
        ),
      ),
    );
  });
}
