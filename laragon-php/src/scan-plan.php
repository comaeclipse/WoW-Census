<?php
// The /who scan plan behind public/schedule.php (local only). Edit this file to
// change the calendar; the page reads it on every request.
//
// How the schedule is built:
// - Each region has a number of lanes. A lane holds one realm at a time and
//   gives it four realm-local windows per week. Both lanes share a window:
//   sweep the first lane's realm, then the second's, any time inside it.
//   Windows sit in the workday or evening (US Central) so sweeps can run
//   between other things: leave the game open and tap the census key.
// - Every edition runs at once. The queue per region is: the `lead` editions
//   first, then the other editions in turn (one realm each, round robin), then
//   the `last` editions. Each realm takes the next free lane for `weeks`
//   consecutive weeks. TBC Anniversary runs for three weeks to check the
//   estimate is stable week to week; everything else gets one week.
// - At every window, sweep both factions back to back. A sweep counts only if
//   it is complete, has no capped queries and starts inside the window.
// - Each edition gets one calibration day: a sweep every two hours on one
//   realm-faction in that realm's first week, used to turn "seen in a sweep"
//   into "played this week".
// - `unsampled` records realms that exist but are not scanned, so a later
//   estimate can scale the sampled small realms up to the whole edition.
//   Oceanic (AU) realms are left out of the plan.

declare(strict_types=1);

