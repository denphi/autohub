import contextlib
import errno
import importlib.machinery
import importlib.util
import io
import json
import os
import re
import shutil
import socket
import stat
import subprocess
import tarfile
import tempfile
import unittest
from unittest import mock


def multiline_flow_mappings(text):
    """1-based line numbers of `{...}` flow mappings that span lines.

    The CMS pins symfony/yaml 3.4, which parses inline collections only when
    they sit on one line; multi-line support arrived in 4.4. A manifest that
    trips this fails to parse entirely, so nothing at all is provisioned.
    """
    offenders = []
    block_indent = None
    depth = 0
    opened_at = None
    for number, line in enumerate(text.splitlines(), 1):
        stripped = line.strip()
        if block_indent is not None:
            # Inside a block scalar body: braces there are content, not YAML.
            if stripped and (len(line) - len(line.lstrip())) <= block_indent:
                block_indent = None
            else:
                continue
        if not stripped or stripped.startswith("#"):
            continue
        if re.search(r":\s*[|>][+-]?\d*\s*$", line):
            block_indent = len(line) - len(line.lstrip())
            continue
        code = re.sub(r"\s#.*$", "", line)
        opens, closes = code.count("{"), code.count("}")
        if depth == 0 and opens > closes:
            opened_at = number
        depth = max(0, depth + opens - closes)
        if depth == 0 and opened_at is not None:
            offenders.append(opened_at)      # report where it opened, once
            opened_at = None
    if opened_at is not None:
        offenders.append(opened_at)
    return offenders


def load_autohub():
    path = os.path.join(os.path.dirname(__file__), "autohub")
    loader = importlib.machinery.SourceFileLoader("autohub_cli", path)
    spec = importlib.util.spec_from_loader(loader.name, loader)
    module = importlib.util.module_from_spec(spec)
    loader.exec_module(module)
    return module


autohub = load_autohub()


def read_cli_source():
    path = os.path.join(os.path.dirname(__file__), "autohub")
    with open(path, encoding="utf-8") as stream:
        return stream.read()


class RecordingDriver:
    """Records every exec instead of running one, so a test can prove a
    refused command never reached the container at all."""

    name = "docker"

    def __init__(self, project_dir):
        self.dir = project_dir
        self.calls = []

    def target_id(self):
        return "docker:" + os.path.realpath(self.dir)

    def exec(self, service, command, **_kwargs):
        self.calls.append((service, command))
        return subprocess.CompletedProcess(command, 0, "", "")


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


