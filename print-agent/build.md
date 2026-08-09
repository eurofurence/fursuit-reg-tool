# Building the print agent

Packages `run_agent.py` and the `agent/` package into a Windows executable so the print station
needs no Python install. The recipe is `badge-print-agent.spec`; CI runs it in
`.github/workflows/print-agent.yml`.

Two outputs, from the same spec:

| Build | Command | Result |
|---|---|---|
| one file | `py -3.8 -m PyInstaller --clean --noconfirm badge-print-agent.spec` | `dist\BadgePrintAgent.exe` |
| one folder | `set BADGE_AGENT_ONEDIR=1` first | `dist\BadgePrintAgent\BadgePrintAgent.exe` |

The one-file build unpacks about 130 MB into `%TEMP%` on every start. On the station (4 GB of RAM,
spinning disk) that is a few seconds of startup and a large, unsigned executable for antivirus to
form an opinion about. The one-folder build has identical contents and does neither. Ship the single
file; keep the folder build as the fallback.

## Prerequisites on the station

1. **Windows 7 SP1 with KB2999226** (the Universal C Runtime update). Without it none of the binary
   wheels load, and every later failure is a red herring.
2. **Visual C++ 2015-2022 redistributable**, x64.
3. **Python 3.8.10, the official python.org amd64 installer.** 3.8.10 is the last release with a
   Windows installer, and 3.9 dropped Windows 7. Tick "tcl/tk and IDLE": the agent UI is tkinter, and
   PyInstaller can only bundle a Tcl/Tk that is present.
4. **Tesseract**, if you want OCR verification. It is a separate MSI and is not bundled, for the
   reason in the next section. Put its path in `CameraConfig.tesseract_path`.

`tesseract.exe` cannot go in the executable. `pytesseract` builds a command line and runs a separate
binary; there is nothing to freeze. Camera OCR is optional and printing never depends on it, so an
agent without Tesseract prints cards and skips OCR verification.

## Build

```bat
cd print-agent
py -3.8 -m pip install -r requirements.txt
py -3.8 -m PyInstaller --clean --noconfirm badge-print-agent.spec
```

Check the interpreter before anything else:

```bat
py -3.8 -VV
```

It must say `3.8.10` and `MSC v.1928 64 bit (AMD64)`. PyInstaller copies this interpreter's
`python38.dll` into the executable, so whatever Python you build with is the Python the station runs.

Two environment variables change the output:

- `BADGE_AGENT_ONEDIR=1`: one folder instead of one file.
- `BADGE_AGENT_WINDOWED=1`: no console window. Leave this off until the station has printed real
  cards. The console is where the traceback goes when pdfium refuses to load or Tk cannot find its
  script tree, and with a windowed build those go nowhere.

Warnings during the build are normal and mostly harmless. The ones worth reading are in
`build\BadgePrintAgent\warn-BadgePrintAgent.txt`; `missing module named ...` for anything under
`agent`, `pypdfium2`, `cv2`, `pysnmp` or `win32` is not harmless.

## Verify the executable starts

Order matters. The first check settles the biggest unknown, so do not skip to printing.

```bat
dist\BadgePrintAgent.exe
```

The window opens and the console stays quiet. If the console prints a traceback, read it: an exe that
built cleanly and dies on launch is almost always a collection that the spec missed.

Then confirm the bundled dependencies loaded, which the frozen build cannot be asked directly. Run
the same checks against the interpreter you built with:

```bat
py -3.8 -c "import pypdfium2; print(pypdfium2.V_PYPDFIUM2)"
py -3.8 -c "import cv2; print(cv2.__version__)"
py -3.8 -c "import win32print, win32ui; print(len(win32print.EnumPrinters(2)))"
py -3.8 -c "from pysnmp.hlapi import SnmpEngine; SnmpEngine(); print('ok')"
```

If those pass against the interpreter but the exe fails, the problem is the spec, not the wheel.

## Smoke test order

From "First run on the Windows box" in `docs/printing-implementation.md`. Work through it in order.

1. **`import pypdfium2` first, before anything else.** `pypdfium2==4.30.0` bundles a pdfium built
   from a Chromium tree without Windows 7 support, so the DLL may refuse to load outright. This is
   the largest single risk in the dependency set and it is cheap to settle. If it fails, drop to
   pypdfium2 2.x or 3.x, which bundles a pre-M110 build and needs a small change to
   `agent/render.py`.
