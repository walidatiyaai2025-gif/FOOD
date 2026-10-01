import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';

import '../../core/auth/driver_session.dart';
import '../../core/localization/driver_translations.dart';
import '../../core/navigation/driver_navigation.dart';
import '../../core/preview/driver_preview_context.dart';
import '../../core/theme/foodex_theme.dart';

enum DriverLoadState { loading, ready, empty, error, offline }

enum DriverOrderFilter { active, completed, failed, all }

class DriverOrderItem {
  const DriverOrderItem({
    required this.name,
    required this.quantity,
    required this.lineTotal,
    this.sku = '',
  });

  final String name;
  final String sku;
  final double quantity;
  final double lineTotal;
}

class DriverInvoice {
  const DriverInvoice({
    required this.id,
    required this.number,
    required this.status,
    required this.currency,
    required this.grandTotal,
    this.revision = 1,
    this.subtotal = 0,
    this.discountTotal = 0,
    this.deliveryTotal = 0,
    this.taxTotal = 0,
    this.paymentMethod = '',
    this.paymentStatus = '',
    this.issuedAt = '',
    this.items = const [],
  });

  final int id;
  final String number;
  final int revision;
  final String status;
  final String currency;
  final double subtotal;
  final double discountTotal;
  final double deliveryTotal;
  final double taxTotal;
  final double grandTotal;
  final String paymentMethod;
  final String paymentStatus;
  final String issuedAt;
  final List<DriverOrderItem> items;
}

class DriverAssignment {
  const DriverAssignment({
    required this.id,
    required this.channel,
    required this.reference,
    required this.status,
    this.orderId = 0,
    this.storeId = 0,
    this.storeName = '',
    this.orderStatus = '',
    this.customerName = '',
    this.customerPhone = '',
    this.address = '',
    this.navigationLatitude,
    this.navigationLongitude,
    this.currency = 'KWD',
    this.grandTotal = 0,
    this.paymentMethod = '',
    this.paymentStatus = '',
    this.customerNote = '',
    this.items = const [],
    this.availableStatuses = const [],
    this.assignedAt = '',
    this.completedAt = '',
    this.createdAt = '',
    this.invoice,
  });

  final int id;
  final int orderId;
  final int storeId;
  final DriverChannel channel;
  final String reference;
  final String status;
  final String storeName;
  final String orderStatus;
  final String customerName;
  final String customerPhone;
  final String address;
  final double? navigationLatitude;
  final double? navigationLongitude;
  final String currency;

  bool get hasNavigation =>
      navigationLatitude != null && navigationLongitude != null;
  final double grandTotal;
  final String paymentMethod;
  final String paymentStatus;
  final String customerNote;
  final List<DriverOrderItem> items;
  final List<String> availableStatuses;
  final String assignedAt;
  final String completedAt;
  final String createdAt;
  final DriverInvoice? invoice;
}

abstract interface class DriverAssignmentRepository {
  Future<List<DriverAssignment>> list(DriverChannel channel);

  Future<void> transition(
    int id,
    DriverChannel channel,
    String status, {
    String? note,
    String? failureReason,
  });
}

abstract interface class DriverProofAssignmentRepository
    implements DriverAssignmentRepository {
  Future<void> transitionWithProof(
    int id,
    DriverChannel channel,
    String status,
    String proofImagePath, {
    String? note,
    String? failureReason,
  });
}

class DriverJourneyPage extends StatefulWidget {
  const DriverJourneyPage({
    super.key,
    required this.channel,
    required this.repository,
    this.onSessionExpired,
    this.focusAssignmentId,
    this.initialAssignmentStatus,
    this.navigationLauncher = launchDriverNavigation,
    this.previewContext,
  });

  final DriverChannel channel;
  final DriverAssignmentRepository repository;
  final VoidCallback? onSessionExpired;
  final int? focusAssignmentId;
  final String? initialAssignmentStatus;
  final DriverNavigationLauncher navigationLauncher;
  final DriverPreviewContext? previewContext;

  @override
  State<DriverJourneyPage> createState() => _DriverJourneyPageState();
}

class _DriverJourneyPageState extends State<DriverJourneyPage> {
  DriverLoadState state = DriverLoadState.loading;
  List<DriverAssignment> assignments = const [];
  DriverOrderFilter _filter = DriverOrderFilter.active;
  String? _statusFilter;
  final TextEditingController _searchController = TextEditingController();
  final Set<int> _transitioning = <int>{};
  String? _actionError;
  bool _didFocusInitialAssignment = false;

  @override
  void initState() {
    super.initState();
    _statusFilter = widget.initialAssignmentStatus;
    _searchController.addListener(_refresh);
    _load();
  }

  @override
  void dispose() {
    _searchController
      ..removeListener(_refresh)
      ..dispose();
    super.dispose();
  }

  void _refresh() {
    if (mounted) setState(() {});
  }

