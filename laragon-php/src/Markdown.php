<?php
// Markdown companions: port of the retired build-markdown-pages.js and the parts of
// turndown 7.2 and turndown-plugin-gfm it uses. The generated HTML is
// well-formed, so a small tree builder stands in for linkedom and domino.

declare(strict_types=1);

// JavaScript's \s, for the regexes turndown runs.
const JS_WS = '\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';

final class HNode {
  public ?HNode $parent = null;
  public ?HNode $first = null;
  public ?HNode $last = null;
  public ?HNode $prev = null;
  public ?HNode $next = null;
  public array $attrs = [];
  // turndown's per-node decorations.
  public bool $isBlock = false;
  public bool $isCode = false;
  public bool $isBlank = false;
  public array $flank = ['', ''];

  public function __construct(public int $type, public string $name = '', public string $data = '') {}

  public function append(HNode $child): void {
    if ($child->parent) $child->remove();
    $child->parent = $this;
    $child->prev = $this->last;
    if ($this->last) $this->last->next = $child; else $this->first = $child;
    $this->last = $child;
  }

  public function remove(): void {
    $p = $this->parent;
    if (!$p) return;
    if ($this->prev) $this->prev->next = $this->next; else $p->first = $this->next;
    if ($this->next) $this->next->prev = $this->prev; else $p->last = $this->prev;
    $this->parent = $this->prev = $this->next = null;
  }

  public function replaceWith(HNode $node): void {
    $p = $this->parent;
    if ($node->parent) $node->remove();
    $node->parent = $p; $node->prev = $this->prev; $node->next = $this->next;
    if ($this->prev) $this->prev->next = $node; else $p->first = $node;
    if ($this->next) $this->next->prev = $node; else $p->last = $node;
    $this->parent = $this->prev = $this->next = null;
  }

  public function clear(): void {
    for ($c = $this->first; $c; $c = $n) { $n = $c->next; $c->parent = $c->prev = $c->next = null; }
    $this->first = $this->last = null;
  }

  /** @return list<HNode> */
  public function children(bool $elementsOnly = false): array {
    $out = [];
    for ($c = $this->first; $c; $c = $c->next) if (!$elementsOnly || $c->type === 1) $out[] = $c;
    return $out;
  }

  public function text(): string {
    if ($this->type === 3) return $this->data;
    $s = '';
    for ($c = $this->first; $c; $c = $c->next) if ($c->type === 3 || $c->type === 1) $s .= $c->text();
    return $s;
  }

  public function setText(string $text): void {
    $this->clear();
    if ($text !== '') $this->append(new HNode(3, '', $text));
  }

  public function attr(string $name): ?string { return $this->attrs[$name] ?? null; }

  public function hasClass(string $cls): bool {
    return isset($this->attrs['class']) && in_array($cls, preg_split('/\s+/', trim($this->attrs['class'])), true);
  }

  /** Descendant elements in document order matching $test. @return list<HNode> */
  public function find(callable $test): array {
    $out = [];
    $stack = [];
    for ($c = $this->last; $c; $c = $c->prev) $stack[] = $c;
    while ($stack) {
      $n = array_pop($stack);
      if ($n->type !== 1) continue;
      if ($test($n)) $out[] = $n;
      for ($c = $n->last; $c; $c = $c->prev) $stack[] = $c;
    }
    return $out;
  }

  public function findFirst(callable $test): ?HNode { return $this->find($test)[0] ?? null; }
}

const HTML_VOID = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr', 'keygen', 'command'];

function html_decode(string $s): string {
  return str_contains($s, '&') ? html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8') : $s;
}

