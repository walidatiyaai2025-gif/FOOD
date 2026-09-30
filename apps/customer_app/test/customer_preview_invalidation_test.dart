import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/auth/customer_session.dart';
import 'package:foodex_customer_app/core/preview/customer_preview_configuration.dart';
import 'package:foodex_customer_app/core/preview/customer_preview_context.dart';
import 'package:foodex_customer_app/core/preview/customer_preview_invalidation.dart';
import 'package:foodex_customer_app/core/preview/customer_preview_transport.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  const apiBase = 'https://api.example';
  const dashboardBase = 'https://dashboard.example';
  const credential = 'opaque-preview-secret';

  test('Guest matching invalidation refetches authoritative Dashboard config',
      () async {
    final requests = <http.Request>[];
    final context = CustomerPreviewContext.guest(
      channel: CustomerChannel.b2c,
      storeId: 7,
    );
    final oldConfiguration = _configuration(
      channel: CustomerChannel.b2c,
      storeId: 7,
      mode: 'draft',
      revisionId: 'old-draft',
      checksum: _checksum('a'),
    );
    final client = MockClient((request) async {
      requests.add(request);
      if (request.url.path == '/admin/app-preview/events') {
        return _sseResponse(
          _eventBlock(
            id: 11,
            channel: 'b2c',
            storeId: 7,
            mode: 'draft',
            revisionId: 'new-draft',
            checksum: _checksum('b'),
          ),
        );
      }
      if (request.url.path ==
          '/admin/app-preview/storefront-configuration') {
        return _configurationResponse(
          channel: 'b2c',
          storeId: 7,
          mode: 'draft',
          revisionId: 'new-draft',
          checksum: _checksum('b'),
        );
      }
      return http.Response('{}', 404);
    });

    final feed = CustomerPreviewInvalidationFeed(
      apiBaseUrl: apiBase,
      dashboardBaseUrl: dashboardBase,
      context: context,
      client: client,
    );
    final coordinator = CustomerPreviewInvalidationCoordinator(
      feed: feed,
      context: context,
      mode: 'draft',
      currentConfiguration: oldConfiguration,
      authoritativeRefetch: () => CustomerPreviewResolvedConfiguration.resolveGuest(
        dashboardBaseUrl: dashboardBase,
        client: client,
        context: context,
        mode: 'draft',
      ),
    );

    final outcome = await coordinator.pollOnce();

    expect(outcome.changed, isTrue);
    expect(outcome.cursor, 11);
    expect(outcome.configuration.revisionId, 'new-draft');
    expect(outcome.configuration.checksum, _checksum('b'));
    expect(requests, hasLength(2));
    expect(requests.first.url.path, '/admin/app-preview/events');
    expect(requests.first.url.queryParameters, {
      'channel': 'b2c',
      'store_id': '7',
    });
    expect(requests.first.headers.containsKey('Authorization'), isFalse);
    expect(
      requests.first.headers.keys
          .map((key) => key.toLowerCase())
          .contains('x-foodex-preview-token'),
      isFalse,
    );
    expect(
      requests.first.url.queryParameters.keys,
      isNot(contains('preview_token')),
    );
    expect(
      requests.last.url.path,
      '/admin/app-preview/storefront-configuration',
    );
    expect(requests.last.url.queryParameters['mode'], 'draft');
  });

  test('Authenticated Customer invalidation stays header-only and refetches',
      () async {
    final requests = <http.Request>[];
    final context = _authenticatedContext(CustomerChannel.b2c, 7);
    final delegate = MockClient((request) async {
      requests.add(request);
      if (request.url.path == '/api/v1/app-preview/events') {
        return _sseResponse(
          _eventBlock(
            id: 41,
            channel: 'b2c',
            storeId: 7,
            mode: 'published',
            revisionId: 'published-41',
            checksum: _checksum('b'),
          ),
        );
      }
      if (request.url.path ==
          '/api/v1/app-preview/storefront-configuration') {
        return _configurationResponse(
          channel: 'b2c',
          storeId: 7,
          mode: 'published',
          revisionId: 'published-41',
          checksum: _checksum('b'),
        );
      }
      return http.Response('{}', 404);
    });
    final transport = CustomerPreviewReadHttpClient(
      delegate,
      credential: credential,
      channel: CustomerChannel.b2c,
    );
    final feed = CustomerPreviewInvalidationFeed(
      apiBaseUrl: apiBase,
      dashboardBaseUrl: dashboardBase,
      context: context,
      client: transport,
    );
    final coordinator = CustomerPreviewInvalidationCoordinator(
      feed: feed,
      context: context,
      mode: 'published',
      currentConfiguration: _configuration(
        channel: CustomerChannel.b2c,
        storeId: 7,
        mode: 'published',
        revisionId: 'published-40',
        checksum: _checksum('a'),
      ),
      initialCursor: 40,
      authoritativeRefetch: () => CustomerPreviewResolvedConfiguration.resolve(
        baseUrl: apiBase,
        client: transport,
        context: context,
        mode: 'published',
      ),
    );

    final outcome = await coordinator.pollOnce();

    expect(outcome.changed, isTrue);
    expect(outcome.cursor, 41);
    expect(outcome.configuration.revisionId, 'published-41');
    expect(requests, hasLength(2));
    for (final request in requests) {
      final headers = {
        for (final entry in request.headers.entries)
          entry.key.toLowerCase(): entry.value,
      };
      expect(headers['x-foodex-preview-token'], credential);
      expect(headers.containsKey('authorization'), isFalse);
      expect(request.url.queryParameters.containsKey('preview_token'), isFalse);
      expect(request.url.queryParameters.containsKey('credential'), isFalse);
    }
    final eventRequest = requests.first;
    expect(eventRequest.headers['Last-Event-ID'], '40');
  });

  test('Store A invalidation never refreshes Store B', () async {
    var refetches = 0;
    final context = CustomerPreviewContext.guest(
      channel: CustomerChannel.b2c,
      storeId: 7,
    );
    final coordinator = _coordinator(
      context: context,
      mode: 'draft',
      response: _eventBlock(
        id: 21,
        channel: 'b2c',
        storeId: 8,
        mode: 'draft',
        revisionId: 'store-b',
        checksum: _checksum('b'),
      ),
      refetch: () async {
        refetches++;
        return _configuration(
          channel: CustomerChannel.b2c,
          storeId: 7,
          mode: 'draft',
          revisionId: 'unexpected',
          checksum: _checksum('c'),
        );
      },
    );

    final outcome = await coordinator.pollOnce();

    expect(outcome.changed, isFalse);
    expect(outcome.cursor, 21);
    expect(refetches, 0);
  });

  test('B2B invalidation never refreshes B2C preview', () async {
    var refetches = 0;
    final context = CustomerPreviewContext.guest(
      channel: CustomerChannel.b2c,
      storeId: 7,
    );
    final coordinator = _coordinator(
      context: context,
      mode: 'published',
      response: _eventBlock(
        id: 22,
        channel: 'b2b',
        storeId: 7,
        mode: 'published',
        revisionId: 'wrong-channel',
        checksum: _checksum('b'),
      ),
      refetch: () async {
        refetches++;
        return _configuration(
          channel: CustomerChannel.b2c,
          storeId: 7,
          mode: 'published',
          revisionId: 'unexpected',
          checksum: _checksum('c'),
        );
      },
    );

    final outcome = await coordinator.pollOnce();

    expect(outcome.changed, isFalse);
    expect(refetches, 0);
  });

  test('Draft invalidation never refreshes Published preview', () async {
    var refetches = 0;
    final context = CustomerPreviewContext.guest(
      channel: CustomerChannel.b2c,
      storeId: 7,
    );
    final coordinator = _coordinator(
      context: context,
      mode: 'published',
      response: _eventBlock(
        id: 23,
        channel: 'b2c',
        storeId: 7,
        mode: 'draft',
        revisionId: 'draft-only',
        checksum: _checksum('b'),
      ),
      refetch: () async {
        refetches++;
        return _configuration(
          channel: CustomerChannel.b2c,
          storeId: 7,
          mode: 'published',
          revisionId: 'unexpected',
          checksum: _checksum('c'),
        );
      },
    );

    final outcome = await coordinator.pollOnce();

    expect(outcome.changed, isFalse);
    expect(refetches, 0);
  });

  test('Stale revision signal is ignored without authoritative refetch',
      () async {
    var refetches = 0;
    final context = CustomerPreviewContext.guest(
      channel: CustomerChannel.b2c,
      storeId: 7,
    );
    final coordinator = CustomerPreviewInvalidationCoordinator(
      feed: CustomerPreviewInvalidationFeed(
        apiBaseUrl: apiBase,
        dashboardBaseUrl: dashboardBase,
        context: context,
        client: MockClient(
          (_) async => _sseResponse(
            _eventBlock(
              id: 24,
              channel: 'b2c',
              storeId: 7,
              mode: 'draft',
              revisionId: 'current-revision',
              checksum: _checksum('a'),
            ),
          ),
        ),
      ),
      context: context,
      mode: 'draft',
      currentConfiguration: _configuration(
        channel: CustomerChannel.b2c,
        storeId: 7,
        mode: 'draft',
        revisionId: 'current-revision',
        checksum: _checksum('a'),
      ),
      authoritativeRefetch: () async {
        refetches++;
        return _configuration(
          channel: CustomerChannel.b2c,
          storeId: 7,
          mode: 'draft',
          revisionId: 'unexpected',
          checksum: _checksum('b'),
        );
      },
    );

    final outcome = await coordinator.pollOnce();

    expect(outcome.changed, isFalse);
    expect(outcome.cursor, 24);
    expect(refetches, 0);
  });

  test('Network loss reports disconnected retryable state', () async {
    final context = CustomerPreviewContext.guest(
      channel: CustomerChannel.b2c,
      storeId: 7,
    );
    final feed = CustomerPreviewInvalidationFeed(
      apiBaseUrl: apiBase,
      dashboardBaseUrl: dashboardBase,
      context: context,
      client: MockClient(
        (_) async => throw http.ClientException('offline'),
      ),
    );

    await expectLater(
      feed.poll(),
      throwsA(
        isA<CustomerPreviewInvalidationException>()
            .having(
              (error) => error.code,
              'code',
              'preview_invalidation_network_error',
            )
            .having(
              (error) => error.runtimeState,
              'state',
              'disconnected',
            )
            .having((error) => error.retryable, 'retryable', isTrue),
      ),
    );
  });

  test('Reconnect carries monotonic cursor and replay is duplicate safe',
      () async {
    final context = CustomerPreviewContext.guest(
      channel: CustomerChannel.b2c,
      storeId: 7,
    );
    final seenCursors = <String?>[];
    var calls = 0;
    var refetches = 0;
    final client = MockClient((request) async {
      seenCursors.add(request.headers['Last-Event-ID']);
      calls++;
      if (calls == 1) {
        return _sseResponse(
          _eventBlock(
            id: 60,
            channel: 'b2c',
            storeId: 7,
            mode: 'draft',
            revisionId: 'draft-60',
            checksum: _checksum('b'),
          ),
        );
      }
      return _sseResponse(
        [
          _eventBlock(
            id: 60,
            channel: 'b2c',
            storeId: 7,
            mode: 'draft',
            revisionId: 'draft-60',
            checksum: _checksum('b'),
          ),
          _eventBlock(
            id: 61,
            channel: 'b2c',
            storeId: 7,
            mode: 'draft',
            revisionId: 'draft-60',
            checksum: _checksum('b'),
          ),
        ].join('\n'),
      );
    });
    final coordinator = CustomerPreviewInvalidationCoordinator(
      feed: CustomerPreviewInvalidationFeed(
        apiBaseUrl: apiBase,
        dashboardBaseUrl: dashboardBase,
        context: context,
        client: client,
      ),
      context: context,
      mode: 'draft',
      currentConfiguration: _configuration(
        channel: CustomerChannel.b2c,
        storeId: 7,
        mode: 'draft',
        revisionId: 'draft-59',
        checksum: _checksum('a'),
      ),
      initialCursor: 59,
      authoritativeRefetch: () async {
        refetches++;
        return _configuration(
          channel: CustomerChannel.b2c,
          storeId: 7,
          mode: 'draft',
          revisionId: 'draft-60',
          checksum: _checksum('b'),
        );
      },
    );

    final first = await coordinator.pollOnce();
    final second = await coordinator.pollOnce();

    expect(first.changed, isTrue);
    expect(first.cursor, 60);
    expect(second.changed, isFalse);
    expect(second.cursor, 61);
    expect(refetches, 1);
    expect(seenCursors, ['59', '60']);
  });

  test('Duplicate event in one stream is applied only once', () async {
    var refetches = 0;
    final context = CustomerPreviewContext.guest(
      channel: CustomerChannel.b2c,
      storeId: 7,
    );
    final block = _eventBlock(
      id: 70,
      channel: 'b2c',
      storeId: 7,
      mode: 'draft',
      revisionId: 'draft-70',
      checksum: _checksum('b'),
    );
    final coordinator = _coordinator(
      context: context,
      mode: 'draft',
      response: '$block\n$block',
      refetch: () async {
        refetches++;
        return _configuration(
          channel: CustomerChannel.b2c,
          storeId: 7,
          mode: 'draft',
          revisionId: 'draft-70',
          checksum: _checksum('b'),
        );
      },
    );

    final outcome = await coordinator.pollOnce();

    expect(outcome.changed, isTrue);
    expect(outcome.cursor, 70);
    expect(refetches, 1);
  });

  test('Revoked or expired authenticated session is explicit and non-retryable',
      () async {
    final context = _authenticatedContext(CustomerChannel.b2c, 7);
    final feed = CustomerPreviewInvalidationFeed(
      apiBaseUrl: apiBase,
      dashboardBaseUrl: dashboardBase,
      context: context,
      client: CustomerPreviewReadHttpClient(
        MockClient((_) async => http.Response('{}', 401)),
        credential: credential,
        channel: CustomerChannel.b2c,
      ),
    );

    await expectLater(
      feed.poll(),
      throwsA(
        isA<CustomerPreviewInvalidationException>()
            .having(
              (error) => error.code,
              'code',
              'preview_invalidation_session_expired',
            )
            .having((error) => error.runtimeState, 'state', 'expired')
            .having((error) => error.retryable, 'retryable', isFalse),
      ),
    );
  });

  test('Forbidden scoped session is explicit and non-retryable', () async {
    final context = _authenticatedContext(CustomerChannel.b2b, 1);
    final feed = CustomerPreviewInvalidationFeed(
      apiBaseUrl: apiBase,
      dashboardBaseUrl: dashboardBase,
      context: context,
      client: CustomerPreviewReadHttpClient(
        MockClient((_) async => http.Response('{}', 403)),
        credential: credential,
        channel: CustomerChannel.b2b,
      ),
    );

    await expectLater(
      feed.poll(),
      throwsA(
        isA<CustomerPreviewInvalidationException>()
            .having(
              (error) => error.code,
              'code',
              'preview_invalidation_forbidden',
            )
            .having((error) => error.runtimeState, 'state', 'forbidden')
            .having((error) => error.retryable, 'retryable', isFalse),
      ),
    );
  });

  test('Event payload rejects business data PII and credential fields',
      () async {
    final context = CustomerPreviewContext.guest(
      channel: CustomerChannel.b2c,
      storeId: 7,
    );

    for (final forbidden in ['payload', 'email', 'token', 'authorization']) {
      final data = _eventData(
        id: 80,
        channel: 'b2c',
        storeId: 7,
        mode: 'draft',
        revisionId: 'draft-80',
        checksum: _checksum('b'),
      )..[forbidden] = forbidden == 'payload'
          ? {'price': 99}
          : 'must-not-cross-the-stream';

      final feed = CustomerPreviewInvalidationFeed(
        apiBaseUrl: apiBase,
        dashboardBaseUrl: dashboardBase,
        context: context,
        client: MockClient(
          (_) async => _sseResponse(
            'id: 80\n'
            'event: app.preview.configuration.updated\n'
            'data: ${jsonEncode(data)}\n\n',
          ),
        ),
      );

      await expectLater(
        feed.poll(),
        throwsA(
          isA<CustomerPreviewInvalidationException>().having(
            (error) => error.code,
            'code',
            'preview_invalidation_payload_rejected',
          ),
        ),
        reason: 'forbidden event field: $forbidden',
      );
    }
  });

  test('Authoritative refetch result is the only source of runtime truth',
      () async {
    final context = CustomerPreviewContext.guest(
      channel: CustomerChannel.b2c,
      storeId: 7,
    );
    final coordinator = _coordinator(
      context: context,
      mode: 'draft',
      response: _eventBlock(
        id: 90,
        channel: 'b2c',
        storeId: 7,
        mode: 'draft',
        revisionId: 'event-claims-this-revision',
        checksum: _checksum('b'),
      ),
      refetch: () async => _configuration(
        channel: CustomerChannel.b2c,
        storeId: 7,
        mode: 'draft',
        revisionId: 'authoritative-server-revision',
        checksum: _checksum('c'),
      ),
    );

    final outcome = await coordinator.pollOnce();

    expect(outcome.changed, isTrue);
    expect(
      outcome.configuration.revisionId,
      'authoritative-server-revision',
    );
    expect(outcome.configuration.checksum, _checksum('c'));
    expect(
      outcome.configuration.revisionId,
      isNot('event-claims-this-revision'),
    );
  });

  test('Browser host cancels old invalidation before replacing runtime', () async {
    final source = await File(
      'lib/core/preview/customer_preview_browser_host.dart',
    ).readAsString();

    final stop = source.indexOf('_stopInvalidation();');
    final previous = source.indexOf('final previous = _runtime;');
    final close = source.indexOf('previous?.close();');

    expect(stop, greaterThanOrEqualTo(0));
    expect(previous, greaterThan(stop));
    expect(close, greaterThan(previous));
    expect(source, contains('_invalidationTimer?.cancel();'));
    expect(source, contains('_runtime?.close();'));
  });

  test('Invalidation client source never stores logs or URL-embeds credential',
      () async {
    final source = [
      await File(
        'lib/core/preview/customer_preview_invalidation.dart',
      ).readAsString(),
      await File(
        'lib/core/preview/customer_preview_browser_host.dart',
      ).readAsString(),
    ].join('\n');

    for (final forbidden in [
      'localStorage',
      'sessionStorage',
      'console.log',
      'preview_token=',
      'credential=',
      'Authorization: Bearer',
    ]) {
      expect(source, isNot(contains(forbidden)));
    }
  });
}

