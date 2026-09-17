-- Keep the original records for audit/recovery before merging identities.
CREATE TABLE IF NOT EXISTS character_encoding_backup AS
SELECT * FROM characters WHERE instr(character_key, char(65533)) > 0 OR instr(character_key, '?') > 0;
CREATE TABLE IF NOT EXISTS observation_encoding_backup AS
SELECT * FROM character_observations WHERE instr(character_key, char(65533)) > 0 OR instr(character_key, '?') > 0;
INSERT INTO character_encoding_backup SELECT c.* FROM characters c
WHERE (instr(c.character_key, char(65533)) > 0 OR instr(c.character_key, '?') > 0)
AND NOT EXISTS (SELECT 1 FROM character_encoding_backup b WHERE b.game=c.game AND b.source_game=c.source_game AND b.character_key=c.character_key);
INSERT INTO observation_encoding_backup SELECT o.* FROM character_observations o
WHERE (instr(o.character_key, char(65533)) > 0 OR instr(o.character_key, '?') > 0)
AND NOT EXISTS (SELECT 1 FROM observation_encoding_backup b WHERE b.game=o.game AND b.source_game=o.source_game AND b.character_key=o.character_key AND b.day=o.day);

DROP TABLE IF EXISTS character_encoding_matches;
CREATE TABLE character_encoding_matches AS
SELECT bad.game, bad.source_game, bad.character_key AS old_key,
       MIN(good.character_key) AS new_key
FROM characters bad JOIN characters good
  ON good.game = bad.game AND good.source_game = bad.source_game
  AND instr(good.character_key, char(65533)) = 0
  AND instr(good.character_key, '?') = 0
  AND good.character_key GLOB replace(replace(replace(bad.character_key, '??', '?'), '?' || char(65533), '?'), char(65533), '?')
  AND good.full_name GLOB replace(replace(replace(bad.full_name, '??', '?'), '?' || char(65533), '?'), char(65533), '?')
  AND good.realm = bad.realm AND good.race = bad.race AND good.class = bad.class
  AND (good.first_seen = bad.first_seen OR good.last_seen = bad.last_seen)
WHERE instr(bad.character_key, char(65533)) > 0 OR instr(bad.character_key, '?') > 0
GROUP BY bad.game, bad.source_game, bad.character_key
HAVING COUNT(*) = 1;

INSERT INTO character_observations
  (game, source_game, character_key, day, sightings, first_seen, last_seen)
SELECT o.game, o.source_game, m.new_key, o.day,
       MAX(o.sightings), MIN(o.first_seen), MAX(o.last_seen)
FROM character_observations o JOIN character_encoding_matches m
  ON m.game = o.game AND m.source_game = o.source_game AND m.old_key = o.character_key
GROUP BY o.game, o.source_game, m.new_key, o.day
HAVING 1
ON CONFLICT(game, source_game, character_key, day) DO UPDATE SET
  sightings = MAX(character_observations.sightings, excluded.sightings),
  first_seen = MIN(character_observations.first_seen, excluded.first_seen),
  last_seen = MAX(character_observations.last_seen, excluded.last_seen);

UPDATE characters AS target SET
  first_seen = MIN(target.first_seen, (SELECT MIN(b.first_seen) FROM characters b
    JOIN character_encoding_matches m ON m.game=b.game AND m.source_game=b.source_game AND m.old_key=b.character_key
    WHERE m.game=target.game AND m.source_game=target.source_game AND m.new_key=target.character_key)),
  last_seen = MAX(target.last_seen, (SELECT MAX(b.last_seen) FROM characters b
    JOIN character_encoding_matches m ON m.game=b.game AND m.source_game=b.source_game AND m.old_key=b.character_key
    WHERE m.game=target.game AND m.source_game=target.source_game AND m.new_key=target.character_key)),
  seen_count = MAX(target.seen_count, (SELECT MAX(b.seen_count) FROM characters b
    JOIN character_encoding_matches m ON m.game=b.game AND m.source_game=b.source_game AND m.old_key=b.character_key
    WHERE m.game=target.game AND m.source_game=target.source_game AND m.new_key=target.character_key))
WHERE EXISTS (SELECT 1 FROM character_encoding_matches m
  WHERE m.game=target.game AND m.source_game=target.source_game AND m.new_key=target.character_key);

DELETE FROM character_observations AS o WHERE EXISTS
  (SELECT 1 FROM character_encoding_matches m WHERE m.game=o.game AND m.source_game=o.source_game AND m.old_key=o.character_key);
DELETE FROM characters AS c WHERE EXISTS
  (SELECT 1 FROM character_encoding_matches m WHERE m.game=c.game AND m.source_game=c.source_game AND m.old_key=c.character_key);

SELECT game, source_game, COUNT(*) AS repaired FROM character_encoding_matches GROUP BY game, source_game;
SELECT game, source_game, COUNT(*) AS unresolved FROM characters
WHERE instr(character_key, char(65533)) > 0 OR instr(character_key, '?') > 0 GROUP BY game, source_game;