// Parse well-formed HTML into an HNode document. Script and style bodies are raw text.
function html_parse(string $html): HNode {
  $doc = new HNode(9, '#document');
  $stack = [$doc];
  $len = strlen($html);
  $i = 0;
  $text = '';
  $flush = function () use (&$text, &$stack) {
    if ($text === '') return;
    $top = end($stack);
    $t = new HNode(3, '#text', html_decode($text));
    if ($top->last && $top->last->type === 3) $top->last->data .= $t->data; else $top->append($t);
    $text = '';
  };
  while ($i < $len) {
    $lt = strpos($html, '<', $i);
    if ($lt === false) { $text .= substr($html, $i); break; }
    $text .= substr($html, $i, $lt - $i);
    $i = $lt;
    if (substr($html, $i, 4) === '<!--') {
      $flush();
      $end = strpos($html, '-->', $i + 4);
      $i = $end === false ? $len : $end + 3;
      continue;
    }
    if (preg_match('/\G<!/A', $html, $m, 0, $i)) {
      $flush();
      $end = strpos($html, '>', $i);
      $i = $end === false ? $len : $end + 1;
      continue;
    }
    if (preg_match('/\G<\/([a-zA-Z][a-zA-Z0-9:-]*)\s*>/A', $html, $m, 0, $i)) {
      $flush();
      $name = strtolower($m[1]);
      for ($k = count($stack) - 1; $k > 0; $k--) {
        if ($stack[$k]->name === strtoupper($name)) { array_splice($stack, $k); break; }
      }
      $i += strlen($m[0]);
      continue;
    }
    if (preg_match('/\G<([a-zA-Z][a-zA-Z0-9:-]*)((?:\s+[^\s"\'>\/=]+(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+))?)*)\s*(\/?)>/A', $html, $m, 0, $i)) {
      $flush();
      $name = strtolower($m[1]);
      $el = new HNode(1, strtoupper($name));
      preg_match_all('/([^\s"\'>\/=]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?/', $m[2], $attrs, PREG_SET_ORDER);
      foreach ($attrs as $a) {
        $key = strtolower($a[1]);
        if (!array_key_exists($key, $el->attrs)) $el->attrs[$key] = html_decode(($a[2] ?? '') . ($a[3] ?? '') . ($a[4] ?? ''));
      }
      end($stack)->append($el);
      $i += strlen($m[0]);
      if ($name === 'script' || $name === 'style' || $name === 'title' || $name === 'textarea') {
        $close = stripos($html, '</' . $name, $i);
        $raw = substr($html, $i, ($close === false ? $len : $close) - $i);
        if ($raw !== '') $el->append(new HNode(3, '#text', ($name === 'title' || $name === 'textarea') ? html_decode($raw) : $raw));
        $i = $close === false ? $len : strpos($html, '>', $close) + 1;
        continue;
      }
      if (!in_array($name, HTML_VOID, true) && $m[3] !== '/') $stack[] = $el;
      continue;
    }
    $text .= '<';
    $i++;
  }
  $flush();
  return $doc;
}

// ---- turndown -------------------------------------------------------------

const TD_BLOCK = ['ADDRESS', 'ARTICLE', 'ASIDE', 'AUDIO', 'BLOCKQUOTE', 'BODY', 'CANVAS', 'CENTER', 'DD', 'DIR', 'DIV', 'DL', 'DT', 'FIELDSET', 'FIGCAPTION', 'FIGURE', 'FOOTER', 'FORM', 'FRAMESET', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'HEADER', 'HGROUP', 'HR', 'HTML', 'ISINDEX', 'LI', 'MAIN', 'MENU', 'NAV', 'NOFRAMES', 'NOSCRIPT', 'OL', 'OUTPUT', 'P', 'PRE', 'SECTION', 'TABLE', 'TBODY', 'TD', 'TFOOT', 'TH', 'THEAD', 'TR', 'UL'];
const TD_VOID = ['AREA', 'BASE', 'BR', 'COL', 'COMMAND', 'EMBED', 'HR', 'IMG', 'INPUT', 'KEYGEN', 'LINK', 'META', 'PARAM', 'SOURCE', 'TRACK', 'WBR'];
const TD_MEANINGFUL_BLANK = ['A', 'TABLE', 'THEAD', 'TBODY', 'TFOOT', 'TH', 'TD', 'IFRAME', 'SCRIPT', 'AUDIO', 'VIDEO'];

function td_is(HNode $n, array $names): bool { return $n->type === 1 && in_array($n->name, $names, true); }
function td_has(HNode $n, array $names): bool {
  return (bool)$n->findFirst(fn($e) => in_array($e->name, $names, true));
}

function td_trim(string $s): string { return preg_replace('/^[' . JS_WS . ']+|[' . JS_WS . ']+$/u', '', $s); }

