import 'package:flutter/material.dart';

import '../../../core/localization/driver_translations.dart';
import 'driver_completion_contract.dart';
import 'driver_proof_picker.dart';

Future<DriverCompletionResult?> showDriverCompletionDecisionSheet({
  required BuildContext context,
  required int assignmentId,
  required List<String> availableStatuses,
  required DriverCompletionGateway gateway,
  DriverProofPicker? proofPicker,
  List<DriverFailureReasonOption>? failureReasons,
  DriverCompletionTarget initialTarget = DriverCompletionTarget.delivered,
  String initialNote = '',
  VoidCallback? onSessionExpired,
}) =>
    showModalBottomSheet<DriverCompletionResult>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (_) => DriverCompletionDecisionSheet(
        assignmentId: assignmentId,
        availableStatuses: availableStatuses,
        gateway: gateway,
        proofPicker: proofPicker ?? ImagePickerDriverProofPicker(),
        failureReasons: failureReasons,
        initialTarget: initialTarget,
        initialNote: initialNote,
        onSessionExpired: onSessionExpired,
      ),
    );

class DriverCompletionDecisionSheet extends StatefulWidget {
  const DriverCompletionDecisionSheet({
    required this.assignmentId,
    required this.availableStatuses,
    required this.gateway,
    required this.proofPicker,
    this.failureReasons,
    this.initialTarget = DriverCompletionTarget.delivered,
    this.initialNote = '',
    this.onSessionExpired,
    this.onSubmitted,
    super.key,
  });

  final int assignmentId;
  final List<String> availableStatuses;
  final DriverCompletionGateway gateway;
  final DriverProofPicker proofPicker;
  final List<DriverFailureReasonOption>? failureReasons;
  final DriverCompletionTarget initialTarget;
  final String initialNote;
  final VoidCallback? onSessionExpired;
  final ValueChanged<DriverCompletionResult>? onSubmitted;

  @override
  State<DriverCompletionDecisionSheet> createState() =>
      _DriverCompletionDecisionSheetState();
}

