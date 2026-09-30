import 'dart:collection';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_driver_app/app.dart';
import 'package:foodex_driver_app/core/auth/driver_session.dart';
import 'package:foodex_driver_app/core/localization/driver_translations.dart';
import 'package:foodex_driver_app/core/preview/driver_preview_context.dart';
import 'package:foodex_driver_app/core/version/driver_version_policy_client.dart';
import 'package:foodex_driver_app/features/tasks/driver_journey.dart';
import 'package:foodex_driver_app/features/version/driver_version_policy_gate.dart';
import 'package:foodex_driver_app/navigation.dart';
import 'package:foodex_driver_app/version_policy.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

class _FakeVersionClient implements DriverVersionPolicyClient {
  _FakeVersionClient(Iterable<Object> results)
      : results = Queue<Object>.of(results);

  final Queue<Object> results;
  int calls = 0;

  @override
  Future<AppVersionPolicy> fetch() async {
    calls++;
    final result = results.removeFirst();
    if (result is AppVersionPolicy) return result;
    throw result;
  }
}

class _EmptyAssignments implements DriverAssignmentRepository {
  @override
  Future<List<DriverAssignment>> list(DriverChannel channel) async => const [];

  @override
  Future<void> transition(
    int id,
    DriverChannel channel,
    String status, {
    String? note,
    String? failureReason,
  }) async {}
}

AppVersionPolicy _policy(
  AppUpdateStatus status, {
  bool forceUpdate = false,
}) =>
    AppVersionPolicy(
      latestVersion: '1.0.40',
      minimumSupportedVersion: '1.0.38',
      forceUpdate: forceUpdate,
      updateRequired: status != AppUpdateStatus.current,
      status: status,
      storeUrl: Uri.parse('https://example.test/driver-update'),
      releaseNotes: 'Driver tracking rollout',
    );

Widget _gate(DriverVersionPolicyClient client, {Widget? child}) {
  return MaterialApp(
    locale: const Locale('en'),
    home: DriverTranslations(
      locale: const Locale('en'),
      overrides: const {},
      child: DriverVersionPolicyGate(
        client: client,
        child: child ?? const Text('DRIVER-RUNTIME', key: Key('runtime')),
      ),
    ),
  );
}

