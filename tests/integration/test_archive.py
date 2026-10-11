import pytest

from rig.archives import write_tar, write_zip
from rig.client import CartonClient, error_code, json_body
from rig.oracle import assert_inside, assert_no_secrets, assert_unchanged, snapshot
from rig.scenario import OUTSIDE_SENTINEL, PASSWORD, two_users


@pytest.fixture(scope="module")
def world(stack, tmp_path_factory, tools):
    work = tmp_path_factory.mktemp("archive")
    scenario = two_users(work, tools.php_exe)
    url = stack.activate(scenario)
    client = CartonClient(url)
    assert client.login("alice", PASSWORD).status_code == 200
    yield scenario, client
    client.close()


def _extract(client, name: str):
    return client.api(
        "archive_extract",
        method="POST",
        json_body={"path": name, "targetPath": ""},
    )


def test_zip_and_tar_entries_stay_inside_the_root(world):
    scenario, client = world
    outside = snapshot(scenario.outside)
    hostile = {
        "../escaped.txt": OUTSIDE_SENTINEL.encode(),
        "ok.txt": b"inside",
        "shell.php": b"<?php echo 9;\n",
        "__MACOSX/._ok.txt": b"junk",
        "nested/shell.php": b"<?php echo 8;\n",
        "C:/outside/secret.txt": b"abs",
    }
    write_zip(scenario.alice_root / "slip.zip", hostile)
    write_tar(scenario.alice_root / "slip.tar", hostile)

    for name in ("slip.zip", "slip.tar"):
        response = _extract(client, name)
        assert response.status_code == 200, response.text
        body = json_body(response)
        reasons = {item["path"]: item["reason"] for item in body["data"]["failed"]}
        assert reasons.get("shell.php") == "blocked.name"
        assert reasons.get("nested/shell.php") == "blocked.name"
        assert_no_secrets(response.text, [OUTSIDE_SENTINEL])
        assert not (scenario.work / "escaped.txt").exists()
        assert_unchanged(outside, snapshot(scenario.outside), "outside")
        assert_inside(scenario.alice_root)
        assert not (scenario.alice_root / "shell.php").read_text(encoding="utf-8").startswith("<?php echo 9")


def test_svg_raw_is_sandboxed(world):
    scenario, client = world
    (scenario.alice_root / "pic.svg").write_text("<svg xmlns='http://www.w3.org/2000/svg'></svg>", encoding="utf-8")
    response = client.api("raw", query={"path": "pic.svg"}, csrf=False)
    assert response.status_code == 200, response.text[:200]
    assert response.headers.get("content-security-policy") == "sandbox"
    assert response.headers.get("x-content-type-options") == "nosniff"
    assert error_code(response) != "server_error"
