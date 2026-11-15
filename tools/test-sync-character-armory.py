import importlib.util
import pathlib
import unittest

PATH = pathlib.Path(__file__).with_name("sync-character-armory.py")
SPEC = importlib.util.spec_from_file_location("characters", PATH)
characters = importlib.util.module_from_spec(SPEC); SPEC.loader.exec_module(characters)


class CharacterArmoryTests(unittest.TestCase):
    def test_public_id_is_stable_numeric_and_identity_scoped(self):
        value = characters.public_id("retail", "us", "Anvilmar", "Ghuen")
        self.assertRegex(value, r"^\d{12}$")
        self.assertEqual(value, characters.public_id("retail", "US", "anvilmar", "GHUEN"))
        self.assertNotEqual(value, characters.public_id("retail", "us", "Stormrage", "Ghuen"))

    def test_urls_use_edition_namespace_and_internal_realm(self):
        url = characters.api_url("classic-progression", "eu", "spineshatter", "example", "character-media")
        self.assertIn("/spineshatter/example/character-media", url)
        self.assertIn("namespace=profile-classicann-eu", url)

    def test_slugify(self):
        self.assertEqual(characters.slugify("Defias Pillager"), "defias-pillager")


if __name__ == "__main__": unittest.main()