function td_escape(string $s): string {
  $s = str_replace('\\', '\\\\', $s);
  $s = str_replace('*', '\\*', $s);
  $s = preg_replace('/^-/', '\\-', $s);
  $s = preg_replace('/^\+ /', '\\+ ', $s);
  $s = preg_replace('/^(=+)/', '\\\\$1', $s);
  $s = preg_replace('/^(#{1,6}) /', '\\\\$1 ', $s);
  $s = str_replace('`', '\\`', $s);
  $s = preg_replace('/^~~~/', '\\~~~', $s);
  $s = str_replace(['[', ']'], ['\\[', '\\]'], $s);
  $s = preg_replace('/^>/', '\\>', $s);
  $s = str_replace('_', '\\_', $s);
  return preg_replace('/^(\d+)\. /', '$1\\. ', $s);
}

function td_collapse_whitespace(HNode $element): void {
  if (!$element->first || $element->name === 'PRE') return;
  $prevText = null; $keepLeadingWs = false; $prev = null;
  $next = function (?HNode $prev, HNode $current): ?HNode {
    if (($prev && $prev->parent === $current) || $current->name === 'PRE') return $current->next ?? $current->parent;
    return $current->first ?? $current->next ?? $current->parent;
  };
  $remove = function (HNode $node): ?HNode {
    $n = $node->next ?? $node->parent;
    $node->remove();
    return $n;
  };
  $node = $next(null, $element);
  while ($node !== $element) {
    if ($node->type === 3) {
      $text = preg_replace('/[ \r\n\t]+/', ' ', $node->data);
      if ((!$prevText || str_ends_with($prevText->data, ' ')) && !$keepLeadingWs && ($text[0] ?? '') === ' ') $text = substr($text, 1);
      if ($text === '') { $node = $remove($node); continue; }
      $node->data = $text;
      $prevText = $node;
    } elseif ($node->type === 1) {
      if (in_array($node->name, TD_BLOCK, true) || $node->name === 'BR') {
        if ($prevText) $prevText->data = preg_replace('/ $/', '', $prevText->data);
        $prevText = null; $keepLeadingWs = false;
      } elseif (in_array($node->name, TD_VOID, true) || $node->name === 'PRE') {
        $prevText = null; $keepLeadingWs = true;
      } elseif ($prevText) {
        $keepLeadingWs = false;
      }
    } else { $node = $remove($node); continue; }
    $nextNode = $next($prev, $node);
    $prev = $node;
    $node = $nextNode;
  }
  if ($prevText) {
    $prevText->data = preg_replace('/ $/', '', $prevText->data);
    if ($prevText->data === '') $prevText->remove();
  }
}

function td_edge_whitespace(string $s): array {
  $ws = '[' . JS_WS . ']';
  preg_match('/^(([ \t\r\n]*)(' . $ws . '*))(?:(?=[^' . JS_WS . '])[\s\S]*[^' . JS_WS . '])?((' . $ws . '*?)([ \t\r\n]*))$/u', $s, $m);
  return ['leading' => $m[1], 'leadingAscii' => $m[2], 'leadingNonAscii' => $m[3], 'trailing' => $m[4], 'trailingNonAscii' => $m[5], 'trailingAscii' => $m[6]];
}

function td_flanked(string $side, HNode $node): bool {
  $sibling = $side === 'left' ? $node->prev : $node->next;
  $re = $side === 'left' ? '/ $/' : '/^ /';
  if (!$sibling) return false;
  if ($sibling->type === 3) return (bool)preg_match($re, $sibling->data);
  if ($sibling->type === 1 && !td_is($sibling, TD_BLOCK)) return (bool)preg_match($re, $sibling->text());
  return false;
}

