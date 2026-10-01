import 'dart:convert';

import '../auth/customer_session_store.dart';
import 'customer_commerce_context.dart';

abstract interface class CustomerCommerceContextStore {
  Future<CustomerCommerceContext?> read();

  Future<void> write(CustomerCommerceContext context);

  Future<void> clear();
}

class SecureCustomerCommerceContextStore
    implements CustomerCommerceContextStore {
  SecureCustomerCommerceContextStore({
    CustomerSecureKeyValueStore? storage,
  }) : _storage = storage ?? FlutterCustomerSecureKeyValueStore();

  static const _key = 'foodex.customer.commerce_context.v1';
  static const _schemaVersion = 1;

  final CustomerSecureKeyValueStore _storage;

  @override
  Future<CustomerCommerceContext?> read() async {
    final raw = await _storage.read(_key);
    if (raw == null || raw.trim().isEmpty) {
      return null;
    }

    try {
      final decoded = jsonDecode(raw);
      if (decoded is! Map) {
        await clear();
        return null;
      }

      final payload = Map<String, dynamic>.from(decoded);
      if (payload['version'] != _schemaVersion) {
        await clear();
        return null;
      }

      final context = CustomerCommerceContext.tryParseQuery({
        'channel': payload['channel']?.toString() ?? '',
        'store': payload['store_id']?.toString() ?? '',
        if (payload['retail_receiver_id'] != null)
          'receiver': payload['retail_receiver_id'].toString(),
      });

      if (context == null) {
        await clear();
      }

      return context;
    } on FormatException {
      await clear();
      return null;
    } on TypeError {
      await clear();
      return null;
    }
  }

  @override
  Future<void> write(CustomerCommerceContext context) => _storage.write(
        _key,
        jsonEncode({
          'version': _schemaVersion,
          'channel': context.channel.name,
          'store_id': context.storeId,
          'retail_receiver_id': context.retailReceiverId,
        }),
      );

  @override
  Future<void> clear() => _storage.delete(_key);
}

Future<CustomerCommerceContext?> restoreCustomerCommerceContext(
  CustomerCommerceContextStore store,
) =>
    store.read();
