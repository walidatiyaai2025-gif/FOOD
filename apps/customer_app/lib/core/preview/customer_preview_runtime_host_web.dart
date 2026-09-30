import 'dart:async';
import 'dart:js_interop';

import 'customer_preview_bridge_contract.dart';

const _contractVersion = String.fromEnvironment(
  'FOODEX_PREVIEW_CONTRACT_VERSION',
  defaultValue: 'shared-flutter-v1',
);
const _parentOrigin = String.fromEnvironment('FOODEX_PREVIEW_PARENT_ORIGIN');

@JS('window')
external _PreviewWindow get _window;

extension type _PreviewWindow._(JSObject _) implements JSObject {
  external _PreviewWindow get parent;
  external void addEventListener(String type, JSFunction listener);
  external void removeEventListener(String type, JSFunction listener);
  external void postMessage(JSAny? message, String targetOrigin);
}

extension type _PreviewMessageEvent._(JSObject _) implements JSObject {
  external String get origin;
  external JSObject? get source;
  external JSAny? get data;
}

bool get isEmbeddedCustomerPreviewRuntime =>
    _parentOrigin.isNotEmpty && _window.parent != _window;

Future<CustomerPreviewBootstrap?> waitForCustomerPreviewBootstrap() async {
  if (!isEmbeddedCustomerPreviewRuntime) return null;

  final parent = _window.parent;
  final completer = Completer<CustomerPreviewBootstrap>();

  late JSFunction listener;
  listener = ((JSAny? rawEvent) {
    if (rawEvent is! JSObject) return;

    final event = _PreviewMessageEvent._(rawEvent);
    if (event.source != parent || event.origin != _parentOrigin) {
      return;
    }

    final raw = event.data?.dartify();
    if (raw is! Map) {
      _post(parent, {
        'type': 'foodex.preview.status',
        'state': 'error',
        'code': 'invalid_bootstrap',
      });
      return;
    }

    try {
      final bootstrap = CustomerPreviewBootstrap.fromMessage(
        Map<String, dynamic>.from(raw),
        expectedVersion: _contractVersion,
      );
      if (!completer.isCompleted) {
        completer.complete(bootstrap);
        _window.removeEventListener('message', listener);
      }
    } on FormatException {
      _post(parent, {
        'type': 'foodex.preview.status',
        'state': 'error',
        'code': 'invalid_bootstrap',
      });
    }
  }).toJS;

  _window.addEventListener('message', listener);
  _post(parent, {
    'type': 'foodex.preview.ready',
    'version': _contractVersion,
  });

  return completer.future;
}

void postCustomerPreviewStatus(String state, {String? code}) {
  if (!isEmbeddedCustomerPreviewRuntime) return;

  _post(_window.parent, {
    'type': 'foodex.preview.status',
    'state': state,
    if (code != null) 'code': code,
  });
}

void _post(_PreviewWindow parent, Map<String, Object?> message) {
  parent.postMessage(message.jsify(), _parentOrigin);
}
