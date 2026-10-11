import pytest

from rig.client import CartonClient, error_code, json_body
from rig.oracle import assert_inside, assert_unchanged, snapshot
from rig.scenario import PASSWORD, two_users


@pytest.fixture(scope="module")
def world(stack, tmp_path_factory, tools):
    work = tmp_path_factory.mktemp("upload")
    scenario = two_users(work, tools.php_exe)
    url = stack.activate(scenario)
    client = CartonClient(url)
    assert client.login("alice", PASSWORD).status_code == 200
    yield scenario, client
    client.close()


def test_upload_rejects_blocked_name_and_parent_traversal(world):
    scenario, client = world
    outside = snapshot(scenario.outside)
    original = (scenario.alice_root / "shell.php").read_text(encoding="utf-8")
    blocked = client.api(
        "upload",
        method="POST",
        data={"path": ""},
        files={"files": ("shell.php", b"<?php echo 2;\n", "application/octet-stream")},
    )
    assert blocked.status_code == 200, blocked.text
    failed = json_body(blocked)["data"]["failed"]
    assert failed and failed[0]["error"] == "filename_blocked"
    assert (scenario.alice_root / "shell.php").read_text(encoding="utf-8") == original

    traversed = client.api(
        "upload",
        method="POST",
        data={"path": "..", "relativePaths[0]": "../../outside/pwn.txt"},
        files={"files": ("p.txt", b"pwn", "application/octet-stream")},
    )
    assert traversed.status_code in (400, 403), traversed.text
    assert error_code(traversed) != "server_error"
    assert_unchanged(outside, snapshot(scenario.outside), "outside")
    assert_inside(scenario.alice_root)


def test_chunk_upload_id_cannot_leave_the_temp_slot(world):
    scenario, client = world
    outside = snapshot(scenario.outside)
    response = client.api(
        "upload_chunk",
        method="POST",
        data={"uploadId": "../x", "chunkIndex": "0", "totalChunks": "1"},
        files={"chunk": ("c.bin", b"abc", "application/octet-stream")},
    )
    assert response.status_code == 400
    assert error_code(response) == "invalid_input"
    assert_unchanged(outside, snapshot(scenario.outside), "outside")
    assert_inside(scenario.alice_root)
