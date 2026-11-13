#!/usr/bin/env bash
# Serves the agent's own copy of the site: this worktree's code, its scratch database, its own
# port. Run it in the background and stop it when the looking is done. It refuses to run from
# the bot folder. Environment: LOOK_PORT (8123 by default), LOOK_USER (the admin login given
# to seed-copy.php, "admin" by default).
set -euo pipefail

root="$(cd "$(dirname "$0")/../../../.." && pwd)"
main="$(git -C "$root" worktree list --porcelain | sed -n '1s/^worktree //p')"
if [ "$(cd "$main" && pwd)" = "$root" ]; then
    echo "refusing: this is the bot folder. The copy runs from a linked worktree only."
    exit 1
fi
db="$root/storage/app/agent/view.sqlite"
if [ ! -f "$db" ]; then
    echo "no scratch database: run seed-copy.php first"
    exit 1
fi
port="${LOOK_PORT:-8123}"
echo "the copy: http://127.0.0.1:$port/admin"
cd "$root/public"
DB_CONNECTION=sqlite DB_DATABASE="$db" DB_URL= APP_URL="http://127.0.0.1:$port" APP_ENV=local APP_DEBUG=true \
    SESSION_DRIVER=file CACHE_STORE=file QUEUE_CONNECTION=sync LOG_CHANNEL=null \
    OPENAI_API_KEY=none TELEGRAM_BOT_TOKEN=none TELEGRAM_WEBHOOK_SECRET=none ADMIN_NAME="${LOOK_USER:-admin}" \
    php -S "127.0.0.1:$port" ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
