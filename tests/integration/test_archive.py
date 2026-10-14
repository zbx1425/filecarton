import pytest

from rig.archives import write_tar, write_zip
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
    finally:
        client.close()

