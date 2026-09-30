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
    required this.viewport,
    required this.credential,
  });

  final DriverPreviewContext context;
  final String locale;
  final String configuration;
  final DriverPreviewViewport viewport;
  final String credential;

  String get deviceProfile => viewport.profile;
  int get deviceWidth => viewport.width;
  int get deviceHeight => viewport.height;

  Map<String, Object?> get safeStatusMetadata => {
        'target_type': 'driver',
        'channel': context.channel.name,
        'store_id': context.storeId,
        'auth_mode': 'preview-driver',
        'locale': locale,
        'configuration': configuration,
        'device_profile': viewport.profile,
        'device_platform': viewport.platform,
        'device_width': viewport.width,
        'device_height': viewport.height,
        'device_safe_area': {
          'top': viewport.safeAreaTop,
          'right': viewport.safeAreaRight,
          'bottom': viewport.safeAreaBottom,
          'left': viewport.safeAreaLeft,
        },
        'device_text_scale': viewport.textScale,
        'device_orientation': viewport.orientation,
        'keyboard_inset_bottom': viewport.keyboardInsetBottom,
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
    final viewport = _viewport(deviceMap);

    final context = DriverPreviewContext.fromResolvedSession(
      contextData,
      configurationRevision: configuration,
      runtimeVersion: expectedVersion,
    );

    return DriverPreviewBootstrap(
      context: context,
      locale: locale,
      configuration: configuration,
      viewport: viewport,
      credential: credential.trim(),
    );
  }

  static DriverPreviewViewport _viewport(Map<String, dynamic> data) {
    final profile = _nullableString(data['profile']) ?? 'android_common';
    final platform = switch (_nullableString(data['platform'])) {
      'ios' => 'ios',
      'android' || null => 'android',
      _ => throw const DriverPreviewBootstrapException(
          'preview_device_invalid',
        ),
    };
    final width = _positiveInt(data['width']) ?? 390;
    final height = _positiveInt(data['height']) ?? 844;
    final orientation = _nullableString(data['orientation']) ?? 'portrait';
    final textScale = data['text_scale'] is num
        ? (data['text_scale'] as num).toDouble()
        : 1.0;
    final keyboardInset = _nonNegativeInt(data['keyboard_inset_bottom']) ?? 0;
    final safeRaw = data['safe_area'];
    final safe = safeRaw is Map
        ? Map<String, dynamic>.from(safeRaw)
        : const <String, dynamic>{};
    final top = _nonNegativeInt(safe['top']) ?? 0;
    final right = _nonNegativeInt(safe['right']) ?? 0;
    final bottom = _nonNegativeInt(safe['bottom']) ?? 0;
    final left = _nonNegativeInt(safe['left']) ?? 0;

    if (width < 320 ||
        width > 1024 ||
        height < 480 ||
        height > 1600 ||
        height < width ||
        orientation != 'portrait' ||
        textScale < 0.8 ||
        textScale > 2.0 ||
        keyboardInset > 800 ||
        [top, right, bottom, left].any((value) => value > 240)) {
      throw const DriverPreviewBootstrapException('preview_device_invalid');
    }

    return DriverPreviewViewport(
      profile: profile,
      platform: platform,
      width: width,
      height: height,
      safeAreaTop: top,
      safeAreaRight: right,
      safeAreaBottom: bottom,
      safeAreaLeft: left,
      textScale: textScale,
      orientation: orientation,
      keyboardInsetBottom: keyboardInset,
    );
  }

  static int? _nonNegativeInt(Object? value) {
    if (value is int && value >= 0) return value;
    if (value is num && value >= 0) return value.toInt();
    return null;
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
