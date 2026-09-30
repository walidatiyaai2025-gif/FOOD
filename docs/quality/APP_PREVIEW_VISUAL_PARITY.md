# APP-PREVIEW Visual / Runtime Parity Gate

Issue: #575  
Parent: #498

This gate is the automated evidence that the Dashboard embedding boundary does not fork the real Customer or Driver Flutter preview runtime.

## What the gate proves

- Customer and Driver preview fixture suites exercise their real shared runtime contracts, including B2B/B2C scope, guest/authenticated behavior where supported by each runtime suite, Draft/Published configuration handling, safe read-only behavior, localization and failure/empty-state contracts already covered by the preview tests.
- CI compiles the committed `lib/preview_main.dart` entrypoint for both applications. No Dashboard clone is built.
- The exact compiled Flutter Web artifact is loaded both standalone and inside a Dashboard-shaped iframe host.
- The embedded host must emit the `shared-flutter-v1` ready handshake from the iframe window.
- Chromium captures the actual Flutter surface in both modes at representative phone widths and compares pixels. More than 0.1% changed pixels fails the gate.
- Evidence artifacts include standalone, embedded and diff PNGs plus `report.json` with the exact runtime SHA-256.

## Representative matrix

Customer: 360x800 small Android, 390x844 common iPhone, 430x900 large Android.  
Driver: 360x800 small Android, 390x844 common iPhone.

AR/EN, RTL/LTR, B2B/B2C, persona and Draft/Published semantic coverage remains in the Flutter preview test matrix; this browser layer specifically detects embedding drift without reimplementing business payloads.

## Failure semantics

The check fails when the real Web preview cannot compile, the shared preview contract handshake is missing, the iframe does not load the same runtime, or the embedded pixels drift beyond the documented tolerance. Native-only shell surfaces are outside the Web embedding boundary and must remain explicitly simulated by the shared runtime contract.
