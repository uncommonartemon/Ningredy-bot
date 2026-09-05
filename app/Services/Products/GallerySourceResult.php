<?php

namespace App\Services\Products;

/**
 * One source's verdict, and the only way to state it.
 *
 * The bug this exists to make impossible: success was re-derived at the end of
 * the search from the method that produced the frames and a structural flag the
 * browser had set on each of them. A gallery whose frames were dropped by the
 * language rule still carried confirmed_gallery=true on the one survivor, so a
 * partial result introduced itself as complete - and so did a set whose
 * verification had failed with a timeout and never actually run.
 *
 * A frame's flags are evidence about that frame. They are not the verdict on
 * the set, and only fromVerifiedGallery() - which has to be told what the
 * verification actually returned - can produce Complete.
 */
final readonly class GallerySourceResult
{
    private function __construct(
        public GalleryOutcome $outcome,
        public array $candidates,
        public ?array $source,
        public ?string $method,
        public string $reason,
    ) {}

    public static function rejected(string $reason = 'no_verified_frames'): self
    {
        return new self(GalleryOutcome::Rejected, [], null, null, $reason);
    }

    /**
     * The single transition that can end in Complete.
     *
     * @param  array<int, array<string, mixed>>  $frames  what the content check returned, or null when it could not run
     */
    public static function fromVerifiedGallery(
        ?array $frames,
        array $unverifiedFrames,
        int $minimum,
        int $maximum,
        ?array $source,
        string $method,
        string $notes,
    ): self {
        // The check did not run. Its frames survive - they are real photographs
        // of this product - but nothing about them has been confirmed, and the
        // strategy's base rule is that a technical failure is never a semantic
        // verdict.
        if ($frames === null) {
            return self::interrupted($unverifiedFrames, $maximum, $source, $method);
        }

        if ($frames === []) {
            return self::rejected();
        }

        $kept = array_slice(array_values($frames), 0, max(1, $maximum));

        // A traversal that never finished can hand back enough frames to meet
        // the minimum and still not be the whole gallery.
        $partialTraversal = collect($kept)->every(fn (array $frame): bool => (bool) ($frame['partial_gallery'] ?? false));

        if (count($kept) < $minimum || $partialTraversal) {
            return new self(
                GalleryOutcome::Partial,
                self::annotated($kept, 'source_verified', $notes),
                $source,
                $method,
                $partialTraversal ? 'verified_partial_traversal' : 'verified_below_minimum',
            );
        }

        return new self(
            GalleryOutcome::Complete,
            self::annotated($kept, 'source_verified', $notes),
            $source,
            $method,
            'verified_gallery',
        );
    }

    /**
     * The other way a source can end well: frames a per-frame check cleared,
     * as the deferred static sets and the fallback discovery groups produce.
     * It is a second entry point and still not an assignment - the outcome is
     * derived from what came back, never handed in by the caller.
     *
     * @param  array<int, array<string, mixed>>  $frames
     */
    public static function fromVerifiedFrames(
        array $frames,
        int $minimum,
        int $maximum,
        ?array $source,
        string $method,
        string $notes = '',
    ): self {
        if ($frames === []) {
            return self::rejected();
        }

        $kept = array_slice(array_values($frames), 0, max(1, $maximum));
        $complete = count($kept) >= $minimum;

        return new self(
            $complete ? GalleryOutcome::Complete : GalleryOutcome::Partial,
            $notes === '' ? $kept : self::annotated($kept, 'verified', $notes),
            $source,
            $method,
            $complete ? 'verified_frames' : 'verified_below_minimum',
        );
    }

    public static function interrupted(array $candidates, int $maximum, ?array $source, string $method): self
    {
        return new self(
            GalleryOutcome::Interrupted,
            self::annotated(
                array_slice(array_values($candidates), 0, max(1, $maximum)),
                'pending',
                'Проверка текста на кадрах не состоялась технически: кадры сохранены, но не подтверждены.',
            ),
            $source,
            $method,
            'language_check_unavailable',
        );
    }

    public function isComplete(): bool
    {
        return $this->outcome === GalleryOutcome::Complete;
    }

    public function hasFrames(): bool
    {
        return $this->candidates !== [];
    }

    /**
     * A large unverified set must never evict a smaller publishable one: the
     * reserve is ranked by what is known about it first and by size second.
     */
    public function isBetterReserveThan(?self $other): bool
    {
        if (! $this->hasFrames()) {
            return false;
        }

        if ($other === null || ! $other->hasFrames()) {
            return true;
        }

        $rank = fn (self $result): int => match ($result->outcome) {
            GalleryOutcome::Complete => 3,
            GalleryOutcome::Partial => 2,
            GalleryOutcome::Interrupted => 1,
            GalleryOutcome::Rejected => 0,
        };

        return [$rank($this), count($this->candidates)] > [$rank($other), count($other->candidates)];
    }

    /**
     * @param  array<int, array<string, mixed>>  $frames
     * @return array<int, array<string, mixed>>
     */
    private static function annotated(array $frames, string $status, string $notes): array
    {
        return array_map(fn (array $frame): array => [
            ...$frame,
            'verification_status' => $status,
            'verification_notes' => $notes,
        ], $frames);
    }
}
