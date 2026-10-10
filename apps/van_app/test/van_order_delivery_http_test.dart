import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_van_app/core/api/http_van_api.dart';
import 'package:foodex_van_app/features/orders/http_van_order_repository.dart';
import 'package:foodex_van_app/features/orders/van_order_contract.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  test('Van delivery repository uses configured failure reasons', () async {
    final repository = HttpVanOrderRepository(
      VanApiClient(
        'https://foodex.example/',
        'van-token',
        client: MockClient((request) async {
          expect(request.url.path, '/api/v1/lookups/failed-delivery-reasons');
          expect(request.headers['Authorization'], 'Bearer van-token');
          return http.Response(
            '{"data":[{"code":"customer_no_answer","label_ar":"العميل لا يجيب","label_en":"Customer did not answer"},{"code":"other","label_ar":"أخرى","label_en":"Other"}]}',
            200,
            headers: {'content-type': 'application/json'},
          );
        }),
      ),
    );

    final reasons = await repository.failedDeliveryReasons();

    expect(reasons.map((reason) => reason.code), [
      'customer_no_answer',
      'other',
    ]);
    expect(reasons.last.labelEn, 'Other');
  });

  test('Van proof upload is multipart idempotent and parses readiness', () async {
    final directory = await Directory.systemTemp.createTemp('foodex-van-proof-');
    addTearDown(() => directory.delete(recursive: true));
    final file = File('${directory.path}/proof.jpg');
    await file.writeAsBytes([1, 2, 3, 4]);

    http.Request? captured;
    final repository = HttpVanOrderRepository(
      VanApiClient(
        'https://foodex.example/',
        'van-token',
        client: MockClient((request) async {
          captured = request;
          return http.Response(
            '{"data":{"order_id":77,"order_status":"out_for_delivery","status":"out_for_delivery","allowed_actions":["delivered","failed"],"proof_required_for_delivered":true,"delivery_proof_ready":true,"failure_reason_code":null,"failure_note":null,"latest_proof":{"id":91,"type":"delivery_image","available":true,"captured_at":"2026-10-10T11:00:00+03:00"},"last_transition_at":"2026-10-10T10:55:00+03:00"}}',
            200,
            headers: {'content-type': 'application/json'},
          );
        }),
      ),
    );

    final state = await repository.uploadProof(
      orderId: 77,
      proof: VanProofAttachment(
        path: file.path,
        fileName: 'proof.jpg',
        byteLength: await file.length(),
        mimeType: 'image/jpeg',
      ),
      note: 'Door proof',
      idempotencyKey: 'van-proof-http-0001',
    );

    expect(captured?.method, 'POST');
    expect(captured?.url.path, '/api/v1/van/orders/77/execution/proof');
    expect(captured?.headers['Authorization'], 'Bearer van-token');
    expect(captured?.headers['Idempotency-Key'], 'van-proof-http-0001');
    expect(captured?.headers['content-type'], startsWith('multipart/form-data;'));
    expect(captured?.body, contains('name="note"'));
    expect(captured?.body, contains('Door proof'));
    expect(captured?.body, contains('name="proof_image"'));
    expect(captured?.body, contains('filename="proof.jpg"'));
    expect(state.deliveryProofReady, isTrue);
    expect(state.latestProof?.available, isTrue);
  });

  test('Van failed delivery and retry use dedicated server endpoints', () async {
    final requests = <http.Request>[];
    final repository = HttpVanOrderRepository(
      VanApiClient(
        'https://foodex.example/',
        'van-token',
        client: MockClient((request) async {
          requests.add(request);
          if (request.url.path.endsWith('/fail')) {
            return http.Response(
              '{"data":{"order_id":77,"order_status":"failed","status":"failed","allowed_actions":["out_for_delivery"],"proof_required_for_delivered":true,"delivery_proof_ready":false,"failure_reason_code":"other","failure_note":"Gate locked","latest_proof":null,"last_transition_at":"2026-10-10T11:05:00+03:00"}}',
              200,
            );
          }
          return http.Response(
            '{"data":{"order_id":77,"order_status":"out_for_delivery","status":"out_for_delivery","allowed_actions":["delivered","failed"],"proof_required_for_delivered":true,"delivery_proof_ready":false,"failure_reason_code":null,"failure_note":null,"latest_proof":null,"last_transition_at":"2026-10-10T11:06:00+03:00"}}',
            200,
          );
        }),
      ),
    );

    final failed = await repository.failOrder(
      orderId: 77,
      failureReason: 'other',
      note: 'Gate locked',
      idempotencyKey: 'van-fail-http-0001',
    );
    final retried = await repository.retryOrder(
      orderId: 77,
      idempotencyKey: 'van-retry-http-0001',
    );

    expect(requests[0].url.path, '/api/v1/van/orders/77/execution/fail');
    expect(requests[0].headers['Idempotency-Key'], 'van-fail-http-0001');
    expect(requests[0].body, contains('name="failure_reason"'));
    expect(requests[0].body, contains('Gate locked'));
    expect(failed.failureReasonCode, 'other');
    expect(failed.failureNote, 'Gate locked');

    expect(requests[1].url.path, '/api/v1/van/orders/77/execution/retry');
    expect(requests[1].headers['Idempotency-Key'], 'van-retry-http-0001');
    expect(retried.status, 'out_for_delivery');
    expect(retried.deliveryProofReady, isFalse);
  });
}
