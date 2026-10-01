import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_driver_app/core/localization/driver_translations.dart';
import 'package:foodex_driver_app/features/delivery/completion/driver_completion_contract.dart';
import 'package:foodex_driver_app/features/delivery/completion/driver_completion_sheet.dart';
import 'package:foodex_driver_app/features/delivery/completion/driver_proof_picker.dart';

void main() {
  testWidgets('delivered action stays server-gated and proof-gated', (tester) async {
    final gateway = _RecordingGateway();

    await tester.pumpWidget(
      DriverTranslations(
        locale: const Locale('en'),
        overrides: const {},
        child: MaterialApp(
          home: Scaffold(
            body: DriverCompletionDecisionSheet(
              assignmentId: 31,
              availableStatuses: const ['delivered', 'failed'],
              gateway: gateway,
              proofPicker: const _FakeProofPicker(),
            ),
          ),
        ),
      ),
    );

    await tester.tap(find.byKey(const ValueKey('driver-completion-delivered')));
    await tester.pump();

    expect(gateway.calls, isEmpty);
    expect(
      find.byKey(const ValueKey('driver-completion-validation-error')),
      findsOneWidget,
    );
    expect(
      find.text('Attach a proof photo before completing delivery.'),
      findsOneWidget,
    );

    await tester.tap(
      find.byKey(const ValueKey('driver-completion-proof-gallery')),
    );
    await tester.pump();

    expect(
      find.byKey(const ValueKey('driver-completion-proof-attached')),
      findsOneWidget,
    );

    await tester.tap(find.byKey(const ValueKey('driver-completion-delivered')));
    await tester.pump();

    expect(gateway.calls, hasLength(1));
    expect(gateway.calls.single.target, DriverCompletionTarget.delivered);
    expect(gateway.calls.single.proof?.fileName, 'proof.jpg');
  });

  testWidgets('unavailable server action is not rendered', (tester) async {
    await tester.pumpWidget(
      DriverTranslations(
        locale: const Locale('en'),
        overrides: const {},
        child: MaterialApp(
          home: Scaffold(
            body: DriverCompletionDecisionSheet(
              assignmentId: 31,
              availableStatuses: const ['failed'],
              gateway: _RecordingGateway(),
              proofPicker: const _FakeProofPicker(),
            ),
          ),
        ),
      ),
    );

    expect(
      find.byKey(const ValueKey('driver-completion-delivered')),
      findsNothing,
    );
    expect(
      find.byKey(const ValueKey('driver-completion-failed')),
      findsOneWidget,
    );
  });
}

class _RecordingGateway implements DriverCompletionGateway {
  final List<DriverCompletionDraft> calls = [];

  @override
  Future<DriverCompletionResult> submit({
    required int assignmentId,
    required DriverCompletionDraft draft,
  }) async {
    calls.add(draft);
    throw const DriverCompletionException('test_stop');
  }
}

class _FakeProofPicker implements DriverProofPicker {
  const _FakeProofPicker();

  @override
  Future<DriverProofAttachment?> pickCamera() async => proof;

  @override
  Future<DriverProofAttachment?> pickGallery() async => proof;

  static const proof = DriverProofAttachment(
    path: '/tmp/proof.jpg',
    fileName: 'proof.jpg',
    byteLength: 1024,
    mimeType: 'image/jpeg',
  );
}
