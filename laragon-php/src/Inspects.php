<?php
// Port of describeBuild from site/src/inspects.mjs: classify an inspected
// player's build from its talent nodes or its inspected specialization.

declare(strict_types=1);

const FOREVER_TREE_NAMES = ['DRUID' => [281 => 'Feral Combat'], 'WARLOCK' => [302 => 'Affliction', 303 => 'Demonology']];
const SPEC_NAMES = [
  'WARRIOR' => [71 => 'Arms', 72 => 'Fury', 73 => 'Protection'], 'PALADIN' => [65 => 'Holy', 66 => 'Protection', 70 => 'Retribution'],
  'HUNTER' => [253 => 'Beast Mastery', 254 => 'Marksmanship', 255 => 'Survival'], 'ROGUE' => [259 => 'Assassination', 260 => 'Outlaw', 261 => 'Subtlety'],
  'PRIEST' => [256 => 'Discipline', 257 => 'Holy', 258 => 'Shadow'], 'DEATHKNIGHT' => [250 => 'Blood', 251 => 'Frost', 252 => 'Unholy'],
  'SHAMAN' => [262 => 'Elemental', 263 => 'Enhancement', 264 => 'Restoration'], 'MAGE' => [62 => 'Arcane', 63 => 'Fire', 64 => 'Frost'],
  'WARLOCK' => [265 => 'Affliction', 266 => 'Demonology', 267 => 'Destruction'], 'MONK' => [268 => 'Brewmaster', 269 => 'Windwalker', 270 => 'Mistweaver'],
  'DRUID' => [102 => 'Balance', 103 => 'Feral', 104 => 'Guardian', 105 => 'Restoration'],
  'DEMONHUNTER' => [577 => 'Havoc', 581 => 'Vengeance'], 'EVOKER' => [1467 => 'Devastation', 1468 => 'Preservation', 1473 => 'Augmentation'],
];

function talent_catalogs(): array {
  static $c = null;
  if ($c) return $c;
  $load = fn($f) => json_decode(file_get_contents(resource_path('src/' . $f)), true);
  $index = function (array $catalog): array { $m = []; foreach ($catalog['nodes'] as $n) $m[$n['node']] = $n; return $m; };
  $forever = $load('forever-talents.json');
  return $c = [
    'forever' => $index($forever), 'foreverVersion' => $forever['version'],
    'tbc' => $index($load('tbc-talents.json')),
    'classic' => $index($load('classic-talents.json')),
    'mop' => $load('mop-talent-icons.json')['spells'],
    'retail' => ($retail = $load('retail-talent-icons.json'))['spells'],
    'heroChoices' => $retail['heroChoices'], 'heroTrees' => $retail['heroTrees'],
  ];
}

