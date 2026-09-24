# Reads your addon's SavedVariables export and pushes it to the website.
# No copy-paste. Run it after you /reload or log out in game.
#
# Usage:  powershell -File upload-realm.ps1 -Token YOUR_REFRESH_TOKEN
# TBC:    powershell -File upload-realm.ps1 -Flavor tbc-anniversary
# Era:    powershell -File upload-realm.ps1 -Flavor classic-era
# Retail: powershell -File upload-realm.ps1 -Flavor retail
# MoP:    powershell -File upload-realm.ps1 -Flavor mop-classic
#         (double-click "Run with PowerShell" and it will prompt for the token)
#
# The addon writes a fresh export to SavedVariables on logout/reload, so:
#   1. In game: scan the AH, then /reload (or log out)
#   2. (optional) Sanity-check it first: node analyze-realm.js <MarketLens.lua> --realm=<Realm-Faction>
#   3. Run this script (add -Realm <Realm-Faction> when the save holds several)

param(
    [string]$Token,
    [string]$Flavor = "tbc-anniversary",
    [string]$Region,
    [string]$Url    = "https://marketlens.skarz.workers.dev",
    [string]$Wow,
    [switch]$PopulationOnly,
    # Upload a specific "Realm-Faction" bucket instead of the active export. Both
    # factions' data live in the account-wide save, so this uploads either side
    # without swapping characters and reloading. e.g. Nesingwary-Alliance,
    # Dreamscythe-Horde. Without it the uploader takes whichever bucket was last
    # active and warns if the save holds others.
    [string]$Realm
)

$ErrorActionPreference = "Stop"
$OutputEncoding = New-Object System.Text.UTF8Encoding($false)
[Console]::OutputEncoding = $OutputEncoding
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

$flavorKey = $Flavor.ToLowerInvariant()
if ($flavorKey -in @("classic", "anniversary", "tbc")) { $flavorKey = "tbc-anniversary" }
if ($flavorKey -in @("forever", "classicbeta")) { $flavorKey = "classic-beta" }
if ($flavorKey -in @("mists", "mop", "mists-classic")) { $flavorKey = "mop-classic" }
if ($flavorKey -notin @("tbc-anniversary", "classic-era", "retail", "classic-beta", "mop-classic")) {
    Write-Host "Unknown flavor '$Flavor'. Use tbc-anniversary, classic-era, retail, classic-beta, or mop-classic." -ForegroundColor Red
    exit 1
}
$Flavor = $flavorKey

if (-not $Region) {
    $Region = if ($Flavor -eq "retail") { "retail" } elseif ($Flavor -eq "classic-era") { "classic" } elseif ($Flavor -eq "classic-beta") { "classic-beta" } elseif ($Flavor -eq "mop-classic") { "mop-classic" } else { "classic-progression" }
}
if (-not $Wow) {
    $wowFolder = if ($Flavor -eq "retail") { "_retail_" } elseif ($Flavor -eq "classic-era") { "_classic_era_" } elseif ($Flavor -eq "classic-beta") { "_classic_beta_" } elseif ($Flavor -eq "mop-classic") { "_classic_" } else { "_anniversary_" }
    $Wow = Join-Path "C:\Program Files (x86)\World of Warcraft" $wowFolder
}

# Token is remembered (encrypted per-Windows-user via DPAPI) so you enter it once.
$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$tokenFile = Join-Path $scriptDir ".mltoken"
function Save-Token($t) {
    (ConvertTo-SecureString $t -AsPlainText -Force | ConvertFrom-SecureString) |
        Set-Content -Path $tokenFile -Encoding ascii
}
function Load-Token {
    if (Test-Path $tokenFile) {
        try {
            $sec = Get-Content $tokenFile | ConvertTo-SecureString
            return [Runtime.InteropServices.Marshal]::PtrToStringAuto(
                [Runtime.InteropServices.Marshal]::SecureStringToBSTR($sec))
        } catch { return $null }
    }
    return $null
}

