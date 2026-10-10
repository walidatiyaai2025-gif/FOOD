#!/usr/bin/env python3
from __future__ import annotations
import argparse, json, re, sys
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]; REGISTRY=ROOT/".ci"/"failure-patterns.json"

def load()->dict: return json.loads(REGISTRY.read_text(encoding="utf-8"))
def validate(data:dict)->list[str]:
    errors=[]; seen=set()
    if data.get("schema_version")!=1: errors.append("failure-patterns: schema_version must be 1")
    for item in data.get("patterns",[]):
        ident=str(item.get("id","")).strip()
        if not ident: errors.append("failure-patterns: missing id"); continue
        if ident in seen: errors.append(f"failure-patterns: duplicate id {ident}")
        seen.add(ident)
        for field in ("fingerprint_regex","detector","prevention"):
            if not str(item.get(field,"")).strip(): errors.append(f"failure-patterns: {ident} missing {field}")
        try: re.compile(str(item.get("fingerprint_regex","")),re.IGNORECASE)
        except re.error as exc: errors.append(f"failure-patterns: {ident} invalid regex: {exc}")
    return errors
def classify(data:dict,text:str)->list[str]:
    return [item["id"] for item in data.get("patterns",[]) if re.search(item["fingerprint_regex"],text,re.IGNORECASE|re.MULTILINE)]
def main()->int:
    p=argparse.ArgumentParser(); p.add_argument("--validate",action="store_true"); p.add_argument("--classify"); a=p.parse_args()
    data=load(); errors=validate(data)
    for e in errors: print(f"ERROR: {e}",file=sys.stderr)
    if errors: return 1
    if a.validate: print("FOODEX CI failure registry PASS"); return 0
    if a.classify:
        hits=classify(data,Path(a.classify).read_text(encoding="utf-8",errors="replace")); print("\n".join(hits) if hits else "unclassified"); return 0
    p.error("choose --validate or --classify <log-file>")
if __name__=="__main__": raise SystemExit(main())
