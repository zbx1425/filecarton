import os
from pathlib import Path

import pytest

from rig.client import CartonClient, error_code
from rig.oracle import assert_inside, assert_no_secrets, assert_unchanged, snapshot
from rig.scenario import BOB_SENTINEL, OUTSIDE_SENTINEL, PASSWORD, two_users

CORPUS = Path(__file__).resolve().parents[1] / "corpora" / "paths.txt"


@pytest.fixture(scope="module")
def world(stack, tmp_path_factory, tools):
    work = tmp_path_factory.mktemp("isolation")
    scenario = two_users(work, tools.php_exe)
    url = stack.activate(scenario)
    return url, scenario


def _alice(url):
    client = CartonClient(url)
    assert client.login("alice", PASSWORD).status_code == 200
    return client


def _paths():
    rows = []
    for raw in CORPUS.read_text(encoding="utf-8").splitlines():
        if not raw or raw.startswith("#"):
            continue
        rows.append(raw)
    rows.append("hello.txt\x00.php")
    return rows


@pytest.mark.parametrize("path", _paths())
@pytest.mark.parametrize("action", ["list", "read", "raw", "download"])
def test_path_corpus_stays_inside_alice(world, path, action):
    url, scenario = world
    client = _alice(url)
    outside = snapshot(scenario.outside)
    bob = snapshot(scenario.bob_root)
    try:
        response = client.api(action, query={"path": path}, csrf=False)
        assert response.status_code != 500, response.text
        assert error_code(response) != "server_error"
        assert_no_secrets(response.text, [OUTSIDE_SENTINEL, BOB_SENTINEL])
        assert_unchanged(outside, snapshot(scenario.outside), "outside")
        assert_unchanged(bob, snapshot(scenario.bob_root), "bob")
        assert_inside(scenario.alice_root)
    finally:
        client.close()


def test_absolute_outside_path_and_sibling_symlink(world):
    url, scenario = world
    client = _alice(url)
    outside_file = scenario.outside / "secret.txt"
    outside = snapshot(scenario.outside)
    bob = snapshot(scenario.bob_root)
    try:
        response = client.api("read", query={"path": str(outside_file)}, csrf=False)
        assert response.status_code != 200 or OUTSIDE_SENTINEL not in response.text
        assert_no_secrets(response.text, [OUTSIDE_SENTINEL, BOB_SENTINEL])
        link = scenario.alice_root / "leak.txt"
        try:
            if link.exists() or link.is_symlink():
                link.unlink()
            os.symlink(outside_file, link)
        except OSError as exc:
            pytest.skip(f"symlink not permitted: {exc}")
        followed = client.api("read", query={"path": "leak.txt"}, csrf=False)
        assert_no_secrets(followed.text, [OUTSIDE_SENTINEL])
        assert followed.status_code != 200
        assert_unchanged(outside, snapshot(scenario.outside), "outside")
        assert_unchanged(bob, snapshot(scenario.bob_root), "bob")
    finally:
        client.close()
