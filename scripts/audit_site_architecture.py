#!/usr/bin/env python3
"""Audit an AutoHub project for native-content/template separation."""

import argparse
import glob
import json
import os
import re
import sys


def read_text(path):
    try:
        with open(path, encoding="utf-8") as stream:
            return stream.read()
    except (OSError, UnicodeError):
        return ""


def check(name, ok, detail):
    return {"name": name, "ok": bool(ok), "detail": detail}


def template_directories(project):
    patterns = (
        os.path.join(project, "extensions", "tpl_*"),
        os.path.join(project, "app", "templates", "*"),
        os.path.join(project, "templates", "*"),
    )
    found = []
    for pattern in patterns:
        for path in glob.glob(pattern):
            if os.path.isdir(path) and path not in found:
                found.append(path)
    return sorted(found)


def audit(project, require_native_content=False):
    project = os.path.realpath(project)
    checks = []
    manifest_path = os.path.join(project, "hub.yml")
    manifest = read_text(manifest_path)

    checks.append(check(
        "hub-manifest",
        bool(manifest),
        "hub.yml is readable" if manifest else "hub.yml is missing or empty",
    ))

    has_articles = bool(re.search(r"(?m)^articles:\s*(?:#.*)?$", manifest))
    if require_native_content:
        checks.append(check(
            "native-articles",
            has_articles,
            "top-level articles section found" if has_articles
            else "content-rich builds must declare native pages under articles:",
        ))

    resource_pages = bool(
        re.search(
            r"(?im)^\s*(?:-\s*)?(?:alias|type):\s*[\"']?pages[\"']?\s*$",
            manifest,
        )
        or re.search(
            r"(?im)^\s*-\s*\{[^}\n]*(?:alias|type)\s*:\s*[\"']?pages[\"']?(?:\s*[,}])",
            manifest,
        )
    )
    checks.append(check(
        "no-pages-resource-type",
        not resource_pages,
        "ordinary pages are not modelled as resources" if not resource_pages
        else "found a Pages resource type; use native articles instead",
    ))

    templates = template_directories(project)
    checks.append(check(
        "template-discovery",
        True,
        "found %d project template(s)" % len(templates),
    ))

    for template in templates:
        label = os.path.relpath(template, project)
        required_files = (
            "index.php",
            "component.php",
            "error.php",
            "templateDetails.xml",
            os.path.join("less", "site.less"),
        )
        missing_files = [name for name in required_files
                         if not os.path.isfile(os.path.join(template, name))]
        checks.append(check(
            label + ":baseline-files",
            not missing_files,
            "complete template baseline is present" if not missing_files else
            "missing required template files: " + ", ".join(missing_files),
        ))

        page_files = sorted(glob.glob(os.path.join(template, "pages", "*.php")))
        checks.append(check(
            label + ":no-php-pages",
            not page_files,
            "no template-side PHP pages" if not page_files else
            "move these pages to hub.yml articles: " + ", ".join(
                os.path.relpath(path, project) for path in page_files[:8]),
        ))

        catalog_files = []
        for pattern in ("*catalog*.php", "*content*.php", "*dataset*.php", "*guide*.php"):
            catalog_files.extend(glob.glob(os.path.join(template, "data", pattern)))
        catalog_files = sorted(set(catalog_files))
        checks.append(check(
            label + ":no-php-catalog",
            not catalog_files,
            "no template-side PHP content catalog" if not catalog_files else
            "move catalog records to native components: " + ", ".join(
                os.path.relpath(path, project) for path in catalog_files[:8]),
        ))

        index_path = os.path.join(template, "index.php")
        index = read_text(index_path)
        checks.append(check(
            label + ":index",
            bool(index),
            "index.php is readable" if index else "index.php is missing or empty",
        ))
        if not index:
            continue

        component_buffer = bool(re.search(
            r"<jdoc:include\s+type=[\"']component[\"']", index, re.I))
        checks.append(check(
            label + ":component-buffer",
            component_buffer,
            "index.php renders the native component buffer" if component_buffer else
            "index.php must render <jdoc:include type=\"component\" />",
        ))

        router_patterns = (
            r"\$customPages\b",
            r"(?:include|require)(?:_once)?\s*\(?\s*[^;\n]*[/\\]pages[/\\]",
            r"in_array\s*\([^;\n]*\$alias[^;\n]*\)",
        )
        matched = [pattern for pattern in router_patterns if re.search(pattern, index, re.I)]
        checks.append(check(
            label + ":no-page-router",
            not matched,
            "index.php contains no page-dispatch patterns" if not matched else
            "index.php appears to dispatch content by route or alias",
        ))

    ok = all(item["ok"] for item in checks)
    return {
        "ok": ok,
        "action": "audit-site-architecture",
        "project": project,
        "checks": checks,
        "next": [] if ok else [
            "Move editable pages to hub.yml articles:",
            "Link menus with article: <alias>",
            "Keep template index.php limited to shared chrome, modules, and component output",
        ],
    }


def main():
    parser = argparse.ArgumentParser(
        description="Detect template page routers and missing native article content")
    parser.add_argument("project", help="AutoHub project directory")
    parser.add_argument(
        "--require-native-content",
        action="store_true",
        help="require a top-level articles section in hub.yml",
    )
    parser.add_argument("--json", action="store_true", help="emit one JSON object")
    args = parser.parse_args()

    result = audit(args.project, args.require_native_content)
    if args.json:
        print(json.dumps(result, indent=2))
    else:
        for item in result["checks"]:
            print(("PASS" if item["ok"] else "FAIL") + " " + item["name"]
                  + ": " + item["detail"])
    return 0 if result["ok"] else 1


if __name__ == "__main__":
    sys.exit(main())
