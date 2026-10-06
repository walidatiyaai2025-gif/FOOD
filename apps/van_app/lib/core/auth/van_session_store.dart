import 'dart:convert';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import 'van_session.dart';

abstract interface class VanSessionStore {
  Future<VanSession?> read();
  Future<void> write(VanSession session);
  Future<void> clear();
}

class SecureVanSessionStore implements VanSessionStore {
  SecureVanSessionStore({FlutterSecureStorage? storage})
      : _storage = storage ?? const FlutterSecureStorage();

  static const _key = 'foodex.van.auth_session.v1';
  static const _version = 1;

  final FlutterSecureStorage _storage;

  @override
  Future<VanSession?> read() async {
    final raw = await _storage.read(key: _key);
    if (raw == null || raw.trim().isEmpty) return null;

    try {
      final decoded = jsonDecode(raw);
      if (decoded is! Map) {
        await clear();
        return null;
      }

      final payload = Map<String, dynamic>.from(decoded);
      final token = (payload['token'] ?? '').toString().trim();
      final permissions = (payload['permissions'] as List? ?? const [])
          .map((value) => value.toString())
          .where((value) => value.isNotEmpty)
          .toSet();

      if (payload['version'] != _version ||
          token.isEmpty ||
          !permissions.contains('van.login')) {
        await clear();
        return null;
      }

      return VanSession(
        token: token,
        name: (payload['name'] ?? '').toString(),
        email: (payload['email'] ?? '').toString(),
        locale: (payload['locale'] ?? 'ar').toString(),
        permissions: permissions,
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
  Future<void> write(VanSession session) async {
    if (session.token.trim().isEmpty || !session.canUseVan) {
      await clear();
      return;
    }

    await _storage.write(
      key: _key,
      value: jsonEncode(<String, Object?>{
        'version': _version,
        'token': session.token,
        'name': session.name,
        'email': session.email,
        'locale': session.locale,
        'permissions': session.permissions.toList()..sort(),
      }),
    );
  }

  @override
  Future<void> clear() => _storage.delete(key: _key);
}
