// ignore_for_file: avoid_web_libraries_in_flutter, deprecated_member_use

import 'dart:async';
import 'dart:html' as html;
import 'dart:js_interop';

import 'package:flutter/material.dart';

import '../config/foodex_environment.dart';
import 'customer_preview_bootstrap.dart';
import 'customer_preview_configuration.dart';
import 'customer_preview_invalidation.dart';
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
  CustomerPreviewInvalidationCoordinator? _invalidation;
  Timer? _invalidationTimer;
  String? _error;
  int _bootstrapAttempt = 0;
  int _invalidationGeneration = 0;

  String get _allowedOrigin =>
      CustomerPreviewHostContract.allowedParentOrigin.trim();

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
      'version': CustomerPreviewHostContract.version,
      'target_type': 'customer',
    });
  }

  void _onMessage(html.MessageEvent event) async {
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

    final attempt = ++_bootstrapAttempt;

    try {
      final bootstrap = CustomerPreviewBootstrap.parse(
        data,
        origin: event.origin,
        expectedOrigin: _allowedOrigin,
      );

      _stopInvalidation();
      final previous = _runtime;
      if (mounted) {
        setState(() {
          _runtime = null;
          _error = null;
        });
      }
      previous?.close();

      final next = await CustomerPreviewRuntime.create(
        baseUrl: FoodexEnvironment.apiBaseUrl,
        dashboardBaseUrl: _allowedOrigin,
        bootstrap: bootstrap,
      );

      if (!mounted || attempt != _bootstrapAttempt) {
        next.close();
        return;
      }

      setState(() {
        _runtime = next;
        _error = null;
      });

      _postStatus(
        'ready',
        metadata: next.safeStatusMetadata,
      );
      _startInvalidation(next);
    } on CustomerPreviewBootstrapException catch (error) {
      if (attempt == _bootstrapAttempt) {
        _setError(error.code);
      }
    } on CustomerPreviewConfigurationException catch (error) {
      if (attempt == _bootstrapAttempt) {
        _setError(error.code, state: error.runtimeState);
      }
    } catch (_) {
      if (attempt == _bootstrapAttempt) {
        _setError('preview_bootstrap_failed');
      }
    }
  }

  void _startInvalidation(
    CustomerPreviewRuntime runtime, {
    int initialCursor = 0,
  }) {
    _stopInvalidation();
    final generation = _invalidationGeneration;
    final context = runtime.bootstrap.context;
    final mode = runtime.bootstrap.configuration;
    final feed = CustomerPreviewInvalidationFeed(
      apiBaseUrl: runtime.baseUrl,
      dashboardBaseUrl: runtime.dashboardBaseUrl,
      context: context,
      client: runtime.invalidationClient,
    );

    _invalidation = CustomerPreviewInvalidationCoordinator(
      feed: feed,
      context: context,
      mode: mode,
      currentConfiguration: runtime.configuration,
      initialCursor: initialCursor,
      authoritativeRefetch: () {
        if (context.authenticated) {
          return CustomerPreviewResolvedConfiguration.resolve(
            baseUrl: runtime.baseUrl,
            client: runtime.invalidationClient,
            context: context,
            mode: mode,
          );
        }

        return CustomerPreviewResolvedConfiguration.resolveGuest(
          dashboardBaseUrl: runtime.dashboardBaseUrl,
          client: runtime.invalidationClient,
          context: context,
          mode: mode,
        );
      },
    );

    _scheduleInvalidation(Duration.zero, generation);
  }

  void _stopInvalidation() {
    _invalidationGeneration++;
    _invalidationTimer?.cancel();
    _invalidationTimer = null;
    _invalidation = null;
  }

  void _scheduleInvalidation(Duration delay, int generation) {
    if (!mounted || generation != _invalidationGeneration) return;
    _invalidationTimer?.cancel();
    _invalidationTimer = Timer(
      delay,
      () => unawaited(_pollInvalidation(generation)),
    );
  }

  Future<void> _pollInvalidation(int generation) async {
    final controller = _invalidation;
    final current = _runtime;
    if (!mounted ||
        controller == null ||
        current == null ||
        generation != _invalidationGeneration) {
      return;
    }

    try {
      final outcome = await controller.pollOnce();
      if (!mounted ||
          generation != _invalidationGeneration ||
          !identical(current, _runtime)) {
        return;
      }

      if (!outcome.changed) {
        _scheduleInvalidation(outcome.retryAfter, generation);
        return;
      }

      _postStatus(
        'refreshing',
        metadata: current.safeStatusMetadata,
      );

      final next = await CustomerPreviewRuntime.create(
        baseUrl: current.baseUrl,
        dashboardBaseUrl: current.dashboardBaseUrl,
        bootstrap: current.bootstrap,
        resolvedConfiguration: outcome.configuration,
      );

      if (!mounted ||
          generation != _invalidationGeneration ||
          !identical(current, _runtime)) {
        next.close();
        return;
      }

      setState(() {
        _runtime = next;
        _error = null;
      });
      current.close();

      _postStatus(
        'ready',
        metadata: next.safeStatusMetadata,
      );
      _startInvalidation(next, initialCursor: outcome.cursor);
    } on CustomerPreviewInvalidationException catch (error) {
      if (!mounted || generation != _invalidationGeneration) return;
      _postStatus(
        error.runtimeState,
        code: error.code,
        retryable: error.retryable,
        metadata: current.safeStatusMetadata,
      );
      if (error.retryable) {
        _scheduleInvalidation(const Duration(seconds: 3), generation);
      } else {
        _invalidationTimer?.cancel();
        _invalidationTimer = null;
      }
    } on CustomerPreviewConfigurationException catch (error) {
      if (!mounted || generation != _invalidationGeneration) return;
      _postStatus(
        error.runtimeState,
        code: error.code,
        retryable: false,
        metadata: current.safeStatusMetadata,
      );
      _invalidationTimer?.cancel();
      _invalidationTimer = null;
    } catch (_) {
      if (!mounted || generation != _invalidationGeneration) return;
      _postStatus(
        'stale',
        code: 'preview_invalidation_refresh_failed',
        retryable: true,
        metadata: current.safeStatusMetadata,
      );
      _scheduleInvalidation(const Duration(seconds: 3), generation);
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
      'version': CustomerPreviewHostContract.version,
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

  void _setError(String code, {String state = 'error'}) {
    if (mounted) {
      setState(() {
        _error = code;
      });
    }
    if (_allowedOrigin.isNotEmpty) {
      _postStatus(state, code: code, retryable: false);
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
    _bootstrapAttempt++;
    _stopInvalidation();
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
