# Ingest collected saves into local PostgreSQL

This runbook covers deduplication, validation, and **local** ingestion. The target is `marketlens_local` on `127.0.0.1:5433`, schema `marketlens_import`. Publishing to wowcensus.io is a separate step that needs the user's go-ahead: `python -B tools/import-live.py saves --game-root "$stage\game"` runs the step 4 import locally and then against the live database over one SSH tunnel (see [upload.md](upload.md) step 5). Use it instead of step 4 when the import should also go live. Run commands from the repository root. Do not execute Lua files as code: the exporters parse their SavedVariables tables as data.

## 1. Identify and deduplicate files

Record each supplied file's path, byte size, modified time, and SHA-256. Check the hash against `marketlens_import.local_source_files` before staging it. A matching hash is already ingested, regardless of filename; leave that file out of the import. Different hashes may still contain overlapping character observations, which the importer deduplicates by its data keys.

```powershell
$save = 'C:\path\to\wow-forever-YYYYMMDD-HHMMSS.lua'
$hash = (Get-FileHash -LiteralPath $save -Algorithm SHA256).Hash.ToLowerInvariant()
Get-Item -LiteralPath $save | Select-Object FullName, Length, LastWriteTime
& 'C:\laragon\bin\postgresql\postgresql\bin\psql.exe' -X -At -h 127.0.0.1 -p 5433 -U skarz -d marketlens_local -c "SELECT relative_path FROM marketlens_import.local_source_files WHERE sha256 = '$hash'"
```

For a batch, repeat the hash check for **every** file and stage only hashes with no ledger row. If PostgreSQL is unavailable, restore access before deciding whether a file is new; a filename or earlier chat result is not a substitute for the ledger.

## 2. Validate SavedVariables identity and contents

Check the session header, file edition, and the actual realm buckets. The header is a hint; the bucket's recorded identity is authoritative. These commands parse the save without changing it:

```powershell
Get-Content -LiteralPath $save -TotalCount 4
node tools/export-realm-from-savedvariables.js $save --list-realms
node tools/analyze-realm.js $save '--realm=<exact bucket from list>' --flavor=<flavor>
```

Use `_anniversary_` for `wow-tbc-*`, `_classic_` for `wow-mop-*`, `_classic_era_` for `wow-classic-*`, `_retail_` for `wow-retail-*` or `wowcensus-retail-*`, and `_forever_` for `wow-forever-*`. The importer further routes Classic Era buckets such as Wild Growth to SoD and Doomhowl to Hardcore. Forever beta remains separate: `_classic_beta_` is frozen after the 2026-11-04 23:00 UTC launch cutoff unless `--allow-beta-import` is explicitly supplied. A release-client file may contain retained beta buckets; verify its `identity.game` and let the importer route them by that identity.

For every bucket, check that region, game, faction, and realm in the parsed identity agree with the bucket key and the intended client. Check collection timestamps and coverage; a recent file modification does not prove that every contained observation is recent or complete. Empty `realms` tables can be valid short sessions: they add a source hash but no census rows. The importer rejects inconsistent Forever beta/live identities and region-prefixed bucket mismatches.

## 3. Stage individual session files

`tools/import-savedvariables-pg.py` scans a WoW-style tree, not arbitrary file arguments. Keep the original files untouched and make an isolated staging tree outside the repo. Give each session a separate account directory so two saves for the same realm are both read. A session file containing `MarketLensDB` or `WoWCensusDB` plus `NameplateInspectDB` can be copied to both companion filenames; the exporters select the relevant table. For a standalone `WoWCensus.lua` save without inspection data, stage it as `WoWCensus.lua` instead. Verify every staged copy's SHA-256 against its source.

