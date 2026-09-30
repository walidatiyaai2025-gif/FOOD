import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_driver_app/core/preview/driver_preview_bootstrap.dart';
import 'package:foodex_driver_app/core/preview/driver_preview_viewport.dart';

Map<String, dynamic> _message(Map<String, Object?> device) => {
      'type': 'foodex.preview.bootstrap',
      'version': DriverPreviewHostContract.version,
      'payload': {
        'context': {
          'session_id': 'preview-session',
          'target_type': 'driver',
          'channel': 'b2c',
          'store_id': 41,
          'mode': 'read_only',
          'read_only': true,
          'target': {
            'user_id': 17,
            'driver_id': 23,
            'name': 'Preview Driver',
            'locale': 'en',
          },
        },
        'credential': 'preview-secret',
        'configuration': 'published',
        'locale': 'en',
        'device': device,
        'safe_mode': 'read_only',
      },
    };

void main() {
  const origin = 'https://foodex.example';

  test('driver parser carries full viewport semantics without leaking credential', () {
    final bootstrap = DriverPreviewBootstrap.parse(
      _message({
        'profile': 'android_large',
        'width': 430,
        'height': 932,
        'safe_area': {'top': 24, 'right': 0, 'bottom': 24, 'left': 0},
        'orientation': 'portrait',
        'text_scale': 1.1,
        'view_insets': {'bottom': 300},
      }),
      origin: origin,
      expectedOrigin: origin,
    );

    expect(bootstrap.deviceWidth, 430);
    expect(bootstrap.deviceHeight, 932);
    expect(bootstrap.textScale, 1.1);
    expect(bootstrap.viewInsetBottom, 300);
    expect(bootstrap.safeStatusMetadata.values, isNot(contains('preview-secret')));
  });

  test('driver rejects invalid text scale', () {
    expect(
      () => DriverPreviewBootstrap.parse(
        _message({
          'profile': 'bad-scale',
          'width': 390,
          'height': 844,
          'orientation': 'portrait',
          'text_scale': 3.0,
        }),
        origin: origin,
        expectedOrigin: origin,
      ),
      throwsA(isA<DriverPreviewBootstrapException>()),
    );
  });

  testWidgets('driver viewport applies deterministic MediaQuery semantics',
      (tester) async {
    final bootstrap = DriverPreviewBootstrap.parse(
      _message({
        'profile': 'iphone_common',
        'width': 390,
        'height': 844,
        'safe_area': {'top': 59, 'right': 0, 'bottom': 34, 'left': 0},
        'orientation': 'portrait',
        'text_scale': 1.0,
        'view_insets': {'bottom': 0},
      }),
      origin: origin,
      expectedOrigin: origin,
    );
    MediaQueryData? observed;

    await tester.pumpWidget(
      MaterialApp(
        home: DriverPreviewViewport(
          bootstrap: bootstrap,
          child: Builder(
            builder: (context) {
              observed = MediaQuery.of(context);
              return const SizedBox();
            },
          ),
        ),
      ),
    );

    expect(observed?.size, const Size(390, 844));
    expect(observed?.padding.top, 59);
    expect(observed?.padding.bottom, 34);
    expect(observed?.orientation, Orientation.portrait);
  });
}
