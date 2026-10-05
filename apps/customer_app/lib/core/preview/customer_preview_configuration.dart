import 'dart:convert';

import 'package:http/http.dart' as http;

import '../api/storefront_api.dart';
import '../auth/customer_session.dart';
import 'customer_preview_context.dart';

class CustomerPreviewConfigurationException implements Exception {
  const CustomerPreviewConfigurationException(
    this.code, {
    this.statusCode,
    this.runtimeState = 'error',
  });

  final String code;
  final int? statusCode;
  final String runtimeState;

  @override
  String toString() => code;
}

class CustomerPreviewResolvedConfiguration {
  const CustomerPreviewResolvedConfiguration({
    required this.revisionId,
    required this.checksum,
    required this.schemaVersion,
    required this.mode,
    required this.channel,
    required this.storeId,
    required this.payload,
  });

  static const supportedSchemaVersion = 1;

  final String revisionId;
  final String checksum;
  final int schemaVersion;
  final String mode;
  final CustomerChannel channel;
  final int storeId;
  final Map<String, dynamic> payload;

  Map<String, Object?> get safeStatusMetadata => {
        'configuration_revision': revisionId,
        'configuration_checksum': checksum,
        'configuration_schema_version': schemaVersion,
        'configuration': mode,
      };

  static Future<CustomerPreviewResolvedConfiguration> resolve({
    required String baseUrl,
    required http.Client client,
    required CustomerPreviewContext context,
    required String mode,
  }) async {
    if (!context.authenticated) {
      throw const CustomerPreviewConfigurationException(
        'preview_configuration_requires_authenticated_customer',
      );
    }

    return _resolve(
      uri: Uri.parse(
        '${_normalizedBase(baseUrl)}/api/v1/app-preview/storefront-configuration',
      ).replace(queryParameters: {'mode': mode}),
      client: client,
      context: context,
      mode: mode,
    );
  }

  static Future<CustomerPreviewResolvedConfiguration> resolveGuest({
    required String dashboardBaseUrl,
    required http.Client client,
    required CustomerPreviewContext context,
    required String mode,
  }) async {
    if (context.authenticated) {
      throw const CustomerPreviewConfigurationException(
        'preview_guest_configuration_requires_guest',
      );
    }

    return _resolve(
      uri: Uri.parse(
        '${_normalizedBase(dashboardBaseUrl)}/admin/app-preview/storefront-configuration',
      ).replace(
        queryParameters: {
          'channel': context.channel.name,
          'store_id': context.storeId.toString(),
          'mode': mode,
          if (context.supportAccess) 'support_access': '1',
        },
      ),
      client: client,
      context: context,
      mode: mode,
    );
  }

  static Future<CustomerPreviewResolvedConfiguration> _resolve({
    required Uri uri,
    required http.Client client,
    required CustomerPreviewContext context,
    required String mode,
  }) async {
    if (mode != 'draft' && mode != 'published') {
      throw const CustomerPreviewConfigurationException(
        'preview_configuration_mode_invalid',
      );
    }

    late http.Response response;
    try {
      response = await client.get(
        uri,
        headers: const {'Accept': 'application/json'},
      );
    } on http.ClientException {
      throw const CustomerPreviewConfigurationException(
        'preview_configuration_network_error',
      );
    }

    Object? decoded;
    if (response.body.isNotEmpty) {
      try {
        decoded = jsonDecode(response.body);
      } catch (_) {
        decoded = null;
      }
    }

    if (response.statusCode < 200 || response.statusCode >= 300) {
      throw _fromStatus(response.statusCode, mode);
    }

    if (decoded is Map && decoded['data'] == null) {
      final state = decoded['state']?.toString();
      if (state == 'draft_unavailable' || state == 'published_unavailable') {
        throw CustomerPreviewConfigurationException(
          state == 'draft_unavailable'
              ? 'preview_draft_unavailable'
              : 'preview_published_unavailable',
          statusCode: response.statusCode,
          runtimeState: 'unavailable',
        );
      }
    }

    if (decoded is! Map || decoded['data'] is! Map) {
      throw const CustomerPreviewConfigurationException(
        'preview_configuration_response_invalid',
      );
    }

    return _parse(
      Map<String, dynamic>.from(decoded['data'] as Map),
      context: context,
      requestedMode: mode,
    );
  }

