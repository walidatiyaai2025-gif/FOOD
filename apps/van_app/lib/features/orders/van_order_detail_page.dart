import 'dart:async';

import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/auth/van_session.dart';
import '../../core/theme/foodex_van_theme.dart';
import '../../shared/van_action_button.dart';
import '../wallet/van_collection_page.dart';
import '../wallet/van_receipts_page.dart';
import '../wallet/van_wallet_contract.dart';
import 'van_delivery_evidence_sheet.dart';
import 'van_order_contract.dart';
import 'van_order_proof_picker.dart';

typedef VanOrderNavigationLauncher = Future<bool> Function(
  double latitude,
  double longitude,
);

class VanOrderDetailPage extends StatefulWidget {
  const VanOrderDetailPage({
    super.key,
    required this.orderId,
    required this.repository,
    required this.onSessionExpired,
    this.walletRepository,
    this.navigationLauncher,
    this.proofPicker,
  });

  final int orderId;
  final VanOrderRepository repository;
  final Future<void> Function() onSessionExpired;
  final VanWalletRepository? walletRepository;
  final VanOrderNavigationLauncher? navigationLauncher;
  final VanOrderProofPicker? proofPicker;

  @override
  State<VanOrderDetailPage> createState() => _VanOrderDetailPageState();
}

