import contextlib
import errno
import importlib.machinery
import importlib.util
import io
import json
import os
import shutil
import stat
import subprocess
import tempfile
import unittest
from unittest import mock


def load_autohub():
    path = os.path.join(os.path.dirname(__file__), "autohub")
    loader = importlib.machinery.SourceFileLoader("autohub_cli", path)
    spec = importlib.util.spec_from_loader(loader.name, loader)
    module = importlib.util.module_from_spec(spec)
    loader.exec_module(module)
    return module


autohub = load_autohub()


class FakeDriver:
    name = "docker"

    def __init__(self, project_dir):
        self.dir = project_dir
        self.restored = []
        self.destroyed = False

    def target_id(self):
        return "docker:" + os.path.realpath(self.dir)

    def exec(self, service, command, **_kwargs):
        if command[:4] == ["git", "-C", "/var/www/html", "rev-parse"]:
            return subprocess.CompletedProcess(command, 0, "a" * 40 + "\n", "")
        return subprocess.CompletedProcess(command, 0, "", "")

    def stream_to_file(self, service, command, path):
        with open(path, "wb") as f:
            f.write((service + ":" + os.path.basename(path)).encode())
        return subprocess.CompletedProcess(command, 0, "", "")

    def stream_from_file(self, service, command, path):
        self.restored.append((service, os.path.basename(path)))
        return subprocess.CompletedProcess(command, 0, "", "")

    def restart(self, service):
        return subprocess.CompletedProcess(["restart", service], 0, "", "")

    def down(self, volumes=False):
        self.destroyed = volumes
        return subprocess.CompletedProcess(["down"], 0, "", "")


class SnapshotTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        for name, value in ((".env", "SECRET=value\n"),
                            ("hub.yml", "template: {}\n"),
                            ("autohub.yml", "driver: docker\n")):
            with open(os.path.join(self.tmp.name, name), "w", encoding="utf-8") as f:
                f.write(value)
        self.driver = FakeDriver(self.tmp.name)

    def tearDown(self):
        self.tmp.cleanup()

    def test_snapshot_is_complete_host_side_and_mode_restricted(self):
        result = autohub.Result("backup")
        path = autohub._create_snapshot(self.driver, {}, result, "unit test")
        self.assertTrue(path.startswith(os.path.join(self.tmp.name, "backups")))
        with open(os.path.join(path, "metadata.json"), encoding="utf-8") as f:
            metadata = json.load(f)
        self.assertTrue(metadata["complete"])
        self.assertEqual(metadata["target"], self.driver.target_id())
        self.assertEqual(metadata["source_commit"], "a" * 40)
        self.assertIn("database.sql.gz", metadata["files"])
        self.assertIn("hub_app.tar.gz", metadata["files"])
        self.assertIn("project/.env", metadata["files"])
        mode = stat.S_IMODE(os.stat(os.path.join(path, "project", ".env")).st_mode)
        self.assertEqual(mode, 0o600)

    def test_corrupt_snapshot_is_rejected(self):
        result = autohub.Result("backup")
        path = autohub._create_snapshot(self.driver, {}, result, "corrupt")
        with open(os.path.join(path, "database.sql.gz"), "ab") as f:
            f.write(b"changed")
        check = autohub.Result("backup")
        loaded, metadata = autohub._load_snapshot(path, check)
        self.assertIsNone(loaded)
        self.assertIsNone(metadata)
        self.assertFalse(check.ok)

    def test_restore_coordinates_source_app_tls_and_database(self):
        result = autohub.Result("backup")
        path = autohub._create_snapshot(self.driver, {}, result, "restore")
        check = autohub.Result("backup")
        loaded, metadata = autohub._load_snapshot(path, check)
        self.assertTrue(autohub._restore_snapshot(self.driver, loaded, metadata, check))
        self.assertEqual(self.driver.restored, [
            ("web", "hub_app.tar.gz"),
            ("web", "hub_tls.tar.gz"),
            ("db", "database.sql.gz"),
        ])