```powershell
$source = 'C:\path\to\wow-forever-YYYYMMDD-HHMMSS.lua'
$stage = 'C:\Users\skarz\Documents\MarketLensData\session-import-YYYYMMDD-batch'
$account = [IO.Path]::GetFileNameWithoutExtension($source)
$folder = Join-Path $stage "game\_forever_\WTF\Account\$account\SavedVariables"
New-Item -ItemType Directory -Path $folder -Force | Out-Null
foreach ($name in @('MarketLens.lua', 'NameplateInspect.lua')) {
    $target = Join-Path $folder $name
    if (Test-Path -LiteralPath $target) {
        if ((Get-FileHash -LiteralPath $target -Algorithm SHA256).Hash -ne (Get-FileHash -LiteralPath $source -Algorithm SHA256).Hash) { throw "Staging conflict: $target" }
    } else { Copy-Item -LiteralPath $source -Destination $target }
}
```

Repeat for each **new** session, replacing `_forever_` with its verified flavor and using a unique account directory. For a combined `WoWCensus.lua`, copy once under that filename instead of creating companion files. Do not point the importer at the normal game root when the task is limited to supplied files; that could import unrelated current saves.

## 4. Import once and inspect the report

```powershell
python -B tools/import-savedvariables-pg.py --game-root "$stage\game" --archive "$stage\archive"
```

The importer takes an advisory lock, archives new saves, records their hashes, imports market, population, seller, and inspection data, and commits the database work in one transaction. On a database import failure, that transaction rolls back; archive copies may still exist. When data changes, it runs `ANALYZE`, rebuilds the local census cache, and prints `report=<path>` and `CHANGED sources=...`. A new save with no changed source data may produce no report. The report's `files` array may list both staged companion paths for one source hash; count distinct hashes, not paths.

Check exit code 0, `errors: []` when a report exists, the expected number of new hashes and realm buckets, and successful cache output when data changed. Verify the hashes again in `local_source_files`. Read each report bucket's `source`, population, and inspection details; compare its game and region with the identity parsed from the save. `population.characters` and `population.observations` in the report are **records processed from that file**, not necessarily net new database rows. Character identity and character-day keys deduplicate overlaps, and seen counts can use maxima. If net growth matters, compare table counts before and after.

`selected`/`valid` inspection entries are those parsed from the save; `rejected` should be zero. `linked` means the importer matched an inspection to a retained census character. `unmatched` inspections are still stored, but have no confident census-character link; entries across files need not represent distinct people.

The post-import `tools/check-collection-regions.py` check prints provenance warnings. It does not roll back a committed import. Review new warnings and distinguish them from known older issues; do not assume an EU/US conflict is harmless. A cache rebuild failure is recorded in the report after the database commit; rerun `laragon-php/build-census-cache.php` after resolving it.

## ForeverPulse is a separate import path

Do **not** stage `ForeverPulse.lua` as a MarketLens/WoWCensus session file. Its manual `/who` batches have a different schema. [tools/import-foreverpulse-who-pg.py](tools/import-foreverpulse-who-pg.py) documents the original, source-specific importer; its `SOURCE`, cleaned-output path, scope, realm, and version are hardcoded. Do not run it unchanged on a newer or different ForeverPulse file.

For a new ForeverPulse source, first hash the file and check `local_source_files` and `external_who_sources`. Parse `ForeverPulseCensusDB`; validate the single intended scope (region, realm ID/name, faction, live environment), addon version, batch methods, field layouts, dictionary references, levels, timestamps, and unique `(scope_id, batch_id, name)` keys. Decode only supported `who_manual` rows. Compare overlapping batch IDs and sightings with `external_who_batches` and `external_who_sightings`; reject conflicting timestamps or character attributes. Archive and insert only new batch/name keys within a transaction, record the source hash, then merge verified observations into the canonical Forever census using the source-specific merge logic in [tools/merge-foreverpulse-who-pg.sql](tools/merge-foreverpulse-who-pg.sql), adapted and reviewed for the actual scope and counts. Rebuild the census cache and verify ledger, external, canonical, and cache results.

Channel rosters, scan cells, and talent data have different semantics and are not covered by that `/who` import. Batch time bounds are known; exact per-character sighting times are not. Report total source rows, overlapping rows, newly inserted external rows, and net canonical changes separately. The current repository does **not** have a generic, parameterized ForeverPulse ingestion command for arbitrary snapshots.
