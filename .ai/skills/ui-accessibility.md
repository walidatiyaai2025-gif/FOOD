# Skill: FOODEX UI Accessibility & Input Modality

Use for every user-facing page/screen. Accessibility is part of implementation, not post-release polish.

## 1. Existing FOODEX accessibility primitives

Dashboard shared source already includes:
- visible `:focus-visible`;
- labelled controls;
- ARIA modal labelling;
- focus capture/restore around operational dialogs;
- `role="status"` / `role="alert"` state surfaces;
- `aria-current` pagination;
- labelled password/action controls.

Preserve these behaviors when composing new Dashboard UI.

Customer shared components already use Flutter `Semantics` in important interactive primitives. Reuse them.

Driver/Van have less shared semantic coverage; new icon-only/custom interactions must explicitly supply semantic/tooltip meaning rather than rely on visual recognition.

## 2. Keyboard/focus — Dashboard

Interactive UI must:
- be reachable in a sensible keyboard order;
- retain visible focus;
- not remove focus outline without a replacement;
- keep modal focus inside/around the active modal behavior and restore prior focus when closed;
- support Escape/close semantics when the current shared dialog pattern does;
- avoid click-only non-button elements for real actions.

## 3. Flutter semantics

For Customer/Driver/Van:
- icon-only buttons need tooltip/semantic label;
- custom tappable surfaces need button/link semantics when not conveyed by the native widget;
- meaningful images expose semantics when they communicate information; decorative images should not produce noise;
- status/change messages should be understandable without relying only on icon/color.

Prefer native Material controls and existing shared primitives because they carry semantics correctly.

## 4. Touch target

Use current surface conventions:
- Dashboard / Customer / Driver: generally >=44px for interactive touch targets where the existing theme/contract defines it;
- Van: current token target is 48px.

Do not shrink important actions to solve layout overflow.

## 5. Text scaling / language

Verify relevant screens with:
- Arabic RTL;
- English LTR;
- longer translated strings;
- increased text scale where the current widget tests/product constraints permit.

Do not fix overflow by making body text unreadably small.

## 6. Color and status

Status must not be communicated only through color.

Use:
- localized text/status label;
- optional icon/shape;
- semantic color as reinforcement.

Do not invent low-contrast text colors outside the brand system.

## 7. Motion

Customer animations must use `CustomerUiMotion.resolve` / current reduced-motion behavior.

For new animations on other surfaces:
- avoid making critical interaction depend on animation;
- respect platform reduced-motion/accessibility settings where practical;
- do not use continuous decorative animation that competes with operational data.

## 8. Forms and errors

A field error should:
- identify the owning field/action;
- remain readable in AR/EN;
- not rely only on red border;
- preserve entered valid data;
- move focus/scroll to the error when the workflow requires it.

## 9. Accessibility acceptance

PASS only if:
- keyboard/focus behavior works on Dashboard;
- custom mobile controls have semantic meaning;
- icon-only actions are labelled;
- touch targets meet current surface contract;
- state/status meaning survives grayscale/color-blind interpretation through text/shape;
- translations/text scale do not hide the primary action;
- modal/dialog close/cancel is discoverable.
