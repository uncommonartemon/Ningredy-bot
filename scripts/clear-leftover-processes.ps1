# Closing the launcher window does not take every process with it: concurrently's
# grandchildren are orphaned rather than killed, so each start leaves a scheduler
# and an ngrok watcher behind. Eight had piled up by the end of one day, each
# quietly running whatever code was current when it started - which is how a
# fixed bug can appear to still be there.
#
# This runs only after start-ningredy.bat has taken its single-instance lock, so
# no other legitimate instance exists and anything still alive is a leftover.
# Processes are matched on their command line rather than on "php.exe", so an
# unrelated PHP process on this machine is not caught in it.

$pattern = 'artisan\s+(schedule:work|queue:work|telegram:sync-ngrok|serve)'

$leftovers = Get-CimInstance Win32_Process -Filter "Name='php.exe' OR Name='ngrok.exe'" -ErrorAction SilentlyContinue |
    Where-Object { $_.Name -eq 'ngrok.exe' -or $_.CommandLine -match $pattern }

if (-not $leftovers) {
    Write-Host 'No leftover processes from previous runs.'
    exit 0
}

foreach ($process in $leftovers) {
    Write-Host ("Ending leftover process {0}: {1}" -f $process.ProcessId, $process.CommandLine)
    Stop-Process -Id $process.ProcessId -Force -ErrorAction SilentlyContinue
}
