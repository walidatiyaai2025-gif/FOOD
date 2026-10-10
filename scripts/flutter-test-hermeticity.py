#!/usr/bin/env python3
from __future__ import annotations
import re, sys
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
TEST_ROOTS=[ROOT/"apps/customer_app/test",ROOT/"apps/driver_app/test",ROOT/"apps/van_app/test"]
ALLOW_MARKER="foodex-test-network: allowed"
NETWORK_PATTERNS={
    "HttpClient": re.compile(r"\bHttpClient\s*\("),
    "Socket.connect": re.compile(r"\bSocket\.connect\s*\("),
    "RawSocket.connect": re.compile(r"\bRawSocket\.connect\s*\("),
    "WebSocket.connect": re.compile(r"\bWebSocket\.connect\s*\("),
    "InternetAddress.lookup": re.compile(r"\bInternetAddress\.lookup\s*\("),
}

def scan()->list[str]:
    findings=[]
    for root in TEST_ROOTS:
        if not root.is_dir(): continue
        for path in root.rglob("*_test.dart"):
            text=path.read_text(encoding="utf-8",errors="replace")
            if ALLOW_MARKER in text: continue
            for name,pattern in NETWORK_PATTERNS.items():
                if pattern.search(text):
                    findings.append(f"{path.relative_to(ROOT)} uses direct {name}; inject a fake transport or add an explicitly reviewed {ALLOW_MARKER} marker.")
    return findings

def main()->int:
    findings=scan()
    for finding in findings: print(f"ERROR: {finding}",file=sys.stderr)
    if findings: return 1
    print("FOODEX Flutter tests are hermetic: no direct socket/DNS/HttpClient primitives.")
    return 0

if __name__=="__main__": raise SystemExit(main())
