// ignore_for_file: avoid_web_libraries_in_flutter, deprecated_member_use

import 'dart:js_interop';

import 'package:flutter/material.dart';

import 'driver_preview_bootstrap.dart';
import 'driver_preview_runtime.dart';

@JS('window')
external _PreviewWindow get _previewWindow;

extension type _PreviewWindow(JSObject _) implements JSObject {
  external _PreviewWindow get parent;
  external _PreviewLocation get location;
  external void addEventListener(String type, JSFunction listener);
  external void removeEventListener(String type, JSFunction listener);
  external void postMessage(JSAny? message, String targetOrigin);
}

extension type _PreviewLocation(JSObject _) implements JSObject {
  external String get origin;
}

extension type _PreviewMessageEvent(JSObject _) implements JSObject {
  external String get origin;
  external JSAny? get source;
  external JSAny? get data;
}

class DriverPreviewBrowserHost extends StatefulWidget {
  const DriverPreviewBrowserHost({super.key});

  @override
  State<DriverPreviewBrowserHost> createState() =>
      _DriverPreviewBrowserHostState();
}

class _DriverPreviewBrowserHostState extends State<DriverPreviewBrowserHost> {
  JSFunction? _messageListener;
  DriverPreviewRuntime? _runtime;
  String? _error;

  static const _configuredApiBaseUrl = String.fromEnvironment(
    'FOODEX_PREVIEW_API_BASE_URL',
    defaultValue: '',
  );

  String get _runtimeOrigin => _previewWindow.location.origin.trim();

  String get _allowedOrigin {
    final configured = DriverPreviewHostContract.allowedParentOrigin.trim();
    return configured.isNotEmpty ? configured : _runtimeOrigin;
  }

  String get _apiBaseUrl {
    final configured = _configuredApiBaseUrl.trim();
    return configured.isNotEmpty ? configured : _runtimeOrigin;
  }

  @override
  void initState() {
    super.initState();
    _messageListener = ((JSObject rawEvent) {
      _onMessage(_PreviewMessageEvent(rawEvent));
    }).toJS;
    _previewWindow.addEventListener('message', _messageListener!);

    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (_allowedOrigin.isEmpty) {
        _setError('preview_parent_origin_missing');
        return;
      }
      _post({
        'type': 'foodex.preview.ready',
        'version': DriverPreviewHostContract.version,
        'target_type': 'driver',
      });
    });
  }

  void _onMessage(_PreviewMessageEvent event) {
    final source = event.source;
    final fromParent = source != null &&
        source.strictEquals(_previewWindow.parent).toDart;
    if (!DriverPreviewHostContract.allowsMessage(
      origin: event.origin,
      expectedOrigin: _allowedOrigin,
      fromParent: fromParent,
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
      final loadedAt = DateTime.now().toUtc().toIso8601String();
      final next = DriverPreviewRuntime.create(
        baseUrl: _apiBaseUrl,
        bootstrap: bootstrap,
        onReadState: (state, endpoint, statusCode, updatedAt) {
          if (!mounted || attempt != _bootstrapAttempt) return;
          final code = state == 'ready'
              ? null
              : (state == 'disconnected'
                  ? 'preview_driver_read_disconnected'
                  : 'preview_driver_read_http_${statusCode ?? 0}');
          _postStatus(
            state,
            code: code,
            retryable: state == 'disconnected' || state == 'error',
            metadata: {
              ...bootstrap.safeStatusMetadata,
              'loaded_at': loadedAt,
              'updated_at': updatedAt,
              'last_api_endpoint': endpoint,
              if (statusCode != null) 'last_api_status': statusCode,
            },
          );
        },
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

      _postStatus(
        'ready',
        metadata: {
          ...bootstrap.safeStatusMetadata,
          'loaded_at': loadedAt,
          'updated_at': loadedAt,
        },
      );
    } on DriverPreviewBootstrapException catch (error) {
      _setError(error.code);
    } catch (_) {
      _setError('preview_bootstrap_failed');
    }
  }

  void _postStatus(
    String state, {
    String? code,
    bool? retryable,
    Map<String, Object?>? metadata,
  }) {
    if (_allowedOrigin.isEmpty) return;
    _post({
      'type': 'foodex.preview.status',
      'version': DriverPreviewHostContract.version,
      'state': state,
      if (code != null) 'code': code,
      if (retryable != null) 'retryable': retryable,
      if (metadata != null) 'metadata': metadata,
    });
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
      _postStatus('error', code: code, retryable: false);
    }
  }

  void _post(Map<String, Object?> message) {
    _previewWindow.parent.postMessage(
      message.jsify(),
      _allowedOrigin,
    );
  }

  @override
  void dispose() {
    final listener = _messageListener;
    if (listener != null) {
      _previewWindow.removeEventListener('message', listener);
      _messageListener = null;
    }
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
