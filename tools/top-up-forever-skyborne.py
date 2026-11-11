"""Add 250 successful appearance profiles per Skyborne race to the Forever sample."""
import csv
import base64
import io
import json
import os
import random
import time
import urllib.error
import urllib.parse
import urllib.request
from collections import Counter
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timezone
from pathlib import Path

import psycopg2

ROOT = Path(__file__).resolve().parents[1]
OUTPUT = ROOT / 'laragon-php/src/forever-skin-data.json'
DATA_DIR = Path.home() / 'Documents/MarketLensData'
PRIOR = DATA_DIR / 'forever-skin-sample-checkpoint.json'
CHECKPOINT = DATA_DIR / 'forever-skyborne-topup-checkpoint.json'
RACES = ('High Order Skyborne', 'Windshaper Skyborne')
TARGET = 250


def request_json(url, headers=None, data=None):
    request = urllib.request.Request(url, headers=headers or {}, data=data)
    with urllib.request.urlopen(request, timeout=25) as response:
        return json.load(response)


def token():
    value = f"{os.environ['BLIZZARD_CLIENT_ID']}:{os.environ['BLIZZARD_CLIENT_SECRET']}"
    basic = base64.b64encode(value.encode()).decode()
    return request_json('https://us.battle.net/oauth/token',
                        {'Authorization': 'Basic ' + basic}, b'grant_type=client_credentials')['access_token']


def save(state):
    tmp = CHECKPOINT.with_suffix('.json.tmp')
    tmp.write_text(json.dumps(state, ensure_ascii=False), encoding='utf-8')
    tmp.replace(CHECKPOINT)


def main():
    existing = json.loads(OUTPUT.read_text(encoding='utf-8'))
    prior = json.loads(PRIOR.read_text(encoding='utf-8'))
    excluded = {r['slug'] for r in prior['results']}
    if CHECKPOINT.exists():
        state = json.loads(CHECKPOINT.read_text(encoding='utf-8'))
    else:
        conn = psycopg2.connect(os.getenv('MARKETLENS_PG_DSN',
            'host=127.0.0.1 port=5433 dbname=marketlens_local user=skarz'))
        candidates = {}
        seen = set(excluded)
        with conn, conn.cursor() as cur:
            for race in RACES:
                cur.execute("""SELECT name FROM marketlens_import.characters
                    WHERE source_game='forever' AND realm LIKE 'CAMWOW US%%'
                      AND race=%s AND name<>'' ORDER BY random() LIMIT 4000""", (race,))
                names = cur.fetchall()
                candidates[race] = []
                for (name,) in names:
                    slug = '-'.join(name.lower().split())
                    if slug not in seen:
                        seen.add(slug)
                        candidates[race].append([name, slug])
        conn.close()
        state = {'started_at_utc': datetime.now(timezone.utc).isoformat(),
                 'candidates': candidates, 'results': {race: [] for race in RACES},
                 'next': {race: 0 for race in RACES}}
    bearer = token()

    def fetch(item, race):
        name, slug = item
        url = ('https://us.api.blizzard.com/profile/wow/character/'
               + urllib.parse.quote(slug, safe='-')
               + '/appearance?namespace=profile-forever-us&locale=en_US')
        result = {'name': name, 'slug': slug, 'race': race}
        for attempt in range(4):
            try:
                body = request_json(url, {'Authorization': 'Bearer ' + bearer})
                result['status'] = '200'
                result['choices'] = [
                    {'option_id': row['option']['id'], 'choice_id': row['choice']['id']}
                    for row in body.get('customizations', [])
                    if 'skin color' in row.get('option', {}).get('name', '').lower()]
                break
            except urllib.error.HTTPError as error:
                if error.code in (429, 500, 502, 503, 504) and attempt < 3:
                    time.sleep(2 ** attempt * 2)
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
        for race in RACES:
            results = state['results'][race]
            for i in range(state['next'][race], len(state['candidates'][race]), 25):
                if sum(r['status'] == '200' for r in results) >= TARGET:
                    break
                batch = state['candidates'][race][i:i + 25]
                results.extend(pool.map(lambda item: fetch(item, race), batch))
                state['next'][race] = i + len(batch)
                save(state)
                print(f"{race}: {sum(r['status'] == '200' for r in results)} successful; {len(results)} queried", flush=True)
    if any(sum(r['status'] == '200' for r in state['results'][race]) < TARGET for race in RACES):
        raise RuntimeError('Target not reached; checkpoint retained')

    swatches = {(row['option_id'], row['choice_id']): row['swatch_hex']
                for race in existing['races'].values() for row in race['rows'] if row['swatch_hex']}
    missing = {(c['option_id'], c['choice_id'])
               for race in RACES for result in state['results'][race] if result['status'] == '200'
               for c in result['choices'] if (c['option_id'], c['choice_id']) not in swatches}
    if missing:
        url = ('https://wago.tools/db2/ChrCustomizationChoice/csv?build='
               + urllib.parse.quote(existing['swatch_build']))
        req = urllib.request.Request(url, headers={'User-Agent': 'Mozilla/5.0'})
        with urllib.request.urlopen(req, timeout=40) as response:
            for row in csv.DictReader(io.TextIOWrapper(response, encoding='utf-8-sig')):
                key = (int(row['ChrCustomizationOptionID']), int(row['ID']))
                if key in missing and row['SwatchColor_0']:
                    swatches[key] = f"#{int(row['SwatchColor_0']) & 0xffffff:06X}"
        if missing - swatches.keys():
            raise RuntimeError(f'Unmapped choices: {sorted(missing - swatches.keys())}')

    added = Counter()
    for race in RACES:
        results = state['results'][race]
        existing['races'][race]['queried'] += len(results)
        for result in results:
            added[result['status']] += 1
            if result['status'] == '200':
                for choice in result['choices']:
                    key = (choice['option_id'], choice['choice_id'])
                    existing['races'][race]['rows'].append(choice | {'swatch_hex': swatches[key]})
    existing['requested'] += sum(len(state['results'][race]) for race in RACES)
    for status, count in added.items():
        existing['statuses'][status] = existing['statuses'].get(status, 0) + count
    existing['sampled_at_utc'] = datetime.now(timezone.utc).isoformat()
    existing['skyborne_targeted'] = {'successful_per_race': TARGET,
                                    'requested': sum(len(state['results'][race]) for race in RACES)}
    tmp = OUTPUT.with_suffix('.json.tmp')
    tmp.write_text(json.dumps(existing, indent=2, ensure_ascii=False) + '\n', encoding='utf-8')
    tmp.replace(OUTPUT)
    print(f"Total: {existing['statuses']['200']} successful of {existing['requested']} requests")


if __name__ == '__main__':
    main()
