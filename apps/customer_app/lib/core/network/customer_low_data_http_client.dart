import 'dart:async';
import 'dart:convert';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';

import '../diagnostics/customer_diagnostics.dart';
import 'customer_data_mode.dart';

class CustomerNetworkMetricsSnapshot {
  const CustomerNetworkMetricsSnapshot({
    required this.requestCount,
    required this.networkRequestCount,
    required this.uploadedBytes,
    required this.downloadedBytes,
    required this.cacheHits,
    required this.cacheMisses,
    required this.cacheValidations,
    required this.cacheFallbacks,
    required this.networkErrors,
    required this.timeouts,
    required this.dedupedRequests,
    required this.circuitBreakerHits,
    required this.totalLatencyMs,
  });

  final int requestCount;
  final int networkRequestCount;
  final int uploadedBytes;
  final int downloadedBytes;
  final int cacheHits;
  final int cacheMisses;
  final int cacheValidations;
  final int cacheFallbacks;
  final int networkErrors;
  final int timeouts;
  final int dedupedRequests;
  final int circuitBreakerHits;
  final int totalLatencyMs;

  double get cacheHitRatio => requestCount == 0 ? 0 : cacheHits / requestCount;

  double get averageNetworkLatencyMs =>
      networkRequestCount == 0 ? 0 : totalLatencyMs / networkRequestCount;

  Map<String, Object> toJson() => <String, Object>{
        'request_count': requestCount,
        'network_request_count': networkRequestCount,
        'uploaded_bytes': uploadedBytes,
        'downloaded_bytes': downloadedBytes,
        'cache_hits': cacheHits,
        'cache_misses': cacheMisses,
        'cache_validations': cacheValidations,
        'cache_fallbacks': cacheFallbacks,
        'network_errors': networkErrors,
        'timeouts': timeouts,
        'deduped_requests': dedupedRequests,
        'circuit_breaker_hits': circuitBreakerHits,
        'cache_hit_ratio': cacheHitRatio,
        'average_network_latency_ms': averageNetworkLatencyMs,
      };
}

class CustomerNetworkMetrics {
  int _requestCount = 0;
  int _networkRequestCount = 0;
  int _uploadedBytes = 0;
  int _downloadedBytes = 0;
  int _cacheHits = 0;
  int _cacheMisses = 0;
  int _cacheValidations = 0;
  int _cacheFallbacks = 0;
  int _networkErrors = 0;
  int _timeouts = 0;
  int _dedupedRequests = 0;
  int _circuitBreakerHits = 0;
  int _totalLatencyMs = 0;

  void beginRequest() => _requestCount += 1;

  void recordCacheHit({bool fallback = false}) {
    _cacheHits += 1;
    if (fallback) _cacheFallbacks += 1;
  }

  void recordCacheMiss() => _cacheMisses += 1;

  void recordNetwork({
    required int uploadedBytes,
    required int downloadedBytes,
    required Duration elapsed,
    bool validated = false,
  }) {
    _networkRequestCount += 1;
    _uploadedBytes += uploadedBytes;
    _downloadedBytes += downloadedBytes;
    _totalLatencyMs += elapsed.inMilliseconds;
    if (validated) _cacheValidations += 1;
  }

  void recordError({required bool timeout}) {
    _networkErrors += 1;
    if (timeout) _timeouts += 1;
  }

  void recordDedupedRequest() => _dedupedRequests += 1;

  void recordCircuitBreakerHit() => _circuitBreakerHits += 1;

  CustomerNetworkMetricsSnapshot get snapshot => CustomerNetworkMetricsSnapshot(
        requestCount: _requestCount,
        networkRequestCount: _networkRequestCount,
        uploadedBytes: _uploadedBytes,
        downloadedBytes: _downloadedBytes,
        cacheHits: _cacheHits,
        cacheMisses: _cacheMisses,
        cacheValidations: _cacheValidations,
        cacheFallbacks: _cacheFallbacks,
        networkErrors: _networkErrors,
        timeouts: _timeouts,
        dedupedRequests: _dedupedRequests,
        circuitBreakerHits: _circuitBreakerHits,
        totalLatencyMs: _totalLatencyMs,
      );
}

