import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;

import '../../app.dart';
import '../../navigation.dart';
import 'driver_preview_bootstrap.dart';
import 'driver_preview_transport.dart';

class DriverPreviewRuntime {
  DriverPreviewRuntime._({
    required this.app,
    required this.bundle,
  });

  final Widget app;
  final DriverPreviewAssignmentBundle bundle;

  void close() => bundle.close();

  static DriverPreviewRuntime create({
    required String baseUrl,
    required DriverPreviewBootstrap bootstrap,
    http.Client? client,
    void Function(
      String state,
      String endpoint,
      int? statusCode,
      String updatedAt,
    )? onReadState,
  }) {
    if (baseUrl.trim().isEmpty) {
      throw const DriverPreviewBootstrapException('preview_api_base_missing');
    }

    final bundle = DriverPreviewAssignmentBundle(
      baseUrl: baseUrl,
      credential: bootstrap.credential,
      client: client,
      onReadState: onReadState,
    );
    final channel = bootstrap.context.channel;
    final initialRoute = channel == DriverChannel.b2c
        ? DriverRoutes.b2cHome
        : DriverRoutes.b2bHome;

    final app = FoodexDriverApp.preview(
      previewContext: bootstrap.context,
      assignmentRepository: bundle.assignments,
      initialRoute: initialRoute,
      locale: Locale(bootstrap.locale),
      previewBootstrap: bootstrap,
    );

    return DriverPreviewRuntime._(
      app: app,
      bundle: bundle,
    );
  }
}
