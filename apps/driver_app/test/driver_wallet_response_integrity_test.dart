import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_driver_app/core/api/http_driver_api.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

Map<String, dynamic> _wallet() => {
      'id': 81,
      'currency': 'KWD',
      'status': 'active',
      'custody_balance': 23.5,
      'available_to_remit': 20.0,
      'unsettled_collections': 23.5,
      'total_collected': 50.0,
      'orders': [
        {
          'order_id': 13,
          'order_number': 'RETAIL-13',
          'currency': 'KWD',
          'order_total': 50,
          'collected_amount': 23.5,
          'remaining_amount': 26.5,
          'payment_state': 'partially_settled',
        },
      ],
      'transactions': [
        {
          'id': 23,
          'amount': 23.5,
          'currency': 'KWD',
          'status': 'posted',
          'created_at': '2026-11-11T00:00:00Z',
        },
      ],
      'remittances': [
        {
          'id': 45,
          'amount': 3.5,
          'currency': 'KWD',
          'method': 'cash',
          'status': 'pending',
          'created_at': '2026-11-11T00:00:00Z',
        },
      ],
    };

HttpDriverAssignmentRepository _repository(
  List<dynamic> accounts, {
  int status = 200,
}) =>
    HttpDriverAssignmentRepository(
      'https://foodex.example/',
      'b2c-driver-token',
      client: MockClient((request) async {
        expect(request.method, 'GET');
        expect(request.url.path, '/api/v1/driver/wallet');
        expect(request.headers['Authorization'], 'Bearer b2c-driver-token');
        return http.Response(jsonEncode({'data': accounts}), status);
      }),
    );

void main() {
  test('fully scoped wallet preserves balances and complete transaction rows',
      () async {
    final accounts = await _repository([_wallet()]).wallet();
    expect(accounts, hasLength(1));
    final wallet = accounts.single;
    expect(wallet.id, 81);
    expect(wallet.currency, 'KWD');
    expect(wallet.custodyBalance, 23.5);
    expect(wallet.availableToRemit, 20);
    expect(wallet.unsettledCollectionsBalance, 23.5);
    expect(wallet.totalCollected, 50);
    expect(wallet.collectedOrders.single.orderId, 13);
    expect(wallet.transactions.single.id, 23);
    expect(wallet.remittances.single.id, 45);
  });

  test('legitimate empty wallet remains an empty result', () async {
    expect(await _repository([]).wallet(), isEmpty);
  });

  test('malformed collection account cannot be dropped as empty success',
      () async {
    for (final invalid in <dynamic>[null, 10, 'invalid-account']) {
      await expectLater(
        _repository([invalid]).wallet(),
        throwsA(isA<DriverApiException>()),
      );
    }
  });

  test('bad financial balances or missing custody fail closed', () async {
    final brokenWallets = <Map<String, dynamic>>[
      {..._wallet()}..remove('custody_balance'),
      {..._wallet(), 'custody_balance': '23.500'},
      {..._wallet()}..remove('available_to_remit'),
      {..._wallet(), 'available_to_remit': null},
      {..._wallet(), 'id': 0},
      {..._wallet(), 'id': 81.5},
      {..._wallet(), 'currency': ''},
      {..._wallet(), 'status': ''},
      {..._wallet(), 'unsettled_collections': '23.5'},
      {..._wallet(), 'total_collected': '50'},
    ];
    for (final broken in brokenWallets) {
      await expectLater(
        _repository([broken]).wallet(),
        throwsA(isA<DriverApiException>()),
      );
    }
  });

  test('mixed valid and malformed wallet accounts reject entire response',
      () async {
    final broken = {..._wallet()}..remove('custody_balance');
    await expectLater(
      _repository([_wallet(), broken]).wallet(),
      throwsA(isA<DriverApiException>()),
    );
  });

  test('malformed order, receipt or remittance rows never disappear silently',
      () async {
    for (final field in const ['orders', 'transactions', 'remittances']) {
      final brokenWallet = {..._wallet(), field: [null, 23, 'bad-row']};
      await expectLater(
        _repository([brokenWallet]).wallet(),
        throwsA(isA<DriverApiException>()),
        reason: 'financial $field rows cannot be silently filtered',
      );
      final nonList = {..._wallet(), field: {'id': 1}};
      await expectLater(
        _repository([nonList]).wallet(),
        throwsA(isA<DriverApiException>()),
      );
    }
  });

  test('Driver wallet retains distinct 401 and 403 server denial semantics',
      () async {
    await expectLater(
      _repository([], status: 401).wallet(),
      throwsA(isA<DriverSessionExpiredException>()),
    );
    await expectLater(
      _repository([], status: 403).wallet(),
      throwsA(isA<DriverAccessDeniedException>()),
    );
  });
}
