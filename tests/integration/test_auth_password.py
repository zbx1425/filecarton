import pytest

from rig.client import CartonClient, cookie_value, error_code, json_body, set_cookie_blob
from rig.scenario import PASSWORD, two_users

pytestmark = pytest.mark.usefixtures("stack")


@pytest.fixture(scope="module")
def url(stack, tmp_path_factory, tools):
    work = tmp_path_factory.mktemp("password")
    return stack.activate(two_users(work, tools.php_exe))


def test_page_exposes_stable_script_name_and_cookie_flags(url):
    client = CartonClient(url)
    response = client.get_page()
    assert response.status_code == 200, response.text
    assert client.csrf
    config_blob = response.text
    assert "/index.php" in config_blob
    blob = set_cookie_blob(response).lower()
    assert "httponly" in blob
    assert "samesite=lax" in blob
    assert "secure" not in blob
    client.close()


def test_caddy_does_not_let_the_client_spoof_https(url):
    # Caddy replaces X-Forwarded-Proto with the scheme it actually received.
    # FileCarton's own trust of that header is covered by CsrfHttpsTest.
    client = CartonClient(url)
    response = client.get_page(headers={"X-Forwarded-Proto": "https"})
    assert response.status_code == 200, response.text
    assert "secure" not in set_cookie_blob(response).lower()
    client.close()


def test_login_rotates_session_and_returns_user(url):
    client = CartonClient(url)
    page = client.get_page()
    before = cookie_value(page)
    assert before
    response = client.login("alice", PASSWORD)
    assert response.status_code == 200, response.text
    body = json_body(response)
    assert body["data"]["user"]["id"] == "alice"
    assert body["data"]["csrfToken"]
    after = cookie_value(response)
    assert after and after != before
    listed = client.api("list")
    assert listed.status_code == 200, listed.text
    names = [item["name"] for item in json_body(listed)["data"]["files"]]
    assert "hello.txt" in names
    client.close()


@pytest.mark.parametrize(
    "username,password",
    [
        ("alice", "nope"),
        ("nobody", "nope"),
        ("", "x"),
        ("alice", ""),
        ("a" * 201, "x"),
        ("alice", "b" * 1025),
    ],
)
def test_bad_credentials_are_indistinguishable(url, username, password):
    client = CartonClient(url)
    client.get_page()
    response = client.login(username, password)
    assert response.status_code == 401, response.text
    assert error_code(response) == "auth.invalid_credentials"
    assert "params" not in json_body(response)
    client.close()


def test_allowlisted_password_without_grant(url):
    client = CartonClient(url)
    client.get_page()
    response = client.login("carol", PASSWORD)
    assert response.status_code == 403, response.text
    body = json_body(response)
    assert body["error"] == "auth.allowlist"
    assert body["params"]["id"] == "carol"
    client.close()


def test_anonymous_list_requires_auth(url):
    client = CartonClient(url)
    response = client.api("list", csrf=False)
    assert response.status_code == 401
    assert error_code(response) == "auth.required"
    client.close()


def test_login_without_csrf_does_not_start_a_session_user(url):
    client = CartonClient(url)
    client.get_page()
    client.csrf = ""
    response = client.http.post(
        "/index.php",
        params={"fcapi": "auth_login"},
        json={"username": "alice", "password": PASSWORD},
    )
    assert response.status_code == 403
    assert error_code(response) == "auth.csrf"
    follow = client.api("list", csrf=False)
    assert follow.status_code == 401
    client.close()
