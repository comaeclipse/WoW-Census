// Games with race and class talent pages. `dir` prefixes their routes
// (/forever/troll/, /tbc/paladin/); `home` is where that game's own pages live,
// which for Forever is the site root.
export const PROFILE_GAMES = {
  "classic-beta": { dir: "forever", home: "/", nav: "Forever", gameLabel: "WoW Forever" },
  "classic-progression": { dir: "tbc", home: "/tbc/", nav: "TBC Anniversary", gameLabel: "TBC Anniversary" },
  classic: { dir: "classic", home: "/classic/", nav: "Classic Era", gameLabel: "Classic Era" },
  sod: { dir: "sod", home: "/sod/", nav: "SoD", gameLabel: "Season of Discovery" },
  hardcore: { dir: "hardcore", home: "/hardcore/", nav: "Hardcore", gameLabel: "Classic Hardcore" },
  "mop-classic": { dir: "mop", home: "/mop/", nav: "MoP Classic", gameLabel: "Mists of Pandaria Classic" },
  retail: { dir: "retail", home: "/retail/", nav: "Retail", gameLabel: "Retail" },
};
export const GAME_NAV = [["Forever", "/"], ["TBC Anniversary", "/tbc/"], ["Classic Era", "/classic/"], ["SoD", "/sod/"], ["Hardcore", "/hardcore/"], ["MoP Classic", "/mop/"], ["Retail", "/retail/"]];
const PAGE_NAV = [["Census", ""], ["Auction House", "auctionhouse"], ["Guilds", "guilds"], ["Geography", "geography"], ["Talents", "talents"], ["Gear", "gear"]];
export const pageNav = (source) => PAGE_NAV.map(([label, page]) => [label, PROFILE_GAMES[source].home + page]);
export const talentsPath = (source) => PROFILE_GAMES[source].home + "talents";
// Below this many players a spec column or class card shows its count instead
// of ranking talents: with a handful of players every pick reads as 0% or 100%.
export const MIN_SAMPLE = 20;
// Relative path from a profile route back to the static root, for assets that
// must also resolve when a page is opened from disk.
export const rootPrefix = (route) => "../".repeat(route.split("/").filter(Boolean).length);
