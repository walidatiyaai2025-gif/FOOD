import '../../core/api/http_van_api.dart';
import '../../core/auth/van_session.dart';
import 'van_wallet_contract.dart';

class HttpVanWalletRepository implements VanWalletRepository {
  HttpVanWalletRepository(this.api);

  final VanApiClient api;

  @override
  Future<List<VanWalletAccount>> wallet() async {
    final decoded = _map(await api.getJson('van/wallet'));
    final rows = _list(decoded['data']);
    return rows.map((row) => _wallet(_map(row))).toList(growable: false);
  }

  @override
  Future<List<VanCustomerScope>> customers() async {
    final decoded = _map(await api.getJson('van/customers'));
    final rows = _list(decoded['data']);
    return rows.map((row) {
      final data = _map(row);
      final storeId = _int(data['store_id']);
      if (storeId == null) {
        throw const VanApiException('Van customer store scope is unavailable.');
      }
      return VanCustomerScope(
        type: _string(data['type']),
        id: _requiredInt(data['id']),
        name: _string(data['name']),
        storeId: storeId,
      );
    }).toList(growable: false);
  }

  @override
  Future<VanCollectionContext> collectionContext(
    VanCustomerScope customer,
  ) async {
    final decoded = _map(
      await api.getJson(
        'van/customers/${customer.type}/${customer.id}/collection-context'
        '?store_id=${customer.storeId}',
      ),
    );
    final data = _map(decoded['data']);
    final invoices = _list(data['invoices'])
        .map((row) {
          final invoice = _map(row);
          return VanInvoiceBalance(
            id: _requiredInt(invoice['id']),
            number: _string(invoice['number']),
            currency: _string(invoice['currency']),
            total: _double(invoice['total']),
            outstandingAmount: _double(invoice['outstanding_amount']),
          );
        })
        .toList(growable: false);

    return VanCollectionContext(
      storeId: _requiredInt(data['store_id']),
      invoices: invoices,
    );
  }

  @override
  Future<VanCollectionResult> collect({
    required VanCustomerScope customer,
    required int invoiceId,
    required double amount,
    required String idempotencyKey,
  }) async {
    final decoded = _map(
      await api.postJson(
        'van/customers/${customer.type}/${customer.id}/collect',
        headers: {'Idempotency-Key': idempotencyKey},
        body: {
          'store_id': customer.storeId,
          'invoice_id': invoiceId,
          'amount': amount,
        },
      ),
    );
    final data = _map(decoded['data']);

    return VanCollectionResult(
      receipt: _receipt(_map(data['receipt'])),
      remainingOutstanding: _double(data['remaining_outstanding']),
      wallet: _wallet(_map(data['wallet'])),
    );
  }

  @override
  Future<VanWalletAccount> remit({
    required int collectionAccountId,
    required double amount,
    required String method,
    required String idempotencyKey,
    String? reference,
    String? note,
  }) async {
    final decoded = _map(
      await api.postJson(
        'van/remittances',
        headers: {'Idempotency-Key': idempotencyKey},
        body: {
          'collection_account_id': collectionAccountId,
          'amount': amount,
          'method': method,
          if (reference != null && reference.trim().isNotEmpty)
            'reference': reference.trim(),
          if (note != null && note.trim().isNotEmpty) 'note': note.trim(),
        },
      ),
    );
    final data = _map(decoded['data']);
    return _wallet(_map(data['wallet']));
  }

  VanWalletAccount _wallet(Map<String, dynamic> data) {
    return VanWalletAccount(
      id: _requiredInt(data['id']),
      storeId: _int(data['store_id']),
      currency: _string(data['currency']),
      status: _string(data['status']),
      custodyBalance: _double(data['custody_balance']),
      availableToRemit: _double(data['available_to_remit']),
      receipts: _list(data['transactions'])
          .map((row) => _receipt(_map(row)))
          .toList(growable: false),
      remittances: _list(data['remittances']).map((row) {
        final remittance = _map(row);
        return VanRemittance(
          id: _requiredInt(remittance['id']),
          amount: _double(remittance['amount']),
          currency: _string(remittance['currency']),
          method: _string(remittance['method']),
          status: _string(remittance['status']),
          createdAt: _string(remittance['created_at']),
        );
      }).toList(growable: false),
    );
  }

  VanReceipt _receipt(Map<String, dynamic> data) {
    return VanReceipt(
      id: _requiredInt(data['id']),
      amount: _double(data['amount']),
      currency: _string(data['currency']),
      status: _string(data['status']),
      createdAt: _string(data['created_at']),
    );
  }

  Map<String, dynamic> _map(Object? value) {
    if (value is Map<String, dynamic>) return value;
    if (value is Map) return Map<String, dynamic>.from(value);
    throw const VanApiException('Invalid Van finance response.');
  }

  List<Object?> _list(Object? value) {
    if (value is List) return List<Object?>.from(value);
    throw const VanApiException('Invalid Van finance list response.');
  }

  int _requiredInt(Object? value) {
    final parsed = _int(value);
    if (parsed == null) {
      throw const VanApiException('Invalid Van finance identifier.');
    }
    return parsed;
  }

  int? _int(Object? value) {
    if (value is int) return value;
    return int.tryParse(value?.toString() ?? '');
  }

  double _double(Object? value) {
    if (value is num) return value.toDouble();
    final parsed = double.tryParse(value?.toString() ?? '');
    if (parsed == null) {
      throw const VanApiException('Invalid Van finance amount.');
    }
    return parsed;
  }

  String _string(Object? value) => value?.toString() ?? '';
}
