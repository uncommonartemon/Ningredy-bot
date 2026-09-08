<?php

namespace App\Services\Products;

final class BrowserGalleryCheckpoint
{
    /** @return array<string, mixed> */
    public static function interrupted(string $directory, string $error): array
    {
        $path = $directory.'/checkpoint.json';
        $saved = is_file($path) ? json_decode((string) @file_get_contents($path), true) : null;
        $saved = is_array($saved) ? $saved : [];
        $diagnostics = is_array($saved['diagnostics'] ?? null) ? $saved['diagnostics'] : [];
        $diagnostics['partial'] = true;
        $diagnostics['stopped_early'] = true;
        $diagnostics['browser_stage'] ??= 'startup';
        $diagnostics['interruption_reason'] = $error;
        $screenshot = is_file($directory.'/page.png') ? @file_get_contents($directory.'/page.png') : false;

        return [
            'images' => [], // A checkpoint cannot activate a recipe or publish raw URLs.
            'screenshot' => is_string($screenshot) && $screenshot !== '' ? $screenshot : null,
            'scout' => is_array($saved['scout'] ?? null) ? $saved['scout'] : [],
            'post_interaction_scout' => is_array($saved['post_interaction_scout'] ?? null) ? $saved['post_interaction_scout'] : [],
            'action_trace' => is_array($saved['action_trace'] ?? null) ? $saved['action_trace'] : [],
            'diagnostics' => $diagnostics,
            'error' => $error,
            'failure_kind' => 'browser_timeout',
        ];
    }
}
