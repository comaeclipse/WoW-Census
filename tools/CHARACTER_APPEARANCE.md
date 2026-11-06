# Retail character appearance observations

Blizzard's public character profile is keyed by region, realm, and character name. MarketLens has no Battle.net account or player identity for census characters. Appearance records therefore live in separate PostgreSQL tables and use the existing `source_game='retail'` plus `character_key` census identity. Do not interpret a character as a distinct person.

- `marketlens_import.appearance_sample_runs` records the immutable source report hash, local file modification time, and swatch catalog provenance.
- `marketlens_import.character_appearance_observations` stores one API result per character per report, including 404s and successful responses with no Skin Color choice. Repeated future queries create new observations.
- `marketlens_import.character_appearance_latest` selects the newest observation per character key. The original pilot reports have no per-request timestamp, so their report file time is only an ordering proxy. Future reports capture `fetched_at` per request.

The census `characters` primary key includes `game` (a scan realm/faction bucket), while the same Retail character key can appear in more than one bucket. The importer resolves each API result to exactly one existing `character_key` by case-folded name and realm. It aborts on missing or ambiguous matches. The observation table intentionally has no account/user foreign key and no arbitrary `game` assignment.

To import local reports into the local PostgreSQL source of truth:

```powershell
python tools/import-appearance-pilots-pg.py C:\path\to\report-1.json C:\path\to\report-2.json
```

The importer runs in one transaction and is idempotent for the same report hash. Rebuild the local mockup with `python mockups/build-retail-appearance-mockup.py`; it reads the PostgreSQL observations. Neither command publishes the site.

Skin choice IDs and hex swatches are game customization UI data. Hex values are not measured model skin pixels. The API can include unrelated options named `Skin Color`, including dragon-mount customizations. The collector and page generator use the shared `laragon-php/src/retail-skin-option-ids.json` allowlist, derived from `ChrCustomizationOption` -> `ChrModel` -> `ChrRaceXChrModel` in the Retail DB2 catalog. Worgen's sampled options map to its human form; Dracthyr's map to Visage. No sampled Vulpera `Skin Color` option mapped to its playable model, so its page has no verified skin-color swatches.

Older reports selected the first `Skin Color` option without checking its model. The missing valid choice cannot be reconstructed from a report that retained only the first option. The census race is captured before the API request and may also be stale if a character changed race. The updated collector can fetch the current profile race before selecting a Skin Color option.

On 2026-10-19, the 456 latest mismatched non-Vulpera identities were resampled with `--candidates-json` and `--profile-race`. The local report is stored outside the repository in `Documents/MarketLens Reports/retail-skin-refresh-2026-10-19.json`. It contains 454 valid choices and two profile 404s; 56 current API races differed from the prior census race. All 456 observations and 454 child choices were imported into local PostgreSQL, and all 456 became the latest observation for their identities. No mismatched non-Vulpera `Skin Color` options remain in the latest view. Vulpera has no verified playable-model `Skin Color` choice in these API responses; its `Fur Color` needs a separate mapping and display design. The local static skin-color pages were rebuilt, with no S3 publication.

On 2026-10-20, a further 500 previously unsampled Retail character identities were queried across all 26 races. The corrected local report is `Documents/MarketLens Reports/retail-appearance-new-500-region-corrected-2026-10-20.json`. It has 494 successful profiles, 476 valid skin choices, and six profile 404s. The first pass incorrectly queried EU characters through the US endpoint; the corrected report uses recorded collection provenance and Blizzard's `temple-noir` slug for the Templenoir realm. All 500 observations and 476 child choices were imported into local PostgreSQL. All 500 are unique new identities and latest observations. The DB2 swatch catalog gained one choice. Local static skin-color pages were rebuilt; no S3 publication was performed. Candidate JSON for future passes can include `region` (`us`, `eu`, `kr`, or `tw`) so the sampler sends each request to the matching Blizzard API namespace.
