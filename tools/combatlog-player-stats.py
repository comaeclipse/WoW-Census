"""Build anonymous class/spell totals from a combat log: a raw local log or a companion upload.

Companion uploads blank every player to one GUID (Player-0-00000000), so a player cannot be followed by
GUID. Class comes from two kinds of evidence instead:

  * a player's own lines (casts, melee swings) carry the advanced block of that player: max health,
    power type and max power. Together with the party/outsider flag that groups a player's lines into a
    pseudo-actor, whose class is known when its class-exclusive casts (CLASS_CASTS) name one class;
  * a spell cast by actors of exactly one class is that class's spell. Spell damage, ticks and heals
    (whose advanced block describes the target, not the player) are attributed by the spell alone.

Conflicting evidence excludes: an actor whose casts name two classes, or a spell cast by two classes
(wand Shoot, Throw), is left out rather than guessed. Raw logs keep the real GUID as the actor key.
"""

import csv
from collections import defaultdict


# Exact class-exclusive casts used for attribution. Conflicting evidence excludes the actor.
CLASS_CASTS = {
    (78, "Heroic Strike"): "WARRIOR", (100, "Charge"): "WARRIOR", (284, "Heroic Strike"): "WARRIOR",
    (20271, "Judgement"): "PALADIN", (639, "Holy Light"): "PALADIN",
    (1130, "Hunter's Mark"): "HUNTER", (13549, "Serpent Sting"): "HUNTER",
    (1752, "Sinister Strike"): "ROGUE", (1757, "Sinister Strike"): "ROGUE",
    (591, "Smite"): "PRIEST", (8092, "Mind Blast"): "PRIEST", (2052, "Lesser Heal"): "PRIEST",
    (403, "Lightning Bolt"): "SHAMAN", (529, "Lightning Bolt"): "SHAMAN", (331, "Healing Wave"): "SHAMAN",
    (332, "Healing Wave"): "SHAMAN", (8050, "Flame Shock"): "SHAMAN",
    (143, "Fireball"): "MAGE", (2136, "Fire Blast"): "MAGE",
    (172, "Corruption"): "WARLOCK", (980, "Bane of Agony"): "WARLOCK",
    (5176, "Wrath"): "DRUID", (5178, "Wrath"): "DRUID", (8924, "Moonfire"): "DRUID",
}
# Ability names (any rank) only one class has. They anchor actors and attribute damage whose cast
# is never logged (Thunder Clap, Seal of Righteousness procs).
CLASS_NAMES = {
    "WARRIOR": ("Heroic Strike", "Charge", "Rend", "Thunder Clap", "Hamstring", "Shield Bash", "Revenge",
                "Overpower", "Sunder Armor", "Battle Shout", "Demoralizing Shout", "Execute", "Cleave",
                "Mortal Strike", "Bloodthirst", "Shield Slam", "Whirlwind", "Slam", "Intercept", "Pummel",
                "Mocking Blow", "Bloodrage", "Shield Block", "Intimidating Shout", "Piercing Howl"),
    "PALADIN": ("Judgement", "Holy Light", "Flash of Light", "Seal of Righteousness", "Seal of the Crusader",
                "Seal of Command", "Hammer of Justice", "Holy Strike", "Crusader Strike", "Consecration",
                "Exorcism", "Holy Shock", "Hammer of Wrath", "Devotion Aura", "Retribution Aura",
                "Blessing of Might", "Divine Protection", "Lay on Hands", "Holy Shield"),
    "HUNTER": ("Auto Shot", "Arcane Shot", "Serpent Sting", "Hunter's Mark", "Raptor Strike", "Multi-Shot",
               "Aimed Shot", "Concussive Shot", "Mongoose Bite", "Wing Clip", "Steady Shot", "Immolation Trap",
               "Explosive Trap", "Freezing Trap", "Aspect of the Hawk", "Aspect of the Monkey", "Mend Pet"),
    "ROGUE": ("Sinister Strike", "Eviscerate", "Backstab", "Gouge", "Kick", "Slice and Dice", "Ambush",
              "Garrote", "Rupture", "Hemorrhage", "Ghostly Strike", "Riposte", "Kidney Shot", "Cheap Shot",
              "Sap", "Stealth", "Evasion", "Sprint", "Expose Armor", "Deadly Poison", "Instant Poison"),
    "PRIEST": ("Smite", "Mind Blast", "Shadow Word: Pain", "Lesser Heal", "Power Word: Shield", "Renew",
               "Holy Fire", "Mind Flay", "Flash Heal", "Heal", "Greater Heal", "Psychic Scream",
               "Power Word: Fortitude", "Inner Fire", "Devouring Plague", "Vampiric Embrace"),
    "SHAMAN": ("Lightning Bolt", "Earth Shock", "Flame Shock", "Frost Shock", "Healing Wave",
               "Lesser Healing Wave", "Chain Lightning", "Lightning Shield", "Rockbiter Weapon",
               "Flametongue Weapon", "Flametongue Attack", "Stormstrike", "Searing Totem", "Magma Totem",
               "Stoneskin Totem", "Strength of Earth Totem", "Chain Heal", "Fire Nova"),
    "MAGE": ("Fireball", "Frostbolt", "Fire Blast", "Arcane Missiles", "Arcane Missile", "Arcane Explosion",
             "Frost Nova", "Flamestrike", "Blizzard", "Cone of Cold", "Scorch", "Pyroblast",
             "Arcane Intellect", "Frost Armor", "Ice Armor", "Polymorph", "Blink", "Conjure Water",
             "Conjure Food", "Ice Barrier", "Mana Shield"),
    "WARLOCK": ("Shadow Bolt", "Corruption", "Immolate", "Bane of Agony", "Curse of Agony", "Drain Life",
                "Drain Soul", "Life Tap", "Searing Pain", "Shadowburn", "Hellfire", "Rain of Fire",
                "Death Coil", "Fear", "Howl of Terror", "Siphon Life", "Demon Skin", "Demon Armor",
                "Summon Imp", "Summon Voidwalker", "Health Funnel", "Soul Fire", "Curse of Weakness"),
    "DRUID": ("Wrath", "Moonfire", "Starfire", "Healing Touch", "Rejuvenation", "Regrowth", "Entangling Roots",
              "Thorns", "Mark of the Wild", "Insect Swarm", "Hurricane", "Maul", "Swipe", "Claw", "Rake",
              "Shred", "Ferocious Bite", "Rip", "Bear Form", "Cat Form", "Demoralizing Roar", "Bash",
              "Faerie Fire", "Enrage"),
}
NAME_CLASS = {name: cls for cls, names in CLASS_NAMES.items() for name in names}
# Attacks several classes share; never attributed through the spell alone.
SHARED = {"Shoot", "Throw", "Shoot Bow", "Shoot Gun", "Shoot Crossbow", "Attack"}
BLANK = "Player-0-00000000"
DAMAGE = ("SWING_DAMAGE", "RANGE_DAMAGE", "SPELL_DAMAGE", "SPELL_PERIODIC_DAMAGE")
HEALING = ("SPELL_HEAL", "SPELL_PERIODIC_HEAL")


