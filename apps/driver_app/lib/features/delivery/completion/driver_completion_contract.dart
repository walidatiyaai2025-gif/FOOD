enum DriverCompletionTarget {
  delivered('delivered'),
  failed('failed');

  const DriverCompletionTarget(this.status);

  final String status;
}

class DriverFailureReason {
  const DriverFailureReason._();

  static const customerNoAnswer = 'customer_no_answer';
  static const wrongAddress = 'wrong_address';
  static const customerRefused = 'customer_refused';
  static const customerAbsent = 'customer_absent';
  static const paymentIssue = 'payment_issue';
  static const orderIssue = 'order_issue';
  static const other = 'other';

  static const values = <String>[
    customerNoAnswer,
    wrongAddress,
    customerRefused,
    customerAbsent,
    paymentIssue,
    orderIssue,
    other,
  ];

  static bool isValid(String? value) =>
      value != null && values.contains(value);
}

class DriverFailureReasonOption {
  const DriverFailureReasonOption({
    required this.code,
    required this.labelAr,
    required this.labelEn,
  });

  final String code;
  final String labelAr;
  final String labelEn;

  String labelFor(String languageCode) =>
      languageCode.toLowerCase() == 'ar' ? labelAr : labelEn;
}

abstract interface class DriverFailureReasonCatalog {
  Future<List<DriverFailureReasonOption>> failedDeliveryReasons();
}

class DriverProofAttachment {
  const DriverProofAttachment({
    required this.path,
    required this.fileName,
    required this.byteLength,
    this.mimeType,
  });

  static const maxBytes = 5 * 1024 * 1024;

  final String path;
  final String fileName;
  final int byteLength;
  final String? mimeType;

  bool get isWithinSizeLimit => byteLength > 0 && byteLength <= maxBytes;
}

enum DriverCompletionValidationError {
  proofRequired,
  proofTooLarge,
  failureReasonRequired,
  otherReasonNoteRequired,
}

class DriverCompletionDraft {
  const DriverCompletionDraft({
    required this.target,
    this.note = '',
    this.failureReason,
    this.proof,
  });

  final DriverCompletionTarget target;
  final String note;
  final String? failureReason;
  final DriverProofAttachment? proof;

  DriverCompletionValidationError? get validationError {
    if (proof != null && !proof!.isWithinSizeLimit) {
      return DriverCompletionValidationError.proofTooLarge;
    }

    if (target == DriverCompletionTarget.delivered && proof == null) {
      return DriverCompletionValidationError.proofRequired;
    }

    if (target == DriverCompletionTarget.failed) {
      if (!DriverFailureReason.isValid(failureReason)) {
        return DriverCompletionValidationError.failureReasonRequired;
      }

      if (failureReason == DriverFailureReason.other && note.trim().isEmpty) {
        return DriverCompletionValidationError.otherReasonNoteRequired;
      }
    }

    return null;
  }

  bool get canSubmit => validationError == null;

  DriverCompletionDraft copyWith({
    DriverCompletionTarget? target,
    String? note,
    String? failureReason,
    bool clearFailureReason = false,
    DriverProofAttachment? proof,
    bool clearProof = false,
  }) =>
      DriverCompletionDraft(
        target: target ?? this.target,
        note: note ?? this.note,
        failureReason:
            clearFailureReason ? null : (failureReason ?? this.failureReason),
        proof: clearProof ? null : (proof ?? this.proof),
      );
}

class DriverCompletionResult {
  const DriverCompletionResult({
    required this.assignmentId,
    required this.status,
    required this.payload,
  });

  final int assignmentId;
  final String status;
  final Map<String, dynamic> payload;

  factory DriverCompletionResult.fromJson(
    int assignmentId,
    Map<String, dynamic> json,
  ) {
    final raw = json['data'];
    final payload = raw is Map
        ? Map<String, dynamic>.from(raw)
        : <String, dynamic>{};

    return DriverCompletionResult(
      assignmentId: assignmentId,
      status: payload['status']?.toString() ?? '',
      payload: payload,
    );
  }
}

abstract interface class DriverCompletionGateway {
  Future<DriverCompletionResult> submit({
    required int assignmentId,
    required DriverCompletionDraft draft,
  });
}

class DriverCompletionException implements Exception {
  const DriverCompletionException(
    this.code, {
    this.message,
  });

  final String code;
  final String? message;

  @override
  String toString() => message ?? code;
}