class CustomerLowDataHttpClient extends http.BaseClient {
  CustomerLowDataHttpClient(
    this._inner, {
    CustomerDataModeController? dataMode,
    CustomerDiagnostics? diagnostics,
    CustomerHttpResponseCache? cache,
    CustomerNetworkMetrics? metrics,
  })  : dataMode = dataMode ?? CustomerDataModeController.instance,
        diagnostics = diagnostics ?? CustomerDiagnostics.instance,
        cache = cache ?? CustomerHttpResponseCache(),
        metrics = metrics ?? CustomerNetworkMetrics();

  final http.Client _inner;
  final CustomerDataModeController dataMode;
  final CustomerDiagnostics diagnostics;
  final CustomerHttpResponseCache cache;
  final CustomerNetworkMetrics metrics;
  final Set<String> _revalidating = <String>{};
  final Map<String, Future<bool>> _inFlightGets = <String, Future<bool>>{};
  final List<Completer<void>> _foregroundGetWaiters = <Completer<void>>[];
  int _activeForegroundGets = 0;
  bool _revalidationInFlight = false;
  DateTime? _circuitOpenUntil;

  static const Duration _circuitCooldown = Duration(seconds: 12);
  static const int _maxForegroundGets = 3;

  CustomerNetworkMetricsSnapshot get metricsSnapshot => metrics.snapshot;

  bool get _isCircuitOpen {
    final until = _circuitOpenUntil;
    if (until == null) return false;
    if (DateTime.now().toUtc().isBefore(until)) return true;
    _circuitOpenUntil = null;
    return false;
  }

  void _openCircuit() {
    _circuitOpenUntil = DateTime.now().toUtc().add(_circuitCooldown);
  }

  void _closeCircuit() {
    _circuitOpenUntil = null;
  }

  Future<void> _acquireForegroundGetSlot() async {
    if (_activeForegroundGets < _maxForegroundGets) {
      _activeForegroundGets += 1;
      return;
    }

    final waiter = Completer<void>();
    _foregroundGetWaiters.add(waiter);
    await waiter.future;
  }

  void _releaseForegroundGetSlot() {
    if (_foregroundGetWaiters.isNotEmpty) {
      _foregroundGetWaiters.removeAt(0).complete();
      return;
    }
    _activeForegroundGets -= 1;
  }

  Future<T> _withForegroundGetSlot<T>(Future<T> Function() action) async {
    await _acquireForegroundGetSlot();
    try {
      return await action();
    } finally {
      _releaseForegroundGetSlot();
    }
  }

