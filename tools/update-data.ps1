# Downloads TradeSkillMaster's public region pricing CSV and writes it as a Lua
# table that the in-game addon loads. WoW addons cannot fetch URLs themselves,
# so this runs outside the game (double-click or scheduled).
#
# All WoW flavors here (retail / tbc-anniversary / classic-era) may be junctions to the
# same repo, so they load the SAME files. To let retail and classic data coexist
# we emit TWO files, each self-gated on WOW_PROJECT_ID so exactly one assigns the
# shared global MarketLensRegionData on any given client:
#   -Flavor tbc-anniversary  -> Data/TSMRegion.lua        (skips on Retail)
#   -Flavor classic-era      -> Data/TSMRegion.lua        (skips on Retail)
#   -Flavor retail           -> Data/TSMRegionRetail.lua  (runs only on Retail)
#
# Data source: https://public-data.tradeskillmaster.com  (public, no key)
# Usage:  right-click > Run with PowerShell  (defaults to TBC Anniversary),
#   or:  powershell -File update-data.ps1 -Flavor retail
#        optional: -Region eu   -GameType <override>   -Out C:\path\File.lua

param(
    [string]$Flavor   = "tbc-anniversary",
    [string]$Region   = "us",
    [string]$GameType,
    [string]$Out
)

$ErrorActionPreference = "Stop"
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

$flavorKey = $Flavor.ToLowerInvariant()
if ($flavorKey -in @("classic", "anniversary", "tbc")) { $flavorKey = "tbc-anniversary" }
if ($flavorKey -notin @("tbc-anniversary", "classic-era", "retail")) {
    Write-Host "Unknown flavor '$Flavor'. Use tbc-anniversary, classic-era, or retail." -ForegroundColor Red
    exit 1
}
$Flavor = $flavorKey
$isRetail = ($Flavor -eq "retail")

# Default the TSM game-type slug per flavor unless the caller overrides it.
if (-not $GameType) {
    if ($isRetail) { $GameType = "retail" }
    elseif ($Flavor -eq "classic-era") { $GameType = "classic" }
    else { $GameType = "classic-progression" }
}

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
if (-not $Out) {
    $name = if ($isRetail) { "TSMRegionRetail.lua" } else { "TSMRegion.lua" }
    # The addon's data folder is repo\MarketLens\Data (the addon lives in a
    # MarketLens\ subfolder), not repo\Data. tools\ sits at the repo root.
    $Out  = Join-Path $scriptDir "..\MarketLens\Data\$name"
}

# The load-time guard: only the file matching the running client assigns the
# global; the other returns immediately (its huge literal is never built).
# Nil-safe: only ever SKIP the classic file when we're definitely on Retail, so
# a missing WOW_PROJECT_* global can never blank out the primary Classic data.
if ($isRetail) {
    $guard = "if not (WOW_PROJECT_ID and WOW_PROJECT_ID == WOW_PROJECT_MAINLINE) then return end -- Retail only"
} else {
    $guard = "if WOW_PROJECT_ID and WOW_PROJECT_ID == WOW_PROJECT_MAINLINE then return end -- Classic Era/TBC Anniversary only"
}

$url = "https://public-data.tradeskillmaster.com/$GameType/$Region/region/items.csv"
Write-Host "MarketLens: downloading $url"

$resp = Invoke-WebRequest -Uri $url -UseBasicParsing
$lines = $resp.Content -split "`n"

function Num($v) {
    if ($v -and (($v -as [double]) -ne $null)) { return $v } else { return "0" }
}

$sb = New-Object System.Text.StringBuilder
$updatedAt = ""
$count = 0

for ($i = 1; $i -lt $lines.Length; $i++) {
    $line = $lines[$i].TrimEnd("`r")
    if ($line -eq "") { continue }
    $f = $line.Split(",")
    if ($f.Length -lt 8) { continue }
    $n = $f.Length

    # Parse from the RIGHT so item names containing commas can't shift columns.
    $id   = $f[0]
    $spd  = Num $f[$n-2]
    $sr   = Num $f[$n-3]
    $asp  = Num $f[$n-4]
    $hist = Num $f[$n-5]
    $mv   = Num $f[$n-6]
    if ($updatedAt -eq "") { $updatedAt = $f[$n-1].Trim() }

    if ((($sr -as [double]) -gt 0) -or (($spd -as [double]) -gt 0)) {
        [void]$sb.AppendLine("    [$id]={mv=$mv,hist=$hist,asp=$asp,sr=$sr,spd=$spd},")
        $count++
    }
}

$header = @"
-- MarketLens/Data/$(Split-Path -Leaf $Out)
-- Auto-generated from TradeSkillMaster public data. Do not edit by hand.
-- Source: $url
-- Regenerate with tools/update-data.ps1 -Flavor $Flavor
$guard
MarketLensRegionData = {
  region = "$Region",
  gameType = "$GameType",
  updatedAt = "$updatedAt",
  count = $count,
  items = {
"@
$footer = @"
  },
}
"@

$full = $header + "`r`n" + $sb.ToString() + $footer
Set-Content -Path $Out -Value $full -Encoding utf8

Write-Host ("MarketLens: wrote {0} items (scanned {1}) -> {2}" -f $count, $updatedAt, (Resolve-Path $Out))
Write-Host "Reload WoW (/reload) or restart to pick up the new data."
