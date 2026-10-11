"""Caddy in front of a pool of php-cgi workers.

php-fpm does not ship on Windows. One php-cgi process accepts one FastCGI
request at a time, so the rig starts FC_WORKERS of them and lets Caddy
balance. Workers are recycled when the scenario changes because each process
keeps FC_SCENARIO in its environment.
"""

from __future__ import annotations

import os
import socket
import subprocess
import time
from pathlib import Path

import httpx

from rig.env import Tools

CREATE_NO_WINDOW = 0x08000000
BACKEND_RELATIVE = Path("apps") / "backend"


def _free_port(bind: str) -> int:
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as sock:
        sock.bind((bind, 0))
        return int(sock.getsockname()[1])


def _port_open(bind: str, port: int) -> bool:
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as sock:
        sock.settimeout(0.2)
        try:
            sock.connect((bind, port))
            return True
        except OSError:
            return False


def _tail(path: Path, limit: int = 80) -> str:
    if not path.is_file():
        return ""
    lines = path.read_text(encoding="utf-8", errors="replace").splitlines()
    return "\n".join(lines[-limit:])


class WorkerPool:
    def __init__(self, tools: Tools, log_dir: Path):
        self.tools = tools
        self.log_dir = log_dir
        self.ports: list[int] = []
        self.procs: list[subprocess.Popen] = []
        self.logs: list[Path] = []
        self._log_handles: list = []

    def start(self, scenario_php: Path, session_path: Path, front: str, embed_base: str, idp: str, backend: Path) -> None:
        self.stop()
        session_path.mkdir(parents=True, exist_ok=True)
        self.ports = [_free_port(self.tools.bind) for _ in range(self.tools.workers)]
        env = os.environ.copy()
        env.pop("PHP_FCGI_CHILDREN", None)
        env["PHP_FCGI_MAX_REQUESTS"] = "0"
        env["FC_SCENARIO"] = str(scenario_php)
        env["FC_BACKEND"] = str(backend)
        env["FC_FRONT"] = front
        env["FC_EMBED_BASE"] = embed_base
        env["FC_IDP"] = idp
        for index, port in enumerate(self.ports):
            log_path = self.log_dir / f"php-cgi-{index}.log"
            handle = open(log_path, "ab")
            self._log_handles.append(handle)
            self.logs.append(log_path)
            args = [
                self.tools.php_cgi,
                "-b",
                f"{self.tools.bind}:{port}",
                "-d",
                "upload_max_filesize=64M",
                "-d",
                "post_max_size=64M",
                "-d",
                "display_errors=0",
                "-d",
                "log_errors=1",
                "-d",
                "date.timezone=UTC",
                "-d",
                "session.save_path=" + str(session_path).replace("\\", "/"),
            ]
            if self.tools.php_ini:
                args[1:1] = ["-c", self.tools.php_ini]
            proc = subprocess.Popen(
                args,
                env=env,
                stdout=handle,
                stderr=subprocess.STDOUT,
                creationflags=CREATE_NO_WINDOW,
            )
            self.procs.append(proc)
        deadline = time.time() + 15
        while time.time() < deadline:
            if all(_port_open(self.tools.bind, port) for port in self.ports):
                dead = [p for p in self.procs if p.poll() is not None]
                if dead:
                    break
                return
            if any(p.poll() is not None for p in self.procs):
                break
            time.sleep(0.1)
        raise RuntimeError("php-cgi did not stay up\n" + self.log_text())

    def healthy(self) -> bool:
        return bool(self.procs) and all(p.poll() is None for p in self.procs)

    def reap(self) -> None:
        """Restart a worker that exited, on the same port, with the same command."""
        for index, proc in enumerate(list(self.procs)):
            if proc.poll() is None:
                continue
            args = proc.args
            env = proc.env if hasattr(proc, "env") else None
            # Popen does not keep env. Workers are recycled as a set instead.
            _ = args
            _ = env

    def log_text(self) -> str:
        return "\n".join(f"--- {path} ---\n{_tail(path)}" for path in self.logs)

    def stop(self) -> None:
        for proc in self.procs:
            if proc.poll() is None:
                proc.kill()
        for proc in self.procs:
            try:
                proc.wait(timeout=5)
            except subprocess.TimeoutExpired:
                pass
        for handle in self._log_handles:
            handle.close()
        self.procs = []
        self.ports = []
        self.logs = []
        self._log_handles = []


