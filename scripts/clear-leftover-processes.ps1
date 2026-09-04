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

# Two bots on one database fight over the same queue, so a second launcher
# window has to stop before it touches anything.
#
# This replaced an exclusive file lock held for the life of the window, which
# deadlocked: cmd's redirection is inherited by every child process, so an
# orphaned worker from a closed window kept holding the lock, and the next start
# was refused by exactly the leftovers it was about to clean up. A launcher
# window is directly observable, so it is counted instead - and this window is
# one of them.
$windows = @(Get-CimInstance Win32_Process -Filter "Name='cmd.exe'" -ErrorAction SilentlyContinue |
    Where-Object { $_.CommandLine -like '*start-ningredy*' })

if ($windows.Count -gt 1) {
    Write-Host 'Ningredy is already running in another window.'
    Write-Host 'Close that window first, or use it instead of this one.'
    exit 2
}

# These patterns name what this launcher starts. An earlier version ended any
# ngrok.exe on the machine and any PHP running "artisan queue:work", which on a
# developer's machine is somebody else's project; ngrok is matched by the port
# this bot tunnels and the queues by the names it uses.
#
# Some of them are still not unique to this project, and saying otherwise would
# be a promise this cannot keep: another Laravel application running "artisan
# schedule:work", or a second checkout of this same project, would be ended too.
# Windows does not expose a process's working directory, and every command here
# is started by relative path, so there is nothing left to tell two checkouts
# apart - matching the browser server by absolute path was tried and matched
# nothing, because npm starts it as "node scripts/browser-server.mjs".
#
# node.exe is included for the shared browser server, which holds a Chromium
# window open for as long as the bot runs - an orphaned one keeps that window
# alive and keeps advertising an endpoint the next start would connect to.
$pattern = 'artisan\s+(?:schedule:work|telegram:sync-ngrok)' +
    '|artisan\s+queue:work\s+--queue=(?:assistant|voice|media|default)' +
    '|artisan\s+serve\s+--host=127\.0\.0\.1\s+--port=8000' +
    '|browser-server\.mjs' +
    '|ngrok(?:\.exe)?["'']?\s+http\s+8000'

$leftovers = Get-CimInstance Win32_Process -Filter "Name='php.exe' OR Name='ngrok.exe' OR Name='node.exe'" -ErrorAction SilentlyContinue |
    Where-Object { $_.CommandLine -match $pattern }

if (-not $leftovers) {
    Write-Host 'No leftover processes from previous runs.'
    exit 0
}

foreach ($process in $leftovers) {
    Write-Host ("Ending leftover process {0}: {1}" -f $process.ProcessId, $process.CommandLine)
    Stop-Process -Id $process.ProcessId -Force -ErrorAction SilentlyContinue
}
