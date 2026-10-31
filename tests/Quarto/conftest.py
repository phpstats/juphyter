"""What the render tests need before they can render anything.

The fixtures here build the three things a real `quarto render` of a PHP
notebook takes, and each of them skips with the reason rather than failing when
its prerequisite is missing:

- **Quarto, a PHP 8.5, and a Python with Jupyter in it.** PHP 8.5 is the floor
  because the fixture plots with phiz, which is written in 8.5 syntax and does
  not parse below it.
- **A notebook project of the fixture's own**, built under `build/`, holding
  phibble, phplyr, and phiz. juphyter depends on none of the three, so its
  committed manifest and its `vendor/` stay at the none-installed set and the
  packages the fixture needs are installed somewhere else entirely. The
  notebooks sit one directory below that project, so rendering them exercises
  the engine's walk up from the notebook's directory in search of an
  autoloader.
- **A kernelspec in a temporary `JUPYTER_DATA_DIR`**, written by
  `juphyter install` itself and removed when the run ends. The real Jupyter data
  directory is never touched, and `JUPYTER_PATH` points at the temporary one so
  that Quarto resolves the fixture's kernel name there and nowhere else.

The Python that runs these tests is the Python Quarto is pointed at, through
`QUARTO_PYTHON`, for the same reason the protocol tests use `sys.executable`: it
is the interpreter known to have the packages, and pinning it keeps the test
from rendering against some other Jupyter on the machine.
"""

from __future__ import annotations

import atexit
import hashlib
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
from dataclasses import dataclass
from pathlib import Path

import pytest

#: The package root, two levels up from this file.
ROOT = Path(__file__).resolve().parents[2]

#: The fixture sources this directory copies into the build directory.
FIXTURE = Path(__file__).resolve().parent / "fixture"

#: Where the notebook project and the rendered output are built. Gitignored,
#: and kept rather than removed: the Composer install in it is the slow part of
#: these tests, and the rendered files are what a reviewer looks at.
BUILD = Path(os.environ.get("JUPHYTER_QUARTO_BUILD", ROOT / "build" / "quarto"))

#: The kernel name the fixture documents name in their front matter.
KERNEL_NAME = "juphyter-render"

#: The PHP floor, set by phiz rather than by juphyter.
PHP_FLOOR = (8, 5)

#: The packages the fixture notebook uses.
FAMILY = ("phpstats/phibble", "phpstats/phplyr", "phpstats/phiz")

#: What Quarto's jupyter engine imports, plus the kernel's own host.
PYTHON_MODULES = ("nbclient", "nbformat", "jupyter_client", "yaml", "ipykernel")

#: Generous, because a cold Composer install clones three repositories.
INSTALL_TIMEOUT = 900

#: A render of the fixture takes a few seconds; a first one pays for Quarto's
#: own startup as well.
RENDER_TIMEOUT = 600


def quarto() -> str:
    """The `quarto` binary, or a skip naming what to install."""
    explicit = os.environ.get("JUPHYTER_QUARTO")
    found = explicit if explicit and Path(explicit).is_file() else shutil.which("quarto")

    if not found:
        pytest.skip(
            "The render tests need the quarto binary on PATH. Install it from "
            "https://quarto.org/docs/get-started/, or set JUPHYTER_QUARTO to it."
        )

    return found


def php() -> str:
    """A PHP 8.5 or newer, or a skip naming why that is the floor."""
    explicit = os.environ.get("JUPHYTER_QUARTO_PHP")
    candidates = [explicit] if explicit else ["php85", "php8.5", "php"]
    tried: list[str] = []

    for candidate in candidates:
        if not candidate:
            continue

        resolved = shutil.which(candidate) or (candidate if Path(candidate).is_file() else None)

        if not resolved:
            continue

        version = php_version(resolved)

        if version is None:
            continue

        tried.append(f"{resolved} ({version})")

        if tuple(int(part) for part in version.split(".")[:2]) >= PHP_FLOOR:
            return resolved

    pytest.skip(
        "The render tests need PHP {}.{} or newer, because the fixture plots with "
        "phiz and phiz is written in 8.5 syntax. Tried: {}. Set "
        "JUPHYTER_QUARTO_PHP to the binary to use.".format(
            PHP_FLOOR[0], PHP_FLOOR[1], ", ".join(tried) or "nothing on PATH"
        )
    )