class _VanOrderDetailPageState extends State<VanOrderDetailPage>
    with WidgetsBindingObserver {
  bool _loading = true;
  bool _refreshing = false;
  bool _submitting = false;
  bool _stale = false;
  Object? _error;
  VanOrderDetail? _detail;
  VanOrderExecutionState? _execution;
  VanCollectionResult? _lastCollectionResult;
  late final VanOrderProofPicker _proofPicker;

  bool get _arabic => Localizations.localeOf(context).languageCode == 'ar';
  String _text(String en, String ar) => _arabic ? ar : en;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _proofPicker = widget.proofPicker ?? ImagePickerVanOrderProofPicker();
    unawaited(_load());
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed &&
        !_loading &&
        !_refreshing &&
        !_submitting) {
      unawaited(_load(background: true));
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  Future<void> _load({bool background = false}) async {
    if (mounted) {
      setState(() {
        if (background && _detail != null) {
          _refreshing = true;
        } else {
          _loading = true;
        }
        _error = null;
      });
    }

    try {
      final results = await Future.wait<Object>([
        widget.repository.order(widget.orderId),
        widget.repository.execution(widget.orderId),
      ]);
      if (!mounted) return;
      setState(() {
        _detail = results[0] as VanOrderDetail;
        _execution = results[1] as VanOrderExecutionState;
        _loading = false;
        _refreshing = false;
        _stale = false;
      });
    } on VanSessionExpiredException {
      await widget.onSessionExpired();
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _refreshing = false;
        if (background && _detail != null) {
          _stale = true;
        } else {
          _error = error;
        }
      });
    }
  }

  String _idempotencyKey(String action) =>
      'van-ui-${widget.orderId}-$action-${DateTime.now().microsecondsSinceEpoch}';

  Future<void> _applyMutation(
    Future<VanOrderExecutionState> Function() mutation,
  ) async {
    if (_submitting || _stale) return;
    setState(() => _submitting = true);

    try {
      final execution = await mutation();
      final detail = await widget.repository.order(widget.orderId);
      if (!mounted) return;
      setState(() {
        _execution = execution;
        _detail = detail;
        _stale = false;
      });
    } on VanSessionExpiredException {
      await widget.onSessionExpired();
    } catch (error) {
      if (!mounted) return;
      final message = error is VanOfflineException
          ? _text(
              'Network unavailable. No delivery change was applied locally.',
              'لا يوجد اتصال بالشبكة. لم يتم تطبيق أي تغيير محليًا.',
            )
          : error is VanAccessDeniedException
              ? _text('Access denied.', 'غير مصرح بهذه العملية.')
              : error is VanApiException && error.message.trim().isNotEmpty
                  ? error.message
                  : _text(
                      'The server rejected this delivery action.',
                      'رفض الخادم إجراء التسليم هذا.',
                    );
      ScaffoldMessenger.of(context)
        ..hideCurrentSnackBar()
        ..showSnackBar(SnackBar(content: Text(message)));
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  Future<void> _transition(String status) =>
      _applyMutation(
        () => widget.repository.transitionOrder(
          orderId: widget.orderId,
          status: status,
          idempotencyKey: _idempotencyKey(status),
        ),
      );

  Future<void> _captureProof() async {
    if (_submitting || _stale) return;
    final draft = await showVanDeliveryEvidenceSheet(
      context: context,
      mode: VanDeliveryEvidenceMode.proof,
      proofPicker: _proofPicker,
    );
    if (!mounted || draft?.proof == null) return;

    await _applyMutation(
      () => widget.repository.uploadProof(
        orderId: widget.orderId,
        proof: draft!.proof!,
        note: draft.note,
        idempotencyKey: _idempotencyKey('proof'),
      ),
    );
  }

  Future<void> _markFailed() async {
    if (_submitting || _stale) return;

    List<VanFailureReasonOption> reasons;
    try {
      setState(() => _submitting = true);
      reasons = await widget.repository.failedDeliveryReasons();
      if (!mounted) return;
      if (reasons.isEmpty) {
        throw const VanApiException(
          'No configured failed-delivery reasons are available.',
        );
      }
    } on VanSessionExpiredException {
      await widget.onSessionExpired();
      return;
    } catch (error) {
      if (!mounted) return;
      final message = error is VanOfflineException
          ? _text(
              'Network unavailable. Failure reasons could not be loaded.',
              'لا يوجد اتصال بالشبكة. تعذر تحميل أسباب التعذر.',
            )
          : _text(
              'Failed-delivery reasons are unavailable.',
              'أسباب تعذر التسليم غير متاحة.',
            );
      ScaffoldMessenger.of(context)
        ..hideCurrentSnackBar()
        ..showSnackBar(SnackBar(content: Text(message)));
      return;
    } finally {
      if (mounted) setState(() => _submitting = false);
    }

    if (!mounted) return;
    final draft = await showVanDeliveryEvidenceSheet(
      context: context,
      mode: VanDeliveryEvidenceMode.failure,
      proofPicker: _proofPicker,
      failureReasons: reasons,
    );
    if (!mounted || draft == null || draft.failureReason == null) return;

    await _applyMutation(
      () => widget.repository.failOrder(
        orderId: widget.orderId,
        failureReason: draft.failureReason!,
        note: draft.note,
        proof: draft.proof,
        idempotencyKey: _idempotencyKey('fail'),
      ),
    );
  }

  Future<void> _retryDelivery() async {
    if (_submitting || _stale) return;
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: Text(_text('Retry delivery?', 'إعادة محاولة التسليم؟')),
        content: Text(
          _text(
            'The order will return to Out for delivery using the server-authorized retry transition.',
            'سيعود الطلب إلى حالة خرج للتسليم باستخدام انتقال إعادة المحاولة المعتمد من الخادم.',
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(dialogContext).pop(false),
            child: Text(_text('Cancel', 'إلغاء')),
          ),
          FilledButton(
            key: const ValueKey('van-retry-confirm'),
            onPressed: () => Navigator.of(dialogContext).pop(true),
            child: Text(_text('Retry delivery', 'إعادة محاولة التسليم')),
          ),
        ],
      ),
    );
    if (confirmed != true || !mounted) return;

    await _applyMutation(
      () => widget.repository.retryOrder(
        orderId: widget.orderId,
        idempotencyKey: _idempotencyKey('retry'),
      ),
    );
  }

  String get _paymentMethod =>
      (_detail?.paymentMethod ?? '').trim().toLowerCase();

  bool get _hasCashOutstanding {
    final detail = _detail;
    final invoice = detail?.invoice;
    if (widget.walletRepository == null || detail == null || invoice == null) {
      return false;
    }

    return detail.summary.customerType == 'b2b' &&
        invoice.outstandingAmount > 0.0001 &&
        const {'cash_on_delivery', 'cod'}.contains(_paymentMethod);
  }

  bool get _hasAccountCreditOutstanding {
    final detail = _detail;
    final invoice = detail?.invoice;
    if (detail == null || invoice == null || invoice.outstandingAmount <= 0.0001) {
      return false;
    }

    return const {'account_credit', 'account_debt', 'account'}
        .contains(_paymentMethod);
  }

  Future<void> _openReceipt(int receiptId) async {
    final walletRepository = widget.walletRepository;
    if (walletRepository == null || !mounted) return;

    await Navigator.of(context).push<void>(
      MaterialPageRoute(
        builder: (_) => Scaffold(
          appBar: AppBar(
            title: Text(_text('Receipt', 'الإيصال')),
          ),
          body: VanReceiptsPage(
            repository: walletRepository,
            onSessionExpired: widget.onSessionExpired,
            highlightReceiptId: receiptId,
          ),
        ),
      ),
    );
  }

  Future<void> _openCollectionFlow() async {
    final detail = _detail;
    final invoice = detail?.invoice;
    final walletRepository = widget.walletRepository;
    if (detail == null || invoice == null || walletRepository == null) return;

    final customer = VanCustomerScope(
      type: detail.summary.customerType,
      id: detail.summary.customerId,
      name: detail.customer?.name ?? detail.summary.orderNumber,
      storeId: detail.summary.storeId,
    );

    final result = await Navigator.of(context).push<VanCollectionResult>(
      MaterialPageRoute(
        builder: (routeContext) => Scaffold(
          appBar: AppBar(
            title: Text(_text('Collect outstanding', 'تحصيل المبلغ المستحق')),
          ),
          body: VanCollectionPage(
            repository: walletRepository,
            onSessionExpired: widget.onSessionExpired,
            initialCustomer: customer,
            initialInvoiceId: invoice.id,
            onOpenReceipt: (receiptId) {
              unawaited(
                Navigator.of(routeContext).push<void>(
                  MaterialPageRoute(
                    builder: (_) => Scaffold(
                      appBar: AppBar(
                        title: Text(_text('Receipt', 'الإيصال')),
                      ),
                      body: VanReceiptsPage(
                        repository: walletRepository,
                        onSessionExpired: widget.onSessionExpired,
                        highlightReceiptId: receiptId,
                      ),
                    ),
                  ),
                ),
              );
            },
            onReturnToOrder: (collectionResult) {
              Navigator.of(routeContext).pop(collectionResult);
            },
          ),
        ),
      ),
    );

    if (!mounted || result == null) return;
    setState(() => _lastCollectionResult = result);
    await _load(background: true);
  }

  Future<void> _openMap(VanOrderDeliveryAddress address) async {
    final latitude = address.latitude;
    final longitude = address.longitude;
    if (!address.hasCoordinates || latitude == null || longitude == null) {
      return;
    }

    final launcher = widget.navigationLauncher ??
        (lat, lng) => launchUrl(
              Uri.parse(
                'https://www.google.com/maps/search/?api=1&query=$lat,$lng',
              ),
              mode: LaunchMode.externalApplication,
            );
    final opened = await launcher(latitude, longitude);
    if (!opened && mounted) {
      ScaffoldMessenger.of(context)
        ..hideCurrentSnackBar()
        ..showSnackBar(
          SnackBar(
            content: Text(
              _text(
                'Unable to open navigation.',
                'تعذر فتح تطبيق الملاحة.',
              ),
            ),
          ),
        );
    }
  }

  String _statusLabel(String value) {
    switch (value) {
      case 'assigned':
        return _text('Assigned', 'مسند');
      case 'accepted':
        return _text('Accepted', 'مقبول');
      case 'picked_up':
        return _text('Picked up', 'تم الاستلام');
      case 'out_for_delivery':
        return _text('Out for delivery', 'خرج للتسليم');
      case 'delivered':
        return _text('Delivered', 'تم التسليم');
      case 'failed':
        return _text('Failed', 'تعذر التسليم');
      default:
        return value.replaceAll('_', ' ');
    }
  }

  String _actionLabel(String value) {
    switch (value) {
      case 'accepted':
        return _text('Accept order', 'قبول الطلب');
      case 'picked_up':
        return _text('Confirm pickup', 'تأكيد الاستلام');
      case 'out_for_delivery':
        return _text('Start delivery', 'بدء التوصيل');
      case 'delivered':
        return _text('Mark delivered', 'تأكيد التسليم');
      default:
        return _statusLabel(value);
    }
  }

  IconData _actionIcon(String value) {
    switch (value) {
      case 'accepted':
        return Icons.check_circle_outline;
      case 'picked_up':
        return Icons.inventory_2_outlined;
      case 'out_for_delivery':
        return Icons.local_shipping_outlined;
      case 'delivered':
        return Icons.task_alt;
      default:
        return Icons.arrow_forward;
    }
  }

  bool _isPrimaryAction(String value) {
    if (value == 'out_for_delivery' && _execution?.status == 'failed') {
      return false;
    }
    return const {
      'accepted',
      'picked_up',
      'out_for_delivery',
      'delivered',
    }.contains(value);
  }

  List<String> get _deliveryActions {
    final execution = _execution;
    final allowed = execution?.allowedActions ?? const <String>[];
    return allowed.where((action) {
      if (!_isPrimaryAction(action)) return false;
      if (execution?.status == 'failed' && action == 'out_for_delivery') {
        return false;
      }
      if (action == 'delivered' &&
          execution?.proofRequiredForDelivered == true &&
          execution?.deliveryProofReady != true) {
        return false;
      }
      if (action == 'delivered' && _hasCashOutstanding) {
        return false;
      }
      return true;
    }).toList(growable: false);
  }

  bool get _canFail =>
      _execution?.allowedActions.contains('failed') == true;

  bool get _canRetry =>
      _execution?.status == 'failed' &&
      _execution?.allowedActions.contains('out_for_delivery') == true;

  bool get _canCaptureProof =>
      _execution?.status == 'out_for_delivery' &&
      _execution?.proofRequiredForDelivered == true;

  Widget _section({
    required String title,
    required Widget child,
    Key? key,
  }) =>
      Card(
        key: key,
        elevation: 0,
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(
                title,
                style: Theme.of(context).textTheme.titleMedium?.copyWith(
                      fontWeight: FontWeight.w900,
                    ),
              ),
              const SizedBox(height: 10),
              child,
            ],
          ),
        ),
      );

  Widget _money(String currency, double amount) => Text(
        '${amount.toStringAsFixed(3)} $currency',
        maxLines: 1,
        overflow: TextOverflow.ellipsis,
      );

  @override
  Widget build(BuildContext context) {
    if (_loading && _detail == null) {
      return const Scaffold(
        body: Center(child: CircularProgressIndicator()),
      );
    }

    if (_error != null && _detail == null) {
      return Scaffold(
        appBar: AppBar(title: Text(_text('Order detail', 'تفاصيل الطلب'))),
        body: Center(
          child: Padding(
            padding: const EdgeInsets.all(20),
            child: VanActionButton.secondary(
              onPressed: _load,
              child: Text(_text('Retry', 'إعادة المحاولة')),
            ),
          ),
        ),
      );
    }

    final detail = _detail!;
    final order = detail.summary;
    final execution = _execution;
    final address = detail.deliveryAddress;
    final customer = detail.customer;
    final invoice = detail.invoice;

    return Scaffold(
      appBar: AppBar(
        title: Text(
          order.orderNumber,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
        ),
      ),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          key: const ValueKey('van-order-detail-page'),
          padding: const EdgeInsets.fromLTRB(12, 10, 12, 24),
          children: [
            if (_refreshing || _stale)
              Container(
                margin: const EdgeInsets.only(bottom: 10),
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: FoodexVanTokens.surface,
                  border: Border.all(color: FoodexVanTokens.border),
                  borderRadius: BorderRadius.circular(14),
                ),
                child: Text(
                  _stale
                      ? _text(
                          'Showing the last confirmed order detail.',
                          'يتم عرض آخر تفاصيل طلب مؤكدة.',
                        )
                      : _text(
                          'Refreshing order detail…',
                          'جارٍ تحديث تفاصيل الطلب…',
                        ),
                ),
              ),
            _section(
              key: const ValueKey('van-order-detail-summary'),
              title: _text('Delivery status', 'حالة التسليم'),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Row(
                    children: [
                      Expanded(
                        child: Text(
                          _statusLabel(
                            execution?.status ??
                                order.vanExecutionStatus ??
                                order.status,
                          ),
                          style:
                              Theme.of(context).textTheme.titleLarge?.copyWith(
                                    fontWeight: FontWeight.w900,
                                  ),
                        ),
                      ),
                      const SizedBox(width: 8),
                      _money(order.currency, order.grandTotal),
                    ],
                  ),
                  if (execution?.proofRequiredForDelivered == true) ...[
                    const SizedBox(height: 8),
                    Text(
                      _text(
                        'Delivery proof is required before Delivered can be accepted by the server.',
                        'إثبات التسليم مطلوب قبل أن يقبل الخادم حالة تم التسليم.',
                      ),
                      style: Theme.of(context).textTheme.bodySmall?.copyWith(
                            color: FoodexVanTokens.muted,
                          ),
                    ),
                  ],
                  for (final action in _deliveryActions) ...[
                    const SizedBox(height: 10),
                    VanActionButton.icon(
                      key: ValueKey('van-order-action-$action'),
                      onPressed: _submitting ? null : () => _transition(action),
                      icon: Icon(_actionIcon(action)),
                      label: Text(_actionLabel(action)),
                    ),
                  ],
                  if (_canCaptureProof) ...[
                    const SizedBox(height: 10),
                    VanActionButton.secondaryIcon(
                      key: const ValueKey('van-order-proof-action'),
                      onPressed: _submitting || _stale ? null : _captureProof,
                      icon: Icon(
                        execution?.deliveryProofReady == true
                            ? Icons.verified_outlined
                            : Icons.add_a_photo_outlined,
                      ),
                      label: Text(
                        execution?.deliveryProofReady == true
                            ? _text(
                                'Replace delivery proof',
                                'استبدال إثبات التسليم',
                              )
                            : _text(
                                'Add delivery proof',
                                'إضافة إثبات التسليم',
                              ),
                      ),
                    ),
                    if (execution?.deliveryProofReady == true) ...[
                      const SizedBox(height: 6),
                      Text(
                        _text(
                          'Delivery proof is ready. Delivered can now be confirmed.',
                          'إثبات التسليم جاهز. يمكن الآن تأكيد التسليم.',
                        ),
                        key: const ValueKey('van-order-proof-ready'),
                        style: Theme.of(context).textTheme.bodySmall?.copyWith(
                              color: FoodexVanTokens.green,
                              fontWeight: FontWeight.w800,
                            ),
                      ),
                    ],
                  ],
                  if (_canFail) ...[
                    const SizedBox(height: 10),
                    VanActionButton.secondaryIcon(
                      key: const ValueKey('van-order-fail-action'),
                      onPressed: _submitting || _stale ? null : _markFailed,
                      icon: const Icon(Icons.report_problem_outlined),
                      label: Text(
                        _text('Failed delivery', 'تعذر التسليم'),
                      ),
                    ),
                  ],
                  if (_canRetry) ...[
                    const SizedBox(height: 10),
                    VanActionButton.icon(
                      key: const ValueKey('van-order-retry-action'),
                      onPressed:
                          _submitting || _stale ? null : _retryDelivery,
                      icon: const Icon(Icons.restart_alt_rounded),
                      label: Text(
                        _text('Retry delivery', 'إعادة محاولة التسليم'),
                      ),
                    ),
                  ],
                  if (execution?.status == 'failed' &&
                      execution?.failureReasonCode != null) ...[
                    const SizedBox(height: 10),
                    Text(
                      '${_text('Failure reason', 'سبب التعذر')}: '
                      '${execution!.failureReasonCode}',
                      key: const ValueKey('van-order-failure-reason'),
                      style: const TextStyle(fontWeight: FontWeight.w800),
                    ),
                    if (execution.failureNote?.trim().isNotEmpty == true)
                      Text(
                        execution.failureNote!,
                        key: const ValueKey('van-order-failure-note'),
                      ),
                  ],
                ],
              ),
            ),
            _section(
              title: _text('Customer', 'العميل'),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    customer?.name ?? _text('Assigned customer', 'عميل مسند'),
                    style: const TextStyle(fontWeight: FontWeight.w800),
                  ),
                  if (customer?.phone != null) Text(customer!.phone!),
                  if (customer?.email != null) Text(customer!.email!),
                ],
              ),
            ),
            _section(
              key: const ValueKey('van-order-detail-address'),
              title: _text('Delivery address', 'عنوان التسليم'),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text(
                    address != null && address.formatted.trim().isNotEmpty
                        ? address.formatted
                        : _text(
                            'No delivery address snapshot.',
                            'لا توجد نسخة محفوظة لعنوان التسليم.',
                          ),
                  ),
                  if (address?.hasCoordinates == true) ...[
                    const SizedBox(height: 8),
                    VanActionButton.secondaryIcon(
                      key: const ValueKey('van-order-open-map'),
                      onPressed: () => _openMap(address!),
                      icon: const Icon(Icons.navigation_outlined),
                      label: Text(_text('Open navigation', 'فتح الملاحة')),
                    ),
                  ],
                ],
              ),
            ),
            _section(
              title: _text('Items', 'الأصناف'),
              child: detail.items.isEmpty
                  ? Text(_text('No items.', 'لا توجد أصناف.'))
                  : Column(
                      children: [
                        for (final item in detail.items)
                          ListTile(
                            dense: true,
                            contentPadding: EdgeInsets.zero,
                            title: Text(
                              item.name,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                            ),
                            subtitle: Text(
                              '${item.sku} · ${item.quantity.toStringAsFixed(3)}',
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                            ),
                            trailing: _money(order.currency, item.lineTotal),
                          ),
                      ],
                    ),
            ),
            _section(
              title: _text('Invoice & collection', 'الفاتورة والتحصيل'),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  if (invoice != null) ...[
                    Text(
                      invoice.number,
                      style: const TextStyle(fontWeight: FontWeight.w800),
                    ),
                    Text(
                      '${_text('Outstanding', 'المتبقي')}: '
                      '${invoice.outstandingAmount.toStringAsFixed(3)} '
                      '${invoice.currency}',
                    ),
                    if (_hasCashOutstanding) ...[
                      const SizedBox(height: 10),
                      VanActionButton.secondaryIcon(
                        key: const ValueKey('van-order-collect-action'),
                        onPressed:
                            _submitting || _stale ? null : _openCollectionFlow,
                        icon: const Icon(Icons.payments_outlined),
                        label: Text(
                          _text(
                            'Collect outstanding',
                            'تحصيل المبلغ المستحق',
                          ),
                        ),
                      ),
                      const SizedBox(height: 6),
                      Text(
                        _text(
                          'Delivered stays unavailable until the authoritative COD outstanding is cleared.',
                          'تظل حالة تم التسليم غير متاحة حتى تتم تسوية المبلغ النقدي المستحق بشكل معتمد.',
                        ),
                        style: Theme.of(context).textTheme.bodySmall?.copyWith(
                              color: FoodexVanTokens.muted,
                            ),
                      ),
                    ],
                    if (_hasAccountCreditOutstanding) ...[
                      const SizedBox(height: 8),
                      Text(
                        key: const ValueKey(
                          'van-order-account-credit-settlement',
                        ),
                        _text(
                          'Account credit is authoritative. No cash collection is required from the Van for this outstanding balance.',
                          'الائتمان على الحساب هو المرجع المعتمد. لا يلزم تحصيل نقدي من الفان لهذا الرصيد المستحق.',
                        ),
                        style: Theme.of(context).textTheme.bodySmall?.copyWith(
                              color: FoodexVanTokens.muted,
                            ),
                      ),
                    ],
                  ] else
                    Text(_text('No invoice yet.', 'لا توجد فاتورة بعد.')),
                  const SizedBox(height: 8),
                  Text(
                    '${_text('Payments', 'المدفوعات')}: '
                    '${detail.payments.length} · '
                    '${_text('Collections', 'التحصيلات')}: '
                    '${detail.collections.length}',
                  ),
                  for (final collection in detail.collections)
                    Text(
                      '${collection.amount.toStringAsFixed(3)} '
                      '${collection.currency} · ${collection.status}',
                    ),
                  if (_lastCollectionResult != null) ...[
                    const SizedBox(height: 10),
                    Card(
                      key: const ValueKey('van-order-last-receipt'),
                      elevation: 0,
                      color: FoodexVanTokens.mint,
                      child: Padding(
                        padding: const EdgeInsets.all(12),
                        child: Row(
                          children: [
                            const Icon(
                              Icons.receipt_long_outlined,
                              color: FoodexVanTokens.green,
                            ),
                            const SizedBox(width: 8),
                            Expanded(
                              child: Text(
                                _text(
                                  'Receipt #${_lastCollectionResult!.receipt.id} posted · remaining ${_lastCollectionResult!.remainingOutstanding.toStringAsFixed(3)} ${_lastCollectionResult!.receipt.currency}',
                                  'تم تسجيل الإيصال #${_lastCollectionResult!.receipt.id} · المتبقي ${_lastCollectionResult!.remainingOutstanding.toStringAsFixed(3)} ${_lastCollectionResult!.receipt.currency}',
                                ),
                                style: const TextStyle(
                                  fontWeight: FontWeight.w800,
                                ),
                              ),
                            ),
                            IconButton(
                              key: const ValueKey(
                                'van-order-view-last-receipt',
                              ),
                              tooltip: _text('View receipt', 'عرض الإيصال'),
                              onPressed: () => _openReceipt(
                                _lastCollectionResult!.receipt.id,
                              ),
                              icon: const Icon(Icons.open_in_new),
                            ),
                          ],
                        ),
                      ),
                    ),
                  ],
                ],
              ),
            ),
            _section(
              key: const ValueKey('van-order-detail-timeline'),
              title: _text('Timeline', 'الخط الزمني'),
              child: detail.timeline.isEmpty
                  ? Text(_text('No timeline events.', 'لا توجد أحداث.'))
                  : Column(
                      children: [
                        for (final event in detail.timeline)
                          ListTile(
                            dense: true,
                            contentPadding: EdgeInsets.zero,
                            leading: const Icon(
                              Icons.circle,
                              size: 10,
                              color: FoodexVanTokens.green,
                            ),
                            title: Text(_statusLabel(event.stage)),
                            subtitle: Text(
                              event.occurredAt == null
                                  ? event.source
                                  : '${event.source} · ${event.occurredAt}',
                              maxLines: 2,
                              overflow: TextOverflow.ellipsis,
                            ),
                          ),
                      ],
                    ),
            ),
          ],
        ),
      ),
    );
  }
}
