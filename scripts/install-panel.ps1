# Installs the desktop panel (desktop/main.mjs): the Electron binary npm leaves
# for first use, and a "Ningredy" shortcut in the project folder next to the
# .bat files. npm runs this after every install; running it again is harmless.
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$electron = Join-Path $root 'node_modules\electron\dist\electron.exe'

if (-not (Test-Path -LiteralPath $electron)) {
    & node (Join-Path $root 'node_modules\electron\install.js')
}
# The .bat files still start the bot, so a failed download must not fail the
# install or the update that ran it.
if (-not (Test-Path -LiteralPath $electron)) {
    Write-Warning 'The Ningredy panel could not be downloaded; start-ningredy.bat still works. Run "npm ci" again later.'
    exit 0
}

# A shortcut stores an absolute path, so it is made on each machine, not kept in git.
$link = (New-Object -ComObject WScript.Shell).CreateShortcut((Join-Path $root 'Ningredy.lnk'))
$link.TargetPath = $electron
$link.Arguments = '"' + $root + '"'
$link.WorkingDirectory = $root
$link.IconLocation = Join-Path $root 'desktop\icon.ico'
$link.Description = 'Ningredy control panel'
$link.Save()
Write-Host 'Ningredy panel ready: Ningredy.lnk in the project folder.'
