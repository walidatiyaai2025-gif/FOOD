import 'package:flutter/material.dart';

import '../../core/theme/foodex_van_theme.dart';
import 'van_order_contract.dart';
import 'van_order_proof_picker.dart';

enum VanDeliveryEvidenceMode { proof, failure }

class VanDeliveryEvidenceDraft {
  const VanDeliveryEvidenceDraft({
    required this.mode,
    required this.note,
    this.failureReason,
    this.proof,
  });

  final VanDeliveryEvidenceMode mode;
  final String note;
  final String? failureReason;
  final VanProofAttachment? proof;
}

Future<VanDeliveryEvidenceDraft?> showVanDeliveryEvidenceSheet({
  required BuildContext context,
  required VanDeliveryEvidenceMode mode,
  required VanOrderProofPicker proofPicker,
  List<VanFailureReasonOption> failureReasons = const [],
}) =>
    showModalBottomSheet<VanDeliveryEvidenceDraft>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (_) => _VanDeliveryEvidenceSheet(
        mode: mode,
        proofPicker: proofPicker,
        failureReasons: failureReasons,
      ),
    );

class _VanDeliveryEvidenceSheet extends StatefulWidget {
  const _VanDeliveryEvidenceSheet({
    required this.mode,
    required this.proofPicker,
    required this.failureReasons,
  });

  final VanDeliveryEvidenceMode mode;
  final VanOrderProofPicker proofPicker;
  final List<VanFailureReasonOption> failureReasons;

  @override
  State<_VanDeliveryEvidenceSheet> createState() =>
      _VanDeliveryEvidenceSheetState();
}

