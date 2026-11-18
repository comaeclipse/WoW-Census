#!/usr/bin/env python3
"""Build laragon-php/src/forever-dungeons.json for the WoW: Forever dungeon pages.

    python -B tools/build-forever-dungeons.py [--addon DIR] [--uploads DIR]

Two inputs:

* The Dungeon Journal add-on (ForeverDungeonJournal, by Exehn) as installed in the
  _forever_ client: Data/Dungeons.lua (dungeons, bosses, loot) and Data/Bosses.lua
  (boss levels). The files are read as data with a small Lua table reader; nothing
  in them is executed. Only facts are kept: names, levels, locations, NPC and item
  IDs, slots and qualities, the dungeon's one-line description. Quest text,
  guides and the add-on's notes are left out.
* Players' DungeonJournal companion uploads (pull-companion-uploads.py pull-addons
  stages them under MarketLensData/companion-uploads/addons-*/forever/dungeonjournal/).
  Each install's newest save says which bosses its characters have killed; the
  output counts characters per boss. Character names are not written.
"""

import argparse
import json
import re
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
OUT = REPO / "laragon-php" / "src" / "forever-dungeons.json"
ADDON = Path(r"C:\Program Files (x86)\World of Warcraft\_forever_\Interface\AddOns\ForeverDungeonJournal")
UPLOADS = Path.home() / "Documents" / "MarketLensData" / "companion-uploads"

# Dungeons WoW Classic shipped; every other dungeon in the journal is new in Forever.
CLASSIC = {
    "Ragefire Chasm", "The Deadmines", "Wailing Caverns", "Shadowfang Keep", "Blackfathom Deeps",
    "The Stockade", "Gnomeregan", "Razorfen Kraul", "Scarlet Monastery: Graveyard",
    "Scarlet Monastery: Library", "Scarlet Monastery: Armory", "Scarlet Monastery: Cathedral",
    "Razorfen Downs", "Uldaman", "Zul'Farrak", "Maraudon", "Sunken Temple", "Blackrock Depths",
    "Lower Blackrock Spire", "Upper Blackrock Spire", "Dire Maul: East", "Dire Maul: West",
    "Dire Maul: North", "Stratholme: Living", "Stratholme: Undead", "Scholomance",
}

# ---------------------------------------------------------------- Lua table reader

TOKEN = re.compile(r"""
    (?P<ws>\s+|--\[(?P<eq>=*)\[.*?\](?P=eq)\]|--[^\n]*)
  | (?P<str>"(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*')
  | (?P<lstr>\[(?P<leq>=*)\[.*?\](?P=leq)\])
  | (?P<num>0x[0-9a-fA-F]+|\d+\.?\d*(?:[eE][+-]?\d+)?|\.\d+)
  | (?P<name>[A-Za-z_][A-Za-z0-9_]*)
  | (?P<op>\.\.|==|~=|<=|>=|[{}\[\]=,;().:+\-*/#<>%^])
""", re.S | re.X)

ESC = {"n": "\n", "t": "\t", "r": "\r", "\\": "\\", '"': '"', "'": "'", "a": "\a", "b": "\b", "f": "\f", "v": "\v", "\n": "\n"}


def unquote(s):
    body, out, i = s[1:-1], [], 0
    while i < len(body):
        c = body[i]
        if c == "\\" and i + 1 < len(body):
            n = body[i + 1]
            if n.isdigit():
                m = re.match(r"\d{1,3}", body[i + 1:])
                out.append(chr(int(m.group(0))))
                i += 1 + len(m.group(0))
                continue
            out.append(ESC.get(n, n))
            i += 2
            continue
        out.append(c)
        i += 1
    return "".join(out)


def tokenize(text):
    pos, toks = 0, []
    while pos < len(text):
        m = TOKEN.match(text, pos)
        if not m:
            raise ValueError(f"unreadable Lua at offset {pos}: {text[pos:pos + 40]!r}")
        pos = m.end()
        if m.group("ws") is not None:
            continue
        if m.group("str") is not None:
            toks.append(("str", unquote(m.group("str"))))
        elif m.group("lstr") is not None:
            raw = m.group("lstr")
            k = len(m.group("leq")) + 2
            toks.append(("str", raw[k:-k].lstrip("\n")))
        elif m.group("num") is not None:
            v = m.group("num")
            toks.append(("num", int(v, 16) if v.startswith("0x") else (float(v) if re.search(r"[.eE]", v) else int(v))))
        elif m.group("name") is not None:
            toks.append(("name", m.group("name")))
        else:
            toks.append(("op", m.group("op")))
    return toks