CustomerPreviewInvalidationCoordinator _coordinator({
  required CustomerPreviewContext context,
  required String mode,
  required String response,
  required Future<CustomerPreviewResolvedConfiguration> Function() refetch,
}) {
  return CustomerPreviewInvalidationCoordinator(
    feed: CustomerPreviewInvalidationFeed(
      apiBaseUrl: 'https://api.example',
      dashboardBaseUrl: 'https://dashboard.example',
      context: context,
      client: MockClient((_) async => _sseResponse(response)),
    ),
    context: context,
    mode: mode,
    currentConfiguration: _configuration(
      channel: context.channel,
      storeId: context.storeId,
      mode: mode,
      revisionId: 'current-revision',
      checksum: _checksum('a'),
    ),
    authoritativeRefetch: refetch,
  );
}

CustomerPreviewContext _authenticatedContext(
  CustomerChannel channel,
  int storeId,
) =>
    CustomerPreviewContext.fromResolvedSession({
      'session_id': 'preview-session',
      'target_type': 'customer',
      'channel': channel.name,
      'store_id': storeId,
      'read_only': true,
      'support_access': false,
      'target': {
        'user_id': 44,
        'name': 'Preview Customer',
        'locale': 'en',
      },
    });

CustomerPreviewResolvedConfiguration _configuration({
  required CustomerChannel channel,
  required int storeId,
  required String mode,
  required String revisionId,
  required String checksum,
}) =>
    CustomerPreviewResolvedConfiguration(
      revisionId: revisionId,
      checksum: checksum,
      schemaVersion: 1,
      mode: mode,
      channel: channel,
      storeId: storeId,
      payload: {
        'schema_version': 1,
        'channel': channel.name,
        'store': {
          'id': storeId,
          'code': 'STORE-$storeId',
          'name': 'Store $storeId',
          'is_active': true,
        },
        'settings': {
          'theme_code':
              channel == CustomerChannel.b2b ? 'wholesale_b2b' : 'retail_grocery',
          'primary_color': '#176B3A',
          'branding': <String, Object?>{},
        },
        'sections': const <Object>[],
        'banners': const <Object>[],
      },
    );

