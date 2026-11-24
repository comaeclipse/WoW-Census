/*
 * WoWCensus additions to the mockup's common.js, which stays as the mockup has it.
 * The mockup knew the nine Burning Crusade classes; later editions add four.
 */
Object.assign(CLASS_COLOR, { 'Death Knight': '#C41E3A', Monk: '#00FF98', 'Demon Hunter': '#A330C9', Evoker: '#33937F' });

const NAV_STATE_KEY = 'wowcensus-nav';
const navState = (() => { try { return JSON.parse(localStorage.getItem(NAV_STATE_KEY)) || {}; } catch { return {}; } })();
const setNavSection = (button, expanded) => {
  const links = document.getElementById(button.getAttribute('aria-controls'));
  if (!links) return false;
  button.setAttribute('aria-expanded', String(expanded));
  button.setAttribute('aria-label', `${expanded ? 'Collapse' : 'Expand'} ${button.parentElement.textContent.trim()}`);
  links.hidden = !expanded;
  return true;
};
document.querySelectorAll('.nav .nav-toggle').forEach(button => {
  const id = button.getAttribute('aria-controls');
  if (typeof navState[id] === 'boolean') setNavSection(button, navState[id]);
});
document.querySelector('.nav')?.addEventListener('click', event => {
  const button = event.target.closest('.nav-toggle') || event.target.closest('h3')?.querySelector('.nav-toggle');
  if (!button) return;
  const expanded = button.getAttribute('aria-expanded') !== 'true';
  if (!setNavSection(button, expanded)) return;
  navState[button.getAttribute('aria-controls')] = expanded;
  try { localStorage.setItem(NAV_STATE_KEY, JSON.stringify(navState)); } catch {}
});
const wowIcon = (name, fallback = '') => /^[a-z0-9_-]+$/i.test(name || '')
  ? `<img src="/assets/images/wow/icons/large/${name}.jpg" alt="" loading="lazy">`
  : fallback;
const wowClassIcon = name => wowIcon(`classicon_${name.toLowerCase().replace(/[^a-z]/g, '')}`);
