#!/usr/bin/env python3
"""Create an AutoHub project from the skill's bundled scaffold."""

import argparse
import json
import os
import shlex
import shutil
import sys


def scaffold_dir():
    return os.path.realpath(os.path.join(os.path.dirname(__file__), "..", "assets", "scaffold"))


def create_project(target):
    source = scaffold_dir()
    target = os.path.realpath(target)

    if not os.path.isdir(source):
        raise RuntimeError("bundled scaffold is missing: " + source)
    if os.path.commonpath([source, target]) == source:
        raise RuntimeError("target must be outside the bundled scaffold: " + target)
    if os.path.exists(target) and not os.path.isdir(target):
        raise RuntimeError("target exists and is not a directory: " + target)
    if os.path.isdir(target) and os.listdir(target):
        raise RuntimeError("refusing to merge into a non-empty directory: " + target)

    os.makedirs(target, mode=0o755, exist_ok=True)
    copied = []
    for name in sorted(os.listdir(source)):
        src = os.path.join(source, name)
        dst = os.path.join(target, name)
        if os.path.isdir(src):
            shutil.copytree(src, dst, copy_function=shutil.copy2)
        else:
            shutil.copy2(src, dst)
        copied.append(name)
    return target, copied


def main():
    parser = argparse.ArgumentParser(
        description="Create a HUBzero AutoHub project from the bundled scaffold")
    parser.add_argument("target", help="new or empty project directory")
    parser.add_argument("--json", action="store_true", help="emit one JSON object")
    args = parser.parse_args()

    try:
        target, copied = create_project(args.target)
        result = {
            "ok": True,
            "action": "create-project",
            "target": target,
            "copied": copied,
            "next": ["cd %s" % shlex.quote(target),
                     "cli/autohub init --site \"My Hub\" --json"],
        }
        code = 0
    except Exception as exc:
        result = {
            "ok": False,
            "action": "create-project",
            "error": str(exc),
            "next": [],
        }
        code = 1

    if args.json:
        print(json.dumps(result, indent=2))
    elif result["ok"]:
        print("created AutoHub project at " + result["target"])
        for command in result["next"]:
            print("next: " + command)
    else:
        print("error: " + result["error"], file=sys.stderr)
    return code


if __name__ == "__main__":
    sys.exit(main())
