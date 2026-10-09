import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_van_app/core/api/http_van_api.dart';
import 'package:foodex_van_app/features/wallet/http_van_wallet_repository.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  test('Van finance HTTP contract keeps authoritative scope and idempotency', () async {
    final seen = <String>[];
    final client = MockClient((request) async {
      seen.add('${request.method} ${request.url.path}');
      expect(request.headers['authorization'], 'Bearer van-token');

      if (request.method == 'GET' && request.url.path == '/api/v1/van/customers') {
        return http.Response(
          jsonEncode({
            'data': [
              {
                'type': 'b2b',
                'id': 44,
                'name': 'Scoped Customer',
                'store_id': 7,
              },
            ],
          }),
          200,
        );
      }

      if (request.method == 'GET' &&
          request.url.path == '/api/v1/van/customers/b2b/44/collection-context') {
        expect(request.url.queryParameters['store_id'], '7');
        return http.Response(
          jsonEncode({
            'data': {
              'customer_type': 'b2b',
              'customer_id': 44,
              'store_id': 7,
              'invoices': [
                {
                  'id': 91,
                  'number': 'INV-91',
                  'currency': 'EGP',
                  'total': 100,
                  'outstanding_amount': 65,
                },
              ],
            },
          }),
          200,
        );
      }

      if (request.method == 'POST' &&
          request.url.path == '/api/v1/van/customers/b2b/44/collect') {
        expect(request.headers['idempotency-key'], 'collect-retry-1');
        final body = jsonDecode(request.body) as Map<String, dynamic>;
        expect(body['store_id'], 7);
        expect(body['invoice_id'], 91);
        expect(body['amount'], 25);
        return http.Response(
          jsonEncode({
            'data': {
              'receipt': {
                'id': 501,
                'amount': 25,
                'currency': 'EGP',
                'status': 'posted',
                'created_at': '2026-10-06T03:00:00Z',
              },
              'remaining_outstanding': 40,
              'wallet': {
                'id': 12,
                'store_id': 7,
                'currency': 'EGP',
                'status': 'active',
                'custody_balance': 25,
                'available_to_remit': 25,
                'transactions': [],
                'remittances': [],
              },
            },
          }),
          201,
        );
      }

      if (request.method == 'POST' &&
          request.url.path == '/api/v1/van/remittances') {
        expect(request.headers['idempotency-key'], 'remit-retry-1');
        return http.Response(
          jsonEncode({
            'data': {
              'remittance': {
                'id': 77,
                'amount': 20,
                'currency': 'EGP',
                'method': 'bank_deposit',
                'status': 'pending',
                'created_at': '2026-10-06T03:05:00Z',
              },
              'wallet': {
                'id': 12,
                'store_id': 7,
                'currency': 'EGP',
                'status': 'active',
                'custody_balance': 25,
                'available_to_remit': 5,
                'transactions': [],
                'remittances': [],
              },
            },
          }),
          201,
        );
      }

      return http.Response('{}', 404);
    });

    final repository = HttpVanWalletRepository(
      VanApiClient(
        'https://foodex.example/',
        'van-token',
        client: client,
      ),
    );

    final customers = await repository.customers();
    expect(customers.single.name, 'Scoped Customer');

    final context = await repository.collectionContext(customers.single);
    expect(context.invoices.single.outstandingAmount, 65);

    final collection = await repository.collect(
      customer: customers.single,
      invoiceId: 91,
      amount: 25,
      idempotencyKey: 'collect-retry-1',
    );
    expect(collection.receipt.id, 501);
    expect(collection.remainingOutstanding, 40);
    expect(collection.wallet.custodyBalance, 25);

    final wallet = await repository.remit(
      collectionAccountId: 12,
      amount: 20,
      method: 'bank_deposit',
      idempotencyKey: 'remit-retry-1',
    );
    expect(wallet.availableToRemit, 5);

    expect(
      seen,
      containsAll([
        'GET /api/v1/van/customers',
        'GET /api/v1/van/customers/b2b/44/collection-context',
        'POST /api/v1/van/customers/b2b/44/collect',
        'POST /api/v1/van/remittances',
      ]),
    );
  });

  test('Van finance HTTP errors retain sanitized status for actionable UI', () async {
    final client = MockClient((request) async => http.Response(
          jsonEncode({
            'message': 'The given data was invalid.',
            'errors': {
              'amount': ['Remittance exceeds available custody.'],
            },
          }),
          422,
        ));

    final repository = HttpVanWalletRepository(
      VanApiClient(
        'https://foodex.example/',
        'van-token',
        client: client,
      ),
    );

    await expectLater(
      repository.remit(
        collectionAccountId: 12,
        amount: 20,
        method: 'bank_transfer',
        idempotencyKey: 'remit-invalid-1',
      ),
      throwsA(
        isA<VanApiException>().having(
          (error) => error.statusCode,
          'statusCode',
          422,
        ),
      ),
    );
  });

}
