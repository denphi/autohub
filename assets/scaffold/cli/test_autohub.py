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
import tarfile
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
        self.restore_commands = {}
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
        self.restore_commands[os.path.basename(path)] = command
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
        tls_command = " ".join(
            self.driver.restore_commands["hub_tls.tar.gz"])
        self.assertIn("find /etc/hubzero/tls -mindepth 1", tls_command)
        self.assertNotIn("rm -rf /etc/hubzero/tls;", tls_command)


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

    def test_native_component_framework_is_present_and_content_is_read_only(self):
        root = os.path.realpath(os.path.join(os.path.dirname(__file__), ".."))
        with open(os.path.join(root, "docker-compose.yml"), encoding="utf-8") as f:
            compose = f.read()
        with open(os.path.join(root, "docker", "bin", "components",
                               "provision.php"), encoding="utf-8") as f:
            provisioner = f.read()

        self.assertIn("${HUB_CONTENT_PATH:-./content}:/etc/hubzero/content:ro",
                      compose)
        self.assertIn("autohub_import_file", provisioner)
        self.assertIn("realpath", provisioner)
        self.assertIn("HUB_COMPONENT_AUTHORIZATION", provisioner)
        self.assertIn("Components\\Projects\\Models\\Repo", provisioner)
        self.assertIn("publication_version_id", provisioner)
        self.assertIn("#__courses_asset_associations", provisioner)


class ComponentCommandTests(unittest.TestCase):
    def test_all_four_component_command_families_share_the_contract(self):
        parser = autohub.build_parser()
        for domain in ("project", "resource", "publication", "course"):
            args = parser.parse_args([
                domain, "plan", "--max-items", "3", "--json"])
            self.assertEqual(args.cmd, domain)
            self.assertEqual(args.sub, "plan")
            self.assertEqual(args.max_items, 3)
            self.assertIsNone(args.id)
            self.assertIs(args.fn, autohub.cmd_component)

    def test_result_emits_optional_structured_data(self):
        result = autohub.Result("publication")
        result.data = {"changes": {"create": ["example"]}}
        output = io.StringIO()
        with contextlib.redirect_stdout(output):
            result.emit(True)
        payload = json.loads(output.getvalue())
        self.assertEqual(payload["data"]["changes"]["create"], ["example"])

    def test_apply_requires_every_authorization_reported_by_plan(self):
        class ComponentDriver(FakeDriver):
            def url(self):
                return "https://localhost:8443"

            def exec(self, service, command, **_kwargs):
                if command[0] == "hub-component" and command[2] == "plan":
                    domain = command[1]
                    payload = {
                        "ok": True,
                        "authorization": ["publish"] if domain == "publication" else [],
                        "errors": [],
                        "checks": [],
                        "data": {"changes": {
                            "create": ["example"] if domain == "publication" else []}},
                    }
                    return subprocess.CompletedProcess(
                        command, 0, json.dumps(payload), "")
                raise AssertionError("apply must stop before mutation")

        with tempfile.TemporaryDirectory() as project:
            with open(os.path.join(project, "hub.yml"), "w",
                      encoding="utf-8") as stream:
                stream.write("publications: []\n")
            args = type("Args", (), {
                "cmd": "publication", "sub": "apply", "manifest": "hub.yml",
                "max_items": 3, "alias": None, "id": None, "authorize": [],
            })()
            result = autohub.Result("publication")
            autohub.cmd_component(ComponentDriver(project), {}, args, result)
            self.assertFalse(result.ok)
            self.assertIn("--authorize", result.details[-1])

    def test_kubernetes_stages_only_files_beneath_content_root(self):
        class KubernetesStageDriver(FakeDriver):
            name = "kubernetes"

            def target_id(self):
                return "kubernetes:test:autohub"

            def stream_from_file(self, service, command, path):
                with tarfile.open(path, "r:gz") as bundle:
                    self.members = bundle.getnames()
                return subprocess.CompletedProcess(command, 0, "", "")

        with tempfile.TemporaryDirectory() as project:
            content = os.path.join(project, "content")
            os.makedirs(content)
            source = os.path.join(content, "observations.csv")
            with open(source, "w", encoding="utf-8") as stream:
                stream.write("flower,pollinator\n")
            driver = KubernetesStageDriver(project)
            result = autohub.Result("resource")
            destination = autohub._stage_kubernetes_component_files(
                driver, {"HUB_CONTENT_PATH": "./content"},
                [{"path": "content/observations.csv"}], result)
            self.assertTrue(destination.startswith("/tmp/autohub-content-"))
            self.assertEqual(driver.members, ["observations.csv"])
            self.assertFalse(result.verify_failed)


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

    def test_template_status_rejects_path_traversal_names(self):
        # A bare "." or ".." must never reach `TEMPLATES_DIR + "/" + name` and
        # escape the templates directory (git status/push on the wrong repo).
        class TraversalDriver(FakeDriver):
            def exec(self, service, command, **_kwargs):
                raise AssertionError(
                    "traversal name must be rejected before any container exec")

        for unsafe in ("..", "."):
            args = type("Args", (), {
                "sub": "status", "name": unsafe, "branch": None,
                "token_env": "GITLAB_TOKEN", "force": False,
            })()
            result = autohub.Result("template")
            autohub.cmd_template(TraversalDriver("/nonexistent"), {}, args, result)
            self.assertFalse(result.ok)
            self.assertIn("begin with a letter or digit", result.details[-1])

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
        tls = parser.parse_args(
            ["tls", "setup", "--hostname", "research.localhost"])
        self.assertEqual(tls.sub, "setup")
        self.assertEqual(tls.hostname, ["research.localhost"])

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


