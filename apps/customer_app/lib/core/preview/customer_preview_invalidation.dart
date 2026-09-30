import 'dart:convert';

import 'package:http/http.dart' as http;

import '../auth/customer_session.dart';
import 'customer_preview_configuration.dart';
import 'customer_preview_context.dart';

class CustomerPreviewInvalidationException implements Exception {
  const CustomerPreviewInvalidationException(
    this.code, {
    this.runtimeState = 'stale',
    this.retryable = true,
  });

  final String code;
  final String runtimeState;
  final bool retryable;

  @override
  String toString() => code;
}

class CustomerPreviewInvalidationEvent {
  const CustomerPreviewInvalidationEvent({
    required this.eventId,
    required this.event,
    required this.channel,
    required this.storeId,
    required this.revisionId,
    required this.revisionStatus,
    required this.checksum,
    required this.schemaVersion,
  });

  final int eventId;
  final String event;
  final CustomerChannel channel;
  final int storeId;
  final String revisionId;
  final String revisionStatus;
  final String checksum;
  final int schemaVersion;

  bool matches({
    required CustomerPreviewContext context,
    required String mode,
    required CustomerPreviewResolvedConfiguration current,
  }) {
    if (event != 'app.preview.configuration.updated' &&
        event != 'storefront.preview.updated') {
      return false;
    }
    if (channel != context.channel || storeId != context.storeId) {
      return false;
    }
    if (revisionStatus != mode ||
        schemaVersion != CustomerPreviewResolvedConfiguration.supportedSchemaVersion) {
      return false;
    }

    return revisionId != current.revisionId || checksum != current.checksum;
  }
}

class CustomerPreviewInvalidationBatch {
  const CustomerPreviewInvalidationBatch({
    required this.events,
    required this.cursor,
    required this.retryAfter,
  });

  final List<CustomerPreviewInvalidationEvent> events;
  final int cursor;
  final Duration retryAfter;
}

class CustomerPreviewInvalidationFeed {
  CustomerPreviewInvalidationFeed({
    required this.apiBaseUrl,
    required this.dashboardBaseUrl,
    required this.context,
    required this.client,
  });

  final String apiBaseUrl;
  final String dashboardBaseUrl;
  final CustomerPreviewContext context;
  final http.Client client;

  Future<CustomerPreviewInvalidationBatch> poll({
    int lastEventId = 0,
  }) async {
    final uri = context.authenticated
        ? Uri.parse(_join(apiBaseUrl, '/api/v1/app-preview/events'))
        : Uri.parse(_join(dashboardBaseUrl, '/admin/app-preview/events')).replace(
            queryParameters: {
              'channel': context.channel.name,
              'store_id': context.storeId.toString(),
              if (context.supportAccess) 'support_access': '1',
            },
          );

    final headers = <String, String>{
      'Accept': 'text/event-stream',
      'Cache-Control': 'no-cache',
      if (lastEventId > 0) 'Last-Event-ID': lastEventId.toString(),
    };

    late http.Response response;
    try {
      response = await client.get(uri, headers: headers);
    } on http.ClientException {
      throw const CustomerPreviewInvalidationException(
        'preview_invalidation_network_error',
      );
    }

    if (response.statusCode < 200 || response.statusCode >= 300) {
      throw _fromStatus(response.statusCode);
    }

    return _parse(response.body, initialCursor: lastEventId);
  }

  CustomerPreviewInvalidationException _fromStatus(int statusCode) {
    return switch (statusCode) {
      401 => const CustomerPreviewInvalidationException(
          'preview_invalidation_session_expired',
          runtimeState: 'expired',
          retryable: false,
        ),
      403 => const CustomerPreviewInvalidationException(
          'preview_invalidation_forbidden',
          runtimeState: 'forbidden',
          retryable: false,
        ),
      404 => const CustomerPreviewInvalidationException(
          'preview_invalidation_scope_unavailable',
          runtimeState: 'error',
          retryable: false,
        ),
      422 => const CustomerPreviewInvalidationException(
          'preview_invalidation_scope_invalid',
          runtimeState: 'error',
          retryable: false,
        ),
      _ => CustomerPreviewInvalidationException(
          'preview_invalidation_http_$statusCode',
        ),
    };
  }

