class DriverDiagnosticExportFile {
  const DriverDiagnosticExportFile(this.path);

  final String path;
}

String get driverOperatingSystem => 'web';
String get driverOperatingSystemVersion => 'browser';

Future<DriverDiagnosticExportFile> writeDriverDiagnosticExport({
  required String filename,
  required String content,
}) {
  return Future<DriverDiagnosticExportFile>.error(
    UnsupportedError('Driver diagnostics file export is unavailable in Web preview.'),
  );
}
