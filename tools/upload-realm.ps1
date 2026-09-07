# Reads your addon's SavedVariables export and pushes it to the website.
# No copy-paste. Run it after you /reload or log out in game.
#
# Usage:  powershell -File upload-realm.ps1 -Token YOUR_REFRESH_TOKEN
# Retail: powershell -File upload-realm.ps1 -Flavor retail
#         (double-click "Run with PowerShell" and it will prompt for the token)
#
# The addon writes a fresh export to SavedVariables on logout/reload, so:
#   1. In game: scan the AH, then /reload (or log out)
#   2. Run this script

param(
    [string]$Token,
    [ValidateSet("classic", "classic-era", "retail")]
    [string]$Flavor = "classic",
    [string]$Region,
    [string]$Url    = "https://marketlens.skarz.workers.dev",
    [string]$Wow,
    # Retail only: upload a specific "Realm-Faction" bucket instead of the active
    # export. Both factions' data live in the account-wide save, so this uploads
    # either side without swapping characters and reloading. e.g. Nesingwary-Alliance
    [string]$Realm
)

$ErrorActionPreference = "Stop"
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

if (-not $Region) {
    $Region = if ($Flavor -eq "retail") { "retail" } elseif ($Flavor -eq "classic-era") { "classic" } else { "classic-progression" }
}
if (-not $Wow) {
    $wowFolder = if ($Flavor -eq "retail") { "_retail_" } elseif ($Flavor -eq "classic-era") { "_classic_era_" } else { "_anniversary_" }
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

# Locate the account-wide SavedVariables file (newest if several accounts).
$files = Get-ChildItem "$Wow\WTF\Account\*\SavedVariables\MarketLens.lua" -ErrorAction SilentlyContinue
if (-not $files) {
    Write-Host "Couldn't find MarketLens.lua under $Wow\WTF\Account\*\SavedVariables\." -ForegroundColor Red
    Write-Host "Log out (or /reload) in game at least once so the addon writes its data, then retry."
    exit 1
}
$file = $files | Sort-Object LastWriteTime -Descending | Select-Object -First 1
Write-Host "Reading $($file.FullName)"

$content = Get-Content $file.FullName -Raw
if ($content -notmatch '\["export"\]\s*=\s*"((?:\\.|[^"\\])*)"') {
    Write-Host "No export found in SavedVariables." -ForegroundColor Red
    Write-Host "In game: scan, then /reload (or log out) so the addon saves the export, then retry."
    exit 1
}
# Un-escape the Lua string literal (\" -> ", \\ -> \).
$json = [regex]::Replace($matches[1], '\\(.)', '$1')

try { $obj = $json | ConvertFrom-Json } catch { Write-Host "Export isn't valid JSON." -ForegroundColor Red; exit 1 }
if ($obj.type -ne "ml-realm-v1") {
    Write-Host "Old export format. In game run /reload to load the current addon, then retry." -ForegroundColor Red
    exit 1
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
        Write-Host "Couldn't rebuild the realm export from SavedVariables." -ForegroundColor Red
        exit 1
    }
    try { $obj = $rebuilt | ConvertFrom-Json } catch {
        Write-Host "Rebuilt export isn't valid JSON." -ForegroundColor Red
        exit 1
    }
    $json = $rebuilt
}
$itemCount = ($obj.items.PSObject.Properties | Measure-Object).Count
if ($itemCount -lt 1) {
    Write-Host "Refusing to upload a realm export with zero items." -ForegroundColor Red
    exit 1
}
Write-Host ("Realm: {0}  -  {1} items to upload" -f $obj.realm, $itemCount)

if (-not $Token) { $Token = Load-Token }
if (-not $Token) { $Token = Read-Host "REFRESH_TOKEN" }
if ($Token) { Save-Token $Token }

$uri = "$Url/admin/import-realm?token=$([uri]::EscapeDataString($Token))&region=$Region"
$resp = Invoke-RestMethod -Uri $uri -Method Post -Body $json -ContentType "application/json"

if ($resp.ok) {
    Write-Host ("Imported {0} items for {1}." -f $resp.items, $resp.realm) -ForegroundColor Green
    Write-Host ("View: {0}/?game=realm:{1}" -f $Url, [uri]::EscapeDataString($resp.realm))
} else {
    Write-Host ("Server error: {0}" -f $resp.error) -ForegroundColor Red
}

# The addon also writes a population export. Rebuild it from the authoritative
# SavedVariables tables when possible so identity history cannot be stale.
if ($content -match '\["popExport"\]\s*=\s*"((?:\\.|[^"\\])*)"') {
    $popJson = [regex]::Replace($matches[1], '\\(.)', '$1')
    try { $pop = $popJson | ConvertFrom-Json } catch { $pop = $null }
    $helper = Join-Path $scriptDir "export-realm-from-savedvariables.js"
    $node = Get-Command node -ErrorAction SilentlyContinue
    if ($node -and (Test-Path $helper)) {
        $popArgs = @($helper, $file.FullName, "--population", "--flavor=$Region")
        if ($Realm) { $popArgs += "--realm=$Realm" }
        $rebuiltPop = & $node.Source @popArgs
        if ($LASTEXITCODE -eq 0 -and $rebuiltPop) {
            try {
                $pop = $rebuiltPop | ConvertFrom-Json
                $popJson = $rebuiltPop
            } catch { $pop = $null }
        }
    }
    if ($pop -and $pop.type -in @("ml-pop-v1", "ml-pop-v2") -and $pop.samples) {
        $nSamples = ($pop.samples | Measure-Object).Count
        $nCharacters = if ($pop.characters) { ($pop.characters | Measure-Object).Count } else { 0 }
        if ($nSamples -gt 0 -or $nCharacters -gt 0) {
            $popUri = "$Url/admin/import-pop?token=$([uri]::EscapeDataString($Token))"
            try {
                $pr = Invoke-RestMethod -Uri $popUri -Method Post -Body $popJson -ContentType "application/json"
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