2. Render one real badge PDF with `render_pdf()` and look at the bitmap before a printer is involved.
3. `list_printers()`, then one card through `print_pages()`. If `pywin32==228` misbehaves, try 302.
4. SNMP against the live printer, then induce faults one at a time (cover open, ribbon out, empty
   hopper, jam) with `tools/snmp_probe.py` running.
5. Camera last. Printing must never depend on it.

Do all of this against the plain Python install, then repeat 2 to 5 through the built executable. A
dependency that works unfrozen and fails frozen is a missing entry in the spec, and the spec comments
say which entry covers what.

## Can CI target Windows 7?

Not with certainty. The workflow produces a build that should run on Windows 7 and cannot prove it.

What is settled:

- **`windows-2019` is gone.** Deprecation began 2025-06-01, full retirement 2025-06-30. The oldest
  GitHub-hosted Windows image available is `windows-2022`, which the workflow uses.
- **No hosted runner runs Windows 7**, and none will. Nothing in CI can execute the artifact on the
  target OS.
- **The bootloader is fine.** PyInstaller ships prebuilt bootloaders in the wheel, so the build
  machine's OS does not affect it. PyInstaller 5.13.2 is the last line whose bootloader targets
  Windows 7; 6.x rebuilt it against a newer toolchain and dropped Windows 7. Do not bump that pin
  casually, and note that upstream describes Windows 7 as working but unsupported even for 5.x.
- **The wheels are identical either way.** They come from PyPI, not from the runner.

What is not settled, and is the reason to keep the local build path:

- **The interpreter DLL travels with the exe.** PyInstaller bundles the build machine's
  `python38.dll` and its `vcruntime140.dll`. The workflow installs the official python.org 3.8.10
  amd64 build rather than using `actions/setup-python`, whose Windows 3.8 is a rebuild by
  `actions/python-versions` against a current MSVC toolchain. Same version number, different binary,
  and the difference is exactly the kind that shows up as a Windows 7 load failure.
- **UCRT forwarder DLLs.** An exe frozen on a modern Windows and run on Windows 7 can fail with
  `api-ms-win-crt-runtime-l1-1-0.dll` missing or `ucrtbase.dll` entry point errors. KB2999226 on the
  station is the fix and is a prerequisite above, but whether a `windows-2022` build trips it can
  only be found out by running the exe on Windows 7.

Practical options, in order of preference:

1. **Use CI, verify on the station.** Build on `windows-2022`, then run the smoke tests above before
   the machine is trusted with a print run. This is what the workflow assumes.
2. **Build on the station.** Everything in this document works there, and it removes the build/target
   mismatch entirely. This is the fallback if the CI artifact fails to launch on Windows 7, and the
   route to take if a build is needed during the convention.
3. **A self-hosted Windows 7 runner.** Removes the mismatch and keeps CI, at the cost of maintaining
   an out-of-support machine with a GitHub Actions runner on it. The runner agent's own .NET and TLS
   requirements make this awkward.

## Troubleshooting

| Symptom | Cause |
|---|---|
| `Could not find library 'pdfium'` | `pdfium.dll` is not at `pypdfium2_raw/pdfium.dll` in the bundle. The spec collects it with `collect_dynamic_libs("pypdfium2_raw")`. |
| `FileNotFoundError: version.json` on `import pypdfium2` | Data files for `pypdfium2` and `pypdfium2_raw` were not collected. |
| `import cv2` fails in the exe, works unfrozen | `cv2/config-3.py` is missing. It has a hyphen in the name, so it is a data file, not a module. |
| Every printer reads as `unknown`, SNMP returns nothing | The pysnmp MIB files under `pysnmp/smi/mibs/` were not shipped as data. pysnmp reads them off disk by filename rather than importing them. |
| `Can't find a usable init.tcl` when the window opens | The Tcl/Tk script tree was not bundled. Most often the build interpreter has no tkinter. |
| Cannot open a printer DC, exe otherwise fine | `mfc140u.dll` is missing next to `win32ui.pyd`. |
| Antivirus quarantines the exe | Expected for a large unsigned one-file build. Use the one-folder build and add an exclusion. |
| `api-ms-win-crt-runtime-l1-1-0.dll is missing` on launch | KB2999226 is not installed on the station, or the build machine's UCRT is newer than the station's. |