  Future<void> _load() async {
    if (mounted) setState(() => state = DriverLoadState.loading);
    try {
      final rows = await widget.repository.list(widget.channel);
      if (!mounted) return;
      final preview = widget.previewContext;
      final filtered = rows
          .where(
            (row) =>
                row.channel == widget.channel &&
                (preview == null ||
                    preview.allowsAssignment(
                      assignmentChannel: row.channel,
                      assignmentStoreId: row.storeId,
                    )),
          )
          .toList(growable: false);
      setState(() {
        assignments = filtered;
        state = filtered.isEmpty ? DriverLoadState.empty : DriverLoadState.ready;
      });

      final focusId = widget.focusAssignmentId;
      if (!_didFocusInitialAssignment && focusId != null) {
        _didFocusInitialAssignment = true;
        DriverAssignment? match;
        for (final row in filtered) {
          if (row.id == focusId) {
            match = row;
            break;
          }
        }
        if (match != null) {
          final focusedAssignment = match;
          WidgetsBinding.instance.addPostFrameCallback((_) {
            if (mounted) _showDetail(focusedAssignment);
          });
        }
      }
    } on DriverSessionExpiredException {
      widget.onSessionExpired?.call();
      if (mounted && widget.onSessionExpired == null) {
        setState(() => state = DriverLoadState.error);
      }
    } on DriverOfflineException {
      if (mounted) setState(() => state = DriverLoadState.offline);
    } catch (_) {
      if (mounted) setState(() => state = DriverLoadState.error);
    }
  }

  List<DriverAssignment> get _visibleAssignments {
    final query = _searchController.text.trim().toLowerCase();

    return assignments.where((assignment) {
      final matchesFilter = _statusFilter != null
          ? assignment.status == _statusFilter
          : switch (_filter) {
        DriverOrderFilter.active =>
          !const ['delivered', 'failed', 'cancelled', 'unassigned', 'reassigned']
              .contains(assignment.status),
        DriverOrderFilter.completed => assignment.status == 'delivered',
        DriverOrderFilter.failed => assignment.status == 'failed',
        DriverOrderFilter.all => true,
      };
      if (!matchesFilter) return false;
      if (query.isEmpty) return true;

      return [
        assignment.reference,
        assignment.customerName,
        assignment.customerPhone,
        assignment.address,
        assignment.storeName,
      ].any((value) => value.toLowerCase().contains(query));
    }).toList(growable: false);
  }

  Future<void> _transition(
    DriverAssignment assignment,
    String status, {
    String? note,
    String? proofImagePath,
    String? failureReason,
  }) async {
    if (widget.previewContext != null) {
      if (mounted) {
        setState(
          () => _actionError = context.tr('driver.preview.mutation_blocked'),
        );
      }
      return;
    }
    if (_transitioning.contains(assignment.id)) return;
    setState(() {
      _transitioning.add(assignment.id);
      _actionError = null;
    });
    try {
      final repository = widget.repository;
      if (proofImagePath != null &&
          repository is DriverProofAssignmentRepository) {
        await repository.transitionWithProof(
          assignment.id,
          widget.channel,
          status,
          proofImagePath,
          note: note,
          failureReason: failureReason,
        );
      } else {
        await repository.transition(
          assignment.id,
          widget.channel,
          status,
          note: note,
          failureReason: failureReason,
        );
      }
      await _load();
    } on DriverSessionExpiredException {
      widget.onSessionExpired?.call();
    } on DriverOfflineException {
      if (mounted) setState(() => _actionError = context.tr('driver.offline'));
    } catch (_) {
      if (mounted) setState(() => _actionError = context.tr('driver.error'));
    } finally {
      if (mounted) setState(() => _transitioning.remove(assignment.id));
    }
  }