class _DriverCompletionDecisionSheetState
    extends State<DriverCompletionDecisionSheet> {
  late DriverCompletionDraft _draft;
  DriverCompletionValidationError? _validationError;
  Object? _submitError;
  bool _submitting = false;

  @override
  void initState() {
    super.initState();
    _draft = DriverCompletionDraft(
      target: widget.initialTarget,
      note: widget.initialNote,
    );
  }

  bool get _canDeliver =>
      widget.availableStatuses.contains(DriverCompletionTarget.delivered.status);

  bool get _canFail =>
      widget.availableStatuses.contains(DriverCompletionTarget.failed.status);

  Future<void> _pickProof({required bool camera}) async {
    if (_submitting) return;

    final attachment = camera
        ? await widget.proofPicker.pickCamera()
        : await widget.proofPicker.pickGallery();
    if (!mounted || attachment == null) return;

    setState(() {
      _draft = _draft.copyWith(proof: attachment);
      _validationError = attachment.isWithinSizeLimit
          ? null
          : DriverCompletionValidationError.proofTooLarge;
      _submitError = null;
    });
  }

  void _removeProof() {
    if (_submitting) return;
    setState(() {
      _draft = _draft.copyWith(clearProof: true);
      _validationError = null;
      _submitError = null;
    });
  }

  void _chooseFailureMode() {
    if (_submitting || !_canFail) return;
    setState(() {
      _draft = _draft.copyWith(target: DriverCompletionTarget.failed);
      _validationError = null;
      _submitError = null;
    });
  }

  Future<void> _submit(DriverCompletionTarget target) async {
    if (_submitting) return;
    if (target == DriverCompletionTarget.delivered && !_canDeliver) return;
    if (target == DriverCompletionTarget.failed && !_canFail) return;

    final candidate = _draft.copyWith(
      target: target,
      clearFailureReason: target == DriverCompletionTarget.delivered,
    );
    final validation = candidate.validationError;
    if (validation != null) {
      setState(() {
        _draft = candidate;
        _validationError = validation;
        _submitError = null;
      });
      return;
    }

    setState(() {
      _draft = candidate;
      _validationError = null;
      _submitError = null;
      _submitting = true;
    });

    try {
      final result = await widget.gateway.submit(
        assignmentId: widget.assignmentId,
        draft: candidate,
      );
      if (!mounted) return;

      widget.onSubmitted?.call(result);
      Navigator.of(context).pop(result);
    } catch (error) {
      if (!mounted) return;
      if (error is DriverCompletionException &&
          error.code == 'session_expired' &&
          widget.onSessionExpired != null) {
        widget.onSessionExpired!.call();
        Navigator.of(context).pop();
        return;
      }
      setState(() {
        _submitting = false;
        _submitError = error;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final failureMode = _draft.target == DriverCompletionTarget.failed;
    final validationText = _validationError == null
        ? null
        : _validationMessage(context, _validationError!);
    final submitText =
        _submitError == null ? null : _submitErrorMessage(context, _submitError!);

    return Padding(
      padding: EdgeInsets.only(
        left: 20,
        right: 20,
        top: 16,
        bottom: MediaQuery.viewInsetsOf(context).bottom + 20,
      ),
      child: SingleChildScrollView(
        child: Column(
          key: const ValueKey('driver-completion-sheet'),
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    failureMode
                        ? context.tr('driver.failure.title')
                        : context.tr('driver.action.delivered'),
                    style: Theme.of(context).textTheme.titleLarge?.copyWith(
                          fontWeight: FontWeight.w800,
                        ),
                  ),
                ),
                IconButton(
                  onPressed:
                      _submitting ? null : () => Navigator.of(context).pop(),
                  tooltip: MaterialLocalizations.of(context).closeButtonTooltip,
                  icon: const Icon(Icons.close_rounded),
                ),
              ],
            ),
            const SizedBox(height: 12),
            if (failureMode) ...[
              DropdownButtonFormField<String>(
                key: ValueKey(
                  'driver-completion-failure-${_draft.failureReason ?? 'none'}',
                ),
                initialValue: _draft.failureReason,
                decoration: InputDecoration(
                  labelText: context.tr('driver.failure.reason'),
                  border: const OutlineInputBorder(),
                ),
                items: (widget.failureReasons ??
                        DriverFailureReason.values
                            .map(
                              (code) => DriverFailureReasonOption(
                                code: code,
                                labelAr:
                                    context.tr('driver.failure.reason.$code'),
                                labelEn:
                                    context.tr('driver.failure.reason.$code'),
                              ),
                            )
                            .toList(growable: false))
                    .map(
                      (reason) => DropdownMenuItem<String>(
                        value: reason.code,
                        child: Text(
                          widget.failureReasons == null
                              ? context.tr(
                                  'driver.failure.reason.${reason.code}',
                                )
                              : reason.labelFor(
                                  Localizations.localeOf(context).languageCode,
                                ),
                        ),
                      ),
                    )
                    .toList(growable: false),
                onChanged: _submitting
                    ? null
                    : (value) => setState(() {
                          _draft = _draft.copyWith(failureReason: value);
                          _validationError = null;
                          _submitError = null;
                        }),
              ),
              const SizedBox(height: 12),
            ],
            TextField(
              key: const ValueKey('driver-completion-note'),
              enabled: !_submitting,
              minLines: 2,
              maxLines: 4,
              onChanged: (value) => setState(() {
                _draft = _draft.copyWith(note: value);
                _validationError = null;
                _submitError = null;
              }),
              decoration: InputDecoration(
                labelText: failureMode
                    ? context.tr('driver.failure.note_optional')
                    : context.tr('driver.action.note_optional'),
                border: const OutlineInputBorder(),
              ),
            ),
            const SizedBox(height: 16),
            Text(
              context.tr('driver.action.attach_proof'),
              style: Theme.of(context).textTheme.titleSmall?.copyWith(
                    fontWeight: FontWeight.w800,
                  ),
            ),
            const SizedBox(height: 8),
            Row(
              children: [
                Expanded(
                  child: OutlinedButton.icon(
                    key: const ValueKey('driver-completion-proof-camera'),
                    onPressed:
                        _submitting ? null : () => _pickProof(camera: true),
                    icon: const Icon(Icons.photo_camera_rounded),
                    label: Text(context.tr('driver.proof.camera')),
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: OutlinedButton.icon(
                    key: const ValueKey('driver-completion-proof-gallery'),
                    onPressed:
                        _submitting ? null : () => _pickProof(camera: false),
                    icon: const Icon(Icons.photo_library_rounded),
                    label: Text(context.tr('driver.proof.gallery')),
                  ),
                ),
              ],
            ),
            if (_draft.proof != null) ...[
              const SizedBox(height: 10),
              Container(
                key: const ValueKey('driver-completion-proof-attached'),
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  border: Border.all(
                    color: Theme.of(context).colorScheme.outlineVariant,
                  ),
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
                            context.tr('driver.proof.attached'),
                            style: const TextStyle(fontWeight: FontWeight.w700),
                          ),
                          Text(
                            _draft.proof!.fileName,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                          ),
                          Text(
                            '${(_draft.proof!.byteLength / (1024 * 1024)).toStringAsFixed(1)} MB',
                            style: Theme.of(context).textTheme.bodySmall,
                          ),
                        ],
                      ),
                    ),
                    TextButton(
                      key: const ValueKey('driver-completion-proof-remove'),
                      onPressed: _submitting ? null : _removeProof,
                      child: Text(context.tr('driver.proof.remove')),
                    ),
                  ],
                ),
              ),
            ],
            if (validationText != null) ...[
              const SizedBox(height: 10),
              _InlineError(
                key: const ValueKey('driver-completion-validation-error'),
                text: validationText,
              ),
            ],
            if (submitText != null) ...[
              const SizedBox(height: 10),
              _InlineError(
                key: const ValueKey('driver-completion-submit-error'),
                text: submitText,
              ),
            ],
            if (_submitting) ...[
              const SizedBox(height: 14),
              const LinearProgressIndicator(
                key: ValueKey('driver-completion-progress'),
              ),
              const SizedBox(height: 8),
              Text(
                context.tr('driver.completion.uploading'),
                textAlign: TextAlign.center,
              ),
            ],
            const SizedBox(height: 18),
            if (_canFail)
              OutlinedButton(
                key: const ValueKey('driver-completion-failed'),
                onPressed: _submitting
                    ? null
                    : failureMode
                        ? () => _submit(DriverCompletionTarget.failed)
                        : _chooseFailureMode,
                child: Text(
                  failureMode
                      ? context.tr('driver.failure.submit')
                      : context.tr('driver.action.delivery_failed'),
                ),
              ),
            if (_canFail && _canDeliver) const SizedBox(height: 8),
            if (_canDeliver)
              FilledButton.icon(
                key: const ValueKey('driver-completion-delivered'),
                onPressed: _submitting
                    ? null
                    : () => _submit(DriverCompletionTarget.delivered),
                icon: const Icon(Icons.check_circle_outline_rounded),
                label: Text(context.tr('driver.action.confirm_delivered')),
              ),
          ],
        ),
      ),
    );
  }
}

