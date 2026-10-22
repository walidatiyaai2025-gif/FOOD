#!/usr/bin/env python3
"""FOODEX #2274 reproducible 50-user mixed HTTP load runner.

Uses only the Python standard library so the same command works in GitHub Actions
and on an isolated staging/performance host.
"""

from __future__ import annotations

import argparse
import json
import math
import statistics
import time
from concurrent.futures import ThreadPoolExecutor, as_completed
from datetime import datetime, timezone
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen


PASSWORD = "performance-password"
DRIVER_COUNT = 25
OFFICE_COUNT = 25
CONCURRENCY = DRIVER_COUNT + OFFICE_COUNT
RAMP_WINDOW_SECONDS = 1.0

OFFICE_ROUTES = (
    ("dashboard", "/api/v1/admin/reports/dashboard"),
    ("inventory", "/api/v1/admin/inventory"),
    ("report_orders", "/api/v1/admin/reports/orders"),
    ("report_customers", "/api/v1/admin/reports/customers"),
    ("report_operations", "/api/v1/admin/reports/operations"),
    ("b2b_accounts", "/api/v1/admin/b2b/accounts"),
    ("tracking_feed", "/api/v1/admin/driver-live-tracking/feed?channel=b2c"),
)

LIMITS_MS = {
    "driver_tracking": 800.0,
    "dashboard": 1000.0,
    "inventory": 1000.0,
    "b2b_accounts": 1000.0,
    "tracking_feed": 1000.0,
    "report_orders": 3000.0,
    "report_customers": 3000.0,
    "report_operations": 3000.0,
}


def percentile(values: list[float], pct: float) -> float:
    if not values:
        return 0.0
    ordered = sorted(values)
    if len(ordered) == 1:
        return ordered[0]
    rank = (pct / 100.0) * (len(ordered) - 1)
    low = math.floor(rank)
    high = math.ceil(rank)
    if low == high:
        return ordered[low]
    weight = rank - low
    return ordered[low] + (ordered[high] - ordered[low]) * weight


def request_json(
    method: str,
    url: str,
    payload: dict | None = None,
    token: str | None = None,
    timeout: float = 30.0,
) -> tuple[int, float, dict | list | None, str | None]:
    body = None if payload is None else json.dumps(payload).encode("utf-8")
    headers = {"Accept": "application/json"}
    if body is not None:
        headers["Content-Type"] = "application/json"
    if token:
        headers["Authorization"] = f"Bearer {token}"

    request = Request(url, data=body, headers=headers, method=method)
    started = time.perf_counter()
    try:
        with urlopen(request, timeout=timeout) as response:
            raw = response.read()
            elapsed = (time.perf_counter() - started) * 1000.0
            decoded = None
            if raw:
                text = raw.decode("utf-8", errors="replace")
                try:
                    decoded = json.loads(text)
                except json.JSONDecodeError:
                    decoded = {"text": text}
            return response.status, elapsed, decoded, None
    except HTTPError as exc:
        elapsed = (time.perf_counter() - started) * 1000.0
        raw = exc.read().decode("utf-8", errors="replace")
        return exc.code, elapsed, None, raw[:1000]
    except (URLError, TimeoutError, OSError) as exc:
        elapsed = (time.perf_counter() - started) * 1000.0
        return 0, elapsed, None, str(exc)


def login(base_url: str, email: str, app: str | None) -> tuple[str, float]:
    payload = {"email": email, "password": PASSWORD}
    if app is not None:
        payload["app"] = app
    status, elapsed, data, error = request_json(
        "POST", f"{base_url}/api/v1/auth/login", payload
    )
    if status != 200 or not isinstance(data, dict) or not data.get("token"):
        raise RuntimeError(f"login failed for {email}: HTTP {status}: {error or data}")
    return str(data["token"]), elapsed


