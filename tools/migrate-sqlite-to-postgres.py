#!/usr/bin/env python3
"""Copy a frozen MarketLens SQLite snapshot into a new PostgreSQL schema.

Usage: python tools/migrate-sqlite-to-postgres.py SQLITE_PATH --dsn "..."
The target database must already exist. Existing tables in schema marketlens_import
are replaced; other schemas are untouched.
"""
import argparse
import csv
import hashlib
import io
import json
import sqlite3

import psycopg2
from psycopg2 import sql


def ident(name):
    return sql.Identifier(name)


def canonical(row):
    return json.dumps([v.tobytes().hex() if isinstance(v, memoryview) else
                       v.hex() if isinstance(v, bytes) else v for v in row],
                      ensure_ascii=True, separators=(',', ':'), default=str).encode()


def digest(rows):
    h = hashlib.sha256()
    for row in rows:
        h.update(canonical(row))
        h.update(b'\n')
    return h.hexdigest()


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('source')
    parser.add_argument('--dsn', required=True)
    parser.add_argument('--schema', default='marketlens_import')
    args = parser.parse_args()
    source = sqlite3.connect('file:' + args.source.replace('\\', '/') + '?mode=ro', uri=True)
    source.execute('PRAGMA query_only=ON')
    if source.execute('PRAGMA integrity_check').fetchone()[0] != 'ok':
        raise RuntimeError('SQLite integrity_check failed')
    if source.execute('PRAGMA foreign_key_check').fetchone() is not None:
        raise RuntimeError('SQLite foreign_key_check failed')
    tables = [r[0] for r in source.execute(
        "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")]
    target = psycopg2.connect(args.dsn)
    target.autocommit = False
    with target.cursor() as cur:
        cur.execute(sql.SQL('CREATE SCHEMA IF NOT EXISTS {}').format(ident(args.schema)))
        cur.execute(sql.SQL('SET search_path TO {}').format(ident(args.schema)))
        for name in tables:
            columns = source.execute(f'PRAGMA table_info("{name}")').fetchall()
            pk = [c[1] for c in sorted(columns, key=lambda c: c[5]) if c[5]]
            defs = []
            for _, col, kind, required, default, _ in columns:
                affinity = kind.upper()
                pg_type = ('BIGINT' if 'INT' in affinity else
                           'DOUBLE PRECISION' if any(t in affinity for t in ('REAL', 'FLOA', 'DOUB')) else
                           'BYTEA' if 'BLOB' in affinity else 'TEXT')
                defs.append(sql.SQL('{} {}{}').format(
                    ident(col), sql.SQL(pg_type), sql.SQL(' NOT NULL' if required else '')))
                if default is not None:
                    if default != '0':
                        raise RuntimeError(f'Unsupported default in {name}.{col}: {default}')
                    defs[-1] += sql.SQL(' DEFAULT 0')
            if pk:
                defs.append(sql.SQL('PRIMARY KEY ({})').format(
                    sql.SQL(', ').join(map(ident, pk))))
            cur.execute(sql.SQL('DROP TABLE IF EXISTS {} CASCADE').format(ident(name)))
            cur.execute(sql.SQL('CREATE TABLE {} ({})').format(
                ident(name), sql.SQL(', ').join(defs)))
            source_cur = source.execute(sql.SQL('SELECT * FROM {}').format(ident(name)).as_string(target))
            names = [d[0] for d in source_cur.description]
            copied = 0
            while True:
                batch = source_cur.fetchmany(10000)
                if not batch:
                    break
                output = io.StringIO()
                writer = csv.writer(output, lineterminator='\n')
                for row in batch:
                    if '\\N' in row:
                        raise RuntimeError(f'{name}: NULL marker collision')
                    writer.writerow(['\\N' if v is None else
                                     ('\\x' + v.hex() if isinstance(v, bytes) else v)
                                     for v in row])
                output.seek(0)
                statement = sql.SQL('COPY {} ({}) FROM STDIN WITH (FORMAT csv, NULL {})').format(
                    ident(name), sql.SQL(', ').join(map(ident, names)), sql.Literal('\\N'))
                cur.copy_expert(statement.as_string(target), output)
                copied += len(batch)
            print(f'{name}: {copied} rows', flush=True)
        target.commit()
        cur.execute(sql.SQL('SET search_path TO {}').format(ident(args.schema)))
        indexes = source.execute(
            "SELECT sql FROM sqlite_master WHERE type='index' AND sql IS NOT NULL ORDER BY name")
        for (statement,) in indexes:
            cur.execute(statement)
        cur.execute("ALTER TABLE population_sweeps ADD CONSTRAINT population_sweeps_status_check CHECK (status IN ('active', 'complete', 'partial'))")
        cur.execute("ALTER TABLE inspect_talents ADD CONSTRAINT inspect_talents_inspect_fk FOREIGN KEY (source_game, guid, captured_at) REFERENCES character_inspects (source_game, guid, captured_at)")
        target.commit()
        # Fresh tables have no planner statistics; without them the PHP site's
        # census queries pick nested-loop plans that take tens of seconds.
        cur.execute('ANALYZE')
        target.commit()
        for name in tables:
            src_count = source.execute(f'SELECT count(*) FROM "{name}"').fetchone()[0]
            cur.execute(sql.SQL('SELECT count(*) FROM {}').format(ident(name)))
            dst_count = cur.fetchone()[0]
            if src_count != dst_count:
                raise RuntimeError(f'{name}: count mismatch {src_count} != {dst_count}')
            columns = source.execute(f'PRAGMA table_info("{name}")').fetchall()
            order = [c[1] for c in columns]
            types = {c[1]: c[2].upper() for c in columns}
            ordered = [sql.SQL('{}{}').format(ident(col),
                       sql.SQL(' COLLATE "C" NULLS FIRST' if types[col] == 'TEXT'
                               else ' NULLS FIRST'))
                       for col in order]
            source_order = ', '.join('"' + col.replace('"', '""') + '"' for col in order)
            source_rows = source.execute(f'SELECT * FROM "{name}" ORDER BY {source_order}')
            with target.cursor(name='verify_' + name) as stream:
                stream.itersize = 10000
                stream.execute(sql.SQL('SELECT * FROM {} ORDER BY {}').format(
                    ident(name), sql.SQL(', ').join(ordered)))
                target_hash = digest(stream)
            if digest(source_rows) != target_hash:
                raise RuntimeError(f'{name}: full row digest mismatch')
            print(f'{name}: count and full row digest verified', flush=True)
        print(f'All {len(tables)} table row counts match SQLite.', flush=True)
        # Pages break ties between equal item quantities by insertion order (SQLite's
        # rowid). Number the rows in load order, which is rowid order; later inserts
        # continue the sequence and updates keep their number.
        cur.execute('ALTER TABLE items ADD COLUMN seq BIGSERIAL')
        cur.execute('CREATE INDEX idx_items_game_q_seq ON items (game, q DESC, seq)')
        target.commit()
    target.close()
    source.close()


if __name__ == '__main__':
    main()