  Future<void> _performAction(
    DriverAssignment assignment,
    String status,
  ) async {
    if (widget.previewContext != null) {
      if (mounted) {
        setState(
          () => _actionError = context.tr('driver.preview.mutation_blocked'),
        );
      }
      return;
    }

    var noteValue = '';
    String? proofImagePath;
    String? failureReason;
    final picker = ImagePicker();
    const failureReasons = [
      'customer_no_answer',
      'wrong_address',
      'customer_refused',
      'customer_absent',
      'payment_issue',
      'order_issue',
      'other',
    ];

    final result = await showDialog<
        ({String note, String? proofImagePath, String? failureReason})>(
      context: context,
      builder: (dialogContext) => StatefulBuilder(
        builder: (dialogContext, setDialogState) => AlertDialog(
          title: Text(
            status == 'failed'
                ? context.tr('driver.failure.title')
                : context.tr('driver.action.confirm_title'),
          ),
          content: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                if (status == 'failed') ...[
                  DropdownButtonFormField<String>(
                    key: const Key('driver-failure-reason'),
                    initialValue: failureReason,
                    isExpanded: true,
                    decoration: InputDecoration(
                      labelText: context.tr('driver.failure.reason'),
                    ),
                    items: failureReasons
                        .map(
                          (reason) => DropdownMenuItem(
                            value: reason,
                            child: Text(
                              context.tr('driver.failure.reason.$reason'),
                            ),
                          ),
                        )
                        .toList(growable: false),
                    onChanged: (value) =>
                        setDialogState(() => failureReason = value),
                  ),
                  const SizedBox(height: 10),
                ],
                TextField(
                  key: Key('driver-status-note-$status'),
                  onChanged: (value) => noteValue = value,
                  maxLength: 1000,
                  maxLines: 3,
                  decoration: InputDecoration(
                    labelText: status == 'failed'
                        ? context.tr('driver.failure.note_optional')
                        : context.tr('driver.action.note_optional'),
                  ),
                ),
                if (status == 'delivered' || status == 'failed') ...[
                  const SizedBox(height: 8),
                  Row(
                    children: [
                      Expanded(
                        child: OutlinedButton.icon(
                          key: const Key('driver-proof-camera'),
                          onPressed: () async {
                            final image = await picker.pickImage(
                              source: ImageSource.camera,
                              imageQuality: 82,
                              maxWidth: 1600,
                            );
                            if (image != null) {
                              setDialogState(() => proofImagePath = image.path);
                            }
                          },
                          icon: const Icon(Icons.photo_camera_rounded),
                          label: Text(context.tr('driver.proof.camera')),
                        ),
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: OutlinedButton.icon(
                          key: const Key('driver-proof-gallery'),
                          onPressed: () async {
                            final image = await picker.pickImage(
                              source: ImageSource.gallery,
                              imageQuality: 82,
                              maxWidth: 1600,
                            );
                            if (image != null) {
                              setDialogState(() => proofImagePath = image.path);
                            }
                          },
                          icon: const Icon(Icons.photo_library_rounded),
                          label: Text(context.tr('driver.proof.gallery')),
                        ),
                      ),
                    ],
                  ),
                  if (proofImagePath != null) ...[
                    const SizedBox(height: 8),
                    Row(
                      children: [
                        const Icon(Icons.check_circle_rounded, size: 18),
                        const SizedBox(width: 6),
                        Expanded(
                          child: Text(
                            context.tr('driver.proof.attached'),
                            key: const Key('driver-proof-attached'),
                          ),
                        ),
                        IconButton(
                          key: const Key('driver-proof-remove'),
                          onPressed: () =>
                              setDialogState(() => proofImagePath = null),
                          icon: const Icon(Icons.close_rounded),
                        ),
                      ],
                    ),
                  ],
                ],
              ],
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.of(dialogContext).pop(),
              child: Text(context.tr('driver.dismiss')),
            ),
            FilledButton(
              key: Key('driver-status-confirm-$status'),
              onPressed: () {
                final value = noteValue.trim();
                if (status == 'failed') {
                  if (failureReason == null) return;
                  if (failureReason == 'other' && value.isEmpty) return;
                }
                Navigator.of(dialogContext).pop((
                  note: value,
                  proofImagePath: proofImagePath,
                  failureReason: failureReason,
                ));
              },
              child: Text(
                status == 'failed'
                    ? context.tr('driver.failure.submit')
                    : context.tr('driver.action.confirm'),
              ),
            ),
          ],
        ),
      ),
    );
    if (result == null) return;
    await _transition(
      assignment,
      status,
      note: result.note.isEmpty ? null : result.note,
      proofImagePath: result.proofImagePath,
      failureReason: result.failureReason,
    );
  }

  String? _primaryActionStatus(DriverAssignment assignment) {
    if (assignment.status == 'accepted' &&
        assignment.availableStatuses.contains('out_for_delivery')) {
      return 'out_for_delivery';
    }
    if (assignment.status == 'out_for_delivery' &&
        assignment.availableStatuses.contains('delivered')) {
      return 'delivered';
    }
    return null;
  }

  String _primaryActionLabel(String status) => status == 'out_for_delivery'
      ? context.tr('driver.action.start_delivery')
      : context.tr('driver.action.delivered');

  Future<void> _performDeliveryDecision(
    DriverAssignment assignment,
    String primaryStatus,
  ) async {
    if (widget.previewContext != null) {
      if (mounted) {
        setState(
          () => _actionError = context.tr('driver.preview.mutation_blocked'),
        );
      }
      return;
    }
    if (_transitioning.contains(assignment.id)) return;

    var noteValue = '';
    String? proofImagePath;
    String? failureReason;
    var failureMode = false;
    final picker = ImagePicker();
    const failureReasons = [
      'customer_no_answer',
      'wrong_address',
      'customer_refused',
      'customer_absent',
      'payment_issue',
      'order_issue',
      'other',
    ];

    final result = await showModalBottomSheet<
        ({
          String status,
          String note,
          String? proofImagePath,
          String? failureReason,
        })>(
      context: context,
      showDragHandle: true,
      isScrollControlled: true,
      builder: (sheetContext) => StatefulBuilder(
        builder: (sheetContext, setSheetState) {
          final allowsFailure =
              assignment.availableStatuses.contains('failed');
          final requiresProofChoice = primaryStatus == 'delivered';

          return SafeArea(
            top: false,
            child: Padding(
              padding: EdgeInsets.fromLTRB(
                20,
                4,
                20,
                20 + MediaQuery.viewInsetsOf(sheetContext).bottom,
              ),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text(
                    primaryStatus == 'out_for_delivery'
                        ? context.tr('driver.action.start_delivery')
                        : context.tr('driver.action.delivered'),
                    style: Theme.of(context).textTheme.titleLarge?.copyWith(
                          fontWeight: FontWeight.w900,
                        ),
                  ),
                  const SizedBox(height: 6),
                  Text(
                    assignment.reference,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                          color: FoodexBrand.muted,
                          fontWeight: FontWeight.w700,
                        ),
                  ),
                  const SizedBox(height: 16),
                  if (failureMode) ...[
                    DropdownButtonFormField<String>(
                      key: const Key('driver-decision-failure-reason'),
                      initialValue: failureReason,
                      isExpanded: true,
                      decoration: InputDecoration(
                        labelText: context.tr('driver.failure.reason'),
                      ),
                      items: failureReasons
                          .map(
                            (reason) => DropdownMenuItem(
                              value: reason,
                              child: Text(
                                context.tr('driver.failure.reason.$reason'),
                              ),
                            ),
                          )
                          .toList(growable: false),
                      onChanged: (value) =>
                          setSheetState(() => failureReason = value),
                    ),
                    const SizedBox(height: 10),
                  ],
                  TextField(
                    key: Key(
                      'driver-decision-note-${assignment.id}-$primaryStatus',
                    ),
                    onChanged: (value) => noteValue = value,
                    maxLength: 1000,
                    maxLines: 3,
                    decoration: InputDecoration(
                      labelText: failureMode
                          ? context.tr('driver.failure.note_optional')
                          : context.tr('driver.action.note_optional'),
                    ),
                  ),
                  if (requiresProofChoice && !failureMode) ...[
                    const SizedBox(height: 4),
                    Text(
                      context.tr('driver.action.attach_proof'),
                      style: Theme.of(context).textTheme.labelLarge?.copyWith(
                            fontWeight: FontWeight.w800,
                          ),
                    ),
                    const SizedBox(height: 8),
                    Row(
                      children: [
                        Expanded(
                          child: OutlinedButton.icon(
                            key: const Key('driver-decision-proof-camera'),
                            onPressed: () async {
                              final image = await picker.pickImage(
                                source: ImageSource.camera,
                                imageQuality: 82,
                                maxWidth: 1600,
                              );
                              if (image != null) {
                                setSheetState(() => proofImagePath = image.path);
                              }
                            },
                            icon: const Icon(Icons.photo_camera_rounded),
                            label: Text(context.tr('driver.proof.camera')),
                          ),
                        ),
                        const SizedBox(width: 8),
                        Expanded(
                          child: OutlinedButton.icon(
                            key: const Key('driver-decision-proof-gallery'),
                            onPressed: () async {
                              final image = await picker.pickImage(
                                source: ImageSource.gallery,
                                imageQuality: 82,
                                maxWidth: 1600,
                              );
                              if (image != null) {
                                setSheetState(() => proofImagePath = image.path);
                              }
                            },
                            icon: const Icon(Icons.photo_library_rounded),
                            label: Text(context.tr('driver.proof.gallery')),
                          ),
                        ),
                      ],
                    ),
                    if (proofImagePath != null)
                      Padding(
                        padding: const EdgeInsets.only(top: 8),
                        child: Row(
                          children: [
                            const Icon(
                              Icons.check_circle_rounded,
                              size: 18,
                              color: FoodexBrand.green,
                            ),
                            const SizedBox(width: 6),
                            Expanded(
                              child: Text(
                                context.tr('driver.proof.attached'),
                                key: const Key(
                                  'driver-decision-proof-attached',
                                ),
                              ),
                            ),
                            IconButton(
                              key: const Key('driver-decision-proof-remove'),
                              onPressed: () => setSheetState(
                                () => proofImagePath = null,
                              ),
                              icon: const Icon(Icons.close_rounded),
                            ),
                          ],
                        ),
                      ),
                  ],
                  const SizedBox(height: 14),
                  if (!failureMode)
                    Row(
                      children: [
                        if (allowsFailure) ...[
                          Expanded(
                            child: OutlinedButton.icon(
                              key: Key(
                                'driver-decision-failed-${assignment.id}',
                              ),
                              onPressed: () =>
                                  setSheetState(() => failureMode = true),
                              style: OutlinedButton.styleFrom(
                                foregroundColor: FoodexBrand.red,
                                side: const BorderSide(color: FoodexBrand.red),
                              ),
                              icon: const Icon(Icons.report_gmailerrorred_rounded),
                              label: Text(
                                context.tr('driver.action.delivery_failed'),
                              ),
                            ),
                          ),
                          const SizedBox(width: 10),
                        ],
                        Expanded(
                          child: FilledButton(
                            key: Key(
                              'driver-decision-confirm-$primaryStatus',
                            ),
                            onPressed: () {
                              Navigator.of(sheetContext).pop((
                                status: primaryStatus,
                                note: noteValue.trim(),
                                proofImagePath: proofImagePath,
                                failureReason: null,
                              ));
                            },
                            child: Text(
                              primaryStatus == 'out_for_delivery'
                                  ? context.tr(
                                      'driver.action.confirm_start_delivery',
                                    )
                                  : context.tr(
                                      'driver.action.confirm_delivered',
                                    ),
                            ),
                          ),
                        ),
                      ],
                    )
                  else
                    Row(
                      children: [
                        Expanded(
                          child: TextButton(
                            key: const Key('driver-decision-failure-back'),
                            onPressed: () => setSheetState(() {
                              failureMode = false;
                              failureReason = null;
                            }),
                            child: Text(context.tr('driver.dismiss')),
                          ),
                        ),
                        const SizedBox(width: 10),
                        Expanded(
                          child: FilledButton(
                            key: const Key(
                              'driver-decision-confirm-failed',
                            ),
                            style: FilledButton.styleFrom(
                              backgroundColor: FoodexBrand.red,
                            ),
                            onPressed: () {
                              final note = noteValue.trim();
                              if (failureReason == null) return;
                              if (failureReason == 'other' && note.isEmpty) {
                                return;
                              }
                              Navigator.of(sheetContext).pop((
                                status: 'failed',
                                note: note,
                                proofImagePath: null,
                                failureReason: failureReason,
                              ));
                            },
                            child: Text(context.tr('driver.failure.submit')),
                          ),
                        ),
                      ],
                    ),
                ],
              ),
            ),
          );
        },
      ),
    );

    if (result == null) return;
    await _transition(
      assignment,
      result.status,
      note: result.note.isEmpty ? null : result.note,
      proofImagePath: result.proofImagePath,
      failureReason: result.failureReason,
    );
  }

  String _paymentMethodLabel(String value) {
    if (value.trim().isEmpty) return context.tr('driver.detail.unknown');
    final key = 'driver.payment_method.${value.toLowerCase()}';
    final translated = context.tr(key);
    return translated == key ? value : translated;
  }

  String _paymentStatusLabel(String value) {
    if (value.trim().isEmpty) return context.tr('driver.detail.unknown');
    final key = 'driver.payment_status.${value.toLowerCase()}';
    final translated = context.tr(key);
    return translated == key ? value : translated;
  }

  String _invoiceStatusLabel(String value) {
    if (value.trim().isEmpty) return context.tr('driver.detail.unknown');
    final key = 'driver.invoice_status.${value.toLowerCase()}';
    final translated = context.tr(key);
    return translated == key ? value : translated;
  }

  Future<void> _showInvoice(DriverAssignment assignment) async {
    final invoice = assignment.invoice;
    if (invoice == null) return;

    await showDialog<void>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: Text('${context.tr('driver.invoice.title')} ${invoice.number}'),
        content: SizedBox(
          width: 520,
          child: SingleChildScrollView(
            key: Key('driver-invoice-detail-${invoice.id}'),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                _DetailLine(
                  label: context.tr('driver.invoice.status'),
                  value: _invoiceStatusLabel(invoice.status),
                ),
                _DetailLine(
                  label: context.tr('driver.invoice.revision'),
                  value: invoice.revision.toString(),
                ),
                if (invoice.issuedAt.isNotEmpty)
                  _DetailLine(
                    label: context.tr('driver.invoice.issued_at'),
                    value: invoice.issuedAt,
                  ),
                const Divider(height: 24),
                ...invoice.items.map(
                  (item) => Padding(
                    padding: const EdgeInsets.symmetric(vertical: 4),
                    child: Row(
                      children: [
                        Expanded(
                          child: Text(
                            item.sku.isEmpty
                                ? item.name
                                : '${item.sku} · ${item.name}',
                          ),
                        ),
                        Text(
                          '${item.quantity.toStringAsFixed(3)} · ${item.lineTotal.toStringAsFixed(3)} ${invoice.currency}',
                        ),
                      ],
                    ),
                  ),
                ),
                const Divider(height: 24),
                _DetailLine(
                  label: context.tr('driver.invoice.subtotal'),
                  value:
                      '${invoice.subtotal.toStringAsFixed(3)} ${invoice.currency}',
                ),
                _DetailLine(
                  label: context.tr('driver.invoice.discount'),
                  value:
                      '${invoice.discountTotal.toStringAsFixed(3)} ${invoice.currency}',
                ),
                _DetailLine(
                  label: context.tr('driver.invoice.delivery'),
                  value:
                      '${invoice.deliveryTotal.toStringAsFixed(3)} ${invoice.currency}',
                ),
                _DetailLine(
                  label: context.tr('driver.invoice.tax'),
                  value:
                      '${invoice.taxTotal.toStringAsFixed(3)} ${invoice.currency}',
                ),
                _DetailLine(
                  label: context.tr('driver.invoice.total'),
                  value:
                      '${invoice.grandTotal.toStringAsFixed(3)} ${invoice.currency}',
                ),
                _DetailLine(
                  label: context.tr('driver.invoice.payment'),
                  value:
                      '${_paymentMethodLabel(invoice.paymentMethod)} · ${_paymentStatusLabel(invoice.paymentStatus)}',
                ),
              ],
            ),
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(dialogContext).pop(),
            child: Text(context.tr('driver.dismiss')),
          ),
        ],
      ),
    );
  }

  Future<void> _showDetail(DriverAssignment assignment) async {
    await showModalBottomSheet<void>(
      context: context,
      showDragHandle: true,
      isScrollControlled: true,
      builder: (sheetContext) => SafeArea(
        child: FractionallySizedBox(
          heightFactor: .88,
          child: SingleChildScrollView(
            key: Key('assignment-detail-${assignment.id}'),
            padding: const EdgeInsets.fromLTRB(20, 8, 20, 28),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(
                  context.tr('driver.detail.title'),
                  style: Theme.of(context).textTheme.headlineSmall,
                ),
                const SizedBox(height: 12),
                _DetailLine(
                  label: context.tr('driver.detail.order'),
                  value: assignment.reference,
                ),
                _DetailLine(
                  label: context.tr('driver.detail.order_status'),
                  value: context.tr(
                    'driver.order_status.${assignment.orderStatus}',
                  ),
                ),
                _DetailLine(
                  label: context.tr('driver.detail.store'),
                  value: assignment.storeName,
                ),
                _DetailLine(
                  label: context.tr('driver.detail.customer'),
                  value: assignment.customerName,
                ),
                if (assignment.customerPhone.isNotEmpty)
                  _DetailLine(
                    label: context.tr('driver.detail.phone'),
                    value: assignment.customerPhone,
                  ),
                if (assignment.address.isNotEmpty)
                  _DetailLine(
                    label: context.tr('driver.detail.address'),
                    value: assignment.address,
                  ),
                if (assignment.hasNavigation) ...[
                  const SizedBox(height: 10),
                  FilledButton.icon(
                    key: Key(
                      'driver-navigate-${assignment.id}',
                    ),
                    onPressed: () async {
                      final preview = widget.previewContext;
                      if (preview != null && !preview.nativeNavigationEnabled) {
                        if (mounted) {
                          ScaffoldMessenger.of(context).showSnackBar(
                            SnackBar(
                              content: Text(
                                context.tr('driver.preview.navigation_simulated'),
                              ),
                            ),
                          );
                        }
                        return;
                      }

                      final launched = await widget.navigationLauncher(
                        assignment.navigationLatitude!,
                        assignment.navigationLongitude!,
                      );
                      if (!launched && mounted) {
                        ScaffoldMessenger.of(context).showSnackBar(
                          SnackBar(
                            content: Text(
                              context.tr('driver.navigation.unavailable'),
                            ),
                          ),
                        );
                      }
                    },
                    icon: const Icon(Icons.navigation_rounded),
                    label: Text(context.tr('driver.navigation.open')),
                  ),
                ],
                if (assignment.customerNote.isNotEmpty)
                  _DetailLine(
                    label: context.tr('driver.detail.note'),
                    value: assignment.customerNote,
                  ),
                const Divider(height: 28),
                Text(
                  context.tr('driver.detail.items'),
                  style: Theme.of(context).textTheme.titleMedium,
                ),
                const SizedBox(height: 8),
                if (assignment.items.isEmpty)
                  Text(context.tr('driver.detail.no_items'))
                else
                  ...assignment.items.map(
                    (item) => Padding(
                      padding: const EdgeInsets.symmetric(vertical: 5),
                      child: Row(
                        children: [
                          Expanded(
                            child: Text(
                              item.sku.isEmpty
                                  ? item.name
                                  : '${item.sku} · ${item.name}',
                            ),
                          ),
                          Text(
                            '${item.quantity.toStringAsFixed(3)} · ${item.lineTotal.toStringAsFixed(3)} ${assignment.currency}',
                          ),
                        ],
                      ),
                    ),
                  ),
                const Divider(height: 28),
                _DetailLine(
                  label: context.tr('driver.detail.total'),
                  value:
                      '${assignment.grandTotal.toStringAsFixed(3)} ${assignment.currency}',
                ),
                _DetailLine(
                  label: context.tr('driver.detail.payment'),
                  value: assignment.paymentMethod.isEmpty
                      ? context.tr('driver.detail.unknown')
                      : '${_paymentMethodLabel(assignment.paymentMethod)} · ${_paymentStatusLabel(assignment.paymentStatus)}',
                ),
                if (assignment.invoice != null) ...[
                  const SizedBox(height: 10),
                  OutlinedButton.icon(
                    key: Key('driver-open-invoice-${assignment.id}'),
                    onPressed: () => _showInvoice(assignment),
                    icon: const Icon(Icons.receipt_long_rounded),
                    label: Text(
                      '${context.tr('driver.invoice.open')} · ${assignment.invoice!.number}',
                    ),
                  ),
                ],
                const SizedBox(height: 18),
                if (assignment.availableStatuses.isEmpty)
                  Text(context.tr('driver.action.none'))
                else
                  Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    children: assignment.availableStatuses
                        .map(
                          (status) => FilledButton(
                            key: Key(
                              'assignment-status-${assignment.id}-$status',
                            ),
                            onPressed: _transitioning.contains(assignment.id)
                                ? null
                                : () {
                                    Navigator.of(sheetContext).pop();
                                    _performAction(assignment, status);
                                  },
                            child: Text(context.tr('driver.status.$status')),
                          ),
                        )
                        .toList(growable: false),
                  ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final visible = _visibleAssignments;

    return Scaffold(
      appBar: AppBar(
        title: Text(
          context.tr(
            widget.channel == DriverChannel.b2c
                ? 'driver.b2c.title'
                : 'driver.b2b.title',
          ),
        ),
        actions: [
          IconButton(
            key: const Key('driver-refresh-assignments'),
            tooltip: context.tr('driver.refresh'),
            onPressed: state == DriverLoadState.loading ? null : _load,
            icon: const Icon(Icons.refresh_rounded),
          ),
        ],
      ),
      body: Column(
        children: [
          if (_actionError != null)
            MaterialBanner(
              content: Text(
                _actionError!,
                key: const Key('driver-action-error'),
              ),
              actions: [
                TextButton(
                  onPressed: () => setState(() => _actionError = null),
                  child: Text(context.tr('driver.dismiss')),
                ),
              ],
            ),
          if (state == DriverLoadState.ready)
            Container(
              margin: const EdgeInsets.fromLTRB(16, 12, 16, 8),
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                color: FoodexBrand.surface,
                borderRadius: BorderRadius.circular(20),
                border: Border.all(color: FoodexBrand.border),
                boxShadow: const [
                  BoxShadow(
                    color: Color(0x10172033),
                    blurRadius: 18,
                    offset: Offset(0, 8),
                  ),
                ],
              ),
              child: Column(
                children: [
                  TextField(
                    key: const Key('driver-order-search'),
                    controller: _searchController,
                    decoration: InputDecoration(
                      prefixIcon: const Icon(Icons.search),
                      hintText: context.tr('driver.search'),
                    ),
                  ),
                  const SizedBox(height: 10),
                  if (_statusFilter != null)
                    Align(
                      alignment: AlignmentDirectional.centerStart,
                      child: InputChip(
                        key: const Key('driver-exact-status-filter'),
                        avatar: const Icon(Icons.filter_alt_rounded, size: 18),
                        label: Text(context.tr('driver.status.$_statusFilter')),
                        onDeleted: () => setState(() => _statusFilter = null),
                      ),
                    )
                  else
                    _DriverFilterBar(
                      value: _filter,
                      onChanged: (value) => setState(() => _filter = value),
                    ),
                ],
              ),
            ),
          Expanded(
            child: switch (state) {
              DriverLoadState.loading => const Center(
                  child: CircularProgressIndicator(key: Key('driver-loading')),
                ),
              DriverLoadState.empty => Center(
                  child: Text(
                    context.tr('driver.empty'),
                    key: const Key('driver-empty'),
                  ),
                ),
              DriverLoadState.error => _Retry(
                  message: context.tr('driver.error'),
                  retryLabel: context.tr('driver.retry'),
                  onRetry: _load,
                  keyName: 'driver-error',
                ),
              DriverLoadState.offline => _Retry(
                  message: context.tr('driver.offline'),
                  retryLabel: context.tr('driver.retry'),
                  onRetry: _load,
                  keyName: 'driver-offline',
                ),
              DriverLoadState.ready => visible.isEmpty
                  ? Center(
                      child: Text(
                        context.tr('driver.filter.empty'),
                        key: const Key('driver-filter-empty'),
                      ),
                    )
                  : RefreshIndicator(
                      onRefresh: _load,
                      child: ListView.builder(
                        padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
                        itemCount: visible.length,
                        itemBuilder: (context, index) {
                          final assignment = visible[index];
                          final primaryStatus =
                              _primaryActionStatus(assignment);
                          final identity = [
                            assignment.customerName,
                            assignment.storeName,
                          ].where((value) => value.trim().isNotEmpty).join(' · ');
                          final payment = assignment.paymentMethod.isEmpty
                              ? ''
                              : '${_paymentMethodLabel(assignment.paymentMethod)} · ${_paymentStatusLabel(assignment.paymentStatus)}';
                          final timestamp = assignment.completedAt.isNotEmpty
                              ? assignment.completedAt
                              : (assignment.assignedAt.isNotEmpty
                                  ? assignment.assignedAt
                                  : assignment.createdAt);

                          return Card(
                            key: Key('assignment-${assignment.id}'),
                            margin: const EdgeInsets.only(bottom: 10),
                            elevation: 0,
                            shape: RoundedRectangleBorder(
                              borderRadius: BorderRadius.circular(18),
                              side: const BorderSide(
                                color: FoodexBrand.border,
                              ),
                            ),
                            child: InkWell(
                              onTap: () => _showDetail(assignment),
                              borderRadius: BorderRadius.circular(18),
                              child: Padding(
                                padding: const EdgeInsets.all(14),
                                child: Column(
                                  crossAxisAlignment:
                                      CrossAxisAlignment.stretch,
                                  children: [
                                    Row(
                                      children: [
                                        Expanded(
                                          child: Text(
                                            assignment.reference,
                                            maxLines: 1,
                                            overflow: TextOverflow.ellipsis,
                                            style: const TextStyle(
                                              fontWeight: FontWeight.w900,
                                              fontSize: 15,
                                            ),
                                          ),
                                        ),
                                        const SizedBox(width: 8),
                                        DecoratedBox(
                                          decoration: BoxDecoration(
                                            color: FoodexBrand.statusSurface(
                                              assignment.status,
                                            ),
                                            borderRadius:
                                                BorderRadius.circular(999),
                                          ),
                                          child: Padding(
                                            padding: const EdgeInsets.symmetric(
                                              horizontal: 9,
                                              vertical: 5,
                                            ),
                                            child: Text(
                                              context.tr(
                                                'driver.status.${assignment.status}',
                                              ),
                                              maxLines: 1,
                                              style: TextStyle(
                                                color: FoodexBrand.statusColor(
                                                  assignment.status,
                                                ),
                                                fontSize: 11,
                                                fontWeight: FontWeight.w800,
                                              ),
                                            ),
                                          ),
                                        ),
                                      ],
                                    ),
                                    const SizedBox(height: 9),
                                    Row(
                                      children: [
                                        Expanded(
                                          child: Text(
                                            identity.isEmpty
                                                ? context.tr(
                                                    'driver.detail.unknown',
                                                  )
                                                : identity,
                                            maxLines: 1,
                                            overflow: TextOverflow.ellipsis,
                                            style: const TextStyle(
                                              fontWeight: FontWeight.w700,
                                            ),
                                          ),
                                        ),
                                        const SizedBox(width: 8),
                                        Text(
                                          '${assignment.grandTotal.toStringAsFixed(3)} ${assignment.currency}',
                                          maxLines: 1,
                                          style: const TextStyle(
                                            fontWeight: FontWeight.w900,
                                          ),
                                        ),
                                      ],
                                    ),
                                    if (payment.isNotEmpty ||
                                        timestamp.isNotEmpty) ...[
                                      const SizedBox(height: 6),
                                      Row(
                                        children: [
                                          Expanded(
                                            child: Text(
                                              payment,
                                              maxLines: 1,
                                              overflow: TextOverflow.ellipsis,
                                              style: Theme.of(context)
                                                  .textTheme
                                                  .bodySmall
                                                  ?.copyWith(
                                                    color: FoodexBrand.muted,
                                                    fontWeight: FontWeight.w600,
                                                  ),
                                            ),
                                          ),
                                          if (timestamp.isNotEmpty) ...[
                                            const SizedBox(width: 8),
                                            Flexible(
                                              child: Text(
                                                timestamp,
                                                maxLines: 1,
                                                overflow: TextOverflow.ellipsis,
                                                textAlign: TextAlign.end,
                                                style: Theme.of(context)
                                                    .textTheme
                                                    .bodySmall
                                                    ?.copyWith(
                                                      color: FoodexBrand.muted,
                                                    ),
                                              ),
                                            ),
                                          ],
                                        ],
                                      ),
                                    ],
                                    if (assignment.address.isNotEmpty) ...[
                                      const SizedBox(height: 6),
                                      Text(
                                        assignment.address,
                                        maxLines: 1,
                                        overflow: TextOverflow.ellipsis,
                                        style: Theme.of(context)
                                            .textTheme
                                            .bodySmall
                                            ?.copyWith(
                                              color: FoodexBrand.muted,
                                            ),
                                      ),
                                    ],
                                    if (primaryStatus != null) ...[
                                      const SizedBox(height: 12),
                                      FilledButton.icon(
                                        key: Key(
                                          'driver-primary-action-${assignment.id}',
                                        ),
                                        onPressed:
                                            _transitioning.contains(assignment.id)
                                                ? null
                                                : () => _performDeliveryDecision(
                                                      assignment,
                                                      primaryStatus,
                                                    ),
                                        icon: Icon(
                                          primaryStatus == 'out_for_delivery'
                                              ? Icons.local_shipping_rounded
                                              : Icons.task_alt_rounded,
                                        ),
                                        label: Text(
                                          _primaryActionLabel(primaryStatus),
                                        ),
                                      ),
                                    ],
                                  ],
                                ),
                              ),
                            ),
                          );
                        },
                      ),
                    ),
            },
          ),
        ],
      ),
    );
  }
}

class _DriverFilterBar extends StatelessWidget {
  const _DriverFilterBar({
    required this.value,
    required this.onChanged,
  });

  final DriverOrderFilter value;
  final ValueChanged<DriverOrderFilter> onChanged;

  @override
  Widget build(BuildContext context) {
    final options = [
      (DriverOrderFilter.active, 'driver.filter.active'),
      (DriverOrderFilter.completed, 'driver.filter.completed'),
      (DriverOrderFilter.failed, 'driver.filter.failed'),
      (DriverOrderFilter.all, 'driver.filter.all'),
    ];

    return Container(
      key: const Key('driver-order-filter-bar'),
      padding: const EdgeInsets.all(4),
      decoration: BoxDecoration(
        color: FoodexBrand.surfaceMuted,
        borderRadius: BorderRadius.circular(14),
      ),
      child: Row(
        children: [
          for (var i = 0; i < options.length; i++) ...[
            Expanded(
              child: Material(
                color: options[i].$1 == value
                    ? FoodexBrand.surface
                    : Colors.transparent,
                borderRadius: BorderRadius.circular(11),
                child: InkWell(
                  key: Key('driver-filter-${options[i].$1.name}'),
                  onTap: () => onChanged(options[i].$1),
                  borderRadius: BorderRadius.circular(11),
                  child: SizedBox(
                    height: 38,
                    child: Center(
                      child: Text(
                        context.tr(options[i].$2),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: TextStyle(
                          fontSize: 11,
                          fontWeight: FontWeight.w800,
                          color: options[i].$1 == value
                              ? FoodexBrand.greenDark
                              : FoodexBrand.muted,
                        ),
                      ),
                    ),
                  ),
                ),
              ),
            ),
            if (i != options.length - 1) const SizedBox(width: 3),
          ],
        ],
      ),
    );
  }
}

class _DetailLine extends StatelessWidget {
  const _DetailLine({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 5),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            SizedBox(
              width: 112,
              child: Text(
                label,
                style: const TextStyle(fontWeight: FontWeight.w700),
              ),
            ),
            Expanded(child: Text(value.isEmpty ? '—' : value)),
          ],
        ),
      );
}

class _Retry extends StatelessWidget {
  const _Retry({
    required this.message,
    required this.retryLabel,
    required this.onRetry,
    required this.keyName,
  });

  final String message;
  final String retryLabel;
  final VoidCallback onRetry;
  final String keyName;

  @override
  Widget build(BuildContext context) => Center(
        key: Key(keyName),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(message),
            const SizedBox(height: 12),
            FilledButton(onPressed: onRetry, child: Text(retryLabel)),
          ],
        ),
      );
}