class ReadOnlyQueryTests(unittest.TestCase):
    """`db query` is the path an agent reaches for most casually during
    diagnosis. SKILL.md calls it read-only; these prove the CLI enforces it."""

    READ_ONLY = [
        "SELECT * FROM jos_extensions",
        "select id, alias from jos_content where state = 1 limit 10;",
        "SHOW TABLES",
        "SHOW CREATE TABLE jos_menu",          # contains "create", still a read
        "DESCRIBE jos_resources",
        "EXPLAIN SELECT * FROM jos_content",
        "WITH t AS (SELECT id FROM jos_content) SELECT COUNT(*) FROM t",
        "SELECT * FROM jos_content WHERE title = 'drop table jos_users; --'",
        "SELECT `weird;column` FROM jos_content",
        "SELECT * FROM jos_content WHERE note = 'a''b; DELETE FROM x'",
    ]

    MUTATING = [
        "DROP TABLE jos_users",
        "DELETE FROM jos_users WHERE 1",
        "UPDATE jos_content SET state = 0",
        "TRUNCATE jos_session",
        "INSERT INTO jos_content VALUES (1)",
        "GRANT ALL ON *.* TO 'x'@'%'",
        "SET GLOBAL general_log = 1",
        "CALL some_proc()",
        # `mariadb -e` runs every statement in the string, so a smuggled
        # second statement is a complete bypass of the verb allowlist.
        "SELECT 1; DROP TABLE jos_users",
        "SELECT 1 /* x */; DROP TABLE jos_users",
        # MySQL requires whitespace after `--`, so this really is two
        # statements at the server even though it looks commented out.
        "SELECT 1--1; DROP TABLE jos_users",
        # A CTE may feed UPDATE/DELETE in MariaDB.
        "WITH t AS (SELECT id FROM jos_content) DELETE FROM jos_content"
        " WHERE id IN (SELECT id FROM t)",
        # Writes a webshell into the document root without any write verb.
        "SELECT * FROM jos_users INTO OUTFILE '/var/www/html/pwn.php'",
        "SELECT LOAD_FILE('/etc/passwd')",
        "-- just a comment",
        "",
    ]

    def test_read_only_statements_are_accepted(self):
        for sql in self.READ_ONLY:
            self.assertEqual("", autohub._reject_non_read_only_sql(sql), sql)

    def test_mutating_statements_are_refused(self):
        for sql in self.MUTATING:
            self.assertNotEqual("", autohub._reject_non_read_only_sql(sql), sql)

    def test_query_refuses_a_write_and_never_reaches_the_database(self):
        with tempfile.TemporaryDirectory() as project:
            driver = RecordingDriver(project)
            args = type("Args", (), {
                "sub": "query", "arg": "DELETE FROM jos_users",
                "write": False, "force": False, "confirm": None, "json": True,
            })()
            result = autohub.Result("db")
            autohub.cmd_db(driver, {}, args, result)
            self.assertFalse(result.ok)
            self.assertEqual([], driver.calls)

    def test_read_query_uses_the_unprivileged_account_and_a_read_only_session(self):
        with tempfile.TemporaryDirectory() as project:
            driver = RecordingDriver(project)
            args = type("Args", (), {
                "sub": "query", "arg": "SELECT 1",
                "write": False, "force": False, "confirm": None, "json": True,
            })()
            autohub.cmd_db(driver, {}, args, autohub.Result("db"))
            command = " ".join(driver.calls[0][1])
            self.assertIn("$MARIADB_USER", command)
            self.assertNotIn("MARIADB_ROOT_PASSWORD", command)
            self.assertIn("SET SESSION TRANSACTION READ ONLY", command)

    def test_write_query_requires_force_and_exact_target(self):
        with tempfile.TemporaryDirectory() as project:
            driver = RecordingDriver(project)
            args = type("Args", (), {
                "sub": "query", "arg": "UPDATE jos_content SET state = 0",
                "write": True, "force": True, "confirm": "wrong", "json": True,
            })()
            result = autohub.Result("db")
            autohub.cmd_db(driver, {}, args, result)
            self.assertFalse(result.ok)
            self.assertEqual([], driver.calls)

            args.confirm = driver.target_id()
            autohub.cmd_db(driver, {}, args, autohub.Result("db"))
            self.assertEqual(1, len(driver.calls))

    def test_write_flag_is_exposed_and_defaults_off(self):
        parser = autohub.build_parser()
        self.assertFalse(parser.parse_args(["db", "query", "SELECT 1"]).write)
        self.assertTrue(parser.parse_args(
            ["db", "query", "UPDATE x SET y=1", "--write"]).write)


class SecretRedactionTests(unittest.TestCase):
    """SECRET_RE only knows token *shapes*. A generated password echoed back
    by mariadb, apache or git matches none of them, so scrub() also redacts
    the literal values this project holds."""

    def setUp(self):
        self.saved = autohub.SECRET_VALUES

    def tearDown(self):
        autohub.SECRET_VALUES = self.saved

    def test_literal_secret_values_are_redacted_in_every_form(self):
        autohub.register_secret_values({
            "DB_PASSWORD": "Xq7vNp2LmR8s",
            "HUB_SECRET": "abcdefghijklmnop",
        })
        for text in ("Access denied for user 'hub'@'%': Xq7vNp2LmR8s",
                     "mariadb -uroot -pXq7vNp2LmR8s hub",
                     "password: Xq7vNp2LmR8s",
                     "rendered app.php with secret abcdefghijklmnop"):
            self.assertNotIn("Xq7vNp2LmR8s", autohub.scrub(text))
            self.assertNotIn("abcdefghijklmnop", autohub.scrub(text))
            self.assertIn("[redacted]", autohub.scrub(text))

    def test_non_secret_and_trivial_values_are_left_alone(self):
        autohub.register_secret_values({
            "DB_USER": "hubuser",
            "HUB_SITENAME": "Research Hub",
            "HUB_SMTP_PASSWORD": "",          # unset
            "TEST_USER_PASSWORD": "short1",   # too short to redact safely
        })
        text = "Research Hub as hubuser with short1"
        self.assertEqual(text, autohub.scrub(text))

    def test_pattern_based_redaction_still_applies(self):
        autohub.register_secret_values({})
        self.assertIn("[redacted]", autohub.scrub(
            "https://oauth2:glpat-AAAABBBBCCCC@gitlab.example/x.git"))


