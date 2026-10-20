from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import threading
from urllib.parse import urlparse

import pytest

from rig.client import CartonClient, error_code, json_body
from rig.oracle import assert_inside, assert_unchanged, snapshot
from rig.scenario import (
    OUTSIDE_SENTINEL,
    PASSWORD,
    Scenario,
    _base_defines,
    _plant,
    _write_defines,
    fwd,
    password_hash,
    two_users,
)


class _DownloadOriginHandler(BaseHTTPRequestHandler):
    def log_message(self, fmt, *args):
        return

    def do_GET(self):
        parsed = urlparse(self.path)
        if parsed.path == "/hello.txt":
            body = b"remote-hello-bytes"
            self.send_response(200)
            self.send_header("Content-Type", "text/plain")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)
        elif parsed.path == "/attachment":
            body = b"from-content-disposition"
            self.send_response(200)
            self.send_header("Content-Type", "application/octet-stream")
            self.send_header("Content-Disposition", 'attachment; filename="report.txt"')
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)
        elif parsed.path == "/blocked-cd":
            body = b"<?php echo 1;"
            self.send_response(200)
            self.send_header("Content-Type", "application/octet-stream")
            self.send_header("Content-Disposition", 'attachment; filename="evil.php"')
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)
        elif parsed.path == "/empty":
            self.send_response(200)
            self.send_header("Content-Length", "0")
            self.end_headers()
        elif parsed.path == "/large":
            body = b"x" * 1024
            self.send_response(200)
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)
        elif parsed.path == "/race":
            # Simulate concurrent file creation while download body is in flight
            race_target = getattr(self.server, "race_target", None)
            if race_target is not None:
                race_target.write_bytes(b"created-concurrently")
            body = b"downloaded-during-race"
            self.send_response(200)
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)
        else:
            self.send_response(404)
            self.end_headers()


@pytest.fixture(scope="module")
def origin_server():
    server = ThreadingHTTPServer(("127.0.0.1", 0), _DownloadOriginHandler)
    server.race_target = None
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    host, port = server.server_address
    yield f"http://{host}:{port}", server
    server.shutdown()


def test_remote_download_disabled_by_default(stack, tmp_path_factory, tools, origin_server):
    origin_url, _ = origin_server
    work = tmp_path_factory.mktemp("rd-disabled")
    scenario = two_users(work, tools.php_exe)
    url = stack.activate(scenario)
    client = CartonClient(url)
    try:
        assert client.login("alice", PASSWORD).status_code == 200
        before = snapshot(scenario.alice_root)
        resp = client.api(
            "remote_download",
            method="POST",
            json_body={"path": "", "url": f"{origin_url}/hello.txt"},
        )
        assert resp.status_code == 403
        assert error_code(resp) == "remote_download.disabled"
        assert_unchanged(before, snapshot(scenario.alice_root), "alice")
    finally:
        client.close()


