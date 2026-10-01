import 'dart:convert';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import 'customer_session.dart';

abstract interface class CustomerSessionStore {
  Future<CustomerSession?> read();

  Future<void> write(CustomerSession session);

  Future<void> clear();
}

class SecureCustomerSessionStore implements CustomerSessionStore {
  SecureCustomerSessionStore({
    FlutterSecureStorage? storage,
  }) : _storage = storage ??
            const FlutterSecureStorage(
              aOptions: AndroidOptions(encryptedSharedPreferences: true),
            );

  static const _key = 'foodex.customer.auth_session.v1';
  static const _schemaVersion = 1;

  final FlutterSecureStorage _storage;

  @override
  Future<CustomerSession?> read() async {
    final raw = await _storage.read(key: _key);
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

      final channelName = payload['channel'];
      final accessToken = payload['access_token'];
      if (channelName is! String ||
          accessToken is! String ||
          accessToken.trim().isEmpty) {
        await clear();
        return null;
      }

      final channel = switch (channelName) {
        'b2c' => CustomerChannel.b2c,
        'b2b' => CustomerChannel.b2b,
        _ => null,
      };
      if (channel == null) {
        await clear();
        return null;
      }

      final retailStoreValue = payload['b2b_retail_store_id'];
      final retailStoreId = retailStoreValue is int
          ? retailStoreValue
          : int.tryParse(retailStoreValue?.toString() ?? '');

      return CustomerSession.authenticated(
        channel,
        accessToken: accessToken,
        b2bRetailStoreId: retailStoreId,
        platformWide: payload['platform_wide'] == true,
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
        channel == null ||
        token == null ||
        token.isEmpty) {
      await clear();
      return;
    }

    await _storage.write(
      key: _key,
      value: jsonEncode({
        'version': _schemaVersion,
        'channel': channel.name,
        'access_token': token,
        'b2b_retail_store_id': session.b2bRetailStoreId,
        'platform_wide': session.platformWide,
      }),
    );
  }

  @override
  Future<void> clear() => _storage.delete(key: _key);
}