class ExecTimeoutTests(unittest.TestCase):
    """An uncapped exec hangs the CLI forever, which leaves an agent driving
    this contract with nothing to react to."""

    def test_container_exec_is_capped_by_default(self):
        with tempfile.TemporaryDirectory() as project:
            driver = autohub.DockerDriver(project, {})
            seen = {}

            def fake_run(args, **kwargs):
                seen.update(kwargs)
                return subprocess.CompletedProcess(args, 0, "", "")

            with mock.patch.object(subprocess, "run", fake_run):
                driver.exec("web", ["true"])
            self.assertEqual(autohub.EXEC_TIMEOUT, seen.get("timeout"))

    def test_slow_commands_raise_the_cap_rather_than_removing_it(self):
        source = read_cli_source()
        for call, cap in (('["hub-provision"]', "PROVISION_TIMEOUT"),
                          ('["hub-migrate"]', "PROVISION_TIMEOUT")):
            self.assertIn("%s, timeout=%s" % (call, cap), source)
        self.assertGreater(autohub.UPDATE_TIMEOUT, autohub.EXEC_TIMEOUT)
        self.assertGreater(autohub.STREAM_TIMEOUT, autohub.EXEC_TIMEOUT)

    def test_timeout_is_reported_as_a_contract_result_not_a_crash(self):
        # The specific handler must precede the catch-all inside main(), or a
        # timeout is reported as an opaque "internal error".
        main_body = read_cli_source().split("\ndef main():", 1)[1]
        self.assertIn("except subprocess.TimeoutExpired", main_body)
        self.assertLess(main_body.index("except subprocess.TimeoutExpired"),
                        main_body.index("except Exception as e:"))


class BootScopedLogTests(unittest.TestCase):
    """Container logs are cumulative, so an error from the boot you just fixed
    must not fail the retry that fixed it."""

    MARKER = "\x1b[0;36m[hub]\x1b[0m HUBzero container starting"

    def _buffer(self, *boots):
        return "\n".join(self.MARKER + "\n" + b for b in boots)

    def test_previous_boot_error_does_not_fail_the_current_boot(self):
        text = self._buffer("\x1b[0;31m[hub] ERROR:\x1b[0m schema import failed",
                            "all good\n[hub] bootstrap complete")
        current = autohub._current_boot_log(autohub.ANSI_RE.sub("", text))
        self.assertNotIn("ERROR", current)
        self.assertIn("bootstrap complete", current)

    def test_current_boot_error_is_still_detected(self):
        text = self._buffer("fine", "\x1b[0;31m[hub] ERROR:\x1b[0m schema import failed")
        current = autohub._current_boot_log(autohub.ANSI_RE.sub("", text))
        self.assertRegex(current, r"\[hub\] ERROR")

    def test_marker_survives_the_colour_codes_lib_sh_emits(self):
        # lib.sh prints "\033[0;36m[hub]\033[0m <message>", so the reset code
        # sits between "[hub]" and the text: a literal "[hub] HUBzero..."
        # search finds nothing.
        self.assertIn(autohub.BOOT_MARKER, autohub.ANSI_RE.sub("", self.MARKER))

    def test_missing_marker_falls_back_to_the_whole_buffer(self):
        text = "no marker here, older image"
        self.assertEqual(text, autohub._current_boot_log(text))

    def test_wait_reads_a_window_large_enough_to_hold_the_marker(self):
        # A fixed 400-line tail can let "bootstrap complete" scroll past
        # between 10s polls on a chatty first boot.
        self.assertGreaterEqual(autohub.BOOT_LOG_LINES, 2000)
        self.assertIn("tail=BOOT_LOG_LINES", read_cli_source())

    def test_doctor_exposes_an_all_boots_escape_hatch(self):
        parser = autohub.build_parser()
        self.assertFalse(parser.parse_args(["doctor"]).all_boots)
        self.assertTrue(parser.parse_args(["doctor", "--all-boots"]).all_boots)


