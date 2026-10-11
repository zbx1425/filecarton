from pathlib import Path

import pytest

from rig.client import CartonClient, error_code, json_body
from rig.oracle import assert_no_secrets, assert_unchanged, snapshot
from rig.scenario import PASSWORD, two_users

NAMES = Path(__file__).resolve().parents[1] / "corpora" / "names.txt"


@pytest.fixture(scope="module")
def world(stack, tmp_path_factory, tools):
    work = tmp_path_factory.mktemp("names")
    scenario = two_users(work, tools.php_exe)
    url = stack.activate(scenario)
    client = CartonClient(url)
    assert client.login("alice", PASSWORD).status_code == 200
    yield url, scenario, client
    client.close()


def _names():
    return [raw for raw in NAMES.read_text(encoding="utf-8").splitlines() if raw and not raw.startswith("#")]


@pytest.mark.parametrize("name", _names())
def test_create_rejects_hostile_names(world, name):
    _url, scenario, client = world
    before = snapshot(scenario.alice_root)
    outside = snapshot(scenario.outside)
    response = client.api(
        "create",
        method="POST",
        json_body={"path": "", "name": name, "type": "file"},
    )
    assert response.status_code in (400, 403), response.text
    assert error_code(response) != "server_error"
    assert_unchanged(before, snapshot(scenario.alice_root), "alice")
    assert_unchanged(outside, snapshot(scenario.outside), "outside")
    assert_no_secrets(response.text, scenario.sentinels)


def test_existing_blocked_name_stays_readable(world):
    _url, scenario, client = world
    response = client.api("read", query={"path": "shell.php"}, csrf=False)
    assert response.status_code == 200, response.text
    assert "<?php echo 1;" in response.text
    listed = json_body(client.api("list", csrf=False))
    names = [item["name"] for item in listed["data"]["files"]]
    assert "shell.php" in names
    assert "hidden.txt" not in names


def test_ignored_file_is_hidden_and_not_deleted(world):
    _url, scenario, client = world
    hidden = scenario.alice_root / "hidden.txt"
    assert hidden.is_file()
    read = client.api("read", query={"path": "hidden.txt"}, csrf=False)
    assert read.status_code == 404
    removed = client.api("delete", method="POST", json_body={"path": "", "items": ["hidden.txt"]})
    assert removed.status_code == 200, removed.text
    assert hidden.is_file()
    renamed = client.api(
        "rename",
        method="POST",
        json_body={"path": "", "oldName": "hello.txt", "newName": "shell.php"},
    )
    assert renamed.status_code == 403
    assert error_code(renamed) == "filename_blocked"


