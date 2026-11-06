(() => {
  const key = 'wowcensus-skin';
  const root = document.documentElement;
  const current = root.dataset.skin === 'cobalt' ? 'cobalt' : 'current';
  const header = document.querySelector('.ihead');
  const wrap = header?.closest('.wrap');
  if (!header || !wrap) return;

  function switcher() {
    const group = document.createElement('div');
    group.className = 'skin-switch';
    group.setAttribute('role', 'group');
    group.setAttribute('aria-label', 'Site appearance');
    for (const [value, label] of [['current', 'Current'], ['cobalt', 'Cobalt']]) {
      const button = document.createElement('button');
      button.type = 'button';
      button.textContent = label;
      button.setAttribute('aria-pressed', String(current === value));
      button.addEventListener('click', () => {
        if (value === current) return;
        try { localStorage.setItem(key, value); } catch (_) {}
        root.dataset.skin = value;
        location.reload();
      });
      group.append(button);
    }
    return group;
  }

  if (current === 'current') {
    const stack = header.querySelector('.site-nav-stack') || header.querySelector('.page-heading') || header;
    stack.append(switcher());
    return;
  }

  for (const style of document.head.querySelectorAll('style, link[rel="stylesheet"]')) {
    if (style.matches('[data-modern-style]')) style.media = 'all';
    else if (!style.getAttribute('href')?.includes('modern-bridge.css')) style.disabled = true;
  }

  const topic = header.querySelector('.itopic')?.textContent?.trim() || document.title || 'WoWCensus';
  const edition = header.querySelector('.game-nav [aria-current]')?.textContent?.trim() || 'WoW Forever';
  const page = header.querySelector('.breadcrumbs [aria-current]')?.textContent?.trim() || 'Census';
  const top = document.createElement('header');
  top.className = 'top cobalt-top';
  const topInner = document.createElement('div');
  topInner.className = 'wrap top-inner cobalt-top-inner';
  const brand = document.createElement('a');
  brand.className = 'brand cobalt-brand';
  brand.href = '/';
  brand.innerHTML = 'WoW<span>Census</span>';
  topInner.append(brand);
  const pageNav = header.querySelector('.page-nav')?.cloneNode(true);
  if (pageNav) { pageNav.className = 'cobalt-page-nav'; topInner.append(pageNav); }
  topInner.append(switcher());
  top.append(topInner);
  document.body.insertBefore(top, document.body.firstChild);

  const shell = document.createElement('div');
  shell.className = 'cobalt-shell census-wrap detail-wrap';
  const editionNav = header.querySelector('.game-nav')?.cloneNode(true);
  if (editionNav) { editionNav.className = 'cobalt-edition-nav'; shell.append(editionNav); }
  const hero = document.createElement('section');
  hero.className = 'census-hero cobalt-hero';
  const heroCopy = document.createElement('div');
  heroCopy.className = 'census-hero-copy';
  const eyebrow = document.createElement('p');
  eyebrow.className = 'eyebrow cobalt-eyebrow';
  eyebrow.textContent = `${edition} · ${page}`;
  const title = document.createElement('h1');
  title.textContent = topic.replace(/^WoW\s+/, '');
  const intro = document.createElement('p');
  intro.className = 'cobalt-intro';
  intro.textContent = 'Explore the published WoWCensus snapshot. Counts describe observed characters, not unique players or a live online population.';
  heroCopy.append(eyebrow, title, intro);
  const art = document.createElement('img');
  art.className = 'census-hero-art';
  art.src = '/cobalt-character.webp';
  art.alt = '';
  hero.append(heroCopy, art);
  shell.append(hero);
  wrap.insertBefore(shell, header.nextSibling);

  const content = document.createElement('div');
  content.className = 'cobalt-content census-main detail-main';
  let node = shell.nextSibling;
  while (node) {
    const next = node.nextSibling;
    if (node !== header) content.append(node);
    node = next;
  }
  shell.append(content);
  wrap.classList.add('cobalt-wrap');
  for (const panel of content.querySelectorAll('.vpane,.panel,.combo-panel,.race-panel,.realm-detail,.slab')) {
    panel.classList.add('census-panel','detail-panel');
  }

  if (document.body.classList.contains('census-home')) {
    const editionPath = location.pathname.replace(/(?:index\.html)?$/, '');
    fetch(editionPath + 'census.json', { credentials: 'same-origin' })
      .then(response => { if (!response.ok) throw new Error('Census unavailable'); return response.json(); })
      .then(data => {
        const groups = data.groups || [];
        const alliance = groups.find(group => group.faction === 'Alliance')?.characters || 0;
        const horde = groups.find(group => group.faction === 'Horde')?.characters || 0;
        const units = data.units || [];
        const cards = document.createElement('div');
        cards.className = 'census-metrics cobalt-metrics';
        for (const [label, value, note] of [
          ['Alliance observed', alliance, 'all realms in sample'],
          ['Horde observed', horde, 'all realms in sample'],
          ['Realm buckets', units.length, 'home-realm views'],
          ['Latest observation', data.lastT ? new Date(data.lastT * 1000).toLocaleDateString() : 'Unknown', 'snapshot timestamp'],
        ]) {
          const card = document.createElement('div');
          card.className = 'metric cobalt-metric';
          const caption = document.createElement('span'); caption.textContent = label;
          const number = document.createElement('strong'); number.textContent = typeof value === 'number' ? value.toLocaleString() : value;
          const detail = document.createElement('small'); detail.textContent = note;
          card.append(caption, number, detail);
          cards.append(card);
        }
        const firstPanel = content.querySelector('.vpane,.panel');
        if (firstPanel) firstPanel.parentNode.insertBefore(cards, firstPanel);
      })
      .catch(error => console.warn('Cobalt census metrics unavailable', error));
  }

  for (const table of content.querySelectorAll('table')) {
    if (table.closest('.tablewrap, .cobalt-table-scroll')) continue;
    const scroll = document.createElement('div');
    scroll.className = 'cobalt-table-scroll';
    table.parentNode.insertBefore(scroll, table);
    scroll.append(table);
  }
})();
