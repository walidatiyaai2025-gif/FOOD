import 'dart:convert';
import 'dart:io';

import 'package:http/http.dart' as http;

import '../../features/delivery/completion/driver_completion_contract.dart';
import '../../features/delivery/driver_assignment_contract.dart';
import '../../features/wallet/driver_wallet_contract.dart';
import '../auth/driver_session.dart';
import '../diagnostics/driver_runtime_inspector.dart';

Uri _driverApiBase(String raw) {
  final parsed = Uri.parse(raw);
  final path = parsed.path.endsWith('/') ? parsed.path : '${parsed.path}/';
  return parsed.replace(path: path);
}

class HttpDriverAuthRepository implements DriverAuthRepository {
  HttpDriverAuthRepository(String baseUrl, {http.Client? client})
    : _base = _driverApiBase(baseUrl),
      _client = DriverDiagnosticHttpClient(client ?? http.Client());

  final Uri _base;
  final http.Client _client;

  Uri _endpoint(String path) => _base.resolve('api/v1/$path');

  @override
  Future<DriverSession> login({
    required String email,
    required String password,
  }) async {
    final http.Response response;
    try {
      response = await _client.post(
        _endpoint('auth/login'),
        headers: const {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
        },
        body: jsonEncode({
          'email': email,
          'password': password,
          'app': 'driver',
        }),
      );
    } on SocketException {
      throw const DriverOfflineException();
    } on http.ClientException {
      throw const DriverOfflineException();
    }

    if (response.statusCode == 401 || response.statusCode == 422) {
      throw const DriverAuthenticationException();
    }
    if (response.statusCode < 200 || response.statusCode >= 300) {
      throw const DriverApiException();
    }

    final decoded = jsonDecode(response.body);
    if (decoded is! Map<String, dynamic> ||
        decoded['token'] is! String ||
        decoded['user'] is! Map) {
      throw const DriverApiException('Invalid login response.');
    }

    final user = Map<String, dynamic>.from(decoded['user'] as Map);
    final roles = (user['roles'] as List? ?? const [])
        .map((role) => role.toString().toUpperCase())
        .where((role) => role == 'B2C_DRIVER' || role == 'B2B_DRIVER')
        .toSet();

    if (roles.length != 1 || roles.single != 'B2C_DRIVER') {
      throw const DriverRoleDeniedException();
    }

    const channel = DriverChannel.b2c;

    final scopeRaw = user['driver_scope'];
    if (scopeRaw is! Map) {
      throw const DriverRoleDeniedException();
    }
    final scope = Map<String, dynamic>.from(scopeRaw);
    final scopeChannel = (scope['channel'] ?? '').toString().toLowerCase();
    final storeId = (scope['store_id'] as num?)?.toInt() ?? 0;
    const expectedChannel = 'b2c';
    if (scopeChannel != expectedChannel || storeId <= 0) {
      throw const DriverRoleDeniedException();
    }

    return DriverSession(
      token: decoded['token'] as String,
      name: (user['name'] ?? '').toString(),
      email: (user['email'] ?? email).toString(),
      locale: (user['locale'] ?? 'ar').toString(),
      channel: channel,
      storeId: storeId,
    );
  }

  @override
  Future<void> logout(String token) async {
    try {
      final response = await _client.post(
        _endpoint('auth/logout'),
        headers: {
          'Accept': 'application/json',
          'Authorization': 'Bearer $token',
        },
      );
      if (response.statusCode == 401 || response.statusCode == 204) return;
      if (response.statusCode < 200 || response.statusCode >= 300) {
        throw const DriverApiException();
      }
    } on SocketException {
      throw const DriverOfflineException();
    } on http.ClientException {
      throw const DriverOfflineException();
    }
  }
}