  static CustomerPreviewInvalidationBatch _parse(
    String body, {
    required int initialCursor,
  }) {
    var cursor = initialCursor;
    var retryAfter = const Duration(seconds: 3);
    final events = <CustomerPreviewInvalidationEvent>[];
    final normalized = body.replaceAll('\r\n', '\n');

    for (final block in normalized.split(RegExp(r'\n\n+'))) {
      if (block.trim().isEmpty) continue;

      String? idText;
      String? eventName;
      final dataLines = <String>[];

      for (final rawLine in block.split('\n')) {
        final line = rawLine.trimRight();
        if (line.isEmpty || line.startsWith(':')) continue;
        if (line.startsWith('retry:')) {
          final milliseconds = int.tryParse(line.substring(6).trim());
          if (milliseconds != null) {
            retryAfter = Duration(
              milliseconds: milliseconds.clamp(1000, 30000),
            );
          }
          continue;
        }
        if (line.startsWith('id:')) {
          idText = line.substring(3).trim();
          continue;
        }
        if (line.startsWith('event:')) {
          eventName = line.substring(6).trim();
          continue;
        }
        if (line.startsWith('data:')) {
          dataLines.add(line.substring(5).trimLeft());
        }
      }

      if (dataLines.isEmpty) continue;

      final decoded = jsonDecode(dataLines.join('\n'));
      if (decoded is! Map) {
        throw const CustomerPreviewInvalidationException(
          'preview_invalidation_payload_invalid',
          runtimeState: 'error',
          retryable: false,
        );
      }

      final data = Map<String, dynamic>.from(decoded);
      const allowedKeys = <String>{
        'event_id',
        'event',
        'channel',
        'store_id',
        'revision_id',
        'revision_status',
        'checksum',
        'schema_version',
        'occurred_at',
      };
      if (data.keys.any((key) => !allowedKeys.contains(key))) {
        throw const CustomerPreviewInvalidationException(
          'preview_invalidation_payload_rejected',
          runtimeState: 'error',
          retryable: false,
        );
      }

      final eventId = _positiveInt(data['event_id']);
      final transportId = _positiveInt(idText);
      final storeId = _positiveInt(data['store_id']);
      final schemaVersion = _positiveInt(data['schema_version']);
      final channel = switch (data['channel']) {
        'b2b' => CustomerChannel.b2b,
        'b2c' => CustomerChannel.b2c,
        _ => null,
      };
      final payloadEvent = data['event']?.toString().trim() ?? '';
      final revisionId = data['revision_id']?.toString().trim() ?? '';
      final revisionStatus = data['revision_status']?.toString().trim() ?? '';
      final checksum = data['checksum']?.toString().trim() ?? '';

      if (eventId == null ||
          transportId == null ||
          eventId != transportId ||
          storeId == null ||
          schemaVersion == null ||
          channel == null ||
          eventName == null ||
          eventName != payloadEvent ||
          revisionId.isEmpty ||
          (revisionStatus != 'draft' && revisionStatus != 'published') ||
          !RegExp(r'^[0-9a-fA-F]{64}$').hasMatch(checksum)) {
        throw const CustomerPreviewInvalidationException(
          'preview_invalidation_payload_invalid',
          runtimeState: 'error',
          retryable: false,
        );
      }

      if (eventId <= cursor) {
        continue;
      }

      cursor = eventId;
      events.add(
        CustomerPreviewInvalidationEvent(
          eventId: eventId,
          event: payloadEvent,
          channel: channel,
          storeId: storeId,
          revisionId: revisionId,
          revisionStatus: revisionStatus,
          checksum: checksum,
          schemaVersion: schemaVersion,
        ),
      );
    }

    return CustomerPreviewInvalidationBatch(
      events: List.unmodifiable(events),
      cursor: cursor,
      retryAfter: retryAfter,
    );
  }

  static int? _positiveInt(Object? value) {
    if (value is int && value > 0) return value;
    if (value is num && value > 0) return value.toInt();
    return int.tryParse(value?.toString() ?? '');
  }

  static String _join(String base, String path) {
    final normalizedBase =
        base.endsWith('/') ? base.substring(0, base.length - 1) : base;
    return '$normalizedBase$path';
  }
}

class CustomerPreviewInvalidationOutcome {
  const CustomerPreviewInvalidationOutcome({
    required this.cursor,
    required this.retryAfter,
    required this.configuration,
    required this.changed,
  });

  final int cursor;
  final Duration retryAfter;
  final CustomerPreviewResolvedConfiguration configuration;
  final bool changed;
}

class CustomerPreviewInvalidationCoordinator {
  CustomerPreviewInvalidationCoordinator({
    required this.feed,
    required this.context,
    required this.mode,
    required CustomerPreviewResolvedConfiguration currentConfiguration,
    required this.authoritativeRefetch,
    int initialCursor = 0,
  })  : _configuration = currentConfiguration,
        _cursor = initialCursor;

  final CustomerPreviewInvalidationFeed feed;
  final CustomerPreviewContext context;
  final String mode;
  final Future<CustomerPreviewResolvedConfiguration> Function()
      authoritativeRefetch;

  CustomerPreviewResolvedConfiguration _configuration;
  int _cursor;
  int _lastAppliedEventId = 0;

  int get cursor => _cursor;
  CustomerPreviewResolvedConfiguration get configuration => _configuration;

  Future<CustomerPreviewInvalidationOutcome> pollOnce() async {
    final batch = await feed.poll(lastEventId: _cursor);
    CustomerPreviewInvalidationEvent? latest;

    for (final event in batch.events) {
      if (event.eventId <= _cursor) continue;
      _cursor = event.eventId;

      if (event.eventId <= _lastAppliedEventId ||
          !event.matches(
            context: context,
            mode: mode,
            current: _configuration,
          )) {
        continue;
      }

      latest = event;
    }

    if (batch.cursor > _cursor) {
      _cursor = batch.cursor;
    }

    if (latest == null) {
      return CustomerPreviewInvalidationOutcome(
        cursor: _cursor,
        retryAfter: batch.retryAfter,
        configuration: _configuration,
        changed: false,
      );
    }

    final resolved = await authoritativeRefetch();
    if (resolved.channel != context.channel ||
        resolved.storeId != context.storeId ||
        resolved.mode != mode) {
      throw const CustomerPreviewInvalidationException(
        'preview_invalidation_refetch_scope_mismatch',
        runtimeState: 'error',
        retryable: false,
      );
    }

    final changed = resolved.revisionId != _configuration.revisionId ||
        resolved.checksum != _configuration.checksum;
    _configuration = resolved;
    _lastAppliedEventId = latest.eventId;

    return CustomerPreviewInvalidationOutcome(
      cursor: _cursor,
      retryAfter: batch.retryAfter,
      configuration: resolved,
      changed: changed,
    );
  }
}
