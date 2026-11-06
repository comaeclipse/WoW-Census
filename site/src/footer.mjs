import { pagePath } from "./edition.mjs";

// Shared compact brand bar for every generated content page.
export function renderFooter(source) {
  const link = (page, label) => `<a href="${pagePath(source, page)}">${label}</a>`;
  const shortcut = (page, label, icon) => `<a class="site-footer-shortcut" href="${pagePath(source, page)}" aria-label="${label}" title="${label}"><span aria-hidden="true">${icon}</span></a>`;
  return `<footer class="site-footer" aria-label="Site footer">
    <div class="site-footer-brand"><a href="/">WoWCensus</a><small>© ${new Date().getUTCFullYear()} WoWCensus. All rights reserved.</small></div>
    <nav class="site-footer-links" aria-label="Footer">${link("index", "Census")}${link("combos", "Race + Class")}${link("auctionhouse", "Auction House")}${link("talents", "Talents")}</nav>
    <nav class="site-footer-shortcuts" aria-label="More pages">${shortcut("guilds", "Guilds", "♜")}${shortcut("geography", "Geography", "◎")}${shortcut("gear", "Gear", "⚒")}</nav>
  </footer>`;
}

export function withFooter(html, source) {
  if (!html.includes("</body>")) throw new Error("Page has no closing body for the site footer");
  return html.replace("</body>", `${renderFooter(source)}</body>`);
}
