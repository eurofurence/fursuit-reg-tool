# -*- mode: python ; coding: utf-8 -*-
"""PyInstaller recipe for the Badge Print Agent.

    py -3.8 -m PyInstaller --clean --noconfirm badge-print-agent.spec

Read this before deleting anything from it.

Almost every entry below collects something that is loaded at *runtime*: by
`ctypes`, by a filesystem lookup, or by a lazy `import` inside a function. None
of it is visible to PyInstaller's static analysis. Removing one does not
break the build. It builds a clean, signed-looking exe that dies the first time
somebody presses Print at the convention. That asymmetry is why each block
carries a comment saying what breaks without it.

Target platform, from `requirements.txt`: Windows 7 SP1, Python 3.8.10, all
wheels cp38 or pure-Python, PyInstaller 5.13.2 (the last 5.x, and the last line
whose prebuilt bootloader still starts on Windows 7). See `build.md` for the
Windows 7 caveats that this file cannot solve on its own.

Build variants, both selected by environment variable so CI and a hand build on
the station use the same spec:

    BADGE_AGENT_ONEDIR=1     one folder instead of one file (see build.md)
    BADGE_AGENT_WINDOWED=1   no console window (see the `console` comment below)
"""

import os
import sys

from PyInstaller.utils.hooks import (
    collect_all,
    collect_data_files,
    collect_dynamic_libs,
    collect_submodules,
)

NAME = "BadgePrintAgent"

ONEDIR = os.environ.get("BADGE_AGENT_ONEDIR", "") not in ("", "0")
WINDOWED = os.environ.get("BADGE_AGENT_WINDOWED", "") not in ("", "0")

block_cipher = None

binaries = []
datas = []
hiddenimports = []


def warn(package, error):
    """A miss here still produces a broken build, so say so loudly."""
    print("!! badge-print-agent.spec: could not collect %r (%s)." % (package, error))
    print("!! The resulting build will be INCOMPLETE. Do not ship it.")


def collect(package, **kwargs):
    """`collect_all`, but a missing package warns instead of aborting.

    The spec has to stay readable on a developer machine, where none of the
    Windows-only wheels can be installed.
    """
    try:
        found_datas, found_binaries, found_hidden = collect_all(package, **kwargs)
    except Exception as error:
        warn(package, error)
        return

    datas.extend(found_datas)
    binaries.extend(found_binaries)
    hiddenimports.extend(found_hidden)


def gather(hook, package, **kwargs):
    """One of the narrower `collect_*` hooks, with the same forgiveness."""
    try:
        return hook(package, **kwargs)
    except Exception as error:
        warn(package, error)
        return []


# --- The agent itself --------------------------------------------------------
#
# `agent/ui/app.py` imports the worker, api, notify, store, printing, render and
# camera modules from inside methods, not at module scope, so that the UI still
# opens on a machine where one of the heavy dependencies is missing. PyInstaller
# does follow function-level imports, but listing them means a module can never
# be dropped from the build by an unrelated refactor of the UI.
#
# `tests/test_packaging.py` fails if this list drifts from what is on disk.

AGENT_MODULES = [
    "agent",
    "agent.api",
    "agent.camera",
    "agent.config",
    "agent.monitor",
    "agent.notify",
    "agent.printing",
    "agent.render",
    "agent.store",
    "agent.telegram",
    "agent.verifier",
    "agent.vocabulary",
    "agent.worker",
    "agent.zebra",
    "agent.ui",
    "agent.ui.app",
    "agent.ui.calibration",
    "agent.ui.console",
]

hiddenimports += AGENT_MODULES


# --- pypdfium2: the bundled pdfium DLL --------------------------------------
#
# This is the entry most likely to be got wrong, because pypdfium2 ships the
# native library in a *second* top-level package, `pypdfium2_raw`, which nothing
# imports by name from our code.
#
#   pypdfium2_raw/bindings.py resolves the library with
#       pathlib.Path(__file__).parent / "pdfium.dll"
#   and raises ImportError if the file is not there.
#
# So `pdfium.dll` has to land at `pypdfium2_raw/pdfium.dll` inside the bundle,
# as a *binary*. Collecting it as a module gets you the Python bindings and no
# DLL, and `import pypdfium2` then fails at startup with "Could not find library
# 'pdfium'". `collect_dynamic_libs` puts it in the right relative directory.
#
# Both packages also read a `version.json` next to their `__init__.py` on first
# attribute access, which is a data file, not a module. Without it the smoke
# test in build.md (`print(pypdfium2.V_PYPDFIUM2)`) raises FileNotFoundError.
collect("pypdfium2")
collect("pypdfium2_raw")
binaries += gather(collect_dynamic_libs, "pypdfium2_raw")