class SafetyTests(unittest.TestCase):
    def test_destructive_confirmation_requires_force_and_exact_target(self):
        with tempfile.TemporaryDirectory() as project:
            driver = FakeDriver(project)
            args = type("Args", (), {"force": True, "confirm": "wrong"})()
            result = autohub.Result("destroy")
            self.assertFalse(autohub._require_destructive_confirmation(
                driver, args, result, "destroy"))
            self.assertFalse(result.ok)

            args.confirm = driver.target_id()
            result = autohub.Result("destroy")
            self.assertTrue(autohub._require_destructive_confirmation(
                driver, args, result, "destroy"))

    def test_admin_cli_has_no_password_argument(self):
        parser = autohub.build_parser()
        args = parser.parse_args(["admin", "alice"])
        self.assertEqual(args.user, "alice")
        with contextlib.redirect_stderr(io.StringIO()):
            with self.assertRaises(SystemExit):
                parser.parse_args(["admin", "alice", "secret"])

    def test_destroy_requires_and_validates_external_snapshot(self):
        with tempfile.TemporaryDirectory() as project:
            for name, value in ((".env", "SECRET=value\n"),
                                ("hub.yml", "template: {}\n")):
                with open(os.path.join(project, name), "w", encoding="utf-8") as f:
                    f.write(value)
            driver = FakeDriver(project)
            snapshot_result = autohub.Result("backup")
            snapshot = autohub._create_snapshot(driver, {}, snapshot_result, "destroy")
            args = type("Args", (), {
                "force": True,
                "confirm": driver.target_id(),
                "snapshot": snapshot,
            })()
            result = autohub.Result("destroy")
            autohub.cmd_destroy(driver, {}, args, result)
            self.assertTrue(result.ok)
            self.assertTrue(driver.destroyed)


class ContentProvisioningTests(unittest.TestCase):
    def test_scaffold_provisions_native_articles(self):
        root = os.path.realpath(os.path.join(os.path.dirname(__file__), ".."))
        with open(os.path.join(root, "docker", "bin", "provision.php"),
                  encoding="utf-8") as f:
            provisioner = f.read()
        with open(os.path.join(root, "hub.yml.example"), encoding="utf-8") as f:
            manifest = f.read()

        self.assertIn("$manifest['articles']", provisioner)
        self.assertIn("INSERT INTO `#__content`", provisioner)
        self.assertIn("Hubzero\\Database\\Asset::resolve", provisioner)
        self.assertIn("!empty($item['article'])", provisioner)
        self.assertIn("articles:\n", manifest)
        self.assertIn("article: home", manifest)

    def test_template_pages_are_not_a_manifest_content_pattern(self):
        root = os.path.realpath(os.path.join(os.path.dirname(__file__), ".."))
        with open(os.path.join(root, "hub.yml.example"), encoding="utf-8") as f:
            manifest = f.read()

        self.assertNotIn("type: Pages", manifest)
        self.assertNotIn("templates/pages", manifest)


class InitializationTests(unittest.TestCase):
    def test_init_generates_project_specific_compose_names(self):
        scaffold = os.path.realpath(os.path.join(os.path.dirname(__file__), ".."))
        names = []
        with tempfile.TemporaryDirectory() as root:
            for suffix in ("one", "two"):
                project = os.path.join(root, suffix)
                os.makedirs(os.path.join(project, "scripts"))
                shutil.copy2(os.path.join(scaffold, ".env.example"),
                             os.path.join(project, ".env.example"))
                shutil.copy2(os.path.join(scaffold, "scripts", "hub-init.sh"),
                             os.path.join(project, "scripts", "hub-init.sh"))
                run = subprocess.run(
                    [os.path.join(project, "scripts", "hub-init.sh"),
                     "--site", "Shared Name"],
                    cwd=project, capture_output=True, text=True)
                self.assertEqual(run.returncode, 0, run.stderr)
                env = autohub.read_env(project)
                names.append(env["COMPOSE_PROJECT_NAME"])
                self.assertNotEqual(env["HTTP_PORT"], env["ADMINER_PORT"])
                self.assertNotEqual(env["HTTP_PORT"], env["MAILPIT_PORT"])
                self.assertEqual(stat.S_IMODE(
                    os.stat(os.path.join(project, ".env")).st_mode), 0o600)
        self.assertNotEqual(names[0], names[1])

    def test_docker_target_includes_resolved_compose_name(self):
        with tempfile.TemporaryDirectory() as project:
            driver = autohub.DockerDriver(
                project, {"COMPOSE_PROJECT_NAME": "research-a1b2c3d4"})
            self.assertIn("research-a1b2c3d4", driver.target_id())
            self.assertIn(os.path.realpath(project), driver.target_id())

    def test_port_preflight_detects_a_bound_port(self):
        fake = mock.Mock()
        fake.bind.side_effect = OSError(errno.EADDRINUSE, "in use")
        with mock.patch.object(autohub.socket, "socket", return_value=fake):
            self.assertFalse(autohub._port_available(54321))


