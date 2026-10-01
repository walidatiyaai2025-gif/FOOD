#!/usr/bin/env python3
"""Package Customer and Driver Flutter Web preview runtimes for deployment."""

from __future__ import annotations

import argparse
import gzip
import hashlib
import json
import shutil
import tarfile
from pathlib import Path
from urllib.parse import urlparse

REQUIRED_FILES = ("index.html", "main.dart.js", "flutter_bootstrap.js")


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def normalize_base_href(value: str) -> str:
    value = value.strip()
    if not value.startswith("/") or not value.endswith("/"):
        raise ValueError(f"base href must start and end with '/': {value!r}")
    if "//" in value:
        raise ValueError(f"base href must not contain duplicate slashes: {value!r}")
    return value


def normalize_origin(value: str) -> str:
    value = value.strip().rstrip("/")
    parsed = urlparse(value)
    if parsed.scheme != "https" or not parsed.hostname or parsed.path not in ("", "/"):
        raise ValueError(f"runtime origin must be an HTTPS origin without a path: {value!r}")
    return value


def validate_build(build_dir: Path, base_href: str) -> None:
    if not build_dir.is_dir():
        raise FileNotFoundError(f"missing Flutter Web build directory: {build_dir}")

    for relative in REQUIRED_FILES:
        path = build_dir / relative
        if not path.is_file() or path.stat().st_size == 0:
            raise FileNotFoundError(f"missing required runtime asset: {path}")

    assets = build_dir / "assets"
    if not assets.is_dir() or not any(path.is_file() for path in assets.rglob("*")):
        raise FileNotFoundError(f"missing Flutter assets payload: {assets}")

    index = (build_dir / "index.html").read_text(encoding="utf-8")
    if f'<base href="{base_href}">' not in index:
        raise ValueError(
            f"{build_dir}/index.html does not contain expected base href {base_href!r}"
        )


def normalized_tar_filter(info: tarfile.TarInfo) -> tarfile.TarInfo:
    info.uid = 0
    info.gid = 0
    info.uname = ""
    info.gname = ""
    info.mtime = 0
    info.mode = 0o755 if info.isdir() else 0o644
    return info


def write_deterministic_targz(source: Path, archive: Path, arcname: str) -> None:
    archive.parent.mkdir(parents=True, exist_ok=True)
    with archive.open("wb") as raw:
        with gzip.GzipFile(filename="", mode="wb", fileobj=raw, mtime=0) as zipped:
            with tarfile.open(fileobj=zipped, mode="w", format=tarfile.PAX_FORMAT) as tar:
                tar.add(source, arcname=arcname, recursive=True, filter=normalized_tar_filter)


def file_manifest(root: Path) -> list[dict[str, object]]:
    rows: list[dict[str, object]] = []
    for path in sorted(p for p in root.rglob("*") if p.is_file()):
        rows.append(
            {
                "path": path.relative_to(root).as_posix(),
                "bytes": path.stat().st_size,
                "sha256": sha256_file(path),
            }
        )
    return rows


def package_runtime(
    *,
    name: str,
    build_dir: Path,
    base_href: str,
    runtime_origin: str,
    output_dir: Path,
    version: str,
    commit: str,
) -> dict[str, object]:
    validate_build(build_dir, base_href)

    target = output_dir / "www" / base_href.strip("/")
    shutil.copytree(build_dir, target, dirs_exist_ok=True)

    identity = f"{version}-{commit[:12]}"
    archive = output_dir / f"foodex-{name}-preview-{identity}.tar.gz"
    write_deterministic_targz(target, archive, name)

    files = file_manifest(target)
    return {
        "base_href": base_href,
        "runtime_url": f"{runtime_origin}{base_href}",
        "files": files,
        "file_count": len(files),
        "archive": {
            "file": archive.name,
            "bytes": archive.stat().st_size,
            "sha256": sha256_file(archive),
        },
    }


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    parser.add_argument("--customer-build", type=Path, required=True)
    parser.add_argument("--driver-build", type=Path, required=True)
    parser.add_argument("--output-dir", type=Path, required=True)
    parser.add_argument("--version", required=True)
    parser.add_argument("--commit", required=True)
    parser.add_argument("--api-base-url", required=True)
    parser.add_argument("--parent-origin", required=True)
    parser.add_argument("--runtime-origin", required=True)
    parser.add_argument("--contract-version", default="shared-flutter-v1")
    parser.add_argument("--customer-base-href", default="/preview/customer/")
    parser.add_argument("--driver-base-href", default="/preview/driver/")
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    output = args.output_dir
    if output.exists():
        shutil.rmtree(output)
    output.mkdir(parents=True)

    runtime_origin = normalize_origin(args.runtime_origin)
    customer_base = normalize_base_href(args.customer_base_href)
    driver_base = normalize_base_href(args.driver_base_href)

    customer = package_runtime(
        name="customer",
        build_dir=args.customer_build,
        base_href=customer_base,
        runtime_origin=runtime_origin,
        output_dir=output,
        version=args.version,
        commit=args.commit,
    )
    driver = package_runtime(
        name="driver",
        build_dir=args.driver_build,
        base_href=driver_base,
        runtime_origin=runtime_origin,
        output_dir=output,
        version=args.version,
        commit=args.commit,
    )

    manifest = {
        "schema": "foodex.preview-runtime-distribution.v1",
        "version": args.version,
        "commit": args.commit,
        "contract_version": args.contract_version,
        "api_base_url": args.api_base_url.rstrip("/"),
        "parent_origin": args.parent_origin.rstrip("/"),
        "runtime_origin": runtime_origin,
        "runtimes": {
            "customer": customer,
            "driver": driver,
        },
    }
    (output / "preview-runtime-manifest.json").write_text(
        json.dumps(manifest, indent=2, sort_keys=True) + "\n",
        encoding="utf-8",
    )

    env_lines = [
        f"FOODEX_CUSTOMER_PREVIEW_RUNTIME_URL={customer['runtime_url']}",
        f"FOODEX_CUSTOMER_PREVIEW_ALLOWED_ORIGIN={runtime_origin}",
        f"FOODEX_CUSTOMER_PREVIEW_CONTRACT_VERSION={args.contract_version}",
        f"FOODEX_DRIVER_PREVIEW_RUNTIME_URL={driver['runtime_url']}",
        f"FOODEX_DRIVER_PREVIEW_ALLOWED_ORIGIN={runtime_origin}",
        f"FOODEX_DRIVER_PREVIEW_CONTRACT_VERSION={args.contract_version}",
    ]
    (output / "preview-runtime.env").write_text("\n".join(env_lines) + "\n", encoding="utf-8")

    readme = (
        "FOODEX Preview Runtime Distribution\n"
        f"Version: {args.version}\n"
        f"Commit: {args.commit}\n"
        f"Contract: {args.contract_version}\n\n"
        "Deploy the contents of www/ to the production web root without changing the "
        "preview/customer/ and preview/driver/ paths. Then apply preview-runtime.env "
        "to the Dashboard environment and run the deployed smoke workflow before "
        "recording production evidence.\n"
    )
    (output / "README.txt").write_text(readme, encoding="utf-8")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
