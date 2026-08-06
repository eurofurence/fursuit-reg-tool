"""Config round-tripping and the multi-printer model.

A station can drive several printers: two card printers for throughput plus a
thermal receipt printer. Each carries its own SNMP address and camera.
"""

import json
import os
import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from agent import config as cfg  # noqa: E402


def sample() -> cfg.AgentConfig:
    left = cfg.PrinterBinding(
        name="ZXP Series 9", role=cfg.ROLE_CARD, label="Card printer left",
        snmp_host="10.0.0.92",
    )
    left.camera.enabled = True
    left.camera.zones = [
        cfg.Zone(name="card", purpose=cfg.ZONE_CARD, x=0.1, y=0.2, width=0.5, height=0.4),
        ]
    left.camera.checkpoints = [
        cfg.Checkpoint(name="tray", purpose=cfg.POINT_TRAY_FULL, x=0.8, y=0.7,
                       reference_hue=120.0, reference_saturation=0.6, calibrated=True),
    ]

    right = cfg.PrinterBinding(name="ZXP Series 9 (2)", role=cfg.ROLE_CARD,
                               label="Card printer right", snmp_host="10.0.0.93")
    receipt = cfg.PrinterBinding(name="TM-T88", role=cfg.ROLE_RECEIPT, label="Receipts")

    return cfg.AgentConfig(
        server_url="https://example.test",
        api_token="secret",
        printers=[left, right, receipt],
    )


class MultiPrinterTest(unittest.TestCase):
    def test_separates_card_and_receipt_printers(self):
        config = sample()

        self.assertEqual(len(config.card_printers()), 2)
        self.assertEqual(len(config.receipt_printers()), 1)
        self.assertEqual(config.receipt_printers()[0].name, "TM-T88")

    def test_disabled_printers_are_not_worked(self):
        config = sample()
        config.printers[0].enabled = False

        self.assertEqual(len(config.card_printers()), 1)

    def test_finds_a_printer_by_name(self):
        config = sample()

        self.assertIsNotNone(config.printer("TM-T88"))
        self.assertIsNone(config.printer("Not Installed"))

    def test_each_card_printer_keeps_its_own_snmp_host(self):
        config = sample()
        hosts = [p.snmp_host for p in config.card_printers()]
        self.assertEqual(hosts, ["10.0.0.92", "10.0.0.93"])

    def test_display_name_falls_back_to_the_windows_name(self):
        self.assertEqual(cfg.PrinterBinding(name="Raw Name").display_name(), "Raw Name")
        self.assertEqual(
            cfg.PrinterBinding(name="Raw Name", label="Left").display_name(), "Left")

    def test_not_configured_without_printers(self):
        config = cfg.AgentConfig(server_url="https://x", api_token="t")
        self.assertFalse(config.is_configured())

        config.printers = [cfg.PrinterBinding(name="ZXP")]
        self.assertTrue(config.is_configured())


class RoundTripTest(unittest.TestCase):
    def setUp(self):
        self.dir = tempfile.TemporaryDirectory()
        os.environ["APPDATA"] = self.dir.name

    def tearDown(self):
        os.environ.pop("APPDATA", None)
        self.dir.cleanup()

    def test_survives_a_save_and_load(self):
        sample().save()

        loaded = cfg.AgentConfig.load()

        self.assertEqual(loaded.server_url, "https://example.test")
        self.assertEqual(len(loaded.printers), 3)

        left = loaded.printer("ZXP Series 9")
        self.assertTrue(left.camera.enabled)
        self.assertEqual(len(left.camera.zones), 1)
        self.assertEqual(len(left.camera.checkpoints), 1)

    def test_calibration_geometry_survives(self):
        sample().save()

        zone = cfg.AgentConfig.load().printer("ZXP Series 9").camera.zones[0]

        self.assertAlmostEqual(zone.x, 0.1)
        self.assertAlmostEqual(zone.height, 0.4)
        self.assertEqual(zone.purpose, cfg.ZONE_CARD)

    def test_checkpoint_reference_colour_survives(self):
        sample().save()

        point = cfg.AgentConfig.load().printer("ZXP Series 9").camera.checkpoints[0]

        self.assertTrue(point.calibrated)
        self.assertAlmostEqual(point.reference_hue, 120.0)
        self.assertAlmostEqual(point.reference_saturation, 0.6)

    def test_zone_converts_to_pixels(self):
        zone = cfg.Zone(x=0.5, y=0.25, width=0.25, height=0.5)
        self.assertEqual(zone.pixels(800, 400), (400, 100, 200, 200))

    def test_a_corrupt_config_does_not_stop_startup(self):
        path = Path(self.dir.name) / cfg.APP_DIR_NAME / cfg.CONFIG_FILENAME
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text("{not json at all")

        # The operator can retype settings faster than they can repair JSON.
        self.assertEqual(cfg.AgentConfig.load().printers, [])

    def test_unknown_keys_from_an_older_version_are_ignored(self):
        path = Path(self.dir.name) / cfg.APP_DIR_NAME / cfg.CONFIG_FILENAME
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(json.dumps({
            "server_url": "https://example.test",
            "card_printer": "left over from the old single-printer config",
            "printers": [{"name": "ZXP", "role": "card", "obsolete_field": 1}],
        }))

        loaded = cfg.AgentConfig.load()

        self.assertEqual(loaded.server_url, "https://example.test")
        self.assertEqual(len(loaded.printers), 1)
        self.assertEqual(loaded.printers[0].name, "ZXP")

    def test_zones_can_be_selected_by_purpose(self):
        camera = sample().printer("ZXP Series 9").camera

        self.assertEqual(len(camera.zones_for(cfg.ZONE_CARD)), 1)
        self.assertEqual(len(camera.zones_for("nonexistent")), 0)
        self.assertEqual(len(camera.checkpoints_for(cfg.POINT_TRAY_FULL)), 1)


if __name__ == "__main__":
    unittest.main()