function td_decorate(HNode $node): void {
  $node->isBlock = td_is($node, TD_BLOCK);
  $node->isCode = $node->name === 'CODE' || ($node->parent && $node->parent->isCode);
  $node->isBlank = $node->type === 1 && !td_is($node, TD_VOID) && !td_is($node, TD_MEANINGFUL_BLANK)
    && preg_match('/^[' . JS_WS . ']*$/u', $node->text()) && !td_has($node, TD_VOID) && !td_has($node, TD_MEANINGFUL_BLANK);
  if ($node->isBlock) { $node->flank = ['', '']; return; }
  $edges = td_edge_whitespace($node->text());
  if ($edges['leadingAscii'] !== '' && td_flanked('left', $node)) $edges['leading'] = $edges['leadingNonAscii'];
  if ($edges['trailingAscii'] !== '' && td_flanked('right', $node)) $edges['trailing'] = $edges['trailingNonAscii'];
  $node->flank = [$edges['leading'], $edges['trailing']];
}

function td_trim_trailing_newlines(string $s): string { return rtrim($s, "\n"); }
function td_trim_leading_newlines(string $s): string { return ltrim($s, "\n"); }

function td_join(string $output, string $replacement): string {
  $s1 = td_trim_trailing_newlines($output);
  $s2 = td_trim_leading_newlines($replacement);
  $nls = max(strlen($output) - strlen($s1), strlen($replacement) - strlen($s2));
  return $s1 . substr("\n\n", 0, $nls) . $s2;
}

function td_process(HNode $parent): string {
  $output = '';
  foreach ($parent->children() as $node) {
    td_decorate($node);
    $replacement = '';
    if ($node->type === 3) $replacement = $node->isCode ? $node->data : td_escape($node->data);
    elseif ($node->type === 1) $replacement = td_replacement_for_node($node);
    $output = td_join($output, $replacement);
  }
  return $output;
}

function td_is_heading_row(?HNode $tr): bool {
  if (!$tr) return false;
  $p = $tr->parent;
  if ($p->name === 'THEAD') return true;
  if ($p->first !== $tr) return false;
  $firstTbody = $p->name === 'TBODY' && (!$p->prev || ($p->prev->name === 'THEAD' && preg_match('/^[' . JS_WS . ']*$/u', $p->prev->text())));
  if ($p->name !== 'TABLE' && !$firstTbody) return false;
  foreach ($tr->children() as $c) if ($c->name !== 'TH') return false;
  return true;
}

function td_table_rows(HNode $table): array {
  $rows = [];
  foreach ($table->children(true) as $c) {
    if ($c->name === 'TR') $rows[] = $c;
    elseif (in_array($c->name, ['THEAD', 'TBODY', 'TFOOT'], true)) foreach ($c->children(true) as $r) if ($r->name === 'TR') $rows[] = $r;
  }
  // The DOM orders rows thead, then tbody and bare rows, then tfoot.
  usort($rows, fn($a, $b) => ($a->parent->name === 'THEAD' ? 0 : ($a->parent->name === 'TFOOT' ? 2 : 1)) <=> ($b->parent->name === 'THEAD' ? 0 : ($b->parent->name === 'TFOOT' ? 2 : 1)));
  return $rows;
}

function td_cell(string $content, HNode $node): string {
  return ($node->parent->first === $node ? '| ' : ' ') . $content . ' |';
}

function td_replacement_for_node(HNode $node): string {
  $content = td_process($node);
  [$leading, $trailing] = $node->flank;
  if ($leading !== '' || $trailing !== '') $content = td_trim($content);
  return $leading . td_rule($content, $node) . $trailing;
}

