import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/preview/customer_preview_bootstrap.dart';
import 'package:foodex_customer_app/core/preview/customer_preview_viewport.dart';

Map<String, dynamic> _message(Map<String, Object?> device) => {
      'type': 'foodex.preview.bootstrap',
      'version': CustomerPreviewHostContract.version,
      'payload': {
        'context': {
          'session_id': null,
          'target_type': 'customer',
          'channel': 'b2c',
          'store_id': 7,
          'mode': 'read_only',
          'read_only': true,
          'support_access': false,
          'target': null,
        },
        'credential': null,
        'configuration': 'draft',
        'locale': 'ar',
        'device': device,
        'safe_mode': 'read_only',
      },
    };

void main() {
  const origin = 'https://foodex.example';

  test('parses full deterministic iPhone viewport semantics', () {
    final bootstrap = CustomerPreviewBootstrap.parse(
      _message({
        'profile': 'iphone_common',
        'width': 390,
        'height': 844,
        'safe_area': {'top': 59, 'right': 0, 'bottom': 34, 'left': 0},
        'orientation': 'portrait',
        'text_scale': 1.15,
        'view_insets': {'bottom': 280},
      }),
      origin: origin,
      expectedOrigin: origin,
    );

    expect(bootstrap.deviceProfile, 'iphone_common');
    expect(bootstrap.deviceWidth, 390);
    expect(bootstrap.deviceHeight, 844);
    expect(bootstrap.safeAreaTop, 59);
    expect(bootstrap.safeAreaBottom, 34);
    expect(bootstrap.textScale, 1.15);
    expect(bootstrap.viewInsetBottom, 280);
    expect(bootstrap.safeStatusMetadata['device_orientation'], 'portrait');
  });

  test('legacy width-only bootstrap keeps safe deterministic defaults', () {
    final bootstrap = CustomerPreviewBootstrap.parse(
      _message({'profile': 'phone_standard', 'width': 390}),
      origin: origin,
      expectedOrigin: origin,
    );

    expect(bootstrap.deviceHeight, 844);
    expect(bootstrap.orientation, 'portrait');
    expect(bootstrap.textScale, 1);
  });

  test('rejects landscape or impossible viewport geometry', () {
    expect(
      () => CustomerPreviewBootstrap.parse(
        _message({
          'profile': 'bad',
          'width': 844,
          'height': 390,
          'orientation': 'landscape',
        }),
        origin: origin,
        expectedOrigin: origin,
      ),
      throwsA(isA<CustomerPreviewBootstrapException>()),
    );
  });

  testWidgets('viewport applies size safe area text scale and keyboard inset',
      (tester) async {
    final bootstrap = CustomerPreviewBootstrap.parse(
      _message({
        'profile': 'narrow_stress',
        'width': 320,
        'height': 568,
        'safe_area': {'top': 24, 'right': 1, 'bottom': 16, 'left': 2},
        'orientation': 'portrait',
        'text_scale': 1.2,
        'view_insets': {'bottom': 220},
      }),
      origin: origin,
      expectedOrigin: origin,
    );
    MediaQueryData? observed;

    await tester.pumpWidget(
      MaterialApp(
        home: CustomerPreviewViewport(
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

    expect(observed?.size, const Size(320, 568));
    expect(observed?.padding, const EdgeInsets.fromLTRB(2, 24, 1, 16));
    expect(observed?.viewInsets.bottom, 220);
    expect(observed?.textScaler.scale(10), 12);
    expect(observed?.orientation, Orientation.portrait);
  });
}
