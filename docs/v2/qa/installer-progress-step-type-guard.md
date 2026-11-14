# FOODEX V2 #3398 — Installer persisted progress step type guard

**Source:** P112/G004 #2745, #2846, master #2503. **Integration:** `Foodex_V2` only. No main, production, releases, tags, direct cPanel setup or user data modifications.

## Reproduced source trust-boundary discrepancy

`InstallState::progress()` previously read a local JSON `completed_step` and used `(int)` with a clamp. Thus `"12"`, `12.9` and `99` became privileged installer progress 12, 12 and 15. `InstallerController::process()` uses this progress to determine the highest allowed step, including migration, seeding and APP_KEY generation. This is a **corrupted/persisted-state risk**, not a claim that an unauthenticated attacker can write the server-side JSON file. Existing installer HTTP token validation and installed.lock checks remain mandatory.

The persisted source of truth now recognizes only JSON integers 0 through 15. Non-integers, negative/out-of-range numbers and malformed step types are treated as **completed step 0**, not silently promoted; absence still means new installation step 0. A valid stored 12 and an ordinary completed 15 remain unchanged. Other persisted fields (`admin`, `token`) are parsed exactly as before. No live `.env`, application key, credentials, migrations or admin users are modified by the implementation.

## Test and acceptance evidence

- NEW `backend/tests/Feature/V2InstallerProgressStepTypeGuardTest.php`: unit-like persisted JSON state checks valid integers 0/1/11/12/13/15 and denies string `"12"`, float `12.9`, bool, arrays, negative and >15 while retaining the valid fixture token.
- Real Laravel `POST /install/step/13` with forged string progress `"12"` and a valid progress token returns 409; `APP_KEY` remains empty, persisted bytes are unchanged and no installed lock appears.
- Test owns its temporary `.env`, progress, install lock and persistent token lock paths, removed after each test. It does not use production config or data.
- Required CI on the **exact final PR HEAD** must independently pass PHP syntax/Pint/PHPStan, Laravel full tests, AR/EN localization, Repository Policy, V2 Isolation and MySQL/Redis fresh-install/upgrade/recovery. CI run links and final SHA belong in Issue/PR checkpoints after verification; no unverified PASS is claimed here.

## Cross-surface impact and exclusions

| Surface | Impact |
| --- | --- |
| Backend | Installer state parsing and existing HTTP step authorization only |
| Dashboard | Existing web setup flow safely restarts on corrupt progress; no new route, menu or translation text |
| Customer | No API/Flutter/auth/checkout change |
| Driver | No API/Flutter/dispatch/wallet change; B2C = DRIVER ONLY |
| Van | No API/Flutter/custody/route change; B2B = VAN ONLY |
| AR/EN, RTL/LTR | No user-facing wording or layout change; existing setup UI remains |

This leaf **does not certify** the full G004/P112 source contract, signed Setup ZIP, signed Admin Update ZIP, real shared-host production install or historical ZIP upgrades. Keep parents #2745, #2846 and #2503 OPEN for exact artifact and product acceptance.