# --- OpenCV: native extension plus its loader shim ---------------------------
#
# `cv2/__init__.py` is a loader that reads `cv2/config.py` and `cv2/config-3.py`
# to work out where to find the extension. `config-3.py` has a hyphen in its
# name, so it is not an importable module and PyInstaller will never pick it up
# on its own. It must be collected as a data file or `import cv2` fails.
#
# `collect_all` also brings in `cv2.pyd` and `opencv_videoio_ffmpeg455_64.dll`,
# which is the backend that actually opens the webcam. Camera verification is
# optional (printing must never depend on it, per docs/printing-implementation.md)
# but a build without the ffmpeg DLL fails at capture time, not at import time,
# which is the worse failure.
collect("cv2")
binaries += gather(collect_dynamic_libs, "cv2")

# numpy has a working bundled hook, but camera.py imports it at module scope and
# a partial numpy is a spectacularly confusing failure, so be explicit.
hiddenimports += ["numpy"]


# --- pysnmp: MIB modules are loaded off the filesystem, not imported ---------
#
# This is the one that fails silently, and SNMP is the only honest source of
# printer status we have. Getting it wrong means the agent reports every printer
# as `unknown`, which classifies as a stop, which halts the queue.
#
# pysnmp's `MibBuilder` does not import its MIBs. It resolves
# `pysnmp.smi.mibs.__file__`, takes the directory, lists it, and compiles the
# `.py` files it finds there by hand. Those filenames (`SNMPv2-MIB.py`,
# `__SNMP-FRAMEWORK-MIB.py`) contain hyphens, so they are not valid module
# names and cannot be hidden imports. They have to be shipped as data files at
# exactly `pysnmp/smi/mibs/` and `pysnmp/smi/mibs/instances/`, which is what
# `include_py_files=True` does here.
#
# Constructing `SnmpEngine()` loads SNMPv2-MIB and the framework instances even
# though agent/zebra.py only ever walks numeric OIDs, so this is needed for
# every reading, not just for MIB-aware lookups.
collect("pysnmp", include_py_files=True)
hiddenimports += gather(collect_submodules, "pysnmp.smi.mibs")
datas += gather(collect_data_files, "pysnmp.smi.mibs", include_py_files=True)
datas += gather(collect_data_files, "pysnmp.smi.mibs.instances", include_py_files=True)

# pyasn1 is pinned to 0.4.8 because pysnmp 4.4.12 imports compat helpers that
# 0.5 removed. Collected whole: pysnmp reaches parts of it by string name.
collect("pyasn1")

# Declared dependencies of pysnmp that the agent does not use directly (numeric
# OIDs, v2c, no MIB compilation, no v3 crypto). They are collected anyway
# because pysnmp imports them behind bare `except ImportError`, so a missing one
# degrades quietly instead of raising, which is exactly the failure mode we
# cannot see from here.
collect("pysmi")
collect("Cryptodome")


# --- tkinter -----------------------------------------------------------------
#
# PyInstaller's own hook bundles the Tcl/Tk runtime (tcl86t.dll, tk86t.dll and
# the `tcl`/`tk` script trees) whenever tkinter is in the graph, so there is no
# Tree() to add here. The hidden imports cover the submodules the UI pulls in:
# `ttk` and `messagebox` are imported by name in agent/ui/, and `PhotoImage`
# in agent/ui/calibration.py needs the base package initialised.
#
# Verify on the station rather than trusting the build log: a Tk that builds but
# cannot find its script tree fails with "Can't find a usable init.tcl" the
# moment the window opens, and nothing earlier in the run hints at it.
hiddenimports += [
    "tkinter",
    "tkinter.ttk",
    "tkinter.messagebox",
    "tkinter.filedialog",
    "tkinter.font",
]


