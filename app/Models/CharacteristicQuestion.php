<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A doubtful proposal of the characteristic audit, put to the operator: merge two characteristics
 * (payload from, into, reason) or put one into a group (payload key, group, reason).
 */
class CharacteristicQuestion extends Model
{
    public const string MERGE = 'merge';

    public const string GROUP = 'group';

    protected $fillable = ['kind', 'payload', 'status', 'chat_id', 'message_id', 'answered_by', 'answered_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'answered_at' => 'datetime'];
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }

    /** One identity per proposal: the same pair or the same characteristic and group is one question. */
    public static function identity(string $kind, array $payload): string
    {
        if ($kind === self::MERGE) {
            $pair = [(string) ($payload['from'] ?? ''), (string) ($payload['into'] ?? '')];
            sort($pair);

            return 'merge:'.implode('|', $pair);
        }

        return 'group:'.($payload['key'] ?? '').'|'.mb_strtolower((string) ($payload['group']['name'] ?? ''));
    }

    /** @return array<string, true> identities the operator answered «no» to: never proposed again */
    public static function declined(): array
    {
        return self::query()->where('status', 'declined')->get()
            ->mapWithKeys(fn (self $question): array => [self::identity($question->kind, $question->payload) => true])->all();
    }
}
