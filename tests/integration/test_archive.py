import pytest

from rig.archives import write_tar, write_targz, write_zip
from rig.client import CartonClient, error_code, json_body, page_config
from rig.oracle import assert_inside, assert_no_secrets, assert_unchanged, snapshot
from rig.scenario import OUTSIDE_SENTINEL, PASSWORD, Scenario, _base_defines, _plant, _write_defines, fwd, password_hash, two_users


@pytest.fixture(scope="module")
def world(stack, tmp_path_factory, tools):
    work = tmp_path_factory.mktemp("archive")
    scenario = two_users(work, tools.php_exe)
    url = stack.activate(scenario)
    client = CartonClient(url)
    assert client.login("alice", PASSWORD).status_code == 200
    yield scenario, client
    client.close()


def _extract(client, name: str):
    return client.api(
        "archive_extract",
        method="POST",
        json_body={"path": name, "targetPath": ""},
    )


def test_zip_and_tar_entries_stay_inside_the_root(world):
    scenario, client = world
    outside = snapshot(scenario.outside)
    hostile = {
        "../escaped.txt": OUTSIDE_SENTINEL.encode(),
        "ok.txt": b"inside",
        "shell.php": b"<?php echo 9;\n",
        "__MACOSX/._ok.txt": b"junk",
        "nested/shell.php": b"<?php echo 8;\n",
        "C:/outside/secret.txt": b"abs",
    }
    write_zip(scenario.alice_root / "slip.zip", hostile)
    write_tar(scenario.alice_root / "slip.tar", hostile)

    for name in ("slip.zip", "slip.tar"):
        response = _extract(client, name)
        assert response.status_code == 200, response.text
        body = json_body(response)
        reasons = {item["path"]: item["reason"] for item in body["data"]["failed"]}
        assert reasons.get("shell.php") == "blocked.name"
        assert reasons.get("nested/shell.php") == "blocked.name"
        assert_no_secrets(response.text, [OUTSIDE_SENTINEL])
        assert not (scenario.work / "escaped.txt").exists()
        assert_unchanged(outside, snapshot(scenario.outside), "outside")
        assert_inside(scenario.alice_root)
        assert not (scenario.alice_root / "shell.php").read_text(encoding="utf-8").startswith("<?php echo 9")


def test_svg_raw_is_sandboxed(world):
    scenario, client = world
    (scenario.alice_root / "pic.svg").write_text("<svg xmlns='http://www.w3.org/2000/svg'></svg>", encoding="utf-8")
    response = client.api("raw", query={"path": "pic.svg"}, csrf=False)
    assert response.status_code == 200, response.text[:200]
    assert response.headers.get("content-security-policy") == "sandbox"
    assert response.headers.get("x-content-type-options") == "nosniff"
    assert error_code(response) != "server_error"


def test_video_raw_range_and_etag_isolation(world):
    scenario, client = world
    video_bytes = b"0123456789abcdef"
    (scenario.alice_root / "clip.mp4").write_bytes(video_bytes)
    (scenario.bob_root / "clip.mp4").write_bytes(video_bytes)

    full = client.api("raw", query={"path": "clip.mp4"}, csrf=False)
    assert full.status_code == 200
    assert full.headers.get("content-type") == "video/mp4"
    assert full.headers.get("accept-ranges") == "bytes"
    assert full.headers.get("cache-control") == "private, no-cache, must-revalidate"
    assert "cookie" in (full.headers.get("vary") or "").lower()
    alice_etag = full.headers.get("etag")
    assert alice_etag
    assert full.content == video_bytes

    not_mod = client.api("raw", query={"path": "clip.mp4"}, headers={"If-None-Match": alice_etag}, csrf=False)
    assert not_mod.status_code == 304

    part = client.api(
        "raw",
        query={"path": "clip.mp4"},
        headers={"Range": "bytes=4-9", "If-Range": alice_etag},
        csrf=False,
    )
    assert part.status_code == 206
    assert part.headers.get("content-range") == "bytes 4-9/16"
    assert part.headers.get("content-length") == "6"
    assert part.content == b"456789"

    suffix = client.api(
        "raw",
        query={"path": "clip.mp4"},
        headers={"Range": "bytes=-4"},
        csrf=False,
    )
    assert suffix.status_code == 206
    assert suffix.headers.get("content-range") == "bytes 12-15/16"
    assert suffix.content == b"cdef"

    stale_if_range = client.api(
        "raw",
        query={"path": "clip.mp4"},
        headers={"Range": "bytes=4-9", "If-Range": '"stale-etag"'},
        csrf=False,
    )
    assert stale_if_range.status_code == 200
    assert stale_if_range.content == video_bytes

    bad_range = client.api(
        "raw",
        query={"path": "clip.mp4"},
        headers={"Range": "bytes=100-200"},
        csrf=False,
    )
    assert bad_range.status_code == 416
    assert bad_range.headers.get("content-range") == "bytes */16"


