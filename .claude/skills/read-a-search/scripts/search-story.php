<?php

// Read-only: the story of one search, or where the time of the last searches went.
// Usage:
//   php search-story.php last            the last request the bot handled
//   php search-story.php 423             request number 423
//   php search-story.php --slow 20       the last 20 requests: time by stage, summed
//   add --scratch to read the worktree's own search database (whole-search.php) instead of the bot's
// Nothing is written anywhere.

$root = dirname(__DIR__, 4);
$args = array_slice($argv, 1);
$scratch = in_array('--scratch', $args, true);
$args = array_values(array_diff($args, ['--scratch']));
if ($scratch) {
    $database = $root.'/storage/app/agent/search.sqlite';
} else {
    $list = (string) shell_exec('git -C '.escapeshellarg($root).' worktree list --porcelain');
    $bot = preg_match('/^worktree (.+)$/m', $list, $match) === 1 ? trim($match[1]) : $root;
    $database = $bot.'/database/database.sqlite';
}
if (! is_file($database)) {
    exit("no database at {$database}\n");
}
$db = new PDO('sqlite:'.$database, null, null, [PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
// This script reads the database directly and does not start the application: no framework helpers here.
$filled = fn (mixed $value): bool => trim((string) $value) !== '';
$rows = fn (string $sql, array $bind = []): array => (function () use ($db, $sql, $bind): array {
    $statement = $db->prepare($sql);
    $statement->execute($bind);

    return $statement->fetchAll(PDO::FETCH_ASSOC);
})();

/** Model time of one request, by stage: [stage => [calls, seconds, longest]]. */
$stages = function (int $id) use ($rows): array {
    $out = [];
    foreach ($rows('select activity, started_at, completed_at from ai_runs where telegram_update_id = ? and started_at is not null and completed_at is not null', [$id]) as $run) {
        $activity = json_decode((string) $run['activity'], true) ?: [];
        $stage = (string) ($activity['stage'] ?? 'other');
        if ($stage === 'vision') {
            $stage .= ':'.($activity['vision_operation'] ?? '?');
        }
        $seconds = max(0, strtotime($run['completed_at']) - strtotime($run['started_at']));
        $out[$stage] ??= [0, 0, 0];
        $out[$stage][0]++;
        $out[$stage][1] += $seconds;
        $out[$stage][2] = max($out[$stage][2], $seconds);
    }
    uasort($out, fn (array $a, array $b): int => $b[1] <=> $a[1]);

    return $out;
};

if (($args[0] ?? '') === '--slow') {
    $count = max(1, (int) ($args[1] ?? 20));
    $totals = [];
    foreach ($rows('select id, text, created_at, processed_at from telegram_updates where processed_at is not null order by id desc limit ?', [$count]) as $request) {
        $wall = strtotime($request['processed_at']) - strtotime($request['created_at']);
        if ($wall < 120) {
            continue;
        }
        $byStage = $stages((int) $request['id']);
        $model = array_sum(array_column($byStage, 1));
        $sites = (int) ($rows('select count(distinct domain) as n from product_source_attempts where telegram_update_id = ? and domain is not null', [$request['id']])[0]['n'] ?? 0);
        echo "#{$request['id']} · ".round($wall / 60, 1).' min · model '.round($model / 60, 1)." min · sites {$sites} · ".mb_substr((string) $request['text'], 0, 60)."\n";
        foreach ($byStage as $stage => [$calls, $seconds]) {
            $totals[$stage][0] = ($totals[$stage][0] ?? 0) + $calls;
            $totals[$stage][1] = ($totals[$stage][1] ?? 0) + $seconds;
        }
    }
    uasort($totals, fn (array $a, array $b): int => $b[1] <=> $a[1]);
    echo "\n=== model time by stage, searches of two minutes and longer ===\n";
    foreach ($totals as $stage => [$calls, $seconds]) {
        echo '  '.str_pad($stage, 34)." {$calls} calls · ".round($seconds / 60, 1).' min · '.round($seconds / max(1, $calls))." s each\n";
    }
    exit;
}

$request = ($args[0] ?? 'last') === 'last'
    ? ($rows('select * from telegram_updates where text is not null order by id desc limit 1')[0] ?? null)
    : ($rows('select * from telegram_updates where id = ?', [(int) $args[0]])[0] ?? null);
if ($request === null) {
    exit("no such request\n");
}
$id = (int) $request['id'];
$wall = $request['processed_at'] ? strtotime($request['processed_at']) - strtotime($request['created_at']) : null;
echo "request #{$id} «".mb_substr((string) $request['text'], 0, 160)."»\n  status {$request['status']}, received {$request['created_at']}"
    .($wall !== null ? ', took '.round($wall / 60, 1).' min' : ', not finished').($filled($request['error']) ? "\n  error: ".mb_substr((string) $request['error'], 0, 300) : '')."\n";

echo "\nmodel time by stage:\n";
foreach ($stages($id) as $stage => [$calls, $seconds, $longest]) {
    echo '  '.str_pad($stage, 34)." {$calls} calls · {$seconds} s · longest {$longest} s\n";
}

$journal = $rows('select domain, phase, action, status, decision, message, created_at from product_source_attempts where telegram_update_id = ? order by id', [$id]);
echo "\njournal: ".count($journal).' steps on '.count(array_unique(array_filter(array_column($journal, 'domain'))))." sites\n";
$steps = array_count_values(array_map(fn (array $row): string => $row['phase'].'.'.$row['action'].':'.($row['decision'] ?? $row['status']), $journal));
arsort($steps);
foreach (array_slice($steps, 0, 8, true) as $step => $times) {
    echo "  {$times}× {$step}\n";
}
// The longest silences of the journal and what came after each: where the search stood still.
$gaps = [];
for ($i = 1; $i < count($journal); $i++) {
    $gap = strtotime($journal[$i]['created_at']) - strtotime($journal[$i - 1]['created_at']);
    if ($gap >= 40) {
        $gaps[] = [$gap, trim($journal[$i]['domain'].' '.$journal[$i]['phase'].'.'.$journal[$i]['action'])];
    }
}
rsort($gaps);
foreach (array_slice($gaps, 0, 5) as [$gap, $before]) {
    echo "  silent {$gap} s before {$before}\n";
}
echo "\nthe steps, in order:\n";
foreach ($journal as $row) {
    echo '  '.substr((string) $row['created_at'], 11, 8).' '.str_pad((string) $row['domain'], 26).' '.$row['phase'].'.'.$row['action'].' -> '.($row['decision'] ?? $row['status'])
        .($filled($row['message']) ? ' · '.mb_substr((string) preg_replace('/\s+/u', ' ', (string) $row['message']), 0, 90) : '')."\n";
}

foreach ($rows('select * from product_drafts where telegram_update_id = ? order by id', [$id]) as $draft) {
    echo "\ndraft #{$draft['id']} [{$draft['status']}] {$draft['title']}\n  gallery: ".($draft['gallery_status'] ?? '-')
        .($filled($draft['gallery_search_stop_reason'] ?? null) ? ', stopped: '.$draft['gallery_search_stop_reason'] : '')."\n";
    foreach ($rows('select path, source_url, width, height, verification_status, verification_notes from product_draft_media where product_draft_id = ? order by sort_order, id', [$draft['id']]) as $photo) {
        echo "  photo {$photo['width']}x{$photo['height']} {$photo['verification_status']} from {$photo['source_url']}\n     "
            .mb_substr((string) preg_replace('/\s+/u', ' ', (string) $photo['verification_notes']), 0, 180)."\n";
    }
}