function td_rule(string $content, HNode $node): string {
  if ($node->isBlank) return $node->isBlock ? "\n\n" : '';
  $name = $node->name;
  switch ($name) {
    case 'THEAD': case 'TBODY': case 'TFOOT': return $content;
    case 'TABLE':
      if (td_is_heading_row(td_table_rows($node)[0] ?? null)) {
        $at = strpos($content, "\n\n");
        if ($at !== false) $content = substr_replace($content, "\n", $at, 2);
        return "\n\n" . $content . "\n\n";
      }
      throw new RuntimeException('Table without a heading row');
    case 'TR':
      $border = '';
      if (td_is_heading_row($node)) {
        $align = ['left' => ':--', 'right' => '--:', 'center' => ':-:'];
        foreach ($node->children() as $c) {
          $a = strtolower($c->attr('align') ?? '');
          $border .= td_cell($a !== '' ? ($align[$a] ?? '---') : '---', $c);
        }
      }
      return "\n" . $content . ($border !== '' ? "\n" . $border : '');
    case 'TH': case 'TD': return td_cell($content, $node);
    case 'DEL': case 'S': case 'STRIKE': return '~' . $content . '~';
    case 'P': return "\n\n" . $content . "\n\n";
    case 'BR': return "  \n";
    case 'H1': case 'H2': case 'H3': case 'H4': case 'H5': case 'H6':
      return "\n\n" . str_repeat('#', (int)$name[1]) . ' ' . $content . "\n\n";
    case 'BLOCKQUOTE':
      return "\n\n" . preg_replace('/^/m', '> ', trim($content, "\n")) . "\n\n";
    case 'UL': case 'OL':
      $parent = $node->parent;
      if ($parent->name === 'LI' && end($parent->children(true)) === $node) return "\n" . $content;
      return "\n\n" . $content . "\n\n";
    case 'LI':
      $prefix = '-   ';
      $parent = $node->parent;
      if ($parent->name === 'OL') {
        $start = $parent->attr('start');
        $index = array_search($node, $parent->children(true), true);
        $prefix = (($start !== null && $start !== '') ? (int)$start + $index : $index + 1) . '.  ';
      }
      $isParagraph = str_ends_with($content, "\n");
      $content = trim($content, "\n") . ($isParagraph ? "\n" : '');
      $content = str_replace("\n", "\n" . str_repeat(' ', strlen($prefix)), $content);
      return $prefix . $content . ($node->next ? "\n" : '');
    case 'HR': return "\n\n* * *\n\n";
    case 'A':
      $href = $node->attr('href');
      if ($href !== null && $href !== '') {
        $dest = preg_replace('/([<>()])/', '\\\\$1', $href);
        if (str_contains($dest, ' ')) $dest = '<' . $dest . '>';
        $title = $node->attr('title');
        $title = $title ? str_replace('"', '\\"', preg_replace('/(\n+\s*)+/', "\n", $title)) : '';
        return '[' . $content . '](' . $dest . ($title !== '' ? ' "' . $title . '"' : '') . ')';
      }
      break;
    case 'EM': case 'I':
      return td_trim($content) === '' ? '' : '_' . $content . '_';
    case 'STRONG': case 'B':
      return td_trim($content) === '' ? '' : '**' . $content . '**';
    case 'CODE':
    case 'PRE':
    case 'IMG':
      throw new RuntimeException("Unported turndown rule for <$name>");
  }
  return $node->isBlock ? "\n\n" . $content . "\n\n" : $content;
}

function turndown(HNode $root): string {
  td_collapse_whitespace($root);
  $output = td_process($root);
  return preg_replace('/[\t\r\n' . JS_WS . ']+$/u', '', preg_replace('/^[\t\r\n]+/', '', $output));
}

// ---- build-markdown-pages.js ---------------------------------------------

function md_cell(mixed $value): string {
  return td_trim(preg_replace('/[' . JS_WS . ']+/u', ' ', str_replace('|', '\\|', $value === null ? '' : js_string($value))));
}

function md_class_name(mixed $value): string {
  $names = ['DEATHKNIGHT' => 'Death Knight', 'DEMONHUNTER' => 'Demon Hunter'];
  if (isset($names[$value])) return $names[$value];
  $s = js_lower(($value === null || $value === '') ? 'Unknown class' : js_string($value));
  return $s === '' ? $s : js_upper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
}

function unpack_talent_records(array $packed): array {
  $n = count($packed['fields']);
  $out = [];
  foreach ($packed['rows'] as $row) {
    $r = [];
    foreach ($packed['fields'] as $i => $f) $r[$f] = $row[$i] < 0 ? null : $packed['values'][$f][$row[$i]];
    $r['level'] = $row[$n] < 0 ? null : $row[$n];
    $r['hybrid'] = $row[$n + 1] === 1;
    $r['talents'] = array_map(fn($i) => ['node' => $i, 'name' => $packed['names'][$i]], $row[$n + 2]);
    $out[] = $r;
  }
  return $out;
}

