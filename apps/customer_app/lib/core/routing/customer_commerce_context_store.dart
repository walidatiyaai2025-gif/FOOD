import 'dart:convert';

import '../auth/customer_session_store.dart';
import 'customer_commerce_context.dart';

abstract interface class CustomerCommerceContextStore {
  Future<CustomerCommerceContext?> read();

  Future<void> write(CustomerCommerceContext context);

  Future<void> clear();
}

abstract interface class CustomerGuestCartTokenStore {
  Future<String?> readToken(int storeId);

  Future<Map<int, String>> readAll();

  Future<void> writeToken(int storeId, String token);

  Future<void> removeToken(int storeId);

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

      final channel = switch (payload['channel']) {
        'retail' => CustomerCommerceChannel.retail,
        'wholesale' => CustomerCommerceChannel.wholesale,
        _ => null,
      };
      final storeId = _positiveInt(payload['store_id']);
      final receiverRaw = payload['retail_receiver_id'];
      final receiverId =
          receiverRaw == null ? null : _positiveInt(receiverRaw);

      if (channel == null ||
          storeId == null ||
          (receiverRaw != null && receiverId == null)) {
        await clear();
        return null;
      }

      return CustomerCommerceContext(
        channel: channel,
        storeId: storeId,
        retailReceiverId: receiverId,
      );
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
        jsonEncode(<String, Object?>{
          'version': _schemaVersion,
          'channel': context.channel.name,
          'store_id': context.storeId,
          'retail_receiver_id': context.retailReceiverId,
        }),
      );

  @override
  Future<void> clear() => _storage.delete(_key);
}

class SecureCustomerGuestCartTokenStore
    implements CustomerGuestCartTokenStore {
  SecureCustomerGuestCartTokenStore({
    CustomerSecureKeyValueStore? storage,
  }) : _storage = storage ?? FlutterCustomerSecureKeyValueStore();

  static const _key = 'foodex.customer.guest_cart_tokens.v1';
  static const _schemaVersion = 1;

  final CustomerSecureKeyValueStore _storage;

  @override
  Future<String?> readToken(int storeId) async {
    _requireStoreId(storeId);
    return (await readAll())[storeId];
  }

  @override
  Future<Map<int, String>> readAll() async {
    final raw = await _storage.read(_key);
    if (raw == null || raw.trim().isEmpty) {
      return <int, String>{};
    }

    try {
      final decoded = jsonDecode(raw);
      if (decoded is! Map) {
        await clear();
        return <int, String>{};
      }

      final payload = Map<String, dynamic>.from(decoded);
      final encodedTokens = payload['tokens'];
      if (payload['version'] != _schemaVersion || encodedTokens is! Map) {
        await clear();
        return <int, String>{};
      }

      final tokens = <int, String>{};
      for (final entry in encodedTokens.entries) {
        final storeId = _positiveInt(entry.key);
        final token = entry.value is String ? (entry.value as String).trim() : '';
        if (storeId == null || token.isEmpty) {
          await clear();
          return <int, String>{};
        }
        tokens[storeId] = token;
      }
      return tokens;
    } on FormatException {
      await clear();
      return <int, String>{};
    } on TypeError {
      await clear();
      return <int, String>{};
    }
  }

  @override
  Future<void> writeToken(int storeId, String token) async {
    _requireStoreId(storeId);
    final normalized = token.trim();
    if (normalized.isEmpty) {
      throw ArgumentError.value(token, 'token', 'must not be empty');
    }

    final tokens = await readAll();
    tokens[storeId] = normalized;
    await _writeAll(tokens);
  }

  @override
  Future<void> removeToken(int storeId) async {
    _requireStoreId(storeId);
    final tokens = await readAll();
    if (tokens.remove(storeId) == null) {
      return;
    }
    if (tokens.isEmpty) {
      await clear();
      return;
    }
    await _writeAll(tokens);
  }

  Future<void> _writeAll(Map<int, String> tokens) => _storage.write(
        _key,
        jsonEncode(<String, Object?>{
          'version': _schemaVersion,
          'tokens': <String, String>{
            for (final entry in tokens.entries)
              entry.key.toString(): entry.value,
          },
        }),
      );

  @override
  Future<void> clear() => _storage.delete(_key);

  static void _requireStoreId(int storeId) {
    if (storeId <= 0) {
      throw ArgumentError.value(storeId, 'storeId', 'must be positive');
    }
  }
}

int? _positiveInt(Object? raw) {
  final value = raw is int ? raw : int.tryParse(raw?.toString() ?? '');
  return value != null && value > 0 ? value : null;
}