class HttpDriverAssignmentRepository
    implements
        DriverProofAssignmentRepository,
        DriverInvoiceDocumentRepository,
        DriverFailureReasonCatalog,
        DriverWalletRepository {
  HttpDriverAssignmentRepository(
    String baseUrl,
    this.token, {
    http.Client? client,
  }) : _base = _driverApiBase(baseUrl),
       _client = DriverDiagnosticHttpClient(client ?? http.Client());

  final Uri _base;
  final String token;
  final http.Client _client;

  Uri _endpoint(String path) => _base.resolve('api/v1/$path');

  Map<String, String> get _headers => {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
    'Authorization': 'Bearer $token',
  };

  @override
  Future<List<DriverAssignment>> list(DriverChannel channel) async {
    if (channel != DriverChannel.b2c) {
      throw const DriverAccessDeniedException();
    }

    final response = await _request(
      () => _client.get(
        _endpoint(
          'driver/assignments',
        ).replace(queryParameters: const {'scope': 'all'}),
        headers: _headers,
      ),
    );
    final decoded = jsonDecode(response.body);
    if (decoded is! Map<String, dynamic> || decoded['data'] is! List) {
      throw const DriverApiException('Invalid assignment response.');
    }

    return (decoded['data'] as List)
        .map((raw) {
          if (raw is! Map) {
            throw const DriverApiException('Invalid assignment row.');
          }
          return _assignment(Map<String, dynamic>.from(raw));
        })
        .where((assignment) => assignment.channel == channel)
        .toList(growable: false);
  }

  DriverAssignment _assignment(Map<String, dynamic> map) {
    final assignmentChannel = switch ((map['assignment_type'] ?? '')
        .toString()
        .toLowerCase()) {
      'b2c' => DriverChannel.b2c,
      'b2b' => throw const DriverAccessDeniedException(),
      _ => throw const DriverApiException('Invalid assignment channel.'),
    };

    final order = map['order'] is Map
        ? Map<String, dynamic>.from(map['order'] as Map)
        : <String, dynamic>{};
    final customer = order['customer'] is Map
        ? Map<String, dynamic>.from(order['customer'] as Map)
        : <String, dynamic>{};
    final store = order['store'] is Map
        ? Map<String, dynamic>.from(order['store'] as Map)
        : <String, dynamic>{};
    final payment = order['payment'] is Map
        ? Map<String, dynamic>.from(order['payment'] as Map)
        : <String, dynamic>{};
    final address = order['address'] is Map
        ? Map<String, dynamic>.from(order['address'] as Map)
        : <String, dynamic>{};
    final navigation = order['navigation'] is Map
        ? Map<String, dynamic>.from(order['navigation'] as Map)
        : <String, dynamic>{};

    final addressParts =
        [
              address['label'],
              address['line1'],
              address['line2'],
              address['block'],
              address['street'],
              address['avenue'],
              address['building'],
              address['floor'],
              address['apartment'],
              address['area'],
              address['city'],
              address['governorate'],
              address['country'],
              address['landmark'],
            ]
            .where(
              (value) => value != null && value.toString().trim().isNotEmpty,
            )
            .map((value) => value.toString().trim())
            .toList(growable: false);

    final items = (order['items'] as List? ?? const [])
        .whereType<Map>()
        .map((raw) {
          final item = Map<String, dynamic>.from(raw);
          return DriverOrderItem(
            name: (item['name'] ?? '').toString(),
            sku: (item['sku'] ?? '').toString(),
            imageUrl: (item['image_url'] ?? '').toString(),
            variant: (item['variant'] ?? '').toString(),
            unit: (item['unit'] ?? '').toString(),
            note: (item['note'] ?? '').toString(),
            quantity: (item['quantity'] as num?)?.toDouble() ?? 0,
            quantityConversionFactor:
                (item['quantity_conversion_factor'] as num?)?.toDouble() ?? 1,
            packSize: (item['pack_size'] as num?)?.toDouble() ?? 0,
            caseSize: (item['case_size'] as num?)?.toDouble() ?? 0,
            unitPrice: (item['unit_price'] as num?)?.toDouble() ?? 0,
            lineTotal: (item['line_total'] as num?)?.toDouble() ?? 0,
          );
        })
        .toList(growable: false);

    final invoiceMap = order['invoice'] is Map
        ? Map<String, dynamic>.from(order['invoice'] as Map)
        : null;
    final invoiceItems = (invoiceMap?['items'] as List? ?? const [])
        .whereType<Map>()
        .map((raw) {
          final item = Map<String, dynamic>.from(raw);
          return DriverOrderItem(
            name: (item['name'] ?? '').toString(),
            sku: (item['sku'] ?? '').toString(),
            imageUrl: (item['image_url'] ?? '').toString(),
            variant: (item['variant'] ?? '').toString(),
            unit: (item['unit'] ?? '').toString(),
            note: (item['note'] ?? '').toString(),
            quantity: (item['quantity'] as num?)?.toDouble() ?? 0,
            unitPrice: (item['unit_price'] as num?)?.toDouble() ?? 0,
            lineTotal: (item['line_total'] as num?)?.toDouble() ?? 0,
          );
        })
        .toList(growable: false);
    final invoice = invoiceMap == null
        ? null
        : DriverInvoice(
            id: (invoiceMap['id'] as num?)?.toInt() ?? 0,
            number: (invoiceMap['number'] ?? '').toString(),
            revision: (invoiceMap['revision'] as num?)?.toInt() ?? 1,
            status: (invoiceMap['status'] ?? '').toString(),
            currency: (invoiceMap['currency'] ?? order['currency'] ?? 'KWD')
                .toString(),
            subtotal: (invoiceMap['subtotal'] as num?)?.toDouble() ?? 0,
            discountTotal:
                (invoiceMap['discount_total'] as num?)?.toDouble() ?? 0,
            deliveryTotal:
                (invoiceMap['delivery_total'] as num?)?.toDouble() ?? 0,
            taxTotal: (invoiceMap['tax_total'] as num?)?.toDouble() ?? 0,
            grandTotal: (invoiceMap['grand_total'] as num?)?.toDouble() ?? 0,
            paymentMethod: (invoiceMap['payment_method'] ?? '').toString(),
            paymentStatus: (invoiceMap['payment_status'] ?? '').toString(),
            outstandingAmount:
                (invoiceMap['outstanding_amount'] as num?)?.toDouble() ?? 0,
            downloadPath: (invoiceMap['download_path'] ?? '').toString(),
            issuedAt: (invoiceMap['issued_at'] ?? '').toString(),
            items: invoiceItems,
          );

    final settlementMap = order['settlement'] is Map
        ? Map<String, dynamic>.from(order['settlement'] as Map)
        : null;
    final settlement = settlementMap == null
        ? null
        : DriverSettlement(
            currency: (settlementMap['currency'] ?? order['currency'] ?? 'KWD')
                .toString(),
            orderTotal: (settlementMap['order_total'] as num?)?.toDouble() ?? 0,
            balanceApplied:
                (settlementMap['balance_applied'] as num?)?.toDouble() ?? 0,
            paidAmount: (settlementMap['paid_amount'] as num?)?.toDouble() ?? 0,
            remainingAmount:
                (settlementMap['remaining_amount'] as num?)?.toDouble() ?? 0,
            remainderMethod: (settlementMap['remainder_method'] ?? '')
                .toString(),
            paymentState: (settlementMap['payment_state'] ?? '').toString(),
            amountToCollectNow:
                (settlementMap['amount_to_collect_now'] as num?)?.toDouble() ??
                0,
            invoiceOutstandingAmount:
                (settlementMap['invoice_outstanding_amount'] as num?)
                    ?.toDouble() ??
                0,
          );

    return DriverAssignment(
      id: (map['id'] as num).toInt(),
      orderId: (map['order_id'] as num?)?.toInt() ?? 0,
      storeId: (map['store_id'] as num?)?.toInt() ?? 0,
      channel: assignmentChannel,
      reference:
          (order['number'] ?? '#${(map['order_id'] as num?)?.toInt() ?? 0}')
              .toString(),
      status: (map['status'] ?? '').toString(),
      storeName: (store['name'] ?? '').toString(),
      orderStatus: (order['status'] ?? '').toString(),
      customerName: (customer['name'] ?? '').toString(),
      customerPhone: (customer['phone'] ?? '').toString(),
      address: addressParts.join(' · '),
      navigationLatitude: navigation['available'] == true
          ? (navigation['latitude'] as num?)?.toDouble()
          : null,
      navigationLongitude: navigation['available'] == true
          ? (navigation['longitude'] as num?)?.toDouble()
          : null,
      currency: (order['currency'] ?? 'KWD').toString(),
      grandTotal: (order['grand_total'] as num?)?.toDouble() ?? 0,
      paymentMethod: (order['payment_method'] ?? '').toString(),
      paymentStatus: (payment['status'] ?? '').toString(),
      customerNote: (order['customer_note'] ?? '').toString(),
      settlement: settlement,
      items: items,
      invoice: invoice,
      availableStatuses: (map['available_statuses'] as List? ?? const [])
          .map((status) => status.toString())
          .toList(growable: false),
      assignedAt: (map['assigned_at'] ?? '').toString(),
      completedAt: (map['completed_at'] ?? '').toString(),
      createdAt: (order['created_at'] ?? '').toString(),
    );
  }

  @override
  Future<List<DriverFailureReasonOption>> failedDeliveryReasons() async {
    final response = await _request(
      () => _client.get(
        _endpoint('lookups/failed-delivery-reasons'),
        headers: _headers,
      ),
    );
    final decoded = jsonDecode(response.body);
    if (decoded is! Map<String, dynamic> || decoded['data'] is! List) {
      throw const DriverApiException(
        'Invalid failed-delivery lookup response.',
      );
    }

    return (decoded['data'] as List)
        .whereType<Map>()
        .map((raw) {
          final item = Map<String, dynamic>.from(raw);
          return DriverFailureReasonOption(
            code: (item['code'] ?? '').toString(),
            labelAr: (item['label_ar'] ?? '').toString(),
            labelEn: (item['label_en'] ?? '').toString(),
          );
        })
        .where(
          (option) =>
              option.code.trim().isNotEmpty &&
              option.labelAr.trim().isNotEmpty &&
              option.labelEn.trim().isNotEmpty,
        )
        .toList(growable: false);
  }

  @override
  Future<List<int>> downloadInvoicePdf(
    int assignmentId, {
    required String locale,
  }) async {
    final response = await _request(
      () => _client.get(
        _endpoint(
          'driver/assignments/$assignmentId/invoice/download',
        ).replace(queryParameters: {'locale': locale == 'ar' ? 'ar' : 'en'}),
        headers: _headers,
      ),
    );
    final contentType = response.headers['content-type']?.toLowerCase() ?? '';
    if (!contentType.contains('application/pdf') ||
        response.bodyBytes.isEmpty) {
      throw const DriverApiException('Invalid invoice PDF response.');
    }
    return response.bodyBytes;
  }

  @override
  Future<void> transition(
    int id,
    DriverChannel channel,
    String status, {
    String? note,
    String? failureReason,
  }) async {
    if (channel != DriverChannel.b2c) {
      throw const DriverAccessDeniedException();
    }

    await _request(
      () => _client.post(
        _endpoint('driver/assignments/$id/status'),
        headers: _headers,
        body: jsonEncode({
          'status': status,
          if (failureReason != null && failureReason.trim().isNotEmpty)
            'failure_reason': failureReason.trim(),
          if (note != null && note.trim().isNotEmpty) 'note': note.trim(),
        }),
      ),
    );
  }

  @override
  Future<void> transitionWithProof(
    int id,
    DriverChannel channel,
    String status,
    String proofImagePath, {
    String? note,
    String? failureReason,
  }) async {
    if (channel != DriverChannel.b2c) {
      throw const DriverAccessDeniedException();
    }

    final request = http.MultipartRequest(
      'POST',
      _endpoint('driver/assignments/$id/status'),
    );
    request.headers.addAll({
      'Accept': 'application/json',
      'Authorization': 'Bearer $token',
    });
    request.fields['status'] = status;
    if (failureReason != null && failureReason.trim().isNotEmpty) {
      request.fields['failure_reason'] = failureReason.trim();
    }
    if (note != null && note.trim().isNotEmpty) {
      request.fields['note'] = note.trim();
    }
    request.files.add(
      await http.MultipartFile.fromPath('proof_image', proofImagePath),
    );

    final http.Response response;
    try {
      final streamed = await _client.send(request);
      response = await http.Response.fromStream(streamed);
    } on SocketException {
      throw const DriverOfflineException();
    } on http.ClientException {
      throw const DriverOfflineException();
    }
    _ensureSuccess(response);
  }

  @override
  Future<DriverCollectionResult> collect(
    int assignmentId, {
    required double amount,
    required String idempotencyKey,
  }) async {
    final response = await _request(
      () => _client.post(
        _endpoint('driver/assignments/$assignmentId/collections'),
        headers: {..._headers, 'Idempotency-Key': idempotencyKey},
        body: jsonEncode({'amount': amount}),
      ),
    );
    final decoded = jsonDecode(response.body);
    if (decoded is! Map<String, dynamic> || decoded['data'] is! Map) {
      throw const DriverApiException('Invalid collection response.');
    }
    final data = Map<String, dynamic>.from(decoded['data'] as Map);
    final receiptRaw = data['receipt'];
    final settlementRaw = data['settlement'];
    if (receiptRaw is! Map || settlementRaw is! Map) {
      throw const DriverApiException('Invalid collection response.');
    }
    final settlement = Map<String, dynamic>.from(settlementRaw);
    return DriverCollectionResult(
      receipt: DriverCollectionReceipt.fromJson(
        Map<String, dynamic>.from(receiptRaw),
      ),
      remainingAmount:
          (settlement['amount_to_collect_now'] as num?)?.toDouble() ?? 0,
      currency: (settlement['currency'] ?? '').toString(),
    );
  }

  @override
  Future<List<DriverWalletAccount>> wallet() async {
    final response = await _request(
      () => _client.get(_endpoint('driver/wallet'), headers: _headers),
    );
    final decoded = jsonDecode(response.body);
    if (decoded is! Map<String, dynamic> || decoded['data'] is! List) {
      throw const DriverApiException('Invalid wallet response.');
    }
    return (decoded['data'] as List)
        .whereType<Map>()
        .map(
          (row) => DriverWalletAccount.fromJson(Map<String, dynamic>.from(row)),
        )
        .toList(growable: false);
  }

  @override
  Future<DriverRemittance> submitRemittance({
    required int collectionAccountId,
    required double amount,
    required String method,
    String? reference,
    String? note,
    required String idempotencyKey,
  }) async {
    final response = await _request(
      () => _client.post(
        _endpoint('driver/wallet/remittances'),
        headers: {..._headers, 'Idempotency-Key': idempotencyKey},
        body: jsonEncode({
          'collection_account_id': collectionAccountId,
          'amount': amount,
          'method': method,
          if (reference != null && reference.trim().isNotEmpty)
            'reference': reference.trim(),
          if (note != null && note.trim().isNotEmpty) 'note': note.trim(),
        }),
      ),
    );
    final decoded = jsonDecode(response.body);
    if (decoded is! Map<String, dynamic> || decoded['data'] is! Map) {
      throw const DriverApiException('Invalid remittance response.');
    }
    final data = Map<String, dynamic>.from(decoded['data'] as Map);
    final raw = data['remittance'];
    if (raw is! Map) {
      throw const DriverApiException('Invalid remittance response.');
    }
    return DriverRemittance.fromJson(Map<String, dynamic>.from(raw));
  }

  Future<http.Response> _request(
    Future<http.Response> Function() request,
  ) async {
    final http.Response response;
    try {
      response = await request();
    } on SocketException {
      throw const DriverOfflineException();
    } on http.ClientException {
      throw const DriverOfflineException();
    }

    _ensureSuccess(response);
    return response;
  }

  void _ensureSuccess(http.Response response) {
    if (response.statusCode == 401) {
      throw const DriverSessionExpiredException();
    }
    if (response.statusCode == 403) {
      throw const DriverAccessDeniedException();
    }
    if (response.statusCode < 200 || response.statusCode >= 300) {
      throw const DriverApiException();
    }
  }
}
