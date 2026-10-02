import 'dart:convert';

import 'package:local_auth/local_auth.dart';

import 'customer_session.dart';
import 'customer_session_store.dart';

class CustomerAuthPreferences {
  const CustomerAuthPreferences({
    this.rememberMe = false,
    this.biometricEnabled = false,
  }) : assert(!biometricEnabled || rememberMe);

  final bool rememberMe;
  final bool biometricEnabled;

  CustomerAuthPreferences copyWith({
    bool? rememberMe,
    bool? biometricEnabled,
  }) {
    final nextRemember = rememberMe ?? this.rememberMe;
    final nextBiometric = biometricEnabled ?? this.biometricEnabled;
    return CustomerAuthPreferences(
      rememberMe: nextRemember,
      biometricEnabled: nextRemember && nextBiometric,
    );
  }
}

abstract interface class CustomerAuthPreferenceStore {
  Future<CustomerAuthPreferences?> read();

  Future<void> write(CustomerAuthPreferences preferences);

  Future<void> clear();
}

class SecureCustomerAuthPreferenceStore implements CustomerAuthPreferenceStore {
  SecureCustomerAuthPreferenceStore({
    CustomerSecureKeyValueStore? storage,
  }) : _storage = storage ?? FlutterCustomerSecureKeyValueStore();

  static const _key = 'foodex.customer.auth_preferences.v1';
  static const _version = 1;

  final CustomerSecureKeyValueStore _storage;

  @override
  Future<CustomerAuthPreferences?> read() async {
    final raw = await _storage.read(_key);
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
      final biometricEnabled =
          rememberMe && payload['biometric_enabled'] == true;
      return CustomerAuthPreferences(
        rememberMe: rememberMe,
        biometricEnabled: biometricEnabled,
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
  Future<void> write(CustomerAuthPreferences preferences) => _storage.write(
        _key,
        jsonEncode(<String, Object?>{
          'version': _version,
          'remember_me': preferences.rememberMe,
          'biometric_enabled':
              preferences.rememberMe && preferences.biometricEnabled,
        }),
      );

  @override
  Future<void> clear() => _storage.delete(_key);
}

class CustomerAuthBootstrap {
  const CustomerAuthBootstrap({
    required this.session,
    required this.preferences,
  });

  final CustomerSession session;
  final CustomerAuthPreferences preferences;
}

/// Restores only sessions the user explicitly chose to remember.
///
/// A legacy persisted session from versions before the Remember-me control is
/// migrated as remembered so an upgrade does not unexpectedly sign the user
/// out. When biometric unlock is enabled, the token remains in secure storage
/// but the runtime starts as guest until the device unlock succeeds.
Future<CustomerAuthBootstrap> restoreCustomerAuthBootstrap({
  required CustomerSessionStore sessionStore,
  required CustomerAuthPreferenceStore preferenceStore,
}) async {
  final preferences = await preferenceStore.read();
  final storedSession = await sessionStore.read();

  if (preferences == null) {
    if (storedSession == null) {
      return const CustomerAuthBootstrap(
        session: CustomerSession.guest(),
        preferences: CustomerAuthPreferences(),
      );
    }

    const migrated = CustomerAuthPreferences(rememberMe: true);
    await preferenceStore.write(migrated);
    return CustomerAuthBootstrap(
      session: storedSession,
      preferences: migrated,
    );
  }

  if (!preferences.rememberMe) {
    if (storedSession != null) {
      await sessionStore.clear();
    }
    return CustomerAuthBootstrap(
      session: const CustomerSession.guest(),
      preferences: preferences,
    );
  }

  if (storedSession == null) {
    final corrected = preferences.copyWith(biometricEnabled: false);
    if (corrected.biometricEnabled != preferences.biometricEnabled) {
      await preferenceStore.write(corrected);
    }
    return CustomerAuthBootstrap(
      session: const CustomerSession.guest(),
      preferences: corrected,
    );
  }

  if (preferences.biometricEnabled) {
    return CustomerAuthBootstrap(
      session: const CustomerSession.guest(),
      preferences: preferences,
    );
  }

  return CustomerAuthBootstrap(
    session: storedSession,
    preferences: preferences,
  );
}

abstract interface class CustomerBiometricAuthenticator {
  Future<bool> isAvailable();

  Future<bool> authenticate({required String reason});
}

class LocalAuthCustomerBiometricAuthenticator
    implements CustomerBiometricAuthenticator {
  LocalAuthCustomerBiometricAuthenticator({LocalAuthentication? auth})
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