def test_extract_subdir_and_conflict(world):
    scenario, client = world
    write_zip(scenario.alice_root / "bundle.zip", {"inside.txt": b"sub-content"})
    res = client.api(
        "archive_extract",
        method="POST",
        json_body={"path": "bundle.zip", "targetPath": "", "createSubdir": True},
    )
    assert res.status_code == 200, res.text
    assert json_body(res)["data"]["targetPath"] == "bundle"
    assert (scenario.alice_root / "bundle" / "inside.txt").read_bytes() == b"sub-content"

    # Existing regular file at subdir target must return 409 already_exists
    (scenario.alice_root / "clash").write_text("file-blocking-dir", encoding="utf-8")
    write_zip(scenario.alice_root / "clash.zip", {"a.txt": b"1"})
    conflict_res = client.api(
        "archive_extract",
        method="POST",
        json_body={"path": "clash.zip", "targetPath": "", "createSubdir": True, "dryRun": True},
    )
    assert conflict_res.status_code == 409
    assert error_code(conflict_res) == "already_exists"


def test_archive_list_and_inner_file_access(world):
    scenario, client = world
    entries = {
        "docs/readme.md": b"# Hello Archive\n",
        "img/icon.svg": b"<svg xmlns='http://www.w3.org/2000/svg'></svg>",
        "bin/data.bin": b"\x00\x01\x02\x03",
    }
    write_zip(scenario.alice_root / "explore.zip", entries)
    write_tar(scenario.alice_root / "explore.tar", entries)
    write_targz(scenario.alice_root / "explore.tar.gz", entries)

    for archive_name in ("explore.zip", "explore.tar", "explore.tar.gz"):
        res_list = client.api("archive_list", query={"path": archive_name}, csrf=False)
        assert res_list.status_code == 200, res_list.text
        data = json_body(res_list)["data"]
        assert data["totalFiles"] == 3
        assert data["truncated"] is False
        assert data["randomAccess"] is True
        paths = {e["path"] for e in data["entries"]}
        assert "docs/readme.md" in paths
        assert "img/icon.svg" in paths

        res_read = client.api("archive_read", query={"path": archive_name, "entry": "docs/readme.md"}, csrf=False)
        assert res_read.status_code == 200, res_read.text
        read_data = json_body(res_read)["data"]
        assert read_data["content"] == "# Hello Archive\n"
        assert read_data["encoding"] == "utf-8"

        res_svg = client.api("archive_raw", query={"path": archive_name, "entry": "img/icon.svg"}, csrf=False)
        assert res_svg.status_code == 200
        assert res_svg.headers.get("content-security-policy") == "sandbox"
        assert res_svg.headers.get("x-content-type-options") == "nosniff"
        etag = res_svg.headers.get("etag")
        assert etag

        res_304 = client.api(
            "archive_raw",
            query={"path": archive_name, "entry": "img/icon.svg"},
            headers={"If-None-Match": etag},
            csrf=False,
        )
        assert res_304.status_code == 304

        res_dl = client.api("archive_download", query={"path": archive_name, "entry": "bin/data.bin"}, csrf=False)
        assert res_dl.status_code == 200
        assert "attachment" in (res_dl.headers.get("content-disposition") or "")
        assert res_dl.content == b"\x00\x01\x02\x03"


