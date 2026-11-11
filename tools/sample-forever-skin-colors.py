"""Replace the local Forever skin sample with a larger, distinct API sample.

Uses the existing published choice-to-swatch mapping. Never writes a partial
sample to the site: unmapped choices leave the output unchanged.
"""
import base64
import csv
import io
import json
import os
import random
import time
import urllib.error
import urllib.parse
import urllib.request
from concurrent.futures import ThreadPoolExecutor
from collections import Counter, defaultdict
from datetime import datetime, timezone
from pathlib import Path

import psycopg2

ROOT = Path(__file__).resolve().parents[1]
OUTPUT = ROOT / 'laragon-php/src/forever-skin-data.json'
CHECKPOINT = Path.home() / 'Documents/MarketLensData/forever-skin-sample-checkpoint.json'
TARGET = 2000


def save_checkpoint(state):
    temporary = CHECKPOINT.with_suffix('.json.tmp')
    temporary.write_text(json.dumps(state, ensure_ascii=False), encoding='utf-8')
    temporary.replace(CHECKPOINT)


def request_json(url, headers=None, data=None):
    request = urllib.request.Request(url, headers=headers or {}, data=data)
    with urllib.request.urlopen(request, timeout=25) as response:
        return json.load(response)


def token():
    value = f"{os.environ['BLIZZARD_CLIENT_ID']}:{os.environ['BLIZZARD_CLIENT_SECRET']}"
    basic = base64.b64encode(value.encode()).decode()
    return request_json('https://us.battle.net/oauth/token',
                        {'Authorization': 'Basic ' + basic}, b'grant_type=client_credentials')['access_token']


def main():
    old = json.loads(OUTPUT.read_text(encoding='utf-8'))
    swatches = {}
    for race, entry in old['races'].items():
        for row in entry['rows']:
            key = (row['option_id'], row['choice_id'])
            if row['swatch_hex']:
                if key in swatches and swatches[key] != row['swatch_hex']:
                    raise ValueError(f'conflicting swatch for {key}')
                swatches[key] = row['swatch_hex']
    races = set(old['races'])
    if CHECKPOINT.exists():
        state = json.loads(CHECKPOINT.read_text(encoding='utf-8'))
    else:
        conn = psycopg2.connect(os.getenv('MARKETLENS_PG_DSN',
            'host=127.0.0.1 port=5433 dbname=marketlens_local user=skarz'))
        with conn, conn.cursor() as cur:
            cur.execute("""SELECT name, race FROM marketlens_import.characters
                WHERE source_game='forever' AND realm LIKE 'CAMWOW US%%'
                  AND race = ANY(%s) AND name <> ''
                ORDER BY random() LIMIT 10000""", (list(races),))
            pool = cur.fetchall()
        conn.close()
        random.shuffle(pool)
        seen = set()
        candidates = []
        for name, race in pool:
            slug = '-'.join(name.lower().split())
            if slug not in seen:
                seen.add(slug)
                candidates.append([name, race, slug])
        state = {'candidates': candidates, 'results': [], 'next': 0,
                 'started_at_utc': datetime.now(timezone.utc).isoformat()}
    bearer = token()
    status = Counter(row['status'] for row in state['results'])
    def fetch(candidate):
        name, race, slug = candidate
        url = ('https://us.api.blizzard.com/profile/wow/character/'
               + urllib.parse.quote(slug, safe='-')
               + '/appearance?namespace=profile-forever-us&locale=en_US')
        result = {'name': name, 'race': race, 'slug': slug}
        for attempt in range(4):
            try:
                body = request_json(url, {'Authorization': 'Bearer ' + bearer})
                result['status'] = '200'
                result['choices'] = [
                    {'option_id': item['option']['id'], 'choice_id': item['choice']['id']}
                    for item in body.get('customizations', [])
                    if 'skin color' in item.get('option', {}).get('name', '').lower()
                ]
                break
            except urllib.error.HTTPError as error:
                if error.code in (429, 500, 502, 503, 504) and attempt < 3:
                    time.sleep(min(2 ** attempt * 2, 12))
                    continue
                result['status'] = str(error.code)
                break
            except (urllib.error.URLError, TimeoutError):
                if attempt < 3:
                    time.sleep(2 ** attempt)
                    continue
                result['status'] = 'network_error'
        return result

    with ThreadPoolExecutor(max_workers=6) as pool:
      for i in range(state['next'], len(state['candidates']), 25):
        if status['200'] >= TARGET:
            break
        batch = state['candidates'][i:i + 25]
        for result in pool.map(fetch, batch):
            state['results'].append(result)
            status[result['status']] += 1
        state['next'] = i + len(batch)
        save_checkpoint(state)
        print(f"Queried {state['next']}; successful {status['200']}", flush=True)
    save_checkpoint(state)
    if status['200'] < TARGET:
        raise RuntimeError(f"Only {status['200']} successful appearances; checkpoint retained")
    missing = Counter((c['option_id'], c['choice_id'])
                      for row in state['results'] if row['status'] == '200'
                      for c in row['choices'] if (c['option_id'], c['choice_id']) not in swatches)
    if missing:
        url = ('https://wago.tools/db2/ChrCustomizationChoice/csv?build='
               + urllib.parse.quote(old['swatch_build']))
        request = urllib.request.Request(url, headers={'User-Agent': 'Mozilla/5.0'})
        with urllib.request.urlopen(request, timeout=40) as response:
            catalog = csv.DictReader(io.TextIOWrapper(response, encoding='utf-8-sig'))
            for item in catalog:
                key = (int(item['ChrCustomizationOptionID']), int(item['ID']))
                if key in missing and item['SwatchColor_0']:
                    swatches[key] = f"#{int(item['SwatchColor_0']) & 0xffffff:06X}"
        if set(missing) - set(swatches):
            raise RuntimeError(f'Unmapped choice IDs: {sorted(set(missing) - set(swatches))}')
        print(f'Mapped {len(missing)} new choice IDs from build {old["swatch_build"]}')
    grouped = defaultdict(lambda: {'queried': 0, 'rows': []})
    for row in state['results']:
        grouped[row['race']]['queried'] += 1
        if row['status'] == '200':
            for choice in row['choices']:
                key = (choice['option_id'], choice['choice_id'])
                grouped[row['race']]['rows'].append(choice | {'swatch_hex': swatches[key]})
    report = {'sampled_at_utc': datetime.now(timezone.utc).isoformat(),
              'requested': len(state['results']), 'statuses': dict(status),
              'swatch_build': old['swatch_build'], 'races': dict(grouped)}
    temp = OUTPUT.with_suffix('.json.tmp')
    temp.write_text(json.dumps(report, indent=2, ensure_ascii=False) + '\n', encoding='utf-8')
    temp.replace(OUTPUT)
    print(f"Published local sample: {status['200']} successful from {len(state['results'])} requests")


if __name__ == '__main__':
    main()