  @override
  Future<http.StreamedResponse> send(http.BaseRequest request) async {
    metrics.beginRequest();

    final mode = dataMode.effectiveMode;
    request.headers['X-FOODEX-Client'] = 'customer';
    request.headers['X-FOODEX-Low-Data'] = '1';
    request.headers['X-FOODEX-Data-Mode'] = mode.name;

    final cacheable = _isCacheableGet(request);
    final identity = cacheable ? _cacheIdentity(request) : null;
    final cached = identity == null
        ? null
        : await cache.read(
            identity.key,
            sensitive: identity.sensitive,
          );

    if (mode == CustomerDataMode.offline) {
      if (cached != null) {
        metrics.recordCacheHit();
        _recordTransfer(
          request: request,
          statusCode: cached.statusCode,
          downloadedBytes: 0,
          uploadedBytes: 0,
          elapsed: Duration.zero,
          cacheState: 'offline',
        );
        return _cachedResponse(request, cached, 'offline');
      }
      metrics.recordCacheMiss();
      throw http.ClientException('offline_mode_cache_miss', request.url);
    }

    if (cached != null) {
      metrics.recordCacheHit();
      final age = DateTime.now().toUtc().difference(cached.storedAt);
      final freshness = _freshnessFor(request.url.path, mode);
      if (age >= freshness && dataMode.allowsRefresh) {
        _scheduleRevalidation(
          identity!,
          request.url,
          Map<String, String>.from(request.headers),
          cached,
        );
      }
      _recordTransfer(
        request: request,
        statusCode: cached.statusCode,
        downloadedBytes: 0,
        uploadedBytes: 0,
        elapsed: Duration.zero,
        cacheState: age >= freshness ? 'stale' : 'hit',
      );
      return _cachedResponse(
        request,
        cached,
        age >= freshness ? 'stale' : 'hit',
      );
    }

    if (cacheable) {
      metrics.recordCacheMiss();
      final key = identity!.key;

      if (_isCircuitOpen) {
        metrics.recordCircuitBreakerHit();
        _recordTransfer(
          request: request,
          statusCode: null,
          downloadedBytes: 0,
          uploadedBytes: 0,
          elapsed: Duration.zero,
          cacheState: 'circuit_open',
        );
        throw http.ClientException('network_circuit_open', request.url);
      }

      final pending = _inFlightGets[key];
      if (pending != null) {
        metrics.recordDedupedRequest();
        final succeeded = await pending;
        if (succeeded) {
          final coalesced = await cache.read(
            key,
            sensitive: identity.sensitive,
          );
          if (coalesced != null) {
            metrics.recordCacheHit();
            _recordTransfer(
              request: request,
              statusCode: coalesced.statusCode,
              downloadedBytes: 0,
              uploadedBytes: 0,
              elapsed: Duration.zero,
              cacheState: 'coalesced',
            );
            return _cachedResponse(request, coalesced, 'coalesced');
          }
        }

        if (_isCircuitOpen) {
          metrics.recordCircuitBreakerHit();
          _recordTransfer(
            request: request,
            statusCode: null,
            downloadedBytes: 0,
            uploadedBytes: 0,
            elapsed: Duration.zero,
            cacheState: 'circuit_open',
          );
          throw http.ClientException('network_circuit_open', request.url);
        }
      }

      final completer = Completer<bool>();
      _inFlightGets[key] = completer.future;
      try {
        final response = await _withForegroundGetSlot(() async {
          if (_isCircuitOpen) {
            metrics.recordCircuitBreakerHit();
            _recordTransfer(
              request: request,
              statusCode: null,
              downloadedBytes: 0,
              uploadedBytes: 0,
              elapsed: Duration.zero,
              cacheState: 'circuit_open',
            );
            throw http.ClientException('network_circuit_open', request.url);
          }
          return _networkSend(
            request,
            cacheIdentity: identity,
            cached: null,
          );
        });
        completer.complete(_isSuccessful(response.statusCode));
        return response;
      } catch (_) {
        if (!completer.isCompleted) completer.complete(false);
        rethrow;
      } finally {
        _inFlightGets.remove(key);
      }
    }

    return _networkSend(
      request,
      cacheIdentity: identity,
      cached: null,
    );
  }

