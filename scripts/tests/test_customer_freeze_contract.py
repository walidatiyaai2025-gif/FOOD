from __future__ import annotations

import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "scripts"))

from validate_customer_freeze_contract import ContractError, validate_customer_freeze


def git(root: Path, *args: str) -> str:
    return subprocess.check_output(["git", *args], cwd=root, text=True).strip()


class CustomerFreezeContractTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory(ignore_cleanup_errors=True)
        self.root = Path(self.temp.name)
        git(self.root, "init")
        git(self.root, "config", "user.name", "FOODEX Test")
        git(self.root, "config", "user.email", "foodex-test@example.invalid")

        customer = self.root / "apps/customer_app"
        customer.mkdir(parents=True)
        (customer / "pubspec.yaml").write_text("version: 1.0.76+76\n", encoding="utf-8")
        (customer / "lib.dart").write_text("const frozen = true;\n", encoding="utf-8")
        (self.root / "VERSION").write_text("1.0.76\n", encoding="utf-8")
        git(self.root, "add", ".")
        git(self.root, "commit", "-m", "customer frozen baseline")
        self.baseline = git(self.root, "rev-parse", "HEAD")
        self.tree = git(self.root, "rev-parse", "HEAD:apps/customer_app")

        release = self.root / "docs/release"
        release.mkdir(parents=True)
        self.contract = release / "FOODEX_1_0_77_CUSTOMER_FREEZE.json"
        self.contract.write_text(
            json.dumps(
                {
                    "schema_version": 1,
                    "program": "FOODEX 1.0.77",
                    "release_version": "1.0.77",
                    "frozen_customer": {
                        "baseline_commit": self.baseline,
                        "immutable_paths": ["apps/customer_app/**"],
                        "policy": "zero-diff",
                        "lineage_mode": "carried_forward",
                        "source_version": "1.0.76",
                        "source_build_number": 76,
                        "source_identity": "1.0.76+76",
                        "tree_sha": self.tree,
                    },
                }
            ),
            encoding="utf-8",
        )
        (self.root / "VERSION").write_text("1.0.77\n", encoding="utf-8")
        git(self.root, "add", ".")
        git(self.root, "commit", "-m", "prepare platform 1.0.77")

    def tearDown(self) -> None:
        self.temp.cleanup()

    def validate(self, candidate: str = "WORKTREE") -> dict[str, object]:
        return validate_customer_freeze(self.root, self.contract, candidate=candidate)

    def test_unchanged_customer_tree_reports_truthful_carried_forward_identity(self) -> None:
        metadata = self.validate()
        customer = metadata["customer"]
        self.assertEqual("1.0.77", metadata["release_version"])
        self.assertEqual("frozen", customer["status"])
        self.assertEqual("carried_forward", customer["lineage_mode"])
        self.assertEqual("1.0.76+76", customer["source_identity"])
        self.assertEqual(self.baseline, customer["source_commit"])
        self.assertEqual(self.tree, customer["tree_sha"])
        self.assertTrue(customer["zero_diff"])

    def test_committed_customer_change_fails(self) -> None:
        (self.root / "apps/customer_app/lib.dart").write_text(
            "const frozen = false;\n", encoding="utf-8"
        )
        git(self.root, "add", ".")
        git(self.root, "commit", "-m", "forbidden customer edit")
        with self.assertRaisesRegex(ContractError, "Customer tree changed"):
            self.validate("HEAD")

    def test_uncommitted_or_untracked_customer_change_fails(self) -> None:
        (self.root / "apps/customer_app/new.txt").write_text("forbidden\n", encoding="utf-8")
        with self.assertRaisesRegex(ContractError, "worktree/index"):
            self.validate()

    def test_contract_cannot_claim_a_different_customer_identity(self) -> None:
        payload = json.loads(self.contract.read_text(encoding="utf-8"))
        payload["frozen_customer"]["source_identity"] = "1.0.77+77"
        self.contract.write_text(json.dumps(payload), encoding="utf-8")
        with self.assertRaisesRegex(ContractError, "source_identity"):
            self.validate()

    def test_required_ci_and_factory_are_wired_to_the_contract(self) -> None:
        required_ci = (ROOT / ".github/workflows/required-ci-gate.yml").read_text(
            encoding="utf-8"
        )
        factory = (ROOT / ".github/workflows/release-factory.yml").read_text(
            encoding="utf-8"
        )
        trial = (ROOT / ".github/workflows/trial-distribution.yml").read_text(
            encoding="utf-8"
        )
        self.assertIn("validate_customer_freeze_contract.py", required_ci)
        self.assertIn("test_customer_freeze_contract.py", required_ci)
        self.assertIn("customer_version", factory)
        self.assertIn("customer_lineage_mode", factory)
        self.assertIn('if [[ "$version" == "1.0.77" ]]', factory)
        self.assertIn('test "$customer" = "${frozen[2]}"', factory)
        self.assertIn("validate_customer_freeze_contract.py --candidate HEAD", trial)
        self.assertIn("cannot relabel Customer 1.0.76 as 1.0.77", trial)

    def test_bundle_manifest_records_customer_as_carried_forward(self) -> None:
        bundle = self.root / "bundle"
        bundle.mkdir()
        paths = {}
        for name in ("customer", "driver", "van", "setup"):
            paths[name] = bundle / name
            paths[name].write_bytes(name.encode("utf-8"))
        output = bundle / "BUILD_INFO.json"
        subprocess.run(
            [
                sys.executable,
                str(ROOT / "scripts/write-trial-bundle-manifest.py"),
                "--version",
                "1.0.77",
                "--source-commit",
                "a" * 40,
                "--run-number",
                "1381",
                "--mobile-build-number",
                "77",
                "--customer-version",
                "1.0.76",
                "--customer-build-number",
                "76",
                "--customer-source-commit",
                self.baseline,
                "--customer-tree-sha",
                self.tree,
                "--customer-lineage",
                "carried_forward",
                "--api-base-url",
                "https://foodex.50sols.com",
                "--customer",
                str(paths["customer"]),
                "--driver",
                str(paths["driver"]),
                "--van",
                str(paths["van"]),
                "--setup",
                str(paths["setup"]),
                "--output",
                str(output),
            ],
            check=True,
        )
        metadata = json.loads(output.read_text(encoding="utf-8"))
        self.assertEqual("1.0.77", metadata["version"])
        self.assertEqual("1.0.76", metadata["customer"]["version"])
        self.assertEqual(76, metadata["customer"]["build_number"])
        self.assertEqual("1.0.77", metadata["customer"]["release_version"])
        self.assertEqual("carried_forward", metadata["customer"]["lineage_mode"])
        self.assertEqual(self.baseline, metadata["customer"]["source_commit"])
        self.assertEqual(self.tree, metadata["customer"]["tree_sha"])


if __name__ == "__main__":
    unittest.main()
