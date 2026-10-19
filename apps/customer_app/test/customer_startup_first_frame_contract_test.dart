import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

void main() {
  test('Customer startup renders before persisted plugin initialization', () {
    final source = File('lib/main.dart').readAsStringSync();

    final runAppIndex = source.indexOf('runApp(');
    final diagnosticsInitIndex =
        source.indexOf('widget.diagnostics.initialize()');
    final dataModeInitIndex =
        source.indexOf('CustomerDataModeController.instance\n          .initialize()');
    final authRestoreIndex =
        source.indexOf('return await restoreCustomerAuthBootstrap(');

    expect(runAppIndex, greaterThanOrEqualTo(0));
    expect(diagnosticsInitIndex, greaterThan(runAppIndex));
    expect(dataModeInitIndex, greaterThan(runAppIndex));
    expect(authRestoreIndex, greaterThan(runAppIndex));

    expect(source, contains('const _startupTaskTimeout = Duration(seconds: 3);'));
    expect(source, contains('if (!_runtimeReady)'));
    expect(source, contains("AssetImage(\n                  'assets/branding/foodex-economical-group.webp',"));
    expect(source, contains("'session_restore_timeout'"));
    expect(source, contains("'diagnostics_startup_timeout'"));
    expect(source, contains("'data_mode_startup_timeout'"));
    expect(source, contains("'push_bootstrap_timeout'"));
  });
}