  Future<http.StreamedResponse> _networkSend(
    http.BaseRequest request, {
    required CustomerCacheIdentity? cacheIdentity,
    required CustomerCachedHttpResponse? cached,
  }) async {
    final timeout = dataMode.requestTimeout();
    final stopwatch = Stopwatch()..start();

    try {
      final response = await _inner.send(request).timeout(timeout);
      final bytes = await response.stream.toBytes().timeout(timeout);
      stopwatch.stop();

      _closeCircuit();
      dataMode.observeSuccess(stopwatch.elapsed);
      metrics.recordNetwork(
        uploadedBytes: _uploadBytes(request),
        downloadedBytes: bytes.length,
        elapsed: stopwatch.elapsed,
        validated: response.statusCode == 304,
      );

      if (cacheIdentity != null) {
        if (response.statusCode == 304 && cached != null) {
          await cache.touch(
            cacheIdentity.key,
            cached,
            sensitive: cacheIdentity.sensitive,
          );
          _recordTransfer(
            request: request,
            statusCode: 304,
            downloadedBytes: 0,
            uploadedBytes: _uploadBytes(request),
            elapsed: stopwatch.elapsed,
            cacheState: 'validated',
          );
          return _cachedResponse(request, cached, 'validated');
        }

        if (_isSuccessful(response.statusCode) &&
            _isJsonResponse(response.headers)) {
          await cache.write(
            cacheIdentity.key,
            CustomerCachedHttpResponse(
              statusCode: response.statusCode,
              body: utf8.decode(bytes, allowMalformed: true),
              headers: _cacheHeaders(response.headers),
              storedAt: DateTime.now().toUtc(),
            ),
            sensitive: cacheIdentity.sensitive,
          );
        }
      } else if (_isSuccessful(response.statusCode) &&
          request.method.toUpperCase() != 'GET') {
        await _handleSuccessfulMutation(request, response, bytes);
      }

      _recordTransfer(
        request: request,
        statusCode: response.statusCode,
        downloadedBytes: bytes.length,
        uploadedBytes: _uploadBytes(request),
        elapsed: stopwatch.elapsed,
        cacheState: 'network',
      );
      return _rebuiltResponse(request, response, bytes);
    } on TimeoutException catch (error) {
      stopwatch.stop();
      _openCircuit();
      dataMode.observeFailure();
      metrics.recordError(timeout: true);
      diagnostics.recordNetworkTransfer(
        method: request.method,
        uri: request.url,
        statusCode: null,
        downloadedBytes: 0,
        uploadedBytes: _uploadBytes(request),
        elapsed: stopwatch.elapsed,
        cacheState: 'timeout',
        errorType: error.runtimeType.toString(),
      );
      if (cached != null) {
        metrics.recordCacheHit(fallback: true);
        return _cachedResponse(request, cached, 'fallback');
      }
      rethrow;
    } catch (error) {
      stopwatch.stop();
      _openCircuit();
      dataMode.observeFailure();
      metrics.recordError(timeout: false);
      diagnostics.recordNetworkTransfer(
        method: request.method,
        uri: request.url,
        statusCode: null,
        downloadedBytes: 0,
        uploadedBytes: _uploadBytes(request),
        elapsed: stopwatch.elapsed,
        cacheState: 'error',
        errorType: error.runtimeType.toString(),
      );
      if (cached != null) {
        metrics.recordCacheHit(fallback: true);
        return _cachedResponse(request, cached, 'fallback');
      }
      rethrow;
    }
  }

