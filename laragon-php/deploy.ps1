# Deploy the PHP site into Laragon's web root. Copies the app, the generator
# sources it reads its templates and browser scripts from, and the static
# assets (fonts, favicons, mirrored icons) Apache serves directly.
param([string]$Target = "C:\laragon\www\marketlens")
$ErrorActionPreference = "Stop"
$repo = Split-Path $PSScriptRoot -Parent

robocopy "$PSScriptRoot\src" "$Target\src" /MIR /NFL /NDL /NJH /NJS /NP | Out-Null
robocopy "$repo\site\src" "$Target\resources\src" /MIR /NFL /NDL /NJH /NJS /NP | Out-Null
robocopy "$repo\tools\item-names" "$Target\resources\item-names" /MIR /NFL /NDL /NJH /NJS /NP | Out-Null
robocopy "$repo\tools\item-icons" "$Target\resources\item-icons" /MIR /NFL /NDL /NJH /NJS /NP | Out-Null
New-Item -ItemType Directory -Force "$Target\resources\public" | Out-Null
Copy-Item "$repo\site\public\style.css", "$repo\site\public\census.css" "$Target\resources\public\" -Force

# Document root: the front controller plus files Apache serves as they are.
$public = "$Target\public"
robocopy "$PSScriptRoot\public" $public /E /NFL /NDL /NJH /NJS /NP | Out-Null
robocopy "$repo\site\public\fonts" "$public\fonts" /MIR /NFL /NDL /NJH /NJS /NP | Out-Null
Copy-Item "$repo\site\public\favicon.ico", "$repo\site\public\favicon-96.png", "$repo\site\public\role-icons.png" $public -Force
robocopy "$repo\assets\wow.zamimg.com" "$public\assets" /MIR /NFL /NDL /NJH /NJS /NP | Out-Null
if ($LASTEXITCODE -ge 8) { throw "robocopy failed ($LASTEXITCODE)" }
Write-Output "Deployed to $Target"
