import pytest
from hypothesis import given
from hypothesis import strategies as st

from rig.client import CartonClient, error_code
from rig.oracle import assert_inside, assert_no_secrets, assert_unchanged, snapshot
from rig.scenario import PASSWORD, two_users

pytestmark = pytest.mark.fuzz


@pytest.fixture(scope="module")
def world(stack, tmp_path_factory, tools):
    work = tmp_path_factory.mktemp("fuzz-path")
    scenario = two_users(work, tools.php_exe)
    url = stack.activate(scenario)
    client = CartonClient(url)
    assert client.login("alice", PASSWORD).status_code == 200
    yield scenario, client
    client.close()


@given(path=st.text(min_size=0, max_size=80))
def test_read_and_list_stay_in_jail(world, path):
    scenario, client = world
    outside = snapshot(scenario.outside)
    bob = snapshot(scenario.bob_root)
    for action in ("list", "read"):
        response = client.api(action, query={"path": path}, csrf=False)
        assert response.status_code != 500, response.text
        assert error_code(response) != "server_error"
        assert_no_secrets(response.text, scenario.sentinels)
    assert_unchanged(outside, snapshot(scenario.outside), "outside")
    assert_unchanged(bob, snapshot(scenario.bob_root), "bob")
    assert_inside(scenario.alice_root)