void main() {
  test('HTTP policy client uses authoritative Driver endpoint contract',
      () async {
    final client = HttpDriverVersionPolicyClient(
      baseUrl: 'https://foodex.example.test',
      platform: 'android',
      currentVersion: '1.0.37',
      client: MockClient((request) async {
        expect(request.method, 'GET');
        expect(request.url.path, '/api/v1/app-version');
        expect(request.url.queryParameters, {
          'app': 'driver',
          'platform': 'android',
          'current_version': '1.0.37',
        });
        expect(request.headers['Accept'], 'application/json');
        expect(request.headers.containsKey('Authorization'), isFalse);

        return http.Response(
          jsonEncode({
            'latest_version': '1.0.40',
            'minimum_supported_version': '1.0.38',
            'force_update': true,
            'update_required': true,
            'status': 'unsupported',
            'store_url': 'https://example.test/driver-update',
            'release_notes': 'Tracking build required',
          }),
          200,
        );
      }),
    );

    final policy = await client.fetch();
    expect(policy.status, AppUpdateStatus.unsupported);
    expect(policy.blocksApp, isTrue);
    expect(policy.latestVersion, '1.0.40');
    client.close();
  });

  test('HTTP policy client does not manufacture current policy on failure',
      () async {
    final client = HttpDriverVersionPolicyClient(
      baseUrl: 'https://foodex.example.test',
      platform: 'ios',
      client: MockClient((_) async => http.Response('{}', 503)),
    );

    await expectLater(
      client.fetch(),
      throwsA(
        isA<DriverVersionPolicyException>()
            .having((error) => error.statusCode, 'statusCode', 503),
      ),
    );
    client.close();
  });

  testWidgets('forced and unsupported policies block runtime and open store URL',
      (tester) async {
    for (final status in [
      AppUpdateStatus.forced,
      AppUpdateStatus.unsupported,
    ]) {
      final client = _FakeVersionClient([
        _policy(status, forceUpdate: true),
      ]);
      Uri? launched;

      await tester.pumpWidget(
        MaterialApp(
          locale: const Locale('en'),
          home: DriverTranslations(
            locale: const Locale('en'),
            overrides: const {},
            child: DriverVersionPolicyGate(
              client: client,
              updateLauncher: (uri) async {
                launched = uri;
                return true;
              },
              child: const Text('DRIVER-RUNTIME', key: Key('runtime')),
            ),
          ),
        ),
      );
      await tester.pumpAndSettle();

      expect(find.byKey(const Key('driver-version-blocking')), findsOneWidget);
      expect(find.byKey(const Key('runtime')), findsNothing);
      expect(find.text('1.0.38'), findsNWidgets(2));
      expect(find.text('1.0.40'), findsOneWidget);

      await tester.tap(find.byKey(const Key('driver-version-update-now')));
      await tester.pump();
      expect(launched, Uri.parse('https://example.test/driver-update'));
    }
  });

  testWidgets('optional update is non-blocking and current policy proceeds',
      (tester) async {
    await tester.pumpWidget(
      _gate(_FakeVersionClient([_policy(AppUpdateStatus.optional)])),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('driver-version-optional')), findsOneWidget);
    expect(find.byKey(const Key('runtime')), findsOneWidget);

    await tester.pumpWidget(
      _gate(_FakeVersionClient([_policy(AppUpdateStatus.current)])),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('driver-version-blocking')), findsNothing);
    expect(find.byKey(const Key('driver-version-optional')), findsNothing);
    expect(find.byKey(const Key('runtime')), findsOneWidget);
  });

  testWidgets('policy failure blocks by default and retry can recover',
      (tester) async {
    final client = _FakeVersionClient([
      const DriverVersionPolicyOfflineException(),
      _policy(AppUpdateStatus.current),
    ]);

    await tester.pumpWidget(_gate(client));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('driver-version-error')), findsOneWidget);
    expect(find.byKey(const Key('runtime')), findsNothing);

    await tester.tap(find.byKey(const Key('driver-version-retry')));
    await tester.pumpAndSettle();

    expect(client.calls, 2);
    expect(find.byKey(const Key('runtime')), findsOneWidget);
  });


  testWidgets('Arabic blocking update surface is RTL and localized',
      (tester) async {
    final client = _FakeVersionClient([
      _policy(AppUpdateStatus.forced, forceUpdate: true),
    ]);

    await tester.pumpWidget(
      MaterialApp(
        locale: const Locale('ar'),
        localizationsDelegates: const [
          GlobalMaterialLocalizations.delegate,
          GlobalWidgetsLocalizations.delegate,
          GlobalCupertinoLocalizations.delegate,
        ],
        supportedLocales: const [Locale('ar'), Locale('en')],
        home: DriverTranslations(
          locale: const Locale('ar'),
          overrides: const {},
          child: DriverVersionPolicyGate(
            client: client,
            child: const Text('DRIVER-RUNTIME', key: Key('runtime')),
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    final blocking = find.byKey(const Key('driver-version-blocking'));
    expect(blocking, findsOneWidget);
    expect(find.text('يجب تحديث تطبيق السائق'), findsOneWidget);
    expect(find.text('تحديث التطبيق'), findsOneWidget);
    expect(
      Directionality.of(tester.element(blocking)),
      TextDirection.rtl,
    );
    expect(find.byKey(const Key('runtime')), findsNothing);
  });

  testWidgets('direct authenticated Home cannot bypass forced update',
      (tester) async {
    final session = DriverSession(
      token: 'driver-token',
      name: 'Driver',
      email: 'driver@example.test',
      locale: 'en',
      channel: DriverChannel.b2c,
    );

    await tester.pumpWidget(
      FoodexDriverApp(
        locale: const Locale('en'),
        initialRoute: DriverRoutes.b2cHome,
        initialSession: session,
        assignmentRepositoryFactory: (_) => _EmptyAssignments(),
        versionPolicyClient: _FakeVersionClient([
          _policy(AppUpdateStatus.forced, forceUpdate: true),
        ]),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('driver-version-blocking')), findsOneWidget);
    expect(find.text('Driver Home'), findsNothing);
  });

  testWidgets('preview runtime is exempt from production version policy',
      (tester) async {
    final client = _FakeVersionClient([
      StateError('preview must not fetch production version policy'),
    ]);

    await tester.pumpWidget(
      FoodexDriverApp(
        locale: const Locale('en'),
        initialRoute: DriverRoutes.b2cHome,
        initialSession: const DriverPreviewContext(
          channel: DriverChannel.b2c,
          storeId: 41,
          targetLocale: 'en',
        ).runtimeIdentity,
        assignmentRepositoryFactory: (_) => _EmptyAssignments(),
        previewContext: const DriverPreviewContext(
          channel: DriverChannel.b2c,
          storeId: 41,
          targetLocale: 'en',
        ),
        versionPolicyClient: client,
      ),
    );
    await tester.pumpAndSettle();

    expect(client.calls, 0);
    expect(find.byKey(const Key('driver-version-error')), findsNothing);
    expect(find.byKey(const Key('driver-version-blocking')), findsNothing);
    expect(find.textContaining('Safe preview'), findsOneWidget);
  });
}
