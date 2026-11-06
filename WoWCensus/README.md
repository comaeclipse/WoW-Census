# WoWCensus

One add-on for `/who` census runs. The window shows completed and partial run counts for the current realm and faction, the three most recent query filters with their observed result counts, the query currently waiting for a response, and the next planned filter.

## Install

Choose the ZIP matching your client: Forever (`_forever_`), Retail (`_retail_`), MoP Classic (`_classic_`), TBC Anniversary (`_anniversary_`), or Classic Era/Season of Discovery (`_classic_era_`). Extract its inner `WoWCensus` folder directly to that client's `Interface/AddOns/` directory. The TOC must be at `AddOns/WoWCensus/WoWCensus.toc`; an extra version-named folder prevents WoW from finding it. Disable MarketLens in that client to avoid duplicate census collection. Restart the game.

On a new install, log in and play: WoWCensus starts a run at login and advances it from eligible movement keys or clicks on the game world. WoW requires a real player input for each `/who` query, and the server may throttle replies. Clicks on the add-on window do not count as world clicks. Type `/wc` to see progress; no button press is needed for the normal path. If the region cannot be identified, collection waits until you choose it with `/wc region us|eu|kr|tw|cn`.

The window also has Start and Run Next for manual control. You can bind **Toggle window** and **Run next census query** under WoW's Key Bindings > WoWCensus. The **Auto: On** control enables or disables passive advancement. `/wc passive on` and `/wc passive off` control the same setting; `/wc auto off` disables automatic starts at login, and `/wc auto on` enables them again. `/wc stop` records an unfinished run as partial. If direct `/who` is blocked, chat fallback offers `/wc who <filter>`.

Run counts are scoped to the current realm and faction. A complete run exhausted its queue with no unresolved capped cells. A partial run was stopped or left capped cells unresolved. Results are observed characters, not a full population or player-account count.

Collection data is also separated by region and game version. WoWCensus records the client's region ID, realm ID, realm name, and normalized realm name after login; `/wc region` shows the chosen region. If the client cannot identify it (notably some Classic clients or Taiwan through a Korean portal), set it with `/wc region us|eu|kr|tw|cn` before collecting. Older saves without region metadata remain separate and cannot be assigned to a region automatically. Character records retain the home realm returned by `/who` separately from the collection realm.

The **Last /who** line distinguishes an attempted query waiting for a server answer, an empty response, a stale response, and an add-on action blocked by WoW. A query with no observable response is marked `timeout_unknown` after 25 seconds; that does not prove throttling. Queries stay at least 12 seconds apart, and an outstanding query is never replaced early. The next eligible player input can retry after the timeout and backoff. `/wc diag` prints the eight latest events. The add-on keeps 80 timestamped events per realm in SavedVariables, including request gaps, response times, counts, and home/world latency readings. `CHAT_MSG_SYSTEM_WHILE_PENDING` is diagnostic only and may be unrelated to `/who`.

## Saved data

WoWCensus writes `WoWCensus.lua` in the account's SavedVariables directory. Existing `MarketLens.lua` and `NameplateInspect.lua` files are left untouched. New installations start with an empty census history; to carry history over, use `tools/migrate-wowcensus-savedvariables.py` from the repository while WoW is closed. The migration refuses to overwrite an existing `WoWCensus.lua`.

For this local PostgreSQL archive, the user confirmed that observations before October 17, 2026 in America/Chicago were collected on US realms. `tools/collection-region-provenance.sql` applies that timestamped rule in query views. Legacy observations from October 17 onward are left unassigned unless the addon recorded a region.

The standalone MarketLens add-on is the source for the copied population modules. Keep census fixes synchronized if both packages continue to be maintained.
