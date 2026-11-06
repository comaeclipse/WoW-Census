import fs from 'node:fs';

const REALMS = [
  {
    name: "Ashkandi",
    type: "PvE",
    region: "US East",
    cluster: "Ashkandi, Mankrik, Pagle, Westfall, Windseeker",
    alliance: 3828,
    horde: 3150,
    samples: 633,
    observed: 18515,
    races: {
      Alliance: { Human: 1851, "Night Elf": 882, Dwarf: 587, Gnome: 508 },
      Horde: { Undead: 1085, Orc: 713, Troll: 697, Tauren: 655 }
    },
    classes: {
      Alliance: { MAGE: 770, WARRIOR: 752, HUNTER: 580, PALADIN: 443, PRIEST: 410, ROGUE: 315, DRUID: 313, WARLOCK: 245 },
      Horde: { WARRIOR: 604, HUNTER: 474, MAGE: 444, SHAMAN: 394, WARLOCK: 332, PRIEST: 329, ROGUE: 288, DRUID: 285 }
    },
    combos: {
      Alliance: [
        { race: "Human", cls: "WARRIOR", count: 554 },
        { race: "Human", cls: "MAGE", count: 475 },
        { race: "Human", cls: "PALADIN", count: 354 }
      ],
      Horde: [
        { race: "Tauren", cls: "DRUID", count: 285 },
        { race: "Undead", cls: "MAGE", count: 278 },
        { race: "Orc", cls: "WARRIOR", count: 264 }
      ]
    }
  },
  {
    name: "Westfall",
    type: "PvE",
    region: "US East",
    cluster: "Ashkandi, Mankrik, Pagle, Westfall, Windseeker",
    alliance: 4757,
    horde: 1300,
    samples: 416,
    observed: 11365,
    races: {
      Alliance: { Human: 2299, "Night Elf": 1104, Dwarf: 737, Gnome: 617 },
      Horde: { Undead: 455, Troll: 290, Orc: 280, Tauren: 275 }
    },
    classes: {
      Alliance: { MAGE: 869, WARRIOR: 793, HUNTER: 709, PALADIN: 654, PRIEST: 511, ROGUE: 459, DRUID: 397, WARLOCK: 365 },
      Horde: { WARRIOR: 264, MAGE: 212, HUNTER: 190, PRIEST: 149, SHAMAN: 149, WARLOCK: 127, ROGUE: 107, DRUID: 102 }
    },
    combos: {
      Alliance: [
        { race: "Human", cls: "WARRIOR", count: 563 },
        { race: "Human", cls: "MAGE", count: 531 },
        { race: "Human", cls: "PALADIN", count: 521 }
      ],
      Horde: [
        { race: "Undead", cls: "MAGE", count: 126 },
        { race: "Orc", cls: "WARRIOR", count: 115 },
        { race: "Undead", cls: "PRIEST", count: 112 }
      ]
    }
  },
  {
    name: "Mankrik",
    type: "PvE",
    region: "US East",
    cluster: "Ashkandi, Mankrik, Pagle, Westfall, Windseeker (Horde Hub)",
    alliance: 0,
    horde: 2459,
    samples: 135,
    observed: 4255,
    races: {
      Alliance: {},
      Horde: { Undead: 813, Orc: 576, Troll: 553, Tauren: 517 }
    },
    classes: {
      Alliance: {},
      Horde: { WARRIOR: 453, HUNTER: 401, MAGE: 356, SHAMAN: 318, PRIEST: 256, WARLOCK: 248, ROGUE: 217, DRUID: 210 }
    },
    combos: {
      Alliance: [],
      Horde: [
        { race: "Undead", cls: "MAGE", count: 218 },
        { race: "Tauren", cls: "DRUID", count: 210 },
        { race: "Orc", cls: "WARRIOR", count: 198 }
      ]
    }
  },
  {
    name: "Old Blanchy",
    type: "PvE",
    region: "US West",
    cluster: "Atiesh, Azuresong, Myzrael, Old Blanchy",
    alliance: 534,
    horde: 370,
    samples: 162,
    observed: 3304,
    races: {
      Alliance: { Human: 272, "Night Elf": 117, Dwarf: 79, Gnome: 66 },
      Horde: { Undead: 120, Tauren: 87, Orc: 83, Troll: 80 }
    },
    classes: {
      Alliance: { MAGE: 135, HUNTER: 85, PALADIN: 82, WARRIOR: 66, PRIEST: 48, DRUID: 41, ROGUE: 39, WARLOCK: 38 },
      Horde: { WARRIOR: 66, HUNTER: 51, MAGE: 50, SHAMAN: 49, WARLOCK: 45, PRIEST: 42, ROGUE: 37, DRUID: 30 }
    },
    combos: {
      Alliance: [
        { race: "Human", cls: "MAGE", count: 97 },
        { race: "Human", cls: "PALADIN", count: 67 },
        { race: "Night Elf", cls: "HUNTER", count: 52 }
      ],
      Horde: [
        { race: "Undead", cls: "WARLOCK", count: 35 },
        { race: "Tauren", cls: "DRUID", count: 30 },
        { race: "Undead", cls: "PRIEST", count: 30 }
      ]
    }
  },
  {
    name: "Whitemane",
    type: "PvP",
    region: "US West",
    cluster: "Whitemane, Anathema, Arcanite Reaper, Bigglesworth, Fairbanks, Thunderfury...",
    alliance: 874,
    horde: 0,
    samples: 67,
    observed: 1765,
    races: {
      Alliance: { Human: 429, "Night Elf": 178, Gnome: 156, Dwarf: 111 },
      Horde: {}
    },
    classes: {
      Alliance: { MAGE: 213, WARRIOR: 160, ROGUE: 114, HUNTER: 98, PALADIN: 96, PRIEST: 79, DRUID: 58, WARLOCK: 56 },
      Horde: {}
    },
    combos: {
      Alliance: [
        { race: "Human", cls: "MAGE", count: 119 },
        { race: "Human", cls: "WARRIOR", count: 118 },
        { race: "Gnome", cls: "MAGE", count: 94 }
      ],
      Horde: []
    }
  },
  {
    name: "Thunderfury",
    type: "PvP",
    region: "US West",
    cluster: "Whitemane, Bigglesworth, Fairbanks, Kurinnaxx, Thunderfury...",
    alliance: 0,
    horde: 863,
    samples: 40,
    observed: 1420,
    races: {
      Alliance: {},
      Horde: { Undead: 297, Orc: 241, Troll: 205, Tauren: 120 }
    },
    classes: {
      Alliance: {},
      Horde: { MAGE: 207, WARRIOR: 176, ROGUE: 115, SHAMAN: 102, HUNTER: 86, WARLOCK: 70, DRUID: 54, PRIEST: 53 }
    },
    combos: {
      Alliance: [],
      Horde: [
        { race: "Undead", cls: "MAGE", count: 118 },
        { race: "Orc", cls: "WARRIOR", count: 97 },
        { race: "Troll", cls: "MAGE", count: 89 }
      ]
    }
  },
  {
    name: "Azuresong",
    type: "PvE",
    region: "US West",
    cluster: "Atiesh, Azuresong, Myzrael, Old Blanchy",
    alliance: 0,
    horde: 314,
    samples: 15,
    observed: 462,
    races: {
      Alliance: {},
      Horde: { Undead: 96, Troll: 83, Tauren: 72, Orc: 63 }
    },
    classes: {
      Alliance: {},
      Horde: { WARRIOR: 54, HUNTER: 49, MAGE: 44, SHAMAN: 40, DRUID: 37, ROGUE: 33, WARLOCK: 31, PRIEST: 26 }
    },
    combos: {
      Alliance: [],
      Horde: [
        { race: "Tauren", cls: "DRUID", count: 37 },
        { race: "Orc", cls: "WARRIOR", count: 25 },
        { race: "Undead", cls: "WARLOCK", count: 24 }
      ]
    }
  },
  {
    name: "Penance (AU)",
    type: "PvE",
    region: "Oceanic",
    cluster: "Oceanic Classic Era Cluster",
    alliance: 13,
    horde: 11,
    samples: 8,
    observed: 24,
    races: {
      Alliance: { Human: 4, Dwarf: 4, "Night Elf": 3, Gnome: 2 },
      Horde: { Orc: 5, Tauren: 3, Troll: 2, Undead: 1 }
    },
    classes: {
      Alliance: { PALADIN: 3, HUNTER: 2, WARRIOR: 2, DRUID: 2, WARLOCK: 2, MAGE: 1, ROGUE: 1 },
      Horde: { SHAMAN: 3, ROGUE: 2, WARRIOR: 2, DRUID: 1, MAGE: 1, PRIEST: 1, HUNTER: 1 }
    },
    combos: {
      Alliance: [
        { race: "Dwarf", cls: "HUNTER", count: 2 },
        { race: "Dwarf", cls: "PALADIN", count: 2 },
        { race: "Night Elf", cls: "DRUID", count: 2 }
      ],
      Horde: [
        { race: "Orc", cls: "SHAMAN", count: 2 },
        { race: "Orc", cls: "HUNTER", count: 1 },
        { race: "Tauren", cls: "DRUID", count: 1 }
      ]
    }
  },
  {
    name: "Crusader Strike",
    type: "Seasonal Transfer",
    region: "US East",
    cluster: "Seasonal / Cross-Realm transfer sightings",
    alliance: 4,
    horde: 0,
    samples: 7,
    observed: 4,
    races: { Alliance: { Human: 2, Dwarf: 1, Gnome: 1 }, Horde: {} },
    classes: { Alliance: { ROGUE: 3, PALADIN: 1 }, Horde: {} },
    combos: { Alliance: [{ race: "Human", cls: "PALADIN", count: 1 }, { race: "Human", cls: "ROGUE", count: 1 }], Horde: [] }
  },
  {
    name: "Living Flame",
    type: "Seasonal Transfer",
    region: "US East",
    cluster: "Seasonal / Cross-Realm transfer sightings",
    alliance: 1,
    horde: 0,
    samples: 7,
    observed: 2,
    races: { Alliance: { Human: 1 }, Horde: {} },
    classes: { Alliance: { ROGUE: 1 }, Horde: {} },
    combos: { Alliance: [{ race: "Human", cls: "ROGUE", count: 1 }], Horde: [] }
  },
  {
    name: "Lone Wolf",
    type: "Seasonal Transfer",
    region: "US East",
    cluster: "Seasonal / Cross-Realm transfer sightings",
    alliance: 1,
    horde: 0,
    samples: 8,
    observed: 2,
    races: { Alliance: { Human: 1 }, Horde: {} },
    classes: { Alliance: { ROGUE: 1 }, Horde: {} },
    combos: { Alliance: [{ race: "Human", cls: "ROGUE", count: 1 }], Horde: [] }
  }
];

