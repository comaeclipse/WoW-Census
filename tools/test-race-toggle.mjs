import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mergeCensusUnits } from '../site/src/edition.mjs';
import { renderCensusHtml } from '../site/src/census.mjs';

test('race windows merge realm counts without changing the existing census total', () => {
  const unit = (game, all, week, day) => ({
    game, faction: 'Alliance', races: { Human: 4 }, classes: {}, combos: {},
    raceWindows: { all: { Human: all }, week: { Human: week }, day: { Human: day } },
    characters: 4, samples: 1, observed: 5,
  });
  const [group] = mergeCensusUnits([unit('one', 8, 3, 1), unit('two', 6, 2, 0)]);
  assert.equal(group.characters, 8);
  assert.deepEqual(group.raceWindows, {
    all: { Human: 14 }, week: { Human: 5 }, day: { Human: 1 },
  });
});

test('race panel renders each window ahead of interaction and keeps class totals separate', () => {
  const group = {
    faction: 'Alliance', races: { Human: 4 }, classes: { MAGE: 4 }, combos: {},
    raceWindows: { all: { Human: 14 }, week: { Human: 5 }, day: { Human: 1 } },
    characters: 4, samples: 1, observed: 5, games: [],
  };
  const html = renderCensusHtml([{ key: 'all', label: 'All', census: {
    groups: [group], realms: ['one'], lastT: 1,
  } }]);
  assert.match(html, /data-window="all" aria-pressed="true">All<\/button>/);
  assert.match(html, /data-window="week" aria-pressed="false">7 days<\/button>/);
  assert.match(html, /data-window="day" aria-pressed="false">24 hours<\/button>/);
  assert.match(html, /<div class="race-bars" data-window="all">[\s\S]*?<div class="bn">14<\/div>/);
  assert.match(html, /<div class="race-bars" data-window="week" hidden>[\s\S]*?<div class="bn">5<\/div>/);
  assert.match(html, /<div class="race-bars" data-window="day" hidden>[\s\S]*?<div class="bn">1<\/div>/);
  assert.match(html, /4 characters shown/);
  assert.match(html, /addEventListener\('popstate'/);
  assert.match(html, /searchParams\.set\('race'/);
});
