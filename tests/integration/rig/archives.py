"""Build small zip and tar archives whose entry names are hostile."""

from __future__ import annotations

import io
import tarfile
import zipfile
from pathlib import Path


def write_zip(path: Path, entries: dict[str, bytes]) -> None:
    with zipfile.ZipFile(path, "w") as archive:
        for name, payload in entries.items():
            info = zipfile.ZipInfo(filename=name)
            archive.writestr(info, payload)


def write_tar(path: Path, entries: dict[str, bytes]) -> None:
    with tarfile.open(path, "w") as archive:
        for name, payload in entries.items():
            info = tarfile.TarInfo(name=name)
            info.size = len(payload)
            archive.addfile(info, io.BytesIO(payload))