const CLASS_COLORS = {
  WARRIOR: '#C79C6E',
  PALADIN: '#F58CBA',
  HUNTER: '#ABD473',
  ROGUE: '#FFF569',
  PRIEST: '#FFFFFF',
  SHAMAN: '#0070DE',
  MAGE: '#69CCF0',
  WARLOCK: '#9482C9',
  DRUID: '#FF7D0A'
};

const RACE_ICONS = {
  Human: 'achievement_character_human_male.jpg',
  Dwarf: 'achievement_character_dwarf_male.jpg',
  'Night Elf': 'achievement_character_nightelf_male.jpg',
  Gnome: 'achievement_character_gnome_male.jpg',
  Orc: 'achievement_character_orc_male.jpg',
  Undead: 'achievement_character_undead_male.jpg',
  Tauren: 'achievement_character_tauren_male.jpg',
  Troll: 'achievement_character_troll_male.jpg'
};

const fmt = n => (n || 0).toLocaleString('en-US');
const esc = s => String(s || '').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;');
const totalChars = r => (r.alliance || 0) + (r.horde || 0);

function iconPath(filename) {
  return '/assets/images/wow/icons/large/' + filename;
}

function renderRealmCardHtml(r, isSelected) {
  const tot = totalChars(r);
  const aPct = tot ? Math.round((r.alliance / tot) * 100) : 0;
  const hPct = tot ? 100 - aPct : 0;

  return `
    <div class="realm-card ${isSelected ? 'active' : ''}" role="option" aria-selected="${isSelected}" tabindex="0" data-realm="${esc(r.name)}">
      <div class="rc-head">
        <div class="rc-left">
          <div class="rc-name">${esc(r.name)}</div>
          <div class="rc-tag">${esc(r.region)} · ${esc(r.type)}</div>
        </div>
        <div class="rc-total">${fmt(tot)}<small>CHARS</small></div>
      </div>
      <div class="bar-track" aria-hidden="true">
        <span class="bar-seg-alliance" style="width:${aPct}%"></span>
        <span class="bar-seg-horde" style="width:${hPct}%"></span>
      </div>
      <div class="rc-split-legend">
        <span class="alliance">◆ ${fmt(r.alliance)} A (${aPct}%)</span>
        <span class="horde">(${hPct}%) H ${fmt(r.horde)} ◆</span>
      </div>
    </div>
  `;
}

function renderComboCardHtml(c, factionTot, isHorde) {
  const pct = factionTot ? ((c.count / factionTot) * 100).toFixed(1) : "0.0";
  const raceIconFile = RACE_ICONS[c.race] || 'inv_misc_questionmark.jpg';
  const classIconFile = 'classicon_' + c.cls.toLowerCase() + '.jpg';
  const classProper = c.cls.charAt(0) + c.cls.slice(1).toLowerCase();

  return `
    <article class="combocard">
      <div class="comboicons">
        <img src="${iconPath(raceIconFile)}" data-icon="${raceIconFile}" alt="${c.race} icon" onerror="handleImgError(this)" loading="lazy">
        <img src="${iconPath(classIconFile)}" data-icon="${classIconFile}" alt="${classProper} icon" onerror="handleImgError(this)" loading="lazy">
      </div>
      <div>
        <div class="comboname">${esc(c.race)} ${esc(classProper)}</div>
        <div class="combostat">${fmt(c.count)} &middot; ${pct}% of ${isHorde ? 'Horde' : 'Alliance'}</div>
      </div>
    </article>
  `;
}

function renderClassBarsHtml(clsObj, factionTot, defaultColor) {
  if (!clsObj || !Object.keys(clsObj).length) {
    return '<div style="font-size:16px;color:var(--muted);padding:8px">No recorded class sightings for this faction.</div>';
  }
  const sorted = Object.entries(clsObj).sort((a,b) => b[1] - a[1]);
  const maxVal = sorted[0][1] || 1;

  return sorted.map(([cls, count]) => {
    const color = CLASS_COLORS[cls] || defaultColor;
    const widthPct = ((count / maxVal) * 100).toFixed(1);
    const proper = cls.charAt(0) + cls.slice(1).toLowerCase();
    return `
      <div class="bar-row">
        <span class="bar-name">${esc(proper)}</span>
        <div class="bar-meter"><i style="width:${widthPct}%;background:${color}"></i></div>
        <span class="bar-count">${fmt(count)}</span>
      </div>
    `;
  }).join('');
}

