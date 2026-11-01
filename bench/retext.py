"""Apply the benchmark scripts' current wording to existing records, without a rerun.

    python bench/retext.py site/benchmarks

Only the fields that text() returns change. Rows, environment, and every
measured value are left as they are.
"""

from __future__ import annotations

import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import cells  # noqa: E402
import startup  # noqa: E402

TEXT = {"kernel-startup": startup.text, "cell-round-trip": cells.text}


def main() -> None:
    directory = Path(sys.argv[1])
    for slug, text in TEXT.items():
        path = directory / f"{slug}.json"
        if not path.is_file():
            continue
        record = json.loads(path.read_text())
        before = {key: record[key] for key in ("rows", "environment")}
        record.update(text())
        assert before == {key: record[key] for key in ("rows", "environment")}
        path.write_text(json.dumps(record, indent=4, ensure_ascii=False) + "\n")
        print(f"bench/retext: rewrote the text of {path}")


if __name__ == "__main__":
    main()
