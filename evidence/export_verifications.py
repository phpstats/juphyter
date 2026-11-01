"""Write site/verifications records from the JUnit files evidence/verify left.

    python evidence/export_verifications.py BUILD_DIR OUTPUT_DIR

Every test case in a suite becomes a case in its record, with its status and,
for a skip or a failure, the reason the runner gave. Nothing is dropped and
nothing that did not run is reported as a pass.
"""

from __future__ import annotations

import datetime
import importlib.metadata
import json
import os
import platform
import re
import subprocess
import sys
import xml.etree.ElementTree as ET
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

REPRODUCE_PROTOCOL = [
    "python -m pip install -r tests/Protocol/requirements.txt",
    "composer install",
    "python -m pytest tests/Protocol -v",
]
REPRODUCE_QUARTO = [
    "python -m pip install -r tests/Quarto/requirements.txt",
    "composer install",
    "python -m pytest tests/Quarto -v",
]
REPRODUCE_FAMILY = [
    "composer install",
    'composer require --dev --prefer-source "phpstats/phibble:^0.5" "phpstats/phplyr:^0.4" "phpstats/phiz:^0.5"',
    "vendor/bin/pest",
]


def run(*command: str) -> str:
    try:
        return subprocess.run(command, capture_output=True, text=True, check=True).stdout.strip()
    except (OSError, subprocess.CalledProcessError):
        return ""


def os_name() -> str:
    machine = platform.machine()
    if sys.platform == "darwin":
        return f"macOS {run('sw_vers', '-productVersion')} ({machine})"
    pretty = ""
    release = Path("/etc/os-release")
    if release.is_file():
        for line in release.read_text().splitlines():
            if line.startswith("PRETTY_NAME="):
                pretty = line.split("=", 1)[1].strip('"')
    return f"Linux, {pretty or platform.release()} ({machine})"


def package_version(name: str) -> str | None:
    try:
        return importlib.metadata.version(name)
    except importlib.metadata.PackageNotFoundError:
        return None


def composer_version(build: Path, name: str) -> str | None:
    file = build / f"{name}.json"
    if not file.is_file():
        return None
    try:
        versions = json.loads(file.read_text()).get("versions") or []
    except ValueError:
        return None
    return versions[0].lstrip("v") if versions else None


def cases_from(junit: Path) -> list[dict]:
    """Each <testcase> as (classname, name, status, reason)."""
    if not junit.is_file():
        return []
    cases = []
    for case in ET.parse(junit).getroot().iter("testcase"):
        outcomes = []
        for child in case:
            message = child.get("message") or (child.text or "").strip()
            if child.tag == "skipped":
                outcomes.append(("skipped", message))
            elif child.tag in ("failure", "error"):
                outcomes.append(("fail", message))
        # A test with subtests reports each skipped subtest as its own child,
        # and each one is a check of its own, so each becomes a case.
        if not outcomes:
            outcomes = [("pass", None)]
        failures = [o for o in outcomes if o[0] == "fail"]
        if failures:
            outcomes = failures
        for index, (status, reason) in enumerate(outcomes):
            name = case.get("name") or ""
            cases.append(
                {
                    "classname": case.get("classname") or "",
                    "file": case.get("file") or "",
                    "name": name,
                    "part": index + 1 if len(outcomes) > 1 else None,
                    "status": status,
                    "reason": first_sentence(reason) if reason else None,
                }
            )
    return cases


def teamcity_reasons(log: Path) -> dict[str, str]:
    """Skip reasons from pest's TeamCity log, which its JUnit file leaves out.

    Keyed by the test's name with everything but letters and digits removed,
    because the two logs spell the same name differently.
    """
    reasons: dict[str, str] = {}
    if not log.is_file():
        return reasons
    pattern = re.compile(r"##teamcity\[testIgnored name='(.*?)' message='(.*?)'")
    for line in log.read_text().splitlines():
        match = pattern.search(line)
        if match:
            name = match.group(1).removeprefix("__pest_evaluable_")
            reasons[loose(name)] = match.group(2).replace("|'", "'").replace("|n", " ")
    return reasons


def loose(name: str) -> str:
    return re.sub(r"[^0-9a-z]", "", name.lower())