class HelmValuesTests(unittest.TestCase):
    """helm splits --set on unescaped commas, so an ordinary hub name became a
    second, bogus key."""

    def _driver(self, env, **kube):
        driver = autohub.KubernetesDriver.__new__(autohub.KubernetesDriver)
        driver.env = env
        driver.rel = "autohub"
        driver.backup = kube.get("backup", {})
        driver.ingress_host = kube.get("ingress_host", "")
        return driver

    def test_a_comma_in_a_hub_name_survives(self):
        driver = self._driver({"HUB_SITENAME": "Nanoscale Hub, Inc.",
                               "DB_NAME": "hub"})
        rendered = autohub._render_yaml(driver.release_values())
        self.assertIn('HUB_SITENAME: "Nanoscale Hub, Inc."', rendered)

    def test_values_are_rendered_as_json_compatible_yaml(self):
        driver = self._driver({"HUB_SITENAME": 'quote " colon: hash # comma,'})
        rendered = autohub._render_yaml(driver.release_values())
        line = [l for l in rendered.splitlines() if "HUB_SITENAME" in l][0]
        value = line.split(": ", 1)[1]
        self.assertEqual('quote " colon: hash # comma,', json.loads(value))

    def test_image_tag_stays_a_string_as_set_string_guaranteed(self):
        driver = self._driver({"HUB_IMAGE": "reg.example/hub:1.2"})
        rendered = autohub._render_yaml(driver.release_values())
        self.assertIn('tag: "1.2"', rendered)      # not the float 1.2

    def test_typed_chart_values_stay_typed(self):
        driver = self._driver({}, backup={"enabled": "true", "keep": "7"})
        rendered = autohub._render_yaml(driver.release_values())
        self.assertIn("enabled: true", rendered)
        self.assertIn("keep: 7", rendered)

    def test_secrets_no_longer_travel_in_argv(self):
        # HUBZERO_REPO may carry a token, and --set-string put it in argv.
        # Match the quoted argv form, not the word: the docstring explaining
        # why the flag was dropped legitimately names it.
        source = read_cli_source()
        self.assertNotIn('"--set-string"', source)
        self.assertIn("mkstemp", source)

    def test_generated_values_are_applied_after_user_overlays(self):
        # helm lets a later -f win, which is what now preserves ".env identity
        # beats a tuning overlay" -- the --set flags used to guarantee it.
        up_body = read_cli_source().split("    def up(self):", 1)[1]
        self.assertLess(up_body.index("for values in self.values_files"),
                        up_body.index("mkstemp"))


class SiteUrlResolutionTests(unittest.TestCase):
    """Without an ingress host the driver used to hand out the placeholder
    "https://<rel-web service>", and every HTTP check failed with HTTP 0."""

    def _driver(self, ingress_host="", discovered=None):
        driver = autohub.KubernetesDriver.__new__(autohub.KubernetesDriver)
        driver.rel = "autohub"
        driver.ingress_host = ingress_host
        driver._discovered_host = discovered
        driver._kubectl = ["kubectl", "-n", "autohub"]
        driver.dir = "."
        return driver

    def test_configured_host_wins(self):
        self.assertEqual("https://hub.example.org",
                         self._driver(ingress_host="hub.example.org").url())

    def test_host_is_discovered_from_the_cluster_ingress(self):
        driver = self._driver()
        with mock.patch.object(
                driver, "_run",
                return_value=subprocess.CompletedProcess([], 0, "found.example.org", "")):
            self.assertEqual("https://found.example.org", driver.url())

    def test_unresolvable_url_is_empty_not_a_placeholder(self):
        driver = self._driver(discovered="")
        self.assertEqual("", driver.url())
        self.assertNotIn("<", driver.url())

    def test_verify_reports_one_actionable_failure_not_a_wall_of_http_zero(self):
        driver = self._driver(discovered="")
        driver.name = "kubernetes"
        args = type("Args", (), {"scope": "all", "route": []})()
        result = autohub.Result("verify")
        with mock.patch.object(
                driver, "_run",
                return_value=subprocess.CompletedProcess([], 0, "", "")):
            with mock.patch.object(autohub, "http_get") as fetched:
                autohub.cmd_verify(driver, {}, args, result)
        fetched.assert_not_called()
        names = [c["name"] for c in result.checks]
        self.assertIn("site URL resolvable", names)
        self.assertFalse(result.checks[0]["ok"])
        self.assertIn("ingress_host", result.checks[0]["info"])


