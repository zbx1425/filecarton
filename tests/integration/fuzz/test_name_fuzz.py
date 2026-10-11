import pytest
from hypothesis import given
from hypothesis import strategies as st

from rig.client import CartonClient, error_code
from rig.oracle import assert_inside, assert_unchanged, snapshot
from rig.scenario import PASSWORD, two_users

pytestmark = pytest.mark.fuzz


@pytest.fixture(scope="module")
def world(stack, tmp_path_factory, tools):
    work = tmp_path_factory.mktemp("fuzz-name")
    scenario = two_users(work, tools.php_exe)
    url = stack.activate(scenario)
    client = CartonClient(url)
    assert client.login("alice", PASSWORD).status_code == 200
    yield scenario, client
    client.close()


@given(name=st.text(min_size=0, max_size=40))
def test_create_does_not_escape(world, name):
    scenario, client = world
    outside = snapshot(scenario.outside)
    bob = snapshot(scenario.bob_root)
    response = client.api(
        "create",
        method="POST",
        json_body={"path": "", "name": name, "type": "file"},
    )
    assert response.status_code != 500, response.text
    assert error_code(response) != "server_error"
    assert_unchanged(outside, snapshot(scenario.outside), "outside")
    assert_unchanged(bob, snapshot(scenario.bob_root), "bob")
    assert_inside(scenario.alice_root)