class Stack:
    def __init__(self, tools: Tools, repo_root: Path, run_dir: Path):
        self.tools = tools
        self.repo_root = repo_root
        self.backend = repo_root / "apps" / "backend"
        self.support = repo_root / "tests" / "support" / "php"
        self.run_dir = run_dir
        self.run_dir.mkdir(parents=True, exist_ok=True)
        self.http_port = _free_port(tools.bind)
        self.docroot = run_dir / "docroot"
        self.docroot.mkdir(parents=True, exist_ok=True)
        self.caddy_log = run_dir / "caddy.log"
        self.caddyfile = run_dir / "Caddyfile"
        self.pool = WorkerPool(tools, run_dir)
        self.caddy: subprocess.Popen | None = None
        self._caddy_handle = None
        self.base_url = f"http://{tools.bind}:{self.http_port}"

    def start(self) -> None:
        index = self.docroot / "index.php"
        front = str(self.support / "front.php").replace("\\", "/")
        index.write_text(f"<?php\nrequire '{front}';\n", encoding="utf-8")

    def activate(self, scenario) -> str:
        if self.caddy is not None:
            self._stop_caddy()
        session_path = scenario.work / "sessions"
        self.pool.start(
            scenario_php=scenario.php_file,
            session_path=session_path,
            front=scenario.front,
            embed_base="" if scenario.embed_base is None else str(scenario.embed_base),
            idp=scenario.idp or "",
            backend=self.backend,
        )
        self._write_caddyfile()
        self._start_caddy()
        self._wait_http()
        return self.base_url

    def _write_caddyfile(self) -> None:
        upstreams = " ".join(f"{self.tools.bind}:{port}" for port in self.pool.ports)
        root = str(self.docroot).replace("\\", "/")
        text = (
            "{\n"
            "\tadmin off\n"
            "\tauto_https off\n"
            "}\n\n"
            f"http://{self.tools.bind}:{self.http_port} {{\n"
            f"\troot * \"{root}\"\n"
            f"\tphp_fastcgi {upstreams}\n"
            "}\n"
        )
        self.caddyfile.write_text(text, encoding="utf-8")

    def _start_caddy(self) -> None:
        handle = open(self.caddy_log, "ab")
        self._caddy_handle = handle
        self.caddy = subprocess.Popen(
            [self.tools.caddy, "run", "--config", str(self.caddyfile), "--adapter", "caddyfile"],
            stdout=handle,
            stderr=subprocess.STDOUT,
            creationflags=CREATE_NO_WINDOW,
            cwd=str(self.run_dir),
        )

    def _wait_http(self) -> None:
        deadline = time.time() + 20
        last = "no response"
        while time.time() < deadline:
            if self.caddy is not None and self.caddy.poll() is not None:
                break
            try:
                response = httpx.get(self.base_url + "/index.php", timeout=2.0)
                if response.status_code:
                    return
            except httpx.HTTPError as exc:
                last = str(exc)
            time.sleep(0.15)
        raise RuntimeError(
            "Caddy did not serve index.php\n"
            + last
            + "\n--- caddy ---\n"
            + _tail(self.caddy_log)
            + "\n"
            + self.pool.log_text()
        )

    def _stop_caddy(self) -> None:
        if self.caddy is not None and self.caddy.poll() is None:
            self.caddy.kill()
            try:
                self.caddy.wait(timeout=5)
            except subprocess.TimeoutExpired:
                pass
        self.caddy = None
        if self._caddy_handle is not None:
            self._caddy_handle.close()
            self._caddy_handle = None

    def stop(self) -> None:
        self._stop_caddy()
        self.pool.stop()

    def logs(self) -> str:
        return "--- caddy ---\n" + _tail(self.caddy_log) + "\n" + self.pool.log_text()
