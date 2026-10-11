import pytest

from rig.client import CartonClient, cookie_value, error_code
from rig.oracle import assert_unchanged, snapshot
from rig.scenario import PASSWORD, two_users

POST_ACTIONS = [
    "write",
    "create",
    "delete",
    "rename",
    "paste",
    "upload",
    "upload_chunk",
    "upload_complete",
    "archive_create",
    "archive_extract",
    "check_upload_conflicts",
    "auth_login",
    "auth_logout",
]


@pytest.fixture(scope="module")
def world(stack, tmp_path_factory, tools):
    work = tmp_path_factory.mktemp("csrf")
    scenario = two_users(work, tools.php_exe)
    url = stack.activate(scenario)
    return url, scenario


def _alice(url):
    client = CartonClient(url)
    response = client.login("alice", PASSWORD)
    assert response.status_code == 200, response.text
    return client


def test_mutating_verbs_reject_get(world):
    url, _scenario = world
    client = _alice(url)
    try:
        for action in POST_ACTIONS:
            if action in ("auth_login", "auth_logout"):
                continue
            response = client.api(action, method="GET", csrf=False)
            assert response.status_code == 405, action
            assert error_code(response) == "method_not_allowed"
    finally:
        client.close()


def test_post_without_csrf_does_not_write(world):
    url, scenario = world
    client = _alice(url)
    before = snapshot(scenario.alice_root)
    try:
        client.csrf = "0" * 64
        response = client.api(
            "create",
            method="POST",
            json_body={"path": "", "name": "nope.txt", "type": "file"},
        )
        assert response.status_code == 403
        assert error_code(response) == "auth.csrf"
        assert_unchanged(before, snapshot(scenario.alice_root), "alice")
    finally:
        client.close()


def test_other_sessions_csrf_is_rejected(world):
    url, scenario = world
    alice = _alice(url)
    bob = _alice(url)
    bob.login("bob", PASSWORD)
    before = snapshot(scenario.alice_root)
    try:
        stolen = alice.csrf
        alice.csrf = bob.csrf
        response = alice.api(
            "create",
            method="POST",
            json_body={"path": "", "name": "stolen.txt", "type": "file"},
        )
        assert response.status_code == 403
        assert error_code(response) == "auth.csrf"
        assert_unchanged(before, snapshot(scenario.alice_root), "alice")
        alice.csrf = stolen
    finally:
        alice.close()
        bob.close()


def test_logout_invalidates_old_cookie_and_old_csrf(world):
    url, scenario = world
    client = _alice(url)
    old_sid = client.session_id()
    old_csrf = client.csrf
    before = snapshot(scenario.alice_root)
    try:
        response = client.api("auth_logout", method="POST")
        assert response.status_code == 200, response.text
        new_sid = cookie_value(response) or client.session_id()
        assert new_sid and new_sid != old_sid

        # Logout drops the identity before CSRF is checked, so the new
        # anonymous session answers 401. The rotated token is still returned.
        assert response.json()["data"]["csrfToken"] != old_csrf
        client.csrf = old_csrf
        rejected = client.api(
            "create",
            method="POST",
            json_body={"path": "", "name": "after-logout.txt", "type": "file"},
        )
        assert rejected.status_code == 401
        assert error_code(rejected) == "auth.required"
        assert_unchanged(before, snapshot(scenario.alice_root), "alice")

        stale = CartonClient(url)
        stale.http.cookies.set("FILECARTON", old_sid, path="/")
        listed = stale.api("list", csrf=False)
        assert listed.status_code == 401
        stale.close()
    finally:
        client.close()