# Read the site back after an import and report what actually landed, since the
# import call only says "ok". Regex over the raw response instead of
# ConvertFrom-Json: Windows PowerShell 5.1 caps JSON parsing at 2 MB and a big
# realm's /api/items is larger. Report-only -- it never fails the upload.
function Test-SiteImport($storedRealm, $uploaded) {
    try {
        $game = [uri]::EscapeDataString("realm:$storedRealm")
        $raw = (Invoke-WebRequest -Uri "$Url/api/items?game=$game" -UseBasicParsing -TimeoutSec 60).Content
        $count = if ($raw -match '"count":(\d+)') { [int]$matches[1] } else { $null }
        $updated = if ($raw -match '"updatedAt":"([^"]+)"') { $matches[1] } else { $null }
        $placeholders = [regex]::Matches($raw, ',"item:\d+",').Count

        if ($null -eq $count) {
            Write-Host "Verify: couldn't read the item count back from the site." -ForegroundColor Yellow
        } elseif ($count -lt $uploaded) {
            Write-Host ("Verify: site lists {0} items but the import reported {1}." -f $count, $uploaded) -ForegroundColor Yellow
        } else {
            Write-Host ("Verify: site lists {0} items for {1} ({2} imported)." -f $count, $storedRealm, $uploaded) -ForegroundColor Green
        }
        if ($updated) {
            $age = [DateTimeOffset]::UtcNow - [DateTimeOffset]::Parse($updated, [Globalization.CultureInfo]::InvariantCulture)
            if ($age.TotalMinutes -gt 10) {
                Write-Host ("Verify: newest item row on the site is {0:N0} min old ({1}) -- the import may not have written." -f $age.TotalMinutes, $updated) -ForegroundColor Yellow
            }
        }
        if ($placeholders -gt 0) {
            Write-Host ("Verify: {0} item(s) still show as item:<id> (not in the region table). Backfill: GET {1}/admin/resolve-names?token=...&region={2}&limit=80, repeating until checked=0." -f $placeholders, $Url, $Region) -ForegroundColor Yellow
        } else {
            Write-Host "Verify: no unnamed items." -ForegroundColor Green
        }
    } catch {
        Write-Host ("Verify: couldn't read the site back ({0})." -f $_.Exception.Message) -ForegroundColor Yellow
    }
}

# Locate the account-wide SavedVariables file (newest if several accounts).
$files = Get-ChildItem "$Wow\WTF\Account\*\SavedVariables\MarketLens.lua" -ErrorAction SilentlyContinue
if (-not $files) {
    Write-Host "Couldn't find MarketLens.lua under $Wow\WTF\Account\*\SavedVariables\." -ForegroundColor Red
    Write-Host "Log out (or /reload) in game at least once so the addon writes its data, then retry."
    exit 1
}
$file = $files | Sort-Object LastWriteTime -Descending | Select-Object -First 1
Write-Host "Reading $($file.FullName)"

$content = Get-Content $file.FullName -Raw -Encoding UTF8

# The compact ["export"] string is just a cache RefreshExports writes on
# logout/reload -- if that Lua call errored out (or hasn't run yet this
# session) the string can be missing even though the realm tables it would
# have summarized are sitting right there. Treat it as a best-effort hint,
# not a hard gate: fall through to an empty placeholder and let the node
# rebuild below (which reads those tables directly) do the real work.
$obj = [PSCustomObject]@{ realm = $null; items = [PSCustomObject]@{} }
$json = $null
if ($content -match '\["export"\]\s*=\s*"((?:\\.|[^"\\])*)"') {
    $rawJson = [regex]::Replace($matches[1], '\\(.)', '$1')
    try { $parsed = $rawJson | ConvertFrom-Json } catch { $parsed = $null }
    if ($parsed -and $parsed.type -eq "ml-realm-v1") { $obj = $parsed; $json = $rawJson }
}
if (-not $json) {
    Write-Host "No usable compact export in SavedVariables -- rebuilding directly from the saved realm tables instead." -ForegroundColor Yellow
}

