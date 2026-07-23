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