def test_remote_download_flows_conflict_overwrite_and_limits(stack, tmp_path_factory, tools, origin_server):
    origin_url, server = origin_server
    work = tmp_path_factory.mktemp("rd-enabled")
    alice = work / "alice"
    outside = work / "outside"
    _plant(alice, "existing.txt", "original-content")
    _plant(outside, "secret.txt", OUTSIDE_SENTINEL)
    hidden = alice / "hidden.txt"
    hidden.write_text("hidden-sentinel", encoding="utf-8")

    digest = password_hash(tools.php_exe)
    defines = _base_defines({
        "FILECARTON_UPLOAD_CHUNK_SIZE": 64,
        "FILECARTON_UPLOAD_MAX_FILE_SIZE": 256,
        "FILECARTON_IGNORE_REALPATH": [fwd(hidden.resolve())],
        "FILECARTON_STATIC_USER_LIST": [
            {
                "id": "alice",
                "password_hash": digest,
                "root_path": fwd(alice),
                "feature_remote_download": True,
            },
        ],
        "FILECARTON_AUTH_PROVIDERS": [
            {"class": r"\FileCarton\StaticPasswordAuth"},
        ],
    })
    php_file = work / "scenario.php"
    _write_defines(php_file, defines)
    scenario = Scenario(work=work, php_file=php_file, alice_root=alice, outside=outside)

    url = stack.activate(scenario)
    client = CartonClient(url)
    try:
        assert client.login("alice", PASSWORD).status_code == 200

        # 1. URL inference download
        r1 = client.api(
            "remote_download",
            method="POST",
            json_body={"path": "", "url": f"{origin_url}/hello.txt"},
        )
        assert r1.status_code == 200, r1.text
        d1 = json_body(r1)["data"]
        assert d1["completed"] is True
        assert d1["name"] == "hello.txt"
        assert (alice / "hello.txt").read_bytes() == b"remote-hello-bytes"

        # 2. Content-Disposition filename inference
        r2 = client.api(
            "remote_download",
            method="POST",
            json_body={"path": "", "url": f"{origin_url}/attachment"},
        )
        assert r2.status_code == 200, r2.text
        d2 = json_body(r2)["data"]
        assert d2["completed"] is True
        assert d2["name"] == "report.txt"
        assert (alice / "report.txt").read_bytes() == b"from-content-disposition"

        # 3. Conflict when overwrite=False (preserves original file)
        r_conflict = client.api(
            "remote_download",
            method="POST",
            json_body={"path": "", "url": f"{origin_url}/hello.txt", "name": "existing.txt", "overwrite": False},
        )
        assert r_conflict.status_code == 200, r_conflict.text
        d_conflict = json_body(r_conflict)["data"]
        assert d_conflict["completed"] is False
        assert d_conflict["conflict"] == "existing.txt"
        assert (alice / "existing.txt").read_text(encoding="utf-8") == "original-content"

        # 4. Overwrite when overwrite=True (atomically replaces file)
        r_over = client.api(
            "remote_download",
            method="POST",
            json_body={"path": "", "url": f"{origin_url}/hello.txt", "name": "existing.txt", "overwrite": True},
        )
        assert r_over.status_code == 200, r_over.text
        assert json_body(r_over)["data"]["completed"] is True
        assert (alice / "existing.txt").read_bytes() == b"remote-hello-bytes"

        # 5. Race condition: file created during download with overwrite=False must not be overwritten
        race_file = alice / "race.txt"
        server.race_target = race_file
        try:
            r_race = client.api(
                "remote_download",
                method="POST",
                json_body={"path": "", "url": f"{origin_url}/race", "name": "race.txt", "overwrite": False},
            )
            assert r_race.status_code == 200, r_race.text
            d_race = json_body(r_race)["data"]
            assert d_race["completed"] is False
            assert d_race["conflict"] == "race.txt"
            assert race_file.read_bytes() == b"created-concurrently"
        finally:
            server.race_target = None

        # 6. Empty 0-byte response creates empty file
        r_empty = client.api(
            "remote_download",
            method="POST",
            json_body={"path": "", "url": f"{origin_url}/empty", "name": "zero.txt"},
        )
        assert r_empty.status_code == 200, r_empty.text
        assert (alice / "zero.txt").is_file()
        assert (alice / "zero.txt").stat().st_size == 0

        # 7. Blocked filename (via custom name or Content-Disposition) is rejected
        r_blocked = client.api(
            "remote_download",
            method="POST",
            json_body={"path": "", "url": f"{origin_url}/blocked-cd"},
        )
        assert r_blocked.status_code == 403
        assert error_code(r_blocked) == "filename_blocked"
        assert not (alice / "evil.php").exists()

        # 8. Oversize file is rejected with 413 and leaves no temp file behind
        r_large = client.api(
            "remote_download",
            method="POST",
            json_body={"path": "", "url": f"{origin_url}/large", "name": "huge.bin"},
        )
        assert r_large.status_code == 413
        assert error_code(r_large) == "remote_download.too_large"
        assert not (alice / "huge.bin").exists()
        assert list(alice.glob("__fc_download_*.tmp")) == []

        # 9. Non-HTTP scheme is rejected
        r_scheme = client.api(
            "remote_download",
            method="POST",
            json_body={"path": "", "url": "file:///etc/passwd"},
        )
        assert r_scheme.status_code == 400
        assert error_code(r_scheme) == "remote_download.invalid_url"

        assert_inside(alice)
    finally:
        client.close()
