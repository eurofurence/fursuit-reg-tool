"""Guards on the PyInstaller build, checked without running PyInstaller.

The build itself only happens on Windows, and the thing it produces can only be
judged on the station. What can be checked anywhere is the part that goes wrong
quietly: a module that stops being reachable from the entry point and is not
listed as a hidden import gets silently dropped from the bundle, and the exe
still builds. The failure surfaces the first time somebody opens that screen.

So these tests read `badge-print-agent.spec` as text, walk the import graph of
`run_agent.py` as source, and compare both against what is actually on disk.
"""

import ast
import os
import sys
import unittest

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, ROOT)

SPEC = os.path.join(ROOT, "badge-print-agent.spec")
REQUIREMENTS = os.path.join(ROOT, "requirements.txt")
ENTRY_POINT = "run_agent"
PACKAGE = "agent"


def spec_source() -> str:
    with open(SPEC, "r") as handle:
        return handle.read()


def spec_tree() -> ast.Module:
    return ast.parse(spec_source(), filename=SPEC)


def spec_literal(name: str):
    """The value of a module-level `NAME = <literal>` assignment in the spec."""
    for node in spec_tree().body:
        if not isinstance(node, ast.Assign):
            continue
        for target in node.targets:
            if isinstance(target, ast.Name) and target.id == name:
                return ast.literal_eval(node.value)
    raise AssertionError("%s is not assigned a literal in badge-print-agent.spec" % name)


def modules_on_disk():
    """Every importable module under `agent/`, by dotted name."""
    found = set()

    for dirpath, dirnames, filenames in os.walk(os.path.join(ROOT, PACKAGE)):
        dirnames[:] = [d for d in dirnames if d != "__pycache__"]

        for filename in filenames:
            if not filename.endswith(".py"):
                continue

            relative = os.path.relpath(os.path.join(dirpath, filename), ROOT)
            parts = relative[:-len(".py")].split(os.sep)

            if parts[-1] == "__init__":
                parts = parts[:-1]

            found.add(".".join(parts))

    return found


def path_for(module: str):
    """Source file for a dotted module name, or None if it is not ours."""
    base = os.path.join(ROOT, *module.split("."))

    for candidate in (base + ".py", os.path.join(base, "__init__.py")):
        if os.path.isfile(candidate):
            return candidate

    return None


def imports_in(module: str, source: str):
    """Dotted names a module imports, including imports inside functions.

    Resolves relative imports, and treats `from . import x` as importing the
    submodule `x` as well as the name `x`, because that is how the agent's UI
    reaches the worker and the printing path.
    """
    parts = module.split(".")
    package = parts if path_for(module) and path_for(module).endswith("__init__.py") else parts[:-1]

    names = set()

    for node in ast.walk(ast.parse(source, filename=module)):
        if isinstance(node, ast.Import):
            for alias in node.names:
                names.add(alias.name)

        elif isinstance(node, ast.ImportFrom):
            if node.level == 0:
                base = ""
            else:
                trimmed = package[:len(package) - (node.level - 1)]
                base = ".".join(trimmed)

            if node.module:
                base = "%s.%s" % (base, node.module) if base else node.module

            if base:
                names.add(base)

            # `from .. import worker` and `from .config import config_dir` look
            # identical here; the caller filters to names that exist on disk.
            for alias in node.names:
                names.add("%s.%s" % (base, alias.name) if base else alias.name)

    return names


def reachable_modules():
    """Modules the frozen import graph reaches, starting at run_agent.py."""
    seen = set()
    queue = [ENTRY_POINT]

    while queue:
        module = queue.pop()

        if module in seen:
            continue

        path = path_for(module)

        if path is None:
            continue

        seen.add(module)

        with open(path, "r") as handle:
            source = handle.read()

        for name in imports_in(module, source):
            if name not in seen and path_for(name) is not None:
                queue.append(name)

    return seen


def requirement_lines():
    with open(REQUIREMENTS, "r") as handle:
        for number, line in enumerate(handle, start=1):
            stripped = line.strip()
            if stripped and not stripped.startswith("#"):
                yield number, stripped


