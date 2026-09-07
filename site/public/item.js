// MarketLens item page — draws region price history (SVG) and, when present,
// overlays your own realm history imported from the addon's /ml export.
(function () {
  "use strict";
  var ITEM = window.ITEM || {};
  var PTS = (window.POINTS || []).map(function (p) {
    return { d: ymdToMs(p.ts), asp: p.asp || 0, mv: p.mv || 0, sr: p.sr || 0 };
  });

  function ymdToMs(ts) {
    ts = ts | 0;
    var y = Math.floor(ts / 10000), m = Math.floor((ts % 10000) / 100), d = ts % 100;
    return Date.UTC(y, m - 1, d);
  }
  function fmtDate(ms) {
    var d = new Date(ms);
    return (d.getUTCMonth() + 1) + "/" + d.getUTCDate();
  }
  function gsc(cop) {
    cop = Math.round(cop || 0);
    var g = Math.floor(cop / 10000), s = Math.floor((cop % 10000) / 100);
    if (g > 0) return g + "g" + (s ? " " + s + "s" : "");
    return (Math.floor((cop % 10000) / 100)) + "s";
  }

  function realmSeries() {
    try {
      var raw = localStorage.getItem("ml_realm");
      if (!raw) return null;
      var db = JSON.parse(raw);
      var rec = db.items && db.items[String(ITEM.id)];
      var arr = rec && (rec.s || rec); // v1 = {s:[[t,q,a,s,l,m,w,tc]]}; legacy = [[t,w]]
      if (!arr || !arr.length) return null;
      return arr.map(function (p) { return { d: p[0] * 1000, w: p.length > 2 ? p[6] : p[1] }; })
        .sort(function (a, b) { return a.d - b.d; });
    } catch (e) { return null; }
  }
  var REALM = realmSeries();

  var chart = document.getElementById("chart");
  var hint = document.getElementById("hhint");

  if (PTS.length === 0) {
    chart.innerHTML = '<div style="padding:40px 10px;text-align:center;color:var(--muted)">No history yet.</div>';
    hint.textContent = "HISTORY STARTS BUILDING FROM THE SITE'S FIRST DAILY COLLECTION.";
    mountImport();
    return;
  }

  draw();
  mountImport();

  function draw() {
    var W = 900, H = 300, pl = 62, pr = 20, pt = 16, pb = 34;
    var iw = W - pl - pr, ih = H - pt - pb;

    var allD = PTS.map(function (p) { return p.d; });
    if (REALM) REALM.forEach(function (p) { allD.push(p.d); });
    var dMin = Math.min.apply(null, allD), dMax = Math.max.apply(null, allD);
    if (dMin === dMax) { dMin -= 86400000; dMax += 86400000; }

    var vals = [];
    PTS.forEach(function (p) { vals.push(p.asp, p.mv); });
    if (REALM) REALM.forEach(function (p) { vals.push(p.w); });
    vals = vals.filter(function (v) { return v > 0; });
    var vMax = vals.length ? Math.max.apply(null, vals) : 1;
    var srMax = Math.max.apply(null, PTS.map(function (p) { return p.sr; }).concat([0.1]));

    function x(d) { return pl + (dMax === dMin ? 0.5 : (d - dMin) / (dMax - dMin)) * iw; }
    function yv(v) { return pt + ih - (v / vMax) * ih; }
    function ys(s) { return pt + ih - (s / srMax) * ih; }

    var svg = '<svg viewBox="0 0 ' + W + " " + H + '" preserveAspectRatio="none" role="img" aria-label="price history">';
    for (var g = 0; g <= 4; g++) {
      var gy = pt + (ih * g) / 4;
      svg += '<line class="gridline" x1="' + pl + '" y1="' + gy + '" x2="' + (W - pr) + '" y2="' + gy + '"/>';
      var val = vMax * (1 - g / 4);
      svg += '<text class="axislabel" x="' + (pl - 6) + '" y="' + (gy + 5) + '" text-anchor="end">' + gsc(val) + "</text>";
    }
    // x labels (first / mid / last)
    [dMin, (dMin + dMax) / 2, dMax].forEach(function (d, i) {
      svg += '<text class="axislabel" x="' + x(d) + '" y="' + (H - 10) + '" text-anchor="' + (i === 0 ? "start" : i === 2 ? "end" : "middle") + '">' + fmtDate(d) + "</text>";
    });

    svg += line(PTS, function (p) { return x(p.d); }, function (p) { return yv(p.mv); }, "var(--blue)", 2);
    svg += line(PTS, function (p) { return x(p.d); }, function (p) { return ys(p.sr); }, "var(--green)", 2, "3 3");
    svg += line(PTS, function (p) { return x(p.d); }, function (p) { return yv(p.asp); }, "var(--gold)", 3);
    if (REALM) svg += line(REALM, function (p) { return x(p.d); }, function (p) { return yv(p.w); }, "var(--gold)", 2, "6 4");

    var last = PTS[PTS.length - 1];
    svg += '<circle class="dot" cx="' + x(last.d) + '" cy="' + yv(last.asp) + '" r="4"/>';
    svg += "</svg>";
    chart.innerHTML = svg;

    document.getElementById("range").textContent = "· " + fmtDate(dMin) + "–" + fmtDate(dMax) + " · " + PTS.length + " day" + (PTS.length === 1 ? "" : "s");
    hint.innerHTML = PTS.length < 3
      ? "ONLY " + PTS.length + " SNAPSHOT" + (PTS.length === 1 ? "" : "S") + " SO FAR — THE TREND FILLS IN AS THE DAILY COLLECTOR RUNS."
      : (REALM ? "DASHED GOLD = YOUR REALM (imported). SOLID = REGION-WIDE." : "");
  }

  function line(data, fx, fy, color, w, dash) {
    var d = data.map(function (p, i) { return (i ? "L" : "M") + fx(p).toFixed(1) + " " + fy(p).toFixed(1); }).join(" ");
    return '<path d="' + d + '" fill="none" stroke="' + color + '" stroke-width="' + w + '"' + (dash ? ' stroke-dasharray="' + dash + '"' : "") + ' vector-effect="non-scaling-stroke"/>';
  }

  function mountImport() {
    var wrap = document.createElement("details");
    wrap.className = "panel";
    wrap.style.marginTop = "18px";
    wrap.innerHTML =
      '<summary style="cursor:pointer;font-family:var(--pixel);font-size:9px;color:var(--gold);letter-spacing:1px">' +
      (REALM ? "YOUR REALM DATA LOADED ✓ — UPDATE / CLEAR" : "OVERLAY YOUR REALM HISTORY") + "</summary>" +
      '<p class="src" style="text-align:left;margin:12px 0">In game: <b>/ml export</b> &rarr; copy the text &rarr; paste below. Stored only in this browser.</p>' +
      '<textarea id="imp" style="width:100%;height:90px;background:var(--bg);color:var(--ink);border:2px solid var(--line);font-family:var(--term);font-size:16px;padding:8px" placeholder="paste /ml export json..."></textarea>' +
      '<div style="margin-top:10px;display:flex;gap:8px"><button class="chip" id="impSave">SAVE</button><button class="chip" id="impClear">CLEAR</button></div>';
    chart.closest(".wrap").insertBefore(wrap, document.querySelector(".src"));
    document.getElementById("impSave").onclick = function () {
      try { JSON.parse(document.getElementById("imp").value); localStorage.setItem("ml_realm", document.getElementById("imp").value); location.reload(); }
      catch (e) { alert("That doesn't look like valid /ml export JSON."); }
    };
    document.getElementById("impClear").onclick = function () { localStorage.removeItem("ml_realm"); location.reload(); };
  }
})();