def php_version(binary: str) -> str | None:
    """What a PHP binary reports as its version, or None when it will not run."""
    try:
        result = subprocess.run(
            [binary, "-r", "echo PHP_VERSION;"],
            capture_output=True,
            text=True,
            check=False,
            timeout=60,
        )
    except (OSError, subprocess.SubprocessError):
        return None

    return result.stdout.strip() or None


def python_with_jupyter() -> str:
    """This interpreter, once it is known to hold what Quarto and the kernel need."""
    missing = []

    for module in PYTHON_MODULES:
        try:
            __import__(module)
        except ImportError:
            missing.append(module)

    if missing:
        pytest.skip(
            "The render tests run Quarto's jupyter engine in this interpreter "
            "({}), which is missing {}. Install them with: {} -m pip install -r "
            "tests/Quarto/requirements.txt".format(
                sys.executable, ", ".join(missing), sys.executable
            )
        )

    return sys.executable


def vl_convert(environment: dict[str, str]) -> str | None:
    """The vl-convert binary phiz would find, or None when there is none.

    The lookup mirrors `Phiz\\Export::find()`: the two environment variables
    first, then `PATH`. The static SVG is the only representation that needs it,
    so its absence skips the PDF assertions rather than failing them.
    """
    for name in ("PHIZ_VL_CONVERT", "VL_CONVERT"):
        candidate = environment.get(name)

        if candidate and Path(candidate).is_file():
            return candidate

    return shutil.which("vl-convert", path=environment.get("PATH"))


@dataclass(frozen=True)
class Kernel:
    """An installed kernelspec, and the directory Jupyter will find it in."""

    name: str
    data_directory: Path
    spec_directory: Path

    def spec(self) -> dict:
        return json.loads((self.spec_directory / "kernel.json").read_text())


@dataclass(frozen=True)
class Project:
    """The notebook project the fixture documents are rendered inside."""

    root: Path

    @property
    def notebooks(self) -> Path:
        return self.root / "notebooks"

    @property
    def autoloader(self) -> Path:
        return self.root / "vendor" / "autoload.php"


@pytest.fixture(scope="session")
def tools() -> dict[str, str]:
    """The three binaries every render needs, each resolved once."""
    return {"quarto": quarto(), "php": php(), "python": python_with_jupyter()}


@pytest.fixture(scope="session")
def project(tools: dict[str, str]) -> Project:
    """The fixture notebook project, built once and then reused.

    The notebooks and the manifest are copied fresh on every run, because they
    are the thing under test and they cost nothing. `vendor/` is left alone when
    the manifest has not changed since it was installed, because installing it
    clones three packages from GitHub.
    """
    root = BUILD / "project"
    root.mkdir(parents=True, exist_ok=True)

    manifest = FIXTURE / "composer.json"
    shutil.copy2(manifest, root / "composer.json")

    notebooks = root / "notebooks"
    notebooks.mkdir(exist_ok=True)

    for source in sorted((FIXTURE / "notebooks").glob("*.qmd")):
        shutil.copy2(source, notebooks / source.name)

    built = Project(root)
    stamp = root / ".installed"
    wanted = hashlib.sha256(manifest.read_bytes()).hexdigest()

    if built.autoloader.is_file() and stamp.is_file() and stamp.read_text().strip() == wanted:
        return built

    result = subprocess.run(
        [
            tools["php"],
            composer(),
            "install",
            "--no-interaction",
            "--no-progress",
            # --prefer-source clones from GitHub rather than downloading dist
            # zipballs through api.github.com, so it needs no GitHub token.
            "--prefer-source",
        ],
        cwd=root,
        capture_output=True,
        text=True,
        check=False,
        timeout=INSTALL_TIMEOUT,
    )

    if result.returncode != 0 or not built.autoloader.is_file():
        pytest.skip(
            "The fixture notebook needs {} installed in {}, and Composer could "
            "not install them. They come from Packagist and are cloned from "
            "GitHub, so this needs network access. Composer said:\n{}".format(
                ", ".join(FAMILY), root, (result.stdout + result.stderr).strip()[-2000:]
            )
        )

    stamp.write_text(wanted + "\n")

    return built


def composer() -> str:
    """The Composer the fixture project is installed with."""
    found = os.environ.get("JUPHYTER_COMPOSER") or shutil.which("composer")

    if not found:
        pytest.skip(
            "The render tests build the fixture's notebook project with Composer, "
            "which is not on PATH. Set JUPHYTER_COMPOSER to composer.phar."
        )

    return found