  void _scheduleRevalidation(
    CustomerCacheIdentity identity,
    Uri uri,
    Map<String, String> sourceHeaders,
    CustomerCachedHttpResponse cached,
  ) {
    if (_isCircuitOpen || _revalidationInFlight) return;
    if (!_revalidating.add(identity.key)) return;
    _revalidationInFlight = true;

    unawaited(() async {
      final request = http.Request('GET', uri);
      request.headers.addAll(sourceHeaders);
      final etag = cached.etag;
      final lastModified = cached.lastModified;
      if (etag != null && etag.isNotEmpty) {
        request.headers['If-None-Match'] = etag;
      }
      if (lastModified != null && lastModified.isNotEmpty) {
        request.headers['If-Modified-Since'] = lastModified;
      }

      final timeout = dataMode.requestTimeout();
      final stopwatch = Stopwatch()..start();
      try {
        final response = await _inner.send(request).timeout(timeout);
        final bytes = await response.stream.toBytes().timeout(timeout);
        stopwatch.stop();

        _closeCircuit();
        dataMode.observeSuccess(stopwatch.elapsed);
        metrics.recordNetwork(
          uploadedBytes: 0,
          downloadedBytes: bytes.length,
          elapsed: stopwatch.elapsed,
          validated: response.statusCode == 304,
        );

        if (response.statusCode == 304) {
          await cache.touch(
            identity.key,
            cached,
            sensitive: identity.sensitive,
          );
        } else if (_isSuccessful(response.statusCode) &&
            _isJsonResponse(response.headers)) {
          await cache.write(
            identity.key,
            CustomerCachedHttpResponse(
              statusCode: response.statusCode,
              body: utf8.decode(bytes, allowMalformed: true),
              headers: _cacheHeaders(response.headers),
              storedAt: DateTime.now().toUtc(),
            ),
            sensitive: identity.sensitive,
          );
        }

        diagnostics.recordNetworkTransfer(
          method: 'GET',
          uri: uri,
          statusCode: response.statusCode,
          downloadedBytes: bytes.length,
          uploadedBytes: 0,
          elapsed: stopwatch.elapsed,
          cacheState: response.statusCode == 304 ? 'validated' : 'revalidated',
        );
      } on TimeoutException catch (error) {
        stopwatch.stop();
        _openCircuit();
        dataMode.observeFailure();
        metrics.recordError(timeout: true);
        diagnostics.recordNetworkTransfer(
          method: 'GET',
          uri: uri,
          statusCode: null,
          downloadedBytes: 0,
          uploadedBytes: 0,
          elapsed: stopwatch.elapsed,
          cacheState: 'revalidation_timeout',
          errorType: error.runtimeType.toString(),
        );
      } catch (error) {
        stopwatch.stop();
        _openCircuit();
        dataMode.observeFailure();
        metrics.recordError(timeout: false);
        diagnostics.recordNetworkTransfer(
          method: 'GET',
          uri: uri,
          statusCode: null,
          downloadedBytes: 0,
          uploadedBytes: 0,
          elapsed: stopwatch.elapsed,
          cacheState: 'revalidation_error',
          errorType: error.runtimeType.toString(),
        );
      } finally {
        _revalidating.remove(identity.key);
        _revalidationInFlight = false;
      }
    }());
  }

  Future<void> _handleSuccessfulMutation(
    http.BaseRequest request,
    http.StreamedResponse response,
    List<int> bytes,
  ) async {
    final path = request.url.path;
    final method = request.method.toUpperCase();

    if (path == '/api/v1/cart/items' ||
        (path.startsWith('/api/v1/cart/items/') &&
            (method == 'PATCH' || method == 'PUT'))) {
      if (bytes.isEmpty || !_isJsonResponse(response.headers)) return;
      Object? decoded;
      try {
        decoded = jsonDecode(utf8.decode(bytes));
      } catch (_) {
        return;
      }
      if (decoded is! Map) return;

      final store = decoded['store_id'] ??
          (decoded['store'] is Map ? (decoded['store'] as Map)['id'] : null);
      final storeId =
          store is num ? store.toInt() : int.tryParse(store?.toString() ?? '');
      if (storeId == null || storeId <= 0) return;

      final uri = request.url.replace(
        path: '/api/v1/cart',
        queryParameters: <String, String>{'store': '$storeId'},
      );
      final headers = Map<String, String>.from(request.headers);
      headers['X-FOODEX-Store-ID'] = '$storeId';
      final guestToken = _header(response.headers, 'x-guest-token');
      if (guestToken != null && guestToken.trim().isNotEmpty) {
        headers['X-Guest-Token'] = guestToken.trim();
      }

      final identity = _cacheIdentityFor(uri, headers);
      await cache.write(
        identity.key,
        CustomerCachedHttpResponse(
          statusCode: 200,
          body: utf8.decode(bytes, allowMalformed: true),
          headers: _cacheHeaders(response.headers),
          storedAt: DateTime.now().toUtc(),
        ),
        sensitive: true,
      );
      return;
    }

    if (path.startsWith('/api/v1/cart/items/') && method == 'DELETE') {
      await cache.clearSensitive();
      return;
    }

    await cache.clearSensitive();
  }

