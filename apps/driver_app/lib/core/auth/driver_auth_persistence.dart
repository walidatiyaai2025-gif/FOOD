import 'dart:convert';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:local_auth/local_auth.dart';

import 'driver_session.dart';

class DriverStoredSession {
  const DriverStoredSession({
    required this.session,
    required this.biometricEnabled,
  });

  final DriverSession session;
  final bool biometricEnabled;
}

abstract interface class DriverSessionStore {
  Future<DriverStoredSession?> read();
  Future<void> write(DriverSession session, {required bool biometricEnabled});
  Future<void> clear();
}

class SecureDriverSessionStore implements DriverSessionStore {
  SecureDriverSessionStore({FlutterSecureStorage? storage})
    : _storage = storage ?? const FlutterSecureStorage();

  static const _key = 'foodex.driver.auth_session.v1';
  static const _version = 1;

  final FlutterSecureStorage _storage;

  @override
  Future<DriverStoredSession?> read() async {
    final raw = await _storage.read(key: _key);
    if (raw == null || raw.trim().isEmpty) return null;

    try {
      final decoded = jsonDecode(raw);
      if (decoded is! Map) {
        await clear();
        return null;
      }
      final payload = Map<String, dynamic>.from(decoded);
      if (payload['version'] != _version) {
        await clear();
        return null;
      }

      final token = (payload['token'] ?? '').toString().trim();
      final name = (payload['name'] ?? '').toString();
      final email = (payload['email'] ?? '').toString();
      final locale = (payload['locale'] ?? 'ar').toString();
      final channelName = (payload['channel'] ?? '').toString();
      final storeId = (payload['store_id'] as num?)?.toInt();
      final channel = switch (channelName) {
        'b2c' => DriverChannel.b2c,
        _ => null,
      };

      if (token.isEmpty || channel == null || storeId == null || storeId <= 0) {
        await clear();
        return null;
      }

      return DriverStoredSession(
        session: DriverSession(
          token: token,
          name: name,
          email: email,
          locale: locale,
          channel: channel,
          storeId: storeId,
        ),
        biometricEnabled: payload['biometric_enabled'] == true,
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
  Future<void> write(
    DriverSession session, {
    required bool biometricEnabled,
  }) async {
    final token = session.token.trim();
    final storeId = session.storeId;
    if (token.isEmpty || storeId == null || storeId <= 0) {
      await clear();
      return;
    }

    await _storage.write(
      key: _key,
      value: jsonEncode(<String, Object?>{
        'version': _version,
        'token': token,
        'name': session.name,
        'email': session.email,
        'locale': session.locale,
        'channel': session.channel.name,
        'store_id': storeId,
        'biometric_enabled': biometricEnabled,
      }),
    );
  }

  @override
  Future<void> clear() => _storage.delete(key: _key);
}

abstract interface class DriverBiometricAuthenticator {
  Future<bool> isAvailable();
  Future<bool> authenticate({required String reason});
}

class LocalAuthDriverBiometricAuthenticator
    implements DriverBiometricAuthenticator {
  LocalAuthDriverBiometricAuthenticator({LocalAuthentication? auth})
    : _auth = auth ?? LocalAuthentication();

  final LocalAuthentication _auth;

  @override
  Future<bool> isAvailable() async {
    try {
      return await _auth.isDeviceSupported() && await _auth.canCheckBiometrics;
    } catch (_) {
      return false;
    }
  }

  @override
  Future<bool> authenticate({required String reason}) async {
    try {
      return await _auth.authenticate(
        localizedReason: reason,
        options: const AuthenticationOptions(
          biometricOnly: true,
          stickyAuth: true,
          useErrorDialogs: true,
        ),
      );
    } catch (_) {
      return false;
    }
  }
}