function describe_build(array $e, string $source): array {
  $cat = talent_catalogs();
  $points = []; $talents = []; $unresolved = [];
  $specBased = $source === 'retail' || $source === 'mop-classic';
  $listed = array_flip(array_map(fn($t) => $t['node'], $e['list'] ?? []));
  foreach ($e['list'] ?? [] as $t) {
    $candidate = $source === 'classic-beta' ? ($cat['forever'][$t['node']] ?? null) : null;
    $mapped = $candidate && $candidate['classFile'] === $e['class'] && $candidate['max'] === $t['max']
      && (empty($t['spell']) || $candidate['spell'] === $t['spell']) ? $candidate : null;
    $branch = $mapped
      ? (FOREVER_TREE_NAMES[$mapped['classFile']][$mapped['tab']] ?? null ?: $mapped['branch'])
      : ($source !== 'classic-beta' && ($t['branch'] ?? '') !== '' ? $t['branch'] : null);
    if ($branch !== null && $branch !== '') $points[$branch] = ($points[$branch] ?? 0) + $t['rank'];
    else $unresolved[] = $t['node'];
    // TBC and the Classic Era clients report tree names; their catalogs only supply icons.
    $tbc = $source === 'classic-progression' ? ($cat['tbc'][$t['node']] ?? null)
      : (in_array($source, ['classic', 'sod', 'hardcore'], true) ? ($cat['classic'][$t['node']] ?? null) : null);
    $spell = $t['spell'] ?? null;
    $icon = ($mapped['icon'] ?? null) ?: (($tbc && $tbc['classFile'] === $e['class'] && $tbc['max'] === $t['max'] ? $tbc['icon'] : null)
      ?: (($source === 'mop-classic' && $spell !== null ? ($cat['mop'][$spell] ?? null) : null)
      ?: ($source === 'retail' && $spell !== null ? ($cat['retail'][$spell] ?? null) : null)));
    $t['branch'] = ($branch !== null && $branch !== '') ? $branch
      : ($specBased && !empty($t['tree']) ? 'Tree ' . js_string($t['tree']) : 'Unmapped talent');
    $t['icon'] = $icon ?: null;
    // A retail hero-tree choice node has no spell: name it after the subtree
    // whose talents the player took, and count each subtree separately.
    if ($source === 'retail' && $spell === null && isset($cat['heroChoices'][$t['node']]))
      foreach ($cat['heroChoices'][$t['node']] as $id) {
        $hero = $cat['heroTrees'][$id];
        if (!array_filter($hero['nodes'], fn($n) => isset($listed[$n]))) continue;
        $t['node'] = 'hero:' . $id; $t['name'] = $hero['name']; $t['icon'] = $hero['icon'];
        break;
      }
    $talents[] = $t;
  }
  $orderedPoints = array_map(fn($p) => ['tree' => (string)$p[0], 'points' => $p[1]],
    js_sort(js_entries($points), fn($a, $b) => js_or($b[1] - $a[1], fn() => js_cmp((string)$a[0], (string)$b[0]))));
  $totalPoints = 0;
  foreach ($e['list'] ?? [] as $t) $totalPoints += $t['rank'];
  $specID = $e['spec'] ?? ($e['rawSpecID'] ?? null);
  $spec = $specID !== null ? (SPEC_NAMES[$e['class']][$specID] ?? null) : null;
  $eraValid = $source !== 'mop-classic' || !in_array($e['class'], ['DEMONHUNTER', 'EVOKER'], true);
  $primaryTree = null; $classification = 'Unknown build'; $hybrid = false; $unknownReason = null;
  if ($specBased) {
    if ($spec && $eraValid) $classification = $source === 'mop-classic' && (int)$specID === 260 ? 'Combat' : $spec;
    else $unknownReason = $specID === null || $specID === 0 ? 'No inspected specialization ID' : 'Unrecognized specialization ID for this class and edition';
  } elseif ($unresolved) {
    $unknownReason = 'Selected talent nodes could not be mapped to trusted trees';
  } elseif (!$orderedPoints) {
    $unknownReason = 'No allocated talent points observed';
  } else {
    $primaryTree = $orderedPoints[0]['tree'];
    $participating = [];
    foreach ($orderedPoints as $i => $p)
      if ($i === 0 || ($p['points'] / $totalPoints >= 0.25 && $orderedPoints[0]['points'] - $p['points'] <= 10)) $participating[] = $p;
    $hybrid = count($participating) > 1;
    $classification = implode('/', array_map(fn($p) => $p['tree'], $participating));
  }
  return ['points' => $points, 'orderedPoints' => $orderedPoints, 'primaryTree' => $primaryTree, 'classification' => $classification,
    'hybrid' => $hybrid, 'unknownReason' => $unknownReason, 'dominant' => $classification, 'talents' => $talents,
    'unresolvedNodes' => count($unresolved), 'totalPoints' => $totalPoints,
    'classificationMethod' => $specBased ? 'inspected-specialization-id' : 'inferred-talent-trees',
    'role' => null, 'catalogVersion' => $source === 'classic-beta' ? $cat['foreverVersion'] : null];
}
