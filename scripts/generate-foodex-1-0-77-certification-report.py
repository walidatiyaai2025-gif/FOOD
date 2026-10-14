#!/usr/bin/env python3
import argparse
import json
import re
import subprocess
from pathlib import Path

BASELINE = "4de5a24b2ca6bd9b448a79ca05f7f44c7d428f4d"
EXPECTED_CUSTOMER_TREE = "7e5828423d2b747afcd607a643c853495024328c"
EXPECTED_RELEASE = "1.0.77"
EXPECTED_CUSTOMER_IDENTITY = "1.0.76+76"
EXPECTED_DRIVER_VAN_IDENTITY = "1.0.77+77"

def load_json(path: str):
    return json.loads(Path(path).read_text(encoding="utf-8"))

def git(*args: str) -> str:
    return subprocess.check_output(["git", *args], text=True).strip()

def mobile_identity(pubspec: str) -> str:
    text = Path(pubspec).read_text(encoding="utf-8")
    match = re.search(r"^version:\s*(\d+\.\d+\.\d+\+\d+)\s*$", text, re.M)
    if not match:
        raise SystemExit(f"Missing mobile identity in {pubspec}")
    return match.group(1)

def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--source-sha", required=True)
    parser.add_argument("--run-number", required=True)
    parser.add_argument("--evidence", required=True)
    parser.add_argument("--output", required=True)
    args = parser.parse_args()

    evidence = load_json(args.evidence)
    freeze = load_json("docs/release/FOODEX_1_0_77_CUSTOMER_FREEZE.json")
    provenance = load_json("backend/database/seeders/fixtures/foodex-launch-catalog-provenance.json")
    images = load_json("backend/database/seeders/fixtures/foodex-launch-image-manifest.json")

    root_version = Path("VERSION").read_text(encoding="utf-8").strip()
    customer = freeze["frozen_customer"]
    customer_identity = customer["source_identity"]
    driver_identity = mobile_identity("apps/driver_app/pubspec.yaml")
    van_identity = mobile_identity("apps/van_app/pubspec.yaml")

    head_sha = git("rev-parse", "HEAD")
    customer_tree = git("rev-parse", "HEAD:apps/customer_app")
    baseline_customer_tree = git("rev-parse", f"{BASELINE}:apps/customer_app")
    customer_diff = subprocess.run(
        ["git", "diff", "--quiet", BASELINE, "HEAD", "--", "apps/customer_app"],
        check=False,
    ).returncode == 0

    entries = provenance.get("entries", [])
    product_ids = sorted({
        e.get("product_identifier")
        for e in entries
        if isinstance(e, dict) and isinstance(e.get("product_identifier"), str)
    })
    inferred = [e for e in entries if isinstance(e, dict) and e.get("provenance_state") == "inferred"]
    reconstructed = [e for e in entries if isinstance(e, dict) and e.get("provenance_state") == "reconstructed_asset"]

    image_entries = images.get("entries", [])
    source_backed = [e for e in image_entries if isinstance(e, dict) and e.get("method") == "content_preserving_upscale_lanczos_unsharp"]
    placeholders = [e for e in image_entries if isinstance(e, dict) and e.get("method") == "source_missing_placeholder"]
    image_dimensions_ok = all(
        isinstance(e.get("derivative_dimensions"), dict)
        and int(e["derivative_dimensions"].get("width", 0)) >= 800
        and int(e["derivative_dimensions"].get("height", 0)) >= 800
        and re.fullmatch(r"[0-9a-f]{64}", str(e.get("sha256", ""))) is not None
        for e in image_entries
    )

    before = evidence.get("before", {})
    after = evidence.get("after_restore", {})
    relaunch_after = evidence.get("after_relaunch_restore", {})

    checks = {
        "exact_head": head_sha == args.source_sha == evidence.get("source_sha"),
        "release_version": root_version == EXPECTED_RELEASE,
        "customer_contract_release": freeze.get("release_version") == EXPECTED_RELEASE,
        "customer_identity": customer_identity == EXPECTED_CUSTOMER_IDENTITY,
        "customer_baseline": customer.get("baseline_commit") == BASELINE,
        "customer_zero_diff": customer_diff,
        "customer_tree": customer_tree == baseline_customer_tree == customer.get("tree_sha") == EXPECTED_CUSTOMER_TREE,
        "driver_identity": driver_identity == EXPECTED_DRIVER_VAN_IDENTITY,
        "van_identity": van_identity == EXPECTED_DRIVER_VAN_IDENTITY,
        "catalog_release": provenance.get("release") == EXPECTED_RELEASE,
        "catalog_25_products": len(product_ids) == 25,
        "catalog_has_inferred_disclosure": len(inferred) > 0,
        "images_release": images.get("release") == EXPECTED_RELEASE,
        "images_25_products": len(image_entries) == 25,
        "images_19_source_backed": len(source_backed) == 19,
        "images_6_source_missing_placeholders": len(placeholders) == 6,
        "images_dimensions_checksums": image_dimensions_ok,
        "snapshot_exact_restore": evidence.get("exact_restore_verified") is True and before.get("sha256") == after.get("sha256") and before.get("counts") == after.get("counts"),
        "relaunch_exact_restore": evidence.get("relaunch_verified") is True and before.get("sha256") == relaunch_after.get("sha256") and before.get("counts") == relaunch_after.get("counts"),
        "launch_trial_mutated": before.get("sha256") != evidence.get("launch_trial", {}).get("sha256"),
        "source_bound_run": str(evidence.get("run_number")) == str(args.run_number),
    }

    failures = [name for name, passed in checks.items() if not passed]
    status = "PASS" if not failures else "FAIL"

    report = [
        "# FOODEX 1.0.77 Final Integration Certification",
        "",
        f"**Status: {status}**",
        "",
        f"- Certified source SHA: {args.source_sha}",
        f"- GitHub run number: {args.run_number}",
        f"- Root release: {root_version}",
        f"- Customer identity: {customer_identity} (frozen/carried forward)",
        f"- Driver identity: {driver_identity}",
        f"- Van identity: {van_identity}",
        "",
        "## Certification matrix",
        "",
        "| Check | Result |",
        "|---|---|",
    ]
    for name, passed in checks.items():
        report.append(f"| {name} | {'PASS' if passed else 'FAIL'} |")

    report += [
        "",
        "## Customer zero-diff",
        "",
        f"- Frozen baseline: {BASELINE}",
        f"- Frozen Customer tree: {baseline_customer_tree}",
        f"- Certified Customer tree: {customer_tree}",
        f"- Files changed under apps/customer_app/**: {'0' if customer_diff else 'NON-ZERO'}",
        "",
        "## Catalog provenance",
        "",
        f"- Products covered: **{len(product_ids)}**",
        f"- Provenance records: **{len(entries)}**",
        f"- Explicit inferred fields: **{len(inferred)}**",
        f"- Reconstructed image provenance records: **{len(reconstructed)}**",
        "",
        "### Inferred fields",
        "",
        "| Product | Field | Final value | Rationale |",
        "|---|---|---:|---|",
    ]
    for entry in inferred:
        value = str(entry.get("final_value", "")).replace("|", "\\|")
        rationale = str(entry.get("rationale", "")).replace("|", "\\|").replace("\n", " ")
        report.append(f"| {entry.get('product_identifier','')} — {entry.get('product_name','')} | {entry.get('field','')} | {value} | {rationale} |")

    bundle = images.get("bundle", {}) if isinstance(images.get("bundle", {}), dict) else {}
    report += [
        "",
        "## Image reconstruction",
        "",
        f"- Product mappings: **{len(image_entries)}/25**",
        f"- Source-backed derivatives: **{len(source_backed)}**",
        f"- Source-missing neutral placeholders: **{len(placeholders)}**",
        f"- Minimum dimensions/checksum contract: **{'PASS' if image_dimensions_ok else 'FAIL'}**",
        f"- Bundle SHA-256: {bundle.get('sha256','')}",
        f"- Bundle parts: **{bundle.get('part_count','')}**",
        "",
        "## Reversible trial evidence",
        "",
        f"- Canonical tables fingerprinted: **{len(evidence.get('canonical_tables', []))}**",
        f"- Before trial SHA-256: {before.get('sha256','')}",
        f"- During launch SHA-256: {evidence.get('launch_trial',{}).get('sha256','')}",
        f"- After Legacy restore SHA-256: {after.get('sha256','')}",
        f"- After re-launch + second restore SHA-256: {relaunch_after.get('sha256','')}",
        f"- First snapshot ID: {evidence.get('first_snapshot_id','')}",
        f"- Re-launch snapshot ID: {evidence.get('relaunch_snapshot_id','')}",
        "",
        "## Publication state",
        "",
        "The 1.0.77 production HOLD remains intentionally active during this certification. Certification proves the integrated source; it does not itself publish final artifacts. The HOLD may be removed only after #1388 and parent #1380 accept this PASS evidence.",
        "",
        "## Unresolved risks",
        "",
    ]
    report.extend([f"- {failure}" for failure in failures] if failures else ["- None."])

    Path(args.output).parent.mkdir(parents=True, exist_ok=True)
    Path(args.output).write_text("\n".join(report) + "\n", encoding="utf-8")

    machine = {
        "schema_version": 1,
        "release": EXPECTED_RELEASE,
        "status": status,
        "source_sha": args.source_sha,
        "run_number": str(args.run_number),
        "checks": checks,
        "unresolved_risks": failures,
        "customer": {
            "baseline_commit": BASELINE,
            "baseline_tree": baseline_customer_tree,
            "certified_tree": customer_tree,
            "zero_diff": customer_diff,
        },
        "catalog": {
            "products": len(product_ids),
            "provenance_records": len(entries),
            "inferred_fields": len(inferred),
        },
        "images": {
            "products": len(image_entries),
            "source_backed": len(source_backed),
            "source_missing_placeholders": len(placeholders),
        },
        "rollback": evidence,
    }
    Path(args.output).with_suffix(".json").write_text(json.dumps(machine, indent=2) + "\n", encoding="utf-8")

    if failures:
        raise SystemExit("Certification failed: " + ", ".join(failures))

if __name__ == "__main__":
    main()
