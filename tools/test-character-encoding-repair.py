import sqlite3
from pathlib import Path

root = Path(__file__).resolve().parents[1]
db = sqlite3.connect(":memory:")
db.executescript((root / "site/migrate-characters.sql").read_text(encoding="utf-8"))


def character(game, key, first=100, last=200, count=2):
    db.execute(
        "INSERT INTO characters VALUES (?, 'classic', ?, ?, ?, 'Windseeker', '', 32, 'Human', 'Paladin', 'PALADIN', '', ?, ?, ?)",
        (game, key, key, key, first, last, count),
    )


for game in ("realm:A-Alliance", "realm:B-Horde"):
    character(game, "classic:windseeker:relann\ufffd", last=300, count=4)
    character(game, "classic:windseeker:relann\u00ef")
    db.execute("INSERT INTO character_observations VALUES (?, 'classic', ?, 1, 4, 100, 300)", (game, "classic:windseeker:relann\ufffd"))
    db.execute("INSERT INTO character_observations VALUES (?, 'classic', ?, 1, 2, 100, 200)", (game, "classic:windseeker:relann\u00ef"))
    db.execute("INSERT INTO character_observations VALUES (?, 'classic', ?, 2, 1, 300, 300)", (game, "classic:windseeker:relann\ufffd"))

# Both an ambiguous accent and an unknown name must remain unresolved.
character("realm:A-Alliance", "ambiguous\ufffd")
character("realm:A-Alliance", "ambiguous\u00ef")
character("realm:A-Alliance", "ambiguous\u00e9")
character("realm:A-Alliance", "unknown\ufffd")
character("realm:A-Alliance", "older?\ufffd")
character("realm:A-Alliance", "older\u00e9")
character("realm:A-Alliance", "two?\ufffd?\ufffd")
character("realm:A-Alliance", "two\u00ea\u00ea")
character("realm:A-Alliance", "mixed??x?\ufffd?\ufffd")
character("realm:A-Alliance", "mixed\u00fcx\u00ef\u00eb")
character("realm:A-Alliance", "bounds\ufffd", first=50)
character("realm:A-Alliance", "bounds\u00f4")
character("realm:A-Alliance", "plain??")
character("realm:A-Alliance", "plain\u00fc")
repair = (root / "site/repair-character-encoding.sql").read_text(encoding="utf-8")
db.executescript(repair)
assert db.execute("SELECT COUNT(*) FROM character_encoding_matches").fetchone()[0] == 7
for game in ("realm:A-Alliance", "realm:B-Horde"):
    assert db.execute("SELECT first_seen,last_seen,seen_count FROM characters WHERE game=? AND character_key=?", (game, "classic:windseeker:relann\u00ef")).fetchone() == (100, 300, 4)
    assert db.execute("SELECT day,sightings FROM character_observations WHERE game=? ORDER BY day", (game,)).fetchall() == [(1, 4), (2, 1)]
assert db.execute("SELECT COUNT(*) FROM character_encoding_backup").fetchone()[0] == 9
assert db.execute("SELECT COUNT(*) FROM observation_encoding_backup").fetchone()[0] == 4
assert db.execute("SELECT COUNT(*) FROM characters WHERE instr(character_key,char(65533))>0").fetchone()[0] == 2
before = db.execute("SELECT * FROM character_observations ORDER BY game,day").fetchall()
db.executescript(repair)
assert db.execute("SELECT * FROM character_observations ORDER BY game,day").fetchall() == before
print("Encoding repair passed: all realms, history preservation, ambiguity, backups, idempotence")