function talent_summary(array $packed): string {
  $records = unpack_talent_records($packed);
  $grouped = []; $classTotals = [];
  foreach ($records as $row) {
    if (($row['faction'] ?? '') === '' || ($row['classification'] ?? '') === '' || $row['classification'] === 'Unknown build' || $row['classification'] === 'Unresolved') continue;
    $key = implode("\t", [$row['faction'], $row['classFile'], $row['classification']]);
    $grouped[$key] ??= ['count' => 0, 'hybrid' => false];
    $grouped[$key]['count']++;
    $grouped[$key]['hybrid'] = $grouped[$key]['hybrid'] || $row['hybrid'];
    $classKey = $row['faction'] . "\t" . $row['classFile'];
    $classTotals[$classKey] = ($classTotals[$classKey] ?? 0) + 1;
  }
  $lines = ['## Observed talent builds', '',
    'Shares are within the classified inspect sample for each faction and class, not the full character population.',
    '', '| Faction | Class | Build | Players | Share |', '| --- | --- | --- | ---: | ---: |'];
  $entries = [];
  foreach ($grouped as $k => $v) $entries[] = [(string)$k, $v];
  foreach (js_sort($entries, fn($a, $b) => js_or($b[1]['count'] - $a[1]['count'], fn() => js_cmp($a[0], $b[0]))) as [$key, $entry]) {
    [$faction, $classFile, $build] = explode("\t", $key);
    $share = js_fixed(100 * $entry['count'] / $classTotals[$faction . "\t" . $classFile], 1);
    $lines[] = '| ' . md_cell($faction) . ' | ' . md_cell(md_class_name($classFile)) . ' | ' . md_cell($build) . ($entry['hybrid'] ? ' Hybrid' : '') . ' | ' . $entry['count'] . ' | ' . $share . '% |';
  }
  $denominators = []; $talents = [];
  foreach ($records as $row) {
    if (!$row['talents']) continue;
    $denominators[$row['classFile']] = ($denominators[$row['classFile']] ?? 0) + 1;
    foreach ($row['talents'] as $talent) {
      $key = $row['classFile'] . "\t" . $talent['node'];
      $talents[$key] ??= ['classFile' => $row['classFile'], 'name' => $talent['name'], 'count' => 0];
      $talents[$key]['count']++;
    }
  }
  if ($talents) {
    array_push($lines, '', '## Selected talent popularity', '',
      'Shares use inspected players with readable talent selections in each class.', '',
      '| Class | Talent | Players selecting | Share |', '| --- | --- | ---: | ---: |');
    $list = js_sort(array_values($talents), fn($a, $b) => js_or($b['count'] / $denominators[$b['classFile']] - $a['count'] / $denominators[$a['classFile']],
      fn() => js_or($b['count'] - $a['count'], fn() => js_cmp(js_string($a['name']), js_string($b['name'])))));
    foreach ($list as $entry) {
      $d = $denominators[$entry['classFile']];
      $lines[] = '| ' . md_cell(md_class_name($entry['classFile'])) . ' | ' . md_cell($entry['name']) . ' | ' . $entry['count'] . ' / ' . $d . ' | ' . js_fixed(100 * $entry['count'] / $d, 1) . '% |';
    }
  }
  return implode("\n", $lines);
}