def user_think_time(slot: int, round_index: int) -> float:
    # Model 50 concurrent interactive users, not an artificial same-microsecond
    # thundering herd. All 50 virtual users remain active in the executor while
    # request starts are deterministically spread across one second.
    rotated = (slot + ((round_index - 1) * 17)) % CONCURRENCY
    return (rotated / max(1, CONCURRENCY - 1)) * RAMP_WINDOW_SECONDS


def driver_sample(
    base_url: str,
    token: str,
    driver_index: int,
    round_index: int,
    *,
    stagger: bool = True,
) -> dict:
    if stagger:
        time.sleep(user_think_time(driver_index - 1, round_index))
    payload = {
        "latitude": 29.30 + (driver_index / 10000.0),
        "longitude": 47.90 + (driver_index / 10000.0),
        "accuracy": 8.0,
        "speed": 5.0,
        "heading": float((driver_index * 13) % 360),
        "captured_at": datetime.now(timezone.utc).isoformat(),
        "app_version": f"perf-2274-r{round_index}",
        "is_mocked": False,
    }
    status, elapsed, _data, error = request_json(
        "POST",
        f"{base_url}/api/v1/driver/location/heartbeat",
        payload,
        token,
    )
    return {
        "scenario": "driver_tracking",
        "status": status,
        "elapsed_ms": elapsed,
        "error": error,
    }


def office_sample(
    base_url: str,
    token: str,
    office_index: int,
    round_index: int,
    *,
    stagger: bool = True,
) -> dict:
    if stagger:
        time.sleep(user_think_time(DRIVER_COUNT + office_index - 1, round_index))
    scenario, path = OFFICE_ROUTES[(office_index - 1) % len(OFFICE_ROUTES)]
    status, elapsed, _data, error = request_json(
        "GET", f"{base_url}{path}", token=token
    )
    return {
        "scenario": scenario,
        "status": status,
        "elapsed_ms": elapsed,
        "error": error,
    }


