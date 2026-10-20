import pytest

from rig.client import CartonClient, error_code
from rig.oracle import assert_unchanged, snapshot
from rig.scenario import PASSWORD, must_readonly, two_users


def _login(url, username):
    client = CartonClient(url)
    response = client.login(username, PASSWORD)
    assert response.status_code == 200, response.text
    return client


def test_readonly_grant_blocks_post(stack, tmp_path_factory, tools):
    work = tmp_path_factory.mktemp("bob-ro")
    scenario = two_users(work, tools.php_exe)
    url = stack.activate(scenario)
    bob = _login(url, "bob")
    before = snapshot(scenario.bob_root)
    try:
        listed = bob.api("list", csrf=False)
        assert listed.status_code == 200, listed.text
        created = bob.api(
            "create",
            method="POST",
            json_body={"path": "", "name": "nope.txt", "type": "file"},
        )
        assert created.status_code == 403
        assert error_code(created) == "readonly"
        chmod_resp = bob.api(
            "chmod",
            method="POST",
            json_body={"path": "", "items": ["secret.txt"], "setMask": 0o644},
        )
        assert chmod_resp.status_code == 403
        assert error_code(chmod_resp) == "readonly"
        remote_dl_resp = bob.api(
            "remote_download",
            method="POST",
            json_body={"path": "", "url": "http://127.0.0.1:9/file.txt"},
        )
        assert remote_dl_resp.status_code == 403
        assert error_code(remote_dl_resp) == "readonly"
        assert_unchanged(before, snapshot(scenario.bob_root), "bob")
    finally:
        bob.close()


def test_must_readonly_overrides_writable_grant(stack, tmp_path_factory, tools):
    work = tmp_path_factory.mktemp("must-ro")
    scenario = must_readonly(work, tools.php_exe)
    url = stack.activate(scenario)
    alice = _login(url, "alice")
    before = snapshot(scenario.alice_root)
    try:
        created = alice.api(
            "create",
            method="POST",
            json_body={"path": "", "name": "nope.txt", "type": "file"},
        )
        assert created.status_code == 403
        assert error_code(created) == "readonly"
        remote_dl_resp = alice.api(
            "remote_download",
            method="POST",
            json_body={"path": "", "url": "http://127.0.0.1:9/file.txt"},
        )
        assert remote_dl_resp.status_code == 403
        assert error_code(remote_dl_resp) == "readonly"
        assert_unchanged(before, snapshot(scenario.alice_root), "alice")
    finally:
        alice.close()
