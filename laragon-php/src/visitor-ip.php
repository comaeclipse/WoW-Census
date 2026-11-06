<?php
// The visitor's address, for per-visitor limits (shoutbox cooldowns, one poll vote each).
declare(strict_types=1);

function shout_ip_in_cidr(string $ip, string $cidr): bool {
  [$network, $bits] = explode('/', $cidr);
  $address = inet_pton($ip);
  $base = inet_pton($network);
  if ($address === false || $base === false || strlen($address) !== strlen($base)) return false;
  $bits = (int)$bits;
  $whole = intdiv($bits, 8);
  $remainder = $bits % 8;
  return substr($address, 0, $whole) === substr($base, 0, $whole)
    && ($remainder === 0 || (ord($address[$whole]) & (0xff << (8 - $remainder))) === (ord($base[$whole]) & (0xff << (8 - $remainder))));
}

function shout_visitor_ip(): string {
  $remote = $_SERVER['REMOTE_ADDR'] ?? '';
  $cloudflare = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
  // Trust the visitor header only when the TCP peer is Cloudflare.
  $ranges = [
    '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
    '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
    '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
    '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
    '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
    '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
  ];
  if (filter_var($cloudflare, FILTER_VALIDATE_IP)) {
    foreach ($ranges as $range) if (shout_ip_in_cidr($remote, $range)) return $cloudflare;
  }
  return $remote;
}
