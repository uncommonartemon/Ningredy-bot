<?php

// Adds missing admin strings to lang/en.json, lang/cs.json and lang/uk.json.
// Usage: php add-lang.php <definitions.php>
// The definitions file returns [russian string => [english, czech, ukrainian]].
// Only keys a file lacks are added, as new lines before its closing brace; nothing that is
// already there is touched, and each file must parse before and after.

$root = dirname(__DIR__, 4).DIRECTORY_SEPARATOR.'lang'.DIRECTORY_SEPARATOR;
$definitions = require $argv[1];
foreach (['en' => 0, 'cs' => 1, 'uk' => 2] as $locale => $index) {
    $file = $root.$locale.'.json';
    $body = file_get_contents($file);
    $current = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
    $lines = '';
    $added = 0;
    foreach ($definitions as $russian => $translations) {
        if (! array_key_exists($russian, $current)) {
            $lines .= ",\n    ".json_encode($russian, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).': '
                .json_encode($translations[$index], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $added++;
        }
    }
    $end = strrpos($body, '}');
    $next = rtrim(substr($body, 0, $end)).$lines."\n}".substr($body, $end + 1);
    json_decode($next, true, flags: JSON_THROW_ON_ERROR);
    file_put_contents($file, $next);
    echo "{$locale}: added {$added}\n";
}
