#!/usr/bin/env python3
from __future__ import annotations
import argparse, re, sys

KINDS=("feat","fix","chore","docs","refactor","test","ci")
SPECIAL={"feat/assistant-v1-integration","chore/geography-reset-one-shot"}
STANDARD_RE=re.compile(r"^(feat|fix|chore|docs|refactor|test|ci)/([0-9]+)-[a-z0-9-]+$")
RELEASE_RE=re.compile(r"^release/([0-9]+|[0-9]+\.[0-9]+\.[0-9]+)-[a-z0-9-]+$")

def normalize_slug(value:str)->str:
    slug=re.sub(r"[^a-z0-9]+","-",value.strip().lower()).strip("-")
    slug=re.sub(r"-{2,}","-",slug)
    if not slug: raise ValueError("slug must contain at least one alphanumeric character")
    return slug

def build_name(issue:int, kind:str, slug:str)->str:
    if issue<=0: raise ValueError("issue must be a positive integer")
    if kind not in KINDS: raise ValueError("invalid branch kind")
    return f"{kind}/{issue}-{normalize_slug(slug)}"

def is_valid(branch:str, base:str|None=None)->bool:
    if branch in SPECIAL: return True
    if branch=="main": return bool(base and RELEASE_RE.fullmatch(base))
    return bool(STANDARD_RE.fullmatch(branch) or RELEASE_RE.fullmatch(branch))

def main()->int:
    p=argparse.ArgumentParser()
    sub=p.add_subparsers(dest="command",required=True)
    n=sub.add_parser("name"); n.add_argument("issue",type=int); n.add_argument("kind",choices=KINDS); n.add_argument("slug")
    v=sub.add_parser("validate"); v.add_argument("branch"); v.add_argument("--base")
    a=p.parse_args()
    if a.command=="name":
        try: print(build_name(a.issue,a.kind,a.slug)); return 0
        except ValueError as exc: print(str(exc),file=sys.stderr); return 2
    if is_valid(a.branch,a.base): return 0
    print(f"Invalid issue branch name: {a.branch}",file=sys.stderr); return 1

if __name__=="__main__": raise SystemExit(main())
