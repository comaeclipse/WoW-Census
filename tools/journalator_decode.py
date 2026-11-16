#!/usr/bin/env python3
"""Decode a Journalator.lua SavedVariables file into plain JSON, reading it as data only.

    python -B tools/journalator_decode.py <Journalator.lua> [--out logs.json] [--summary]

Journalator keeps its logs in JOURNALATOR_ARCHIVE.stores.SometimesLocked["Logs-<t>"].data:
LibSerialize output, raw-DEFLATE compressed (LibDeflate:CompressDeflate), then
LibDeflate:EncodeForPrint. This reverses those three steps in Python; no addon code runs.
"""

import argparse
import json
import re
import struct
import sys
import zlib
from pathlib import Path

ALPHABET = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789()"
SIX = {c: i for i, c in enumerate(ALPHABET)}


def decode_for_print(s):
    s = s.strip()
    out = bytearray()
    i, n = 0, len(s)
    while i <= n - 4:
        x = SIX[s[i]] + SIX[s[i + 1]] * 64 + SIX[s[i + 2]] * 4096 + SIX[s[i + 3]] * 262144
        out += bytes((x & 255, (x >> 8) & 255, (x >> 16) & 255))
        i += 4
    cache, bits = 0, 0
    while i < n:
        cache += SIX[s[i]] << bits
        bits += 6
        i += 1
    while bits >= 8:
        out.append(cache & 255)
        cache >>= 8
        bits -= 8
    return bytes(out)


class LibSerializeReader:
    def __init__(self, data):
        self.d, self.p, self.strs, self.tabs = data, 0, [], []

    def take(self, n):
        b = self.d[self.p:self.p + n]
        if len(b) < n:
            raise ValueError("read past end")
        self.p += n
        return b

    def int(self, n):
        return int.from_bytes(self.take(n), "big")

    def string(self, n):
        b = self.take(n)
        v = b.decode("utf-8", "replace")
        if n > 2:
            self.strs.append(v)
        return v

    def table(self, count, value=None):
        if value is None:
            value = {}
            self.tabs.append(value)
        for _ in range(count):
            k = self.obj()
            value[k] = self.obj()
        return value

    def array(self, count, value=None):
        if value is None:
            value = {}
            self.tabs.append(value)
        start = sum(1 for k in value if isinstance(k, int))
        for i in range(1, count + 1):
            value[start + i] = self.obj()
        return value

    def mixed(self, acount, mcount):
        value = {}
        self.tabs.append(value)
        self.array(acount, value)
        self.table(mcount, value)
        return value

    def obj(self):
        v = self.int(1)
        if v % 2 == 1:
            return (v - 1) // 2
        if v % 4 == 2:
            typ = (v - 2) // 4
            count, typ = typ // 4, typ % 4
            return [lambda c: self.string(c), lambda c: self.table(c), lambda c: self.array(c),
                    lambda c: self.mixed(c % 4 + 1, c // 4 + 1)][typ](count)
        if v % 8 == 4:
            packed = self.int(1) * 256 + v
            return -(packed - 12) // 16 if v % 16 == 12 else (packed - 4) // 16
        t = v // 8
        if t == 0: return None
        if t in (1, 2): return self.int(2) * (1 if t == 1 else -1)
        if t in (3, 4): return self.int(3) * (1 if t == 3 else -1)
        if t in (5, 6): return self.int(4) * (1 if t == 5 else -1)
        if t in (7, 8): return self.int(7) * (1 if t == 7 else -1)
        if t == 9: return struct.unpack(">d", self.take(8))[0]
        if t in (10, 11): return float(self.take(self.int(1)).decode()) * (1 if t == 10 else -1)
        if t == 12: return True
        if t == 13: return False
        if t in (14, 15, 16): return self.string(self.int(t - 13))
        if t in (17, 18, 19): return self.table(self.int(t - 16))
        if t in (20, 21, 22): return self.array(self.int(t - 19))
        if t in (23, 24, 25):
            w = t - 22
            a = self.int(w)
            return self.mixed(a, self.int(w))
        if t in (26, 27, 28): return self.strs[self.int(t - 25) - 1]
        if t in (29, 30, 31): return self.tabs[self.int(t - 28) - 1]
        raise ValueError(f"unknown LibSerialize type {t}")

    def all(self):
        version = self.int(1)
        if version > 1:
            raise ValueError(f"unknown LibSerialize version {version}")
        out = []
        while self.p < len(self.d):
            out.append(self.obj())
        return out


def listify(v):
    """Lua tables whose keys are exactly 1..n become JSON lists."""
    if isinstance(v, dict):
        v = {k: listify(x) for k, x in v.items()}
        if v and all(isinstance(k, int) for k in v) and sorted(v) == list(range(1, len(v) + 1)):
            return [v[i] for i in range(1, len(v) + 1)]
        return {str(k): x for k, x in v.items()}
    return v


def decode_blob(text):
    raw = zlib.decompress(decode_for_print(text), -15)
    values = LibSerializeReader(raw).all()
    return listify(values[0] if len(values) == 1 else values)


def read_archive(path):
    """{store key: decoded logs} for every Logs-* entry in the file."""
    text = Path(path).read_text(encoding="utf-8", errors="replace")
    out = {}
    for key, ts, blob in re.findall(r'\["(Logs-\d+)"\] = \{\s*\["timestamp"\] = (\d+),\s*\["version"\] = \d+,\s*\["data"\] = "([^"]*)"', text):
        out[key] = {"timestamp": int(ts), "logs": decode_blob(blob)}
    return out


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("file")
    ap.add_argument("--out")
    ap.add_argument("--summary", action="store_true")
    args = ap.parse_args()
    stores = read_archive(args.file)
    if args.out:
        Path(args.out).write_text(json.dumps(stores, indent=1, ensure_ascii=False), encoding="utf-8")
    if args.summary or not args.out:
        for key, s in stores.items():
            logs = s["logs"]
            print(key, "saved", s["timestamp"])
            if isinstance(logs, dict):
                for section, rows in logs.items():
                    n = len(rows) if isinstance(rows, (list, dict)) else rows
                    print(f"  {section:28} {n}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