http.Response _configurationResponse({
  required String channel,
  required int storeId,
  required String mode,
  required String revisionId,
  required String checksum,
}) =>
    http.Response(
      jsonEncode({
        'data': {
          'revision_id': revisionId,
          'checksum': checksum,
          'schema_version': 1,
          'mode': mode,
          'status': mode,
          'channel': channel,
          'store_id': storeId,
          'read_only': true,
          'payload': {
            'schema_version': 1,
            'channel': channel,
            'store': {
              'id': storeId,
              'code': 'STORE-$storeId',
              'name': 'Authoritative Store',
              'is_active': true,
            },
            'settings': {
              'theme_code':
                  channel == 'b2b' ? 'wholesale_b2b' : 'retail_grocery',
              'primary_color': '#445566',
              'branding': <String, Object?>{},
            },
            'sections': const <Object>[],
            'banners': const <Object>[],
          },
        },
      }),
      200,
      headers: const {'content-type': 'application/json'},
    );

http.Response _sseResponse(String body) => http.Response(
      'retry: 3000\n\n$body: heartbeat test\n\n',
      200,
      headers: const {'content-type': 'text/event-stream; charset=UTF-8'},
    );

String _eventBlock({
  required int id,
  required String channel,
  required int storeId,
  required String mode,
  required String revisionId,
  required String checksum,
}) =>
    'id: $id\n'
    'event: app.preview.configuration.updated\n'
    'data: ${jsonEncode(_eventData(
      id: id,
      channel: channel,
      storeId: storeId,
      mode: mode,
      revisionId: revisionId,
      checksum: checksum,
    ))}\n\n';

Map<String, Object?> _eventData({
  required int id,
  required String channel,
  required int storeId,
  required String mode,
  required String revisionId,
  required String checksum,
}) =>
    {
      'event_id': id,
      'event': 'app.preview.configuration.updated',
      'channel': channel,
      'store_id': storeId,
      'revision_id': revisionId,
      'revision_status': mode,
      'checksum': checksum,
      'schema_version': 1,
      'occurred_at': '2026-09-30T10:00:00Z',
    };

String _checksum(String character) => List.filled(64, character).join();