  static String _normalizedBase(String value) {
    final base = value.trim();
    return base.endsWith('/') ? base.substring(0, base.length - 1) : base;
  }

  static CustomerPreviewResolvedConfiguration _parse(
    Map<String, dynamic> data, {
    required CustomerPreviewContext context,
    required String requestedMode,
  }) {
    final revisionId = data['revision_id']?.toString().trim() ?? '';
    final checksum = data['checksum']?.toString().trim() ?? '';
    final schemaVersion = _intValue(data['schema_version']);
    final storeId = _intValue(data['store_id']);
    final channel = switch (data['channel']) {
      'b2b' => CustomerChannel.b2b,
      'b2c' => CustomerChannel.b2c,
      _ => null,
    };
    final mode = data['mode']?.toString();
    final status = data['status']?.toString();
    final readOnly = data['read_only'] == true;
    final rawPayload = data['payload'];

    if (revisionId.isEmpty ||
        checksum.isEmpty ||
        schemaVersion == null ||
        storeId == null ||
        channel == null ||
        mode == null ||
        rawPayload is! Map ||
        !readOnly) {
      throw const CustomerPreviewConfigurationException(
        'preview_configuration_response_invalid',
      );
    }

    if (schemaVersion != supportedSchemaVersion) {
      throw const CustomerPreviewConfigurationException(
        'preview_configuration_schema_incompatible',
      );
    }
    if (storeId != context.storeId || channel != context.channel) {
      throw const CustomerPreviewConfigurationException(
        'preview_configuration_scope_mismatch',
      );
    }
    if (mode != requestedMode || status != requestedMode) {
      throw const CustomerPreviewConfigurationException(
        'preview_configuration_mode_mismatch',
      );
    }

    final payload = Map<String, dynamic>.from(rawPayload);
    final payloadStore = payload['store'];
    if (_intValue(payloadStore is Map ? payloadStore['id'] : null) !=
            context.storeId ||
        payload['channel']?.toString() != context.channel.name ||
        _intValue(payload['schema_version']) != supportedSchemaVersion) {
      throw const CustomerPreviewConfigurationException(
        'preview_configuration_payload_scope_mismatch',
      );
    }

    return CustomerPreviewResolvedConfiguration(
      revisionId: revisionId,
      checksum: checksum,
      schemaVersion: schemaVersion,
      mode: mode,
      channel: channel,
      storeId: storeId,
      payload: payload,
    );
  }

  static CustomerPreviewConfigurationException _fromStatus(
    int statusCode,
    String mode,
  ) {
    return switch (statusCode) {
      401 => const CustomerPreviewConfigurationException(
          'preview_session_expired',
          statusCode: 401,
          runtimeState: 'expired',
        ),
      403 => const CustomerPreviewConfigurationException(
          'preview_configuration_forbidden',
          statusCode: 403,
          runtimeState: 'forbidden',
        ),
      404 => CustomerPreviewConfigurationException(
          mode == 'draft'
              ? 'preview_draft_unavailable'
              : 'preview_published_unavailable',
          statusCode: 404,
        ),
      422 => const CustomerPreviewConfigurationException(
          'preview_configuration_schema_incompatible',
          statusCode: 422,
        ),
      _ => CustomerPreviewConfigurationException(
          'preview_configuration_http_$statusCode',
          statusCode: statusCode,
        ),
    };
  }

  static int? _intValue(Object? value) {
    if (value is int) return value;
    if (value is num) return value.toInt();
    return int.tryParse(value?.toString() ?? '');
  }
}

class PreviewRevisionStorefrontApi implements StorefrontApi {
  const PreviewRevisionStorefrontApi({
    required this.delegate,
    required this.context,
    required this.configuration,
    required this.baseUrl,
  });

  final StorefrontApi delegate;
  final CustomerPreviewContext context;
  final CustomerPreviewResolvedConfiguration configuration;
  final String baseUrl;

  @override
  Future<Map<String, dynamic>> selection({
    String? countryCode,
    String? city,
    String? area,
    bool support = false,
  }) =>
      delegate.selection(
        countryCode: countryCode,
        city: city,
        area: area,
        support: false,
      );

  @override
  Future<Map<String, dynamic>> retailHome(int storeId) async {
    _require(CustomerChannel.b2c, storeId);
    return _storefrontPayload();
  }

