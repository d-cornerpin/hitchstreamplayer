"""
Tiny .env loader — avoids a python-dotenv dependency so this harness runs on a
stock Python 3. Parses KEY=VALUE lines, ignores blanks/comments, strips simple
surrounding quotes. Does not overwrite variables already set in the environment.
"""

import os


def load_env(path=".env"):
    if not os.path.exists(path):
        return
    with open(path, "r", encoding="utf-8") as fh:
        for line in fh:
            line = line.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            key, _, val = line.partition("=")
            key = key.strip()
            val = val.strip()
            if len(val) >= 2 and val[0] == val[-1] and val[0] in ("'", '"'):
                val = val[1:-1]
            os.environ.setdefault(key, val)


def require(name):
    val = os.environ.get(name)
    if not val:
        raise SystemExit(
            f"Missing {name}. Copy .env.example to .env and fill it in "
            f"(see README.md)."
        )
    return val
