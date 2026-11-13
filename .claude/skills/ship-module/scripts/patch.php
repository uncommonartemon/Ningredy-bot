<?php

// Exact replacements in long files, each found exactly once or nothing is written.
// Usage: php patch.php <definitions.php> [--check]
// The definitions file returns a list of [file, old text, new text]. A file may be given
// relative to the project root. The file's own line ending is kept.

$root = dirname(__DIR__, 4);
$definitions = require $argv[1];
$check = in_array('--check', $argv, true);
$errors = 0;
$out = [];
foreach ($definitions as $index => [$file, $old, $new]) {
    $path = preg_match('/^([A-Za-z]:[\\\\\/]|\/)/', $file) === 1 ? $file : $root.DIRECTORY_SEPARATOR.$file;
    $body = $out[$path] ?? @file_get_contents($path);
    if ($body === false || $body === '') {
        echo "EMPTY OR MISSING: {$file}\n";
        $errors++;

        continue;
    }
    if (str_contains($body, "\r\n")) {
        $old = str_replace(["\r\n", "\n"], ["\n", "\r\n"], $old);
        $new = str_replace(["\r\n", "\n"], ["\n", "\r\n"], $new);
    }
    $found = substr_count($body, $old);
    if ($found !== 1) {
        echo "#{$index} {$file}: old text occurs {$found} times: ".substr(str_replace("\n", ' ', $old), 0, 90)."\n";
        $errors++;

        continue;
    }
    $out[$path] = str_replace($old, $new, $body);
}
if ($errors > 0) {
    echo "NOTHING WRITTEN, errors: {$errors}\n";
    exit(1);
}
foreach ($out as $path => $body) {
    if (! $check) {
        file_put_contents($path, $body);
    }
    echo ($check ? 'ok ' : 'patched ').$path."\n";
}
