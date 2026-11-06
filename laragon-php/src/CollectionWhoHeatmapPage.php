<?php
// Shared local and protected-origin heatmap page shell.
declare(strict_types=1);

function render_collection_heatmap_page(array $snapshot, string $region, string $timeMode, string $self, string $chrome, string $note, string $extraNav = ''): string {
  if (!isset(cs_region_choices()[$region])) $region = 'both';
  $timeMode = $timeMode === 'realm' ? 'realm' : 'local';
  $nav = $chrome . '<nav class="view-nav" aria-label="Activity view">'
    . '<a href="' . cs_h($self) . '?region=' . $region . '">Daily history</a>'
    . '<a href="' . cs_h($self) . '?view=who-hours&amp;region=' . $region . '" aria-current="page">/who time heatmap</a>' . $extraNav . '</nav>';
  $html = render_collection_stats($snapshot, $region, $self, $nav, $note);
  $start = strpos($html, '<p class="eyebrow">');
  $end = strrpos($html, '</body></html>');
  if ($start === false || $end === false) throw new RuntimeException('Cannot render heatmap shell');
  $heatmap = render_who_heatmap($snapshot, $region, $timeMode, $self);
  $regions = '<div class="toolbar"><nav class="regions" aria-label="Collection region">';
  foreach (cs_region_choices() as $choice=>$label) {
    $regions .= '<a href="' . cs_h($self) . '?view=who-hours&amp;region=' . $choice . '&amp;time=' . $timeMode . '"'
      . ($region === $choice ? ' aria-current="page"' : '') . '>' . $label . '</a>';
  }
  $regions .= '</nav></div>';
  $html = substr($html, 0, $start) . '<p class="eyebrow">Collection activity</p>' . $regions . $heatmap . substr($html, $end);
  $html = str_replace('</style>', '.view-nav{display:flex;gap:12px;margin:12px 0 22px}.view-nav a{font-size:.85rem;text-decoration:none;padding:7px 12px;border:1px solid #344759;border-radius:7px}.view-nav a[aria-current=page]{background:#456e96;color:white}.heat-legend{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:-8px 0 20px;color:#a8b9ca;font-size:.78rem}.heat-legend-item{display:inline-flex;align-items:center;gap:5px}.heat-legend .heat-cell{display:inline-block;width:15px;height:15px;flex:none}.heatmap-edition{position:relative}.heatmap-edition>summary{padding-right:245px}.heat-selector{position:absolute;right:18px;top:14px;display:flex;align-items:center;gap:8px;color:#a8b9ca;font-size:.8rem}.heat-selector select{max-width:170px;background:#101d2a;color:#edf3fa;border:1px solid #456176;border-radius:6px;padding:7px 9px;font:inherit}.heat-edition{padding:4px 18px 18px;border-top:1px solid #344759}.heat-realm{border-bottom:1px solid #344759;padding:14px 0}.heat-realm[hidden]{display:none}.heat-realm:last-child{border-bottom:0}.heat-realm h2{font-size:.95rem;margin:0 0 12px}.heat-realm h2 small{font-weight:400;color:#9db0c2;margin-left:8px}.heat-scroll{overflow-x:auto}.heat-grid{display:grid;grid-template-columns:52px repeat(30,minmax(16px,1fr));gap:3px;min-width:760px}.heat-date{font-size:.65rem;color:#a8b9ca;writing-mode:vertical-rl;transform:rotate(180deg);height:39px;text-align:left}.heat-corner,.heat-hour{font-size:.68rem;color:#91a7bb;text-align:right;padding-right:5px}.heat-cell{height:15px;border-radius:2px;background:#263646}.heat-cell.level-1{background:#315d86}.heat-cell.level-2{background:#367db0}.heat-cell.level-3{background:#40a9ce}.heat-cell.level-4{background:#80ddeb}.heat-cell:hover{outline:2px solid #fff;outline-offset:1px}@media(max-width:650px){.heat-edition{padding:4px 12px 14px}.heatmap-edition>summary{padding:14px 12px 48px}.heat-selector{top:51px;left:12px;right:12px}.heat-selector select{max-width:min(240px,75vw)}}</style>', $html);
  $html = str_replace('</style>', '.heat-time-toggle{display:flex;gap:5px;flex-wrap:wrap;margin:-7px 0 19px;padding:4px;width:max-content;max-width:100%;background:#1b2936;border:1px solid #344759;border-radius:9px}.heat-time-toggle a{padding:7px 12px;border-radius:6px;text-decoration:none;color:#b9cbdc;font-size:.82rem}.heat-time-toggle a[aria-current=page]{background:#456e96;color:white;font-weight:700}</style>', $html);
  $html = str_replace('</style>', '.heatmap-edition>summary{padding-right:440px}.heat-controls{position:absolute;right:18px;top:14px;display:flex;align-items:center;gap:10px}.heat-controls .heat-selector{position:static;display:flex;align-items:center;gap:6px}.heat-region-pills{display:flex;gap:2px;padding:3px;background:#101d2a;border:1px solid #456176;border-radius:8px}.heat-region-pills button{font:inherit;font-size:.78rem;color:#b9cbdc;background:transparent;border:0;border-radius:5px;padding:7px 9px;cursor:pointer}.heat-region-pills button[aria-pressed=true]{background:#456e96;color:white;font-weight:700}.heat-region-pills button:disabled{opacity:.35;cursor:not-allowed}@media(max-width:950px){.heatmap-edition>summary{padding-right:18px}.heat-controls{position:static;padding:0 18px 14px;flex-wrap:wrap}.heatmap-edition>summary{padding-bottom:10px}}@media(max-width:650px){.heatmap-edition>summary{padding:14px 12px 10px}.heat-controls{padding:0 12px 12px}}</style>', $html);
  return $html;
}