class TrustedTlsTests(unittest.TestCase):
    def test_hostname_validation_adds_localhost_ips_and_rejects_flags(self):
        names = autohub._validated_tls_hostnames(
            ["research.localhost"], {"HUB_TLS_CN": "ignored"})
        self.assertEqual(names[0], "research.localhost")
        self.assertIn("localhost", names)
        self.assertIn("127.0.0.1", names)
        self.assertIn("::1", names)
        with self.assertRaises(Exception):
            autohub._validated_tls_hostnames(["--unsafe"], {})

    def test_tls_setup_issues_ignored_project_certificate_and_updates_env(self):
        with tempfile.TemporaryDirectory() as project:
            with open(os.path.join(project, ".env"), "w", encoding="utf-8") as f:
                f.write("HUB_TLS_PATH=hub_tls\nHUB_TLS_CN=localhost\n")
            driver = FakeDriver(project)
            args = type("Args", (), {
                "sub": "setup",
                "hostname": ["research.localhost"],
            })()

            def fake_run(command, **_kwargs):
                if "-cert-file" in command:
                    cert = command[command.index("-cert-file") + 1]
                    key = command[command.index("-key-file") + 1]
                    with open(cert, "w", encoding="utf-8") as stream:
                        stream.write("certificate")
                    with open(key, "w", encoding="utf-8") as stream:
                        stream.write("private-key")
                return subprocess.CompletedProcess(command, 0, "ok", "")

            result = autohub.Result("tls")
            with mock.patch.object(autohub.shutil, "which",
                                   return_value="/usr/local/bin/mkcert"), \
                    mock.patch.object(autohub.subprocess, "run",
                                      side_effect=fake_run):
                autohub.cmd_tls(driver, autohub.read_env(project), args, result)

            self.assertFalse(result.verify_failed)
            env = autohub.read_env(project)
            self.assertEqual(env["HUB_TLS_PATH"], "./.autohub/tls")
            self.assertEqual(env["HUB_TLS_MODE"], "mkcert")
            self.assertEqual(env["HUB_TLS_CN"], "research.localhost")
            key = os.path.join(project, ".autohub", "tls", "hub.key")
            self.assertEqual(stat.S_IMODE(os.stat(key).st_mode), 0o600)

    def test_tls_setup_refuses_a_project_local_root_ca(self):
        with tempfile.TemporaryDirectory() as project:
            driver = FakeDriver(project)
            args = type("Args", (), {
                "sub": "setup",
                "hostname": [],
            })()
            response = subprocess.CompletedProcess(
                ["mkcert", "-CAROOT"], 0,
                os.path.join(project, ".autohub", "ca") + "\n", "")
            result = autohub.Result("tls")
            with mock.patch.object(autohub.shutil, "which",
                                   return_value="/usr/local/bin/mkcert"), \
                    mock.patch.object(autohub.subprocess, "run",
                                      return_value=response) as run:
                autohub.cmd_tls(driver, {}, args, result)
            self.assertFalse(result.ok)
            self.assertEqual(run.call_count, 1)
            self.assertIn("root CA inside the project", result.details[-1])

    def test_scaffold_ignores_and_mounts_trusted_local_tls(self):
        root = os.path.realpath(os.path.join(os.path.dirname(__file__), ".."))
        with open(os.path.join(root, ".gitignore"), encoding="utf-8") as f:
            ignore = f.read()
        with open(os.path.join(root, "docker-compose.yml"),
                  encoding="utf-8") as f:
            compose = f.read()
        self.assertIn("/.autohub/", ignore)
        self.assertIn("rootCA-key.pem", ignore)
        self.assertIn("${HUB_TLS_PATH:-hub_tls}:/etc/hubzero/tls", compose)

    def test_host_trust_check_does_not_confuse_http_error_with_tls_error(self):
        response = subprocess.CompletedProcess(
            ["curl"], 0, "500", "")
        with mock.patch.object(autohub.shutil, "which",
                               return_value="/usr/bin/curl"), \
                mock.patch.object(autohub.subprocess, "run",
                                  return_value=response) as run:
            trusted, detail = autohub._host_https_trust(
                "https://localhost:8443/")
        self.assertTrue(trusted)
        self.assertIn("HTTP 500", detail)
        self.assertNotIn("--fail", run.call_args.args[0])


if __name__ == "__main__":
    unittest.main()
