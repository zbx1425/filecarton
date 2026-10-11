import pytest
from hypothesis import given
from hypothesis import strategies as st

from rig.client import CartonClient, error_code
from rig.oracle import assert_no_secrets, assert_unchanged, snapshot
from rig.scenario import two_users

pytestmark = pytest.mark.fuzz

# Printable ASCII only. Arbitrary controls and NUL bytes reset php-cgi on
# Windows, and Hypothesis then sees a different socket error on replay.
_TEXT = st.text(
    alphabet=st.characters(min_codepoint=32, max_codepoint=126),
    max_size=40,
)


@pytest.fixture(scope="module")
def world(stack, tmp_path_factory, tools):
    work = tmp_path_factory.mktemp("fuzz-auth")
    scenario = two_users(work, tools.php_exe)
    url = stack.activate(scenario)
    yield url, scenario


@given(username=_TEXT, password=_TEXT, token=st.one_of(st.none(), _TEXT))
def test_login_attempts_do_not_open_the_root(world, username, password, token):
    url, scenario = world
    outside = snapshot(scenario.outside)
    client = CartonClient(url)
    try:
        client.get_page()
        if token is not None:
            client.csrf = token or "0"
        response = client.login(username, password)
        assert response.status_code != 500, response.text
        assert error_code(response) != "server_error"
        assert_no_secrets(response.text, scenario.sentinels + ["passwordHash"])
        if response.status_code != 200:
            listed = client.api("list", csrf=False)
            assert listed.status_code == 401
    finally:
        client.close()
        assert_unchanged(outside, snapshot(scenario.outside), "outside")
