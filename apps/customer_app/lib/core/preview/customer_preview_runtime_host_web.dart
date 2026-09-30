// ignore_for_file: avoid_web_libraries_in_flutter

import 'dart:async';
import 'dart:html' as html;

import 'customer_preview_bridge_contract.dart';

const _contractVersion = String.fromEnvironment(
  'FOODEX_PREVIEW_CONTRACT_VERSION',
  defaultValue: 'shared-flutter-v1',
);
const _parentOrigin = String.fromEnvironment('FOODEX_PREVIEW_PARENT_ORIGIN');

bool get isEmbeddedCustomerPreviewRuntime =>
    _parentOrigin.isNotEmpty && html.window.parent != html.window;

Future<CustomerPreviewBootstrap?> waitForCustomerPreviewBootstrap() async {
  if (!isEmbeddedCustomerPreviewRuntime) return null;

  final parent = html.window.parent;
  if (parent == null) return null;

  final completer = Completer<CustomerPreviewBootstrap>();
  late StreamSubscription<html.MessageEvent> subscription;

  subscription = html.window.onMessage.listen((event) {
    if (event.source != parent || event.origin != _parentOrigin) {
      return;
    }

    final raw = event.data;
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
        unawaited(subscription.cancel());
      }
    } on FormatException {
      _post(parent, {
        'type': 'foodex.preview.status',
        'state': 'error',
        'code': 'invalid_bootstrap',
      });
    }
  });

  _post(parent, {
    'type': 'foodex.preview.ready',
    'version': _contractVersion,
  });

  return completer.future;
}

void postCustomerPreviewStatus(String state, {String? code}) {
  if (!isEmbeddedCustomerPreviewRuntime) return;
  final parent = html.window.parent;
  if (parent == null) return;

  _post(parent, {
    'type': 'foodex.preview.status',
    'state': state,
    if (code != null) 'code': code,
  });
}

void _post(html.WindowBase parent, Map<String, Object?> message) {
  parent.postMessage(message, _parentOrigin);
}