// The Markdown companion of one rendered page. $talentPacked is the talents
// page's filter data, which the summary tables are built from.
function html_to_markdown(string $html, ?array $talentPacked = null): string {
  $doc = html_parse($html);
  $by = fn(string $tag) => fn(HNode $e) => $e->name === strtoupper($tag);
  $cls = fn(string $c) => fn(HNode $e) => $e->hasClass($c);
  $titleEl = $doc->findFirst($by('title'));
  $title = $titleEl ? td_trim($titleEl->text()) : '';
  if ($title === '') $title = 'WoWCensus';
  $meta = $doc->findFirst(fn($e) => $e->name === 'META' && $e->attr('name') === 'description');
  $description = $meta ? ($meta->attr('content') ?? '') : '';
  $link = $doc->findFirst(fn($e) => $e->name === 'LINK' && $e->attr('rel') === 'canonical');
  $canonical = $link ? ($link->attr('href') ?? '') : '';
  $hasTalents = (bool)$doc->findFirst(fn($e) => $e->attr('id') === 'talent-content' && $e->attr('data-src') !== null);

  $removed = $doc->find(fn(HNode $e) => in_array($e->name, ['SCRIPT', 'STYLE', 'NAV', 'HEADER', 'FOOTER', 'BUTTON', 'SELECT', 'IMG'], true)
    || $e->hasClass('crt') || $e->hasClass('site-notice') || $e->hasClass('page-controls') || $e->hasClass('talent-filters') || $e->hasClass('gear-filters')
    || ($e->name === 'A' && (function () use ($e) { for ($p = $e->parent; $p; $p = $p->parent) if ($p->hasClass('ptitle')) return true; return false; })()));
  foreach ($removed as $el) $el->remove();
  foreach ($doc->find($cls('ptitle')) as $el) {
    $heading = new HNode(1, 'H2');
    $heading->setText($el->text());
    $el->replaceWith($heading);
  }
  foreach ($doc->find($cls('tile')) as $el) {
    $part = fn(string $c) => ($n = $el->findFirst(fn($e) => $e->hasClass($c))) ? td_trim($n->text()) : null;
    $label = $part('k'); $value = $part('v'); $detail = $part('s');
    // JavaScript truthiness: "0" is a value.
    if (($label ?? '') !== '' && ($value ?? '') !== '') $el->setText($label . ': ' . $value . (($detail ?? '') !== '' ? ' (' . $detail . ')' : ''));
  }
  foreach ($doc->find($cls('gear-top-grid')) as $grid) {
    $table = new HNode(1, 'TABLE');
    $thead = new HNode(1, 'THEAD'); $headRow = new HNode(1, 'TR');
    foreach (['Slot', 'Most equipped item', 'Players'] as $h) { $th = new HNode(1, 'TH'); $th->setText($h); $headRow->append($th); }
    $thead->append($headRow); $table->append($thead);
    $tbody = new HNode(1, 'TBODY'); $table->append($tbody);
    foreach ($grid->find($cls('gear-top-item')) as $item) {
      $row = new HNode(1, 'TR');
      $cells = [];
      foreach (['gear-top-slot', 'gear-top-name', 'gear-top-count'] as $c) {
        $td = new HNode(1, 'TD');
        $found = $item->findFirst(fn($e) => $e->hasClass($c));
        $td->setText($found ? $found->text() : '');
        $row->append($td);
        $cells[] = $td;
      }
      $a = new HNode(1, 'A');
      $a->attrs['href'] = $item->attr('href') ?? '';
      $a->setText($cells[1]->text());
      $cells[1]->clear();
      $cells[1]->append($a);
      $tbody->append($row);
    }
    $grid->replaceWith($table);
  }
  $body = $doc->findFirst($cls('wrap')) ?? $doc->findFirst($by('body'));
  // turndown converts a reparse of the body's HTML: adjacent text nodes merge.
  $root = new HNode(1, 'X-TURNDOWN');
  foreach ($body->children() as $c) $root->append($c);
  md_merge_text($root);
  $content = td_trim(turndown($root));
  $front = ['---', 'title: ' . js_json_string($title)];
  if ($description !== '') $front[] = 'description: ' . js_json_string($description);
  if ($canonical !== '') $front[] = 'url: ' . js_json_string($canonical);
  array_push($front, '---', '', '# ' . $title, '');
  $markdown = implode("\n", $front) . $content . "\n";
  if ($hasTalents && $talentPacked !== null) $markdown .= "\n" . talent_summary($talentPacked) . "\n";
  return $markdown;
}

function md_merge_text(HNode $node): void {
  $c = $node->first;
  while ($c) {
    if ($c->type === 3) {
      while ($c->next && $c->next->type === 3) { $c->data .= $c->next->data; $c->next->remove(); }
      if ($c->data === '') { $n = $c->next; $c->remove(); $c = $n; continue; }
    } elseif ($c->type === 1) md_merge_text($c);
    $c = $c->next;
  }
}

function page_markdown(string $source, string $page): string {
  $packed = $page === 'talents' ? talent_page_model($source) : null;
  return html_to_markdown(edition_page_html($source, $page), $packed);
}

function profile_markdown(string $html, string $route): string {
  return html_to_markdown($html);
}
