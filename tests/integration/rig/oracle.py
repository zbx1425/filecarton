"""Filesystem and response oracles shared by the security tests."""

from __future__ import annotations

import hashlib
import os
from pathlib import Path


def snapshot(root: Path | None) -> dict[str, str]:
    if root is None or not root.exists():
        return {}
    out: dict[str, str] = {}
    for dirpath, dirnames, filenames in os.walk(root):
        base = Path(dirpath)
        for name in dirnames + filenames:
            path = base / name
            rel = path.relative_to(root).as_posix()
            try:
                if path.is_symlink():
                    out[rel] = "link:" + os.readlink(path)
                elif path.is_file():
                    out[rel] = hashlib.sha256(path.read_bytes()).hexdigest()
                elif path.is_dir():
                    out[rel] = "dir"
            except OSError:
                out[rel] = "unreadable"
    return out


def assert_unchanged(before: dict[str, str], after: dict[str, str], label: str) -> None:
    assert before == after, f"{label} changed\nbefore={before}\nafter={after}"


def assert_no_secrets(body: str, secrets: list[str]) -> None:
    for secret in secrets:
        assert secret not in body, f"response leaked {secret!r}"


def assert_inside(root: Path) -> None:
    """Every file under root, once symlinks are resolved, stays under root."""
    root_real = Path(os.path.realpath(root))
    root_key = str(root_real).replace("\\", "/").rstrip("/").lower()
    for dirpath, dirnames, filenames in os.walk(root, followlinks=False):
        for name in dirnames + filenames:
            path = Path(dirpath) / name
            try:
                real = Path(os.path.realpath(path))
            except OSError:
                continue
            key = str(real).replace("\\", "/").rstrip("/").lower()
            if key == root_key:
                continue
            assert key.startswith(root_key + "/"), f"{path} resolved to {real}, outside {root_real}"
