<?php
declare(strict_types=1);

date_default_timezone_set('UTC');
ini_set('serialize_precision', '-1');

require __DIR__ . '/config.php';
foreach (['Js', 'Db', 'Census', 'Data', 'Inspects', 'Edition', 'Market', 'Guilds', 'GuildRosters', 'Talents', 'Profiles', 'RaceProfile', 'Site', 'Markdown', 'Realms', 'SkinColors', 'ForeverSkinColors', 'RaceTo60', 'Routes'] as $module)
  require __DIR__ . "/$module.php";