class SpecFileTest(unittest.TestCase):
    def test_spec_exists(self):
        self.assertTrue(os.path.isfile(SPEC), "badge-print-agent.spec is missing")

    def test_spec_parses_as_python(self):
        # A spec file is executed by PyInstaller, so a syntax error is a failed
        # build rather than a failed import, and only shows up on the Windows
        # runner. Catch it here instead.
        spec_tree()

    def test_spec_compiles(self):
        # ast.parse accepts a few things compile() rejects, e.g. a `return`
        # outside a function.
        compile(spec_source(), SPEC, "exec")

    def test_entry_point_is_run_agent(self):
        self.assertIn("run_agent.py", spec_source())


class AgentModuleCoverageTest(unittest.TestCase):
    """No module under agent/ may fall out of the bundle unnoticed."""

    def test_every_module_is_reachable_or_declared(self):
        declared = set(spec_literal("AGENT_MODULES"))
        reachable = reachable_modules()

        missing = sorted(
            module for module in modules_on_disk()
            if module not in reachable and module not in declared
        )

        self.assertEqual(missing, [], (
            "These modules are neither imported from run_agent.py nor listed in "
            "AGENT_MODULES in badge-print-agent.spec, so PyInstaller will leave "
            "them out of the build: %s" % ", ".join(missing)
        ))

    def test_declared_modules_all_exist(self):
        # A stale entry is harmless to the build but hides a rename, which is
        # how the previous name quietly stops being collected.
        stale = sorted(set(spec_literal("AGENT_MODULES")) - modules_on_disk())
        self.assertEqual(stale, [], "AGENT_MODULES lists modules that do not exist: %s" % stale)

    def test_the_package_itself_is_declared(self):
        self.assertIn(PACKAGE, spec_literal("AGENT_MODULES"))

    def test_graph_walk_finds_the_ui(self):
        # Guard on the test rather than the spec: if the walker ever stops
        # following imports, every other assertion here passes vacuously.
        reachable = reachable_modules()
        self.assertIn("agent.ui.app", reachable)
        self.assertIn("agent.worker", reachable, "function-level imports are not being followed")
        self.assertIn("agent.render", reachable)


class RuntimeDependencyTest(unittest.TestCase):
    """The collections that fail at runtime rather than at build time."""

    def test_native_and_dynamic_dependencies_are_collected(self):
        source = spec_source()

        for package, why in [
            ("pypdfium2_raw", "the bundled pdfium.dll, loaded by ctypes"),
            ("cv2", "the OpenCV extension and its config-3.py loader shim"),
            ("pysnmp.smi.mibs", "MIB modules, read off disk by filename"),
            ("tkinter", "the Tcl/Tk runtime"),
            ("win32print", "spooler access"),
            ("pytesseract", "OCR, which shells out to a separate tesseract.exe"),
        ]:
            self.assertIn(package, source, "%s is not collected: %s" % (package, why))

    def test_tesseract_is_documented_as_a_separate_install(self):
        # It cannot be bundled, so the only defence is that the spec says so.
        self.assertIn("NOT BUNDLED", spec_source())


class RequirementsTest(unittest.TestCase):
    def test_every_requirement_is_pinned(self):
        unpinned = [
            "%s:%d %s" % (os.path.basename(REQUIREMENTS), number, line)
            for number, line in requirement_lines()
            if "==" not in line
        ]

        self.assertEqual(unpinned, [], (
            "Every dependency has to be pinned: the station is Windows 7 and a "
            "resolver free to pick a newer wheel will pick one that does not "
            "load there. Unpinned: %s" % "; ".join(unpinned)
        ))

    def test_pyinstaller_stays_on_the_windows_7_capable_line(self):
        # PyInstaller 6.x rebuilt its bootloader and dropped Windows 7. Bumping
        # this is a decision, not a routine dependency update.
        pins = dict(
            line.split("==", 1) for _, line in requirement_lines() if "==" in line
        )

        self.assertIn("pyinstaller", pins)
        self.assertTrue(
            pins["pyinstaller"].startswith("5."),
            "pyinstaller is pinned to %s; 6.x does not run on Windows 7" % pins["pyinstaller"],
        )


if __name__ == "__main__":
    unittest.main()
