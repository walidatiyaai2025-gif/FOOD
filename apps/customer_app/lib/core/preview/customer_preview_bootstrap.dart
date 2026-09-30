import '../auth/customer_session.dart';
import 'customer_preview_context.dart';

abstract final class CustomerPreviewHostContract {
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

class CustomerPreviewBootstrap {
  const CustomerPreviewBootstrap({
    required this.context,
    required this.locale,
    required this.configuration,
    required this.viewport,
    this.credential,
  });

  final CustomerPreviewContext context;
  final String locale;
  final String configuration;
  final CustomerPreviewViewport viewport;
  final String? credential;

  String get deviceProfile => viewport.profile;
  int get deviceWidth => viewport.width;
  int get deviceHeight => viewport.height;

  bool get authenticated => context.authenticated;

  Map<String, Object?> get safeStatusMetadata => {
        'target_type': 'customer',
        'channel': context.channel.name,
        'store_id': context.storeId,
        'authenticated': context.authenticated,
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
        'configuration_revision': context.configurationRevision,
        'read_only': true,
      };

  static CustomerPreviewBootstrap parse(
    Map<String, dynamic> message, {
    required String origin,
    required String expectedOrigin,
    String expectedVersion = CustomerPreviewHostContract.version,
  }) {
    if (expectedOrigin.trim().isEmpty || origin != expectedOrigin) {
      throw const CustomerPreviewBootstrapException('preview_origin_rejected');
    }
    if (message['type'] != 'foodex.preview.bootstrap') {
      throw const CustomerPreviewBootstrapException('preview_message_invalid');
    }
    if (message['version'] != expectedVersion) {
      throw const CustomerPreviewBootstrapException('preview_version_mismatch');
    }

    final payload = message['payload'];
    if (payload is! Map) {
      throw const CustomerPreviewBootstrapException('preview_payload_invalid');
    }
    final body = Map<String, dynamic>.from(payload);

    if (body['safe_mode'] != 'read_only') {
      throw const CustomerPreviewBootstrapException('preview_safe_mode_required');
    }

    final rawContext = body['context'];
    if (rawContext is! Map) {
      throw const CustomerPreviewBootstrapException('preview_context_invalid');
    }
    final contextData = Map<String, dynamic>.from(rawContext);

    if (contextData['target_type'] != 'customer' ||
        contextData['read_only'] != true) {
      throw const CustomerPreviewBootstrapException('preview_customer_required');
    }

    final channel = switch (contextData['channel']) {
      'b2b' => CustomerChannel.b2b,
      'b2c' => CustomerChannel.b2c,
      _ => throw const CustomerPreviewBootstrapException(
          'preview_channel_invalid',
        ),
    };
    final storeId = _positiveInt(contextData['store_id']);
    if (storeId == null) {
      throw const CustomerPreviewBootstrapException('preview_store_invalid');
    }

    final credential = body['credential'];
    final credentialText =
        credential is String && credential.trim().isNotEmpty
            ? credential.trim()
            : null;
    final target = contextData['target'];
    final sessionId = contextData['session_id'];
    final authenticated = target is Map || sessionId != null;

    CustomerPreviewContext context;
    if (authenticated) {
      if (credentialText == null) {
        throw const CustomerPreviewBootstrapException(
          'preview_credential_required',
        );
      }
      context = CustomerPreviewContext.fromResolvedSession(contextData);
    } else {
      if (credentialText != null) {
        throw const CustomerPreviewBootstrapException(
          'preview_guest_credential_forbidden',
        );
      }
      final locale = _locale(body['locale']);
      context = CustomerPreviewContext.guest(
        channel: channel,
        storeId: storeId,
        targetLocale: locale,
        configurationRevision: _nullableString(
          contextData['configuration_revision'],
        ),
        runtimeVersion: _nullableString(contextData['runtime_version']),
        supportAccess: contextData['support_access'] == true,
      );
    }

    if (context.channel != channel || context.storeId != storeId) {
      throw const CustomerPreviewBootstrapException('preview_scope_mismatch');
    }

    final locale = _locale(body['locale']);
    final configuration = switch (body['configuration']) {
      'draft' => 'draft',
      'published' => 'published',
      _ => throw const CustomerPreviewBootstrapException(
          'preview_configuration_invalid',
        ),
    };

    final device = body['device'];
    final deviceMap =
        device is Map ? Map<String, dynamic>.from(device) : const <String, dynamic>{};
    final viewport = _viewport(deviceMap);

    return CustomerPreviewBootstrap(
      context: context,
      locale: locale,
      configuration: configuration,
      viewport: viewport,
      credential: credentialText,
    );
  }

  static CustomerPreviewViewport _viewport(Map<String, dynamic> data) {
    final profile = _nullableString(data['profile']) ?? 'android_common';
    final platform = switch (_nullableString(data['platform'])) {
      'ios' => 'ios',
      'android' || null => 'android',
      _ => throw const CustomerPreviewBootstrapException(
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
      throw const CustomerPreviewBootstrapException('preview_device_invalid');
    }

    return CustomerPreviewViewport(
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
    throw const CustomerPreviewBootstrapException('preview_locale_invalid');
  }

  static String? _nullableString(Object? value) {
    if (value is! String || value.trim().isEmpty) return null;
    return value.trim();
  }
}

class CustomerPreviewBootstrapException implements Exception {
  const CustomerPreviewBootstrapException(this.code);

  final String code;

  @override
  String toString() => code;
}