class AdminLoginTrustTests(unittest.TestCase):
    """The one probe that transmits a credential must not cross a connection
    that would accept any certificate."""

    def test_credentials_are_withheld_from_an_untrusted_connection(self):
        with mock.patch.object(autohub, "_host_https_trust",
                               return_value=(False, "self signed certificate")):
            with mock.patch.object(autohub.urllib.request, "build_opener") as opener:
                ok, detail = autohub._try_admin_login(
                    "https://localhost:8443", "admin", "hunter2")
        self.assertFalse(ok)
        self.assertIn("untrusted connection", detail)
        opener.assert_not_called()      # the password was never sent

    def test_a_trusted_connection_still_performs_the_login(self):
        with mock.patch.object(autohub, "_host_https_trust",
                               return_value=(True, "host trust store accepted")):
            with mock.patch.object(autohub.urllib.request, "build_opener") as opener:
                opener.return_value.open.side_effect = OSError("stop here")
                ok, detail = autohub._try_admin_login(
                    "https://localhost:8443", "admin", "hunter2")
        opener.assert_called_once()
        self.assertFalse(ok)
        self.assertNotIn("untrusted connection", detail)


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

    def test_example_manifest_parses_under_the_pinned_yaml_library(self):
        # symfony/yaml 3.4 (what hubzero-cms pins) rejects a flow mapping
        # spanning lines with "Malformed inline YAML string", and provisioning
        # then applies nothing at all.
        root = os.path.realpath(os.path.join(os.path.dirname(__file__), ".."))
        with open(os.path.join(root, "hub.yml.example"), encoding="utf-8") as f:
            manifest = f.read()
        self.assertEqual(multiline_flow_mappings(manifest), [])
        self.assertEqual(
            multiline_flow_mappings("a:\n  - { id: 1,\n      b: 2 }\n"), [2])

    def test_resource_type_default_no_longer_assumes_a_tools_type(self):
        root = os.path.realpath(os.path.join(os.path.dirname(__file__), ".."))
        with open(os.path.join(root, "docker", "bin", "provision.php"),
                  encoding="utf-8") as f:
            provisioner = f.read()
        # The old implicit default only ever resolved because the example
        # manifest defined a 'tools' type; it no longer does.
        self.assertNotIn("$resource['type'] : 'tools'", provisioner)
        self.assertIn("needs a 'type' naming one of the resource_types aliases",
                      provisioner)

    def test_params_merge_refuses_to_clobber_non_json_blobs(self):
        root = os.path.realpath(os.path.join(os.path.dirname(__file__), ".."))
        with open(os.path.join(root, "docker", "bin", "provision.php"),
                  encoding="utf-8") as f:
            provisioner = f.read()
        self.assertIn("function merged_params_json", provisioner)
        self.assertIn("refusing to replace them", provisioner)
        # Every merge site goes through the guard rather than array_merge on a
        # json_decode that returns null for legacy INI Registry data.
        self.assertNotIn("array_merge((array) json_decode", provisioner)
        self.assertEqual(provisioner.count("merged_params_json("), 5)

    def test_menu_lookup_matches_rows_in_any_language(self):
        root = os.path.realpath(os.path.join(os.path.dirname(__file__), ".."))
        with open(os.path.join(root, "docker", "bin", "provision.php"),
                  encoding="utf-8") as f:
            provisioner = f.read()
        # Filtering on language = '*' would miss a localized row and insert a
        # duplicate-alias sibling with an identical computed path.
        self.assertNotIn("AND `language` = '*'", provisioner)
        self.assertIn("ORDER BY (`language` = '*') DESC", provisioner)

    def test_knowledge_base_is_provisionable(self):
        root = os.path.realpath(os.path.join(os.path.dirname(__file__), ".."))
        with open(os.path.join(root, "docker", "bin", "provision.php"),
                  encoding="utf-8") as f:
            provisioner = f.read()
        self.assertIn("$manifest['kb']", provisioner)
        self.assertIn("INSERT INTO `#__kb_articles`", provisioner)
        # extension-scoped nested set, non-zero category, and a visible access
        # level -- the three ways a kb record silently fails to appear.
        self.assertIn("`extension` = 'com_kb'", provisioner)
        self.assertIn("positive|nonzero", provisioner)
        # access must default to a level that is actually visible; the column
        # defaults to 0, which matches no view level.
        self.assertRegex(
            provisioner,
            r"'access'\s*=>\s*isset\(\$spec\['access'\]\)[^\n]*:\s*1")
        # Identity is (category, alias): com_kb resolves an article by both.
        self.assertRegex(
            provisioner,
            r"FROM `#__kb_articles`\s*\n\s*WHERE `alias`[^\n]*\n\s*AND `category`")
        # The tree shift must be undoable; #__categories is MyISAM.
        self.assertIn("UPDATE `#__categories` SET `lft` = `lft` - 2", provisioner)
        # com_kb lists only categories whose parent is id 1.
        self.assertIn("$kbRoot = 1;", provisioner)

    def test_example_manifest_does_not_enable_the_navigation_hijack(self):
        # system/incomplete traps logged-in users on a profile form and
        # intercepts the logout route: the report is "I cannot log out".
        root = os.path.realpath(os.path.join(os.path.dirname(__file__), ".."))
        with open(os.path.join(root, "hub.yml.example"), encoding="utf-8") as f:
            manifest = f.read()
        enable = manifest.split("enable:", 1)[1].split("disable:", 1)[0]
        self.assertNotIn("system/incomplete", enable)

    def test_provisioner_guards_learned_from_field_deployment(self):
        root = os.path.realpath(os.path.join(os.path.dirname(__file__), ".."))
        with open(os.path.join(root, "docker", "bin", "provision.php"),
                  encoding="utf-8") as f:
            provisioner = f.read()
        with open(os.path.join(root, "hub.yml.example"), encoding="utf-8") as f:
            manifest = f.read()

        # Article aliases must not shadow native component routes.
        self.assertIn("would shadow the com_", provisioner)
        # Menu items and resource types accept params: (merged, not replaced).
        self.assertIn("$item['params']", provisioner)
        self.assertIn("$type['params']", provisioner)
        self.assertIn("plg_about", provisioner)
        # Menu lookups use the database's unique key (no menutype), and a hub
        # that loses its front page is a hard failure.
        self.assertIn("no published default (home) menu item", provisioner)
        # The middleware-backed Tools type renders blank pages on this
        # scaffold and must not be a recommended default.
        self.assertNotIn("type: Tools", manifest)

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


