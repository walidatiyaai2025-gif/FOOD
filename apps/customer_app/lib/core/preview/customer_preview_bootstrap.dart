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
    required this.deviceProfile,
    required this.deviceWidth,
    this.credential,
  });

  final CustomerPreviewContext context;
  final String locale;
  final String configuration;
  final String deviceProfile;
  final int deviceWidth;
  final String? credential;

  bool get authenticated => context.authenticated;

  Map<String, Object?> get safeStatusMetadata => {
        'target_type': 'customer',
        'channel': context.channel.name,
        'store_id': context.storeId,
        'authenticated': context.authenticated,
        'locale': locale,
        'configuration': configuration,
        'device_profile': deviceProfile,
        'device_width': deviceWidth,
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
    final width = _positiveInt(deviceMap['width']) ?? 390;
    if (width < 320 || width > 1024) {
      throw const CustomerPreviewBootstrapException('preview_device_invalid');
    }
    final profile =
        _nullableString(deviceMap['profile']) ?? 'phone_standard';

    return CustomerPreviewBootstrap(
      context: context,
      locale: locale,
      configuration: configuration,
      deviceProfile: profile,
      deviceWidth: width,
      credential: credentialText,
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
