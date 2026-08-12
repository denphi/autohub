import importlib.util
import os
import shutil
import tempfile
import unittest


SCRIPT_DIR = os.path.dirname(__file__)
SPEC = importlib.util.spec_from_file_location(
    "audit_site_architecture",
    os.path.join(SCRIPT_DIR, "audit_site_architecture.py"))
audit_module = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(audit_module)


class ArchitectureAuditTests(unittest.TestCase):
    def make_project(self, root):
        with open(os.path.join(root, "hub.yml"), "w", encoding="utf-8") as stream:
            stream.write("articles:\n  - title: Home\n    alias: home\n")
        template = os.path.join(root, "templates", "researchhub")
        scaffold = os.path.realpath(os.path.join(
            SCRIPT_DIR, "..", "assets", "scaffold", "template-starter"))
        shutil.copytree(scaffold, template)
        return template

    def test_starter_passes_native_component_and_asset_checks(self):
        with tempfile.TemporaryDirectory() as project:
            self.make_project(project)
            result = audit_module.audit(project, require_native_content=True)
            self.assertTrue(result["ok"], result["checks"])

    def test_missing_native_component_surface_fails(self):
        with tempfile.TemporaryDirectory() as project:
            template = self.make_project(project)
            path = os.path.join(template, "less", "site.less")
            with open(path, encoding="utf-8") as stream:
                less = stream.read()
            with open(path, "w", encoding="utf-8") as stream:
                stream.write(less.replace(".pagination", ".pages"))
            result = audit_module.audit(project, require_native_content=True)
            failures = [check["name"] for check in result["checks"]
                        if not check["ok"]]
            self.assertIn(
                "templates/researchhub:native-component-styles", failures)

    def test_standalone_stylesheet_without_core_layering_fails(self):
        # A standalone site.less satisfies every generic-surface grep by
        # construction while shipping none of core's grid system -- the audit
        # must not report PASS on a structurally broken site.
        with tempfile.TemporaryDirectory() as project:
            template = self.make_project(project)
            path = os.path.join(template, "less", "site.less")
            with open(path, encoding="utf-8") as stream:
                less = stream.read()
            with open(path, "w", encoding="utf-8") as stream:
                stream.write("\n".join(
                    line for line in less.splitlines()
                    if not line.lstrip().startswith("@import")))
            result = audit_module.audit(project, require_native_content=True)
            failures = [check["name"] for check in result["checks"]
                        if not check["ok"]]
            self.assertIn(
                "templates/researchhub:core-stylesheet-layering", failures)

    def test_article_alias_shadowing_a_component_route_fails(self):
        with tempfile.TemporaryDirectory() as project:
            self.make_project(project)
            with open(os.path.join(project, "hub.yml"), "w",
                      encoding="utf-8") as stream:
                stream.write("articles:\n"
                             "  - title: Home\n"
                             "    alias: home\n"
                             "  - title: Support\n"
                             "    alias: support\n")
            result = audit_module.audit(project, require_native_content=True)
            failures = {check["name"]: check["detail"]
                        for check in result["checks"] if not check["ok"]}
            self.assertIn("no-component-route-shadowing", failures)
            self.assertIn("support", failures["no-component-route-shadowing"])

    def test_flow_style_article_alias_shadowing_is_detected(self):
        # `- { title: Support, alias: support }` must fail the same gate as
        # the block-style form; otherwise the audit passes a manifest the
        # provisioner then rejects.
        with tempfile.TemporaryDirectory() as project:
            self.make_project(project)
            with open(os.path.join(project, "hub.yml"), "w",
                      encoding="utf-8") as stream:
                stream.write("articles:\n"
                             "  - { title: Home, alias: home }\n"
                             "  - { title: Support, alias: support }\n")
            result = audit_module.audit(project, require_native_content=True)
            failures = {check["name"]: check["detail"]
                        for check in result["checks"] if not check["ok"]}
            self.assertIn("no-component-route-shadowing", failures)
            self.assertIn("support", failures["no-component-route-shadowing"])

    def test_reserved_routes_cover_the_components_hubzero_ships(self):
        # The provisioner rejects an alias matching any enabled component, so
        # a narrower audit list would pass what provisioning fails.
        for alias in ("projects", "publications", "courses", "blog", "wiki",
                      "kb", "events", "answers", "wishlist", "citations"):
            self.assertIn(alias, audit_module.RESERVED_COMPONENT_ROUTES)

    def test_hard_coded_template_asset_root_fails(self):
        with tempfile.TemporaryDirectory() as project:
            template = self.make_project(project)
            path = os.path.join(template, "index.php")
            with open(path, "a", encoding="utf-8") as stream:
                stream.write('\n<img src="/app/templates/researchhub/logo.svg">\n')
            result = audit_module.audit(project, require_native_content=True)
            failures = [check["name"] for check in result["checks"]
                        if not check["ok"]]
            self.assertIn("templates/researchhub:base-url-assets", failures)


if __name__ == "__main__":
    unittest.main()
