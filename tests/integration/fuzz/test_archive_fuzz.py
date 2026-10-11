import pytest
from hypothesis import given
from hypothesis import strategies as st

from rig.archives import write_zip
from rig.client import CartonClient, error_code
from rig.oracle import assert_inside, assert_unchanged, snapshot
from rig.scenario import PASSWORD, two_users

pytestmark = pytest.mark.fuzz

_ENTRY = st.text(
    alphabet=st.characters(blacklist_categories=["Cs"], blacklist_characters="\x00"),
    min_size=1,
    max_size=40,
)


@pytest.fixture(scope="module")
def world(stack, tmp_path_factory, tools):
    work = tmp_path_factory.mktemp("fuzz-zip")
    scenario = two_users(work, tools.php_exe)
    url = stack.activate(scenario)
    client = CartonClient(url)
    assert client.login("alice", PASSWORD).status_code == 200
    yield scenario, client
    client.close()


@given(entry=_ENTRY)
def test_zip_entry_cannot_leave_the_root(world, entry):
    scenario, client = world
    outside = snapshot(scenario.outside)
    bob = snapshot(scenario.bob_root)
    write_zip(scenario.alice_root / "fuzz.zip", {entry: b"fuzz-bytes"})
    response = client.api(
        "archive_extract",
        method="POST",
        json_body={"path": "fuzz.zip", "targetPath": ""},
    )
    assert response.status_code != 500, response.text
    assert error_code(response) != "server_error"
    assert_unchanged(outside, snapshot(scenario.outside), "outside")
    assert_unchanged(bob, snapshot(scenario.bob_root), "bob")
    assert_inside(scenario.alice_root)
