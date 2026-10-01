import 'dart:convert';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import 'customer_session.dart';

abstract interface class CustomerSessionStore {
  Future<CustomerSession?> read();

  Future<void> write(CustomerSession session);

  Future<void> clear();
}

abstract interface class CustomerSecureKeyValueStore {
  Future<String?> read(String key);

  Future<void> write(String key, String value);

  Future<void> delete(String key);
}

class FlutterCustomerSecureKeyValueStore
    implements CustomerSecureKeyValueStore {
  FlutterCustomerSecureKeyValueStore({
    FlutterSecureStorage? storage,
  }) : _storage = storage ?? const FlutterSecureStorage();

  final FlutterSecureStorage _storage;

  @override
  Future<String?> read(String key) => _storage.read(key: key);

  @override
  Future<void> write(String key, String value) =>
      _storage.write(key: key, value: value);

  @override
  Future<void> delete(String key) => _storage.delete(key: key);
}

class SecureCustomerSessionStore implements CustomerSessionStore {
  SecureCustomerSessionStore({
    CustomerSecureKeyValueStore? storage,
  }) : _storage = storage ?? FlutterCustomerSecureKeyValueStore();

  static const _key = 'foodex.customer.auth_session.v1';
  static const _schemaVersion = 2;
  static const _legacySchemaVersion = 1;

  final CustomerSecureKeyValueStore _storage;

  @override
  Future<CustomerSession?> read() async {
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
      final version = payload['version'];
      if (version != _schemaVersion && version != _legacySchemaVersion) {
        await clear();
        return null;
      }

      final accessToken = payload['access_token'];
      if (accessToken is! String || accessToken.trim().isEmpty) {
        await clear();
        return null;
      }

      final platformWide = payload['platform_wide'] == true;
      final channelName = payload['channel'];
      final channel = switch (channelName) {
        'b2c' => CustomerChannel.b2c,
        'b2b' => CustomerChannel.b2b,
        null => null,
        _ => null,
      };

      if ((!platformWide && channel == null) ||
          (channelName != null && channel == null)) {
        await clear();
        return null;
      }

      final retailStoreValue = payload['b2b_retail_store_id'];
      final retailStoreId = retailStoreValue is int
          ? retailStoreValue
          : int.tryParse(retailStoreValue?.toString() ?? '');

      if (retailStoreId != null && retailStoreId <= 0) {
        await clear();
        return null;
      }

      if (channel == null) {
        return CustomerSession.platformCustomer(
          accessToken: accessToken.trim(),
          b2bRetailStoreId: retailStoreId,
        );
      }

      return CustomerSession.authenticated(
        channel,
        accessToken: accessToken.trim(),
        b2bRetailStoreId: retailStoreId,
        platformWide: platformWide,
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
  Future<void> write(CustomerSession session) async {
    final token = session.accessToken?.trim();
    final channel = session.channel;
    if (!session.isAuthenticated ||
        token == null ||
        token.isEmpty ||
        (!session.platformWide && channel == null)) {
      await clear();
      return;
    }

    await _storage.write(
      _key,
      jsonEncode(<String, Object?>{
        'version': _schemaVersion,
        'channel': channel?.name,
        'access_token': token,
        'b2b_retail_store_id': session.b2bRetailStoreId,
        'platform_wide': session.platformWide,
      }),
    );
  }

  @override
  Future<void> clear() => _storage.delete(_key);
}

Future<CustomerSession> restoreCustomerSession(
  CustomerSessionStore store,
) async =>
    await store.read() ?? const CustomerSession.guest();
