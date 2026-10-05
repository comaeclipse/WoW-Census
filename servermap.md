# Server map

How the six WoWCensus editions map to internal keys, WoW install folders, site paths and the realms
we collect from. The internal keys (`source_game`) date from before the editions were renamed. They are
still used in D1/SQLite rows, SavedVariables buckets and tool flags, but the UI shows only the edition names.
The definitions are in `site/src/edition.mjs` (`EDITIONS`).

## Editions

| Edition (UI name) | Internal key (`source_game`) | `-Flavor` / build flavor | WoW install folder | Site path | Wowhead branch |
|---|---|---|---|---|---|
| WoW Forever (beta) | `classic-beta` | `classic-beta` | `_classic_beta_` | `/` | `forever` |
| TBC Anniversary | `classic-progression` | `tbc-anniversary` | `_anniversary_` | `/tbc/` | `tbc` |
| Classic Era | `classic` | `classic-era` | `_classic_era_` | `/classic/` | `classic` |
| Season of Discovery | `sod` | `sod` | `_classic_era_` (shared with Era) | `/sod/` | `classic` |
| MoP Classic | `mop-classic` | `mop-classic` | `_classic_` | `/mop/` | `mop-classic` |
| Retail | `retail` | `retail` | `_retail_` | `/retail/` | (none) |

Install folders are under `C:\Program Files (x86)\World of Warcraft\`. SavedVariables live in
`<folder>\WTF\Account\<account>\SavedVariables\` (`MarketLens.lua`, `NameplateInspect.lua`).

Folder names that don't match the edition:
- `_classic_` is **MoP Classic**, not Classic Era.
- `_classic_era_` holds **both** Classic Era and Season of Discovery buckets.
- `_anniversary_` is TBC Anniversary, whose internal key is `classic-progression`.

## Collection realms (observer buckets)

Each dataset is keyed `realm:<Realm>-<Faction>` (the `game` column). This is the realm and faction of the
character that ran the scan, not where the observed players are from.

| Edition | Observer buckets |
|---|---|
| WoW Forever | Classic Beta PvE-Alliance, Classic Beta PvE-Horde, Classic Beta PvP 2-Alliance, Classic Beta PvP 2-Horde |
| TBC Anniversary | Dreamscythe-Alliance, Dreamscythe-Horde |
| Classic Era | Ashkandi-Alliance, Ashkandi-Horde, Old Blanchy-Alliance, Old Blanchy-Horde, Mankrik-Horde, Westfall-Alliance |
| Season of Discovery | Wild Growth-Alliance, Wild Growth-Horde |
| MoP Classic | Nazgrim-Alliance, Nazgrim-Horde |
| Retail | Illidan-Horde, Stormrage-Alliance, Stormrage-Horde, Nesingwary-Alliance, Nesingwary-Horde |

### Retail AH datasets

The Retail AH is shared across factions, so the AH data uses a faction-less key (`realm:Illidan`,
`realm:Stormrage`, `realm:Nesingwary`) next to the faction buckets used for population data. Compare the live
`realm:<Realm>` item dataset before overwriting it with one faction's AH export.

## Observed home realms

Players seen in /who and inspects come from many home realms (connected realms, cross-realm zones, layering).
Inspect and talent data is therefore pooled per edition, never per realm. Home realms with more than 200
characters in the 2026-10-05 snapshot:

| Edition | Main home realms observed |
|---|---|
| WoW Forever | Classic Beta PvE, Classic Beta PvP 2 |
| TBC Anniversary | Dreamscythe |
| Classic Era | Mankrik, Windseeker, Ashkandi, Westfall, Pagle, Azuresong, Atiesh, Old Blanchy, Myzrael |
| Season of Discovery | Wild Growth |
| MoP Classic | Pagle, Galakras, Lei Shen, Nazgrim, Raden, Immerseus |
| Retail | Illidan, Stormrage, Vek'nilash, Nazgrel, Nesingwary |

## Data windows

- Census race/class shares: 14 days, ending at the dataset's newest census sighting (`CENSUS_WINDOW_DAYS`).
- Inspects/talents/gear: 14 days, ending at the newest inspect (`INSPECT_WINDOW_DAYS`).
- Zone/geography: 30 days (`ZONE_WINDOW_DAYS`).
- AH listings: items not seen within 48 hours of the scan are dropped (`STALE_LISTING_SECONDS`).

All windows are measured back from each dataset's newest scan, not from today. Data that isn't rescanned
stays on the site; it just gets older.

## Legacy datasets

The edition-wide datasets `classic`, `classic-progression` and `retail` (with no `realm:` prefix) date from
before data was split per realm. They were last updated 2026-09-14.