return [
  'start' => '2026-10-19', // a Monday
  'lanes' => ['us' => 6, 'eu' => 6],
  'lead' => ['classic-progression'],
  'last' => ['retail'],
  // A sweep counts toward a window if it starts up to this long either side of it.
  'slack_minutes' => 15,
  // Windows that start earlier than this in your own time get a warning.
  'earliest' => '07:30',
  // When you can have the game open at work (your own time, ISO weekdays).
  // Windows inside it are tagged "at work"; one that runs past the end says so.
  'work' => ['days' => [1, 2, 3, 4, 5], 'from' => '07:30', 'to' => '15:30'],
  // Rough minutes per faction sweep (median of past complete sweeps), for the
  // weekly hours estimate. TBC Anniversary has no complete sweep yet.
  'sweep_minutes' => ['classic-progression' => 30, 'hardcore' => 20, 'sod' => 5, 'mop-classic' => 10, 'classic' => 16, 'retail' => 8],
  'zones' => ['us' => 'America/New_York', 'eu' => 'Europe/Paris'],
  // [ISO weekday (1 = Monday), realm-local window start, end, label].
  // Six realms share each window, so the windows are wide. In Central time:
  // EU Mon 7:30-11, Tue 12-3:30, Thu 12-3:30, Sat 8-11:30 am; US Tue 7-10 pm,
  // Wed 11-2, Thu 7-10 pm, Sat 1-4:30 pm. (EU windows are an hour later from
  // Oct 26 to Nov 1, between the European and US clock changes.)
  'slots' => [
    'us' => [[2, '20:00', '23:00', 'Weekday evening peak'], [3, '12:00', '15:00', 'Weekday midday'], [4, '20:00', '23:00', 'Raid-night peak'], [6, '14:00', '17:30', 'Weekend afternoon']],
    'eu' => [[1, '14:30', '18:00', 'Weekday afternoon'], [2, '19:00', '22:30', 'Weekday evening peak'], [4, '19:00', '22:30', 'Raid-night peak'], [6, '15:00', '18:30', 'Weekend afternoon']],
  ],
  'calibration' => [
    // Days with no lane slots in either region. A calibration goes on the
    // first free one, from its realm's first week on.
    'weekdays' => [5, 7],
    // Realm-local window starts; 09:00 ET is 8 am Central.
    'times' => ['09:00', '11:00', '13:00', '15:00', '17:00', '19:00', '21:00', '23:00'],
    'window_minutes' => 60,
    // edition => [region, realm, faction]
    'realms' => [
      'classic-progression' => ['us', 'Nightslayer', 'Horde'],
      'hardcore' => ['us', 'Doomhowl', 'Horde'],
      'sod' => ['us', 'Wild Growth', 'Alliance'],
      'mop-classic' => ['us', 'Galakras', 'Alliance'],
      'classic' => ['us', 'Windseeker', 'Horde'],
      'retail' => ['us', "Zul'jin", 'Horde'],
    ],
  ],
  // Saves from SoD realms sometimes land in the Classic Era bucket.
  'also_match' => ['sod' => ['classic']],
  // [edition, region, realm, weeks, tier, note]. tier: large = always scanned,
  // small = stands in for the unsampled realms of its edition and region.
  // Within an edition, realms are scanned in this order.
  'realms' => [
    ['classic-progression', 'us', 'Dreamscythe', 3, 'large', 'Biggest realm: split capped queries until none are left.'],
    ['classic-progression', 'us', 'Nightslayer', 3, 'large', 'PvP.'],
    ['classic-progression', 'eu', 'Thunderstrike', 3, 'large', ''],
    ['classic-progression', 'eu', 'Spineshatter', 3, 'large', 'PvP. Not scanned yet: check the realm list and make characters.'],

    ['hardcore', 'us', 'Doomhowl', 1, 'large', ''],
    ['sod', 'us', 'Wild Growth', 1, 'large', ''],
    ['sod', 'us', 'Crusader Strike', 1, 'small', 'Earlier sweeps found almost no one; check it is still open.'],
    ['sod', 'us', 'Lone Wolf', 1, 'small', 'Earlier sweeps found almost no one; check it is still open.'],
    ['hardcore', 'eu', 'Soulseeker', 1, 'large', 'Only Alliance scanned so far.'],
    ['sod', 'eu', 'Wild Growth', 1, 'large', ''],
    ['sod', 'eu', 'Living Flame', 1, 'large', 'PvP. Not scanned yet.'],

    ['mop-classic', 'us', 'Pagle', 1, 'large', ''],
    ['mop-classic', 'us', 'Galakras', 1, 'large', ''],
    ['mop-classic', 'us', 'Lei Shen', 1, 'large', 'Not scanned yet.'],
    ['mop-classic', 'us', 'Nazgrim', 1, 'large', ''],
    ['mop-classic', 'us', 'Ra-den', 1, 'large', ''],
    ['mop-classic', 'us', 'Immerseus', 1, 'large', ''],
    ['mop-classic', 'eu', 'Mirage Raceway', 1, 'large', 'Not scanned yet.'],
    ['mop-classic', 'eu', "Shek'zeer", 1, 'large', ''],
    ['mop-classic', 'eu', 'Hoptallus', 1, 'large', 'Not scanned yet.'],
    ['mop-classic', 'eu', 'Garalon', 1, 'small', 'Not scanned yet.'],
    ['mop-classic', 'eu', 'Norushen', 1, 'small', 'Not scanned yet.'],

    ['classic', 'us', 'Mankrik', 1, 'large', ''],
    ['classic', 'us', 'Westfall', 1, 'large', ''],
    ['classic', 'us', 'Ashkandi', 1, 'large', ''],
    ['classic', 'us', 'Windseeker', 1, 'large', 'Not scanned yet.'],
    ['classic', 'us', 'Pagle', 1, 'large', 'Not scanned yet.'],
    ['classic', 'us', 'Whitemane', 1, 'large', 'PvP.'],
    ['classic', 'us', 'Azuresong', 1, 'small', ''],
    ['classic', 'us', 'Old Blanchy', 1, 'small', ''],
    ['classic', 'us', 'Anathema', 1, 'small', 'PvP. Not scanned yet.'],
    ['classic', 'eu', 'Pyrewood Village', 1, 'large', 'Not scanned yet.'],
    ['classic', 'eu', 'Mirage Raceway', 1, 'large', 'Not scanned yet.'],
    ['classic', 'eu', 'Nethergarde Keep', 1, 'large', ''],

    ['retail', 'us', 'Illidan', 1, 'large', ''],
    ['retail', 'us', 'Stormrage', 1, 'large', ''],
    ['retail', 'us', 'Area 52', 1, 'large', ''],
    ['retail', 'us', "Zul'jin", 1, 'small', ''],
    ['retail', 'us', 'Nesingwary', 1, 'small', ''],
    ['retail', 'us', 'Drenden', 1, 'small', ''],
    ['retail', 'eu', 'Draenor', 1, 'large', ''],
    ['retail', 'eu', 'Kilrogg', 1, 'small', ''],
    ['retail', 'eu', 'Turalyon', 1, 'small', ''],
    ['retail', 'eu', 'Dun Modr', 1, 'small', ''],
    ['retail', 'eu', 'Nemesis', 1, 'small', ''],
    ['retail', 'eu', 'Doomhammer', 1, 'small', ''],
  ],
  // Realms seen in the data but not scheduled. Add the rest from the in-game
  // realm list (with its Low/Medium/High label) before estimating.
  'unsampled' => [
    'classic' => ['us' => ['Atiesh', 'Myzrael', 'Thunderfury', 'Bigglesworth', 'Arcanite Reaper', 'Fairbanks', 'Rattlegore']],
    'hardcore' => ['us' => ['Defias Pillager', 'Skull Rock'], 'eu' => ['Stitches', "Nek'Rosh"]],
    'retail' => ['us' => ['Blackmoore', 'Frostmourne', "Quel'Thalas", 'Nazgrel', "Vek'nilash", 'Hellscream', 'Zangarmarsh', '… and about 230 more'],
                 'eu' => ["C'Thun", 'Arathi', 'Illidan', '… and about 250 more']],
  ],
];