class Reader:
    """Reads table constructors and literals. Anything else (a variable, a call,
    an expression) becomes None: the journal's data never needs to be evaluated."""

    def __init__(self, toks):
        self.t, self.i = toks, 0

    def peek(self, k=0):
        j = self.i + k
        return self.t[j] if j < len(self.t) else ("eof", None)

    def take(self):
        tok = self.peek()
        self.i += 1
        return tok

    def expect(self, kind, val=None):
        tok = self.take()
        if tok[0] != kind or (val is not None and tok[1] != val):
            raise ValueError(f"expected {val or kind}, got {tok}")
        return tok

    def value(self):
        kind, v = self.peek()
        if kind == "op" and v == "{":
            out = self.table()
        elif kind in ("str", "num"):
            self.take()
            out = v
        elif kind == "op" and v == "-" and self.peek(1)[0] == "num":
            self.take()
            out = -self.take()[1]
        elif kind == "name" and v in ("true", "false", "nil"):
            self.take()
            out = {"true": True, "false": False, "nil": None}[v]
        else:
            self.skip_expr()
            return None
        # A literal that continues into an expression ("a" .. b) is not data.
        if self.peek()[0] == "op" and self.peek()[1] not in (",", ";", "}", ")", "]"):
            self.skip_expr()
            return None
        return out

    def skip_expr(self):
        depth = 0
        while True:
            kind, v = self.peek()
            if kind == "eof":
                return
            if kind == "op" and v in "{([":
                depth += 1
            elif kind == "op" and v in "})]":
                if depth == 0:
                    return
                depth -= 1
            elif kind == "op" and v in (",", ";") and depth == 0:
                return
            self.take()

    def table(self):
        self.expect("op", "{")
        arr, obj = [], {}
        while not (self.peek()[0] == "op" and self.peek()[1] == "}"):
            kind, v = self.peek()
            if kind == "op" and v == "[":
                self.take()
                key = self.value()
                self.expect("op", "]")
                self.expect("op", "=")
                obj[key] = self.value()
            elif kind == "name" and self.peek(1) == ("op", "="):
                self.take()
                self.take()
                obj[v] = self.value()
            else:
                arr.append(self.value())
            if self.peek()[0] == "op" and self.peek()[1] in (",", ";"):
                self.take()
        self.expect("op", "}")
        if obj and arr:
            for n, x in enumerate(arr, 1):
                obj.setdefault(n, x)
            return obj
        return obj if obj else arr


def assignments(path):
    """Top-level `target = {...}` statements: [(target text, value)]."""
    toks = tokenize(path.read_text(encoding="utf-8"))
    r, out = Reader(toks), []
    while r.peek()[0] != "eof":
        if r.peek()[0] != "name":
            r.take()
            continue
        # An lvalue: name ( .name | ["str"] )*, e.g. FDJ.DB["X"] or DB["X"].
        lhs = r.take()[1]
        while True:
            if r.peek() == ("op", ".") and r.peek(1)[0] == "name":
                r.take()
                lhs += "." + r.take()[1]
            elif r.peek() == ("op", "[") and r.peek(1)[0] == "str" and r.peek(2) == ("op", "]"):
                r.take()
                lhs += "[" + json.dumps(r.take()[1], ensure_ascii=False) + "]"
                r.take()
            else:
                break
        if r.peek() == ("op", "=") and r.peek(1) == ("op", "{"):
            r.take()
            out.append((lhs, r.table()))
    return out


# ---------------------------------------------------------------- the journal

def slugify(name):
    return re.sub(r"[^a-z0-9]+", "-", name.lower().replace("'", "")).strip("-")


def icon_name(path):
    return (path or "").replace("\\", "/").rsplit("/", 1)[-1].lower() or None


def loot_rows(rows):
    out = []
    for row in rows or []:
        if not isinstance(row, list) or len(row) < 2 or not isinstance(row[0], int):
            continue
        out.append({
            "id": row[0], "name": row[1],
            "slot": row[2] if len(row) > 2 and isinstance(row[2], str) else "",
            "quality": row[3] if len(row) > 3 and isinstance(row[3], int) else 1,
            **({"from": row[4]} if len(row) > 4 and isinstance(row[4], str) else {}),
        })
    return out