class _VanDeliveryEvidenceSheetState
    extends State<_VanDeliveryEvidenceSheet> {
  VanProofAttachment? _proof;
  String? _failureReason;
  String _note = '';
  String? _validation;
  bool _picking = false;

  bool get _isFailure => widget.mode == VanDeliveryEvidenceMode.failure;

  String _text(String en, String ar) =>
      Localizations.localeOf(context).languageCode == 'ar' ? ar : en;

  Future<void> _pick({required bool camera}) async {
    if (_picking) return;
    setState(() {
      _picking = true;
      _validation = null;
    });
    try {
      final picked = camera
          ? await widget.proofPicker.pickCamera()
          : await widget.proofPicker.pickGallery();
      if (!mounted || picked == null) return;
      setState(() {
        _proof = picked;
        _validation = picked.isWithinSizeLimit
            ? null
            : _text(
                'Proof image must be 5 MB or smaller.',
                'يجب ألا يتجاوز حجم صورة الإثبات 5 ميجابايت.',
              );
      });
    } finally {
      if (mounted) setState(() => _picking = false);
    }
  }

  void _submit() {
    final proof = _proof;
    if (proof != null && !proof.isWithinSizeLimit) {
      setState(() {
        _validation = _text(
          'Proof image must be 5 MB or smaller.',
          'يجب ألا يتجاوز حجم صورة الإثبات 5 ميجابايت.',
        );
      });
      return;
    }

    if (!_isFailure && proof == null) {
      setState(() {
        _validation = _text(
          'Select a delivery proof image.',
          'اختر صورة إثبات التسليم.',
        );
      });
      return;
    }

    final reason = _failureReason?.trim();
    if (_isFailure && (reason == null || reason.isEmpty)) {
      setState(() {
        _validation = _text(
          'Select a configured failure reason.',
          'اختر سبب التعذر من القائمة المعتمدة.',
        );
      });
      return;
    }
    if (_isFailure && reason == 'other' && _note.trim().isEmpty) {
      setState(() {
        _validation = _text(
          'Add a note when the reason is Other.',
          'أضف ملاحظة عند اختيار سبب «أخرى».',
        );
      });
      return;
    }

    Navigator.of(context).pop(
      VanDeliveryEvidenceDraft(
        mode: widget.mode,
        note: _note.trim(),
        failureReason: reason,
        proof: proof,
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final language = Localizations.localeOf(context).languageCode;

    return Padding(
      padding: EdgeInsets.only(
        left: 18,
        right: 18,
        top: 14,
        bottom: MediaQuery.viewInsetsOf(context).bottom + 18,
      ),
      child: SingleChildScrollView(
        child: Column(
          key: ValueKey(
            _isFailure
                ? 'van-failure-evidence-sheet'
                : 'van-proof-evidence-sheet',
          ),
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    _isFailure
                        ? _text('Failed delivery', 'تعذر التسليم')
                        : _text('Delivery proof', 'إثبات التسليم'),
                    style: Theme.of(context).textTheme.titleLarge?.copyWith(
                          fontWeight: FontWeight.w900,
                        ),
                  ),
                ),
                IconButton(
                  onPressed:
                      _picking ? null : () => Navigator.of(context).pop(),
                  tooltip: MaterialLocalizations.of(context).closeButtonTooltip,
                  icon: const Icon(Icons.close_rounded),
                ),
              ],
            ),
            if (_isFailure) ...[
              const SizedBox(height: 10),
              DropdownButtonFormField<String>(
                key: const ValueKey('van-failure-reason'),
                initialValue: _failureReason,
                isExpanded: true,
                decoration: InputDecoration(
                  labelText: _text('Failure reason', 'سبب التعذر'),
                  border: const OutlineInputBorder(),
                ),
                items: widget.failureReasons
                    .map(
                      (reason) => DropdownMenuItem<String>(
                        value: reason.code,
                        child: Text(reason.labelFor(language)),
                      ),
                    )
                    .toList(growable: false),
                onChanged: _picking
                    ? null
                    : (value) => setState(() {
                          _failureReason = value;
                          _validation = null;
                        }),
              ),
            ],
            const SizedBox(height: 12),
            TextField(
              key: const ValueKey('van-evidence-note'),
              enabled: !_picking,
              minLines: 2,
              maxLines: 4,
              onChanged: (value) => setState(() {
                _note = value;
                _validation = null;
              }),
              decoration: InputDecoration(
                labelText: _isFailure
                    ? _text(
                        'Operational note (required for Other)',
                        'ملاحظة تشغيلية (مطلوبة عند اختيار أخرى)',
                      )
                    : _text('Optional note', 'ملاحظة اختيارية'),
                border: const OutlineInputBorder(),
              ),
            ),
            const SizedBox(height: 14),
            Text(
              _isFailure
                  ? _text(
                      'Optional photo evidence',
                      'صورة إثبات اختيارية',
                    )
                  : _text('Proof image', 'صورة الإثبات'),
              style: const TextStyle(fontWeight: FontWeight.w800),
            ),
            const SizedBox(height: 8),
            Row(
              children: [
                Expanded(
                  child: OutlinedButton.icon(
                    key: const ValueKey('van-proof-camera'),
                    onPressed: _picking ? null : () => _pick(camera: true),
                    icon: const Icon(Icons.photo_camera_outlined),
                    label: Text(_text('Camera', 'الكاميرا')),
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: OutlinedButton.icon(
                    key: const ValueKey('van-proof-gallery'),
                    onPressed: _picking ? null : () => _pick(camera: false),
                    icon: const Icon(Icons.photo_library_outlined),
                    label: Text(_text('Gallery', 'المعرض')),
                  ),
                ),
              ],
            ),
            if (_picking) ...[
              const SizedBox(height: 10),
              const LinearProgressIndicator(),
            ],
            if (_proof != null) ...[
              const SizedBox(height: 10),
              Container(
                key: const ValueKey('van-proof-attached'),
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  border: Border.all(color: FoodexVanTokens.border),
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Row(
                  children: [
                    const Icon(Icons.image_outlined),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            _proof!.fileName,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: const TextStyle(fontWeight: FontWeight.w700),
                          ),
                          Text(
                            '${(_proof!.byteLength / (1024 * 1024)).toStringAsFixed(1)} MB',
                            style: Theme.of(context).textTheme.bodySmall,
                          ),
                        ],
                      ),
                    ),
                    TextButton(
                      key: const ValueKey('van-proof-remove'),
                      onPressed: _picking
                          ? null
                          : () => setState(() {
                                _proof = null;
                                _validation = null;
                              }),
                      child: Text(_text('Remove', 'إزالة')),
                    ),
                  ],
                ),
              ),
            ],
            if (_validation != null) ...[
              const SizedBox(height: 10),
              Container(
                key: const ValueKey('van-evidence-validation'),
                padding: const EdgeInsets.all(10),
                decoration: BoxDecoration(
                  color: Theme.of(context).colorScheme.errorContainer,
                  borderRadius: BorderRadius.circular(10),
                ),
                child: Text(
                  _validation!,
                  style: TextStyle(
                    color: Theme.of(context).colorScheme.onErrorContainer,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ),
            ],
            const SizedBox(height: 16),
            FilledButton.icon(
              key: ValueKey(
                _isFailure ? 'van-failure-submit' : 'van-proof-submit',
              ),
              onPressed: _picking ? null : _submit,
              icon: Icon(
                _isFailure
                    ? Icons.report_problem_outlined
                    : Icons.cloud_upload_outlined,
              ),
              label: Text(
                _isFailure
                    ? _text('Confirm failed delivery', 'تأكيد تعذر التسليم')
                    : _text('Upload proof', 'رفع الإثبات'),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
