import '../auth/customer_session.dart';
import 'customer_preview_context.dart';

class CustomerPreviewBootstrapException implements Exception {
  const CustomerPreviewBootstrapException(this.code);

  final String code;

  @override
  String toString() => code;
}

class CustomerPreviewBootstrap {
  const CustomerPreviewBootstrap({
    required this.contractVersion,
    required this.parentOrigin,
    required this.context,
    required this.credential,
    required this.configuration,
    required this.locale,
    required this.deviceProfile,
    required this.deviceWidth,
    required this.safeMode,
  });

  static const messageType = 'foodex.preview.bootstrap';
  static const readyType = 'foodex.preview.ready';
  static const statusType = 'foodex.preview.status';

  static Map<String, Object?> readyEnvelope(String contractVersion) => {
        'type': readyType,
        'version': contractVersion,
      };

  factory CustomerPreviewBootstrap.parse({
    required Object? message,
    required String eventOrigin,
    required String allowedParentOrigin,
    required String expectedContractVersion,
  }) {
    final normalizedAllowed = _normalizedOrigin(allowedParentOrigin);
    final normalizedEvent = _normalizedOrigin(eventOrigin);
    if (normalizedAllowed == null || normalizedEvent != normalizedAllowed) {
      throw const CustomerPreviewBootstrapException('origin_rejected');
    }

    if (message is! Map) {
      throw const CustomerPreviewBootstrapException('invalid_message');
    }

    final data = Map<String, dynamic>.from(message);
    if (data['type'] != messageType) {
      throw const CustomerPreviewBootstrapException('invalid_message_type');
    }

    final version = data['version']?.toString() ?? '';
    if (version.isEmpty || version != expectedContractVersion) {
      throw const CustomerPreviewBootstrapException('contract_version_mismatch');
    }

    final payloadValue = data['payload'];
    if (payloadValue is! Map) {
      throw const CustomerPreviewBootstrapException('invalid_payload');
    }
    final payload = Map<String, dynamic>.from(payloadValue);

    final safeMode = payload['safe_mode']?.toString() ?? '';
    if (safeMode != 'read_only') {
      throw const CustomerPreviewBootstrapException('unsafe_preview_mode');
    }

    final contextValue = payload['context'];
    if (contextValue is! Map) {
      throw const CustomerPreviewBootstrapException('invalid_context');
    }
    final contextData = Map<String, dynamic>.from(contextValue);

    final targetType = contextData['target_type']?.toString();
    if (targetType != 'customer') {
      throw const CustomerPreviewBootstrapException('invalid_target_type');
    }

    final channel = switch (contextData['channel']?.toString()) {
      'b2b' => CustomerChannel.b2b,
      'b2c' => CustomerChannel.b2c,
      _ => throw const CustomerPreviewBootstrapException('invalid_channel'),
    };

    final storeValue = contextData['store_id'];
    final storeId = storeValue is num ? storeValue.toInt() : 0;
    if (storeId <= 0) {
      throw const CustomerPreviewBootstrapException('invalid_store');
    }

    final target = contextData['target'];
    final authenticated = target is Map;
    final credentialValue = payload['credential'];
    final credential = credentialValue is String && credentialValue.trim().isNotEmpty
        ? credentialValue.trim()
        : null;

    if (authenticated && credential == null) {
      throw const CustomerPreviewBootstrapException('credential_required');
    }
    if (!authenticated && credential != null) {
      throw const CustomerPreviewBootstrapException('guest_credential_forbidden');
    }

    final context = authenticated
        ? CustomerPreviewContext.fromResolvedSession(
            contextData,
            configurationRevision: payload['configuration_revision']?.toString(),
            runtimeVersion: version,
          )
        : CustomerPreviewContext.guest(
            channel: channel,
            storeId: storeId,
            targetLocale: _locale(payload['locale']),
            configurationRevision: payload['configuration_revision']?.toString(),
            runtimeVersion: version,
          );

    final deviceValue = payload['device'];
    final device = deviceValue is Map
        ? Map<String, dynamic>.from(deviceValue)
        : const <String, dynamic>{};
    final widthValue = device['width'];
    final width = widthValue is num ? widthValue.toInt() : 390;
    if (width < 320 || width > 1024) {
      throw const CustomerPreviewBootstrapException('invalid_device_width');
    }

    final configuration = payload['configuration']?.toString() ?? 'published';
    if (configuration != 'published' && configuration != 'draft') {
      throw const CustomerPreviewBootstrapException('invalid_configuration');
    }

    return CustomerPreviewBootstrap(
      contractVersion: version,
      parentOrigin: normalizedEvent,
      context: context,
      credential: credential,
      configuration: configuration,
      locale: _locale(payload['locale']),
      deviceProfile: device['profile']?.toString() ?? 'phone_standard',
      deviceWidth: width,
      safeMode: safeMode,
    );
  }

  final String contractVersion;
  final String parentOrigin;
  final CustomerPreviewContext context;
  final String? credential;
  final String configuration;
  final String locale;
  final String deviceProfile;
  final int deviceWidth;
  final String safeMode;

  bool get authenticated => context.authenticated;

  Map<String, Object?> readyMessage() => {
        'type': readyType,
        'version': contractVersion,
      };

  Map<String, Object?> statusMessage(
    String state, {
    String? code,
    String? runtimeVersion,
    String? appVersion,
    String? configurationRevision,
  }) =>
      {
        'type': statusType,
        'version': contractVersion,
        'state': state,
        if (code != null) 'code': code,
        'metadata': {
          'channel': context.channel.name,
          'store_id': context.storeId,
          'locale': locale,
          'device_profile': deviceProfile,
          'device_width': deviceWidth,
          'configuration': configuration,
          'safe_mode': safeMode,
          if (runtimeVersion != null) 'runtime_version': runtimeVersion,
          if (appVersion != null) 'app_version': appVersion,
          if (configurationRevision != null)
            'configuration_revision': configurationRevision,
        },
      };

  static String _locale(Object? value) => value?.toString() == 'en' ? 'en' : 'ar';

  static String? _normalizedOrigin(String value) {
    final raw = value.trim();
    if (raw.isEmpty) return null;
    final uri = Uri.tryParse(raw);
    if (uri == null || !uri.hasScheme || uri.host.isEmpty) return null;
    if (uri.scheme != 'https' && uri.scheme != 'http') return null;
    if (uri.path.isNotEmpty && uri.path != '/') return null;
    if (uri.query.isNotEmpty || uri.fragment.isNotEmpty) return null;
    return uri.origin;
  }
}