  CustomerCacheIdentity _cacheIdentity(http.BaseRequest request) =>
      _cacheIdentityFor(request.url, request.headers);

  CustomerCacheIdentity _cacheIdentityFor(
    Uri uri,
    Map<String, String> headers,
  ) {
    final authorization = _header(headers, 'authorization') ?? '';
    final guest = _header(headers, 'x-guest-token') ?? '';
    final domain = _header(headers, 'x-foodex-customer-domain') ?? '';
    final store = _header(headers, 'x-foodex-store-id') ?? '';
    final retailStore = _header(headers, 'x-foodex-retail-store-id') ?? '';
    final sensitive = _isSensitivePath(uri.path) ||
        authorization.isNotEmpty ||
        guest.isNotEmpty;

    final scope = <String>[
      uri.toString(),
      _fnv1a(authorization),
      _fnv1a(guest),
      domain,
      store,
      retailStore,
    ].join('|');

    return CustomerCacheIdentity(
      key: _fnv1a(scope),
      sensitive: sensitive,
    );
  }

  bool _isCacheableGet(http.BaseRequest request) {
    if (request.method.toUpperCase() != 'GET') return false;
    if (!request.url.path.startsWith('/api/v1/')) return false;
    final accept = _header(request.headers, 'accept');
    return accept == null ||
        accept.isEmpty ||
        accept.toLowerCase().contains('json');
  }

  static bool _isSensitivePath(String path) {
    return path.startsWith('/api/v1/cart') ||
        path.startsWith('/api/v1/orders') ||
        path.startsWith('/api/v1/b2b/orders') ||
        path.startsWith('/api/v1/profile') ||
        path.startsWith('/api/v1/notifications') ||
        path.startsWith('/api/v1/account-deletion') ||
        path.startsWith('/api/v1/checkout');
  }

  static Duration _freshnessFor(String path, CustomerDataMode mode) {
    final multiplier = mode == CustomerDataMode.lite ? 6 : 1;
    if (path.startsWith('/api/v1/orders') ||
        path.startsWith('/api/v1/cart') ||
        path.startsWith('/api/v1/notifications')) {
      return Duration(seconds: 15 * multiplier);
    }
    if (path.startsWith('/api/v1/stores') ||
        path.startsWith('/api/v1/products') ||
        path.contains('/products') ||
        path.contains('/categories') ||
        path.contains('/offers') ||
        path.contains('/banners')) {
      return Duration(minutes: 5 * multiplier);
    }
    return Duration(minutes: 2 * multiplier);
  }

  static Map<String, String> _cacheHeaders(Map<String, String> headers) {
    const allowed = <String>{
      'etag',
      'last-modified',
      'content-type',
      'content-language',
      'cache-control',
      'x-foodex-payload-bytes',
    };
    return <String, String>{
      for (final entry in headers.entries)
        if (allowed.contains(entry.key.toLowerCase())) entry.key: entry.value,
    };
  }

  static bool _isJsonResponse(Map<String, String> headers) {
    final contentType = _header(headers, 'content-type')?.toLowerCase() ?? '';
    return contentType.isEmpty || contentType.contains('json');
  }

  static bool _isSuccessful(int statusCode) =>
      statusCode >= 200 && statusCode < 300;

  http.StreamedResponse _cachedResponse(
    http.BaseRequest request,
    CustomerCachedHttpResponse cached,
    String source,
  ) {
    final bytes = utf8.encode(cached.body);
    return http.StreamedResponse(
      Stream<List<int>>.value(bytes),
      cached.statusCode,
      contentLength: bytes.length,
      request: request,
      headers: <String, String>{
        ...cached.headers,
        'x-foodex-cache': source,
      },
      reasonPhrase: 'Customer cache',
    );
  }

