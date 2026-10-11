"""Load tool paths for the integration rig.

Lookup order for each tool: tests/.env.local, a sibling of another configured
binary, then PATH. The PHP application does not read this file.
"""

from __future__ import annotations

import os
import shutil
from dataclasses import dataclass
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]  # tests/
ENV_FILE = ROOT / ".env.local"
EXAMPLE = ROOT / ".env.example"


def _parse_env(path: Path) -> dict[str, str]:
    if not path.is_file():
        return {}
    out: dict[str, str] = {}
    for raw in path.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        out[key.strip()] = value.strip().strip('"').strip("'")
    return out


def _which(name: str) -> str | None:
    found = shutil.which(name)
    return found if found else None


@dataclass
class Tools:
    php_exe: str
    php_cgi: str
    caddy: str
    php_ini: str
    workers: int
    bind: str

    @property
    def ok(self) -> bool:
        return bool(self.php_exe and self.php_cgi and self.caddy)


def load_tools() -> tuple[Tools | None, str]:
    data = _parse_env(ENV_FILE)
    missing: list[str] = []

    php_exe = data.get("PHP_EXE") or _which("php") or _which("php.exe")
    if php_exe and not Path(php_exe).is_file():
        php_exe = None
    if not php_exe:
        missing.append("PHP_EXE")

    php_cgi = data.get("PHP_CGI") or ""
    if not php_cgi and php_exe:
        sibling = Path(php_exe).with_name("php-cgi.exe")
        if sibling.is_file():
            php_cgi = str(sibling)
    if not php_cgi:
        php_cgi = _which("php-cgi") or _which("php-cgi.exe") or ""
    if not php_cgi or not Path(php_cgi).is_file():
        missing.append("PHP_CGI")
        php_cgi = ""

    caddy = data.get("CADDY") or _which("caddy") or _which("caddy.exe") or ""
    if not caddy or not Path(caddy).is_file():
        missing.append("CADDY")
        caddy = ""

    if missing:
        return None, (
            "Missing "
            + ", ".join(missing)
            + f". Copy {EXAMPLE.name} to {ENV_FILE} and set absolute paths."
        )

    workers = int(data.get("FC_WORKERS") or "4")
    if workers < 1:
        workers = 1
    bind = data.get("FC_BIND") or "127.0.0.1"
    return Tools(
        php_exe=str(Path(php_exe)),
        php_cgi=str(Path(php_cgi)),
        caddy=str(Path(caddy)),
        php_ini=data.get("PHP_INI") or "",
        workers=workers,
        bind=bind,
    ), ""