def first_sentence(text: str) -> str:
    """One line for the record; the full text stays in the JUnit file."""
    text = re.sub(r"^Skipped:\s*", "", text.strip())
    line = text.splitlines()[0].strip() if text else ""
    return line[:300]


def humanize(test_name: str) -> str:
    name = re.sub(r"\[.*\]$", "", test_name)
    name = re.sub(r"^test_", "", name)
    return name.replace("_", " ")


def case(name: str, quantity: str, source: dict) -> dict:
    if source.get("part"):
        quantity = f"{quantity}, check {source['part']}"
    entry = {"name": name, "quantity": quantity, "status": source["status"]}
    if source["status"] != "pass":
        entry["reason"] = source["reason"] or "The runner gave no reason."
    return entry


def write(directory: Path, slug: str, record: dict) -> None:
    directory.mkdir(parents=True, exist_ok=True)
    (directory / f"{slug}.json").write_text(json.dumps(record, indent=4, ensure_ascii=False) + "\n")
    print(f"export_verifications: wrote {directory / (slug + '.json')}")


def main() -> None:
    build = Path(sys.argv[1])
    output = Path(sys.argv[2])
    junit = build / "junit"

    php = run(os.environ.get("JUPHYTER_EVIDENCE_PHP", "php"), "-r", "echo PHP_VERSION;") or None
    quarto = run("quarto", "--version") or None
    environment = {
        "date": datetime.date.today().isoformat(),
        "commit": run("git", "-C", str(ROOT), "rev-parse", "--short", "HEAD"),
        "php": php,
        "python": platform.python_version(),
        "os": os_name(),
    }
    python_versions = {
        "PHP": php,
        "Python": platform.python_version(),
        "ipykernel": package_version("ipykernel"),
        "jupyter_client": package_version("jupyter_client"),
    }

    protocol = cases_from(junit / "protocol.xml")
    reference = [c for c in protocol if c["classname"].endswith("JuphyterKernelTests")]
    behavior = [c for c in protocol if not c["classname"].endswith("JuphyterKernelTests")]
    kernel_test = package_version("jupyter_kernel_test")

    write(
        output,
        "protocol-conformance",
        {
            "title": "Protocol conformance",
            "label": "Protocol conformance",
            "kind": "reference",
            "checked": (
                "Replies to execute, complete, is_complete, and kernel_info requests, and the "
                "stdout, stderr, execute-result, display-data, and error output of each sample. "
                "Every message validated against the Jupyter messaging protocol v5 schema."
            ),
            "description": (
                "jupyter_kernel_test's KernelTests drive a kernel registered from the tested commit "
                "through jupyter_client, with the PHP samples declared in tests/Protocol/test_kernel.py."
            ),
            "reference": f"jupyter_kernel_test {kernel_test}, Jupyter messaging protocol 5.x",
            "versions": {**python_versions, "jupyter_kernel_test": kernel_test},
            "inputs": "PHP samples declared on JuphyterKernelTests, tests/Protocol/test_kernel.py",
            "comparison": [
                {
                    "id": "schema",
                    "kind": "exact",
                    "applies_to": "Messages and sample output; v5 schema and expected values",
                }
            ],
            "cases": [case(humanize(c["name"]), "Messages and output", c) for c in reference],
            "exclusions": [
                "History requests are not implemented; the three history checks are skipped.",
                "inspect_request is not implemented; the inspect check is skipped.",
                "No clear_output or pager sample is declared; those checks are skipped.",
            ],
            "reproduce": REPRODUCE_PROTOCOL,
            "source": "tests/Protocol/test_kernel.py",
            "environment": environment,
        },
    )

    write(
        output,
        "kernel-behavior",
        {
            "title": "Kernel behavior",
            "label": "Kernel behavior",
            "kind": "contract",
            "checked": (
                "Execution state across cells, stream order and completeness, output written to the "
                "engine's own descriptors, interrupts, completion offsets, and process cleanup after "
                "shutdown, kill, and engine failure."
            ),
            "description": (
                "Protocol tests start a kernel registered from the tested commit and drive it through "
                "jupyter_client. Stream tests drive the PHP engine through the transport's Engine class "
                "with a slowed pipe reader."
            ),
            "versions": python_versions,
            "comparison": [
                {"id": "contract", "kind": "exact", "applies_to": "Asserted behavior"}
            ],
            "cases": [case(humanize(c["name"]), "Behavior", c) for c in behavior],
            "reproduce": REPRODUCE_PROTOCOL,
            "source": "tests/Protocol/test_kernel.py",
            "environment": environment,
        },
    )

    quarto_cases = cases_from(junit / "quarto.xml")
    pdf = [c for c in quarto_cases if "pdf_renders_the_plot" in c["name"]]
    if pdf and pdf[0]["status"] == "pass":
        pdf_note = "PDF figure check: run, passed."
    elif pdf:
        pdf_note = f"PDF figure check: {pdf[0]['status']}; {pdf[0]['reason']}"
    else:
        pdf_note = "PDF figure check: not run."
    vl = run("vl-convert", "--version") or None

    write(
        output,
        "quarto-render",
        {
            "title": "Quarto rendering",
            "label": "Quarto rendering",
            "kind": "integration",
            "checked": (
                "Tables, plots, figure captions, cell options, state across cells, multibyte text, "
                "tracebacks, and syntax highlighting in HTML; kernel metadata and outputs in ipynb; "
                "plot figures in PDF through Typst; kernel reuse under the Quarto daemon."
            ),
            "description": (
                f"pytest renders the fixture documents with Quarto {quarto} through a kernel registered "
                "from the tested commit and inspects the HTML, ipynb, and PDF output."
            ),
            "note": pdf_note,
            "versions": {
                **python_versions,
                "Quarto": quarto,
                "vl-convert": vl,
                "phibble": composer_version(build, "phibble"),
                "phplyr": composer_version(build, "phplyr"),
                "phiz": composer_version(build, "phiz"),
            },
            "inputs": [
                {"name": "report.qmd", "detail": "tables, a plot, cell options, and an error", "url": "tests/Quarto/fixture/notebooks/report.qmd"},
                {"name": "kernelspec.qmd", "detail": "explicit kernelspec front matter", "url": "tests/Quarto/fixture/notebooks/kernelspec.qmd"},
            ],
            "comparison": [
                {"id": "structure", "kind": "exact", "applies_to": "Rendered content; HTML, ipynb, and PDF"}
            ],
            "cases": [case(humanize(c["name"]), "Rendered output", c) for c in quarto_cases],
            "exclusions": [
                "Long-idle daemon test: runs only with JUPHYTER_QUARTO_IDLE set; tests/Engine/IdleTest.php covers the behavior with a one-second timeout.",
            ],
            "known_differences": [
                "quarto render --to ipynb drops HTML tables and error outputs, for any kernel.",
            ],
            "reproduce": REPRODUCE_QUARTO,
            "source": "tests/Quarto/test_render.py",
            "data": "tests/Quarto/fixture",
            "environment": environment,
        },
    )

    family = cases_from(junit / "family.xml")
    reasons = teamcity_reasons(junit / "family.teamcity")
    for c in family:
        if c["status"] == "skipped" and not c["reason"]:
            c["reason"] = reasons.get(loose(c["name"]))
    versions = {
        "PHP": php,
        "phibble": composer_version(build, "phibble"),
        "phplyr": composer_version(build, "phplyr"),
        "phiz": composer_version(build, "phiz"),
        "vl-convert": vl,
    }

    def family_group(c: dict) -> str:
        """The test file, such as Unit/Display/TableFormatterTest."""
        return c["file"].split("::", 1)[0].removeprefix("tests/").removesuffix(".php")

    def family_test(c: dict) -> str:
        return c["name"].replace("`", "")

    write(
        output,
        "family-compatibility",
        {
            "title": "Family compatibility",
            "label": "Family compatibility",
            "kind": "integration",
            "checked": (
                "The full PHP test suite, including the table and chart formatters, with the current "
                "phibble, phplyr, and phiz releases installed."
            ),
            "description": (
                "Pest runs in a copy of the tested commit with the three packages required from Packagist."
            ),
            "versions": versions,
            "comparison": [
                {"id": "contract", "kind": "exact", "applies_to": "Asserted values and output"}
            ],
            "cases": [case(family_group(c), family_test(c), c) for c in family],
            "reproduce": REPRODUCE_FAMILY,
            "source": ".github/workflows/family.yml",
            "environment": environment,
        },
    )


if __name__ == "__main__":
    main()