# Rebuild from the authoritative realm tables rather than trusting the compact
# export string: it carries the addon's per-item market classification, always
# reflects the latest scan (retail can leave a stale export string), and a -Realm
# target can pull any faction bucket instead of only the active export. Falls
# back to the already-parsed export string when node isn't available.
$helper = Join-Path $scriptDir "export-realm-from-savedvariables.js"
$node = Get-Command node -ErrorAction SilentlyContinue
if ($node -and (Test-Path $helper)) {
    $mktArgs = @($helper, $file.FullName)
    if ($Realm) { $mktArgs += "--realm=$Realm" }
    $rebuilt = & $node.Source @mktArgs
    if ($LASTEXITCODE -ne 0 -or -not $rebuilt) {
        # A legitimate zero-item export (no AH scan yet) makes the helper throw;
        # fall back to the already-parsed export string instead of aborting, same
        # as when node itself isn't available.
        Write-Host "No item data to rebuild from SavedVariables (probably no AH scan yet) -- using the compact export as-is." -ForegroundColor Yellow
    } else {
        try { $obj = $rebuilt | ConvertFrom-Json } catch {
            Write-Host "Rebuilt export isn't valid JSON." -ForegroundColor Red
            exit 1
        }
        $json = $rebuilt
    }
}
$itemCount = ($obj.items.PSObject.Properties | Measure-Object).Count
Write-Host ("Realm: {0}  -  {1} items to upload" -f $obj.realm, $itemCount)

# A save can hold several realm/faction buckets. Without -Realm the helper picks
# the last active one, which is easy to miss -- name the alternatives so a
# wrong-faction upload can't go by silently.
if (-not $Realm -and $node -and (Test-Path $helper)) {
    $realmList = & $node.Source $helper $file.FullName --list-realms
    if ($LASTEXITCODE -eq 0 -and $realmList) {
        try {
            # foreach, not a pipeline: 5.1's ConvertFrom-Json hands back the array
            # as ONE object when piped, so Where-Object would see a single element.
            $others = @()
            foreach ($r in (ConvertFrom-Json -InputObject $realmList)) { if ($r -ne $obj.realm) { $others += $r } }
            if ($others.Count -gt 0) {
                Write-Host ("WARNING: this save also holds {0}. Uploading only {1}; re-run with -Realm <name> for another bucket." -f ($others -join ", "), $obj.realm) -ForegroundColor Yellow
            }
        } catch { }
    }
}

if (-not $Token) { $Token = Load-Token }
if (-not $Token) { $Token = Read-Host "REFRESH_TOKEN" }
if ($Token) { Save-Token $Token }

if ($PopulationOnly) {
    Write-Host "Population-only mode -- skipping the market upload." -ForegroundColor Yellow
} elseif ($itemCount -lt 1) {
    Write-Host "No AH items in this export yet (no auction house scan) -- skipping the market upload, still trying population/sellers below." -ForegroundColor Yellow
} else {
    $uri = "$Url/admin/import-realm?token=$([uri]::EscapeDataString($Token))&region=$Region"
    $resp = Invoke-RestMethod -Uri $uri -Method Post -Body ([System.Text.Encoding]::UTF8.GetBytes($json)) -ContentType "application/json; charset=utf-8"

    if ($resp.ok) {
        Write-Host ("Imported {0} items for {1}." -f $resp.items, $resp.realm) -ForegroundColor Green
        Write-Host ("View: {0}/?game=realm:{1}" -f $Url, [uri]::EscapeDataString($resp.realm))
        Test-SiteImport $resp.realm $resp.items
    } else {
        Write-Host ("Server error: {0}" -f $resp.error) -ForegroundColor Red
    }
}

# The addon also writes a population export. Rebuild it from the authoritative
# SavedVariables tables when possible so identity history cannot be stale --
# and attempt that rebuild even when the compact ["popExport"] cache string
# itself is missing (same rationale as the item export above).
$pop = $null
$popJson = $null
if ($content -match '\["popExport"\]\s*=\s*"((?:\\.|[^"\\])*)"') {
    $popJson = [regex]::Replace($matches[1], '\\(.)', '$1')
    try { $pop = $popJson | ConvertFrom-Json } catch { $pop = $null }
}
$helper = Join-Path $scriptDir "export-realm-from-savedvariables.js"
$node = Get-Command node -ErrorAction SilentlyContinue
if ($node -and (Test-Path $helper)) {
    $popArgs = @($helper, $file.FullName, "--population", "--flavor=$Flavor")
    if ($Realm) { $popArgs += "--realm=$Realm" }
    $rebuiltPop = & $node.Source @popArgs
    if ($LASTEXITCODE -eq 0 -and $rebuiltPop) {
        try {
            $pop = $rebuiltPop | ConvertFrom-Json
            $popJson = $rebuiltPop
        } catch { $pop = $null }
    }
}
if (-not $popJson) {
    Write-Host "No population data to rebuild from SavedVariables (probably no /who scan yet)." -ForegroundColor Yellow
} else {
    if ($pop -and $pop.type -in @("ml-pop-v1", "ml-pop-v2") -and $pop.samples) {
        $nSamples = ($pop.samples | Measure-Object).Count
        $nCharacters = if ($pop.characters) { ($pop.characters | Measure-Object).Count } else { 0 }
        if ($nSamples -gt 0 -or $nCharacters -gt 0) {
            $popUri = "$Url/admin/import-pop?token=$([uri]::EscapeDataString($Token))"
            try {
                $pr = Invoke-RestMethod -Uri $popUri -Method Post -Body ([System.Text.Encoding]::UTF8.GetBytes($popJson)) -ContentType "application/json; charset=utf-8"
                if ($pr.ok) {
                    Write-Host ("Imported {0} population sample(s) and {1} character(s) for {2}." -f $pr.samples, $pr.characters, $pr.realm) -ForegroundColor Green
                    Write-Host ("Population: {0}/pop?game=realm:{1}" -f $Url, [uri]::EscapeDataString($pr.realm))
                } else {
                    Write-Host ("Population upload error: {0}" -f $pr.error) -ForegroundColor Yellow
                }
            } catch {
                Write-Host ("Population upload failed: {0}" -f $_.Exception.Message) -ForegroundColor Yellow
            }
        }
    }
}

