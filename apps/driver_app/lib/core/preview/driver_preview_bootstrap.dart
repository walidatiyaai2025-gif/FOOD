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
    required this.deviceHeight,
    required this.safeAreaTop,
    required this.safeAreaRight,
    required this.safeAreaBottom,
    required this.safeAreaLeft,
    required this.orientation,
    required this.textScale,
    required this.viewInsetBottom,
    required this.credential,
  });

  final DriverPreviewContext context;
  final String locale;
  final String configuration;
  final String deviceProfile;
  final int deviceWidth;
  final int deviceHeight;
  final double safeAreaTop;
  final double safeAreaRight;
  final double safeAreaBottom;
  final double safeAreaLeft;
  final String orientation;
  final double textScale;
  final double viewInsetBottom;
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
        'device_height': deviceHeight,
        'device_orientation': orientation,
        'device_text_scale': textScale,
        'device_safe_area': {
          'top': safeAreaTop,
          'right': safeAreaRight,
          'bottom': safeAreaBottom,
          'left': safeAreaLeft,
        },
        'device_view_inset_bottom': viewInsetBottom,
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
    final height = _positiveInt(deviceMap['height']) ?? 844;
    final profile = _nullableString(deviceMap['profile']) ?? 'android_common';
    final orientation =
        _nullableString(deviceMap['orientation']) ?? 'portrait';
    final textScale = _positiveDouble(deviceMap['text_scale']) ?? 1.0;
    final rawSafeArea = deviceMap['safe_area'];
    final safeArea = rawSafeArea is Map
        ? Map<String, dynamic>.from(rawSafeArea)
        : const <String, dynamic>{};
    final rawInsets = deviceMap['view_insets'];
    final viewInsets = rawInsets is Map
        ? Map<String, dynamic>.from(rawInsets)
        : const <String, dynamic>{};
    final safeTop = _nonNegativeDouble(safeArea['top']) ?? 0;
    final safeRight = _nonNegativeDouble(safeArea['right']) ?? 0;
    final safeBottom = _nonNegativeDouble(safeArea['bottom']) ?? 0;
    final safeLeft = _nonNegativeDouble(safeArea['left']) ?? 0;
    final viewInsetBottom =
        _nonNegativeDouble(viewInsets['bottom']) ?? 0;

    if (width < 320 ||
        width > 1024 ||
        height < 480 ||
        height > 1600 ||
        orientation != 'portrait' ||
        height <= width ||
        textScale < 0.8 ||
        textScale > 2.0 ||
        safeTop + safeBottom >= height ||
        safeLeft + safeRight >= width ||
        viewInsetBottom >= height) {
      throw const DriverPreviewBootstrapException('preview_device_invalid');
    }

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
      deviceHeight: height,
      safeAreaTop: safeTop,
      safeAreaRight: safeRight,
      safeAreaBottom: safeBottom,
      safeAreaLeft: safeLeft,
      orientation: orientation,
      textScale: textScale,
      viewInsetBottom: viewInsetBottom,
      credential: credential.trim(),
    );
  }

  static int? _positiveInt(Object? value) {
    if (value is int && value > 0) return value;
    if (value is num && value > 0) return value.toInt();
    return null;
  }

  static double? _positiveDouble(Object? value) {
    if (value is num && value > 0) return value.toDouble();
    return null;
  }

  static double? _nonNegativeDouble(Object? value) {
    if (value is num && value >= 0) return value.toDouble();
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