# --- pywin32: spooler access -------------------------------------------------
#
# agent/printing.py imports win32print at module scope inside a guard and
# win32ui lazily in `print_pages`. win32ui is an MFC extension: `win32ui.pyd`
# links against `mfc140u.dll`, which lives beside it in site-packages/pythonwin
# rather than in a normal DLL directory. PyInstaller's dependency scan usually
# finds it; the explicit copy below is insurance, because the failure is
# "the exe starts fine, then cannot open a printer DC".
hiddenimports += [
    "win32print",
    "win32ui",
    "win32api",
    "win32con",
    "win32gui",
    "pywintypes",
    "pythoncom",
]


def pythonwin_mfc_dlls():
    """MFC runtime shipped inside pywin32, needed by win32ui.pyd."""
    for root in sys.path:
        candidate = os.path.join(root, "pythonwin")
        if not os.path.isdir(candidate):
            continue
        return [
            (os.path.join(candidate, name), ".")
            for name in os.listdir(candidate)
            if name.lower().startswith("mfc") and name.lower().endswith(".dll")
        ]
    return []


binaries += pythonwin_mfc_dlls()


# --- OCR ---------------------------------------------------------------------
#
# pytesseract is pure Python and does nothing but build a command line and run
# it. THE TESSERACT BINARY IS NOT BUNDLED. `tesseract.exe` is a separate MSI
# install on the station, and its path goes in `CameraConfig.tesseract_path`.
# See build.md; there is no way to make this exe self-contained for OCR.
#
# Pillow comes along because pytesseract converts the numpy frame through
# `PIL.Image.fromarray` before writing the temp file it hands to tesseract.
hiddenimports += ["pytesseract"]
collect("PIL")

# sqlite3 backs the offline outbox in agent/store.py. Stdlib, but it is an
# extension module plus a DLL, and a build that drops it loses every queued
# result the moment the network blips.
hiddenimports += ["sqlite3"]


# --- Excluded ----------------------------------------------------------------
#
# Size only, and kept deliberately short. The bundle is already ~130 MB (cv2.pyd
# alone is 70 MB) and onefile extracts all of it to %TEMP% on every start, on a
# machine with 4 GB of RAM. But an over-eager exclude is the same class of
# runtime-only failure as a missing hidden import, so only packages that nothing
# in the dependency tree touches belong here. `unittest` and `setuptools` in
# particular look tempting and are not safe: numpy reaches for both.
EXCLUDES = [
    "matplotlib",
    "scipy",
    "pandas",
    "IPython",
    "tkinter.test",
]


a = Analysis(
    [os.path.join(SPECPATH, "run_agent.py")],
    pathex=[SPECPATH],
    binaries=binaries,
    datas=datas,
    hiddenimports=hiddenimports,
    hookspath=[],
    hooksconfig={},
    runtime_hooks=[],
    excludes=EXCLUDES,
    win_no_prefer_redirects=False,
    win_private_assemblies=False,
    cipher=block_cipher,
    noarchive=False,
)

pyz = PYZ(a.pure, a.zipped_data, cipher=block_cipher)

# `console=True` on purpose. This is a tkinter app, so a console window is
# untidy, but the whole point of the first hardware run is to see the traceback
# when pypdfium2 refuses to load its DLL or Tk cannot find init.tcl, and with
# `console=False` those go nowhere at all. Flip it with BADGE_AGENT_WINDOWED=1
# once the station has actually printed cards.
#
# `upx=False` on purpose too: UPX-packed DLLs are a reliable antivirus false
# positive and have been known to corrupt pdfium.dll. A convention network is
# not the place to argue with an AV quarantine.
EXE_COMMON = dict(
    name=NAME,
    debug=False,
    bootloader_ignore_signals=False,
    strip=False,
    upx=False,
    console=not WINDOWED,
    disable_windowed_traceback=False,
    argv_emulation=False,
    target_arch=None,
    codesign_identity=None,
    entitlements_file=None,
)

if ONEDIR:
    exe = EXE(
        pyz,
        a.scripts,
        [],
        exclude_binaries=True,
        **EXE_COMMON
    )

    coll = COLLECT(
        exe,
        a.binaries,
        a.zipfiles,
        a.datas,
        strip=False,
        upx=False,
        name=NAME,
    )
else:
    exe = EXE(
        pyz,
        a.scripts,
        a.binaries,
        a.zipfiles,
        a.datas,
        [],
        runtime_tmpdir=None,
        **EXE_COMMON
    )
