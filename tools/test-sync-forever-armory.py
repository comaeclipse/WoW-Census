import importlib.util
import pathlib
import unittest

PATH = pathlib.Path(__file__).with_name("sync-forever-armory.py")
SPEC = importlib.util.spec_from_file_location("armory", PATH)
armory = importlib.util.module_from_spec(SPEC); SPEC.loader.exec_module(armory)


class ArmoryTests(unittest.TestCase):
    def test_slug_and_region(self):
        self.assertEqual(armory.slugify("Baldur Asmonson"), "baldur-asmonson")
        self.assertEqual(armory.region_from_realm("CAMWOW EU5 Realm 8 PvP"), "eu")
        self.assertIsNone(armory.region_from_realm("Unknown"))

    def test_url_uses_forever_namespace(self):
        url = armory.api_url("us", "baldur-asmonson", "equipment")
        self.assertIn("/baldur-asmonson/equipment", url)
        self.assertIn("namespace=profile-forever-us", url)

    def test_specializations_flatten_dual_groups(self):
        body = {"specialization_groups": [
            {"is_active": True, "specializations": [{"specialization": {"id": 1, "name": "Fire"},
              "specialization_name": "Fire", "spent_points": 31, "talents": [{"id": 10}, {"id": 11}]}]},
            {"is_active": False, "specializations": [{"specialization": {"id": 2},
              "specialization_name": "Frost", "spent_points": 20, "talents": []}]},
        ]}
        rows = armory.specialization_rows(body)
        self.assertEqual(len(rows), 3)
        self.assertEqual(rows[0][:7], (0, 0, True, 1, "Fire", 31, 0))
        self.assertEqual(rows[-1][4], "Frost")
        self.assertEqual(rows[-1][-1], {})

    def test_guild_url_uses_profile_namespace(self):
        url = armory.guild_url("eu", "the-guild")
        self.assertIn("/data/wow/guild/the-guild/roster", url)
        self.assertIn("namespace=profile-forever-eu", url)


if __name__ == "__main__":
    unittest.main()
