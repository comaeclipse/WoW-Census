# WoWCensus design principles

The governing rules for the website (`site/` Worker pages and the static
`pages/` bundle on wowcensus). Change a rule here first, then the code.
`node --test tools/test-design.mjs` enforces the mechanical parts against the
built bundle; the checklist at the end covers what a test cannot see.

The in-game addon follows a different rule: **native Blizzard look**
(FrameXML templates, GameFont, item icons, GameTooltip). Nothing below applies
to `MarketLens/UI/`.

## 1. Character

A dark arcade cabinet reading a ledger. Pixel type for chrome, terminal type
for data, one gold accent, hard offset shadows, no rounded cards, no gradients,
no animation beyond the single `.blink` cursor (and only when the viewer allows
motion). If a change makes a page look like a generic SaaS dashboard, it is
wrong.

## 2. Tokens (source of truth: `site/public/style.css` `:root`)

| Token | Value | Use |
| --- | --- | --- |
| `--bg` | `#0d0f17` | page background |
| `--panel` / `--panel2` | `#161a28` / `#1e2334` | panels, table body / headers, hover |
| `--line` | `#2b3145` | every border and divider |
| `--ink` | `#eae7d6` | body text |
| `--muted` | `#8b90a0` | labels, secondary text, hints |
| `--gold` | `#ffce43` | the accent: headings, values, active state |
| `--green` / `--red` / `--blue` | `#66d16b` / `#e8595f` / `#5fb0f0` | good / bad / links |
| `--shadow` | `#000` | offset shadows |

- Use tokens, not literals. The only sanctioned literals are WoW's own colors:
  class colors (`CLASS_META` in `site/src/census.mjs`), faction colors
  (`FACTION_COLOR`), and the `#b99b55` gilt frame around race/class icons.
- Active state is always gold fill with dark text (`#161018`), never a new color.
- Don't add tokens casually. A new token means updating this table and the test.

## 3. Typography

Four faces, each with one job. Never swap their roles.

| Face | Variable / family | Job |
| --- | --- | --- |
| Press Start 2P | `--pixel` | chrome: nav buttons, chips, table headers, tile labels, panel titles, breadcrumbs, tags. Always uppercase, `letter-spacing:1px`, 7-11px (logo 30px, tile values 13-16px) |
| VT323 | `--term` | body and data: table cells, stats, legends, inputs. 20px base (15-18px for secondary) |
| Cinzel 800 | `"Cinzel"` | the H1 (`.iname`) only |
| Friz Quadrata | `"Friz Quadrata Web"` | prose: analysis and summary paragraphs (`.combo-analysis`, `.talent-summary`) |

- No new font families and no third-party font hosts. Fonts are self-hosted in
  `/fonts/` with their OFL licence next to them.
- Pixel and terminal faces use `font-display:optional` and are preloaded on
  every page, so the layout never shifts when they arrive (commit b680543).
- Don't shrink type inside one table to make it fit; widen the column or let it
  wrap. Talent tables regressed this way once (fixed in a136eb2).
- Numbers in tables use `font-variant-numeric:tabular-nums` and align right;
  names and labels align left.

## 4. Surfaces

- Panels, tiles, tables and buttons share one recipe: `--panel` fill,
  `2px solid var(--line)` border, a hard offset black shadow (3px buttons,
  4-5px tiles, 6px panels/tables), no blur, no radius.
- Hover raises the border to gold. Focus is a visible 2px outline (blue or
  gold), never removed without a replacement.
- The `.crt` overlay and `.wrap` container (max 1080px) wrap every page.

## 5. Page anatomy (every static page, every game)

In this order:

1. `header.ihead` containing `.page-heading`: the `h1.iname` "WoWCensus" with
   the page topic in `.itopic` underneath, and the **game nav** (one button per
   edition, the current one `aria-current="true"`).
2. **Breadcrumbs**: `WoWCensus / <Game> / <Page>`, last crumb `aria-current="page"`.
3. `.page-controls`: the **page nav** (Census, Auction House, Guilds,
   Geography, Talents; exactly one current) and, where the page slices data,
   the realm/faction chip rows (`.vchips`).
4. Content: tiles, then panels/tables.

Every edition gets every page, even with no data. An empty page says so plainly
in the normal layout; it never disappears or breaks the nav. Navigation is
identical across editions: adding a page or game means adding it everywhere.

## 6. Data honesty is part of the design

- Show only what was observed. Census is race/class **composition**, never
  "X players online". Inspected talents are a nearby-player sample, never
  population-wide spec shares; the page says so.
- Talent data is pooled **per game**, never split by realm.
- Every data page states its freshness (`Scanned <date>` / `Latest inspection`).
- No disclaimers or filler copy beyond what's needed to read the number correctly.

## 7. Layout and responsiveness

- Grids collapse at 760px (tiles 4→2, two-column panels →1); the five-tile row
  steps 5→3→2. Tables scroll horizontally inside `.tablewrap`; the page never does.
- Data-heavy pages prefer two-column `.panelgrid` over long vertical stacks.

## 8. Build rules

- `site/src/*.mjs` renderers are shared by the Worker and the static build, so
  the two can't drift. Style changes go into `site/public/style.css` or the
  renderer's shared `CENSUS_STYLE`, never by hand-editing `pages/`.
- The static stylesheet is content-hashed (`style.<sha256:12>.css`) and served
  immutable. Rebuild every edition after a CSS change so no page references a
  stale hash.
- Pages are static: no runtime fetches. View toggles show/hide pre-rendered
  panes and mirror state in the URL query.

## Regression testing

Automated (run after any renderer, CSS, or rebuild change):

```
node --test tools/test-design.mjs tools/test-inspects.mjs
```

`test-design.mjs` checks the built `pages/` bundle: tokens unchanged; every
edition has every page; each page has the shared head (doctype, lang, viewport,
current hashed stylesheet, font preloads), the anatomy from section 5 with one
current game and one current page; every referenced `/fonts/` file exists; no
external font hosts; no per-cell font-size overrides in talent tables.

Manual, before deploying a visual change: open one page of each type (census,
combos, auction house, guilds, geography, talents) at desktop width and at
375px, and check the following:

- [ ] Header, breadcrumbs, and nav sit in the same place as on the other pages
- [ ] No horizontal page scroll at 375px; wide tables scroll inside their frame
- [ ] Type roles match section 3 (pixel for chrome, terminal for data, Friz for prose)
- [ ] Nothing shifts as fonts load (hard reload with cache disabled)
- [ ] Chip toggles change the view and the URL, and Back restores the previous view
- [ ] An edition with no data (currently SoD talents) still renders cleanly
