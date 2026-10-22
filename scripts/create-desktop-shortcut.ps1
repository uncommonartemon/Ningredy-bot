# Puts a "Ningredy" shortcut on the desktop that opens the panel window
# (desktop/main.mjs). Run once; running it again only refreshes the shortcut.
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$electron = Join-Path $root 'node_modules\electron\dist\electron.exe'

# npm ci downloads it through the postinstall script; this covers a checkout
# installed before the panel existed.
if (-not (Test-Path -LiteralPath $electron)) {
    & node (Join-Path $root 'node_modules\electron\install.js')
    if (-not (Test-Path -LiteralPath $electron)) {
        throw 'Electron is not installed. Run "npm ci" in the project folder first.'
    }
}

$path = Join-Path ([Environment]::GetFolderPath('Desktop')) 'Ningredy.lnk'
$link = (New-Object -ComObject WScript.Shell).CreateShortcut($path)
$link.TargetPath = $electron
$link.Arguments = '"' + $root + '"'
$link.WorkingDirectory = $root
$link.IconLocation = Join-Path $root 'desktop\icon.ico'
$link.Description = 'Ningredy control panel'
$link.Save()
Write-Host "Shortcut created: $path"
