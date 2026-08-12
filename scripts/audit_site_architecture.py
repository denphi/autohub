#!/usr/bin/env python3
"""Audit an AutoHub project for native-content/template separation."""

import argparse
import glob
import json
import os
import re
import sys


# Routes owned by components HUBzero ships enabled. An article aliased to any
# of these shadows the component (see the shadowing check below). Derived from
# core/components/com_*; entries that never resolve as a bare front-end route
# (cpanel, installer, config, ...) are omitted.
RESERVED_COMPONENT_ROUTES = frozenset((
    "activity", "answers", "billboards", "blog", "citations", "collections",
    "content", "courses", "developer", "events", "feedback", "forum",
    "groups", "help", "jobs", "kb", "login", "media", "members", "messages",
    "news", "newsletter", "projects", "publications", "register", "resources",
    "search", "services", "storefront", "support", "tags", "tools", "usage",
    "users", "whatsnew", "wiki", "wishlist",
))


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

    # An article alias that matches a native component's route silently shadows
    # the component: /support serves the article, com_support becomes
    # unreachable, and the route still returns HTTP 200 so reachability checks
    # pass. Provisioning rejects an alias matching any enabled component; this
    # is the pre-provision gate, so keep the list aligned with the components
    # HUBzero actually ships or the audit passes what provisioning rejects.
    shadowing = []
    articles_section = re.search(
        r"(?ms)^articles:[^\n]*\n(.*?)(?=^[A-Za-z_][A-Za-z0-9_-]*:|\Z)", manifest)
    if articles_section:
        body = articles_section.group(1)
        # Block style (`alias: support`) and flow style
        # (`- { title: Support, alias: support }`) alike.
        aliases = re.findall(
            r"(?m)^\s+alias:\s*[\"']?([A-Za-z0-9_-]+)[\"']?\s*(?:#.*)?$", body)
        aliases += re.findall(
            r"[{,]\s*alias\s*:\s*[\"']?([A-Za-z0-9_-]+)[\"']?\s*(?=[,}])", body)
        shadowing = sorted({alias for alias in aliases
                            if alias.lower() in RESERVED_COMPONENT_ROUTES})
    checks.append(check(
        "no-component-route-shadowing",
        not shadowing,
        "no article alias shadows a native component route" if not shadowing
        else "article alias(es) would shadow native component routes: "
             + ", ".join(shadowing),
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

        less_path = os.path.join(template, "less", "site.less")
        less = read_text(less_path)

        # A standalone stylesheet passes every generic-surface grep below by
        # construction while shipping none of core's .grid/.col.span* system,
        # #content-header, fontcons, tabs, or tooltips -- every native
        # component route renders structurally broken with no error. Require
        # the template to layer on core's stylesheet, or to define the grid
        # primitives itself.
        imports_core = bool(re.search(
            r"""(?m)^\s*@import\s+(?:url\(\s*)?["'][^"']*core/assets/less/site(?:\.less)?["']""",
            less))
        defines_grid = all(re.search(pattern, less) for pattern in
                           (r"\.col\b", r"\bspan\d", r"#content-header\b"))
        checks.append(check(
            label + ":core-stylesheet-layering",
            imports_core or defines_grid,
            ("site.less layers on core's stylesheet" if imports_core else
             "site.less defines the grid primitives itself")
            if imports_core or defines_grid else
            "site.less neither imports core/assets/less/site.less nor defines "
            ".col/span*/#content-header; native components will render unstyled",
        ))

        native_surfaces = {
            "form controls": (r"\binput\b", r"\bselect\b", r"\bbutton\b"),
            "tables": (r"\btable\b",),
            "filters": (r"\.filters?\b",),
            "results": (r"\.(?:entries|results)\b",),
            "pagination": (r"\.pagination\b",),
            "empty states": (r"\.(?:no-results|empty|empty-state)\b",),
            "responsive rules": (r"@media\b",),
        }
        missing_surfaces = [
            surface for surface, patterns in native_surfaces.items()
            if not all(re.search(pattern, less, re.I) for pattern in patterns)
        ]
        checks.append(check(
            label + ":native-component-styles",
            not missing_surfaces,
            "native component baseline covers shared surfaces"
            if not missing_surfaces else
            "site.less lacks baseline styling for: " + ", ".join(missing_surfaces),
        ))

        php_shell = "\n".join(
            read_text(os.path.join(template, filename))
            for filename in ("index.php", "component.php", "error.php")
        )
        hardcoded_app_template = bool(re.search(
            r"['\"]/app/templates/", php_shell, re.I))
        checks.append(check(
            label + ":base-url-assets",
            not hardcoded_app_template,
            "template shell derives asset paths from the CMS base URL"
            if not hardcoded_app_template else
            "template PHP hard-codes /app/templates; derive paths from baseurl and the active template",
        ))

    ok = all(item["ok"] for item in checks)
    failed_names = {item["name"] for item in checks if not item["ok"]}
    next_steps = []
    if any(name.endswith(":core-stylesheet-layering") for name in failed_names):
        next_steps.append(
            "Add `@import \"../../../../core/assets/less/site.less\";` so the template layers on core instead of replacing it")
    if "no-component-route-shadowing" in failed_names:
        next_steps.append(
            "Rename article aliases that collide with component routes (support, resources, groups, members, search, login, register, tags)")
    if any(name.endswith(":native-component-styles") for name in failed_names):
        next_steps.append(
            "Add restrained native form, table, filter, result, pagination, empty-state, and responsive styles")
    if any(name.endswith(":base-url-assets") for name in failed_names):
        next_steps.append(
            "Derive template asset paths from the CMS base URL and active template")
    if any(name.endswith((":no-php-pages", ":no-php-catalog",
                          ":no-page-router", ":component-buffer"))
           for name in failed_names) or "native-articles" in failed_names:
        next_steps.extend([
            "Move editable pages to hub.yml articles:",
            "Link menus with article: <alias>",
            "Keep template index.php limited to shared chrome, modules, and component output",
        ])
    if any(name.endswith(":baseline-files") for name in failed_names):
        next_steps.append(
            "Generate the complete baseline with `cli/autohub template create --name <alias> --json`")
    return {
        "ok": ok,
        "action": "audit-site-architecture",
        "project": project,
        "checks": checks,
        "next": [] if ok else next_steps,
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
