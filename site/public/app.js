// MarketLens Arcade — index screener. Region datasets and uploaded realms.
(function () {
  "use strict";
  var params = new URLSearchParams(location.search);
  var game = params.get("game") || "classic-progression";
  var isRealm = game.indexOf("realm:") === 0;

  // Wowhead site branch per dataset (empty = retail). Its tooltips.js reads this
  // path off each link to pick the right icon + tooltip data. Uploaded realm
  // datasets receive their source flavor from the API.
  var WH_BRANCH = { "classic-progression": "tbc", "classic": "classic", "retail": "" };
  var whBranch = isRealm ? "tbc" : (WH_BRANCH[game] != null ? WH_BRANCH[game] : "tbc");
  function whItem(id) { return "https://www.wowhead.com/" + (whBranch ? whBranch + "/" : "") + "item=" + id; }
  function whRefresh() {
    if (window.$WowheadPower && $WowheadPower.refreshLinks) {
      try { $WowheadPower.refreshLinks(); } catch (e) {}
    }
  }

  function clamp(v, a, b) { return v < a ? a : v > b ? b : v; }
  function scale100(v, mn, mx) { return mx === mn ? 0 : clamp((v - mn) / (mx - mn) * 100, 0, 100); }
  function demand(sr, spd) {
    return Math.round(clamp(0.75 * scale100(sr, 0, 0.5) + 0.25 * scale100(Math.log(1 + spd), 0, Math.log(101)), 0, 100));
  }
  function gs(cop) {
    cop = Math.round(cop || 0);
    var g = Math.floor(cop / 10000), s = Math.floor((cop % 10000) / 100), c = cop % 100;
    if (g > 0) return g.toLocaleString() + "g " + s + "s";
    if (s > 0) return s + "s " + c + "c";
    return c + "c";
  }
  function big(cop) {
    var g = Math.floor((cop || 0) / 10000);
    if (g >= 1e6) return (g / 1e6).toFixed(1) + "M";
    if (g >= 1e3) return Math.round(g / 1e3) + "k";
    return "" + g;
  }
  function esc(x) { return String(x).replace(/[&<>]/g, function (m) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;" }[m]; }); }
  // Keep the readable "realm:" prefix (its colon) in navigable URLs; only encode
  // the realm name itself (spaces etc). Region games have no special chars.
  function gameParam(g) { return g.indexOf("realm:") === 0 ? "realm:" + encodeURIComponent(g.slice(6)) : encodeURIComponent(g); }
  function href(g) { return "/?game=" + gameParam(g); }
  var GAME_LABEL = { "classic": "Classic Era", "classic-progression": "TBC Anniversary", "retail": "Retail" };
  var GAME_SHORT = { "classic": "Era", "classic-progression": "TBC", "retail": "Retail" };
  var GAME_ORDER = ["classic", "classic-progression", "retail"];
  function splitRealm(label) { var i = label.lastIndexOf("-"); return i > 0 ? [label.slice(0, i), label.slice(i + 1)] : [label, ""]; }

  var HERBS = new Set(["Peacebloom","Silverleaf","Earthroot","Mageroyal","Briarthorn","Bruiseweed","Stranglekelp","Wild Steelbloom","Kingsblood","Liferoot","Fadeleaf","Goldthorn","Khadgar's Whisker","Wintersbite","Firebloom","Purple Lotus","Arthas' Tears","Sungrass","Blindweed","Ghost Mushroom","Gromsblood","Golden Sansam","Dreamfoil","Mountain Silversage","Plaguebloom","Icecap","Black Lotus","Felweed","Dreaming Glory","Ragveil","Flame Cap","Terocone","Ancient Lichen","Netherbloom","Nightmare Vine","Mana Thistle","Fel Lotus","Bloodthistle"]);
  function classify(n) {
    if (/\bOre\b|\bBar\b|\bNugget\b/.test(n)) return "Ore & Bars";
    if (/\bCloth\b|^Bolt of|\bBolt\b/.test(n)) return "Cloth";
    if (/\bLeather\b|\bHide\b|\bScales?\b|\bFur\b/.test(n)) return "Leather";
    if (/\bPrimal\b|\bMote of\b/.test(n)) return "Primals";
    if (/\bPotion\b/.test(n)) return "Potions";
    if (/\bFlask\b/.test(n)) return "Flasks";
    if (/\bElixir\b/.test(n)) return "Elixirs";
    if (/\bDust\b|\bEssence\b|\bShard\b|Prismatic|\bNexus\b|Void Crystal/.test(n)) return "Enchanting";
    if (/^(Recipe|Pattern|Plans|Schematic|Formula|Design|Manual|Book|Technique):/.test(n)) return "Recipes";
    if (/\bBag\b|Backpack|\bPouch\b|\bQuiver\b|\bSack\b/.test(n)) return "Bags";
    if (HERBS.has(n)) return "Herbs";
    // Food, dishes, and drinks. Fish/berry/booze names rarely collide with gear
    // (and Gear is matched later anyway), so a wide net here is safe.
    if (/\bMeat\b|\bFillet\b|\bFish\b|\bRibs\b|Stew|\bPie\b|\bBread\b|Cheese|Sausage|\bClam\b|\bEgg\b|\bRoast\b|Sandwich|Omelet|Juice|\bBrew\b|Jerky|[Bb]erries|\b[Bb]erry\b|\bSpirits\b|Pretzel|\bCake\b|Cookie|Muffin|Biscuit|Pudding|\bJam\b|Chowder|Broth|Soup|\bSteak\b|Casserole|Delight|Feast|Ration|Snapper|\bCod\b|Salmon|Trout|\bBass\b|Tuna|Mackerel|Herring|Sardine|Catfish|\bEel\b|Yellowtail|Sagefish|Mudfish|Firefin|Rockscale|Elderhorn|\bMead\b|\bAle\b|\bWine\b|\bRum\b|Grog|Cider|Absinthe|Firewater|\bTea\b|Coffee|Slush|\bPort\b/.test(n)) return "Cooking";
    // Jewelcrafting stones — cut and raw share the base gem noun (e.g. "Runed
    // Living Ruby" and "Living Ruby" both match "Ruby"), so match the base.
    if (/\b(Ruby|Topaz|Nightseye|Dawnstone|Talasite|Moonstone|Draenite|Spessarite|Peridot|Garnet|Spinel|Pyrestone|Amethyst|Sapphire|Emerald|Lionseye|Citrine|Chrysoprase|Jade|Opal|Aquamarine|Diamond|Pearl|Tanzanite|Bloodstone|Dreadstone|Zircon|Ametrine|Chalcedony|Carnelian|Hessonite|Bixbite)\b|Sun Crystal|Star of Elune/.test(n)) return "Gems";
    // Applied enhancements (enchant/tailor/LW/alchemy output onto gear/weapons).
    if (/\b(Spellthread|Leg Armor|Leg Reinforcement|Sharpening Stone|Weightstone|Wizard Oil|Mana Oil|Shadow Oil)\b|^Scroll of |\bWeapon Oil\b|\bArmor Kit\b/.test(n)) return "Enhancements";
    // Equipment — match by slot/weapon noun (arbitrary item names otherwise).
    if (/\b(Sword|Axe|Waraxe|Mace|Hammer|Maul|Dagger|Blade|Spear|Lance|Glaive|Cleaver|Halberd|Scepter|Fist|Greatsword|Longsword|Staff|Polearm|Bow|Gun|Rifle|Crossbow|Wand|Shield|Buckler|Helm|Helmet|Coif|Circlet|Cap|Crown|Hood|Cowl|Shoulders|Spaulders|Mantle|Pauldrons|Epaulets|Cloak|Cape|Drape|Shroud|Breastplate|Chestplate|Robe|Tunic|Vest|Hauberk|Chestguard|Chestpiece|Jerkin|Armor|Bracers|Bracer|Wristwraps|Wristband|Vambraces|Wristguards|Armguards|Gauntlets|Gloves|Grips|Handguards|Handwraps|Mitts|Belt|Waistband|Girdle|Waistguard|Cinch|Sash|Legguards|Leggings|Legplates|Legwraps|Greaves|Pants|Kilt|Trousers|Britches|Boots|Sabatons|Treads|Sandals|Walkers|Footwraps|Slippers|Ring|Band|Signet|Loop|Seal|Amulet|Necklace|Pendant|Choker|Collar|Trinket|Idol|Totem|Libram|Sigil)\b/.test(n)) return "Gear";
    return "Other";
  }
  // Uploaded realm datasets carry the addon's real market string (from actual
  // item class/subclass/equip-slot). Map it onto a site chip; fall back to the
  // name heuristic when there's no server market or it's a placeholder/misc
  // bucket the site doesn't chip out (pets, mounts, keys, quest items, ...).
  var MARKET_MAP = {
    "Ore & Bars": "Ore & Bars", "Outland Ore": "Ore & Bars", "Outland Bars": "Ore & Bars",
    "Herbs": "Herbs", "Outland Herbs": "Herbs",
    "Cloth": "Cloth", "Netherweave": "Cloth",
    "Leather & Hides": "Leather", "Knothide": "Leather",
    "Primals": "Primals", "Primals & Motes": "Primals",
    "Enchanting Mats": "Enchanting", "Dust & Essence": "Enchanting", "Shards & Crystals": "Enchanting",
    "Gems": "Gems", "JC Supplies": "Gems",
    "Potions": "Potions", "Flasks": "Flasks", "Elixirs": "Elixirs",
    "Food & Drink": "Cooking", "Cooking Ingredients": "Cooking",
    "Oils & Stones": "Enhancements", "Scrolls": "Enhancements",
    "Weapons": "Gear", "Armor": "Gear", "Shields": "Gear", "Relics": "Gear", "Off-Hand": "Gear",
    "Cosmetic": "Cosmetic",
    "Bags": "Bags", "Quivers": "Bags",
    "Recipes": "Recipes",
    // Class spell reagents. "Reagents" is the legacy addon string still present in
    // datasets uploaded before class reagents got their own sector.
    "Class Reagents": "Class Reagents", "Reagents": "Class Reagents"
  };
  function catFor(market, name) {
    if (market) { var m = MARKET_MAP[market]; if (m) return m; }
    return classify(name);
  }
  function meter(d) {
    var on = Math.round(d / 10), h = "";
    for (var i = 0; i < 10; i++) h += i < on ? '<i class="' + (d >= 80 ? "hi" : "on") + '"></i>' : "<i></i>";
    return h;
  }
  function confidence(data) {
    var ageH = data.updatedAt ? (Date.now() - Date.parse(data.updatedAt)) / 3.6e6 : 999;
    var fresh = clamp((120 - ageH) / (120 - 24) * 100, 0, 100);
    var depth = clamp(((data.days || 1) - 1) / (7 - 1) * 100, 0, 100);
    var breadth = clamp((ITEMS.length - 500) / (12000 - 500) * 100, 0, 100);
    return { pct: Math.round(0.5 * fresh + 0.35 * depth + 0.15 * breadth), ageH: ageH, days: data.days || 1 };
  }
  function bar(pct) {
    var on = Math.round(pct / 10), h = "";
    for (var i = 0; i < 10; i++) h += '<i class="' + (i < on ? (pct >= 70 ? "hi" : "on") : "") + '"></i>';
    return '<span class="meter" style="margin:0 6px 0 0">' + h + "</span>";
  }

  function realmItems(rs) {
    return rs.slice().sort(function (a, b) { return a.label.localeCompare(b.label); }).map(function (x) {
      var p = splitRealm(x.label);
      return '<a class="mitem" href="' + href(x.game) + '" aria-current="' + (x.game === game) + '">' +
        '<span>' + esc(p[0]) + '</span>' + (p[1] ? '<span class="fac">' + esc(p[1]) + '</span>' : '') + '</a>';
    }).join("");
  }
  function group(top, active, items) {
    return '<div class="mgroup">' +
      '<button class="mtop' + (active ? ' active' : '') + '" aria-expanded="false" aria-haspopup="true">' +
      top + ' <span class="car">&#9660;</span></button>' +
      '<div class="mdrop">' + items + '</div></div>';
  }
  function buildMenu(list) {
    var region = {}, realms = { "classic": [], "classic-progression": [], "retail": [] }, other = [];
    var cur = null;
    list.forEach(function (x) {
      if (x.game === game) cur = x;
      if (x.realm) { if (x.hasItems) (realms[x.sourceGame] ? realms[x.sourceGame] : other).push(x); }
      else region[x.game] = x;
    });
    // A realm belongs to its source game; a realm with no recorded flavor lands
    // in the catch-all until its next upload stamps one.
    var curSrc = cur && cur.sourceGame;
    var activeGroup = isRealm ? (realms[curSrc] ? curSrc : "other") : game;

    var html = GAME_ORDER.map(function (gm) {
      var items = "";
      if (region[gm]) items += '<a class="mitem reg" href="' + href(gm) + '" aria-current="' + (game === gm) + '">Region screener</a>';
      if (realms[gm].length) items += '<div class="msep"></div>' + realmItems(realms[gm]);
      return group(GAME_SHORT[gm] || gm, activeGroup === gm, items);
    }).join("");
    if (other.length) html += group("Realms", activeGroup === "other", realmItems(other));
    html += '<a class="mtop" href="/pop">Population</a>';
    html += '<a class="mtop mimport" href="/import.html">+ Import</a>';
    document.getElementById("games").innerHTML = html;
  }

  fetch("/api/games").then(function (r) { return r.json(); }).then(function (g) {
    buildMenu(g.games || []);
  }).catch(function () {
    document.getElementById("games").innerHTML =
      GAME_ORDER.map(function (gm) {
        return '<a class="game" href="' + href(gm) + '" aria-current="' + (gm === game) + '">' + (GAME_SHORT[gm] || gm) + "</a>";
      }).join("") + '<a class="game" href="/pop">Population</a>';
  });

  var ITEMS = [], curCat = "All", search = "", CAP = 300;
  var sortKey = isRealm ? "deal" : "demand", sortDir = -1;
  var CATS = ["All","Ore & Bars","Herbs","Cloth","Leather","Primals","Enchanting","Gems","Enhancements","Gear","Potions","Flasks","Elixirs","Cooking","Class Reagents","Cosmetic","Recipes","Bags","Other"];
  // `w` fixes each column's width so sorting (which reorders rows and moves the
  // sort arrow) can't reflow the auto-layout and make the columns jump. The Item
  // column has no width and absorbs the remaining space. See table-layout:fixed.
  var COLS = isRealm ? [
    { k: "name", t: "Item", l: true },
    { k: "cat", t: "Market", l: true, w: 160 },
    { k: "asp", t: "Realm Buyout", w: 140 },
    { k: "q", t: "Qty", w: 80 },
    { k: "demand", t: "Demand", w: 150 },
    { k: "deal", t: "vs Region", w: 110 },
  ] : [
    { k: "name", t: "Item", l: true },
    { k: "cat", t: "Market", l: true, w: 160 },
    { k: "demand", t: "Demand", w: 150 },
    { k: "sr", t: "Rate", w: 80 },
    { k: "spd", t: "Sold/Day", w: 100 },
    { k: "asp", t: "Avg Sale (g/s)", w: 140 },
    { k: "mv", t: "Mkt Val (g)", w: 120 },
  ];

  fetch("/api/items?game=" + encodeURIComponent(game)).then(function (r) { return r.json(); }).then(function (data) {
    if (isRealm && WH_BRANCH[data.sourceGame] != null) whBranch = WH_BRANCH[data.sourceGame];
    ITEMS = (data.items || []).map(function (r) {
      var o = { id: r[0], name: r[1], slug: r[2], mv: r[3], asp: r[4], sr: r[5], spd: r[6], q: r[7], sc: r[8], tc: r[9], hist: r[10] };
      o.cat = catFor(r[11], o.name);
      o.src = r[12] || null;      // crafted | gathered | disenchant | ...
      o.crafter = r[13] || null;  // producing/gathering profession
      o.demand = demand(o.sr, o.spd);
      o.deal = (isRealm && o.hist > 0 && o.asp > 0) ? Math.round((o.hist - o.asp) / o.hist * 100) : null;
      return o;
    });
    if (!ITEMS.length) {
      document.getElementById("meta").innerHTML = "no data for this dataset";
      document.getElementById("rows").innerHTML = '<tr><td class="l" colspan="' + COLS.length + '" style="padding:22px;color:var(--muted)">No data yet.</td></tr>';
      return;
    }
    boot(data);
  }).catch(function () {
    document.getElementById("rows").innerHTML = '<tr><td class="l" colspan="' + COLS.length + '" style="padding:22px;color:var(--red)">Failed to load data.</td></tr>';
  });

  function boot(data) {
    var top = ITEMS.slice().sort(function (a, b) { return b.demand - a.demand; })[0];
    var c = confidence(data);
    var ageStr = c.ageH < 48 ? Math.round(c.ageH) + "h" : Math.round(c.ageH / 24) + "d";
    var scope = isRealm ? "REALM <b>" + esc(game.slice(6)) + "</b>" : "REGION <b>US</b>";
    document.getElementById("meta").innerHTML =
      scope + " &middot; " + ITEMS.length.toLocaleString() + " items<br>" +
      '<span style="font-size:15px">DATA CONFIDENCE ' + bar(c.pct) +
      '<b style="color:' + (c.pct >= 70 ? "var(--green)" : c.pct >= 40 ? "var(--gold)" : "var(--red)") + '">' + c.pct + '%</b></span><br>' +
      '<span style="font-size:15px;color:var(--muted)">updated ' + ageStr + ' ago &middot; ' + c.days + ' day' + (c.days === 1 ? "" : "s") + ' history</span>';

    if (isRealm) {
      // Retail market is faction-agnostic; its population lives under per-faction
      // keys, so point at the chooser rather than a single faction.
      var popHref = data.sourceGame === "retail" ? "/pop" : "/pop?game=" + gameParam(game);
      document.getElementById("meta").innerHTML +=
        '<br><a href="' + popHref + '" style="font-size:15px">&#9654; POPULATION SURVEY</a>';
    }
    if (isRealm) {
      var bestDeal = ITEMS.filter(function (x) { return x.deal != null; }).sort(function (a, b) { return b.deal - a.deal; })[0];
      var value = ITEMS.reduce(function (s, x) { return s + (x.mv || 0); }, 0);
      document.getElementById("tiles").innerHTML =
        tile("Items on realm", ITEMS.length.toLocaleString(), "with region demand data") +
        tile("Hottest item", top.name, "demand " + top.demand + "/100", "gr") +
        tile("Best deal", bestDeal ? bestDeal.name : "—", bestDeal ? "+" + bestDeal.deal + "% vs region" : "", "gr") +
        tile("Listed value", big(value) + "g", "on the auction house");
    } else {
      var move = {}, turn = 0;
      ITEMS.forEach(function (it) { move[it.cat] = (move[it.cat] || 0) + it.spd; turn += it.spd * it.asp; });
      var busiest = Object.keys(move).filter(function (k) { return k !== "Other"; }).sort(function (a, b) { return move[b] - move[a]; })[0];
      document.getElementById("tiles").innerHTML =
        tile("Items tracked", ITEMS.length.toLocaleString(), "with region sale data") +
        tile("Hottest item", top.name, "demand " + top.demand + "/100", "gr") +
        tile("Busiest market", busiest, Math.round(move[busiest]).toLocaleString() + " sold / day") +
        tile("Region turnover", big(turn) + "g", "changing hands / day");
    }
    document.getElementById("chips").innerHTML = CATS.map(function (ct) {
      return '<button class="chip" data-cat="' + esc(ct) + '" aria-pressed="' + (ct === "All") + '">' + esc(ct) + "</button>";
    }).join("");
    drawHead(); render();
  }
  function tile(k, v, s, cls) {
    return '<div class="tile"><div class="k">' + esc(k) + '</div><div class="v ' + (cls || "") + '">' + esc(v) + '</div><div class="s">' + esc(s) + "</div></div>";
  }
  function drawHead() {
    document.getElementById("cols").innerHTML = COLS.map(function (c) {
      return "<col" + (c.w ? ' style="width:' + c.w + 'px"' : "") + ">";
    }).join("");
    document.getElementById("head").innerHTML = COLS.map(function (c) {
      // Always render the arrow slot (hidden when this isn't the sort column) so
      // switching the sorted column never nudges the header text left or right.
      var glyph = c.k === sortKey ? (sortDir < 0 ? "▼" : "▲") : "▼";
      var ar = '<span class="ar' + (c.k === sortKey ? "" : " off") + '">' + glyph + "</span>";
      return '<th class="' + (c.l ? "l" : "") + '" data-k="' + c.k + '">' + c.t + " " + ar + "</th>";
    }).join("");
  }
  function dealCell(d) {
    if (d == null) return '<td class="mu">--</td>';
    return '<td class="' + (d >= 0 ? "gr" : "rd") + '">' + (d >= 0 ? "+" : "") + d + "%</td>";
  }
  // Source axis badge: where an item's supply comes from, and (for crafted /
  // gathered) the profession that produces it -- the "can a seller make more?"
  // signal. Shown under the market chip.
  var SRC_LABEL = { crafted: "Crafted", gathered: "Gathered", disenchant: "Disenchanted",
    drop: "Drop", vendor: "Vendor", quest: "Quest", reputation: "Reputation", event: "Event" };
  function srcBadge(it) {
    if (!it.src) return "";
    var label = SRC_LABEL[it.src] || it.src;
    var text = it.crafter ? label + " · " + it.crafter : label;
    return '<span class="srcb srcb-' + esc(it.src) + '" title="' + esc(text) + '">' + esc(text) + "</span>";
  }
  function catCell(it) {
    return '<td class="l cat">' + esc(it.cat) + srcBadge(it) + "</td>";
  }
  function render() {
    var q = search.trim().toLowerCase();
    var rows = ITEMS.filter(function (it) {
      if (curCat !== "All" && it.cat !== curCat) return false;
      if (q && it.name.toLowerCase().indexOf(q) < 0) return false;
      if (sortKey === "deal" && it.deal == null) return false; // hide non-deals when sorting deals
      return true;
    });
    rows.sort(function (a, b) {
      var x = a[sortKey], y = b[sortKey];
      if (x == null) x = -Infinity; if (y == null) y = -Infinity;
      return typeof x === "string" ? sortDir * x.localeCompare(y) : sortDir * (x - y);
    });
    var shown = rows.slice(0, CAP), h = "";
    for (var i = 0; i < shown.length; i++) {
      var it = shown[i];
      var ic = '<a class="ic" href="' + whItem(it.id) + '" tabindex="-1" aria-hidden="true"></a>';
      var link;
      if (/^item:\d+$/.test(it.name)) {
        // No captured name (the addon scan never cached it and region data
        // doesn't cover this item), so it's an item:<id> placeholder. Point the
        // link at Wowhead with rename on — its tooltip data fills the real name
        // client-side (same source as the hover card). A click still routes to
        // our own item page via the delegated handler below.
        link = '<a class="name whname" data-id="' + it.id + '" href="' + whItem(it.id) +
          '" data-wh-rename-link="true">' + esc(it.name) + "</a>";
      } else {
        link = '<a class="name" href="/item/' + encodeURIComponent(it.slug || it.id) + '?game=' + gameParam(game) + '">' + esc(it.name) + "</a>";
      }
      var dm = '<td><span class="meter">' + meter(it.demand) + '</span><span class="dv ' + (it.demand >= 80 ? "g" : it.demand >= 45 ? "gr" : "mu") + '">' + it.demand + "</span></td>";
      if (isRealm) {
        h += "<tr><td class=\"l\">" + ic + link + "</td>" + catCell(it) +
          '<td class="g">' + gs(it.asp) + "</td><td>" + (it.q || 0).toLocaleString() + "</td>" +
          dm + dealCell(it.deal) + "</tr>";
      } else {
        h += "<tr><td class=\"l\">" + ic + link + "</td>" + catCell(it) + dm +
          '<td class="' + (it.sr >= 0.35 ? "gr" : it.sr < 0.1 ? "rd" : "") + '">' + Math.round(it.sr * 100) + "%</td>" +
          "<td>" + (it.spd >= 10 ? Math.round(it.spd) : it.spd.toFixed(1)) + "</td>" +
          '<td class="g">' + gs(it.asp) + '</td><td class="mu">' + big(it.mv) + "</td></tr>";
      }
    }
    document.getElementById("rows").innerHTML = h || '<tr><td class="l" colspan="' + COLS.length + '" style="padding:20px;color:var(--muted)">No items match.</td></tr>';
    whRefresh(); // re-scan the freshly rendered rows for Wowhead icons/tooltips
    document.getElementById("foot").textContent =
      "SHOWING " + shown.length.toLocaleString() + " OF " + rows.length.toLocaleString() + (rows.length > CAP ? "  (TOP " + CAP + " — REFINE TO SEE MORE)" : "");
  }

  document.getElementById("head").addEventListener("click", function (e) {
    var th = e.target.closest("th"); if (!th) return;
    var k = th.getAttribute("data-k");
    if (k === sortKey) sortDir = -sortDir;
    else { sortKey = k; sortDir = (k === "name" || k === "cat") ? 1 : -1; }
    drawHead(); render();
  });
  document.getElementById("chips").addEventListener("click", function (e) {
    var b = e.target.closest(".chip"); if (!b) return;
    curCat = b.getAttribute("data-cat");
    Array.prototype.forEach.call(this.children, function (c) { c.setAttribute("aria-pressed", c === b ? "true" : "false"); });
    render();
  });
  document.getElementById("search").addEventListener("input", function (e) { search = e.target.value; render(); });
  // Placeholder-name rows point their link at Wowhead (so it can rename them);
  // keep clicks on our own item page.
  document.getElementById("rows").addEventListener("click", function (e) {
    var a = e.target.closest("a.whname"); if (!a) return;
    e.preventDefault();
    location.href = "/item/" + encodeURIComponent(a.getAttribute("data-id")) + "?game=" + gameParam(game);
  });

  function closeMenus(except) {
    Array.prototype.forEach.call(document.querySelectorAll("#games .mgroup.on"), function (n) {
      if (n === except) return;
      n.classList.remove("on");
      var b = n.querySelector(".mtop"); if (b) b.setAttribute("aria-expanded", "false");
    });
  }
  document.getElementById("games").addEventListener("click", function (e) {
    var btn = e.target.closest("button.mtop"); if (!btn) return;
    e.preventDefault();
    var grp = btn.parentNode, open = grp.classList.contains("on");
    closeMenus(open ? null : grp);
    grp.classList.toggle("on", !open);
    btn.setAttribute("aria-expanded", String(!open));
  });
  document.addEventListener("click", function (e) { if (!e.target.closest("#games .mgroup")) closeMenus(); });
  document.addEventListener("keydown", function (e) { if (e.key === "Escape") closeMenus(); });
})();
