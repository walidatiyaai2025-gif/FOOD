#!/usr/bin/env python3
from __future__ import annotations
import argparse, json, re, subprocess, sys
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
CONFIG_PATH=ROOT/".ci"/"ci-map.json"

def git(*args:str)->str:
    return subprocess.check_output(["git","-C",str(ROOT),*args],text=True)

def load_config()->dict:
    return json.loads(CONFIG_PATH.read_text(encoding="utf-8"))

def validate_config(config:dict)->list[str]:
    errors=[]
    if config.get("schema_version")!=1: errors.append("ci-map: schema_version must be 1")
    areas=config.get("areas",{})
    if not isinstance(areas,dict) or not areas: errors.append("ci-map: areas must be non-empty"); areas={}
    for name,spec in areas.items():
        try: re.compile(str(spec.get("path_regex","")))
        except re.error as exc: errors.append(f"ci-map: area {name} invalid regex: {exc}")
    for group in ("expansions","branch_rules"):
        for item in config.get(group,[]):
            key="path_regex" if group=="expansions" else "branch_regex"
            try: re.compile(str(item.get(key,"")))
            except re.error as exc: errors.append(f"ci-map: {group} {item.get('id')} invalid regex: {exc}")
            for area in item.get("enable",[]):
                if area not in areas: errors.append(f"ci-map: {item.get('id')} unknown area {area}")
    for item in config.get("focused_tests",[]):
        area=str(item.get("area",""))
        try: re.compile(str(item.get("path_regex","")))
        except re.error as exc: errors.append(f"ci-map: focused {item.get('id')} invalid regex: {exc}")
        prefix="backend/" if area=="backend" else f"apps/{area}_app/"
        for test in item.get("tests",[]):
            if not (ROOT/prefix/str(test)).is_file(): errors.append(f"ci-map: focused test missing: {prefix}{test}")
    return errors

def changed_files(base:str,head:str)->list[str]:
    if head=="WORKTREE":
        tracked=git("diff","--name-only",base).splitlines()
        untracked=git("ls-files","--others","--exclude-standard").splitlines()
        return sorted(set(x for x in tracked+untracked if x))
    return [x for x in git("diff","--name-only",base,head).splitlines() if x]

def resolve(config:dict,paths:list[str],branch:str="")->dict:
    areas={name:any(re.search(spec["path_regex"],p) for p in paths) for name,spec in config["areas"].items()}
    expansions=[]; branch_rules=[]
    for item in config.get("expansions",[]):
        if any(re.search(item["path_regex"],p) for p in paths):
            expansions.append(item["id"])
            for area in item.get("enable",[]): areas[area]=True
    for item in config.get("branch_rules",[]):
        if branch and re.search(item["branch_regex"],branch):
            branch_rules.append(item["id"])
            for area in item.get("enable",[]): areas[area]=True
    focused={"backend":[],"customer":[],"driver":[],"van":[]}; focused_rules=[]
    for item in config.get("focused_tests",[]):
        if any(re.search(item["path_regex"],p) for p in paths):
            focused_rules.append(item["id"]); area=item["area"]
            for test in item.get("tests",[]):
                if test not in focused[area]: focused[area].append(test)
    return {"schema_version":1,"branch":branch,"changed_files":paths,"areas":areas,"expansions":expansions,"branch_rules":branch_rules,"focused_rules":focused_rules,"focused_tests":focused}

def emit(plan:dict,fh)->None:
    for name,value in plan["areas"].items(): fh.write(f"{name}={'true' if value else 'false'}\n")

def main()->int:
    p=argparse.ArgumentParser()
    p.add_argument("--base"); p.add_argument("--head",default="HEAD"); p.add_argument("--branch",default="")
    p.add_argument("--github-output"); p.add_argument("--report"); p.add_argument("--focused",choices=["backend","customer","driver","van"])
    p.add_argument("--validate",action="store_true"); p.add_argument("--print-files",action="store_true")
    a=p.parse_args(); config=load_config(); errors=validate_config(config)
    for e in errors: print(f"ERROR: {e}",file=sys.stderr)
    if errors: return 1
    if a.validate: print("FOODEX CI map PASS"); return 0
    if not a.base: p.error("--base is required unless --validate is used")
    paths=changed_files(a.base,a.head); plan=resolve(config,paths,a.branch)
    if a.report:
        target=ROOT/a.report; target.parent.mkdir(parents=True,exist_ok=True); target.write_text(json.dumps(plan,indent=2)+"\n",encoding="utf-8")
    if a.github_output:
        with Path(a.github_output).open("a",encoding="utf-8") as fh: emit(plan,fh)
    if a.print_files:
        for path in paths: print(path,file=sys.stderr)
    if a.focused:
        for test in plan["focused_tests"].get(a.focused,[]): print(test)
    else: emit(plan,sys.stdout)
    return 0

if __name__=="__main__": raise SystemExit(main())
