// Games with race and class talent pages. `dir` prefixes their routes
// (/forever/troll/, /tbc/paladin/); `home` is where that game's own pages live,
// which for Forever is the site root.
export const PROFILE_GAMES = {
  forever: { dir: "forever", home: "/forever/", nav: "Forever", gameLabel: "WoW: Forever" },
  "classic-beta": { dir: "forever-beta", home: "/forever-beta/", nav: "Forever (Beta)", gameLabel: "WoW: Forever (Beta)" },
  "classic-progression": { dir: "tbc", home: "/tbc/", nav: "TBC Anniversary", gameLabel: "TBC Anniversary" },
  classic: { dir: "classic", home: "/classic/", nav: "Classic Era", gameLabel: "Classic Era" },
  sod: { dir: "sod", home: "/sod/", nav: "SoD", gameLabel: "Season of Discovery" },
  hardcore: { dir: "hardcore", home: "/hardcore/", nav: "Hardcore", gameLabel: "Classic Hardcore" },
  "mop-classic": { dir: "mop", home: "/mop/", nav: "MoP Classic", gameLabel: "Mists of Pandaria Classic" },
  retail: { dir: "retail", home: "/retail/", nav: "Retail", gameLabel: "Retail" },
};
const PAGE_NAV = [["Census", ""], ["Auction House", "auctionhouse"], ["Guilds", "guilds"], ["Geography", "geography"], ["Talents", "talents"], ["Gear", "gear"]];
export const pageNav = (source) => PAGE_NAV.map(([label, page]) => [label, PROFILE_GAMES[source].home + page]);
export const talentsPath = (source) => PROFILE_GAMES[source].home + "talents";
// Edition switcher for a profile page: each game's own copy of this page
// (`counterpart` returns its route, or nothing), else that game's talents page.
export const gameNav = (counterpart) => Object.entries(PROFILE_GAMES).map(([source, g]) => [g.nav, counterpart(source) || talentsPath(source)]);
// Relative path from a profile route back to the static root, for assets that
// must also resolve when a page is opened from disk.
export const rootPrefix = (route) => "../".repeat(route.split("/").filter(Boolean).length);
