from __future__ import annotations

from pathlib import Path

import pytest
from hypothesis import HealthCheck, settings

from rig.env import load_tools
from rig.server import Stack

REPO = Path(__file__).resolve().parents[2]


def pytest_addoption(parser):
    parser.addoption(
        "--fuzz-deep",
        action="store_true",
        help="Run Hypothesis with 2000 examples instead of 60.",
    )


def pytest_configure(config):
    examples = 2000 if config.getoption("--fuzz-deep") else 60
    settings.register_profile(
        "fc",
        max_examples=examples,
        derandomize=True,
        deadline=None,
        suppress_health_check=[HealthCheck.too_slow],
    )
    settings.load_profile("fc")


@pytest.fixture(scope="session")
def tools():
    loaded, message = load_tools()
    if loaded is None:
        pytest.skip(message)
    return loaded


@pytest.fixture(scope="session")
def stack(tools, tmp_path_factory):
    rig = Stack(tools, REPO, tmp_path_factory.mktemp("rig"))
    rig.start()
    yield rig
    rig.stop()


@pytest.fixture(scope="session")
def support() -> Path:
    return REPO / "tests" / "support" / "php"