def test_feature_archive_disabled(stack, tmp_path_factory, tools):
    work = tmp_path_factory.mktemp("no-archive")
    alice = work / "alice"
    _plant(alice, "hello.txt", "hello")
    write_zip(alice / "sample.zip", {"a.txt": b"1"})
    digest = password_hash(tools.php_exe)
    defines = _base_defines({
        "FILECARTON_STATIC_USER_LIST": [
            {"id": "alice", "passwordHash": digest, "root": fwd(alice), "featureArchive": False},
        ],
        "FILECARTON_AUTH_PROVIDERS": [
            {"class": r"\FileCarton\StaticPasswordAuth"},
        ],
    })
    php_file = work / "scenario.php"
    _write_defines(php_file, defines)
    scenario = Scenario(work=work, php_file=php_file, alice_root=alice)

    url = stack.activate(scenario)
    client = CartonClient(url)
    try:
        assert client.login("alice", PASSWORD).status_code == 200
        page = client.get_page()
        cfg = page_config(page.text)
        assert cfg is not None
        assert cfg.get("features", {}).get("archive") is False

        r_list = client.api("archive_list", query={"path": "sample.zip"}, csrf=False)
        assert r_list.status_code == 403
        assert error_code(r_list) == "archive.disabled"

        r_create = client.api(
            "archive_create",
            method="POST",
            json_body={"format": "zip", "path": "", "items": ["hello.txt"]},
        )
        assert r_create.status_code == 403
        assert error_code(r_create) == "archive.disabled"

        r_extract = client.api(
            "archive_extract",
            method="POST",
            json_body={"path": "sample.zip", "targetPath": ""},
        )
        assert r_extract.status_code == 403
        assert error_code(r_extract) == "archive.disabled"

        for act in ("archive_read", "archive_raw", "archive_download"):
            r_act = client.api(act, query={"path": "sample.zip", "entry": "a.txt"}, csrf=False)
            assert r_act.status_code == 403
            assert error_code(r_act) == "archive.disabled"
    finally:
        client.close()


def test_archive_limits_and_tar_restrictions(stack, tmp_path_factory, tools):
    work = tmp_path_factory.mktemp("archive-limits")
    alice = work / "alice"
    alice.mkdir(parents=True, exist_ok=True)
    many_entries = {f"dir/file_{i}.txt": f"content-{i}".encode() for i in range(6)}
    write_zip(alice / "many.zip", many_entries)
    write_tar(alice / "many.tar", many_entries)
    write_targz(alice / "big.tar.gz", {"payload.txt": b"x" * 2048})

    digest = password_hash(tools.php_exe)
    defines = _base_defines({
        "FILECARTON_ARCHIVE_LIST_MAX_FILES": 3,
        "FILECARTON_ARCHIVE_TAR_MAX_ACCESS_FILES": 4,
        "FILECARTON_ARCHIVE_TARGZ_MAX_ACCESS_SIZE": 64,
        "FILECARTON_STATIC_USER_LIST": [
            {"id": "alice", "passwordHash": digest, "root": fwd(alice)},
        ],
        "FILECARTON_AUTH_PROVIDERS": [
            {"class": r"\FileCarton\StaticPasswordAuth"},
        ],
    })
    php_file = work / "scenario.php"
    _write_defines(php_file, defines)
    scenario = Scenario(work=work, php_file=php_file, alice_root=alice)

    url = stack.activate(scenario)
    client = CartonClient(url)
    try:
        assert client.login("alice", PASSWORD).status_code == 200

        # ZIP list is truncated at 3 entries, but randomAccess remains True
        r_zip = client.api("archive_list", query={"path": "many.zip"}, csrf=False)
        assert r_zip.status_code == 200
        d_zip = json_body(r_zip)["data"]
        assert d_zip["truncated"] is True
        assert d_zip["entryLimit"] == 3
        assert len(d_zip["entries"]) == 3
        assert d_zip["totalFiles"] == 6
        assert d_zip["randomAccess"] is True

        # ZIP still allows reading the 6th entry
        r_zip_read = client.api("archive_read", query={"path": "many.zip", "entry": "dir/file_5.txt"}, csrf=False)
        assert r_zip_read.status_code == 200
        assert json_body(r_zip_read)["data"]["content"] == "content-5"

        # TAR list is truncated at 3 entries AND randomAccess is False (6 > 4)
        r_tar = client.api("archive_list", query={"path": "many.tar"}, csrf=False)
        assert r_tar.status_code == 200
        d_tar = json_body(r_tar)["data"]
        assert d_tar["truncated"] is True
        assert d_tar["randomAccess"] is False

        # TAR single-entry access is rejected when file count exceeds FILECARTON_ARCHIVE_TAR_MAX_ACCESS_FILES
        r_tar_read = client.api("archive_read", query={"path": "many.tar", "entry": "dir/file_0.txt"}, csrf=False)
        assert r_tar_read.status_code == 403
        assert error_code(r_tar_read) == "archive.random_access_disabled"

        # TAR.GZ exceeding FILECARTON_ARCHIVE_TARGZ_MAX_ACCESS_SIZE is rejected
        r_tgz = client.api("archive_list", query={"path": "big.tar.gz"}, csrf=False)
        assert r_tgz.status_code == 413
        assert error_code(r_tgz) == "archive.too_large_to_open"
    finally:
        client.close()