class InfrastructureConfigTests(unittest.TestCase):
    def test_autohub_config_strips_inline_comments_like_yaml(self):
        # autohub.yml.example itself uses inline comments; taking the raw
        # remainder of the line corrupted every kubectl --context invocation.
        with tempfile.TemporaryDirectory() as project:
            with open(os.path.join(project, "autohub.yml"), "w",
                      encoding="utf-8") as stream:
                stream.write(
                    "driver: kubernetes   # local default\n"
                    "kubernetes:          # only read for kubernetes\n"
                    "  context: geddes    # kube context from iconpcl.yaml\n"
                    "  release: 'iconpcl' # quoted\n"
                    "  schedule: \"0 3 * * *\"\n")
            cfg = autohub.read_autohub_config(project)
            self.assertEqual(cfg["driver"], "kubernetes")
            self.assertEqual(cfg["kubernetes"]["context"], "geddes")
            self.assertEqual(cfg["kubernetes"]["release"], "iconpcl")
            self.assertEqual(cfg["kubernetes"]["schedule"], "0 3 * * *")

    def test_kubernetes_driver_parses_values_files_list(self):
        driver = autohub.KubernetesDriver("/tmp/project", {}, {
            "driver": "kubernetes",
            "kubernetes": {"values_files": "deploy/a.yaml, deploy/b.yaml"},
        })
        self.assertEqual(driver.values_files,
                         ["deploy/a.yaml", "deploy/b.yaml"])

    def test_exec_probes_match_the_final_stdout_line(self):
        # Some clusters prepend a runtime banner to every kubectl exec
        # ("nvidia driver modules are not yet loaded, invoking runc
        # directly"); the verdict our probes echo is always last.
        banner = ("nvidia driver modules are not yet loaded, "
                  "invoking runc directly\nreachable\n")
        result = subprocess.CompletedProcess(["exec"], 0, banner, "")
        self.assertEqual(autohub._last_stdout_line(result), "reachable")

        class BannerDriver(FakeDriver):
            def url(self):
                return "https://localhost:8443"

            def exec(self, service, command, **_kwargs):
                return subprocess.CompletedProcess(command, 0, banner, "")

        with tempfile.TemporaryDirectory() as project:
            args = type("Args", (), {"scope": "mail", "route": []})()
            res = autohub.Result("verify")
            autohub.cmd_verify(BannerDriver(project), {}, args, res)
            self.assertFalse(res.verify_failed)

    def test_manifest_digest_survives_the_configmap_round_trip(self):
        # helm --set-file lands the manifest in a `hub.yml: |` block scalar,
        # which clips trailing newlines to one and normalizes CRLF. Hashing
        # raw bytes would call these manifests stale forever, and the remedy
        # (`up`) re-renders the identical ConfigMap -- an unclearable refusal.
        digests = set()
        with tempfile.TemporaryDirectory() as project:
            path = os.path.join(project, "hub.yml")
            for text in ("a: 1\nb: 2\n", "a: 1\r\nb: 2\r\n",
                         "a: 1\nb: 2", "a: 1\nb: 2\n\n\n"):
                with open(path, "w", encoding="utf-8", newline="") as stream:
                    stream.write(text)
                digests.add(autohub._normalized_manifest_digest(path))
        self.assertEqual(len(digests), 1)

    def test_provision_accepts_a_manifest_matching_after_normalization(self):
        with tempfile.TemporaryDirectory() as project:
            path = os.path.join(project, "hub.yml")
            with open(path, "w", encoding="utf-8", newline="") as stream:
                stream.write("articles: []")        # no trailing newline
            digest = autohub._normalized_manifest_digest(path)

            class MatchingDriver(FakeDriver):
                def exec(self, service, command, **_kwargs):
                    if command[0] == "sh" and "sha256sum" in command[2]:
                        return subprocess.CompletedProcess(
                            command, 0, digest + "  -\n", "")
                    return subprocess.CompletedProcess(
                        command, 0,
                        "[hub] provisioning complete: 3 applied, 0 failed\n", "")

            res = autohub.Result("provision")
            autohub.cmd_provision(MatchingDriver(project), {}, None, res)
            self.assertTrue(res.ok, res.details)
            self.assertFalse(res.verify_failed, res.checks)

    def test_component_result_tolerates_a_runtime_banner_before_json(self):
        class BannerComponentDriver(FakeDriver):
            def exec(self, service, command, **_kwargs):
                payload = json.dumps({"ok": True, "data": {"types": []},
                                      "checks": [], "errors": []})
                return subprocess.CompletedProcess(
                    command, 0,
                    "nvidia driver modules are not yet loaded, "
                    "invoking runc directly\n" + payload, "")

        args = type("Args", (), {"max_items": 5, "alias": None, "id": None})()
        res = autohub.Result("resource")
        payload = autohub._component_result(
            BannerComponentDriver("/nonexistent"), "resource", "describe",
            args, res)
        self.assertIsNotNone(payload)
        self.assertTrue(res.ok, res.details)

    def test_stale_manifest_guard_covers_inspect_and_export(self):
        class StaleComponentDriver(FakeDriver):
            def exec(self, service, command, **_kwargs):
                if command[0] == "sh" and "sha256sum" in command[2]:
                    return subprocess.CompletedProcess(
                        command, 0, "0" * 64 + "  -\n", "")
                raise AssertionError(
                    "must refuse before reading the mounted manifest")

        for operation in ("inspect", "export"):
            with tempfile.TemporaryDirectory() as project:
                with open(os.path.join(project, "hub.yml"), "w",
                          encoding="utf-8") as stream:
                    stream.write("resources: []\n")
                args = type("Args", (), {
                    "cmd": "resource", "sub": operation, "manifest": "hub.yml",
                    "max_items": 5, "alias": None, "id": None, "authorize": [],
                })()
                res = autohub.Result("resource")
                autohub.cmd_component(
                    StaleComponentDriver(project), {}, args, res)
                self.assertFalse(res.ok)
                self.assertIn("stale manifest", res.details[-1])

    def test_port_preflight_detects_a_listener_the_bind_probe_misses(self):
        # A daemon on 127.0.0.1 with SO_REUSEADDR: the wildcard bind probe now
        # succeeds alongside it, so only a connect settles whether publishing
        # that port would fail later with "port is already allocated".
        server = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        server.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        try:
            server.bind(("127.0.0.1", 0))
            # Room for several probes: each leaves a connection in the accept
            # queue that this test never accepts.
            server.listen(16)
            port = server.getsockname()[1]
            self.assertTrue(autohub._port_listening(port))
            self.assertFalse(autohub._port_available(port))
        finally:
            server.close()
        self.assertFalse(autohub._port_listening(port))

    def test_provision_refuses_a_stale_mounted_manifest(self):
        class StaleDriver(FakeDriver):
            def exec(self, service, command, **_kwargs):
                if command[0] == "sh" and "sha256sum" in command[2]:
                    return subprocess.CompletedProcess(
                        command, 0,
                        "0" * 64 + "  /etc/hubzero/hub.yml\n", "")
                raise AssertionError("provision must stop on a stale manifest")

        with tempfile.TemporaryDirectory() as project:
            with open(os.path.join(project, "hub.yml"), "w",
                      encoding="utf-8") as stream:
                stream.write("articles: []\n")
            res = autohub.Result("provision")
            autohub.cmd_provision(StaleDriver(project), {}, None, res)
            self.assertFalse(res.ok)
            self.assertIn("stale manifest", res.details[-1])


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

    def test_template_create_refuses_the_reserved_icon_prefix(self):
        # Core fontcons injects ::before content into *[class^="icon-"], so an
        # icon-* template alias (conventionally reused as the CSS class
        # prefix) inherits invisible pseudo-elements that break grids.
        for name in ("icon", "icon-pcl"):
            args = type("Args", (), {
                "sub": "create", "name": name, "branch": None,
                "token_env": "GITLAB_TOKEN", "force": False,
            })()
            result = autohub.Result("template")
            autohub.cmd_template(FakeDriver("/nonexistent"), {}, args, result)
            self.assertFalse(result.ok)
            self.assertIn("reserved class prefix", result.details[-1])

    def test_starter_layers_on_core_stylesheet(self):
        root = os.path.realpath(os.path.join(os.path.dirname(__file__), ".."))
        with open(os.path.join(root, "template-starter", "less", "site.less"),
                  encoding="utf-8") as f:
            less = f.read()
        self.assertIn('@import "../../../../core/assets/less/site.less";', less)
        # Core paints these grey/hatched; a template that never restyles them
        # looks broken on every com_kb/com_groups/com_support page.
        for selector in (".container-block", ".data-entry"):
            self.assertIn(selector, less)

    def test_starter_shell_emits_core_component_css_hooks(self):
        # Core scopes component CSS under `.com_<name>` and `#content`; a
        # shell without both makes those rules match nothing everywhere.
        root = os.path.realpath(os.path.join(os.path.dirname(__file__), ".."))
        for filename in ("index.php", "component.php"):
            with open(os.path.join(root, "template-starter", filename),
                      encoding="utf-8") as f:
                shell = f.read()
            self.assertIn("Request::getCmd('option'", shell, filename)
            self.assertIn('id="content"', shell, filename)
            self.assertIn("$esc($option)", shell, filename)

    def test_baked_template_install_reads_mounts_as_a_field(self):
        # A path used as a grep pattern silently fails to match when
        # /proc/self/mounts octal-escapes whitespace, and the fall-through
        # branch rm -rf's the developer's bind-mounted working copy.
        root = os.path.realpath(os.path.join(os.path.dirname(__file__), ".."))
        with open(os.path.join(root, "docker", "bin", "entrypoint.sh"),
                  encoding="utf-8") as f:
            entrypoint = f.read()
        self.assertNotIn('grep -qs " ${target} "', entrypoint)
        self.assertIn('mountpoint -q -- "$target"', entrypoint)
        self.assertIn("action=mounted", entrypoint)

    def test_starter_offers_a_tokened_sign_out(self):
        # Without this the user is stranded: "I can not logout from the site
        # side". The tokenless com_users route only reaches a confirmation.
        root = os.path.realpath(os.path.join(os.path.dirname(__file__), ".."))
        with open(os.path.join(root, "template-starter", "index.php"),
                  encoding="utf-8") as f:
            index = f.read()
        self.assertIn("User::isGuest()", index)
        self.assertIn("com_login&task=logout", index)
        self.assertIn("Session::getFormToken()", index)

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