@pytest.fixture(scope="session")
def kernel(tools: dict[str, str]) -> Kernel:
    """A kernelspec of this run's own, written by `juphyter install`.

    `JUPYTER_DATA_DIR` is exported before the install, which is both how the
    installer decides where to write and how Quarto later finds what it wrote.
    `JUPYTER_PATH` is set to the same directory so that the fixture's kernel
    name cannot resolve against a kernelspec somebody installed for real.
    """
    directory = Path(tempfile.mkdtemp(prefix="juphyter-quarto-"))
    atexit.register(shutil.rmtree, directory, ignore_errors=True)

    os.environ["JUPYTER_DATA_DIR"] = str(directory)
    os.environ["JUPYTER_PATH"] = str(directory)

    result = subprocess.run(
        [
            tools["php"],
            str(ROOT / "bin" / "juphyter"),
            "install",
            "--name",
            KERNEL_NAME,
            "--display-name",
            "PHP (juphyter render tests)",
            "--python",
            tools["python"],
        ],
        capture_output=True,
        text=True,
        check=False,
        timeout=300,
    )

    spec_directory = directory / "kernels" / KERNEL_NAME

    if result.returncode != 0 or not (spec_directory / "kernel.json").is_file():
        pytest.fail(
            "juphyter install failed, so there is no kernel to render with:\n"
            + (result.stdout + result.stderr).strip()
        )

    return Kernel(KERNEL_NAME, directory, spec_directory)


@dataclass
class Renderer:
    """`quarto render`, with the environment a PHP notebook needs."""

    quarto: str
    environment: dict[str, str]
    notebooks: Path

    def run(self, document: str, to: str, *arguments: str, check: bool = True):
        """Render one document and return the completed process."""
        result = subprocess.run(
            [self.quarto, "render", document, "--to", to, *arguments],
            cwd=self.notebooks,
            capture_output=True,
            text=True,
            check=False,
            env=self.environment,
            timeout=RENDER_TIMEOUT,
        )

        if check and result.returncode != 0:
            raise AssertionError(
                "quarto render {} --to {} failed with code {}:\n{}".format(
                    document, to, result.returncode, (result.stdout + result.stderr).strip()
                )
            )

        return result

    def output(self, name: str) -> Path:
        return self.notebooks / name


@pytest.fixture(scope="session")
def renderer(tools: dict[str, str], project: Project, kernel: Kernel) -> Renderer:
    """A renderer pointed at the fixture project and this run's kernelspec."""
    environment = dict(os.environ)
    environment.update(
        {
            "JUPYTER_DATA_DIR": str(kernel.data_directory),
            "JUPYTER_PATH": str(kernel.data_directory),
            # The interpreter the kernel and Quarto's jupyter engine both run
            # in, rather than whichever python3 Quarto would pick for itself.
            "QUARTO_PYTHON": sys.executable,
            # phiz looks for vl-convert on PATH, and in a virtual environment
            # it sits beside the interpreter.
            "PATH": os.pathsep.join(
                [str(Path(sys.executable).parent), environment.get("PATH", "")]
            ),
        }
    )

    return Renderer(tools["quarto"], environment, project.notebooks)


@pytest.fixture(scope="session")
def svg_available(renderer: Renderer) -> bool:
    """Whether phiz will be able to produce a static SVG for this run."""
    return vl_convert(renderer.environment) is not None


def normalized(html: str) -> str:
    """Rendered HTML with everything that counts executions taken out.

    Three things in a rendered page change between two renders of the same
    document against the same kept-alive kernel, and all three follow the
    kernel's execution counter rather than the content of a cell:

    - Quarto gives every cell a fresh random `id`.
    - `data-execution_count` is the kernel's count, which a daemonized kernel
      carries on from where the last render left it.
    - A traceback frame names the cell it came from as `Cell <count>`, so the
      label on the fixture's failing cell rises with the count as well.

    A stock `python3` kernel varies the first two in exactly the same way.
    Comparing two renders means comparing what is left once all three are gone.
    """
    without_ids = re.sub(r' id="[0-9a-f]{8}"', "", html)
    without_counts = re.sub(r' data-execution_count="\d+"', "", without_ids)

    return re.sub(r"Cell \d+, line", "Cell N, line", without_counts)
