#!/usr/bin/env python3
"""Check Forever leaderboard candidates against current Blizzard profiles.

Writes an atomic, credential-free snapshot. A failed run leaves the previous
snapshot in place. Credentials come from a 0600 environment file or process env.
"""
import argparse
import base64
import concurrent.futures
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

HERE = Path(__file__).resolve().parent
DEFAULT_OUTPUT = Path('/srv/wowcensus/data/race60-api.json')
DEFAULT_ENV = Path('/etc/wowcensus/forever-api.env')
# Another Forever race tracker. Its names are only candidates: a character joins our board only
# when the Blizzard API returns a Forever profile with the same name and class.
EXTERNAL_URL = 'https://race.aotc.gg/api/leaderboard'
# Blizzard class ids, as the external tracker reports them.
CLASS_IDS = {1: 'Warrior', 2: 'Paladin', 3: 'Hunter', 4: 'Rogue', 5: 'Priest', 6: 'Death Knight',
             7: 'Shaman', 8: 'Mage', 9: 'Warlock', 10: 'Monk', 11: 'Druid', 12: 'Demon Hunter', 13: 'Evoker'}
RULESETS = {'PVP': 'PvP', 'NORMAL': 'PvE', 'RP': 'RP', 'RPPVP': 'RP-PvP'}
FPM_POOL = Path('/etc/php/8.3/fpm/pool.d/wowcensus.conf')
CACHE_ROOT = Path('/var/cache/nginx/wowcensus')


def credentials(path):
    values = dict(os.environ)
    if path.is_file():
        for line in path.read_text().splitlines():
            if line.startswith(('BLIZZARD_CLIENT_ID=', 'BLIZZARD_CLIENT_SECRET=')):
                key, value = line.split('=', 1)
                values[key] = value.strip().strip('"').strip("'")
    return values['BLIZZARD_CLIENT_ID'], values['BLIZZARD_CLIENT_SECRET']


def request(url, headers=None, data=None):
    req = urllib.request.Request(url, headers=headers or {}, data=data)
    with urllib.request.urlopen(req, timeout=20) as response:
        return json.load(response)


def token(client_id, secret):
    basic = base64.b64encode(f'{client_id}:{secret}'.encode()).decode()
    body = request('https://us.battle.net/oauth/token',
                   {'Authorization': 'Basic ' + basic}, b'grant_type=client_credentials')
    return body['access_token']


def check(candidate, bearer, prior):
    now = int(time.time())
    region = candidate['region']
    name = candidate['name']
    result = {'name': name, 'region': region, 'checked_at': now}
    if region not in ('us', 'eu'):
        return result | {'status': 'unsupported_region'}
    slug = urllib.parse.quote('-'.join(name.lower().split()), safe='-')
    url = (f'https://{region}.api.blizzard.com/profile/wow/character/{slug}'
           f'?namespace=profile-forever-{region}&locale=en_US')
    try:
        profile = request(url, {'Authorization': 'Bearer ' + bearer})
    except urllib.error.HTTPError as error:
        return result | {'status': 'not_found' if error.code == 404 else 'http_error',
                         'http_status': error.code}
    except (urllib.error.URLError, TimeoutError, ValueError):
        return result | {'status': 'request_error'}
    api_name = str(profile.get('name', ''))
    api_class = str(profile.get('character_class', {}).get('name', ''))
    api_race = str(profile.get('race', {}).get('name', ''))
    api_faction = str(profile.get('faction', {}).get('type', ''))
    api_id = profile.get('id')
    result.update(http_status=200, level=profile.get('level'), profile_id=api_id,
                  api_class=api_class, api_race=api_race, api_faction=api_faction,
                  api_guild=str((profile.get('guild') or {}).get('name', '')),
                  api_realm=str((profile.get('realm') or {}).get('name', '')))
    expected_name = name.split(' ', 1)[0]
    # External candidates carry no faction; the name and class still have to match.
    if (api_name.casefold() != expected_name.casefold()
            or api_class.casefold() != candidate['class'].casefold()
            or (candidate.get('faction') and api_faction != candidate['faction'])
            or (prior and prior.get('profile_id') and prior['profile_id'] != api_id)):
        result['status'] = 'identity_mismatch'
    elif not isinstance(profile.get('level'), int) or not 1 <= profile['level'] <= 60:
        result['status'] = 'invalid_level'
    else:
        result['status'] = 'ok'
    return result


