import 'dart:convert';

import 'customer_session_store.dart';

class CustomerGuestCartSnapshot {
  CustomerGuestCartSnapshot({
    Map<int, String> tokens = const <int, String>{},
    this.activeStoreId,
  }) : tokens = Map<int, String>.unmodifiable(tokens);

  final Map<int, String> tokens;
  final int? activeStoreId;

  String? tokenForStore(int storeId) => tokens[storeId];
}

abstract interface class CustomerGuestCartSessionStore {
  Future<CustomerGuestCartSnapshot?> read();

  Future<void> write(CustomerGuestCartSnapshot snapshot);

  Future<void> clear();
}

class SecureCustomerGuestCartSessionStore
    implements CustomerGuestCartSessionStore {
  SecureCustomerGuestCartSessionStore({
    CustomerSecureKeyValueStore? storage,
  }) : _storage = storage ?? FlutterCustomerSecureKeyValueStore();

  static const _key = 'foodex.customer.guest_cart_session.v1';
  static const _schemaVersion = 1;

  final CustomerSecureKeyValueStore _storage;

  @override
  Future<CustomerGuestCartSnapshot?> read() async {
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
      if (payload['version'] != _schemaVersion || payload['tokens'] is! Map) {
        await clear();
        return null;
      }

      final tokens = <int, String>{};
      for (final entry in (payload['tokens'] as Map).entries) {
        final storeId = int.tryParse(entry.key.toString());
        final token = entry.value?.toString().trim() ?? '';
        if (storeId == null || storeId <= 0 || token.isEmpty) {
          await clear();
          return null;
        }
        tokens[storeId] = token;
      }

      final activeValue = payload['active_store_id'];
      final activeStoreId =
          activeValue == null ? null : int.tryParse(activeValue.toString());

      if (activeStoreId != null &&
          (activeStoreId <= 0 || !tokens.containsKey(activeStoreId))) {
        await clear();
        return null;
      }

      return CustomerGuestCartSnapshot(
        tokens: tokens,
        activeStoreId: activeStoreId,
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
  Future<void> write(CustomerGuestCartSnapshot snapshot) async {
    if (snapshot.tokens.isEmpty) {
      await clear();
      return;
    }

    await _storage.write(
      _key,
      jsonEncode({
        'version': _schemaVersion,
        'active_store_id': snapshot.activeStoreId,
        'tokens': {
          for (final entry in snapshot.tokens.entries)
            entry.key.toString(): entry.value,
        },
      }),
    );
  }

  @override
  Future<void> clear() => _storage.delete(_key);
}

class CustomerGuestCartSession {
  CustomerGuestCartSession({
    required CustomerGuestCartSessionStore store,
  }) : _store = store;

  final CustomerGuestCartSessionStore _store;
  final Map<int, String> _tokens = <int, String>{};
  int? _activeStoreId;
  bool _restored = false;

  int? get activeStoreId => _activeStoreId;
  bool get isRestored => _restored;

  String? tokenForStore(int storeId) => _tokens[storeId];

  String? get activeToken {
    final storeId = _activeStoreId;
    return storeId == null ? null : _tokens[storeId];
  }

  Future<void> restore() async {
    final snapshot = await _store.read();
    _tokens
      ..clear()
      ..addAll(snapshot?.tokens ?? const <int, String>{});
    _activeStoreId = snapshot?.activeStoreId;
    _restored = true;
  }

  Future<void> activateStore(int storeId) async {
    _requirePositiveStoreId(storeId);
    _activeStoreId = storeId;
    await _persist();
  }

  Future<void> captureToken(int storeId, String token) async {
    _requirePositiveStoreId(storeId);
    final normalized = token.trim();
    if (normalized.isEmpty) {
      throw ArgumentError.value(token, 'token', 'must not be empty');
    }

    _tokens[storeId] = normalized;
    _activeStoreId = storeId;
    await _persist();
  }

  Future<void> clearStore(int storeId) async {
    _requirePositiveStoreId(storeId);
    _tokens.remove(storeId);
    if (_activeStoreId == storeId) {
      _activeStoreId = null;
    }
    await _persist();
  }

  Future<void> clear() async {
    _tokens.clear();
    _activeStoreId = null;
    _restored = true;
    await _store.clear();
  }

  CustomerGuestCartSnapshot snapshot() => CustomerGuestCartSnapshot(
        tokens: _tokens,
        activeStoreId: _activeStoreId,
      );

  Future<void> _persist() => _store.write(snapshot());

  static void _requirePositiveStoreId(int storeId) {
    if (storeId <= 0) {
      throw ArgumentError.value(storeId, 'storeId', 'must be positive');
    }
  }
}
