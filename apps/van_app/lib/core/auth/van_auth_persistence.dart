import 'dart:convert';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:local_auth/local_auth.dart';

class VanAuthPreferences {
  const VanAuthPreferences({
    this.rememberMe = false,
    this.biometricEnabled = false,
  }) : assert(!biometricEnabled || rememberMe);

  final bool rememberMe;
  final bool biometricEnabled;

  VanAuthPreferences copyWith({
    bool? rememberMe,
    bool? biometricEnabled,
  }) {
    final nextRememberMe = rememberMe ?? this.rememberMe;
    final nextBiometric = biometricEnabled ?? this.biometricEnabled;
    return VanAuthPreferences(
      rememberMe: nextRememberMe,
      biometricEnabled: nextRememberMe && nextBiometric,
    );
  }
}

abstract interface class VanAuthPreferenceStore {
  Future<VanAuthPreferences?> read();
  Future<void> write(VanAuthPreferences preferences);
  Future<void> clear();
}

class SecureVanAuthPreferenceStore implements VanAuthPreferenceStore {
  SecureVanAuthPreferenceStore({FlutterSecureStorage? storage})
      : _storage = storage ?? const FlutterSecureStorage();

  static const _key = 'foodex.van.auth_preferences.v1';
  static const _version = 1;

  final FlutterSecureStorage _storage;

  @override
  Future<VanAuthPreferences?> read() async {
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

      final rememberMe = payload['remember_me'] == true;
      return VanAuthPreferences(
        rememberMe: rememberMe,
        biometricEnabled:
            rememberMe && payload['biometric_enabled'] == true,
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
  Future<void> write(VanAuthPreferences preferences) => _storage.write(
        key: _key,
        value: jsonEncode(<String, Object?>{
          'version': _version,
          'remember_me': preferences.rememberMe,
          'biometric_enabled':
              preferences.rememberMe && preferences.biometricEnabled,
        }),
      );

  @override
  Future<void> clear() => _storage.delete(key: _key);
}

abstract interface class VanBiometricAuthenticator {
  Future<bool> isAvailable();
  Future<bool> authenticate({required String reason});
}

class LocalAuthVanBiometricAuthenticator implements VanBiometricAuthenticator {
  LocalAuthVanBiometricAuthenticator({LocalAuthentication? auth})
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