class TemplateWorkflowTests(unittest.TestCase):
    def test_template_create_copies_mounts_registers_and_activates(self):
        scaffold = os.path.realpath(os.path.join(os.path.dirname(__file__), ".."))
        with tempfile.TemporaryDirectory() as project:
            shutil.copytree(os.path.join(scaffold, "template-starter"),
                            os.path.join(project, "template-starter"))
            with open(os.path.join(project, ".env"), "w", encoding="utf-8") as f:
                f.write("COMPOSE_PROJECT_NAME=test\n")
            with open(os.path.join(project, "hub.yml"), "w", encoding="utf-8") as f:
                f.write("# project manifest\n")
            driver = FakeDriver(project)
            args = type("Args", (), {
                "sub": "create",
                "name": "researchhub",
                "branch": None,
                "token_env": "GITLAB_TOKEN",
                "force": False,
            })()
            result = autohub.Result("template")
            autohub.cmd_template(driver, {}, args, result)
            self.assertTrue(result.ok)
            target = os.path.join(project, "templates", "researchhub")
            self.assertTrue(os.path.isfile(os.path.join(target, "index.php")))
            with open(os.path.join(target, "templateDetails.xml"),
                      encoding="utf-8") as f:
                details = f.read()
            self.assertIn("<name>researchhub</name>", details)
            with open(os.path.join(project, "hub.yml"), encoding="utf-8") as f:
                manifest = f.read()
            self.assertIn("alias: researchhub", manifest)
            self.assertIn("site: researchhub", manifest)
            env = autohub.read_env(project)
            self.assertEqual(env["LOCAL_TEMPLATE_PATH"],
                             "./templates/researchhub")
            self.assertEqual(env["LOCAL_TEMPLATE_NAME"], "researchhub")

    def test_legacy_less_lint_rejects_mixed_unit_css_math(self):
        issues = autohub._lint_less_text(
            ".hero { width: min(100%, 72rem); max-width: max(20px, 2rem); }")
        self.assertEqual(len(issues), 2)
        self.assertFalse(autohub._lint_less_text(
            ".hero { width: 100%; max-width: 72rem; min-height: 20rem; }"))

    def test_starter_has_native_component_baseline(self):
        root = os.path.realpath(os.path.join(os.path.dirname(__file__), ".."))
        with open(os.path.join(root, "template-starter", "less", "site.less"),
                  encoding="utf-8") as f:
            less = f.read()
        for selector in (".filters", ".entries", ".pagination",
                         ".no-results", "table", "fieldset"):
            self.assertIn(selector, less)
        self.assertFalse(autohub._lint_less_text(less))


class VerificationTests(unittest.TestCase):
    def test_login_page_classifier_reports_consent_interstitial(self):
        token, detail = autohub._classify_admin_login_page(
            "<html><h1>User consent</h1><p>Accept the privacy agreement and terms.</p></html>")
        self.assertIsNone(token)
        self.assertIn("consent interstitial", detail)

    def test_login_page_classifier_accepts_csrf_token(self):
        csrf = "a" * 32
        token, detail = autohub._classify_admin_login_page(
            '<input name="%s" value="1"><input name="username">' % csrf)
        self.assertEqual(token, csrf)
        self.assertIn("CSRF token found", detail)

    def test_parser_exposes_component_routes_and_template_create(self):
        parser = autohub.build_parser()
        verify = parser.parse_args(
            ["verify", "--scope", "components", "--route", "/learn"])
        self.assertEqual(verify.scope, "components")
        self.assertEqual(verify.route, ["/learn"])
        template = parser.parse_args(
            ["template", "create", "--name", "researchhub"])
        self.assertEqual(template.sub, "create")

    def test_component_scope_inventories_defaults_menu_and_extra_routes(self):
        class RouteDriver(FakeDriver):
            def url(self):
                return "https://localhost:8443"

            def exec(self, service, command, **_kwargs):
                if service == "db":
                    return subprocess.CompletedProcess(
                        command, 0, "/\n/about\n", "")
                return super().exec(service, command, **_kwargs)

        body = (b'<html><link href="/site.css"><img src="/logo.svg">'
                + b"x" * 300 + b"</html>")

        def fake_get(url, **_kwargs):
            if url.endswith((".css", ".svg")):
                return 200, b"asset", {}
            return 200, body, {}

        with tempfile.TemporaryDirectory() as project, \
                mock.patch.object(autohub, "http_get", side_effect=fake_get):
            args = type("Args", (), {
                "scope": "components",
                "route": ["/learn"],
            })()
            result = autohub.Result("verify")
            autohub.cmd_verify(
                RouteDriver(project), {"DB_PREFIX": "jos_"}, args, result)
            names = [check["name"] for check in result.checks]
            self.assertIn("component route /resources", names)
            self.assertIn("component route /about", names)
            self.assertIn("component route /learn", names)
            self.assertFalse(result.verify_failed)


if __name__ == "__main__":
    unittest.main()
