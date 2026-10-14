#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import re
import subprocess
from pathlib import Path
from typing import Any


class ContractError(RuntimeError):
    pass


def _fail(message: str) -> None:
    raise ContractError(message)


def _git(root: Path, *args: str) -> str:
    result = subprocess.run(
        ["git", *args],
        cwd=root,
        check=False,
        capture_output=True,
        text=True,
    )
    if result.returncode != 0:
        detail = result.stderr.strip() or result.stdout.strip() or "git command failed"
        _fail(f"git {' '.join(args)}: {detail}")
    return result.stdout.strip()


def _load_contract(path: Path) -> dict[str, Any]:
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        _fail(f"Invalid Customer freeze contract {path}: {exc}")
    if not isinstance(payload, dict):
        _fail("Customer freeze contract must be a JSON object")
    return payload


def _require_sha(label: str, value: object) -> str:
    text = str(value or "")
    if not re.fullmatch(r"[0-9a-f]{40}", text):
        _fail(f"{label} must be a full lowercase Git SHA")
    return text


def _parse_pubspec_identity(text: str) -> tuple[str, int, str]:
    match = re.search(r"^version:\s*([0-9]+\.[0-9]+\.[0-9]+)\+([1-9][0-9]*)\s*$", text, re.MULTILINE)
    if not match:
        _fail("Frozen Customer pubspec must contain a semantic version plus positive build number")
    version = match.group(1)
    build_number = int(match.group(2))
    return version, build_number, f"{version}+{build_number}"


def _worktree_customer_changes(root: Path) -> list[str]:
    changed: set[str] = set()
    for args in (
        ("diff", "--name-only", "--", "apps/customer_app"),
        ("diff", "--cached", "--name-only", "--", "apps/customer_app"),
        ("ls-files", "--others", "--exclude-standard", "apps/customer_app"),
    ):
        changed.update(line for line in _git(root, *args).splitlines() if line)
    return sorted(changed)


def validate_customer_freeze(
    root: Path,
    contract_path: Path,
    *,
    candidate: str = "WORKTREE",
) -> dict[str, Any]:
    root = root.resolve()
    contract_path = contract_path if contract_path.is_absolute() else root / contract_path
    contract = _load_contract(contract_path)

    if contract.get("schema_version") != 1:
        _fail("Customer freeze contract schema_version must be 1")
    if contract.get("release_version") != "1.0.77":
        _fail("Customer freeze contract release_version must be 1.0.77")

    frozen = contract.get("frozen_customer")
    if not isinstance(frozen, dict):
        _fail("Customer freeze contract is missing frozen_customer")

    baseline = _require_sha("frozen_customer.baseline_commit", frozen.get("baseline_commit"))
    expected_tree = _require_sha("frozen_customer.tree_sha", frozen.get("tree_sha"))
    immutable_paths = frozen.get("immutable_paths")
    if immutable_paths != ["apps/customer_app/**"]:
        _fail("Frozen Customer immutable_paths must be exactly apps/customer_app/**")
    if frozen.get("policy") != "zero-diff":
        _fail("Frozen Customer policy must be zero-diff")
    if frozen.get("lineage_mode") != "carried_forward":
        _fail("Frozen Customer lineage_mode must be carried_forward")

    candidate_ref = "HEAD" if candidate == "WORKTREE" else candidate
    baseline_tree = _git(root, "rev-parse", f"{baseline}:apps/customer_app")
    candidate_tree = _git(root, "rev-parse", f"{candidate_ref}:apps/customer_app")
    if baseline_tree != expected_tree:
        _fail(
            f"Contract tree SHA {expected_tree} does not match baseline {baseline} tree {baseline_tree}"
        )
    if candidate_tree != expected_tree:
        changed = _git(root, "diff", "--name-only", baseline, candidate_ref, "--", "apps/customer_app")
        detail = f"; changed paths: {changed}" if changed else ""
        _fail(f"Customer tree changed: expected {expected_tree}, got {candidate_tree}{detail}")

    diff = _git(root, "diff", "--name-only", baseline, candidate_ref, "--", "apps/customer_app")
    if diff:
        _fail(f"Customer zero-diff failed; changed paths: {diff}")

    if candidate == "WORKTREE":
        worktree_changes = _worktree_customer_changes(root)
        if worktree_changes:
            _fail(
                "Customer worktree/index contains forbidden changes: "
                + ", ".join(worktree_changes)
            )

    baseline_pubspec = _git(root, "show", f"{baseline}:apps/customer_app/pubspec.yaml")
    candidate_pubspec = _git(root, "show", f"{candidate_ref}:apps/customer_app/pubspec.yaml")
    baseline_version, baseline_build, baseline_identity = _parse_pubspec_identity(baseline_pubspec)
    candidate_version, candidate_build, candidate_identity = _parse_pubspec_identity(candidate_pubspec)
    if candidate_identity != baseline_identity:
        _fail(
            f"Customer source identity changed: baseline={baseline_identity} candidate={candidate_identity}"
        )

    expected_identity = frozen.get("source_identity")
    if expected_identity != baseline_identity:
        _fail(
            f"Contract source_identity {expected_identity!r} does not match baseline {baseline_identity!r}"
        )
    if frozen.get("source_version") != baseline_version:
        _fail("Contract source_version does not match frozen Customer pubspec")
    if frozen.get("source_build_number") != baseline_build:
        _fail("Contract source_build_number does not match frozen Customer pubspec")

    return {
        "schema_version": 1,
        "release_version": contract["release_version"],
        "customer": {
            "status": "frozen",
            "lineage_mode": "carried_forward",
            "source_commit": baseline,
            "tree_sha": expected_tree,
            "version": candidate_version,
            "build_number": candidate_build,
            "source_identity": candidate_identity,
            "candidate_commit": _git(root, "rev-parse", candidate_ref),
            "immutable_paths": immutable_paths,
            "zero_diff": True,
        },
    }


def main() -> None:
    parser = argparse.ArgumentParser(
        description="Validate the FOODEX 1.0.77 byte-for-byte frozen Customer lineage."
    )
    parser.add_argument("--root", type=Path, default=Path("."))
    parser.add_argument(
        "--contract",
        type=Path,
        default=Path("docs/release/FOODEX_1_0_77_CUSTOMER_FREEZE.json"),
    )
    parser.add_argument("--candidate", default="WORKTREE")
    parser.add_argument("--metadata-out", type=Path)
    args = parser.parse_args()

    try:
        metadata = validate_customer_freeze(
            args.root,
            args.contract,
            candidate=args.candidate,
        )
    except ContractError as exc:
        raise SystemExit(f"Customer freeze contract failed: {exc}") from exc

    if args.metadata_out:
        output = args.metadata_out if args.metadata_out.is_absolute() else args.root / args.metadata_out
        output.parent.mkdir(parents=True, exist_ok=True)
        output.write_text(json.dumps(metadata, indent=2) + "\n", encoding="utf-8")

    customer = metadata["customer"]
    print(
        "Customer freeze contract PASS: "
        f"{customer['source_identity']} carried forward from {customer['source_commit']} "
        f"with tree {customer['tree_sha']}"
    )


if __name__ == "__main__":
    main()
