import 'driver_preview_context.dart';

abstract final class DriverPreviewHostContract {
  static const version = String.fromEnvironment(
    'FOODEX_PREVIEW_CONTRACT_VERSION',
    defaultValue: 'shared-flutter-v1',
  );

  static const allowedParentOrigin = String.fromEnvironment(
    'FOODEX_PREVIEW_PARENT_ORIGIN',
    defaultValue: '',
  );

  static bool allowsMessage({
    required String origin,
    required String expectedOrigin,
    required bool fromParent,
  }) =>
      fromParent &&
      expectedOrigin.trim().isNotEmpty &&
      origin == expectedOrigin;
}

class DriverPreviewBootstrap {
  const DriverPreviewBootstrap({
    required this.context,
    required this.locale,
    required this.configuration,
    required this.deviceProfile,
    required this.deviceWidth,
    required this.credential,
  });

  final DriverPreviewContext context;
  final String locale;
  final String configuration;
  final String deviceProfile;
  final int deviceWidth;
  final String credential;

  Map<String, Object?> get safeStatusMetadata => {
        'target_type': 'driver',
        'channel': context.channel.name,
        'store_id': context.storeId,
        'auth_mode': 'preview-driver',
        'locale': locale,
        'configuration': configuration,
        'device_profile': deviceProfile,
        'device_width': deviceWidth,
        'runtime_version': context.runtimeVersion,
        'read_only': true,
      };

  static DriverPreviewBootstrap parse(
    Map<String, dynamic> message, {
    required String origin,
    required String expectedOrigin,
    String expectedVersion = DriverPreviewHostContract.version,
  }) {
    if (expectedOrigin.trim().isEmpty || origin != expectedOrigin) {
      throw const DriverPreviewBootstrapException('preview_origin_rejected');
    }
    if (message['type'] != 'foodex.preview.bootstrap') {
      throw const DriverPreviewBootstrapException('preview_message_invalid');
    }
    if (message['version'] != expectedVersion) {
      throw const DriverPreviewBootstrapException('preview_version_mismatch');
    }

    final payload = message['payload'];
    if (payload is! Map) {
      throw const DriverPreviewBootstrapException('preview_payload_invalid');
    }
    final body = Map<String, dynamic>.from(payload);

    if (body['safe_mode'] != 'read_only') {
      throw const DriverPreviewBootstrapException('preview_safe_mode_required');
    }

    final rawContext = body['context'];
    if (rawContext is! Map) {
      throw const DriverPreviewBootstrapException('preview_context_invalid');
    }
    final contextData = Map<String, dynamic>.from(rawContext);
    if (contextData['target_type'] != 'driver' ||
        contextData['read_only'] != true ||
        contextData['mode'] != 'read_only') {
      throw const DriverPreviewBootstrapException('preview_driver_required');
    }

    final target = contextData['target'];
    if (target is! Map ||
        _positiveInt(target['user_id']) == null ||
        _positiveInt(target['driver_id']) == null) {
      throw const DriverPreviewBootstrapException(
        'preview_driver_target_invalid',
      );
    }

    final credential = body['credential'];
    if (credential is! String || credential.trim().isEmpty) {
      throw const DriverPreviewBootstrapException('preview_credential_required');
    }

    final locale = _locale(body['locale']);
    final configuration = switch (body['configuration']) {
      'draft' => 'draft',
      'published' => 'published',
      _ => throw const DriverPreviewBootstrapException(
          'preview_configuration_invalid',
        ),
    };

    final device = body['device'];
    final deviceMap =
        device is Map ? Map<String, dynamic>.from(device) : const <String, dynamic>{};
    final width = _positiveInt(deviceMap['width']) ?? 390;
    if (width < 320 || width > 1024) {
      throw const DriverPreviewBootstrapException('preview_device_invalid');
    }
    final profile = _nullableString(deviceMap['profile']) ?? 'phone_standard';

    final context = DriverPreviewContext.fromResolvedSession(
      contextData,
      configurationRevision: configuration,
      runtimeVersion: expectedVersion,
    );

    return DriverPreviewBootstrap(
      context: context,
      locale: locale,
      configuration: configuration,
      deviceProfile: profile,
      deviceWidth: width,
      credential: credential.trim(),
    );
  }

  static int? _positiveInt(Object? value) {
    if (value is int && value > 0) return value;
    if (value is num && value > 0) return value.toInt();
    return null;
  }

  static String _locale(Object? value) {
    final locale = value?.toString().toLowerCase();
    if (locale == 'ar' || locale == 'en') return locale!;
    throw const DriverPreviewBootstrapException('preview_locale_invalid');
  }

  static String? _nullableString(Object? value) {
    if (value is! String || value.trim().isEmpty) return null;
    return value.trim();
  }
}

class DriverPreviewBootstrapException implements Exception {
  const DriverPreviewBootstrapException(this.code);

  final String code;

  @override
  String toString() => code;
}