def external_candidates(url, known):
    """Names from the external tracker that our own list does not have yet.

    A name with no region is tried in both regions. Returns [] when the tracker is unreachable,
    so a failed fetch never blocks the daily check of our own candidates.
    """
    try:
        req = urllib.request.Request(url, headers={'Accept': 'application/json',
                                                   'User-Agent': 'WoWCensus race-to-60 (+https://wowcensus.io)'})
        with urllib.request.urlopen(req, timeout=30) as response:
            characters = json.load(response).get('characters') or []
    except (urllib.error.URLError, TimeoutError, ValueError, AttributeError) as error:
        print(json.dumps({'external_error': str(error)}))
        return []
    out = {}
    for c in characters:
        name = ' '.join(str(c.get('characterName') or '').split())
        cls = CLASS_IDS.get(c.get('classId'))
        if not name or not cls:
            continue
        # The tracker sometimes reports /who names in capitals.
        display = name.title() if name.isupper() else name
        region = str(c.get('region') or '').lower()
        for r in ([region] if region in ('us', 'eu') else ['us', 'eu']):
            if (r, display.casefold()) in known:
                continue
            key = f'ext:{r}:{display.casefold()}'
            out[key] = {'key': key, 'name': display, 'region': r, 'class': cls, 'race': '', 'faction': None,
                        'external': {'source': 'aotc', 'ruleset': RULESETS.get(str(c.get('ruleset') or '').upper(), ''),
                                     'level': c.get('level')}}
    return list(out.values())


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--env-file', type=Path, default=DEFAULT_ENV)
    parser.add_argument('--output', type=Path, default=DEFAULT_OUTPUT)
    parser.add_argument('--limit', type=int, default=3000)
    parser.add_argument('--max-age-hours', type=int, default=20)
    parser.add_argument('--external-url', default=EXTERNAL_URL, help="another tracker's leaderboard JSON; '' to skip")
    args = parser.parse_args()
    php_env = dict(os.environ)
    if FPM_POOL.is_file():
        for line in FPM_POOL.read_text().splitlines():
            match = re.match(r'^env\[(ML_DB_[A-Z_]+)\]\s*=\s*(.*)$', line)
            if match:
                php_env[match.group(1)] = match.group(2).strip().strip('"').strip("'")
    candidates = json.loads(subprocess.check_output(
        ['php', str(HERE / 'race60-api-candidates.php')], cwd=HERE, env=php_env,
        timeout=120))[:args.limit]
    if not candidates:
        raise RuntimeError('No leaderboard candidates; leaving prior snapshot unchanged')
    known = {(c['region'], c['name'].casefold()) for c in candidates}
    externals = external_candidates(args.external_url, known) if args.external_url else []
    previous = json.loads(args.output.read_text()) if args.output.is_file() else {}
    old = previous.get('checks', {}) | previous.get('external', {})
    now = int(time.time())
    checks = {}
    pending = []
    for candidate in candidates + externals:
        prior = old.get(candidate['key'])
        if prior and prior.get('name') == candidate['name'] and prior.get('region') == candidate['region'] \
                and now - prior.get('checked_at', 0) < args.max_age_hours * 3600:
            checks[candidate['key']] = prior
        else:
            pending.append(candidate)
    if pending:
        bearer = token(*credentials(args.env_file))
        with concurrent.futures.ThreadPoolExecutor(max_workers=5) as pool:
            futures = {pool.submit(check, c, bearer, old.get(c['key'])): c['key'] for c in pending}
            for future in concurrent.futures.as_completed(futures):
                checks[futures[future]] = future.result()
    # External names live apart from our own checks, with what the page needs to show them.
    external = {}
    for c in externals:
        external[c['key']] = checks.pop(c['key']) | {'display_name': c['name'], 'source': c['external']['source'],
                                                     'ruleset': c['external']['ruleset']}
    snapshot = {'version': 1, 'generated_at': int(time.time()), 'candidate_count': len(candidates),
                'checks': checks, 'external_count': len(externals), 'external': external}
    args.output.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.NamedTemporaryFile('w', encoding='utf-8', dir=args.output.parent,
                                     prefix='.race60-api-', delete=False) as temp:
        json.dump(snapshot, temp, ensure_ascii=False, separators=(',', ':'))
        temp.write('\n')
        temp_name = temp.name
    os.chmod(temp_name, 0o644)
    os.replace(temp_name, args.output)
    purged = 0
    if args.output == DEFAULT_OUTPUT and CACHE_ROOT.is_dir():
        for cache_file in CACHE_ROOT.rglob('*'):
            if cache_file.is_file():
                with cache_file.open('rb') as cached:
                    if b'/forever/race-to-60' in cached.read(1024):
                        cache_file.unlink()
                        purged += 1
    summary = {}
    for value in checks.values():
        summary[value['status']] = summary.get(value['status'], 0) + 1
    external_summary = {}
    for value in external.values():
        external_summary[value['status']] = external_summary.get(value['status'], 0) + 1
    print(json.dumps({'candidates': len(candidates), 'queried': len(pending),
                      'status': summary, 'external': len(externals), 'external_status': external_summary,
                      'page_cache_entries_purged': purged}))


if __name__ == '__main__':
    main()
