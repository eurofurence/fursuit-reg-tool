#!/usr/bin/env python3
"""Launch the Badge Print Agent.

    python3 run_agent.py            # normal
    python3 run_agent.py --demo     # cycle through printer states, no hardware

On macOS the Homebrew Python has no Tk; use the system interpreter:

    /usr/bin/python3 run_agent.py --demo
"""

import argparse
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--demo", action="store_true",
                        help="cycle through printer states for UI review")
    args = parser.parse_args()

    try:
        import tkinter  # noqa: F401
    except ImportError:
        print("tkinter is not available in this interpreter.")
        print("On macOS try:  /usr/bin/python3 run_agent.py --demo")
        return 1

    from agent.ui import main as run_ui

    run_ui(demo=args.demo)
    return 0


if __name__ == "__main__":
    sys.exit(main())