def events(path):
    with path.open(encoding="utf-8", errors="replace") as stream:
        for line in stream:
            _, sep, payload = line.partition("  ")
            if not sep:
                continue
            try:
                fields = next(csv.reader([payload]))
            except (csv.Error, StopIteration):
                continue
            if len(fields) >= 12 and fields[1].startswith("Player-"):
                yield fields


def actor(f, adv, adv_len):
    """Who did this line: the GUID in a raw log; in an upload, the player's own advanced block
    (max health, power type, max power) plus the party flag, or None when the block describes the target."""
    if f[1] != BLANK:
        return f[1]
    if len(f) < adv + adv_len or f[adv] != f[1] or f[5] == BLANK:
        return None
    try:
        affiliation = int(f[3], 16) & 0xF
    except ValueError:
        return None
    return (affiliation, f[adv + 3], f[adv + 10], f[adv + 12])


def aggregate(path, npc_id, to_int, adv_len, known=None):
    """Totals keyed (class, level, mode, spell id, name, periodic). `known` maps spell id -> class from
    earlier logs; a spell this log ties to another class is dropped."""
    actor_classes = defaultdict(set)
    casts = []
    for f in events(path):
        if f[0] != "SPELL_CAST_SUCCESS":
            continue
        who = actor(f, 12, adv_len)
        spell = to_int(f[9])
        match = CLASS_CASTS.get((spell, f[10])) or NAME_CLASS.get(f[10])
        if who is not None:
            if match:
                actor_classes[who].add(match)
            casts.append((who, spell, f[10]))

    spell_classes = defaultdict(set)
    for (spell, _), cls in CLASS_CASTS.items():
        spell_classes[spell].add(cls)
    # A spell outside the anchor lists counts as a class's only when two or more players of that class
    # cast it and no player of another class did (keeps one player's engineering bomb out).
    casters = defaultdict(lambda: defaultdict(set))
    for who, spell, name in casts:
        cls = actor_classes.get(who, ())
        if spell is not None and len(cls) == 1 and name not in SHARED:
            casters[spell][next(iter(cls))].add(who)
    for spell, by_class in casters.items():
        if len(by_class) > 1:
            spell_classes[spell].update(by_class)
        else:
            (cls, who), = by_class.items()
            if len(who) >= 2:
                spell_classes[spell].add(cls)
    for spell, cls in (known or {}).items():
        spell_classes[spell].add(cls)

    def spell_class(spell, name):
        if name in SHARED:
            return None
        cls = set(spell_classes.get(spell, ()))
        if name in NAME_CLASS:
            cls.add(NAME_CLASS[name])
        return next(iter(cls)) if len(cls) == 1 else None

    totals = defaultdict(lambda: [0, 0, 0, None, None, 0])
    for f in events(path):
        event, target = f[0], f[5]
        healing = event in HEALING
        if not healing and event not in DAMAGE:
            continue
        swing = event == "SWING_DAMAGE"
        if swing:
            cls = actor_classes.get(actor(f, 9, adv_len), ())
            cls = next(iter(cls)) if len(cls) == 1 else None
        else:
            cls = spell_class(to_int(f[9]), f[10])
            if f[1] != BLANK:
                own = actor_classes.get(f[1], ())
                cls = next(iter(own)) if len(own) == 1 and cls in (None, next(iter(own))) else None
        if cls is None:
            continue
        adv = 9 if swing else 12
        offset = adv + adv_len
        if healing and not target.startswith("Player-"):
            continue
        mode = "Healing" if healing else "PvP" if target.startswith("Player-") else "PvE"
        if mode == "PvE" and npc_id(target, f[7]) is None:
            continue
        if len(f) <= offset + (4 if healing else 7):
            continue
        spell = 0 if swing else to_int(f[9])
        name = "Melee" if swing else f[10]
        amount = to_int(f[offset])
        # Advanced logging provides effective amount first, then raw amount and excess.
        if spell is None or amount is None or amount < 0:
            continue
        periodic = event in ("SPELL_PERIODIC_DAMAGE", "SPELL_PERIODIC_HEAL")
        # The advanced block's last field is not a reliable player level in Forever.
        row = totals[(cls, 0, mode, spell, name, periodic)]
        row[0] += 1
        row[1] += amount
        row[2] += f[offset + (4 if healing else 7)] == "1"
        row[3] = amount if row[3] is None else min(row[3], amount)
        row[4] = amount if row[4] is None else max(row[4], amount)
        if healing:
            row[5] += max(0, to_int(f[offset + 2]) or 0)
    return totals


