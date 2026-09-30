// ignore_for_file: avoid_web_libraries_in_flutter, deprecated_member_use

import 'dart:async';
import 'dart:html' as html;
import 'dart:js_interop';

import 'package:flutter/material.dart';

import '../config/foodex_environment.dart';
import 'driver_preview_bootstrap.dart';
import 'driver_preview_runtime.dart';

class DriverPreviewBrowserHost extends StatefulWidget {
  const DriverPreviewBrowserHost({super.key});

  @override
  State<DriverPreviewBrowserHost> createState() =>
      _DriverPreviewBrowserHostState();
}

class _DriverPreviewBrowserHostState extends State<DriverPreviewBrowserHost> {
  StreamSubscription<html.MessageEvent>? _messages;
  DriverPreviewRuntime? _runtime;
  String? _error;

  String get _allowedOrigin =>
      DriverPreviewHostContract.allowedParentOrigin.trim();

  @override
  void initState() {
    super.initState();
    _messages = html.window.onMessage.listen(_onMessage);

    if (_allowedOrigin.isEmpty) {
      _setError('preview_parent_origin_missing');
      return;
    }
    _post({
      'type': 'foodex.preview.ready',
      'version': DriverPreviewHostContract.version,
      'target_type': 'driver',
    });
  }

  void _onMessage(html.MessageEvent event) {
    final topLevel = html.window.parent == html.window;
    if (!DriverPreviewHostContract.allowsMessage(
      origin: event.origin,
      expectedOrigin: _allowedOrigin,
      fromParent: topLevel || event.source == html.window.parent,
    )) {
      return;
    }

    final data = _map(event.data);
    if (data == null || data['type'] != 'foodex.preview.bootstrap') {
      return;
    }

    try {
      final bootstrap = DriverPreviewBootstrap.parse(
        data,
        origin: event.origin,
        expectedOrigin: _allowedOrigin,
      );
      final next = DriverPreviewRuntime.create(
        baseUrl: FoodexEnvironment.apiBaseUrl,
        bootstrap: bootstrap,
      );

      final previous = _runtime;
      if (!mounted) {
        next.close();
        return;
      }

      setState(() {
        _runtime = next;
        _error = null;
      });
      previous?.close();

      _post({
        'type': 'foodex.preview.status',
        'version': DriverPreviewHostContract.version,
        'state': 'ready',
        'metadata': bootstrap.safeStatusMetadata,
      });
    } on DriverPreviewBootstrapException catch (error) {
      _setError(error.code);
    } catch (_) {
      _setError('preview_bootstrap_failed');
    }
  }

  Map<String, dynamic>? _map(JSAny? value) {
    try {
      final converted = value.dartify();
      if (converted is Map) {
        return Map<String, dynamic>.from(converted);
      }
    } catch (_) {
      return null;
    }

    return null;
  }

  void _setError(String code) {
    if (mounted) {
      setState(() {
        _error = code;
      });
    }
    if (_allowedOrigin.isNotEmpty) {
      _post({
        'type': 'foodex.preview.status',
        'version': DriverPreviewHostContract.version,
        'state': 'error',
        'code': code,
      });
    }
  }

  void _post(Map<String, Object?> message) {
    html.window.parent?.postMessage(
      message.jsify(),
      _allowedOrigin,
    );
  }

  @override
  void dispose() {
    unawaited(_messages?.cancel());
    _runtime?.close();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final runtime = _runtime;
    if (runtime != null) {
      return runtime.app;
    }

    return MaterialApp(
      debugShowCheckedModeBanner: false,
      home: Scaffold(
        body: Center(
          child: Semantics(
            liveRegion: true,
            child: Text(
              _error == null
                  ? 'FOODEX Driver preview ready'
                  : 'FOODEX Driver preview unavailable',
              key: ValueKey(
                _error == null
                    ? 'driver-preview-awaiting-bootstrap'
                    : 'driver-preview-error',
              ),
            ),
          ),
        ),
      ),
    );
  }
}
