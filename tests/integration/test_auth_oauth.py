import json
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlencode, urlparse

import pytest

from rig.client import CartonClient, set_cookie_blob
from rig.oracle import assert_no_secrets
from rig.scenario import oauth


class _IdPHandler(BaseHTTPRequestHandler):
    def log_message(self, fmt, *args):
        return

    def do_GET(self):
        parsed = urlparse(self.path)
        if parsed.path != "/authorize":
            self.send_error(404)
            return
        query = parse_qs(parsed.query)
        redirect = query.get("redirect_uri", [""])[0]
        state = query.get("state", [""])[0]
        joiner = "&" if "?" in redirect else "?"
        location = redirect + joiner + urlencode({"code": "good", "state": state})
        self.send_response(302)
        self.send_header("Location", location)
        self.end_headers()

    def do_POST(self):
        if urlparse(self.path).path != "/token":
            self.send_error(404)
            return
        body = json.dumps({"access_token": "tok", "user_id": "42", "name": "Octo"}).encode()
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)


@pytest.fixture(scope="module")
def idp():
    server = ThreadingHTTPServer(("127.0.0.1", 0), _IdPHandler)
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    host, port = server.server_address
    yield f"http://{host}:{port}"
    server.shutdown()


@pytest.fixture(scope="module")
def oauth_url(stack, tmp_path_factory, tools, support, idp):
    work = tmp_path_factory.mktemp("oauth")
    scenario = oauth(work, tools.php_exe, support, idp)
    return stack.activate(scenario), scenario


def test_oauth_round_trip_then_replay_does_not_mint_a_second_login(oauth_url):
    url, scenario = oauth_url
    client = CartonClient(url)
    try:
        assert client.get_page().status_code == 200
        start = client.http.get("/index.php", params={"fcauth": "start", "plugin": "fake"})
        assert start.status_code == 302, start.text
        hop = client.get_absolute(start.headers["location"])
        assert hop.status_code == 302, hop.text
        callback = hop.headers["location"]
        done = client.get_absolute(callback)
        assert done.status_code == 302, done.text
        assert "fc_auth_error" not in done.headers.get("location", "")
        listed = client.api("list", csrf=False)
        assert listed.status_code == 200, listed.text
        assert_no_secrets(listed.text, scenario.sentinels)

        replay = client.get_absolute(callback)
        assert replay.status_code == 302, replay.text
        assert "fc_auth_error" in replay.headers.get("location", "")
    finally:
        client.close()


def test_bad_state_and_return_hash_do_not_open_a_redirect(oauth_url):
    url, _scenario = oauth_url
    client = CartonClient(url)
    try:
        assert client.get_page().status_code == 200
        start = client.http.get(
            "/index.php",
            params={
                "fcauth": "start",
                "plugin": "fake",
                "fc_return_hash": "http://evil.example/phish",
            },
        )
        assert start.status_code == 302
        bad = client.http.get(
            "/index.php",
            params={"fcauth": "callback", "plugin": "fake", "state": "fake." + ("ab" * 16)},
        )
        assert bad.status_code == 302, bad.text
        location = bad.headers.get("location", "")
        assert "evil.example" not in location
        assert "\r" not in location and "\n" not in location
        assert "fc_auth_error" in location
        listed = client.api("list", csrf=False)
        assert listed.status_code == 401
    finally:
        client.close()


def test_caddy_keeps_the_oauth_callback_on_http(oauth_url):
    # Same proxy behavior as the password cookie test: the client header
    # does not become the callback scheme.
    url, _scenario = oauth_url
    client = CartonClient(url)
    try:
        response = client.http.get(
            "/index.php",
            params={"fcauth": "start", "plugin": "fake"},
            headers={"X-Forwarded-Proto": "https"},
        )
        assert response.status_code == 302, response.text
        location = response.headers["location"]
        assert "redirect_uri=http%3A" in location
        assert "secure" not in set_cookie_blob(response).lower()
    finally:
        client.close()