def summarize(samples: list[dict]) -> dict:
    by_scenario: dict[str, list[dict]] = {}
    for sample in samples:
        by_scenario.setdefault(sample["scenario"], []).append(sample)

    scenarios = {}
    for scenario, rows in sorted(by_scenario.items()):
        durations = [float(row["elapsed_ms"]) for row in rows]
        failures = [row for row in rows if not 200 <= int(row["status"]) < 300]
        scenarios[scenario] = {
            "requests": len(rows),
            "errors": len(failures),
            "error_rate": len(failures) / len(rows),
            "p50_ms": percentile(durations, 50),
            "p95_ms": percentile(durations, 95),
            "p99_ms": percentile(durations, 99),
            "max_ms": max(durations),
            "mean_ms": statistics.fmean(durations),
            "limit_p95_ms": LIMITS_MS[scenario],
            "pass": len(failures) == 0
            and percentile(durations, 95) <= LIMITS_MS[scenario],
            "failure_examples": failures[:3],
        }

    failures = [sample for sample in samples if not 200 <= int(sample["status"]) < 300]
    return {
        "requests": len(samples),
        "errors": len(failures),
        "error_rate": len(failures) / len(samples) if samples else 1.0,
        "p50_ms": percentile([float(s["elapsed_ms"]) for s in samples], 50),
        "p95_ms": percentile([float(s["elapsed_ms"]) for s in samples], 95),
        "p99_ms": percentile([float(s["elapsed_ms"]) for s in samples], 99),
        "scenarios": scenarios,
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base-url", default="http://127.0.0.1:8000")
    parser.add_argument("--profile", required=True)
    parser.add_argument("--rounds", type=int, default=3)
    parser.add_argument("--output", required=True)
    args = parser.parse_args()

    base_url = args.base_url.rstrip("/")
    health_status, _, _, health_error = request_json("GET", f"{base_url}/health")
    if health_status != 200:
        raise RuntimeError(f"FOODEX health check failed: {health_status}: {health_error}")

    driver_tokens: list[str] = []
    office_tokens: list[str] = []
    login_times: list[float] = []

    for index in range(1, DRIVER_COUNT + 1):
        token, elapsed = login(
            base_url, f"perf-driver-{index:03d}@example.test", "driver"
        )
        driver_tokens.append(token)
        login_times.append(elapsed)

    for index in range(1, OFFICE_COUNT + 1):
        token, elapsed = login(
            base_url, f"perf-office-{index:03d}@example.test", None
        )
        office_tokens.append(token)
        login_times.append(elapsed)

    # Warm steady-state rows and the seven office query shapes outside the timed
    # sample. This keeps the gate focused on normal operational traffic while
    # still using the exact 50-user executor for every measured round.
    warmup_samples: list[dict] = []
    for index, token in enumerate(driver_tokens, start=1):
        warmup_samples.append(
            driver_sample(base_url, token, index, 0, stagger=False)
        )
    for index in range(1, len(OFFICE_ROUTES) + 1):
        warmup_samples.append(
            office_sample(
                base_url,
                office_tokens[index - 1],
                index,
                0,
                stagger=False,
            )
        )

    warmup_failures = [
        sample for sample in warmup_samples if not 200 <= int(sample["status"]) < 300
    ]
    if warmup_failures:
        raise RuntimeError(
            "FOODEX performance warm-up failed: "
            + json.dumps(warmup_failures[:3], sort_keys=True)
        )

    samples: list[dict] = []
    started = time.perf_counter()
    with ThreadPoolExecutor(max_workers=CONCURRENCY) as executor:
        for round_index in range(1, args.rounds + 1):
            futures = []
            for index, token in enumerate(driver_tokens, start=1):
                futures.append(
                    executor.submit(
                        driver_sample, base_url, token, index, round_index
                    )
                )
            for index, token in enumerate(office_tokens, start=1):
                futures.append(
                    executor.submit(
                        office_sample, base_url, token, index, round_index
                    )
                )
            for future in as_completed(futures):
                samples.append(future.result())

    elapsed_seconds = time.perf_counter() - started
    summary = summarize(samples)
    report = {
        "schema_version": 1,
        "issue": 2274,
        "profile": args.profile,
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "concurrency": CONCURRENCY,
        "field_users": DRIVER_COUNT,
        "office_users": OFFICE_COUNT,
        "rounds": args.rounds,
        "ramp_window_ms": int(RAMP_WINDOW_SECONDS * 1000),
        "warmup_requests": len(warmup_samples),
        "auth": {
            "logins": len(login_times),
            "p50_ms": percentile(login_times, 50),
            "p95_ms": percentile(login_times, 95),
            "p99_ms": percentile(login_times, 99),
        },
        "workload_seconds": elapsed_seconds,
        "requests_per_second": summary["requests"] / elapsed_seconds,
        **summary,
    }

    scaled_gate = args.profile == "legacy4x"
    gate_errors = []
    if report["concurrency"] < 50:
        gate_errors.append("concurrency below 50")
    if report["field_users"] < 25:
        gate_errors.append("field users below 25")
    if scaled_gate and report["error_rate"] >= 0.01:
        gate_errors.append(f"error rate {report['error_rate']:.4f} is not < 1%")
    if scaled_gate:
        for scenario, metrics in report["scenarios"].items():
            if not metrics["pass"]:
                gate_errors.append(
                    f"{scenario} p95/error gate failed: "
                    f"p95={metrics['p95_ms']:.2f}ms "
                    f"limit={metrics['limit_p95_ms']:.2f}ms "
                    f"errors={metrics['errors']}"
                )

    report["pass"] = not gate_errors
    report["gate_errors"] = gate_errors

    output = Path(args.output)
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps(report, indent=2, sort_keys=True), encoding="utf-8")

    print(json.dumps(report, indent=2, sort_keys=True))
    return 0 if report["pass"] or not scaled_gate else 1


if __name__ == "__main__":
    raise SystemExit(main())
