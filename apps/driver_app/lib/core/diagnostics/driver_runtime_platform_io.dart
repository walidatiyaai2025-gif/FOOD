import 'dart:io';

class DriverDiagnosticExportFile {
  const DriverDiagnosticExportFile(this.path);

  final String path;
}

String get driverOperatingSystem => Platform.operatingSystem;
String get driverOperatingSystemVersion => Platform.operatingSystemVersion;

Future<DriverDiagnosticExportFile> writeDriverDiagnosticExport({
  required String filename,
  required String content,
}) async {
  final file = File('${Directory.systemTemp.path}/$filename');
  await file.writeAsString(content, flush: true);
  return DriverDiagnosticExportFile(file.path);
}