# Seller profiles: which seller lists which items, rebuilt from SavedVariables.
# Only legacy paged scans capture owner names, so getAll / retail uploads have no
# seller data and the node helper exits non-zero here -- skipped silently.
$node = Get-Command node -ErrorAction SilentlyContinue
if ($node -and (Test-Path $helper)) {
    $sellerArgs = @($helper, $file.FullName, "--sellers")
    if ($Realm) { $sellerArgs += "--realm=$Realm" }
    $rebuiltSellers = & $node.Source @sellerArgs
    if ($LASTEXITCODE -eq 0 -and $rebuiltSellers) {
        try { $sellers = $rebuiltSellers | ConvertFrom-Json } catch { $sellers = $null }
        if ($sellers -and $sellers.type -eq "ml-sellers-v1" -and $sellers.sellers) {
            $nSellers = ($sellers.sellers | Measure-Object).Count
            if ($nSellers -gt 0) {
                $sellerUri = "$Url/admin/import-sellers?token=$([uri]::EscapeDataString($Token))&region=$Region"
                try {
                    $sr = Invoke-RestMethod -Uri $sellerUri -Method Post -Body ([System.Text.Encoding]::UTF8.GetBytes($rebuiltSellers)) -ContentType "application/json; charset=utf-8"
                    if ($sr.ok) {
                        Write-Host ("Imported {0} seller(s) with {1} listing(s) for {2}." -f $sr.sellers, $sr.listings, $sr.realm) -ForegroundColor Green
                        if ($sr.meta) {
                            Write-Host ("Seller scan: {0}/{1} pages, {2}% owner coverage{3}." -f $sr.meta.scannedPages, $sr.meta.pages, $sr.meta.ownerCoverage, $(if ($sr.meta.partial) { " (sample)" } else { "" }))
                        }
                        Write-Host ("Sellers show on each item page ({0}/?game=realm:{1}, open an item)." -f $Url, [uri]::EscapeDataString($sr.realm))
                        # The export only carries the last scan that captured owners; a
                        # newer Get All scan leaves it behind, and re-uploading it is a no-op.
                        $newestSeen = ($sellers.sellers | Measure-Object -Property ls -Maximum).Maximum
                        if ($newestSeen) {
                            $sellerAgeH = ([DateTimeOffset]::UtcNow.ToUnixTimeSeconds() - [long]$newestSeen) / 3600
                            if ($sellerAgeH -gt 24) {
                                Write-Host ("WARNING: seller data is {0:N1} days old (last seen {1:yyyy-MM-dd HH:mm} UTC). Run /ml scan paged + /reload for fresh sellers." -f ($sellerAgeH / 24), [DateTimeOffset]::FromUnixTimeSeconds([long]$newestSeen)) -ForegroundColor Yellow
                            }
                        }
                    } else {
                        Write-Host ("Seller upload error: {0}" -f $sr.error) -ForegroundColor Yellow
                    }
                } catch {
                    Write-Host ("Seller upload failed: {0}" -f $_.Exception.Message) -ForegroundColor Yellow
                }
            }
        }
    }
}
