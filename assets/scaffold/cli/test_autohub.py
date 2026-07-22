import contextlib
import importlib.machinery
import importlib.util
import io
import json
import os
import stat
import subprocess
import tempfile
import unittest


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


if __name__ == "__main__":
    unittest.main()
