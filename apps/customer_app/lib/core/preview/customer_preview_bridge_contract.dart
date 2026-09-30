import '../auth/customer_session.dart';
import 'customer_preview_context.dart';

class CustomerPreviewBootstrap {
  const CustomerPreviewBootstrap({
    required this.version,
    required this.context,
    required this.locale,
    required this.configuration,
    required this.deviceProfile,
    required this.deviceWidth,
    this.credential,
  });

  factory CustomerPreviewBootstrap.fromMessage(
    Map<String, dynamic> message, {
    required String expectedVersion,
  }) {
    if (message['type'] != 'foodex.preview.bootstrap' ||
        message['version'] != expectedVersion) {
      throw const FormatException('Invalid Customer preview bridge contract.');
    }

    final payload = message['payload'];
    if (payload is! Map || payload['safe_mode'] != 'read_only') {
      throw const FormatException('Customer preview must be read-only.');
    }

    final rawContext = payload['context'];
    if (rawContext is! Map) {
      throw const FormatException('Missing Customer preview context.');
    }
    final contextData = Map<String, dynamic>.from(rawContext);
    if (contextData['target_type'] != 'customer' ||
        contextData['read_only'] != true) {
      throw const FormatException('Invalid Customer preview target.');
    }

    final storeId = contextData['store_id'];
    if (storeId is! int || storeId <= 0) {
      throw const FormatException('Customer preview requires a store.');
    }

    final channel = switch (contextData['channel']) {
      'b2b' => CustomerChannel.b2b,
      'b2c' => CustomerChannel.b2c,
      _ => throw const FormatException('Unsupported Customer preview channel.'),
    };

    final target = contextData['target'];
    final authenticated = target is Map;
    final credential = messageCredential(payload['credential']);

    if (authenticated && credential == null) {
      throw const FormatException(
        'Authenticated Customer preview requires a credential.',
      );
    }
    if (!authenticated && credential != null) {
      throw const FormatException(
        'Guest Customer preview cannot receive a credential.',
      );
    }

    final locale = payload['locale']?.toString() ?? 'ar';
    if (locale != 'ar' && locale != 'en') {
      throw const FormatException('Unsupported Customer preview locale.');
    }

    final device = payload['device'];
    if (device is! Map) {
      throw const FormatException('Missing Customer preview device profile.');
    }
    final width = device['width'];
    if (width is! num || width < 320 || width > 1200) {
      throw const FormatException('Invalid Customer preview device width.');
    }

    final configuration = payload['configuration']?.toString() ?? 'published';
    if (configuration != 'draft' && configuration != 'published') {
      throw const FormatException('Unsupported storefront revision mode.');
    }

    final context = authenticated
        ? CustomerPreviewContext.fromResolvedSession(
            contextData,
            configurationRevision: configuration,
            runtimeVersion: expectedVersion,
          )
        : CustomerPreviewContext.guest(
            channel: channel,
            storeId: storeId,
            targetLocale: locale,
            configurationRevision: configuration,
            runtimeVersion: expectedVersion,
          );

    return CustomerPreviewBootstrap(
      version: expectedVersion,
      context: context,
      credential: credential,
      locale: locale,
      configuration: configuration,
      deviceProfile: device['profile']?.toString() ?? 'phone_standard',
      deviceWidth: width.toInt(),
    );
  }

  final String version;
  final CustomerPreviewContext context;
  final String? credential;
  final String locale;
  final String configuration;
  final String deviceProfile;
  final int deviceWidth;

  bool get authenticated => context.authenticated;

  static String? messageCredential(Object? raw) {
    if (raw == null) return null;
    final value = raw.toString().trim();
    return value.isEmpty ? null : value;
  }
}
