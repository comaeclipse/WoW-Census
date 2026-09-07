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
  function href(g) { return "/?game=" + encodeURIComponent(g); }

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
    if (/\bMeat\b|\bFillet\b|\bFish\b|\bRibs\b|Stew|\bPie\b|\bBread\b|Cheese|Sausage|\bClam\b|\bEgg\b|\bRoast\b|Sandwich|Omelet|Juice|\bBrew\b/.test(n)) return "Cooking";
    // Jewelcrafting stones — cut and raw share the base gem noun (e.g. "Runed
    // Living Ruby" and "Living Ruby" both match "Ruby"), so match the base.
    if (/\b(Ruby|Topaz|Nightseye|Dawnstone|Talasite|Moonstone|Draenite|Spessarite|Peridot|Garnet|Spinel|Pyrestone|Amethyst|Sapphire|Emerald|Lionseye|Citrine|Chrysoprase|Jade|Opal|Aquamarine|Diamond|Pearl|Tanzanite)\b|Sun Crystal|Star of Elune/.test(n)) return "Gems";
    // Applied enhancements (enchant/tailor/LW/alchemy output onto gear/weapons).
    if (/\b(Spellthread|Leg Armor|Leg Reinforcement|Sharpening Stone|Weightstone|Wizard Oil|Mana Oil|Shadow Oil)\b|^Scroll of |\bWeapon Oil\b|\bArmor Kit\b/.test(n)) return "Enhancements";
    // Equipment — match by slot/weapon noun (arbitrary item names otherwise).
    if (/\b(Sword|Axe|Mace|Hammer|Dagger|Blade|Staff|Polearm|Bow|Gun|Rifle|Crossbow|Wand|Shield|Buckler|Helm|Helmet|Cap|Crown|Hood|Cowl|Shoulders|Spaulders|Mantle|Pauldrons|Epaulets|Cloak|Cape|Drape|Shroud|Breastplate|Robe|Tunic|Vest|Hauberk|Chestguard|Chestpiece|Jerkin|Bracers|Bracer|Vambraces|Wristguards|Armguards|Gauntlets|Gloves|Grips|Handguards|Mitts|Belt|Girdle|Waistguard|Cinch|Sash|Legguards|Leggings|Legplates|Legwraps|Greaves|Pants|Kilt|Trousers|Britches|Boots|Sabatons|Treads|Sandals|Walkers|Footwraps|Slippers|Ring|Band|Signet|Loop|Seal|Amulet|Necklace|Pendant|Choker|Collar|Trinket|Idol|Totem|Libram|Sigil)\b/.test(n)) return "Gear";
    return "Other";
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

  fetch("/api/games").then(function (r) { return r.json(); }).then(function (g) {
    var list = (g.games || []);
    var region = list.filter(function (x) { return !x.realm; });
    var realms = list.filter(function (x) { return x.realm; });
    var order = { "classic-progression": 0, "classic": 1, "retail": 2 };
    region.sort(function (a, b) { return (order[a.game] || 9) - (order[b.game] || 9); });
    var html = region.concat(realms).map(function (x) {
      // Realms with population but no AH items link straight to their /pop page
      // (the item screener would be empty), and get a small POP marker.
      var popOnly = x.hasItems === false && x.hasPop;
      var url = popOnly ? ("/pop?game=" + encodeURIComponent(x.game)) : href(x.game);
      var tag = popOnly ? ' ·POP' : "";
      return '<a class="game" href="' + url + '" aria-current="' + (x.game === game) + '">' + esc(x.label) + tag + "</a>";
    }).join("");
    html += '<a class="game" href="/import.html" style="border-style:dashed">+ IMPORT REALM</a>';
    document.getElementById("games").innerHTML = html;
  }).catch(function () {
    document.getElementById("games").innerHTML =
      ["classic-progression", "classic", "retail"].map(function (gm) {
        return '<a class="game" href="' + href(gm) + '" aria-current="' + (gm === game) + '">' + gm + "</a>";
      }).join("");
  });

  var ITEMS = [], curCat = "All", search = "", CAP = 300;
  var sortKey = isRealm ? "deal" : "demand", sortDir = -1;
  var CATS = ["All","Ore & Bars","Herbs","Cloth","Leather","Primals","Enchanting","Gems","Enhancements","Gear","Potions","Flasks","Elixirs","Cooking","Recipes","Bags","Other"];
  var COLS = isRealm ? [
    { k: "name", t: "Item", l: true },
    { k: "cat", t: "Market", l: true },
    { k: "asp", t: "Your Buyout" },
    { k: "q", t: "Qty" },
    { k: "sc", t: "Sellers" },
    { k: "demand", t: "Demand" },
    { k: "deal", t: "vs Region" },
  ] : [
    { k: "name", t: "Item", l: true },
    { k: "cat", t: "Market", l: true },
    { k: "demand", t: "Demand" },
    { k: "sr", t: "Rate" },
    { k: "spd", t: "Sold/Day" },
    { k: "asp", t: "Avg Sale (g/s)" },
    { k: "mv", t: "Mkt Val (g)" },
  ];

  fetch("/api/items?game=" + encodeURIComponent(game)).then(function (r) { return r.json(); }).then(function (data) {
    if (isRealm && WH_BRANCH[data.sourceGame] != null) whBranch = WH_BRANCH[data.sourceGame];
    ITEMS = (data.items || []).map(function (r) {
      var o = { id: r[0], name: r[1], slug: r[2], mv: r[3], asp: r[4], sr: r[5], spd: r[6], q: r[7], sc: r[8], hist: r[9] };
      o.cat = classify(o.name);
      o.demand = demand(o.sr, o.spd);
      o.deal = (isRealm && o.hist > 0 && o.asp > 0) ? Math.round((o.hist - o.asp) / o.hist * 100) : null;
      return o;
    });
    if (!ITEMS.length) {
      document.getElementById("meta").innerHTML = "no data for this dataset";
      document.getElementById("rows").innerHTML = '<tr><td class="l" colspan="7" style="padding:22px;color:var(--muted)">No data yet.</td></tr>';
      return;
    }
    boot(data);
  }).catch(function () {
    document.getElementById("rows").innerHTML = '<tr><td class="l" colspan="7" style="padding:22px;color:var(--red)">Failed to load data.</td></tr>';
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
      document.getElementById("meta").innerHTML +=
        '<br><a href="/pop?game=' + encodeURIComponent(game) + '" style="font-size:15px">&#9654; POPULATION SURVEY</a>';
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
    document.getElementById("head").innerHTML = COLS.map(function (c) {
      var ar = c.k === sortKey ? '<span class="ar">' + (sortDir < 0 ? "▼" : "▲") + "</span>" : "";
      return '<th class="' + (c.l ? "l" : "") + '" data-k="' + c.k + '">' + c.t + " " + ar + "</th>";
    }).join("");
  }
  function dealCell(d) {
    if (d == null) return '<td class="mu">--</td>';
    return '<td class="' + (d >= 0 ? "gr" : "rd") + '">' + (d >= 0 ? "+" : "") + d + "%</td>";
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
      var link = '<a class="name" href="/item/' + encodeURIComponent(it.slug || it.id) + '?game=' + encodeURIComponent(game) + '">' + esc(it.name) + "</a>";
      var dm = '<td><span class="meter">' + meter(it.demand) + '</span><span class="dv ' + (it.demand >= 80 ? "g" : it.demand >= 45 ? "gr" : "mu") + '">' + it.demand + "</span></td>";
      if (isRealm) {
        h += "<tr><td class=\"l\">" + ic + link + '</td><td class="l cat">' + esc(it.cat) + "</td>" +
          '<td class="g">' + gs(it.asp) + "</td><td>" + (it.q || 0).toLocaleString() + "</td><td>" + (it.sc == null ? "—" : it.sc) + "</td>" +
          dm + dealCell(it.deal) + "</tr>";
      } else {
        h += "<tr><td class=\"l\">" + ic + link + '</td><td class="l cat">' + esc(it.cat) + "</td>" + dm +
          '<td class="' + (it.sr >= 0.35 ? "gr" : it.sr < 0.1 ? "rd" : "") + '">' + Math.round(it.sr * 100) + "%</td>" +
          "<td>" + (it.spd >= 10 ? Math.round(it.spd) : it.spd.toFixed(1)) + "</td>" +
          '<td class="g">' + gs(it.asp) + '</td><td class="mu">' + big(it.mv) + "</td></tr>";
      }
    }
    document.getElementById("rows").innerHTML = h || '<tr><td class="l" colspan="7" style="padding:20px;color:var(--muted)">No items match.</td></tr>';
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
})();
