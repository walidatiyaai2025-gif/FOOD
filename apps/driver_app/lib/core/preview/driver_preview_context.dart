import '../auth/driver_session.dart';

/// Host-provided context for embedding the real Driver runtime in Dashboard.
///
/// This is derived from the backend app-preview session context. The preview
/// token itself must stay with the host/repository adapter and must never be
/// reused as a normal Driver API bearer token.
class DriverPreviewContext {
  const DriverPreviewContext({
    required this.channel,
    required this.storeId,
    this.sessionId,
    this.auditCorrelationId,
    this.targetUserId,
    this.driverId,
    this.targetName = 'Preview Driver',
    this.targetLocale = 'ar',
    this.expiresAt,
    this.configurationRevision,
    this.runtimeVersion,
    this.nativeNavigationEnabled = false,
  }) : assert(storeId > 0, 'Driver preview requires an explicit storeId.');

  factory DriverPreviewContext.fromResolvedSession(
    Map<String, dynamic> data, {
    String? configurationRevision,
    String? runtimeVersion,
    bool nativeNavigationEnabled = false,
  }) {
    if (data['target_type'] != 'driver' || data['read_only'] != true) {
      throw const FormatException(
        'Driver preview requires a read-only driver preview session.',
      );
    }

    final channel = switch (data['channel']) {
      'b2c' => DriverChannel.b2c,
      _ => throw const FormatException(
        'Driver preview is available only for Retail (B2C).',
      ),
    };
    final storeId = data['store_id'];
    final target = data['target'];
    if (storeId is! int || storeId <= 0 || target is! Map) {
      throw const FormatException('Incomplete Driver preview session context.');
    }

    return DriverPreviewContext(
      channel: channel,
      storeId: storeId,
      sessionId: data['session_id']?.toString(),
      auditCorrelationId: data['audit_correlation_id']?.toString(),
      targetUserId: target['user_id'] is int ? target['user_id'] as int : null,
      driverId: target['driver_id'] is int ? target['driver_id'] as int : null,
      targetName: target['name']?.toString() ?? 'Preview Driver',
      targetLocale: target['locale']?.toString() ?? 'ar',
      expiresAt: data['expires_at']?.toString(),
      configurationRevision: configurationRevision,
      runtimeVersion: runtimeVersion,
      nativeNavigationEnabled: nativeNavigationEnabled,
    );
  }

  final DriverChannel channel;
  final int storeId;
  final String? sessionId;
  final String? auditCorrelationId;
  final int? targetUserId;
  final int? driverId;
  final String targetName;
  final String targetLocale;
  final String? expiresAt;
  final String? configurationRevision;
  final String? runtimeVersion;

  /// Native navigation is disabled by default in Dashboard-safe preview.
  /// A host may enable it only when it provides an explicit preview adapter.
  final bool nativeNavigationEnabled;

  /// Production mutations are never allowed by the safe Driver preview runtime.
  bool get mutationsAllowed => false;

  /// A non-credential session identity used only by the shared Flutter shell.
  ///
  /// The empty token is intentional. Preview data access must come from
  /// host-injected repositories backed by the dedicated preview contract.
  DriverSession get runtimeIdentity => DriverSession(
    token: '',
    name: targetName,
    email: '',
    locale: targetLocale,
    channel: channel,
    storeId: storeId,
  );

  bool matchesSession(DriverSession session) =>
      session.channel == channel && session.storeId == storeId;

  bool allowsAssignment({
    required DriverChannel assignmentChannel,
    required int assignmentStoreId,
  }) {
    return assignmentChannel == channel && assignmentStoreId == storeId;
  }
}
