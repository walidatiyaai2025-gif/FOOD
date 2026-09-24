enum AppUpdateStatus { current, optional, forced, unsupported }

final class AppVersionPolicy {
  const AppVersionPolicy({
    required this.latestVersion,
    required this.minimumSupportedVersion,
    required this.forceUpdate,
    required this.updateRequired,
    required this.status,
    required this.storeUrl,
    this.releaseNotes,
  });

  factory AppVersionPolicy.fromJson(Map<String, dynamic> json) {
    final rawStatus = json['status'] as String?;
    final status = AppUpdateStatus.values.where((value) => value.name == rawStatus).firstOrNull;
    if (status == null) {
      throw const FormatException('Unknown app version policy status.');
    }

    final storeUrl = Uri.tryParse(json['store_url'] as String? ?? '');
    if (storeUrl == null || !storeUrl.hasScheme || !storeUrl.hasAuthority) {
      throw const FormatException('Official store URL is required.');
    }

    return AppVersionPolicy(
      latestVersion: json['latest_version'] as String,
      minimumSupportedVersion: json['minimum_supported_version'] as String,
      forceUpdate: json['force_update'] as bool,
      updateRequired: json['update_required'] as bool,
      status: status,
      storeUrl: storeUrl,
      releaseNotes: json['release_notes'] as String?,
    );
  }

  final String latestVersion;
  final String minimumSupportedVersion;
  final bool forceUpdate;
  final bool updateRequired;
  final AppUpdateStatus status;
  final Uri storeUrl;
  final String? releaseNotes;

  bool get blocksApp => status == AppUpdateStatus.forced || status == AppUpdateStatus.unsupported;
}
