<?php
// JavaScript-compatible primitives. The pages must match the Node generator
// byte for byte, so number formatting, JSON, rounding, sorting and string
// comparison follow the ECMAScript rules rather than PHP's own.

declare(strict_types=1);

// Number.prototype.toString / String(number).
function js_str(int|float $x): string {
  if (is_int($x)) return (string)$x;
  if (is_nan($x)) return 'NaN';
  if (is_infinite($x)) return $x > 0 ? 'Infinity' : '-Infinity';
  if ($x == 0.0) return '0';
  $sign = $x < 0 ? '-' : '';
  // var_export prints the shortest round-trip digits (serialize_precision=-1).
  $repr = var_export(abs($x), true);
  if (!preg_match('/^(\d+)(?:\.(\d+))?(?:E([+-]\d+))?$/i', $repr, $m)) throw new RuntimeException("number $repr");
  $int = $m[1]; $frac = $m[2] ?? ''; $exp = (int)($m[3] ?? 0);
  $digits = ltrim($int . $frac, '0');
  $lead = strlen($int . $frac) - strlen($digits);
  // value = 0.<digits> x 10^n
  $n = strlen($int) + $exp - $lead;
  $digits = rtrim($digits, '0');
  $k = strlen($digits);
  if ($k <= $n && $n <= 21) return $sign . $digits . str_repeat('0', $n - $k);
  if (0 < $n && $n <= 21) return $sign . substr($digits, 0, $n) . '.' . substr($digits, $n);
  if (-6 < $n && $n <= 0) return $sign . '0.' . str_repeat('0', -$n) . $digits;
  $e = $n - 1;
  $mant = $k === 1 ? $digits : $digits[0] . '.' . substr($digits, 1);
  return $sign . $mant . 'e' . ($e >= 0 ? '+' : '-') . abs($e);
}

// Number.prototype.toFixed: the exact binary value, ties away from zero.
function js_fixed(int|float $x, int $f): string {
  $x = (float)$x;
  if (is_nan($x)) return 'NaN';
  if (abs($x) >= 1e21) return js_str($x);
  $s = sprintf('%.' . $f . 'f', $x);
  // sprintf rounds exact ties to even; detect a tie from the IEEE bits.
  $bits = unpack('J', pack('E', $x))[1];
  $expBits = ($bits >> 52) & 0x7FF;
  $mant = $bits & 0xFFFFFFFFFFFFF;
  if ($expBits === 0) { $e = -1074; } else { $mant |= 1 << 52; $e = $expBits - 1075; }
  if ($mant === 0) return $s;
  $tz = 0; while (($mant & 1) === 0) { $mant >>= 1; $tz++; }
  if ($tz + $e + 1 + $f !== 0) return $s;
  // Exactly halfway: round the magnitude up.
  $wide = sprintf('%.' . ($f + 1) . 'f', abs($x));
  $digits = str_replace('.', '', substr($wide, 0, -1));
  $i = strlen($digits) - 1;
  while ($i >= 0) {
    if ($digits[$i] === '9') { $digits[$i] = '0'; $i--; continue; }
    $digits[$i] = chr(ord($digits[$i]) + 1); break;
  }
  if ($i < 0) $digits = '1' . $digits;
  $out = $f ? substr($digits, 0, -$f) . '.' . substr($digits, -$f) : $digits;
  if ($f && str_starts_with($out, '.')) $out = '0' . $out;
  return ($x < 0 ? '-' : '') . $out;
}

// Math.round.
function js_round(int|float $x): int|float {
  if (is_int($x)) return $x;
  if (is_nan($x) || is_infinite($x)) return $x;
  $r = floor($x);
  if ($x - $r >= 0.5) $r += 1;
  // Math.round(-0.3) is -0, which toLocaleString prints as "-0".
  if ($r == 0 && ($x < 0 || ($x == 0 && fdiv(1, $x) < 0))) return -0.0;
  return abs($r) < 9.0e15 ? (int)$r : $r;
}

// Unary plus on a toFixed string: back to a number (int when integral).
function js_num(string $s): int|float {
  $f = (float)$s;
  return (floor($f) == $f && abs($f) < 9.0e15) ? (int)$f : $f;
}