function renderRaceBarsHtml(raceObj, factionTot, factionColor) {
  if (!raceObj || !Object.keys(raceObj).length) {
    return '<div style="font-size:16px;color:var(--muted);padding:8px">No recorded race sightings for this faction.</div>';
  }
  const sorted = Object.entries(raceObj).sort((a,b) => b[1] - a[1]);
  const maxVal = sorted[0][1] || 1;

  return sorted.map(([race, count]) => {
    const widthPct = ((count / maxVal) * 100).toFixed(1);
    return `
      <div class="bar-row">
        <span class="bar-name">${esc(race)}</span>
        <div class="bar-meter"><i style="width:${widthPct}%;background:${factionColor}"></i></div>
        <span class="bar-count">${fmt(count)}</span>
      </div>
    `;
  }).join('');
}

function renderDossierHtml(r) {
  const tot = totalChars(r);
  const aPct = tot ? ((r.alliance / tot) * 100).toFixed(1) : "0.0";
  const hPct = tot ? ((r.horde / tot) * 100).toFixed(1) : "0.0";
  const aCombos = (r.combos && r.combos.Alliance) || [];
  const hCombos = (r.combos && r.combos.Horde) || [];

  return `
    <div class="dossier-top">
      <div>
        <div class="dossier-name">${esc(r.name)}</div>
        <div class="dossier-cluster">${esc(r.region)} · ${esc(r.type)} · Classic Era</div>
      </div>
      <div class="dossier-total-box">
        <div class="dossier-total-num">${fmt(tot)}</div>
        <div class="dossier-total-lbl">OBSERVED CHARACTERS</div>
      </div>
    </div>

    <div class="dossier-stats-row">
      <div class="dossier-stat">
        <div class="k">ALLIANCE OBSERVED</div>
        <div class="v alliance">${fmt(r.alliance)}</div>
        <div class="s">${aPct}% share</div>
      </div>
      <div class="dossier-stat">
        <div class="k">HORDE OBSERVED</div>
        <div class="v horde">${fmt(r.horde)}</div>
        <div class="s">${hPct}% share</div>
      </div>
      <div class="dossier-stat">
        <div class="k">RATIO (A : H)</div>
        <div class="v">${r.horde > 0 ? (r.alliance / r.horde).toFixed(2) + ' : 1' : (r.alliance > 0 ? 'ALLIANCE' : 'HORDE')}</div>
        <div class="s">${tot > 0 ? (Math.abs(parseFloat(aPct) - parseFloat(hPct)) < 15 ? 'Balanced' : 'Skewed') : 'N/A'}</div>
      </div>
      <div class="dossier-stat">
        <div class="k">SURVEY SAMPLES</div>
        <div class="v">${fmt(r.samples)}</div>
        <div class="s">${fmt(r.observed)} total sightings</div>
      </div>
    </div>

    <div class="section-subhead">
      <span>FACTION BALANCE METER</span>
      <span>${aPct}% ALLIANCE · ${hPct}% HORDE</span>
    </div>
    <div class="large-split-track" aria-label="Alliance ${aPct} percent, Horde ${hPct} percent">
      <span class="bar-seg-alliance" style="width:${aPct}%"></span>
      <span class="bar-seg-horde" style="width:${hPct}%"></span>
    </div>
    <div class="large-split-legend">
      <span class="alliance">◆ Alliance: ${fmt(r.alliance)} characters (${aPct}%)</span>
      <span class="horde">(${hPct}%) ${fmt(r.horde)} characters :Horde ◆</span>
    </div>

    ${(aCombos.length || hCombos.length) ? `
      <div class="combos-wrap">
        <div class="section-subhead">TOP OBSERVED RACE + CLASS COMBINATIONS</div>
        <div class="dossier-combogrid">
          ${aCombos.length ? `
            <section class="comboblock alliance-block">
              <div class="combofaction alliance-label">Alliance Combos</div>
              ${aCombos.map(c => renderComboCardHtml(c, r.alliance, false)).join('')}
            </section>
          ` : '<div class="empty-state" style="padding:12px;font-size:16px">No Alliance combos observed.</div>'}

          ${hCombos.length ? `
            <section class="comboblock horde-block">
              <div class="combofaction horde-label">Horde Combos</div>
              ${hCombos.map(c => renderComboCardHtml(c, r.horde, true)).join('')}
            </section>
          ` : '<div class="empty-state" style="padding:12px;font-size:16px">No Horde combos observed.</div>'}
        </div>
      </div>
    ` : ''}

    <div class="section-subhead" style="margin-top:20px">CLASS DEMOGRAPHICS</div>
    <div class="class-grid">
      <div class="faction-class-col">
        <div class="fclass-title alliance">Alliance Classes (${fmt(r.alliance)})</div>
        ${renderClassBarsHtml(r.classes.Alliance, r.alliance, '#3f83f8')}
      </div>
      <div class="faction-class-col">
        <div class="fclass-title horde">Horde Classes (${fmt(r.horde)})</div>
        ${renderClassBarsHtml(r.classes.Horde, r.horde, '#d9363e')}
      </div>
    </div>

    <div class="section-subhead" style="margin-top:20px">RACE DEMOGRAPHICS</div>
    <div class="class-grid">
      <div class="faction-class-col">
        <div class="fclass-title alliance">Alliance Races (${fmt(r.alliance)})</div>
        ${renderRaceBarsHtml(r.races.Alliance, r.alliance, '#3f83f8')}
      </div>
      <div class="faction-class-col">
        <div class="fclass-title horde">Horde Races (${fmt(r.horde)})</div>
        ${renderRaceBarsHtml(r.races.Horde, r.horde, '#d9363e')}
      </div>
    </div>

    <div class="cluster-info">
      <strong>REALM CLUSTER INFRASTRUCTURE</strong>
      Connected Realm Grouping: ${esc(r.cluster)}.<br>
      Cross-realm clustering shares auction listings, zone layering, and local grouping across connected servers.
    </div>

    <div class="dossier-actions">
      <a class="cta-btn" href="/classic/?realm=${encodeURIComponent(r.name)}">
        EXPLORE ${esc(r.name.toUpperCase())} IN FULL CENSUS ↗
      </a>
      <a class="cta-btn secondary" href="/classic/auctionhouse">
        VIEW AUCTION HOUSE ↗
      </a>
    </div>
  `;
}

const preRenderedList = REALMS.map(r => renderRealmCardHtml(r, r.name === 'Ashkandi')).join('');
const preRenderedDossier = renderDossierHtml(REALMS[0]);