class _InlineError extends StatelessWidget {
  const _InlineError({
    required this.text,
    super.key,
  });

  final String text;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.all(10),
        decoration: BoxDecoration(
          color: Theme.of(context).colorScheme.errorContainer,
          borderRadius: BorderRadius.circular(10),
        ),
        child: Text(
          text,
          style: TextStyle(
            color: Theme.of(context).colorScheme.onErrorContainer,
            fontWeight: FontWeight.w600,
          ),
        ),
      );
}

String _validationMessage(
  BuildContext context,
  DriverCompletionValidationError error,
) =>
    switch (error) {
      DriverCompletionValidationError.proofRequired =>
        context.tr('driver.completion.proof_required'),
      DriverCompletionValidationError.proofTooLarge =>
        context.tr('driver.completion.proof_too_large'),
      DriverCompletionValidationError.failureReasonRequired =>
        context.tr('driver.completion.failure_reason_required'),
      DriverCompletionValidationError.otherReasonNoteRequired =>
        context.tr('driver.completion.other_note_required'),
    };

String _submitErrorMessage(BuildContext context, Object error) {
  if (error is DriverCompletionException) {
    if (error.code == 'network_unavailable') {
      return context.tr('driver.offline');
    }
  }
  return context.tr('driver.completion.submit_failed');
}