  static http.StreamedResponse _rebuiltResponse(
    http.BaseRequest request,
    http.StreamedResponse response,
    List<int> bytes,
  ) =>
      http.StreamedResponse(
        Stream<List<int>>.value(bytes),
        response.statusCode,
        contentLength: bytes.length,
        request: request,
        headers: response.headers,
        isRedirect: response.isRedirect,
        persistentConnection: response.persistentConnection,
        reasonPhrase: response.reasonPhrase,
      );

  void _recordTransfer({
    required http.BaseRequest request,
    required int statusCode,
    required int downloadedBytes,
    required int uploadedBytes,
    required Duration elapsed,
    required String cacheState,
  }) {
    diagnostics.recordNetworkTransfer(
      method: request.method,
      uri: request.url,
      statusCode: statusCode,
      downloadedBytes: downloadedBytes,
      uploadedBytes: uploadedBytes,
      elapsed: elapsed,
      cacheState: cacheState,
    );
  }

  static int _uploadBytes(http.BaseRequest request) {
    final length = request.contentLength;
    return length == null || length < 0 ? 0 : length;
  }

  static String? _header(Map<String, String> headers, String name) {
    final lower = name.toLowerCase();
    for (final entry in headers.entries) {
      if (entry.key.toLowerCase() == lower) return entry.value;
    }
    return null;
  }

  static String _fnv1a(String value) {
    var hash = 0x811c9dc5;
    for (final codeUnit in value.codeUnits) {
      hash ^= codeUnit;
      hash = (hash * 0x01000193) & 0xffffffff;
    }
    return hash.toRadixString(16).padLeft(8, '0');
  }

  @override
  void close() => _inner.close();
}

class CustomerCacheIdentity {
  const CustomerCacheIdentity({
    required this.key,
    required this.sensitive,
  });

  final String key;
  final bool sensitive;
}

class CustomerCachedHttpResponse {
  const CustomerCachedHttpResponse({
    required this.statusCode,
    required this.body,
    required this.headers,
    required this.storedAt,
  });

  final int statusCode;
  final String body;
  final Map<String, String> headers;
  final DateTime storedAt;

  String? get etag => _headerValue('etag');
  String? get lastModified => _headerValue('last-modified');

  String? _headerValue(String name) {
    for (final entry in headers.entries) {
      if (entry.key.toLowerCase() == name) return entry.value;
    }
    return null;
  }

  CustomerCachedHttpResponse touch() => CustomerCachedHttpResponse(
        statusCode: statusCode,
        body: body,
        headers: headers,
        storedAt: DateTime.now().toUtc(),
      );

  Map<String, dynamic> toJson() => <String, dynamic>{
        'status_code': statusCode,
        'body': body,
        'headers': headers,
        'stored_at': storedAt.toIso8601String(),
      };

  static CustomerCachedHttpResponse? fromJson(Object? value) {
    if (value is! Map) return null;
    final body = value['body'];
    final status = value['status_code'];
    final storedAt = DateTime.tryParse(value['stored_at']?.toString() ?? '');
    if (body is! String || status is! num || storedAt == null) return null;

    final rawHeaders = value['headers'];
    final headers = <String, String>{};
    if (rawHeaders is Map) {
      for (final entry in rawHeaders.entries) {
        headers[entry.key.toString()] = entry.value.toString();
      }
    }
    return CustomerCachedHttpResponse(
      statusCode: status.toInt(),
      body: body,
      headers: headers,
      storedAt: storedAt.toUtc(),
    );
  }
}

class CustomerHttpResponseCache {
  CustomerHttpResponseCache({
    FlutterSecureStorage? secureStorage,
    this.maxEntries = 80,
    this.maxBodyBytes = 512 * 1024,
  }) : _secureStorage = secureStorage ?? const FlutterSecureStorage();

