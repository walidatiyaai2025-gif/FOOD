// ignore_for_file: avoid_web_libraries_in_flutter, deprecated_member_use

import 'dart:async';
import 'dart:html' as html;
import 'dart:js_interop';

import 'package:flutter/material.dart';

import '../config/foodex_environment.dart';
import 'customer_preview_bootstrap.dart';
import 'customer_preview_runtime.dart';

class CustomerPreviewBrowserHost extends StatefulWidget {
  const CustomerPreviewBrowserHost({super.key});

  @override
  State<CustomerPreviewBrowserHost> createState() =>
      _CustomerPreviewBrowserHostState();
}

class _CustomerPreviewBrowserHostState
    extends State<CustomerPreviewBrowserHost> {
  StreamSubscription<html.MessageEvent>? _messages;
  CustomerPreviewRuntime? _runtime;
  String? _error;

  String get _allowedOrigin =>
      CustomerPreviewHostContract.allowedParentOrigin.trim();

  @override
  void initState() {
    super.initState();
    _messages = html.window.onMessage.listen(_onMessage);

    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (_allowedOrigin.isEmpty) {
        _setError('preview_parent_origin_missing');
        return;
      }
      _post({
        'type': 'foodex.preview.ready',
        'version': CustomerPreviewHostContract.version,
        'target_type': 'customer',
      });
    });
  }

  void _onMessage(html.MessageEvent event) {
    if (!CustomerPreviewHostContract.allowsMessage(
      origin: event.origin,
      expectedOrigin: _allowedOrigin,
      fromParent: event.source == html.window.parent,
    )) {
      return;
    }

    final data = _map(event.data);
    if (data == null || data['type'] != 'foodex.preview.bootstrap') {
      return;
    }

    try {
      final bootstrap = CustomerPreviewBootstrap.parse(
        data,
        origin: event.origin,
        expectedOrigin: _allowedOrigin,
      );
      final next = CustomerPreviewRuntime.create(
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
        'version': CustomerPreviewHostContract.version,
        'state': 'ready',
        'metadata': bootstrap.safeStatusMetadata,
      });
    } on CustomerPreviewBootstrapException catch (error) {
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
        'version': CustomerPreviewHostContract.version,
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
                  ? 'FOODEX Customer preview ready'
                  : 'FOODEX Customer preview unavailable',
              key: ValueKey(
                _error == null
                    ? 'customer-preview-awaiting-bootstrap'
                    : 'customer-preview-error',
              ),
            ),
          ),
        ),
      ),
    );
  }
}
