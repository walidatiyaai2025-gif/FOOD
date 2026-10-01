import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_driver_app/features/delivery/completion/driver_completion_contract.dart';

void main() {
  const validProof = DriverProofAttachment(
    path: '/tmp/proof.jpg',
    fileName: 'proof.jpg',
    byteLength: 1024,
    mimeType: 'image/jpeg',
  );

  test('delivered requires proof and accepts a valid attachment', () {
    const missing = DriverCompletionDraft(
      target: DriverCompletionTarget.delivered,
    );
    expect(
      missing.validationError,
      DriverCompletionValidationError.proofRequired,
    );

    const complete = DriverCompletionDraft(
      target: DriverCompletionTarget.delivered,
      note: 'left with customer',
      proof: validProof,
    );
    expect(complete.validationError, isNull);
    expect(complete.canSubmit, isTrue);
  });

  test('proof larger than backend limit is rejected before upload', () {
    const draft = DriverCompletionDraft(
      target: DriverCompletionTarget.delivered,
      proof: DriverProofAttachment(
        path: '/tmp/large.jpg',
        fileName: 'large.jpg',
        byteLength: DriverProofAttachment.maxBytes + 1,
      ),
    );

    expect(
      draft.validationError,
      DriverCompletionValidationError.proofTooLarge,
    );
  });

  test('failed delivery requires official reason and other requires note', () {
    const missingReason = DriverCompletionDraft(
      target: DriverCompletionTarget.failed,
    );
    expect(
      missingReason.validationError,
      DriverCompletionValidationError.failureReasonRequired,
    );

    const otherWithoutNote = DriverCompletionDraft(
      target: DriverCompletionTarget.failed,
      failureReason: DriverFailureReason.other,
    );
    expect(
      otherWithoutNote.validationError,
      DriverCompletionValidationError.otherReasonNoteRequired,
    );

    const validFailure = DriverCompletionDraft(
      target: DriverCompletionTarget.failed,
      failureReason: DriverFailureReason.customerNoAnswer,
    );
    expect(validFailure.validationError, isNull);
  });
}
