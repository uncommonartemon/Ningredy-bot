<?php

namespace App\Services\GalleryRecipes\Training;

/** Projection of saved observations for a prompt; never changes the saved evidence. */
class ObservationPresenter
{
    /**
     * Binary image data is useful to the browser / vision channel, not as text
     * tokens. Keep the raw snapshots untouched; identify each inline asset in
     * the text projection, including copies nested in history or HTML.
     */
    public function textPayload(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->textPayload($value);
            } elseif (is_string($value) && stripos($value, 'data:image/') !== false) {
                $payload[$key] = preg_replace_callback(
                    '~data:(image/[a-z0-9.+-]+);base64,[a-z0-9+/=_-]+~i',
                    static fn (array $match): string => '[inline-image mime='.$match[1]
                        .' uri_bytes='.strlen($match[0]).' sha256='.hash('sha256', $match[0])
                        .'; binary omitted from text, not a fetchable URL]',
                    $value,
                ) ?? $value;
            }
        }

        return $payload;
    }

    private const AGENT_PAGE_LIST_LIMITS = [
        'container_candidates' => 16,
        'image_candidates' => 20,
        'action_candidates' => 24,
        'fragments' => 16,
        'network_image_samples' => 12,
    ];

    public function scoutForAgent(array $pageScout, bool $complete): array
    {
        if ($complete) {
            return $pageScout;
        }

        if (isset($pageScout['frame_observations'])) {
            $pageScout['frame_observations'] = array_map(fn (array $frame): array => [
                'frame_selectors' => $frame['frame_selectors'] ?? [],
                'final_url' => $frame['final_url'] ?? null,
                'title' => $frame['title'] ?? null,
                'action_candidates' => array_slice($frame['action_candidates'] ?? [], 0, 5),
                'image_candidates' => array_slice($frame['image_candidates'] ?? [], 0, 5),
                'error' => $frame['error'] ?? null,
                'more' => 'ReadGalleryPageObservation(section=frame_observations) has the captured details.',
            ], $pageScout['frame_observations']);
        }

        foreach (self::AGENT_PAGE_LIST_LIMITS as $key => $keep) {
            if (is_array($pageScout[$key] ?? null) && count($pageScout[$key]) > $keep) {
                $pageScout[$key.'_omitted'] = count($pageScout[$key]) - $keep;
                $pageScout[$key] = array_slice($pageScout[$key], 0, $keep);
            }
        }

        return $pageScout;
    }

    public function initialObservationForAgent(array $initial, ?array $feedback, bool $complete): array
    {
        $latest = $feedback['previous_attempt_observation'] ?? null;
        if ($complete || ! is_array($latest) || $latest === []) {
            return $this->scoutForAgent($initial, $complete);
        }

        return [
            'final_url' => $initial['final_url'] ?? null,
            'title' => $initial['title'] ?? null,
            'observation_omitted' => 'Initial page is stored on the server. Use ReadGalleryPageObservation(initial) '
                .'for prerequisites or controls outside the latest observation. Every recipe still replays from this initial URL.',
        ];
    }

    public function observationForAgent(array $observation): array
    {
        // The full snapshot stays in execution audit and is available as a tool,
        // but must not be nested inside the supposedly focused paid prompt.
        unset($observation['full_page_observation']);

        return $this->scoutForAgent($observation, false);
    }
}