def read_journal(addon):
    db, order, levels = {}, [], {}
    for target, value in assignments(addon / "Data" / "Dungeons.lua"):
        if target == "FDJ.DB":
            db.update(value)
        elif (m := re.fullmatch(r'(?:FDJ\.)?DB\[(".*")\]', target)):
            db[json.loads(m.group(1))] = value
        elif target == "FDJ.ORDER":
            order = [x for x in value if isinstance(x, str)]
    for target, value in assignments(addon / "Data" / "Bosses.lua"):
        if target == "FDJ.BOSS_LEVELS":
            levels = value
    version = None
    toc = addon / "ForeverDungeonJournal.toc"
    if toc.exists() and (m := re.search(r"^## Version:\s*(\S+)", toc.read_text(encoding="utf-8"), re.M)):
        version = m.group(1)

    dungeons = []
    names = order + sorted(n for n in db if n not in order)
    for pos, name in enumerate(names):
        d = db.get(name)
        if not isinstance(d, dict):
            continue
        bosses, trash = [], []
        for b in d.get("bosses") or []:
            if not isinstance(b, dict):
                continue
            loot = loot_rows(b.get("loot"))
            if b.get("trash"):
                trash.extend(loot)
                continue
            lvl = (levels.get(name) or {}).get(b.get("name"))
            bosses.append({
                "name": b.get("name"), "npcID": b.get("npcID") if isinstance(b.get("npcID"), int) else None,
                "level": str(lvl) if lvl else None, "rare": bool(b.get("rare")),
                "description": b.get("description") if isinstance(b.get("description"), str) else "",
                "loot": loot,
            })
        dungeons.append({
            "name": name, "slug": slugify(name), "order": pos,
            "level": d.get("level") or "", "location": d.get("location") or "",
            "description": d.get("description") or "", "icon": icon_name(d.get("icon")),
            "new": name not in CLASSIC, "lootIncomplete": bool(d.get("fullItemsTBD")),
            "quests": len(d.get("quests") or []),
            "bosses": bosses, "trash": trash,
        })
    return dungeons, version


# ---------------------------------------------------------------- players' kills

def read_kills(uploads):
    """{dungeon: {boss key: characters}} from each install's newest per-character save."""
    newest = {}
    for manifest in sorted(uploads.glob("addons-*/forever/dungeonjournal/*.json")):
        try:
            m = json.loads(manifest.read_text(encoding="utf-8"))
        except (OSError, ValueError):
            continue
        lua = manifest.with_suffix(".lua")
        if not lua.exists():
            continue
        key = m.get("sourceId")
        if key and (key not in newest or m.get("fileModTime", "") > newest[key][0]):
            newest[key] = (m.get("fileModTime", ""), lua)
    kills, chars = {}, 0
    for _, lua in newest.values():
        for target, value in assignments(lua):
            if target != "DungeonJournal" or not isinstance(value, dict) or "character" not in value:
                continue
            boss_tables = (value.get("characterData") or {}).get("rpgBosses") or {}
            if not boss_tables:
                continue
            chars += 1
            for dungeon, seen in boss_tables.items():
                if not isinstance(seen, dict):
                    continue
                for k, v in seen.items():
                    if v:
                        kills.setdefault(dungeon, {}).setdefault(str(k).lower(), 0)
                        kills[dungeon][str(k).lower()] += 1
    return kills, chars


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--addon", type=Path, default=ADDON)
    ap.add_argument("--uploads", type=Path, default=UPLOADS)
    ap.add_argument("--out", type=Path, default=OUT)
    args = ap.parse_args()

    dungeons, version = read_journal(args.addon)
    kills, chars = read_kills(args.uploads)
    for d in dungeons:
        seen = kills.get(d["name"], {})
        for b in d["bosses"]:
            by_id = seen.get(str(b["npcID"])) if b["npcID"] else None
            b["killedBy"] = max(by_id or 0, seen.get((b["name"] or "").lower(), 0))
    out = {
        "source": {"addon": "Dungeon Journal", "author": "Exehn", "version": version},
        "uploaders": chars,
        "dungeons": dungeons,
    }
    args.out.write_text(json.dumps(out, ensure_ascii=False, indent=1) + "\n", encoding="utf-8")
    bosses = sum(len(d["bosses"]) for d in dungeons)
    items = sum(len(b["loot"]) for d in dungeons for b in d["bosses"]) + sum(len(d["trash"]) for d in dungeons)
    print(f"dungeons={len(dungeons)} new={sum(d['new'] for d in dungeons)} bosses={bosses} loot={items} "
          f"uploaders={chars} addon={version} -> {args.out}")


if __name__ == "__main__":
    sys.exit(main())
