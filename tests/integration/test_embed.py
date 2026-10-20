import pytest

from rig.client import CartonClient, error_code, json_body
from rig.oracle import assert_no_secrets
from rig.scenario import OUTSIDE_SENTINEL, embed_direct, embed_state, must_embed, nologin, nologin_plus_password


def test_nologin_lists_without_a_cookie(stack, tmp_path_factory):
    work = tmp_path_factory.mktemp("nologin")
    scenario = nologin(work)
    url = stack.activate(scenario)
    client = CartonClient(url)
    try:
        response = client.api("list", csrf=False)
        assert response.status_code == 200, response.text
        names = [item["name"] for item in json_body(response)["data"]["files"]]
        assert "hello.txt" in names
        assert_no_secrets(response.text, scenario.sentinels)
    finally:
        client.close()


def test_nologin_cannot_combine_with_password(stack, tmp_path_factory, tools):
    work = tmp_path_factory.mktemp("combo")
    url = stack.activate(nologin_plus_password(work, tools.php_exe))
    client = CartonClient(url)
    try:
        page = client.get_page()
        assert page.status_code == 500, page.text
        assert "NoLoginAuth cannot be combined" in page.text
        api = client.api("list", csrf=False)
        assert api.status_code == 500
        assert error_code(api) == "auth.config"
        assert_no_secrets(api.text, ["password_hash"])
    finally:
        client.close()


def test_must_embed_refuses_direct_access(stack, tmp_path_factory):
    work = tmp_path_factory.mktemp("must-embed")
    scenario = must_embed(work)
    url = stack.activate(scenario)
    client = CartonClient(url)
    try:
        page = client.get_page()
        assert page.status_code == 403
        assert "require embedding" in page.text.lower() or "Self-Check" in page.text
        api = client.api("list", csrf=False)
        assert api.status_code == 403
        assert_no_secrets(page.text + api.text, [OUTSIDE_SENTINEL])
    finally:
        client.close()


def test_embed_serves_configured_root_and_disables_auth_api(stack, tmp_path_factory):
    work = tmp_path_factory.mktemp("embed")
    scenario = embed_direct(work)
    url = stack.activate(scenario)
    client = CartonClient(url)
    try:
        page = client.get_page()
        assert page.status_code == 200, page.text
        assert client.csrf
        listed = client.api("list", csrf=False)
        assert listed.status_code == 200, listed.text
        names = [item["name"] for item in json_body(listed)["data"]["files"]]
        assert "hello.txt" in names
        login = client.api(
            "auth_login",
            method="POST",
            json_body={"username": "alice", "password": "x"},
        )
        assert login.status_code == 404
        assert error_code(login) == "auth.disabled"
        assert_no_secrets(listed.text, scenario.sentinels)
    finally:
        client.close()


def test_embed_wrapper_rejects_bad_state_and_serves_known_repo(stack, tmp_path_factory):
    work = tmp_path_factory.mktemp("embed-state")
    scenario = embed_state(work)
    url = stack.activate(scenario)
    client = CartonClient(url)
    try:
        bad = client.http.get("/index.php", params={"state_repo": "../outside"})
        assert bad.status_code == 400
        assert_no_secrets(bad.text, [OUTSIDE_SENTINEL])
        missing = client.http.get("/index.php", params={"state_repo": "nope"})
        assert missing.status_code == 404
        page = client.get_page(params={"state_repo": "photos"})
        assert page.status_code == 200, page.text
        listed = client.api("list", query={"state_repo": "photos"}, csrf=False)
        assert listed.status_code == 200, listed.text
        names = [item["name"] for item in json_body(listed)["data"]["files"]]
        assert "pic.txt" in names
    finally:
        client.close()
