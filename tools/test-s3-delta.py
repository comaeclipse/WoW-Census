import importlib.util
import sys
import tempfile
import unittest
from pathlib import Path

spec = importlib.util.spec_from_file_location("deploy_s3", Path(__file__).with_name("deploy-s3.py"))
deploy = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = deploy
spec.loader.exec_module(deploy)


class S3DeltaTest(unittest.TestCase):
    def test_only_changed_content_and_missing_route_copy_are_uploaded(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            (root / "index.html").write_text("home", encoding="utf-8")
            (root / "combos.html").write_text("new!", encoding="utf-8")
            (root / "census.css").write_text("layout", encoding="utf-8")
            (root / "style.123456789abc.css").write_text("base", encoding="utf-8")
            (root / "_headers").write_text("headers", encoding="utf-8")
            local = list(deploy.objects(root))
            self.assertEqual({obj.key for obj in local},
                             {"index.html", "combos.html", "combos", "census.css", "style.123456789abc.css"})
            remote = {obj.key: f'"{deploy.md5(obj.source)}"' for obj in local}
            remote["combos.html"] = '"different-content-same-size"'
            del remote["combos"]
            self.assertEqual({obj.key for obj in deploy.changed(local, remote)}, {"combos.html", "combos"})
            self.assertEqual(next(obj for obj in local if obj.key == "census.css").cache_control, deploy.SHORT)
            self.assertEqual(next(obj for obj in local if obj.key.endswith("123456789abc.css")).cache_control,
                             deploy.LONG)

    def test_css_only_edit_plans_one_upload(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            (root / "index.html").write_text("<link href='/census.css'>", encoding="utf-8")
            css = root / "census.css"
            css.write_text(".combocard{padding:10px}", encoding="utf-8")
            local = list(deploy.objects(root))
            remote = {obj.key: deploy.md5(obj.source) for obj in local}
            css.write_text(".combocard{padding:11px}", encoding="utf-8")
            self.assertEqual([obj.key for obj in deploy.changed(local, remote)], ["census.css"])


if __name__ == "__main__":
    unittest.main()