  static const _publicPrefix = 'foodex.customer.http_cache.public.v2.';
  static const _privatePrefix = 'foodex.customer.http_cache.private.v2.';
  static const _indexKey = 'foodex.customer.http_cache.index.v2';

  final FlutterSecureStorage _secureStorage;
  final int maxEntries;
  final int maxBodyBytes;
  SharedPreferences? _preferences;

  Future<CustomerCachedHttpResponse?> read(
    String cacheKey, {
    required bool sensitive,
  }) async {
    final raw = sensitive
        ? await _secureStorage.read(key: '$_privatePrefix$cacheKey')
        : (await _prefs()).getString('$_publicPrefix$cacheKey');
    if (raw == null || raw.isEmpty) return null;

    try {
      return CustomerCachedHttpResponse.fromJson(jsonDecode(raw));
    } catch (_) {
      await remove(cacheKey, sensitive: sensitive);
      return null;
    }
  }

  Future<void> write(
    String cacheKey,
    CustomerCachedHttpResponse entry, {
    required bool sensitive,
  }) async {
    if (utf8.encode(entry.body).length > maxBodyBytes) return;
    final raw = jsonEncode(entry.toJson());

    if (sensitive) {
      await _secureStorage.write(
        key: '$_privatePrefix$cacheKey',
        value: raw,
      );
    } else {
      await (await _prefs()).setString('$_publicPrefix$cacheKey', raw);
    }

    final prefs = await _prefs();
    final marker = '${sensitive ? 's' : 'p'}:$cacheKey';
    final index = prefs.getStringList(_indexKey)?.toList() ?? <String>[];
    index.remove(marker);
    index.add(marker);

    while (index.length > maxEntries) {
      final evicted = index.removeAt(0);
      await _removeMarker(evicted);
    }
    await prefs.setStringList(_indexKey, index);
  }

  Future<void> touch(
    String cacheKey,
    CustomerCachedHttpResponse entry, {
    required bool sensitive,
  }) =>
      write(
        cacheKey,
        entry.touch(),
        sensitive: sensitive,
      );

  Future<void> remove(
    String cacheKey, {
    required bool sensitive,
  }) async {
    final prefs = await _prefs();
    final marker = '${sensitive ? 's' : 'p'}:$cacheKey';
    if (sensitive) {
      await _secureStorage.delete(key: '$_privatePrefix$cacheKey');
    } else {
      await prefs.remove('$_publicPrefix$cacheKey');
    }
    final index = prefs.getStringList(_indexKey)?.toList() ?? <String>[];
    if (index.remove(marker)) {
      await prefs.setStringList(_indexKey, index);
    }
  }

  Future<void> clearSensitive() async {
    final prefs = await _prefs();
    final index = prefs.getStringList(_indexKey)?.toList() ?? <String>[];
    final remaining = <String>[];
    for (final marker in index) {
      if (marker.startsWith('s:')) {
        await _removeMarker(marker);
      } else {
        remaining.add(marker);
      }
    }
    await prefs.setStringList(_indexKey, remaining);
  }

  Future<void> clear() async {
    final prefs = await _prefs();
    final index = prefs.getStringList(_indexKey)?.toList() ?? <String>[];
    for (final marker in index) {
      await _removeMarker(marker);
    }
    await prefs.remove(_indexKey);
  }

  Future<void> _removeMarker(String marker) async {
    if (marker.length < 3 || marker[1] != ':') return;
    final cacheKey = marker.substring(2);
    if (marker.startsWith('s:')) {
      await _secureStorage.delete(key: '$_privatePrefix$cacheKey');
    } else {
      await (await _prefs()).remove('$_publicPrefix$cacheKey');
    }
  }

  Future<SharedPreferences> _prefs() async =>
      _preferences ??= await SharedPreferences.getInstance();
}
