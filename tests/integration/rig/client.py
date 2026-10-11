"""HTTP client that knows FileCarton's cookie and CSRF handshake."""

from __future__ import annotations

import json
from typing import Any
from urllib.parse import urljoin

import httpx

COOKIE = "FILECARTON"


def page_config(html: str) -> dict | None:
    needle = "window.__FILECARTON__"
    start = html.find(needle)
    if start < 0:
        return None
    brace = html.find("{", start)
    if brace < 0:
        return None
    try:
        obj, _ = json.JSONDecoder().raw_decode(html[brace:])
    except json.JSONDecodeError:
        return None
    return obj if isinstance(obj, dict) else None


class CartonClient:
    def __init__(self, base_url: str):
        self.base_url = base_url.rstrip("/")
        self.http = httpx.Client(base_url=self.base_url, timeout=30.0, follow_redirects=False)
        self.csrf: str | None = None

    def close(self) -> None:
        self.http.close()

    def session_id(self) -> str | None:
        for cookie in self.http.cookies.jar:
            if cookie.name == COOKIE:
                return cookie.value
        return None

    def get_page(self, headers: dict | None = None, params: dict | None = None) -> httpx.Response:
        response = self.http.get("/index.php", headers=headers, params=params)
        config = page_config(response.text)
        if config and isinstance(config.get("csrfToken"), str):
            self.csrf = config["csrfToken"]
        return response

    def login(self, username: str, password: str) -> httpx.Response:
        if not self.csrf:
            self.get_page()
        response = self.api(
            "auth_login",
            method="POST",
            json_body={"username": username, "password": password},
        )
        if response.status_code == 200:
            try:
                body = response.json()
            except json.JSONDecodeError:
                body = {}
            token = ((body.get("data") or {}) if isinstance(body, dict) else {}).get("csrfToken")
            if isinstance(token, str):
                self.csrf = token
        return response

    def api(
        self,
        action: str,
        method: str = "GET",
        json_body: dict | None = None,
        data: dict | None = None,
        files: Any = None,
        query: dict | None = None,
        headers: dict | None = None,
        csrf: bool = True,
    ) -> httpx.Response:
        params = {"fcapi": action}
        if query:
            params.update(query)
        hdrs = dict(headers or {})
        if method.upper() != "GET" and csrf:
            if not self.csrf:
                raise RuntimeError("CSRF token missing; call get_page() or login() first")
            hdrs["X-CSRF-Token"] = self.csrf
        kwargs: dict[str, Any] = {"params": params, "headers": hdrs}
        if json_body is not None:
            kwargs["json"] = json_body
        if data is not None:
            kwargs["data"] = data
        if files is not None:
            kwargs["files"] = files
        return self.http.request(method, "/index.php", **kwargs)

    def get_absolute(self, url: str, headers: dict | None = None) -> httpx.Response:
        # Absolute callback URLs still need this client's cookie jar.
        return self.http.get(url, headers=headers)

    def adopt_cookie(self, other: "CartonClient") -> None:
        for cookie in other.http.cookies.jar:
            self.http.cookies.set(cookie.name, cookie.value, domain=cookie.domain, path=cookie.path or "/")


def json_body(response: httpx.Response) -> dict:
    try:
        body = response.json()
    except json.JSONDecodeError:
        return {}
    return body if isinstance(body, dict) else {}


def error_code(response: httpx.Response) -> str | None:
    body = json_body(response)
    code = body.get("error")
    return code if isinstance(code, str) else None


def absolute(base_url: str, location: str) -> str:
    return urljoin(base_url + "/", location)


def cookie_value(response: httpx.Response, name: str = COOKIE) -> str | None:
    prefix = name.lower() + "="
    for header in response.headers.get_list("set-cookie"):
        if header.lower().startswith(prefix):
            return header.split(";", 1)[0].split("=", 1)[1]
    return None


def set_cookie_blob(response: httpx.Response) -> str:
    return "\n".join(response.headers.get_list("set-cookie"))
