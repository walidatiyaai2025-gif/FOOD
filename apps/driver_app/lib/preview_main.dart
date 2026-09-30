import 'package:flutter/widgets.dart';

import 'core/diagnostics/driver_runtime_inspector.dart';
import 'core/preview/driver_preview_browser_host.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  await DriverRuntimeInspector.instance.initialize();
  runApp(const DriverPreviewBrowserHost());
}
