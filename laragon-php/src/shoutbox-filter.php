<?php
declare(strict_types=1);

/*
 * Shoutbox slur filter. Only slurs and hate phrases are listed; ordinary swearing
 * (damn, hell, wtf, ...) is allowed. Each term matches as a whole word, with
 * stretched letters ("niiigger"), leetspeak ("n1gg3r"), punctuation between letters
 * ("n.i.g") and fully spaced spellings ("n i g g e r"), plus an s/z/es plural.
 */

const SHOUT_FILTER_TERMS = [
  'nigger', 'nigga', 'niggah', 'nigguh', 'nig', 'niglet', 'nignog', 'sandnigger',
  'chink', 'gook', 'spic', 'spick', 'wetback', 'beaner', 'kike', 'kyke', 'heeb',
  'coon', 'jigaboo', 'jiggaboo', 'porch monkey', 'darkie', 'darky', 'golliwog',
  'raghead', 'towelhead', 'zipperhead', 'paki', 'wop', 'dago', 'ching chong', 'chingchong',
  'faggot', 'fagot', 'fag', 'faggy', 'dyke', 'tranny', 'trannie', 'shemale',
  'retard', 'retarded', 'mongoloid',
  'kkk', 'heil hitler', 'sieg heil', 'white power', 'gas the jews', '1488',
];

const SHOUT_FILTER_LEET = [
  'a' => 'a@4^', 'b' => 'b8', 'c' => 'ck(', 'e' => 'e3', 'g' => 'g69', 'i' => 'i1!|l',
  'l' => 'l1|', 'o' => 'o0', 's' => 's$5z', 't' => 't7+', 'z' => 'z2s',
];

function shout_filter_class(string $char): string {
  return '[' . preg_quote(SHOUT_FILTER_LEET[$char] ?? $char, '/') . ']';
}

/** Two regexes: letters run together (punctuation allowed between them), and every letter spaced apart. */
function shout_filter_patterns(): array {
  static $patterns = null;
  if ($patterns !== null) return $patterns;
  $compact = $spaced = [];
  foreach (SHOUT_FILTER_TERMS as $term) {
    $words = [];
    foreach (explode(' ', $term) as $word) {
      // A doubled letter must stay doubled, so "nigger" never matches "Niger".
      $gap = '[^\p{L}\p{N}\s]{0,2}';
      preg_match_all('/(.)\1*/', $word, $runs);
      $words[] = implode($gap, array_map(function ($run) use ($gap) {
        $class = shout_filter_class($run[0]);
        return $class . '(?:' . $gap . $class . '){' . (strlen($run) - 1) . ',}';
      }, $runs[0]));
    }
    $compact[] = implode('[^\p{L}\p{N}]+', $words);
    if (!str_contains($term, ' ')) $spaced[] = implode('[^\p{L}\p{N}]*\s[^\p{L}\p{N}]*', array_map('shout_filter_class', str_split($term)));
  }
  $wrap = fn(array $alternatives) => '/(?<![\p{L}\p{N}])(?:' . implode('|', $alternatives) . ')(?:e?[sz])?(?![\p{L}\p{N}])/iu';
  return $patterns = [$wrap($compact), $wrap($spaced)];
}

/** Returns [text with each match replaced by asterisks, whether anything matched]. */
function shout_censor(string $text): array {
  $hit = false;
  foreach (shout_filter_patterns() as $pattern) {
    $text = preg_replace_callback($pattern, function (array $m) use (&$hit) {
      $hit = true;
      return str_repeat('*', mb_strlen($m[0]));
    }, $text);
  }
  return [$text, $hit];
}