const html = `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>WoW Classic Era Realm Explorer – WoWCensus</title>
<meta name="description" content="WoW Classic Era realm directory, cluster mapping, faction balance, and observed demographics across Era servers.">
<link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48 96x96">
<link rel="icon" href="/favicon-96.png" type="image/png" sizes="96x96">
<link rel="preload" href="/fonts/press-start-2p-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/fonts/vt323-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/fonts/cinzel-latin-800-normal.woff2" as="font" type="font/woff2" crossorigin>
<style>
@font-face{
  font-family:"Press Start 2P";
  src:url("/fonts/press-start-2p-latin.woff2") format("woff2"),
      url("/static/fonts/press-start-2p-latin.woff2") format("woff2"),
      url("../static/fonts/press-start-2p-latin.woff2") format("woff2");
  font-style:normal;
  font-weight:400;
  font-display:optional;
}
@font-face{
  font-family:"VT323";
  src:url("/fonts/vt323-latin.woff2") format("woff2"),
      url("/static/fonts/vt323-latin.woff2") format("woff2"),
      url("../static/fonts/vt323-latin.woff2") format("woff2");
  font-style:normal;
  font-weight:400;
  font-display:optional;
}
@font-face{
  font-family:"Cinzel";
  src:url("/fonts/cinzel-latin-800-normal.woff2") format("woff2"),
      url("/static/fonts/cinzel-latin-800-normal.woff2") format("woff2"),
      url("../static/fonts/cinzel-latin-800-normal.woff2") format("woff2");
  font-style:normal;
  font-weight:800;
  font-display:optional;
}
:root{
  --bg:#0d0f17;
  --panel:#161a28;
  --panel2:#1e2334;
  --ink:#eae7d6;
  --muted:#8b90a0;
  --gold:#ffce43;
  --green:#66d16b;
  --red:#e8595f;
  --blue:#5fb0f0;
  --line:#2b3145;
  --shadow:#000;
  --pixel:"Press Start 2P", ui-monospace, monospace;
  --term:"VT323", ui-monospace, monospace;
}
*{box-sizing:border-box}
body{
  margin:0;
  background:var(--bg);
  color:var(--ink);
  font-family:var(--term);
  font-size:20px;
  line-height:1.15;
  -webkit-font-smoothing:none;
  image-rendering:pixelated;
}
a{color:var(--blue);text-decoration:none}
a:hover{text-decoration:underline}
.wrap{max-width:1130px;margin:0 auto;padding:22px 18px 60px;position:relative;z-index:1}

/* Header & Nav */
.ihead{border-bottom:3px solid var(--line);padding-bottom:14px;margin-bottom:18px}
.page-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:16px}
.iname{font-family:"Cinzel",Georgia,serif;font-size:32px;font-weight:800;letter-spacing:1px;color:var(--gold);margin:0 0 10px;line-height:1.2;text-shadow:2px 2px 0 var(--shadow)}
.iname .itopic{display:block;margin-top:6px;font-family:var(--pixel);font-size:9px;font-weight:400;letter-spacing:1px;line-height:1.6;text-transform:uppercase;color:var(--muted);text-shadow:none}
.site-nav-stack{display:flex;flex-direction:column;align-items:flex-end;gap:8px;margin-left:auto;min-width:0}
.games{display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end;margin:0}
.game{font-family:var(--pixel);font-size:8px;letter-spacing:1px;text-transform:uppercase;color:var(--ink);background:var(--panel);border:2px solid var(--line);box-shadow:3px 3px 0 var(--shadow);padding:8px 10px;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:8px;line-height:1}
.game:hover{border-color:var(--gold)}
.game[aria-current="true"],.game[aria-current="page"]{background:var(--gold);color:#161018;border-color:var(--gold)}
.game:focus-visible{outline:2px solid var(--blue);outline-offset:2px}

.breadcrumbs{display:flex;flex-wrap:wrap;align-items:center;gap:7px;font-family:var(--pixel);font-size:8px;letter-spacing:1px;text-transform:uppercase;color:var(--muted);margin-top:4px}
.breadcrumbs a{color:var(--blue);text-decoration:none}
.breadcrumbs a:hover{text-decoration:underline}
.breadcrumbs span[aria-current="page"]{color:var(--ink)}

/* Five-across survey tiles */
.tiles5{display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin-bottom:20px}
@media (max-width:900px){.tiles5{grid-template-columns:repeat(3,1fr)}}
@media (max-width:600px){.tiles5{grid-template-columns:repeat(2,1fr)}}
.tile{background:var(--panel);border:2px solid var(--line);box-shadow:5px 5px 0 var(--shadow);padding:10px 10px 11px;overflow-wrap:anywhere}
.tile .k{font-family:var(--pixel);font-size:8px;color:var(--muted);letter-spacing:1px;text-transform:uppercase}
.tile .v{font-family:var(--pixel);font-size:14px;color:var(--gold);margin-top:10px;line-height:1.35}
.tile .v.gr{color:var(--green)}
.tile .v.bl{color:var(--blue)}
.tile .v.rd{color:var(--red)}
.tile .s{font-size:16px;color:var(--muted);margin-top:5px}

/* Page controls & toolbar */
.page-controls{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;margin-bottom:20px}
.controls-left{display:flex;flex-wrap:wrap;align-items:center;gap:8px;flex:1 1 540px}
.controls-right{display:flex;align-items:center;gap:10px}
.search-box{display:inline-flex;align-items:center;gap:8px;background:var(--panel);border:2px solid var(--line);box-shadow:3px 3px 0 var(--shadow);padding:6px 10px}
.search-label{font-family:var(--pixel);font-size:8px;letter-spacing:1px;color:var(--gold)}
.search-input{background:transparent;border:0;outline:none;color:var(--ink);font-family:var(--term);font-size:20px;width:170px}
.search-input::placeholder{color:var(--muted)}
.chips{display:flex;flex-wrap:wrap;gap:6px}
.chip{font-family:var(--pixel);font-size:8px;letter-spacing:1px;text-transform:uppercase;color:var(--ink);background:var(--panel);border:2px solid var(--line);box-shadow:3px 3px 0 var(--shadow);padding:7px 9px;cursor:pointer;line-height:1}
.chip:hover{border-color:var(--gold)}
.chip[aria-pressed="true"]{background:var(--gold);color:#161018;border-color:var(--gold)}
.chip:focus-visible{outline:2px solid var(--blue);outline-offset:2px}
.sort-wrap{display:inline-flex;align-items:center;gap:7px}
.sort-label{font-family:var(--pixel);font-size:8px;letter-spacing:1px;color:var(--muted);text-transform:uppercase}
.sort-select{background:var(--panel);border:2px solid var(--line);box-shadow:3px 3px 0 var(--shadow);color:var(--ink);font-family:var(--term);font-size:18px;padding:5px 8px;outline:none;cursor:pointer}
.sort-select:hover,.sort-select:focus-visible{border-color:var(--gold)}

/* Two-column panel grid */
.panelgrid{display:grid;grid-template-columns:minmax(0,1.05fr) minmax(0,1.25fr);gap:18px;align-items:start;margin-bottom:22px}
@media (max-width:820px){.panelgrid{grid-template-columns:1fr}}

.panel{background:var(--panel);border:2px solid var(--line);box-shadow:6px 6px 0 var(--shadow);padding:16px}
.panel-h{display:flex;justify-content:space-between;align-items:baseline;gap:10px;margin-bottom:14px;border-bottom:2px solid var(--line);padding-bottom:10px}
.ptitle{font-family:var(--pixel);font-size:9px;color:var(--gold);letter-spacing:1px;text-transform:uppercase;margin:0}
.pbadge{font-family:var(--pixel);font-size:8px;color:var(--muted);letter-spacing:1px}

/* Realm list cards */
.realm-list{display:grid;gap:8px}
.realm-card{display:block;width:100%;text-align:left;background:var(--panel2);border:2px solid var(--line);box-shadow:3px 3px 0 var(--shadow);padding:11px 13px;color:var(--ink);cursor:pointer;position:relative}
.realm-card:hover{border-color:var(--gold)}
.realm-card.active{background:var(--gold);color:#161018;border-color:var(--gold);box-shadow:4px 4px 0 var(--shadow)}
.realm-card.active .rc-name{color:#161018}
.realm-card.active .rc-tag{color:#3a2b10}
.realm-card.active .rc-total{color:#161018}
.realm-card.active .rc-split-legend{color:#2a2010}
.realm-card.active .rc-split-legend .alliance{color:#0e3a7a}
.realm-card.active .rc-split-legend .horde{color:#7a141a}
.realm-card:focus-visible{outline:2px solid var(--blue);outline-offset:2px}

.rc-head{display:flex;justify-content:space-between;align-items:baseline;gap:8px}
.rc-left{min-width:0}
.rc-name{font-family:var(--pixel);font-size:10px;letter-spacing:1px;text-transform:uppercase;color:var(--ink);line-height:1.4}
.rc-tag{font-family:var(--pixel);font-size:7px;letter-spacing:1px;color:var(--muted);text-transform:uppercase;margin-top:3px}
.rc-total{font-family:var(--term);font-size:24px;color:var(--gold);font-variant-numeric:tabular-nums;line-height:1;white-space:nowrap}
.rc-total small{font-size:15px;color:var(--muted);margin-left:4px}
.realm-card.active .rc-total small{color:#3a2b10}

/* Dual-segment faction bar */
.bar-track{height:9px;background:#090b12;border:1px solid #000;display:flex;overflow:hidden;margin:8px 0 6px}
.bar-seg-alliance{background:#3f83f8;height:100%}
.bar-seg-horde{background:#d9363e;height:100%}
.rc-split-legend{display:flex;justify-content:space-between;font-size:17px;color:var(--muted)}
.alliance{color:var(--blue)}
.horde{color:var(--red)}

/* Selected Realm Dossier (Right pane) */
.dossier-top{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:14px}
.dossier-name{font-family:var(--pixel);font-size:16px;color:var(--gold);letter-spacing:1px;text-transform:uppercase;line-height:1.2}
.dossier-cluster{font-family:var(--pixel);font-size:8px;color:var(--muted);letter-spacing:1px;text-transform:uppercase;margin-top:5px}
.dossier-total-box{text-align:right}
.dossier-total-num{font-family:var(--term);font-size:40px;color:var(--gold);line-height:1;font-variant-numeric:tabular-nums}
.dossier-total-lbl{font-family:var(--pixel);font-size:7px;letter-spacing:1px;color:var(--muted);text-transform:uppercase;margin-top:4px}

.dossier-stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:16px}
@media (max-width:580px){.dossier-stats-row{grid-template-columns:repeat(2,1fr)}}
.dossier-stat{background:var(--panel2);border:2px solid var(--line);box-shadow:3px 3px 0 var(--shadow);padding:8px 10px}
.dossier-stat .k{font-family:var(--pixel);font-size:7px;color:var(--muted);letter-spacing:1px;text-transform:uppercase}
.dossier-stat .v{font-family:var(--pixel);font-size:12px;margin-top:6px;line-height:1.2}
.dossier-stat .s{font-size:15px;color:var(--muted);margin-top:2px}

/* Large faction split gauge */
.section-subhead{font-family:var(--pixel);font-size:8px;letter-spacing:1px;text-transform:uppercase;color:var(--gold);margin:16px 0 8px;display:flex;justify-content:space-between}
.large-split-track{height:16px;background:#090b12;border:2px solid var(--shadow);display:flex;overflow:hidden;box-shadow:2px 2px 0 var(--shadow)}
.large-split-legend{display:flex;justify-content:space-between;font-size:18px;color:var(--muted);margin-top:5px}

/* Combos grid in dossier */
.combos-wrap{margin-top:14px}
.dossier-combogrid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
@media (max-width:640px){.dossier-combogrid{grid-template-columns:1fr}}
.comboblock{background:var(--panel2);border:2px solid var(--line);box-shadow:3px 3px 0 var(--shadow)}
.comboblock.alliance-block{border-left:4px solid #3f83f8}
.comboblock.horde-block{border-left:4px solid #d9363e}
.combofaction{font-family:var(--pixel);font-size:8px;letter-spacing:1px;padding:8px 10px 4px}
.combofaction.alliance-label{color:var(--blue)}
.combofaction.horde-label{color:var(--red)}
.combocard{display:flex;align-items:center;gap:10px;padding:8px 10px}
.combocard+.combocard{border-top:1px solid var(--line)}
.comboicons{display:flex;align-items:flex-start;min-width:54px}
.comboicons img{display:block;width:30px;height:30px;object-fit:cover;border:2px solid #b99b55;box-shadow:1px 1px 0 #000;background:#111}
.comboicons img+img{margin-left:-10px;margin-top:8px}
.comboname{font-family:var(--pixel);font-size:9px;line-height:1.4;color:var(--ink);margin-bottom:3px}
.combostat{color:var(--muted);font-size:16px}

/* Class breakdown bars */
.class-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:14px}
@media (max-width:600px){.class-grid{grid-template-columns:1fr}}
.faction-class-col{background:var(--panel2);border:2px solid var(--line);box-shadow:3px 3px 0 var(--shadow);padding:10px}
.fclass-title{font-family:var(--pixel);font-size:8px;letter-spacing:1px;text-transform:uppercase;margin-bottom:8px}
.bar-row{display:grid;grid-template-columns:75px 1fr 42px;align-items:center;gap:6px;margin-bottom:4px;font-size:17px}
.bar-name{font-family:var(--pixel);font-size:7px;letter-spacing:1px;text-transform:uppercase;color:var(--ink);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.bar-meter{background:#090b12;border:1px solid var(--line);height:11px}
.bar-meter i{display:block;height:100%}
.bar-count{text-align:right;color:var(--muted);font-variant-numeric:tabular-nums;font-size:16px}

/* Cluster notes box */
.cluster-info{margin-top:16px;background:var(--panel2);border:2px solid var(--line);padding:10px 12px;font-size:17px;color:var(--muted)}
.cluster-info strong{font-family:var(--pixel);font-size:7px;color:var(--gold);text-transform:uppercase;letter-spacing:1px;display:block;margin-bottom:4px}
.dossier-actions{margin-top:16px;display:flex;gap:10px;flex-wrap:wrap}
.cta-btn{font-family:var(--pixel);font-size:8px;letter-spacing:1px;text-transform:uppercase;padding:10px 12px;border:2px solid var(--gold);background:var(--gold);color:#161018;box-shadow:3px 3px 0 var(--shadow);display:inline-flex;align-items:center;gap:6px;cursor:pointer}
.cta-btn:hover{background:#ffe070;border-color:#ffe070;text-decoration:none}
.cta-btn.secondary{background:var(--panel2);color:var(--ink);border-color:var(--line)}
.cta-btn.secondary:hover{border-color:var(--gold);color:var(--gold)}

/* Data Honesty Panel */
.ledger-note{background:var(--panel);border:2px solid var(--line);box-shadow:5px 5px 0 var(--shadow);padding:14px 16px;margin-bottom:26px}
.ledger-note-title{font-family:var(--pixel);font-size:8px;letter-spacing:1px;text-transform:uppercase;color:var(--gold);margin-bottom:8px}
.ledger-note p{margin:0;font-size:18px;color:var(--muted);line-height:1.35}

/* Site footer (cabinet style) */
.site-footer{max-width:1094px;margin:0 auto 24px;padding:20px 18px;display:grid;grid-template-columns:minmax(190px,1fr) auto minmax(150px,1fr);align-items:center;gap:24px;background:var(--gold);color:var(--panel);border:2px solid var(--shadow);box-shadow:5px 5px 0 var(--shadow)}
.site-footer-brand{display:grid;gap:8px;min-width:0}
.site-footer-brand>a{width:max-content;max-width:100%;font-family:var(--pixel);font-size:15px;line-height:1.3;color:var(--panel);text-decoration:none;overflow-wrap:anywhere}
.site-footer-brand small{font-family:var(--term);font-size:17px;line-height:1.1}
.site-footer-links,.site-footer-shortcuts{display:flex;align-items:center;gap:18px}
.site-footer-links a{font-family:var(--term);font-size:21px;line-height:1.1;color:var(--panel);text-decoration:none;white-space:nowrap}
.site-footer-shortcuts{justify-content:flex-end;gap:10px}
.site-footer-shortcut{display:grid;place-items:center;width:38px;height:38px;background:var(--panel);border:2px solid var(--shadow);box-shadow:3px 3px 0 var(--muted);color:var(--ink);font-family:var(--term);font-size:25px;text-decoration:none}
.site-footer a:hover{text-decoration:underline}
.site-footer-shortcut:hover{background:var(--panel2);text-decoration:none!important}
.site-footer a:focus-visible{outline:2px solid var(--blue);outline-offset:3px}
@media (max-width:900px){.site-footer{grid-template-columns:1fr auto}.site-footer-links{grid-column:1/-1;grid-row:2;flex-wrap:wrap}.site-footer-shortcuts{grid-column:2;grid-row:1}}
@media (max-width:520px){.site-footer{margin:0 15px 20px;padding:16px;gap:18px}.site-footer-brand{grid-column:1/-1}.site-footer-links{grid-column:1/-1;grid-row:2;gap:10px 18px}.site-footer-shortcuts{grid-column:1/-1;grid-row:3;justify-content:flex-start}}

.empty-state{padding:26px;text-align:center;color:var(--muted);border:2px dashed var(--line);font-size:20px}
</style>
</head>
<body>
<div class="wrap">
  <header class="ihead">
    <div class="page-heading">
      <h1 class="iname">WoWCensus<span class="itopic">WoW Classic Era Realm Explorer</span></h1>
      <div class="site-nav-stack">
        <nav class="games game-nav" aria-label="Game edition">
          <a class="game" href="/forever/">Forever</a>
          <a class="game" href="/tbc/">TBC Anniversary</a>
          <a class="game" aria-current="true" href="/classic/">Classic Era</a>
          <a class="game" href="/sod/">SoD</a>
          <a class="game" href="/hardcore/">Hardcore</a>
          <a class="game" href="/mop/">MoP Classic</a>
          <a class="game" href="/retail/">Retail</a>
        </nav>
        <nav class="games page-nav" aria-label="Site pages">
          <a class="game" href="/classic/">Census</a>
          <a class="game" href="/classic/auctionhouse">Auction House</a>
          <a class="game" href="/classic/guilds">Guilds</a>
          <a class="game" href="/classic/geography">Geography</a>
          <a class="game" href="/classic/talents">Talents</a>
          <a class="game" href="/classic/gear">Gear</a>
          <a class="game" aria-current="page" href="#">Realm Explorer</a>
        </nav>
      </div>
    </div>
    <nav class="breadcrumbs" aria-label="Breadcrumb">
      <a href="/">WoWCensus</a>
      <span aria-hidden="true">/</span>
      <a href="/classic/">Classic Era</a>
      <span aria-hidden="true">/</span>
      <span aria-current="page">Realm Explorer</span>
    </nav>
  </header>

  <main>
    <!-- Five-tile population survey row -->
    <section class="tiles5" aria-label="Classic Era Survey Summary">
      <div class="tile">
        <div class="k">Realms Tracked</div>
        <div class="v">11 REALMS</div>
        <div class="s">4 active clusters</div>
      </div>
      <div class="tile">
        <div class="k">Sampled Pool</div>
        <div class="v">18,479</div>
        <div class="s">distinct /who characters</div>
      </div>
      <div class="tile">
        <div class="k">Alliance Sampled</div>
        <div class="v bl">10,012</div>
        <div class="s">54.2% of observed pool</div>
      </div>
      <div class="tile">
        <div class="k">Horde Sampled</div>
        <div class="v rd">8,467</div>
        <div class="s">45.8% of observed pool</div>
      </div>
      <div class="tile">
        <div class="k">Largest Hub</div>
        <div class="v gr">ASHKANDI</div>
        <div class="s">6,978 observed (55% A / 45% H)</div>
      </div>
    </section>

    <!-- Page Controls: Quick Filters & Search Toolbar -->
    <div class="page-controls">
      <div class="controls-left">
        <div class="search-box">
          <span class="search-label">FIND:</span>
          <input id="realm-search" class="search-input" type="search" placeholder="Filter realms…" aria-label="Filter realms by name">
        </div>
        <div class="chips" role="radiogroup" aria-label="Realm group filter">
          <button class="chip" type="button" data-filter="all" aria-pressed="true">All (11)</button>
          <button class="chip" type="button" data-filter="major" aria-pressed="false">High Activity (>800)</button>
          <button class="chip" type="button" data-filter="pve" aria-pressed="false">PvE Clusters</button>
          <button class="chip" type="button" data-filter="pvp" aria-pressed="false">PvP Clusters</button>
          <button class="chip" type="button" data-filter="oceanic" aria-pressed="false">Oceanic</button>
        </div>
      </div>
      <div class="controls-right">
        <div class="sort-wrap">
          <label for="realm-sort" class="sort-label">SORT:</label>
          <select id="realm-sort" class="sort-select">
            <option value="observed" selected>Most Observed</option>
            <option value="name">Realm Name (A-Z)</option>
            <option value="alliance">Highest Alliance %</option>
            <option value="horde">Highest Horde %</option>
          </select>
        </div>
      </div>
    </div>

    <!-- Main two-column panelgrid -->
    <div class="panelgrid">
      <!-- Left Column: Realm Directory -->
      <section class="panel" aria-label="Realm Directory">
        <div class="panel-h">
          <h2 class="ptitle">Realm Directory</h2>
          <span id="realm-count-badge" class="pbadge">11 OF 11 REALMS</span>
        </div>
        <div id="realm-list" class="realm-list" role="listbox" aria-label="Classic Era Realms">
${preRenderedList}
        </div>
      </section>

      <!-- Right Column: Selected Realm Dossier -->
      <section class="panel" aria-label="Selected Realm Ledger">
        <div class="panel-h">
          <h2 class="ptitle">Realm Dossier</h2>
          <span class="pbadge">14-DAY CENSUS SNAPSHOT</span>
        </div>
        <div id="realm-dossier" aria-live="polite">
${preRenderedDossier}
        </div>
      </section>
    </div>

    <!-- Data Honesty Note -->
    <div class="ledger-note">
      <div class="ledger-note-title">Ledger Integrity & Sampling Notice</div>
      <p>All data presented is derived from distinct character sightings captured during in-game <code>/who</code> census sweeps across a 14-day observation window ending October 14, 2026. These figures represent demographic composition and faction distribution within the sampled pool, not total concurrent online players. A faction showing zero observed characters indicates that faction fell outside the local scanning character's partition, not necessarily complete server absence.</p>
    </div>
  </main>

  <footer class="site-footer" aria-label="Site footer">
    <div class="site-footer-brand">
      <a href="/">WoWCensus</a>
      <small>© 2026 WoWCensus. All rights reserved.</small>
    </div>
    <nav class="site-footer-links" aria-label="Footer">
      <a href="/classic/">Census</a>
      <a href="/classic/combos">Race + Class</a>
      <a href="/classic/auctionhouse">Auction House</a>
      <a href="/classic/talents">Talents</a>
      <a href="#">Realm Explorer</a>
    </nav>
    <nav class="site-footer-shortcuts" aria-label="More pages">
      <a class="site-footer-shortcut" href="/classic/guilds" aria-label="Guilds" title="Guilds"><span aria-hidden="true">♜</span></a>
      <a class="site-footer-shortcut" href="/classic/geography" aria-label="Geography" title="Geography"><span aria-hidden="true">◎</span></a>
      <a class="site-footer-shortcut" href="/classic/gear" aria-label="Gear" title="Gear"><span aria-hidden="true">⚒</span></a>
    </nav>
  </footer>
</div>

<script>
(function(){
  const REALMS = ${JSON.stringify(REALMS, null, 2)};

  const CLASS_COLORS = {
    WARRIOR: '#C79C6E',
    PALADIN: '#F58CBA',
    HUNTER: '#ABD473',
    ROGUE: '#FFF569',
    PRIEST: '#FFFFFF',
    SHAMAN: '#0070DE',
    MAGE: '#69CCF0',
    WARLOCK: '#9482C9',
    DRUID: '#FF7D0A'
  };

  const RACE_ICONS = {
    Human: 'achievement_character_human_male.jpg',
    Dwarf: 'achievement_character_dwarf_male.jpg',
    'Night Elf': 'achievement_character_nightelf_male.jpg',
    Gnome: 'achievement_character_gnome_male.jpg',
    Orc: 'achievement_character_orc_male.jpg',
    Undead: 'achievement_character_undead_male.jpg',
    Tauren: 'achievement_character_tauren_male.jpg',
    Troll: 'achievement_character_troll_male.jpg'
  };

  let activeRealmName = 'Ashkandi';
  let activeFilter = 'all';

  const fmt = n => (n || 0).toLocaleString('en-US');
  const esc = s => String(s || '').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;');

  function iconPath(filename) {
    return '/assets/images/wow/icons/large/' + filename;
  }

  function handleImgError(img) {
    if (!img.dataset.failedOnce) {
      img.dataset.failedOnce = "1";
      img.src = '/static/assets/images/wow/icons/large/' + img.dataset.icon;
    } else if (!img.dataset.failedTwice) {
      img.dataset.failedTwice = "1";
      img.src = '../static/assets/images/wow/icons/large/' + img.dataset.icon;
    }
  }
  window.handleImgError = handleImgError;

  function totalChars(r) {
    return (r.alliance || 0) + (r.horde || 0);
  }

  function render() {
    const q = document.querySelector('#realm-search').value.trim().toLowerCase();
    const sortVal = document.querySelector('#realm-sort').value;

    let filtered = REALMS.filter(r => {
      const matchSearch = r.name.toLowerCase().includes(q) || r.region.toLowerCase().includes(q) || r.type.toLowerCase().includes(q);
      if (!matchSearch) return false;
      const tot = totalChars(r);
      if (activeFilter === 'major') return tot >= 800;
      if (activeFilter === 'pve') return r.type.toLowerCase().includes('pve');
      if (activeFilter === 'pvp') return r.type.toLowerCase().includes('pvp');
      if (activeFilter === 'oceanic') return r.region === 'Oceanic';
      return true;
    });

    filtered.sort((a, b) => {
      const totA = totalChars(a);
      const totB = totalChars(b);
      if (sortVal === 'name') return a.name.localeCompare(b.name);
      if (sortVal === 'alliance') {
        const pctA = totA ? (a.alliance / totA) : 0;
        const pctB = totB ? (b.alliance / totB) : 0;
        return pctB - pctA;
      }
      if (sortVal === 'horde') {
        const pctA = totA ? (a.horde / totA) : 0;
        const pctB = totB ? (b.horde / totB) : 0;
        return pctB - pctA;
      }
      return totB - totA;
    });

    document.querySelector('#realm-count-badge').textContent = \`\${filtered.length} OF \${REALMS.length} REALMS\`;

    const listEl = document.querySelector('#realm-list');
    if (!filtered.length) {
      listEl.innerHTML = '<div class="empty-state">No Classic Era realms match your search or filter criteria.</div>';
    } else {
      listEl.innerHTML = filtered.map(r => {
        const tot = totalChars(r);
        const aPct = tot ? Math.round((r.alliance / tot) * 100) : 0;
        const hPct = tot ? 100 - aPct : 0;
        const isSel = r.name === activeRealmName;

        return \`
          <div class="realm-card \${isSel ? 'active' : ''}" role="option" aria-selected="\${isSel}" tabindex="0" data-realm="\${esc(r.name)}">
            <div class="rc-head">
              <div class="rc-left">
                <div class="rc-name">\${esc(r.name)}</div>
                <div class="rc-tag">\${esc(r.region)} · \${esc(r.type)}</div>
              </div>
              <div class="rc-total">\${fmt(tot)}<small>CHARS</small></div>
            </div>
            <div class="bar-track" aria-hidden="true">
              <span class="bar-seg-alliance" style="width:\${aPct}%"></span>
              <span class="bar-seg-horde" style="width:\${hPct}%"></span>
            </div>
            <div class="rc-split-legend">
              <span class="alliance">◆ \${fmt(r.alliance)} A (\${aPct}%)</span>
              <span class="horde">(\${hPct}%) H \${fmt(r.horde)} ◆</span>
            </div>
          </div>
        \`;
      }).join('');
    }

    bindRealmEvents();
    renderDossier();
  }

  function bindRealmEvents() {
    document.querySelectorAll('.realm-card').forEach(card => {
      const clickHandler = () => {
        activeRealmName = card.dataset.realm;
        // Fast active class toggle
        document.querySelectorAll('.realm-card').forEach(c => {
          c.classList.remove('active');
          c.setAttribute('aria-selected', 'false');
        });
        card.classList.add('active');
        card.setAttribute('aria-selected', 'true');
        renderDossier();
      };
      card.onclick = clickHandler;
      card.onkeydown = e => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          clickHandler();
        }
      };
    });
  }

  function renderDossier() {
    const r = REALMS.find(x => x.name === activeRealmName) || REALMS[0];
    const dossierEl = document.querySelector('#realm-dossier');
    const tot = totalChars(r);
    const aPct = tot ? ((r.alliance / tot) * 100).toFixed(1) : "0.0";
    const hPct = tot ? ((r.horde / tot) * 100).toFixed(1) : "0.0";

    const aCombos = (r.combos && r.combos.Alliance) || [];
    const hCombos = (r.combos && r.combos.Horde) || [];

    function renderComboCard(c, factionTot, isHorde) {
      const pct = factionTot ? ((c.count / factionTot) * 100).toFixed(1) : "0.0";
      const raceIconFile = RACE_ICONS[c.race] || 'inv_misc_questionmark.jpg';
      const classIconFile = 'classicon_' + c.cls.toLowerCase() + '.jpg';
      const classProper = c.cls.charAt(0) + c.cls.slice(1).toLowerCase();

      return \`
        <article class="combocard">
          <div class="comboicons">
            <img src="\${iconPath(raceIconFile)}" data-icon="\${raceIconFile}" alt="\${c.race} icon" onerror="handleImgError(this)" loading="lazy">
            <img src="\${iconPath(classIconFile)}" data-icon="\${classIconFile}" alt="\${classProper} icon" onerror="handleImgError(this)" loading="lazy">
          </div>
          <div>
            <div class="comboname">\${esc(c.race)} \${esc(classProper)}</div>
            <div class="combostat">\${fmt(c.count)} &middot; \${pct}% of \${isHorde ? 'Horde' : 'Alliance'}</div>
          </div>
        </article>
      \`;
    }

    function renderClassBars(clsObj, factionTot, defaultColor) {
      if (!clsObj || !Object.keys(clsObj).length) {
        return '<div style="font-size:16px;color:var(--muted);padding:8px">No recorded class sightings for this faction.</div>';
      }
      const sorted = Object.entries(clsObj).sort((a,b) => b[1] - a[1]);
      const maxVal = sorted[0][1] || 1;

      return sorted.map(([cls, count]) => {
        const color = CLASS_COLORS[cls] || defaultColor;
        const widthPct = ((count / maxVal) * 100).toFixed(1);
        const proper = cls.charAt(0) + cls.slice(1).toLowerCase();
        return \`
          <div class="bar-row">
            <span class="bar-name">\${esc(proper)}</span>
            <div class="bar-meter"><i style="width:\${widthPct}%;background:\${color}"></i></div>
            <span class="bar-count">\${fmt(count)}</span>
          </div>
        \`;
      }).join('');
    }

    function renderRaceBars(raceObj, factionTot, factionColor) {
      if (!raceObj || !Object.keys(raceObj).length) {
        return '<div style="font-size:16px;color:var(--muted);padding:8px">No recorded race sightings for this faction.</div>';
      }
      const sorted = Object.entries(raceObj).sort((a,b) => b[1] - a[1]);
      const maxVal = sorted[0][1] || 1;

      return sorted.map(([race, count]) => {
        const widthPct = ((count / maxVal) * 100).toFixed(1);
        return \`
          <div class="bar-row">
            <span class="bar-name">\${esc(race)}</span>
            <div class="bar-meter"><i style="width:\${widthPct}%;background:\${factionColor}"></i></div>
            <span class="bar-count">\${fmt(count)}</span>
          </div>
        \`;
      }).join('');
    }

    dossierEl.innerHTML = \`
      <div class="dossier-top">
        <div>
          <div class="dossier-name">\${esc(r.name)}</div>
          <div class="dossier-cluster">\${esc(r.region)} · \${esc(r.type)} · Classic Era</div>
        </div>
        <div class="dossier-total-box">
          <div class="dossier-total-num">\${fmt(tot)}</div>
          <div class="dossier-total-lbl">OBSERVED CHARACTERS</div>
        </div>
      </div>

      <div class="dossier-stats-row">
        <div class="dossier-stat">
          <div class="k">ALLIANCE OBSERVED</div>
          <div class="v alliance">\${fmt(r.alliance)}</div>
          <div class="s">\${aPct}% share</div>
        </div>
        <div class="dossier-stat">
          <div class="k">HORDE OBSERVED</div>
          <div class="v horde">\${fmt(r.horde)}</div>
          <div class="s">\${hPct}% share</div>
        </div>
        <div class="dossier-stat">
          <div class="k">RATIO (A : H)</div>
          <div class="v">\${r.horde > 0 ? (r.alliance / r.horde).toFixed(2) + ' : 1' : (r.alliance > 0 ? 'ALLIANCE' : 'HORDE')}</div>
          <div class="s">\${tot > 0 ? (Math.abs(parseFloat(aPct) - parseFloat(hPct)) < 15 ? 'Balanced' : 'Skewed') : 'N/A'}</div>
        </div>
        <div class="dossier-stat">
          <div class="k">SURVEY SAMPLES</div>
          <div class="v">\${fmt(r.samples)}</div>
          <div class="s">\${fmt(r.observed)} total sightings</div>
        </div>
      </div>

      <div class="section-subhead">
        <span>FACTION BALANCE METER</span>
        <span>\${aPct}% ALLIANCE · \${hPct}% HORDE</span>
      </div>
      <div class="large-split-track" aria-label="Alliance \${aPct} percent, Horde \${hPct} percent">
        <span class="bar-seg-alliance" style="width:\${aPct}%"></span>
        <span class="bar-seg-horde" style="width:\${hPct}%"></span>
      </div>
      <div class="large-split-legend">
        <span class="alliance">◆ Alliance: \${fmt(r.alliance)} characters (\${aPct}%)</span>
        <span class="horde">(\${hPct}%) \${fmt(r.horde)} characters :Horde ◆</span>
      </div>

      \${(aCombos.length || hCombos.length) ? \`
        <div class="combos-wrap">
          <div class="section-subhead">TOP OBSERVED RACE + CLASS COMBINATIONS</div>
          <div class="dossier-combogrid">
            \${aCombos.length ? \`
              <section class="comboblock alliance-block">
                <div class="combofaction alliance-label">Alliance Combos</div>
                \${aCombos.map(c => renderComboCard(c, r.alliance, false)).join('')}
              </section>
            \` : '<div class="empty-state" style="padding:12px;font-size:16px">No Alliance combos observed.</div>'}

            \${hCombos.length ? \`
              <section class="comboblock horde-block">
                <div class="combofaction horde-label">Horde Combos</div>
                \${hCombos.map(c => renderComboCard(c, r.horde, true)).join('')}
              </section>
            \` : '<div class="empty-state" style="padding:12px;font-size:16px">No Horde combos observed.</div>'}
          </div>
        </div>
      \` : ''}

      <div class="section-subhead" style="margin-top:20px">CLASS DEMOGRAPHICS</div>
      <div class="class-grid">
        <div class="faction-class-col">
          <div class="fclass-title alliance">Alliance Classes (\${fmt(r.alliance)})</div>
          \${renderClassBars(r.classes.Alliance, r.alliance, '#3f83f8')}
        </div>
        <div class="faction-class-col">
          <div class="fclass-title horde">Horde Classes (\${fmt(r.horde)})</div>
          \${renderClassBars(r.classes.Horde, r.horde, '#d9363e')}
        </div>
      </div>

      <div class="section-subhead" style="margin-top:20px">RACE DEMOGRAPHICS</div>
      <div class="class-grid">
        <div class="faction-class-col">
          <div class="fclass-title alliance">Alliance Races (\${fmt(r.alliance)})</div>
          \${renderRaceBars(r.races.Alliance, r.alliance, '#3f83f8')}
        </div>
        <div class="faction-class-col">
          <div class="fclass-title horde">Horde Races (\${fmt(r.horde)})</div>
          \${renderRaceBars(r.races.Horde, r.horde, '#d9363e')}
        </div>
      </div>

      <div class="cluster-info">
        <strong>REALM CLUSTER INFRASTRUCTURE</strong>
        Connected Realm Grouping: \${esc(r.cluster)}.<br>
        Cross-realm clustering shares auction listings, zone layering, and local grouping across connected servers.
      </div>

      <div class="dossier-actions">
        <a class="cta-btn" href="/classic/?realm=\${encodeURIComponent(r.name)}">
          EXPLORE \${esc(r.name.toUpperCase())} IN FULL CENSUS ↗
        </a>
        <a class="cta-btn secondary" href="/classic/auctionhouse">
          VIEW AUCTION HOUSE ↗
        </a>
      </div>
    \`;
  }

  // Filter chips interaction
  document.querySelectorAll('.chips button[data-filter]').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.chips button[data-filter]').forEach(b => b.setAttribute('aria-pressed', 'false'));
      btn.setAttribute('aria-pressed', 'true');
      activeFilter = btn.dataset.filter;
      render();
    });
  });

  // Search input & sort interaction
  document.querySelector('#realm-search').addEventListener('input', render);
  document.querySelector('#realm-sort').addEventListener('change', render);

  // Initial event binding
  bindRealmEvents();
})();
</script>
</body>
</html>`;

fs.writeFileSync('mockups/classic-era-realm-explorer.html', html, 'utf8');
fs.writeFileSync('static/mockups/classic-era-realm-explorer.html', html, 'utf8');
console.log('Successfully generated mockups/classic-era-realm-explorer.html and static/mockups/classic-era-realm-explorer.html with pre-rendered HTML!');
