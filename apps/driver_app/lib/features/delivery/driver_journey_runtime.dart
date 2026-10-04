import 'package:flutter/material.dart';

import '../../core/auth/driver_session.dart';
import '../../core/localization/driver_translations.dart';
import '../../core/navigation/driver_shell.dart';
import '../../core/navigation/driver_navigation.dart';
import '../../core/preview/driver_preview_context.dart';
import 'active/driver_active_journey.dart';
import 'completion/driver_completion_contract.dart';
import 'completion/driver_completion_sheet.dart';
import 'driver_assignment_contract.dart';

class DriverJourneyRuntimePage extends StatelessWidget {
  const DriverJourneyRuntimePage({
    super.key,
    required this.channel,
    required this.repository,
    this.onSessionExpired,
    this.focusAssignmentId,
    this.initialAssignmentStatus,
    this.navigationLauncher = launchDriverNavigation,
    this.addressNavigationLauncher = launchDriverAddressNavigation,
    this.previewContext,
    this.homeRoute,
    this.deliveriesRoute,
    this.notificationsRoute,
  });

  final DriverChannel channel;
  final DriverAssignmentRepository repository;
  final VoidCallback? onSessionExpired;
  final int? focusAssignmentId;
  final String? initialAssignmentStatus;
  final DriverNavigationLauncher navigationLauncher;
  final DriverAddressNavigationLauncher addressNavigationLauncher;
  final DriverPreviewContext? previewContext;
  final String? homeRoute;
  final String? deliveriesRoute;
  final String? notificationsRoute;

  Future<void> _openCompletion(
    BuildContext context,
    DriverAssignment assignment, {
    required DriverCompletionTarget target,
    String? note,
  }) async {
    List<DriverFailureReasonOption>? failureReasons;
    final failureCatalog = repository;
    if (assignment.availableStatuses.contains('failed') &&
        failureCatalog is DriverFailureReasonCatalog) {
      try {
        failureReasons = await failureCatalog.failedDeliveryReasons();
        if (failureReasons.isEmpty) {
          throw const DriverApiException(
            'No active failed-delivery reasons are available.',
          );
        }
      } on DriverSessionExpiredException {
        onSessionExpired?.call();
        return;
      } on DriverOfflineException {
        if (context.mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text(context.tr('driver.offline'))),
          );
        }
        return;
      } catch (_) {
        if (context.mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text(context.tr('driver.error'))),
          );
        }
        return;
      }
    }

    if (!context.mounted) return;
    await showDriverCompletionDecisionSheet(
      context: context,
      assignmentId: assignment.id,
      availableStatuses: assignment.availableStatuses,
      gateway: _DriverRepositoryCompletionGateway(
        repository: repository,
        channel: channel,
      ),
      failureReasons: failureReasons,
      initialTarget: target,
      initialNote: note ?? '',
      onSessionExpired: onSessionExpired,
    );
  }

  @override
  Widget build(BuildContext context) => DriverShellScaffold(
        destination: DriverShellDestination.deliveries,
        homeRoute: homeRoute,
        deliveriesRoute: deliveriesRoute,
        notificationsRoute: notificationsRoute,
        title: Text(
          context.tr(
            channel == DriverChannel.b2c
                ? 'driver.b2c.title'
                : 'driver.b2b.title',
          ),
        ),
        body: DriverActiveJourneyPage(
        channel: channel,
        repository: repository,
        focusAssignmentId: focusAssignmentId,
        initialAssignmentStatus: initialAssignmentStatus,
        previewContext: previewContext,
        onSessionExpired: onSessionExpired,
        onNavigationRequested: (assignment) async {
          final preview = previewContext;
          if (preview != null && !preview.nativeNavigationEnabled) {
            ScaffoldMessenger.of(context).showSnackBar(
              SnackBar(
                content: Text(
                  context.tr('driver.preview.navigation_simulated'),
                ),
              ),
            );
            return;
          }

          final latitude = assignment.navigationLatitude;
          final longitude = assignment.navigationLongitude;
          final address = assignment.address.trim();
          final opened = latitude != null && longitude != null
              ? await navigationLauncher(latitude, longitude)
              : address.isNotEmpty
                  ? await addressNavigationLauncher(address)
                  : false;
          if (!opened && context.mounted) {
            ScaffoldMessenger.of(context).showSnackBar(
              SnackBar(
                content: Text(context.tr('driver.navigation.unavailable')),
              ),
            );
          }
        },
        onFailedDeliveryRequested: (assignment, {note}) => _openCompletion(
          context,
          assignment,
          target: DriverCompletionTarget.failed,
          note: note,
        ),
        onDeliveredRequested: (assignment) => _openCompletion(
          context,
          assignment,
          target: DriverCompletionTarget.delivered,
        ),
      ),
    );
}

class _DriverRepositoryCompletionGateway implements DriverCompletionGateway {
  const _DriverRepositoryCompletionGateway({
    required this.repository,
    required this.channel,
  });

  final DriverAssignmentRepository repository;
  final DriverChannel channel;

  @override
  Future<DriverCompletionResult> submit({
    required int assignmentId,
    required DriverCompletionDraft draft,
  }) async {
    final validation = draft.validationError;
    if (validation != null) {
      throw DriverCompletionException(_validationCode(validation));
    }

    try {
      final proof = draft.proof;
      if (proof != null) {
        final proofRepository = repository;
        if (proofRepository is! DriverProofAssignmentRepository) {
          throw const DriverCompletionException('proof_upload_unavailable');
        }
        await proofRepository.transitionWithProof(
          assignmentId,
          channel,
          draft.target.status,
          proof.path,
          note: _normalized(draft.note),
          failureReason: _normalized(draft.failureReason),
        );
      } else {
        await repository.transition(
          assignmentId,
          channel,
          draft.target.status,
          note: _normalized(draft.note),
          failureReason: _normalized(draft.failureReason),
        );
      }
    } on DriverSessionExpiredException {
      throw const DriverCompletionException('session_expired');
    } on DriverAccessDeniedException {
      throw const DriverCompletionException('forbidden');
    } on DriverOfflineException {
      throw const DriverCompletionException('network_unavailable');
    } on DriverApiException catch (error) {
      throw DriverCompletionException(
        'driver_api_error',
        message: error.message,
      );
    }

    return DriverCompletionResult(
      assignmentId: assignmentId,
      status: draft.target.status,
      payload: <String, dynamic>{
        'id': assignmentId,
        'status': draft.target.status,
      },
    );
  }
}

String? _normalized(String? value) {
  final normalized = value?.trim() ?? '';
  return normalized.isEmpty ? null : normalized;
}

String _validationCode(DriverCompletionValidationError error) => switch (error) {
      DriverCompletionValidationError.proofRequired => 'proof_required',
      DriverCompletionValidationError.proofTooLarge => 'proof_too_large',
      DriverCompletionValidationError.failureReasonRequired =>
        'failure_reason_required',
      DriverCompletionValidationError.otherReasonNoteRequired =>
        'other_reason_note_required',
    };