  @override
  Future<Map<String, dynamic>> wholesaleHome(int storeId) async {
    _require(CustomerChannel.b2b, storeId);
    return _storefrontPayload();
  }

  @override
  Future<Map<String, dynamic>> b2bCheckoutOptions(int storeId) {
    _require(CustomerChannel.b2b, storeId);
    return delegate.b2bCheckoutOptions(storeId);
  }

  void _require(CustomerChannel channel, int storeId) {
    if (context.channel != channel || configuration.channel != channel) {
      throw const CustomerPreviewScopeException(
        'preview_channel_scope_mismatch',
      );
    }
    if (storeId != context.storeId || configuration.storeId != storeId) {
      throw const CustomerPreviewScopeException(
        'preview_store_scope_mismatch',
      );
    }
  }

  Map<String, dynamic> _storefrontPayload() {
    final store = _map(configuration.payload['store']);
    final settings = _map(configuration.payload['settings']);

    final sections = _rows(configuration.payload['sections'])
        .where((row) => row['is_active'] != false)
        .toList(growable: false)
      ..sort(_sortOrder);

    final banners = _rows(configuration.payload['banners'])
        .where((row) => row['is_active'] != false)
        .map(
          (row) => {
            'title': row['title']?.toString() ?? '',
            'image_url': _assetUrl(row['image_path']),
            'target_type': row['target_type'],
            'target_id': row['target_id'],
            'target_url': row['target_url'],
            'sort_order': _intValue(row['sort_order']) ?? 0,
          },
        )
        .toList(growable: false)
      ..sort(_sortOrder);

    final logoUrl = _assetUrl(store['logo_path']);
    final themeCode = settings['theme_code']?.toString() ??
        (context.channel == CustomerChannel.b2b
            ? 'wholesale_b2b'
            : 'retail_grocery');

    return {
      'store': {
        'id': configuration.storeId,
        'code': store['code']?.toString() ?? '',
        'name': store['name']?.toString() ?? '',
        'logo_url': logoUrl,
        'channel': context.channel.name,
        'store_type':
            context.channel == CustomerChannel.b2b ? 'B2B' : 'B2C',
        'theme_code': themeCode,
        'is_active': store['is_active'] != false,
      },
      'theme': {
        'code': themeCode,
        'primary': settings['primary_color'],
        'primary_dark': settings['primary_dark_color'],
        'accent': settings['accent_color'],
        'background': settings['background_color'],
      },
      'branding': {
        'logo_url': logoUrl,
        'address': settings['header_address'],
        'custom': _map(settings['branding']),
      },
      'hero': banners.isEmpty ? null : banners.first,
      'banners': banners,
      'sections': sections
          .map(
            (row) => {
              'key': row['key']?.toString() ?? '',
              'type': row['type']?.toString() ?? '',
              'title_ar': row['title_ar'],
              'title_en': row['title_en'],
              'sort_order': _intValue(row['sort_order']) ?? 0,
              'config': _map(row['config']),
            },
          )
          .toList(growable: false),
    };
  }

  String? _assetUrl(Object? value) {
    final path = value?.toString().trim() ?? '';
    if (path.isEmpty) return null;
    final uri = Uri.tryParse(path);
    if (uri != null && (uri.scheme == 'https' || uri.scheme == 'http')) {
      return path;
    }

    final normalizedBase = baseUrl.endsWith('/')
        ? baseUrl.substring(0, baseUrl.length - 1)
        : baseUrl;
    final normalizedPath = path.startsWith('/') ? path : '/$path';
    return '$normalizedBase$normalizedPath';
  }

  static Map<String, dynamic> _map(Object? value) =>
      value is Map ? Map<String, dynamic>.from(value) : <String, dynamic>{};

  static List<Map<String, dynamic>> _rows(Object? value) =>
      (value as List? ?? const <Object>[])
          .whereType<Map>()
          .map((row) => Map<String, dynamic>.from(row))
          .toList(growable: false);

  static int _sortOrder(
    Map<String, dynamic> left,
    Map<String, dynamic> right,
  ) =>
      (_intValue(left['sort_order']) ?? 0)
          .compareTo(_intValue(right['sort_order']) ?? 0);

  static int? _intValue(Object? value) {
    if (value is int) return value;
    if (value is num) return value.toInt();
    return int.tryParse(value?.toString() ?? '');
  }
}
