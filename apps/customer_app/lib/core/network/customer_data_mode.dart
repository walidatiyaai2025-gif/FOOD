import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';

enum CustomerDataMode { normal, lite, offline }

enum CustomerDataPreference { automatic, lite }

class CustomerDataModeController extends ChangeNotifier {
  CustomerDataModeController._();

  static final CustomerDataModeController instance =
      CustomerDataModeController._();

  static const _preferenceKey = 'foodex.customer.data_preference.v2';

  SharedPreferences? _preferences;
  CustomerDataPreference _preference = CustomerDataPreference.automatic;
  bool _autoLite = false;
  bool _networkOffline = false;
  DateTime? _offlineSince;
  bool _backgrounded = false;
  int _consecutiveFailures = 0;
  int _slowSamples = 0;
  int _healthySamples = 0;

  CustomerDataPreference get preference => _preference;
  bool get isAutoManaged => _preference == CustomerDataPreference.automatic;
  bool get autoLite => _autoLite;
  bool get networkOffline => _networkOffline;
  bool get backgrounded => _backgrounded;
  bool get isForeground => !_backgrounded;

  CustomerDataMode get effectiveMode {
    if (_networkOffline && !recoveryProbeDue) return CustomerDataMode.offline;
    if (_preference == CustomerDataPreference.lite ||
        _autoLite ||
        _networkOffline) {
      return CustomerDataMode.lite;
    }
    return CustomerDataMode.normal;
  }

  bool get recoveryProbeDue =>
      _networkOffline &&
      _offlineSince != null &&
      DateTime.now().toUtc().difference(_offlineSince!) >=
          const Duration(seconds: 30);

  Future<void> initialize() async {
    _preferences ??= await SharedPreferences.getInstance();
    final raw = _preferences?.getString(_preferenceKey);
    _preference = switch (raw) {
      'lite' => CustomerDataPreference.lite,
      _ => CustomerDataPreference.automatic,
    };
  }

  Future<void> setPreference(CustomerDataPreference preference) async {
    if (_preference == preference) return;
    _preference = preference;
    _preferences ??= await SharedPreferences.getInstance();
    await _preferences?.setString(_preferenceKey, preference.name);
    notifyListeners();
  }

  void observeSuccess(Duration elapsed) {
    final wasOffline = _networkOffline;
    _networkOffline = false;
    _offlineSince = null;
    _consecutiveFailures = 0;

    if (wasOffline) {
      // Recover conservatively: the first successful request proves reachability,
      // but the connection may still be poor.
      _autoLite = true;
      _slowSamples = 0;
      _healthySamples = 0;
      notifyListeners();
      return;
    }

    if (elapsed >= const Duration(milliseconds: 2500)) {
      _slowSamples += 1;
      _healthySamples = 0;
      if (_slowSamples >= 2 && !_autoLite) {
        _autoLite = true;
        notifyListeners();
      }
      return;
    }

    if (elapsed <= const Duration(milliseconds: 1400)) {
      _healthySamples += 1;
      _slowSamples = 0;
      if (_healthySamples >= 4 && _autoLite) {
        _autoLite = false;
        notifyListeners();
      }
    }
  }

  void observeFailure() {
    _consecutiveFailures += 1;
    _healthySamples = 0;
    _slowSamples = 0;

    var changed = false;
    if (!_autoLite) {
      _autoLite = true;
      changed = true;
    }
    if (_consecutiveFailures >= 2 && !_networkOffline) {
      _networkOffline = true;
      _offlineSince = DateTime.now().toUtc();
      changed = true;
    }
    if (changed) notifyListeners();
  }

  void setBackgrounded(bool value) {
    if (_backgrounded == value) return;
    _backgrounded = value;
    notifyListeners();
  }

  bool get allowsRefresh =>
      !_backgrounded && effectiveMode != CustomerDataMode.offline;
  bool get allowsBackgroundRefresh => allowsRefresh;

  Duration requestTimeout() => switch (effectiveMode) {
        CustomerDataMode.normal => const Duration(seconds: 12),
        CustomerDataMode.lite => const Duration(seconds: 8),
        CustomerDataMode.offline => Duration.zero,
      };

  Duration? orderPollingInterval() {
    if (!allowsRefresh) return null;
    return switch (effectiveMode) {
      CustomerDataMode.normal => const Duration(seconds: 20),
      CustomerDataMode.lite => const Duration(seconds: 90),
      CustomerDataMode.offline => null,
    };
  }

  @visibleForTesting
  void resetForTesting() {
    _preference = CustomerDataPreference.automatic;
    _autoLite = false;
    _networkOffline = false;
    _offlineSince = null;
    _backgrounded = false;
    _consecutiveFailures = 0;
    _slowSamples = 0;
    _healthySamples = 0;
    notifyListeners();
  }
}
