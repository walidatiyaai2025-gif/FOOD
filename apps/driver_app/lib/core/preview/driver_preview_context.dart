import '../auth/driver_session.dart';

/// Host-provided context for embedding the real Driver runtime in Dashboard.
///
/// This context narrows what the already-authorized repository data may render.
/// It is not an authorization boundary: backend preview-session and tenant rules
/// remain authoritative.
class DriverPreviewContext {
  const DriverPreviewContext({
    required this.channel,
    this.storeId,
    this.configurationRevision,
    this.runtimeVersion,
    this.nativeNavigationEnabled = false,
  })  : assert(
          channel != DriverChannel.b2c || storeId != null,
          'Retail Driver preview requires an explicit storeId.',
        ),
        assert(
          channel != DriverChannel.b2b || storeId == null,
          'Wholesale Driver preview must not carry a retail storeId.',
        );

  final DriverChannel channel;
  final int? storeId;
  final String? configurationRevision;
  final String? runtimeVersion;

  /// Native navigation is disabled by default in Dashboard-safe preview.
  /// A host may enable it only when it provides an explicit preview adapter.
  final bool nativeNavigationEnabled;

  /// Production mutations are never allowed by the safe Driver preview runtime.
  bool get mutationsAllowed => false;

  bool matchesSession(DriverSession session) => session.channel == channel;

  bool allowsAssignment({
    required DriverChannel assignmentChannel,
    required int assignmentStoreId,
  }) {
    if (assignmentChannel != channel) return false;
    if (channel == DriverChannel.b2c) {
      return assignmentStoreId == storeId;
    }
    return true;
  }
}
