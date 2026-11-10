<?php
// Add public changelog entries here. Use a unique positive integer ID for each post.
// Body is plain text; separate paragraphs with a blank line. No HTML is executed.
declare(strict_types=1);

return [
    // 1234 => [
    //     'date' => '2026-10-25',
    //     'title' => 'Example update',
    //     'body' => "What changed and why.\n\nAny follow-up notes go here.",
    // ],
    1 => [
        'date' => '2026-09-07',
        'title' => 'Launching WoWCensus',
        'body' => <<<'TEXT'
Every realm has a story, and most of it goes unseen.

This started with a question I couldn't shake: what does a world look like when you step back from your own corner of it? I play alongside thousands of people I'll never meet. I see a name in a city, a seller at the auction house, someone passing through a zone. Each one is a small piece of something bigger. Who is still playing? What do people choose to be? Where do they go, and what do they spend their time making and trading?

Nobody puts those pieces together. So I started to.

WoWCensus has one goal: look at a realm as a whole and see what stories come out of it. It might be a server that quietly leans one way, a class going out of fashion, or an economy built around a single profession. I care more about those patterns than about the numbers themselves.

For now it's just me, collecting what I can see in game with a small addon I wrote for the job and building it up one scan at a time. One snapshot doesn't say much. Weeks of them start to.

Today is day one. There's a population view, a look at realm markets, and a lot of questions I haven't answered yet. It's an ambitious idea for a one-person project, and I'm curious where it leads.
TEXT,
    ],
    2 => [
        'date' => '2026-10-25',
        'title' => 'The shoutbox is here',
        'body' => "In the spirit of the 2000s and to stay true to this theme, I've added a shoutbox to the site so visitors can drop messages to other users. You know, just like the guild site your buddy hosted on Angelfire in 2005. Have fun and be kind!",
    ],
    3 => [
        'date' => '2026-11-05',
        'title' => "WoW: Forever's first 24 hours, by the numbers",
        'body' => <<<'TEXT'
WoW: Forever went live at 23:00 UTC on November 4, and the WoW Census addon was scanning from the first minute. By the same time the next day, it had recorded 153,980 unique characters across 11 realms in the US and EU, along with 7,343 guilds that already had at least one member. Most players were still in the starting zones: about 71% of the characters we saw were level 1–9, and only 3,632 had reached level 20. A handful pushed past level 30 on day one. These are characters our addon saw, not a server population count, but it's a large enough sample to show what launch day looked like.

The biggest story is the new races. High Order Skyborne made up 16.6% of the Alliance characters we saw, ahead of Gnomes (13.8%) and not far behind Dwarves (19.4%) and Night Elves (20.0%). On the Horde side, Windshaper Skyborne took 15.0%, close behind Trolls (16.3%) and Tauren (17.0%). The classic favorites still lead: Humans are 30.1% of the Alliance, and Undead are a full third of the Horde at 33.6%, nearly double Orcs, the next most common Horde race. We compare races within each faction rather than across the whole server. How long we scanned each faction changes the raw totals, but it doesn't change the mix inside a faction.

Classes are spread far more evenly than races. Every class is between 9% and 14% of its faction. Hunters (13.9%) and Druids (13.5%) are the most popular Alliance picks. On the Horde side, Shamans lead at 13.7%, against just 10.0% on the Alliance. Alliance players lean toward Mages and Paladins, while the Horde has more Warlocks, Rogues and Warriors. We'll keep scanning as people level up and settle on their mains. Follow along at wowcensus.io/forever to see whether the Skyborne novelty holds up and how the class mix shifts once people start reaching max level.
TEXT,
    ],
    5 => [
        'date' => '2026-11-09',
        'title' => "WoW: Forever's First-Week Economy: Which Professions Are Already Oversaturated?",
        'body' => 'An early Auction House market analysis reveals mountains of cheap materials, unprofitable crafted goods, and surprising processing opportunities. We sampled 27 items across all 12 professions, comparing asking prices, available quantities, and theoretical crafting margins.',
        'html_file' => 'blog-forever-economy.html',
    ],
    4 => [
        'date' => '2026-11-09',
        'title' => 'Will WoW: Forever Kill Off Classic WoW?',
        'body' => <<<'TEXT'
That is the question after Forever's first weekend. Our scans saw a steep drop in characters online across Classic Era and TBC Anniversary compared with scans before Forever launched on Wednesday, November 4.

On Classic Era, our scans saw about 80% fewer characters on Westfall, averaging observations from its connected realms, and 89% fewer on Nethergarde Keep. Westfall's Auction House had 42% fewer active listings on November 8 than on November 3.

TBC Anniversary showed a similar pattern. Our scans saw 84% fewer characters on Dreamscythe and 86% fewer on Thunderstrike. Dreamscythe's Auction House listings fell 43% between November 4 and November 5.

Elsewhere, our scans saw 73% fewer characters on MoP Classic's Pagle and 79% fewer on Shek'zeer. Season of Discovery's Wild Growth was down roughly 95% in both the US and EU.

These are snapshots of characters seen online at different hours, not a count of subscriptions or a prediction of what happens next. This is week one, and Forever is included in the existing subscription. Naturally, many players are trying it out. Time will tell whether these numbers sustain once the launch rush settles.
TEXT,
        'table' => [
            ['Edition', 'Realm', 'Before', 'After', 'Change'],
            ['Classic Era', 'Westfall', '4,054', '809', '−80%'],
            ['Classic Era', 'Nethergarde Keep', '4,713', '516', '−89%'],
            ['TBC Anniversary', 'Dreamscythe', '8,933', '1,459', '−84%'],
            ['TBC Anniversary', 'Thunderstrike', '8,473', '1,196', '−86%'],
            ['MoP Classic', 'Pagle', '4,270', '1,161', '−73%'],
            ['MoP Classic', "Shek'zeer", '2,718', '576', '−79%'],
            ['Season of Discovery', 'Wild Growth US', '869', '42', '−95%'],
            ['Season of Discovery', 'Wild Growth EU', '1,008', '37', '−96%'],
        ],
    ],
];
