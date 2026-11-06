/*
 * Shared helpers for every page. Loaded by templates/layout.php before page scripts,
 * so page scripts can use these globals directly.
 */

/** In-game class colors, keyed by class name. */
const CLASS_COLOR = {
  Druid: '#FF7D0A', Hunter: '#ABD473', Mage: '#69CCF0', Paladin: '#F58CBA', Priest: '#FFFFFF',
  Rogue: '#FFF569', Shaman: '#0070DE', Warlock: '#9482C9', Warrior: '#C79C6E'
};

/** 1234 -> "1,234" */
const fmt = n => n.toLocaleString('en-US');

/** HTML-escape a value for use in innerHTML strings and attributes. */
const esc = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

/** Short 2008-theme display name; the underlying race keys stay distinct. */
const shortRace = name => String(name).replace(/\b(?:High Order|Windshaper) Skyborne\b/g, 'Skyborne');

/** Lighten (f > 0) or darken (f < 0) a #rrggbb color; returns an rgb() string. */
function shade(hex, f) {
  const n = parseInt(hex.slice(1), 16);
  const ch = s => { const v = (n >> s) & 255; return Math.round(f < 0 ? v * (1 + f) : v + (255 - v) * f); };
  return `rgb(${ch(16)},${ch(8)},${ch(0)})`;
}

/**
 * Animate bars inside root on the next frame: elements with data-w get that width,
 * elements with data-g get that flex-grow. Bars start at 0 in CSS and grow in once.
 */
const grow = root => requestAnimationFrame(() => requestAnimationFrame(() => {
  root.querySelectorAll('[data-w]').forEach(el => el.style.width = el.dataset.w);
  root.querySelectorAll('[data-g]').forEach(el => el.style.flexGrow = el.dataset.g);
}));

/*
 * In-game style tooltip. Any element with data-tip="Title|line 1|line 2" shows it on hover:
 * the title in white (or a custom color), the first line yellow, later lines green.
 * A page can set window.tipTitleColor = (title, lines) => color|null to color the title,
 * e.g. by item quality.
 */
(function () {
  const tipEl = document.getElementById('tip');
  if (!tipEl) return;
  document.addEventListener('mousemove', e => {
    const t = e.target.closest('[data-tip]');
    if (!t) { tipEl.style.display = 'none'; return; }
    const [h, ...lines] = t.dataset.tip.split('|');
    const titleColor = window.tipTitleColor ? window.tipTitleColor(h, lines) : null;
    tipEl.innerHTML = `<div class="tt"${titleColor ? ` style="color:${titleColor}"` : ''}>${esc(h)}</div>` +
      lines.map((l, i) => `<div class="${i ? 'g' : 'y'}">${esc(l)}</div>`).join('');
    tipEl.style.display = 'block';
    tipEl.style.left = Math.min(e.clientX + 14, innerWidth - 230) + 'px';
    tipEl.style.top = (e.clientY + 14) + 'px';
  });
})();