// Number.prototype.toLocaleString() in en-US.
function js_locale(int|float $x): string {
  static $fmt = null;
  if (!$fmt) {
    $fmt = new NumberFormatter('en-US', NumberFormatter::DECIMAL);
    // ECMA-402 rounds half away from zero ("halfExpand").
    $fmt->setAttribute(NumberFormatter::ROUNDING_MODE, NumberFormatter::ROUND_HALFUP);
  }
  if (is_int($x)) return number_format($x);
  return $fmt->format($x);
}

// String.prototype.localeCompare in en-US.
function js_cmp(string $a, string $b): int {
  static $collator = null;
  $collator ??= new Collator('en-US');
  return $collator->compare($a, $b);
}

// Stable sort with a JavaScript comparator (any number; NaN counts as 0).
function js_sort(array $list, callable $cmp): array {
  usort($list, function ($a, $b) use ($cmp) {
    $r = $cmp($a, $b);
    if (is_float($r) && is_nan($r)) return 0;
    return $r <=> 0;
  });
  return $list;
}

// JavaScript `a || b` on comparator results: 0 and NaN are falsy.
function js_or(int|float $a, callable $b): int|float {
  return ($a == 0 || (is_float($a) && is_nan($a))) ? $b() : $a;
}

function js_iso(int|float $seconds): string {
  $ms = (int)round($seconds * 1000);
  $s = intdiv($ms, 1000); $rem = $ms % 1000;
  if ($rem < 0) { $rem += 1000; $s--; }
  return gmdate('Y-m-d\TH:i:s', $s) . '.' . sprintf('%03d', $rem) . 'Z';
}

function js_upper(string $s): string { return mb_strtoupper($s, 'UTF-8'); }
function js_lower(string $s): string { return mb_strtolower($s, 'UTF-8'); }

// JSON.stringify. PHP lists are arrays; other arrays and stdClass objects are
// objects, with integer-like keys first in ascending order as in JavaScript.
function js_json(mixed $v, string $indent = '', string $pad = ''): string {
  if ($v === null) return 'null';
  if ($v === true) return 'true';
  if ($v === false) return 'false';
  if (is_int($v)) return (string)$v;
  if (is_float($v)) return (is_nan($v) || is_infinite($v)) ? 'null' : js_str($v);
  if (is_string($v)) return js_json_string($v);
  $inner = $pad . $indent;
  $sep = $indent === '' ? ',' : ",\n" . $inner;
  $open = $indent === '' ? '' : "\n" . $inner;
  $close = $indent === '' ? '' : "\n" . $pad;
  $colon = $indent === '' ? ':' : ': ';
  if (is_array($v) && array_is_list($v)) {
    if (!$v) return '[]';
    $parts = [];
    foreach ($v as $item) $parts[] = js_json($item, $indent, $inner);
    return '[' . $open . implode($sep, $parts) . $close . ']';
  }
  $entries = is_object($v) ? get_object_vars($v) : $v;
  if (!$entries) return '{}';
  $ints = []; $rest = [];
  foreach ($entries as $k => $item) {
    $key = (string)$k;
    if (preg_match('/^(0|[1-9]\d*)$/', $key) && (float)$key < 4294967295) $ints[$key] = $item;
    else $rest[$key] = $item;
  }
  uksort($ints, fn($a, $b) => (int)$a <=> (int)$b);
  $parts = [];
  foreach ([$ints, $rest] as $group)
    foreach ($group as $k => $item) $parts[] = js_json_string((string)$k) . $colon . js_json($item, $indent, $inner);
  return '{' . $open . implode($sep, $parts) . $close . '}';
}

function js_json_string(string $s): string {
  return json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_INVALID_UTF8_SUBSTITUTE);
}

// Object.keys order for a PHP array standing in for a JavaScript object.
function js_keys(array $o): array {
  $ints = []; $rest = [];
  foreach (array_keys($o) as $k) {
    $key = (string)$k;
    if (preg_match('/^(0|[1-9]\d*)$/', $key) && (float)$key < 4294967295) $ints[] = $key;
    else $rest[] = $key;
  }
  usort($ints, fn($a, $b) => (int)$a <=> (int)$b);
  return array_merge($ints, $rest);
}

// Object.entries in JavaScript key order.
function js_entries(array $o): array {
  $out = [];
  foreach (js_keys($o) as $k) $out[] = [$k, $o[$k]];
  return $out;
}