def known_spells(cur, source):
    """Spell id -> class for spells earlier logs tied to exactly one class (melee excluded)."""
    cur.execute("SELECT to_regclass('marketlens_import.combatlog_class_spells') IS NOT NULL")
    if not cur.fetchone()[0]:
        return {}
    cur.execute("""SELECT spell_id, min(class_file) FROM marketlens_import.combatlog_class_spells
        WHERE source_game = %s AND spell_id <> 0 GROUP BY spell_id HAVING count(DISTINCT class_file) = 1""", (source,))
    return dict(cur.fetchall())


def store(cur, file_key, source, totals):
    cur.execute("""CREATE TABLE IF NOT EXISTS marketlens_import.combatlog_class_spells (
        file_key text NOT NULL, source_game text NOT NULL, class_file text NOT NULL, level smallint NOT NULL,
        mode text NOT NULL, spell_id integer NOT NULL, name text NOT NULL, periodic boolean NOT NULL,
        events integer NOT NULL, amount bigint NOT NULL, crits integer NOT NULL, low integer NOT NULL,
        high integer NOT NULL, overheal bigint NOT NULL,
        PRIMARY KEY (file_key, source_game, class_file, level, mode, spell_id, name, periodic))""")
    cur.execute("DELETE FROM marketlens_import.combatlog_class_spells WHERE file_key = %s AND source_game = %s",
                (file_key, source))
    cur.executemany("""INSERT INTO marketlens_import.combatlog_class_spells
        (file_key, source_game, class_file, level, mode, spell_id, name, periodic, events, amount, crits, low, high, overheal)
        VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)""",
                    [(file_key, source, *key, *values) for key, values in totals.items()])
